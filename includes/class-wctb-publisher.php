<?php
/**
 * 投稿の公開を捉えて Buffer に送る。
 *
 * @package WCTB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 公開時の送信処理。
 */
class WCTB_Publisher {

	/**
	 * フックを登録する。
	 */
	public function register() {
		// wp_after_insert_post は、タグとアイキャッチ画像が保存されたあとに呼ばれる。
		// transition_post_status はブロックエディタではそれらの保存より前に呼ばれるので使わない。
		add_action( 'wp_after_insert_post', array( $this, 'maybe_send' ), 10, 4 );
	}

	/**
	 * 条件を満たす投稿を、選択されたチャンネルのキューに追加する。
	 *
	 * @param int          $post_id     投稿 ID。
	 * @param WP_Post      $post        投稿。
	 * @param bool         $update      更新かどうか。
	 * @param WP_Post|null $post_before 保存前の投稿。
	 */
	public function maybe_send( $post_id, $post, $update, $post_before ) {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		// 公開済みの投稿の更新では送らない。
		if ( $post_before instanceof WP_Post && 'publish' === $post_before->post_status ) {
			return;
		}

		$settings = WCTB_Options::get();

		if ( '' === WCTB_Options::api_key() || ! $settings['channels'] ) {
			return;
		}

		if ( '' !== $this->skip_reason( $post, $settings ) ) {
			return;
		}

		// 下書きに戻して再公開したときに、送信済みのチャンネルへ再送しないための記録。
		$sent = get_post_meta( $post_id, WCTB_Options::SENT_META, true );
		$sent = is_array( $sent ) ? $sent : array();

		$pending = array_values( array_diff( $settings['channels'], array_keys( $sent ) ) );

		foreach ( $this->send( $post, $settings, $pending, $settings['draft'], false ) as $channel_id => $result ) {
			if ( ! is_wp_error( $result ) ) {
				$sent[ $channel_id ] = $result;
			}
		}

		update_post_meta( $post_id, WCTB_Options::SENT_META, $sent );
	}

	/**
	 * 公開済みの投稿を、設定画面からのテストとして送る。
	 *
	 * 公開時と同じ絞り込みを通す。Buffer には必ず下書きとして送るので、SNS には公開されない。
	 * 送信済みの記録は見ないし、書かない。
	 *
	 * @param WP_Post $post 投稿。
	 * @return array<string,string|WP_Error>|WP_Error チャンネル ID ごとの結果。送らなかったときは、理由をコードに持つ WP_Error。
	 */
	public function send_test( WP_Post $post ) {
		$settings = WCTB_Options::get();

		if ( '' === WCTB_Options::api_key() || ! $settings['channels'] ) {
			return new WP_Error( 'not_ready', 'not_ready' );
		}

		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_published', 'not_published' );
		}

		$reason = $this->skip_reason( $post, $settings );
		if ( '' !== $reason ) {
			return new WP_Error( $reason, $reason );
		}

