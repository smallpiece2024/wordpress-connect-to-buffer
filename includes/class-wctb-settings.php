<?php
/**
 * 設定画面。
 *
 * @package WCTB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 「設定 > Connect to Buffer」の画面。
 */
class WCTB_Settings {

	const PAGE  = 'wctb';
	const GROUP = 'wctb';

	/**
	 * フックを登録する。
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCTB_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * 設定メニューにページを追加する。
	 */
	public function add_page() {
		add_options_page(
			__( 'Connect to Buffer', 'wordpress-connect-to-buffer' ),
			__( 'Connect to Buffer', 'wordpress-connect-to-buffer' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * 設定を登録する。
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			WCTB_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * プラグイン一覧に設定画面へのリンクを足す。
	 *
	 * @param string[] $links 既存のリンク。
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wordpress-connect-to-buffer' ) . '</a>' );

		return $links;
	}

	/**
	 * フォームの入力を保存用の形に直す。
	 *
	 * @param mixed $input フォームの入力。
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		// オプションを初めて保存するとき、WordPress はこのコールバックを保存用の値でもう一度呼ぶ。
		// フォームからの入力でなければ、形だけ整えて返す。
		if ( empty( $input['form'] ) ) {
			return array(
				'api_key'     => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
				'channels'    => isset( $input['channels'] ) ? array_values( array_map( 'sanitize_text_field', (array) $input['channels'] ) ) : array(),
				'tags'        => isset( $input['tags'] ) ? array_values( array_filter( array_map( 'absint', (array) $input['tags'] ) ) ) : array(),
				'draft'       => isset( $input['draft'] ) ? (bool) $input['draft'] : true,
				'title_image' => isset( $input['title_image'] ) ? array_values( array_map( 'sanitize_text_field', (array) $input['title_image'] ) ) : array(),
			);
		}

		$new = WCTB_Options::get();

		// 送り方。「キューに追加」を選んだときだけ、下書きにしない。
		$new['draft'] = ! ( isset( $input['delivery'] ) && 'queue' === $input['delivery'] );

		// API キー。空欄なら保存済みのキーを保つ。
		if ( ! WCTB_Options::has_key_constant() ) {
			$old_key = $new['api_key'];
			$typed   = isset( $input['api_key_input'] ) ? trim( sanitize_text_field( $input['api_key_input'] ) ) : '';

			if ( ! empty( $input['delete_api_key'] ) ) {
				$new['api_key'] = '';
			} elseif ( '' !== $typed ) {
				$new['api_key'] = $typed;
			}

			if ( $new['api_key'] !== $old_key ) {
				delete_transient( WCTB_Options::CHANNEL_TRANSIENT );
			}
		}

		// チャンネル。一覧を表示できなかったときは、保存済みの選択を保つ。
		if ( ! empty( $input['channels_loaded'] ) ) {
			$channels        = isset( $input['channels'] ) ? (array) $input['channels'] : array();
			$new['channels'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $channels ) ) ) );

			// タイトル入り画像を送るチャンネル。投稿先から外したチャンネルの指定も、選び直したときのために残す。
			$title_image        = isset( $input['title_image'] ) ? (array) $input['title_image'] : array();
			$new['title_image'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $title_image ) ) ) );
		}

		// タグ。名前かスラッグで受け取り、ターム ID で保存する。
		$names   = isset( $input['tags_input'] ) ? preg_split( '/[,、]/u', (string) $input['tags_input'] ) : array();
		$tag_ids = array();
		$unknown = array();

		foreach ( (array) $names as $name ) {
			$name = trim( sanitize_text_field( $name ) );
			if ( '' === $name ) {
				continue;
			}

			$term = get_term_by( 'name', $name, 'post_tag' );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $name ), 'post_tag' );
			}

			if ( $term ) {
				$tag_ids[] = (int) $term->term_id;
			} else {
				$unknown[] = $name;
			}
		}

		$new['tags'] = array_values( array_unique( $tag_ids ) );

		if ( $unknown ) {
			add_settings_error(
				WCTB_Options::OPTION,
				'wctb_unknown_tags',
				sprintf(
					/* translators: %s: comma-separated tag names. */
					__( 'These tags do not exist and were ignored: %s', 'wordpress-connect-to-buffer' ),
					implode( ', ', $unknown )
				)
			);
		}

		return $new;
	}

	/**
	 * 設定画面を出力する。
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['wctb_refresh'] ) && check_admin_referer( 'wctb_refresh' ) ) {
			delete_transient( WCTB_Options::CHANNEL_TRANSIENT );
		}

		$settings = WCTB_Options::get();
		$has_key  = '' !== WCTB_Options::api_key();
		$channels = $has_key ? WCTB_Options::channels() : array();
		$name     = WCTB_Options::OPTION;

		$tag_names = array();
		foreach ( $settings['tags'] as $tag_id ) {
			$term = get_term( $tag_id, 'post_tag' );
			if ( $term instanceof WP_Term ) {
				$tag_names[] = $term->name;
			}
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[form]" value="1" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wctb-api-key"><?php esc_html_e( 'Buffer API key', 'wordpress-connect-to-buffer' ); ?></label>
						</th>
						<td>
							<?php if ( WCTB_Options::has_key_constant() ) : ?>
								<p><?php esc_html_e( 'The API key is set by the WCTB_API_KEY constant in wp-config.php.', 'wordpress-connect-to-buffer' ); ?></p>
							<?php else : ?>
								<input type="password" id="wctb-api-key" class="regular-text" autocomplete="off"
									name="<?php echo esc_attr( $name ); ?>[api_key_input]" value=""
									placeholder="<?php echo esc_attr( $has_key ? __( 'Saved. Enter a new key to replace it.', 'wordpress-connect-to-buffer' ) : '' ); ?>" />
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL of the Buffer API settings page. */
										esc_html__( 'Create a key at %s.', 'wordpress-connect-to-buffer' ),
										'<a href="https://publish.buffer.com/settings/api" target="_blank" rel="noopener noreferrer">publish.buffer.com/settings/api</a>'
									);
									?>
								</p>
								<?php if ( $has_key ) : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_api_key]" value="1" />
										<?php esc_html_e( 'Delete the saved key', 'wordpress-connect-to-buffer' ); ?>
									</label>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Channels', 'wordpress-connect-to-buffer' ); ?></th>
						<td>
							<?php if ( ! $has_key ) : ?>
								<p><?php esc_html_e( 'Save an API key to load your channels.', 'wordpress-connect-to-buffer' ); ?></p>
							<?php elseif ( is_wp_error( $channels ) ) : ?>
								<p>
									<?php
									printf(
										/* translators: %s: error message. */
										esc_html__( 'Could not load channels: %s', 'wordpress-connect-to-buffer' ),
										esc_html( $channels->get_error_message() )
									);
									?>
								</p>
							<?php elseif ( ! $channels ) : ?>
								<p><?php esc_html_e( 'No channels were found in this Buffer account.', 'wordpress-connect-to-buffer' ); ?></p>
							<?php else : ?>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[channels_loaded]" value="1" />
								<table class="widefat striped" style="width: auto;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Send to this channel', 'wordpress-connect-to-buffer' ); ?></th>
											<th><?php esc_html_e( 'Image', 'wordpress-connect-to-buffer' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $channels as $channel ) : ?>
											<tr>
												<td>
													<label>
														<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[channels][]"
															value="<?php echo esc_attr( $channel['id'] ); ?>"
															<?php checked( in_array( $channel['id'], $settings['channels'], true ) ); ?> />
														<?php echo esc_html( $channel['name'] . ' (' . $channel['service'] . ')' ); ?>
													</label>
												</td>
												<td>
													<label>
														<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[title_image][]"
															value="<?php echo esc_attr( $channel['id'] ); ?>"
															<?php checked( in_array( $channel['id'], $settings['title_image'], true ) ); ?> />
														<?php esc_html_e( 'Vertical image with the title (1080×1920)', 'wordpress-connect-to-buffer' ); ?>
													</label>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<p class="description">
									<?php esc_html_e( 'With "Vertical image with the title", the featured image is cropped to 9:16 around its center and the post title is drawn on it. Channels without it receive the featured image as it is.', 'wordpress-connect-to-buffer' ); ?>
								</p>
								<?php if ( ! WCTB_Image::is_supported() ) : ?>
									<p class="description">
										<strong><?php esc_html_e( 'This server cannot create the vertical image, because the GD extension of PHP with FreeType support is missing. The featured image is sent as it is.', 'wordpress-connect-to-buffer' ); ?></strong>
									</p>
								<?php endif; ?>
							<?php endif; ?>

							<?php if ( $has_key ) : ?>
								<p>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=' . self::PAGE . '&wctb_refresh=1' ), 'wctb_refresh' ) ); ?>">
										<?php esc_html_e( 'Reload channels', 'wordpress-connect-to-buffer' ); ?>
									</a>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'When a post is published', 'wordpress-connect-to-buffer' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( $name ); ?>[delivery]" value="draft" <?php checked( $settings['draft'] ); ?> />
									<?php esc_html_e( 'Save it as a draft in Buffer', 'wordpress-connect-to-buffer' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( $name ); ?>[delivery]" value="queue" <?php checked( ! $settings['draft'] ); ?> />
									<?php esc_html_e( 'Add it to the queue', 'wordpress-connect-to-buffer' ); ?>
								</label>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'A draft is not published until you schedule it in Buffer. A queued post is published automatically at the next slot of your posting schedule.', 'wordpress-connect-to-buffer' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wctb-tags"><?php esc_html_e( 'Tags', 'wordpress-connect-to-buffer' ); ?></label>
						</th>
						<td>
							<input type="text" id="wctb-tags" class="regular-text"
								name="<?php echo esc_attr( $name ); ?>[tags_input]"
								value="<?php echo esc_attr( implode( ', ', $tag_names ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Comma-separated tag names. Only posts with at least one of these tags are sent. Leave empty to send every post.', 'wordpress-connect-to-buffer' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_log( is_array( $channels ) ? $channels : array() ); ?>
		</div>
		<?php
	}

	/**
	 * 送信ログを出力する。
	 *
	 * @param array[] $channels チャンネルの一覧。ID を名前に直すために使う。
	 */
	private function render_log( array $channels ) {
		$log = WCTB_Options::get_log();
		if ( ! $log ) {
			return;
		}

		$names = array();
		foreach ( $channels as $channel ) {
			$names[ $channel['id'] ] = $channel['name'] . ' (' . $channel['service'] . ')';
		}
		?>
		<h2><?php esc_html_e( 'Recent activity', 'wordpress-connect-to-buffer' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'wordpress-connect-to-buffer' ); ?></th>
					<th><?php esc_html_e( 'Post', 'wordpress-connect-to-buffer' ); ?></th>
					<th><?php esc_html_e( 'Channel', 'wordpress-connect-to-buffer' ); ?></th>
					<th><?php esc_html_e( 'Result', 'wordpress-connect-to-buffer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $entry['time'] ) ); ?></td>
						<td><?php echo esc_html( get_the_title( (int) $entry['post_id'] ) ); ?></td>
						<td><?php echo esc_html( $names[ $entry['channel'] ] ?? $entry['channel'] ); ?></td>
						<td>
							<?php
							if ( $entry['success'] && ! empty( $entry['draft'] ) ) {
								esc_html_e( 'Saved as draft', 'wordpress-connect-to-buffer' );
							} elseif ( $entry['success'] ) {
								esc_html_e( 'Added to queue', 'wordpress-connect-to-buffer' );
							} else {
								/* translators: %s: error message. */
								echo esc_html( sprintf( __( 'Failed: %s', 'wordpress-connect-to-buffer' ), $entry['message'] ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
