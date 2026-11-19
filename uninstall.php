<?php
/**
 * プラグインの削除時に、保存したデータを消す。
 *
 * @package WCTB
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wctb_settings' );
delete_option( 'wctb_log' );
delete_transient( 'wctb_channels' );
delete_post_meta_by_key( '_wctb_sent' );

// 生成したタイトル入り画像。
$wctb_uploads = wp_upload_dir( null, false );
$wctb_dir     = trailingslashit( $wctb_uploads['basedir'] ) . 'wctb';

foreach ( (array) glob( $wctb_dir . '/post-*.jpg' ) as $wctb_file ) {
	wp_delete_file( $wctb_file );
}

if ( is_dir( $wctb_dir ) ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- ほかのファイルが残っていれば消さない。
	@rmdir( $wctb_dir );
}
