<?php
/**
 * 設定値と送信ログの読み書き。
 *
 * @package WCTB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * オプションへのアクセスをまとめる。
 */
class WCTB_Options {

	const OPTION            = 'wctb_settings';
	const LOG_OPTION        = 'wctb_log';
	const CHANNEL_TRANSIENT = 'wctb_channels';
	const SENT_META         = '_wctb_sent';
	const LOG_LIMIT         = 20;

	/**
	 * 保存済みの設定を、既定値で補って返す。
	 *
	 * @return array{api_key:string,channels:string[],tags:int[]}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'api_key'  => isset( $saved['api_key'] ) ? (string) $saved['api_key'] : '',
			'channels' => isset( $saved['channels'] ) ? array_values( array_map( 'strval', (array) $saved['channels'] ) ) : array(),
			'tags'     => isset( $saved['tags'] ) ? array_values( array_map( 'intval', (array) $saved['tags'] ) ) : array(),
		);
	}

	/**
	 * API キーが wp-config.php の定数で与えられているか。
	 *
	 * @return bool
	 */
	public static function has_key_constant() {
		return defined( 'WCTB_API_KEY' ) && '' !== (string) WCTB_API_KEY;
	}

	/**
	 * 使用する API キー。定数があれば定数を優先する。
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::has_key_constant() ) {
			return (string) WCTB_API_KEY;
		}

		$settings = self::get();

		return $settings['api_key'];
	}

	/**
	 * 送信結果を 1 件記録する。新しいものから LOG_LIMIT 件だけ残す。
	 *
	 * @param int    $post_id 投稿 ID。
	 * @param string $channel チャンネル ID。
	 * @param bool   $success 成功したか。
	 * @param string $message Buffer の投稿 ID、またはエラーの内容。
	 */
	public static function add_log( $post_id, $channel, $success, $message ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'post_id' => (int) $post_id,
				'channel' => (string) $channel,
				'success' => (bool) $success,
				'message' => (string) $message,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * 送信ログを新しい順に返す。
	 *
	 * @return array[]
	 */
	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}
}
