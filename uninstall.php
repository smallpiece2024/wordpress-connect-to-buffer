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
