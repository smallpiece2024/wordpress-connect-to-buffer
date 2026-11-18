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

		if ( '' !== $post->post_password ) {
			return;
		}

		$settings = WCTB_Options::get();
		$api_key  = WCTB_Options::api_key();

		if ( '' === $api_key || ! $settings['channels'] ) {
			return;
		}

		if ( $settings['tags'] && ! has_term( $settings['tags'], 'post_tag', $post ) ) {
			return;
		}

		/**
		 * この投稿を Buffer に送るかどうか。
		 *
		 * @param bool    $send 送るなら true。
		 * @param WP_Post $post 投稿。
		 */
		if ( ! apply_filters( 'wctb_should_send', true, $post ) ) {
			return;
		}

		// 下書きに戻して再公開したときに、送信済みのチャンネルへ再送しないための記録。
		$sent = get_post_meta( $post_id, WCTB_Options::SENT_META, true );
		$sent = is_array( $sent ) ? $sent : array();

		$text      = $this->build_text( $post );
		$image_url = $this->image_url( $post );
		$api       = new WCTB_Api( $api_key );
		$services  = null;

		foreach ( $settings['channels'] as $channel_id ) {
			if ( isset( $sent[ $channel_id ] ) ) {
				continue;
			}

			if ( null === $services ) {
				$services = $this->services();
			}

			$metadata = $this->metadata( $services[ $channel_id ] ?? '', $channel_id, $post );
			$result   = $api->create_post( $channel_id, $text, $image_url, $metadata );

			if ( is_wp_error( $result ) ) {
				WCTB_Options::add_log( $post_id, $channel_id, false, $result->get_error_message() );
				continue;
			}

			$sent[ $channel_id ] = $result;
			WCTB_Options::add_log( $post_id, $channel_id, true, $result );
		}

		update_post_meta( $post_id, WCTB_Options::SENT_META, $sent );
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
	 * @return array
	 */
	private function metadata( $service, $channel_id, WP_Post $post ) {
		$metadata = array();

		if ( 'facebook' === $service ) {
			$metadata['facebook'] = array( 'type' => 'post' );
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
	 * 本文（タイトルと URL）を作る。
	 *
	 * @param WP_Post $post 投稿。
	 * @return string
	 */
	private function build_text( WP_Post $post ) {
		$title = wp_strip_all_tags( html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) );
		$text  = trim( $title . "\n" . get_permalink( $post ) );

		/**
		 * Buffer に送る本文。
		 *
		 * @param string  $text 本文。
		 * @param WP_Post $post 投稿。
		 */
		return (string) apply_filters( 'wctb_post_text', $text, $post );
	}

	/**
	 * アイキャッチ画像の URL を返す。Buffer は HTTPS の URL だけを受け付けるので、それ以外は付けない。
	 *
	 * @param WP_Post $post 投稿。
	 * @return string
	 */
	private function image_url( WP_Post $post ) {
		$url = get_the_post_thumbnail_url( $post, 'full' );
		$url = is_string( $url ) && 0 === strpos( $url, 'https://' ) ? $url : '';

		/**
		 * Buffer に送る画像の URL。空文字なら画像を付けない。
		 *
		 * @param string  $url  画像の URL。
		 * @param WP_Post $post 投稿。
		 */
		return (string) apply_filters( 'wctb_post_image_url', $url, $post );
	}
}