		return $this->send( $post, $settings, $settings['channels'], true, true );
	}

	/**
	 * 投稿を送らない理由を返す。送るなら空文字。
	 *
	 * @param WP_Post $post     投稿。
	 * @param array   $settings 設定。
	 * @return string password、tags、categories、filter のいずれか。送るなら空文字。
	 */
	public function skip_reason( WP_Post $post, array $settings ) {
		if ( '' !== $post->post_password ) {
			return 'password';
		}

		if ( $settings['tags'] && ! has_term( $settings['tags'], 'post_tag', $post ) ) {
			return 'tags';
		}

		// タグとカテゴリーの両方を指定したときは、両方の条件を満たす投稿だけを送る。
		if ( $settings['categories'] && ! $this->in_categories( $post, $settings['categories'] ) ) {
			return 'categories';
		}

		/**
		 * この投稿を Buffer に送るかどうか。
		 *
		 * @param bool    $send 送るなら true。
		 * @param WP_Post $post 投稿。
		 */
		if ( ! apply_filters( 'wctb_should_send', true, $post ) ) {
			return 'filter';
		}

		return '';
	}

	/**
	 * 投稿を、指定のチャンネルへ送り、結果をログに残す。
	 *
	 * @param WP_Post  $post     投稿。
	 * @param array    $settings 設定。
	 * @param string[] $channels 送り先のチャンネル ID。
	 * @param bool     $draft    Buffer の下書きとして送るか。
	 * @param bool     $test     テスト送信か。
	 * @return array<string,string|WP_Error> チャンネル ID ごとの、Buffer の投稿 ID かエラー。
	 */
	private function send( WP_Post $post, array $settings, array $channels, $draft, $test ) {
		$post_id  = $post->ID;
		$results  = array();
		$text     = $this->build_text( $post );
		$api      = new WCTB_Api( WCTB_Options::api_key() );
		$services = null;

		// 画像の URL。そのままのもの（plain）とタイトル入りのもの（title）を、必要になったときに 1 回だけ作る。
		$images = array();

		foreach ( $channels as $channel_id ) {
			if ( null === $services ) {
				$services = $this->services();
			}

			$kind = in_array( $channel_id, $settings['title_image'], true ) ? 'title' : 'plain';
			if ( ! isset( $images[ $kind ] ) ) {
				$images[ $kind ] = $this->image_url( $post, 'title' === $kind );
			}

			/**
			 * Buffer に送る画像の URL。空文字なら画像を付けない。
			 *
			 * @param string  $url        画像の URL。
			 * @param WP_Post $post       投稿。
			 * @param string  $channel_id チャンネル ID。
			 */
			$image_url = (string) apply_filters( 'wctb_post_image_url', $images[ $kind ], $post, $channel_id );

			$metadata = $this->metadata( $services[ $channel_id ] ?? '', $channel_id, $post, 'title' === $kind );
			$result   = $api->create_post( $channel_id, $text, $image_url, $metadata, $draft );

			$results[ $channel_id ] = $result;

			if ( is_wp_error( $result ) ) {
				WCTB_Options::add_log( $post_id, $channel_id, false, $result->get_error_message(), $draft, $test );
			} else {
				WCTB_Options::add_log( $post_id, $channel_id, true, $result, $draft, $test );
			}
		}

		return $results;
	}

	/**
	 * 投稿が、指定のカテゴリーかその下位のカテゴリーのいずれかに入っているか。
	 *
	 * 下位のカテゴリーは送信のたびに調べるので、設定のあとで足したものも対象になる。
	 *
	 * @param WP_Post $post       投稿。
	 * @param int[]   $categories カテゴリーの ID。
	 * @return bool
	 */
	private function in_categories( $post, array $categories ) {
		$ids = $categories;

		foreach ( $categories as $category_id ) {
			$children = get_term_children( $category_id, 'category' );
			if ( is_array( $children ) ) {
				$ids = array_merge( $ids, $children );
			}
		}

		return has_term( array_values( array_unique( array_map( 'intval', $ids ) ) ), 'category', $post );
	}

	/**
	 * チャンネル ID からサービス名（facebook、instagram など）を引く表を返す。
	 *
	 * 一覧を取得できなければ空の表を返し、サービス固有の指定なしで送る。
	 *
	 * @return array<string,string>
	 */
	private function services() {
		$channels = WCTB_Options::channels();
		$services = array();

		if ( is_array( $channels ) ) {
			foreach ( $channels as $channel ) {
				$services[ $channel['id'] ] = $channel['service'];
			}
		}

		return $services;
	}

	/**
	 * サービスごとに必須の指定を作る。Facebook と Instagram は投稿の種類を求める。
	 *
	 * @param string  $service    サービス名。
	 * @param string  $channel_id チャンネル ID。
	 * @param WP_Post $post       投稿。
	 * @param bool    $vertical   タイトル入りの縦長画像を送るチャンネルか。
	 * @return array
	 */
	private function metadata( $service, $channel_id, WP_Post $post, $vertical = false ) {
		$metadata = array();

		if ( 'facebook' === $service ) {
			$metadata['facebook'] = array( 'type' => 'post' );
		} elseif ( 'instagram' === $service && $vertical ) {
			// 9:16 の画像はフィード投稿の比率（4:5〜1.91:1）に収まらないので、ストーリーズとして送る。
			// shouldShareToFeed は種類を問わず必須。ストーリーズはフィードに出さないので false にする。
			$metadata['instagram'] = array(
				'type'              => 'story',
				'shouldShareToFeed' => false,
			);
		} elseif ( 'instagram' === $service ) {
			$metadata['instagram'] = array(
				'type'              => 'post',
				'shouldShareToFeed' => true,
			);
		}

		/**
		 * Buffer に送るサービス固有の指定（PostInputMetaData）。
		 *
		 * @param array   $metadata   指定。
		 * @param string  $service    サービス名。
		 * @param string  $channel_id チャンネル ID。
		 * @param WP_Post $post       投稿。
		 */
		return (array) apply_filters( 'wctb_post_metadata', $metadata, $service, $channel_id, $post );
	}

	/**
	 * 本文（固定の文面、タイトル、URL）を作る。固定の文面は、設定されているときだけ付ける。
	 *
	 * @param WP_Post $post 投稿。
	 * @return string
	 */
	private function build_text( WP_Post $post ) {
		$title = wp_strip_all_tags( html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) );
		$text  = trim( WCTB_Options::lead( $post ) . "\n" . $title . "\n" . get_permalink( $post ) );

		/**
		 * Buffer に送る本文。
		 *
		 * @param string  $text 本文。
		 * @param WP_Post $post 投稿。
		 */
		return (string) apply_filters( 'wctb_post_text', $text, $post );
	}

	/**
	 * 画像の URL を返す。Buffer は HTTPS の URL だけを受け付けるので、それ以外は付けない。
	 *
	 * @param WP_Post $post        投稿。
	 * @param bool    $title_image true なら、アイキャッチ画像から作ったタイトル入りの縦長画像を使う。
	 *                             作れなければ、アイキャッチ画像をそのまま使う。
	 * @return string
	 */
	private function image_url( WP_Post $post, $title_image = false ) {
		$url = $title_image ? WCTB_Image::generate( $post ) : '';

		if ( '' === $url ) {
			$url = get_the_post_thumbnail_url( $post, 'full' );
		}

		return is_string( $url ) && 0 === strpos( $url, 'https://' ) ? $url : '';
	}
}
