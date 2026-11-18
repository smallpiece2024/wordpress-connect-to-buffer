<?php
/**
 * Buffer の GraphQL API を呼び出すクライアント。
 *
 * @package WCTB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buffer API クライアント。
 */
class WCTB_Api {

	const ENDPOINT = 'https://api.buffer.com';

	/**
	 * API キー。
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * コンストラクタ。
	 *
	 * @param string $api_key Buffer の API キー。
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * GraphQL のリクエストを送り、data を返す。
	 *
	 * Buffer は失敗時も HTTP 200 を返すことがあるので、本文の errors を見て判定する。
	 *
	 * @param string $query     クエリ。
	 * @param array  $variables 変数。
	 * @return array|WP_Error
	 */
	public function request( $query, array $variables = array() ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'wctb_no_api_key', __( 'The Buffer API key is not set.', 'wordpress-connect-to-buffer' ) );
		}

		$body = array( 'query' => $query );
		if ( $variables ) {
			$body['variables'] = $variables;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['errors'][0] ) ) {
			$error = $data['errors'][0];
			$code  = isset( $error['extensions']['code'] ) ? (string) $error['extensions']['code'] : 'UNKNOWN';
			$text  = isset( $error['message'] ) ? (string) $error['message'] : '';

			return new WP_Error( 'wctb_api_error', trim( $code . ': ' . $text, ': ' ) );
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wctb_bad_response', sprintf( __( 'Unexpected response from Buffer (HTTP %d).', 'wordpress-connect-to-buffer' ), $status ) );
		}

		return $data['data'];
	}

	/**
	 * アカウントが属するすべての organization のチャンネルを返す。
	 *
	 * @return array[]|WP_Error id、name、service を持つ配列のリスト。
	 */
	public function get_channels() {
		$data = $this->request( 'query { account { organizations { id name } } }' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$organizations = isset( $data['account']['organizations'] ) ? (array) $data['account']['organizations'] : array();
		$channels      = array();

		foreach ( $organizations as $organization ) {
			if ( empty( $organization['id'] ) ) {
				continue;
			}

			// 入力の型名が文書にないため、ID を文字列リテラルとして埋め込む。JSON の文字列は GraphQL の文字列リテラルとして有効。
			$query = sprintf(
				'query { channels(input: { organizationId: %s }) { id name displayName service } }',
				wp_json_encode( (string) $organization['id'] )
			);

			$result = $this->request( $query );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( (array) ( $result['channels'] ?? array() ) as $channel ) {
				if ( empty( $channel['id'] ) ) {
					continue;
				}

				$name = ! empty( $channel['displayName'] ) ? $channel['displayName'] : ( $channel['name'] ?? '' );

				$channels[] = array(
					'id'      => (string) $channel['id'],
					'name'    => (string) $name,
					'service' => (string) ( $channel['service'] ?? '' ),
				);
			}
		}

		return $channels;
	}

	/**
	 * 投稿をチャンネルのキューに追加する。$draft が true なら下書きとして保存する。
	 *
	 * @param string $channel_id チャンネル ID。
	 * @param string $text       本文。
	 * @param string $image_url  画像の URL。空なら画像なし。
	 * @param array  $metadata   サービス固有の指定（PostInputMetaData）。空なら付けない。
	 * @param bool   $draft      true なら予約せず、Buffer の下書きとして保存する。
	 * @return string|WP_Error Buffer の投稿 ID。
	 */
	public function create_post( $channel_id, $text, $image_url = '', array $metadata = array(), $draft = false ) {
		$query = 'mutation CreatePost($input: CreatePostInput!) {
			createPost(input: $input) {
				__typename
				... on PostActionSuccess { post { id } }
				... on MutationError { message }
			}
		}';

		$input = array(
			'channelId'      => (string) $channel_id,
			'text'           => (string) $text,
			'schedulingType' => 'automatic',
			'mode'           => 'addToQueue',
			'assets'         => array(),
		);

		if ( '' !== $image_url ) {
			$input['assets'][] = array( 'image' => array( 'url' => (string) $image_url ) );
		}

		if ( $metadata ) {
			$input['metadata'] = $metadata;
		}

		if ( $draft ) {
			$input['saveToDraft'] = true;
		}

		$data = $this->request( $query, array( 'input' => $input ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = isset( $data['createPost'] ) && is_array( $data['createPost'] ) ? $data['createPost'] : array();

		if ( ! empty( $result['post']['id'] ) ) {
			return (string) $result['post']['id'];
		}

		$message = ! empty( $result['message'] ) ? (string) $result['message'] : __( 'Buffer did not return a post.', 'wordpress-connect-to-buffer' );

		return new WP_Error( 'wctb_mutation_error', $message );
	}
}
