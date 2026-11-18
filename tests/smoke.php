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
$wctb_calls            = array();
$wctb_settings['tags'] = array();
update_option( WCTB_Options::OPTION, $wctb_settings );
$wctb_all = wp_insert_post( array( 'post_title' => 'All', 'post_status' => 'publish', 'post_type' => 'post' ) );
$wctb_check( 'タグ未指定ならタグのない投稿も送る', 2 === count( $wctb_calls ) );

// 後始末。
foreach ( array( $wctb_post, $wctb_no_tag, $wctb_page, $wctb_future, $wctb_all ) as $wctb_id ) {
	wp_delete_post( $wctb_id, true );
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
