<?php
/**
 * 動作確認のスクリプト。Buffer への通信は模擬するので、実際の投稿は作られない。
 *
 * 実行: npx @wordpress/env run cli wp eval-file wp-content/plugins/wordpress-connect-to-buffer/tests/smoke.php
 *
 * @package WCTB
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$wctb_calls  = array();
$wctb_failed = 0;

$wctb_check = static function ( $label, $ok ) use ( &$wctb_failed ) {
	if ( ! $ok ) {
		++$wctb_failed;
	}
	WP_CLI::line( ( $ok ? 'ok   ' : 'FAIL ' ) . $label );
};

// Buffer への通信を横取りする。ch2 への投稿だけ失敗させる。
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$wctb_calls ) {
		if ( 0 !== strpos( $url, 'https://api.buffer.com' ) ) {
			return $pre;
		}

		$body = json_decode( $args['body'], true );

		if ( false !== strpos( $body['query'], 'organizations' ) ) {
			$data = array( 'account' => array( 'organizations' => array( array( 'id' => 'org1', 'name' => 'Org' ) ) ) );
		} elseif ( false !== strpos( $body['query'], 'channels(' ) ) {
			$data = array(
				'channels' => array(
					array( 'id' => 'ch1', 'name' => 'x', 'displayName' => 'X account', 'service' => 'instagram' ),
					array( 'id' => 'ch2', 'name' => 'fb', 'displayName' => '', 'service' => 'facebook' ),
				),
			);
		} else {
			$wctb_calls[] = array(
				'auth'  => $args['headers']['Authorization'],
				'input' => $body['variables']['input'],
			);

			if ( 'ch2' === $body['variables']['input']['channelId'] ) {
				$data = array( 'createPost' => array( '__typename' => 'InvalidInputError', 'message' => 'mock failure' ) );
			} else {
				$data = array( 'createPost' => array( '__typename' => 'PostActionSuccess', 'post' => array( 'id' => 'bp-' . count( $wctb_calls ) ) ) );
			}
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'data' => $data ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// 準備。保存済みの設定とログは退避し、最後に戻す。
$wctb_saved_settings = get_option( WCTB_Options::OPTION );
$wctb_saved_log      = get_option( WCTB_Options::LOG_OPTION );

delete_option( WCTB_Options::OPTION );
delete_option( WCTB_Options::LOG_OPTION );
delete_transient( WCTB_Options::CHANNEL_TRANSIENT );

$wctb_tag = wp_insert_term( 'wctb-smoke', 'post_tag' );
$wctb_tag = is_wp_error( $wctb_tag ) ? (int) $wctb_tag->get_error_data() : (int) $wctb_tag['term_id'];

// 設定の保存。
$wctb_settings = ( new WCTB_Settings() )->sanitize(
	array(
		'form'            => '1',
		'api_key_input'   => ' secret-key ',
		'channels_loaded' => '1',
		'channels'        => array( 'ch1', 'ch2' ),
		'tags_input'      => 'wctb-smoke、no-such-tag',
	)
);
update_option( WCTB_Options::OPTION, $wctb_settings );

$wctb_check( 'API キーが前後の空白を除いて保存される', 'secret-key' === $wctb_settings['api_key'] );
$wctb_check( 'チャンネルが 2 つ保存される', array( 'ch1', 'ch2' ) === $wctb_settings['channels'] );
$wctb_check( '存在するタグだけがターム ID で保存される', array( $wctb_tag ) === $wctb_settings['tags'] );
$wctb_check( '保存用の値をもう一度通しても変わらない', ( new WCTB_Settings() )->sanitize( $wctb_settings ) === $wctb_settings );

$wctb_kept = ( new WCTB_Settings() )->sanitize( array( 'form' => '1', 'api_key_input' => '', 'tags_input' => 'wctb-smoke' ) );
$wctb_check( 'キーが空欄で一覧が未表示なら、キーとチャンネルを保つ', 'secret-key' === $wctb_kept['api_key'] && array( 'ch1', 'ch2' ) === $wctb_kept['channels'] );

// チャンネルの取得。
$wctb_channels = ( new WCTB_Api( 'secret-key' ) )->get_channels();
$wctb_check( 'チャンネルの一覧を取得できる', is_array( $wctb_channels ) && 2 === count( $wctb_channels ) && 'X account' === $wctb_channels[0]['name'] && 'fb' === $wctb_channels[1]['name'] );

// タグ付きの投稿を公開する。
$wctb_post = wp_insert_post(
	array(
		'post_title'  => 'Smoke &amp; <b>test</b>',
		'post_status' => 'draft',
		'post_type'   => 'post',
		'tags_input'  => array( 'wctb-smoke' ),
	)
);
$wctb_check( '下書きでは送らない', 0 === count( $wctb_calls ) );

wp_update_post( array( 'ID' => $wctb_post, 'post_status' => 'publish' ) );
$wctb_check( '公開時に 2 つのチャンネルへ送る', 2 === count( $wctb_calls ) );
$wctb_check( 'Bearer 形式でキーを送る', 'Bearer secret-key' === $wctb_calls[0]['auth'] );
$wctb_check( '本文がタイトルと URL になる', "Smoke & test\n" . get_permalink( $wctb_post ) === $wctb_calls[0]['input']['text'] );
$wctb_check( 'キューに追加する指定になっている', 'addToQueue' === $wctb_calls[0]['input']['mode'] && 'automatic' === $wctb_calls[0]['input']['schedulingType'] );
$wctb_check( '送り方を選ばなければ下書きとして送る', true === $wctb_settings['draft'] && true === ( $wctb_calls[0]['input']['saveToDraft'] ?? null ) );
$wctb_check( '下書きとして送ったことがログに残る', true === ( WCTB_Options::get_log()[1]['draft'] ?? null ) );
$wctb_check( 'HTTPS でない画像は付けない', array() === $wctb_calls[0]['input']['assets'] );
$wctb_check(
	'Instagram には投稿の種類とフィードへの共有を指定する',
	array(
		'instagram' => array(
			'type'              => 'post',
			'shouldShareToFeed' => true,
		),
	) === ( $wctb_calls[0]['input']['metadata'] ?? null )
);
$wctb_check( 'Facebook には投稿の種類を指定する', array( 'facebook' => array( 'type' => 'post' ) ) === ( $wctb_calls[1]['input']['metadata'] ?? null ) );
$wctb_check( '成功したチャンネルだけを送信済みにする', array( 'ch1' => 'bp-1' ) === get_post_meta( $wctb_post, WCTB_Options::SENT_META, true ) );

$wctb_log = WCTB_Options::get_log();
$wctb_check( '失敗がログに残る', 2 === count( $wctb_log ) && false === $wctb_log[0]['success'] && 'mock failure' === $wctb_log[0]['message'] );

// 更新と再公開。
wp_update_post( array( 'ID' => $wctb_post, 'post_content' => 'updated' ) );
$wctb_check( '公開済みの投稿の更新では送らない', 2 === count( $wctb_calls ) );

wp_update_post( array( 'ID' => $wctb_post, 'post_status' => 'draft' ) );
wp_update_post( array( 'ID' => $wctb_post, 'post_status' => 'publish' ) );
$wctb_check( '再公開では失敗したチャンネルだけに送る', 3 === count( $wctb_calls ) && 'ch2' === $wctb_calls[2]['input']['channelId'] );

// タグのない投稿。
$wctb_calls  = array();
$wctb_no_tag = wp_insert_post( array( 'post_title' => 'No tag', 'post_status' => 'publish', 'post_type' => 'post' ) );
$wctb_check( '指定のタグがない投稿は送らない', 0 === count( $wctb_calls ) );

// 固定ページ。
$wctb_page = wp_insert_post( array( 'post_title' => 'Page', 'post_status' => 'publish', 'post_type' => 'page' ) );
$wctb_check( '固定ページは送らない', 0 === count( $wctb_calls ) );

// タイトル入りの縦長画像。ch1 だけに指定する。
// wp-env は http なので、アップロードの URL を https に見せかけて、画像が付く経路を通す。
$wctb_https = static function ( $dirs ) {
	$dirs['url']     = str_replace( 'http://', 'https://', $dirs['url'] );
	$dirs['baseurl'] = str_replace( 'http://', 'https://', $dirs['baseurl'] );

	return $dirs;
};
add_filter( 'upload_dir', $wctb_https );

$wctb_uploads = wp_upload_dir();
$wctb_source  = trailingslashit( $wctb_uploads['path'] ) . 'wctb-smoke-source.jpg';
$wctb_canvas  = imagecreatetruecolor( 1600, 900 );
imagefill( $wctb_canvas, 0, 0, imagecolorallocate( $wctb_canvas, 40, 90, 160 ) );
imagejpeg( $wctb_canvas, $wctb_source, 90 );
imagedestroy( $wctb_canvas );

$wctb_attachment = wp_insert_attachment(
	array(
		'post_title'     => 'wctb smoke',
		'post_mime_type' => 'image/jpeg',
		'post_status'    => 'inherit',
	),
	$wctb_source
);

$wctb_settings = ( new WCTB_Settings() )->sanitize(
	array(
		'form'            => '1',
		'channels_loaded' => '1',
		'channels'        => array( 'ch1', 'ch2' ),
		'title_image'     => array( 'ch1' ),
		'tags_input'      => 'wctb-smoke',
		'lead'            => '  サンプルサイトに新しい記事を公開しました ',
	)
);
update_option( WCTB_Options::OPTION, $wctb_settings );
$wctb_check( '固定の文面が前後の空白を除いて保存される', 'サンプルサイトに新しい記事を公開しました' === $wctb_settings['lead'] );
$wctb_check( '固定の文面を含む保存用の値をもう一度通しても変わらない', ( new WCTB_Settings() )->sanitize( $wctb_settings ) === $wctb_settings );
$wctb_check( 'タイトル入り画像を送るチャンネルが保存される', array( 'ch1' ) === $wctb_settings['title_image'] );

$wctb_kept = ( new WCTB_Settings() )->sanitize( array( 'form' => '1', 'tags_input' => 'wctb-smoke' ) );
$wctb_check( '一覧が未表示なら、タイトル入り画像の指定を保つ', array( 'ch1' ) === $wctb_kept['title_image'] );

$wctb_calls = array();
$wctb_titled = wp_insert_post(
	array(
		'post_title'  => '日本語のタイトルを画像に描き込む、折り返しの確認',
		'post_status' => 'publish',
		'post_type'   => 'post',
		'tags_input'  => array( 'wctb-smoke' ),
		'meta_input'  => array( '_thumbnail_id' => $wctb_attachment ),
	)
);

$wctb_title_url  = (string) ( $wctb_calls[0]['input']['assets'][0]['image']['url'] ?? '' );
$wctb_title_path = trailingslashit( $wctb_uploads['basedir'] ) . WCTB_Image::DIR . '/' . basename( $wctb_title_url );
$wctb_title_size = is_file( $wctb_title_path ) ? getimagesize( $wctb_title_path ) : array( 0, 0 );

$wctb_check( '指定したチャンネルにはタイトル入り画像の URL を送る', 1 === preg_match( '#^https://.+/wctb/post-' . $wctb_titled . '-[0-9a-f]{12}\.jpg$#', $wctb_title_url ) );
$wctb_check( 'タイトル入り画像を送る Instagram にはストーリーズを指定する', array( 'instagram' => array( 'type' => 'story', 'shouldShareToFeed' => false ) ) === ( $wctb_calls[0]['input']['metadata'] ?? null ) );
$wctb_check( 'タイトル入り画像は 1080×1920 になる', 1080 === $wctb_title_size[0] && 1920 === $wctb_title_size[1] );
$wctb_check( '指定のないチャンネルにはアイキャッチ画像をそのまま送る', 'wctb-smoke-source.jpg' === basename( (string) ( $wctb_calls[1]['input']['assets'][0]['image']['url'] ?? '' ) ) );
$wctb_check( '同じ画像とタイトルなら作り直さない', WCTB_Image::generate( get_post( $wctb_titled ) ) === $wctb_title_url );
$wctb_check(
	'固定の文面があれば、本文の 1 行目に入れる',
	"サンプルサイトに新しい記事を公開しました\n日本語のタイトルを画像に描き込む、折り返しの確認\n" . get_permalink( $wctb_titled ) === ( $wctb_calls[1]['input']['text'] ?? null )
);

// 固定の文面を変えると、画像を作り直す。
$wctb_other_lead = static function () {
	return '別の文面';
};
add_filter( 'wctb_lead_text', $wctb_other_lead );
$wctb_relead_url  = WCTB_Image::generate( get_post( $wctb_titled ) );
$wctb_title_glob  = trailingslashit( $wctb_uploads['basedir'] ) . WCTB_Image::DIR . '/post-' . $wctb_titled . '-*.jpg';
$wctb_relead_size = getimagesize( trailingslashit( $wctb_uploads['basedir'] ) . WCTB_Image::DIR . '/' . basename( $wctb_relead_url ) );
remove_filter( 'wctb_lead_text', $wctb_other_lead );
$wctb_check( '固定の文面を変えると、画像を作り直して古いものを消す', '' !== $wctb_relead_url && $wctb_relead_url !== $wctb_title_url && ! file_exists( $wctb_title_path ) && 1 === count( (array) glob( $wctb_title_glob ) ) );
$wctb_check( '固定の文面を入れた画像も 1080×1920 になる', 1080 === $wctb_relead_size[0] && 1920 === $wctb_relead_size[1] );

wp_delete_post( $wctb_titled, true );
$wctb_check( '投稿を消すとタイトル入り画像も消える', array() === (array) glob( $wctb_title_glob ) );

wp_delete_attachment( $wctb_attachment, true );
remove_filter( 'upload_dir', $wctb_https );
$wctb_calls = array();

// 予約投稿と画像。
add_filter(
	'wctb_post_image_url',
	static function () {
		return 'https://example.com/image.jpg';
	}
);

$wctb_future = wp_insert_post(
	array(
		'post_title'    => 'Future',
		'post_status'   => 'future',
		'post_type'     => 'post',
		'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		'tags_input'    => array( 'wctb-smoke' ),
	)
);
$wctb_check( '予約の時点では送らない', 0 === count( $wctb_calls ) );

wp_publish_post( $wctb_future );
$wctb_check( '予約投稿が公開されたら送る', 2 === count( $wctb_calls ) );
$wctb_check( '画像の URL を assets に入れる', array( array( 'image' => array( 'url' => 'https://example.com/image.jpg' ) ) ) === ( $wctb_calls[0]['input']['assets'] ?? null ) );

// サービスが分からないチャンネル。
$wctb_calls = array();
set_transient( WCTB_Options::CHANNEL_TRANSIENT, array( array( 'id' => 'ch1', 'name' => 'x', 'service' => 'twitter' ) ), HOUR_IN_SECONDS );
$wctb_plain = wp_insert_post( array( 'post_title' => 'Plain', 'post_status' => 'publish', 'post_type' => 'post', 'tags_input' => array( 'wctb-smoke' ) ) );
$wctb_check( 'Facebook と Instagram 以外には種類の指定を付けない', 2 === count( $wctb_calls ) && ! isset( $wctb_calls[0]['input']['metadata'] ) && ! isset( $wctb_calls[1]['input']['metadata'] ) );
wp_delete_post( $wctb_plain, true );

// タグ未指定なら全投稿。
$wctb_calls    = array();
$wctb_settings = ( new WCTB_Settings() )->sanitize( array( 'form' => '1', 'tags_input' => '', 'delivery' => 'queue' ) );
update_option( WCTB_Options::OPTION, $wctb_settings );
$wctb_all = wp_insert_post( array( 'post_title' => 'All', 'post_status' => 'publish', 'post_type' => 'post' ) );
$wctb_check( 'タグ未指定ならタグのない投稿も送る', 2 === count( $wctb_calls ) );
$wctb_check( '「キューに追加」を選ぶと下書きにしない', false === $wctb_settings['draft'] && ! isset( $wctb_calls[0]['input']['saveToDraft'] ) );

// カテゴリー。親を指定し、子と孫、無関係のカテゴリーで確かめる。
$wctb_term = static function ( $name, $parent = 0 ) {
	$term = wp_insert_term( $name, 'category', array( 'parent' => $parent ) );

	return is_wp_error( $term ) ? (int) $term->get_error_data() : (int) $term['term_id'];
};

$wctb_cat_parent = $wctb_term( 'wctb-smoke-parent' );
$wctb_cat_child  = $wctb_term( 'wctb-smoke-child', $wctb_cat_parent );
$wctb_cat_grand  = $wctb_term( 'wctb-smoke-grandchild', $wctb_cat_child );
$wctb_cat_other  = $wctb_term( 'wctb-smoke-other' );

$wctb_settings = ( new WCTB_Settings() )->sanitize(
	array(
		'form'         => '1',
		'delivery'     => 'queue',
		'tags_input'   => '',
		'categories'   => array( (string) $wctb_cat_parent, (string) $wctb_cat_parent, '999999999' ),
		'category_add' => '0',
	)
);
update_option( WCTB_Options::OPTION, $wctb_settings );
$wctb_check( '存在するカテゴリーだけが重複なく保存される', array( $wctb_cat_parent ) === $wctb_settings['categories'] );
$wctb_check( 'カテゴリーを含む保存用の値をもう一度通しても変わらない', ( new WCTB_Settings() )->sanitize( $wctb_settings ) === $wctb_settings );

$wctb_added = ( new WCTB_Settings() )->sanitize(
	array(
		'form'         => '1',
		'categories'   => array( (string) $wctb_cat_parent ),
		'category_add' => (string) $wctb_cat_other,
	)
);
$wctb_check( 'プルダウンで選んだまま保存したカテゴリーも足される', array( $wctb_cat_parent, $wctb_cat_other ) === $wctb_added['categories'] );

$wctb_cat_posts = array();
$wctb_publish   = static function ( $title, array $categories, array $tags = array() ) use ( &$wctb_cat_posts, &$wctb_calls ) {
	$wctb_calls       = array();
	$wctb_cat_posts[] = wp_insert_post(
		array(
			'post_title'    => $title,
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_category' => $categories,
			'tags_input'    => $tags,
		)
	);

	return count( $wctb_calls );
};

$wctb_check( '指定したカテゴリーの投稿は送る', 2 === $wctb_publish( 'In parent', array( $wctb_cat_parent ) ) );
$wctb_check( '下位のカテゴリーの投稿も送る', 2 === $wctb_publish( 'In child', array( $wctb_cat_child ) ) );
$wctb_check( '孫のカテゴリーの投稿も送る', 2 === $wctb_publish( 'In grandchild', array( $wctb_cat_grand ) ) );
$wctb_check( '指定のカテゴリーにない投稿は送らない', 0 === $wctb_publish( 'In other', array( $wctb_cat_other ) ) );
$wctb_check( '複数のカテゴリーのうち 1 つが当てはまれば送る', 2 === $wctb_publish( 'In both', array( $wctb_cat_other, $wctb_cat_grand ) ) );

// 下位のカテゴリーを指定したときは、上位のカテゴリーの投稿を送らない。
$wctb_settings = ( new WCTB_Settings() )->sanitize( array( 'form' => '1', 'delivery' => 'queue', 'categories' => array( (string) $wctb_cat_child ) ) );
update_option( WCTB_Options::OPTION, $wctb_settings );
$wctb_check( '下位を指定したときは上位のカテゴリーの投稿を送らない', 0 === $wctb_publish( 'Parent only', array( $wctb_cat_parent ) ) );

// タグとカテゴリーの両方を指定する。
$wctb_settings = ( new WCTB_Settings() )->sanitize(
	array(
		'form'       => '1',
		'delivery'   => 'queue',
		'tags_input' => 'wctb-smoke',
		'categories' => array( (string) $wctb_cat_parent ),
	)
);
update_option( WCTB_Options::OPTION, $wctb_settings );
$wctb_check( 'タグとカテゴリーの両方を満たす投稿は送る', 2 === $wctb_publish( 'Tag and category', array( $wctb_cat_child ), array( 'wctb-smoke' ) ) );
$wctb_check( 'カテゴリーだけを満たす投稿は送らない', 0 === $wctb_publish( 'Category only', array( $wctb_cat_child ) ) );
$wctb_check( 'タグだけを満たす投稿は送らない', 0 === $wctb_publish( 'Tag only', array( $wctb_cat_other ), array( 'wctb-smoke' ) ) );

// テスト送信。設定は「キューに追加」、タグ wctb-smoke、カテゴリー wctb-smoke-parent。
$wctb_publisher = new WCTB_Publisher();
$wctb_target    = get_post( $wctb_cat_posts[6] ); // Tag and category。公開時に ch1 へ送信済み。
$wctb_sent_meta = get_post_meta( $wctb_target->ID, WCTB_Options::SENT_META, true );

$wctb_calls  = array();
$wctb_result = $wctb_publisher->send_test( $wctb_target );
$wctb_log    = WCTB_Options::get_log();

$wctb_check( 'テスト送信は送信済みのチャンネルにも送る', 2 === count( $wctb_calls ) && is_array( $wctb_result ) && 'ch1' === $wctb_calls[0]['input']['channelId'] );
$wctb_check( 'テスト送信は「キューに追加」の設定でも下書きとして送る', true === ( $wctb_calls[0]['input']['saveToDraft'] ?? null ) && true === ( $wctb_calls[1]['input']['saveToDraft'] ?? null ) );
$wctb_check( 'テスト送信はチャンネルごとの結果を返す', is_string( $wctb_result['ch1'] ?? null ) && is_wp_error( $wctb_result['ch2'] ?? null ) );
$wctb_check( 'テスト送信であることがログに残る', true === ( $wctb_log[0]['test'] ?? null ) && true === ( $wctb_log[0]['draft'] ?? null ) && $wctb_target->ID === $wctb_log[0]['post_id'] );
$wctb_check( 'テスト送信は送信済みの記録を変えない', get_post_meta( $wctb_target->ID, WCTB_Options::SENT_META, true ) === $wctb_sent_meta );

$wctb_calls  = array();
$wctb_result = $wctb_publisher->send_test( get_post( $wctb_cat_posts[8] ) ); // Tag only。
$wctb_check( '絞り込みの対象外の投稿はテスト送信せず、理由を返す', 0 === count( $wctb_calls ) && is_wp_error( $wctb_result ) && 'categories' === $wctb_result->get_error_code() );

$wctb_result = $wctb_publisher->send_test( get_post( $wctb_cat_posts[7] ) ); // Category only。
$wctb_check( 'タグが合わない投稿は、タグを理由として返す', 0 === count( $wctb_calls ) && is_wp_error( $wctb_result ) && 'tags' === $wctb_result->get_error_code() );

$wctb_unpublished = wp_insert_post( array( 'post_title' => 'Unpublished', 'post_status' => 'draft', 'post_type' => 'post' ) );
$wctb_result      = $wctb_publisher->send_test( get_post( $wctb_unpublished ) );
$wctb_check( '公開していない投稿はテスト送信しない', 0 === count( $wctb_calls ) && is_wp_error( $wctb_result ) && 'not_published' === $wctb_result->get_error_code() );
$wctb_cat_posts[] = $wctb_unpublished;

// 設定画面からの実行。転送の直前で例外を投げて、exit を避ける。
wp_set_current_user( 1 );
$wctb_stop = static function () {
	throw new RuntimeException( 'redirect' );
};
add_filter( 'wp_redirect', $wctb_stop, 1 );

$_POST['wctb_test_post'] = (string) $wctb_target->ID;
$_REQUEST['_wpnonce']    = wp_create_nonce( WCTB_Settings::TEST_ACTION );
$wctb_calls              = array();

try {
	( new WCTB_Settings() )->handle_test_send();
} catch ( RuntimeException $wctb_exception ) {
	unset( $wctb_exception );
}
remove_filter( 'wp_redirect', $wctb_stop, 1 );

$wctb_notice = get_transient( WCTB_Settings::TEST_NOTICE . '1' );
$wctb_check( '設定画面からのテスト送信で 2 つのチャンネルへ送る', 2 === count( $wctb_calls ) );
$wctb_check( '設定画面に出す結果が残る', is_array( $wctb_notice ) && 'error' === $wctb_notice['type'] && false !== strpos( $wctb_notice['message'], 'for 1 channel(s), failed for 1' ) );

// タブごとの表示。
$wctb_render = static function ( $tab ) {
	if ( '' === $tab ) {
		unset( $_GET['tab'] );
	} else {
		$_GET['tab'] = $tab;
	}

	ob_start();
	( new WCTB_Settings() )->render();

	return ob_get_clean();
};

$wctb_html = $wctb_render( 'log' );
$wctb_check( 'ログのタブに、テスト送信の結果とログが出る', false !== strpos( $wctb_html, 'notice notice-error' ) && false !== strpos( $wctb_html, 'Test: Saved as draft' ) && false !== strpos( $wctb_html, 'Test: Failed: mock failure' ) );
$wctb_check( 'ログのタブに、設定とテスト送信の欄は出ない', false === strpos( $wctb_html, 'id="wctb-tags"' ) && false === strpos( $wctb_html, 'name="wctb_test_post"' ) );
$wctb_check( '結果は 1 回だけ表示する', false === get_transient( WCTB_Settings::TEST_NOTICE . '1' ) );

$wctb_html = $wctb_render( 'test' );
$wctb_check( 'テストのタブに、投稿の一覧と判定が出る', false !== strpos( $wctb_html, 'name="wctb_test_post"' ) && false !== strpos( $wctb_html, 'Tag only — will not be sent: the post is in none of the categories' ) );
$wctb_check( 'テストのタブに、設定とログは出ない', false === strpos( $wctb_html, 'id="wctb-tags"' ) && false === strpos( $wctb_html, 'Test: Saved as draft' ) );

$wctb_html = $wctb_render( '' );
$wctb_check( 'タブの指定がなければ設定のタブを出す', false !== strpos( $wctb_html, 'id="wctb-tags"' ) && false !== strpos( $wctb_html, "id='wctb-category-add'" ) && false === strpos( $wctb_html, 'name="wctb_test_post"' ) && false === strpos( $wctb_html, 'Test: Saved as draft' ) );
$wctb_check( '知らないタブの指定は設定のタブにする', $wctb_render( 'no-such-tab' ) === $wctb_html );
$wctb_check( '3 つのタブへのリンクが出る', 3 === preg_match_all( '/class="nav-tab( nav-tab-active)?"/', $wctb_html ) && 1 === substr_count( $wctb_html, 'nav-tab-active' ) && false !== strpos( $wctb_html, 'page=wctb&#038;tab=test' ) && false !== strpos( $wctb_html, 'page=wctb&#038;tab=log' ) );

unset( $_GET['tab'], $_POST['wctb_test_post'], $_REQUEST['_wpnonce'] );
wp_set_current_user( 0 );

// 後始末。
foreach ( array_merge( array( $wctb_post, $wctb_no_tag, $wctb_page, $wctb_future, $wctb_all ), $wctb_cat_posts ) as $wctb_id ) {
	wp_delete_post( $wctb_id, true );
}
foreach ( array( $wctb_cat_grand, $wctb_cat_child, $wctb_cat_parent, $wctb_cat_other ) as $wctb_id ) {
	wp_delete_term( $wctb_id, 'category' );
}
wp_delete_term( $wctb_tag, 'post_tag' );
delete_option( WCTB_Options::OPTION );
delete_option( WCTB_Options::LOG_OPTION );
delete_transient( WCTB_Options::CHANNEL_TRANSIENT );

if ( false !== $wctb_saved_settings ) {
	update_option( WCTB_Options::OPTION, $wctb_saved_settings );
}
if ( false !== $wctb_saved_log ) {
	update_option( WCTB_Options::LOG_OPTION, $wctb_saved_log, false );
}

if ( $wctb_failed ) {
	WP_CLI::error( $wctb_failed . ' 件の確認に失敗した。' );
}

WP_CLI::success( 'すべての確認に通った。' );
