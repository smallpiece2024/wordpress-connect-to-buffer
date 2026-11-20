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

	const TEST_ACTION = 'wctb_test_send';
	const TEST_NOTICE = 'wctb_test_notice_';

	/**
	 * フックを登録する。
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'handle_test_send' ) );
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
				'categories'  => isset( $input['categories'] ) ? array_values( array_filter( array_map( 'absint', (array) $input['categories'] ) ) ) : array(),
				'draft'       => isset( $input['draft'] ) ? (bool) $input['draft'] : true,
				'title_image' => isset( $input['title_image'] ) ? array_values( array_map( 'sanitize_text_field', (array) $input['title_image'] ) ) : array(),
				'lead'        => isset( $input['lead'] ) ? sanitize_text_field( $input['lead'] ) : '',
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

		// タイトルの前に付ける固定の文面。
		$new['lead'] = isset( $input['lead'] ) ? sanitize_text_field( $input['lead'] ) : '';

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

		// カテゴリー。一覧に足したものに加えて、プルダウンで選んだまま保存したものも足す。
		$category_ids = isset( $input['categories'] ) ? (array) $input['categories'] : array();
		if ( ! empty( $input['category_add'] ) ) {
			$category_ids[] = $input['category_add'];
		}

		$new['categories'] = array();
		foreach ( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) as $category_id ) {
			if ( get_term( $category_id, 'category' ) instanceof WP_Term ) {
				$new['categories'][] = $category_id;
			}
		}

		return $new;
	}

	/**
	 * 設定画面のタブ。キーは URL の tab に入る値。
	 *
	 * @return array<string,string>
	 */
	private function tabs() {
		return array(
			'settings' => __( 'Settings', 'wordpress-connect-to-buffer' ),
			'test'     => __( 'Test', 'wordpress-connect-to-buffer' ),
			'log'      => __( 'Recent activity', 'wordpress-connect-to-buffer' ),
		);
	}

	/**
	 * タブの URL を返す。
	 *
	 * @param string $tab タブのキー。
	 * @return string
	 */
	private function tab_url( $tab ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );

		return 'settings' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * テスト送信を実行し、結果を設定画面に表示するために残して、設定画面へ戻る。
	 */
	public function handle_test_send() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wordpress-connect-to-buffer' ), 403 );
		}

		check_admin_referer( self::TEST_ACTION );

		$post   = get_post( isset( $_POST['wctb_test_post'] ) ? absint( $_POST['wctb_test_post'] ) : 0 );
		$result = $post instanceof WP_Post ? ( new WCTB_Publisher() )->send_test( $post ) : new WP_Error( 'not_published', 'not_published' );

		if ( is_wp_error( $result ) ) {
			$notice = array(
				'type'    => 'warning',
				/* translators: %s: reason. */
				'message' => sprintf( __( 'The test was not sent: %s', 'wordpress-connect-to-buffer' ), $this->reason_label( $result->get_error_code() ) ),
			);
		} else {
			$failed = count( array_filter( $result, 'is_wp_error' ) );
			$notice = array(
				'type'    => $failed ? 'error' : 'success',
				'message' => sprintf(
					/* translators: 1: post title, 2: number of channels that succeeded, 3: number of channels that failed. */
					__( 'Test of "%1$s": saved as a draft in Buffer for %2$d channel(s), failed for %3$d.', 'wordpress-connect-to-buffer' ),
					get_the_title( $post ),
					count( $result ) - $failed,
					$failed
				),
			);
		}

		set_transient( self::TEST_NOTICE . get_current_user_id(), $notice, MINUTE_IN_SECONDS );

		// 送った結果はログのタブで見せる。送らなかったときは、テストのタブに戻って選び直せるようにする。
		wp_safe_redirect( $this->tab_url( is_wp_error( $result ) ? 'test' : 'log' ) );
		exit;
	}

	/**
	 * 送らない理由を、画面に出す文にする。
	 *
	 * @param string $reason WCTB_Publisher が返す理由のコード。
	 * @return string
	 */
	private function reason_label( $reason ) {
		switch ( $reason ) {
			case 'password':
				return __( 'the post is password protected', 'wordpress-connect-to-buffer' );
			case 'tags':
				return __( 'the post has none of the tags', 'wordpress-connect-to-buffer' );
			case 'categories':
				return __( 'the post is in none of the categories', 'wordpress-connect-to-buffer' );
			case 'filter':
				return __( 'the wctb_should_send filter rejected the post', 'wordpress-connect-to-buffer' );
			case 'not_ready':
				return __( 'the API key or the channels are not set', 'wordpress-connect-to-buffer' );
			default:
				return __( 'the post is not a published post', 'wordpress-connect-to-buffer' );
		}
	}

	/**
	 * テスト送信の欄を出力する。
	 *
	 * @param array $settings 設定。
	 * @param bool  $has_key  API キーがあるか。
	 */
	private function render_test( array $settings, $has_key ) {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => WCTB_Options::TEST_POSTS,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		$publisher = new WCTB_Publisher();
		?>
		<p>
			<?php esc_html_e( 'Sends a published post to Buffer without publishing anything new. A test is always saved as a draft in Buffer, even when "Add it to the queue" is selected, so nothing reaches your social accounts. It uses the saved settings, including tags and categories, and it sends to every selected channel even if the post was sent before. Delete the drafts in Buffer afterwards.', 'wordpress-connect-to-buffer' ); ?>
		</p>
		<?php if ( ! $has_key || ! $settings['channels'] ) : ?>
			<p><?php esc_html_e( 'Save an API key and select channels to run a test.', 'wordpress-connect-to-buffer' ); ?></p>
		<?php elseif ( ! $posts ) : ?>
			<p><?php esc_html_e( 'There is no published post to test with.', 'wordpress-connect-to-buffer' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>" />
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<label for="wctb-test-post" class="screen-reader-text"><?php esc_html_e( 'Post to send', 'wordpress-connect-to-buffer' ); ?></label>
				<select id="wctb-test-post" name="wctb_test_post">
					<?php foreach ( $posts as $post ) : ?>
						<?php
						$reason = $publisher->skip_reason( $post, $settings );
						$status = '' === $reason
							? __( 'will be sent', 'wordpress-connect-to-buffer' )
							/* translators: %s: reason. */
							: sprintf( __( 'will not be sent: %s', 'wordpress-connect-to-buffer' ), $this->reason_label( $reason ) );
						?>
						<option value="<?php echo esc_attr( $post->ID ); ?>">
							<?php echo esc_html( get_the_date( 'Y-m-d', $post ) . ' ' . get_the_title( $post ) . ' — ' . $status ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Send a test to Buffer', 'wordpress-connect-to-buffer' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * カテゴリーの名前を、上位のカテゴリーから順に「 > 」でつないで返す。
	 *
	 * @param int $category_id カテゴリーの ID。
	 * @return string カテゴリーがなければ空文字。
	 */
	private function category_label( $category_id ) {
		$term = get_term( $category_id, 'category' );
		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		$names = array( $term->name );
		foreach ( get_ancestors( $term->term_id, 'category', 'taxonomy' ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'category' );
			if ( $ancestor instanceof WP_Term ) {
				array_unshift( $names, $ancestor->name );
			}
		}

		return implode( ' > ', $names );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示するタブを選ぶだけで、何も変更しない。
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tab = isset( $this->tabs()[ $tab ] ) ? $tab : 'settings';

		$tag_names = array();
		foreach ( $settings['tags'] as $tag_id ) {
			$term = get_term( $tag_id, 'post_tag' );
			if ( $term instanceof WP_Term ) {
				$tag_names[] = $term->name;
			}
		}

		// プルダウンで選んだカテゴリーを一覧に足すときの表示名。
		$category_labels = array();
		foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ) ) as $category_id ) {
			$category_labels[ (int) $category_id ] = $this->category_label( (int) $category_id );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php
			$notice = get_transient( self::TEST_NOTICE . get_current_user_id() );
			if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) :
				delete_transient( self::TEST_NOTICE . get_current_user_id() );
				?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="margin-bottom: 1em;">
				<?php foreach ( $this->tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $key ) ); ?>"
						class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"
						<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'test' === $tab ) {
				$this->render_test( $settings, $has_key );
			} elseif ( 'log' === $tab ) {
				$this->render_log( is_array( $channels ) ? $channels : array() );
			}
			?>

			<?php if ( 'settings' === $tab ) : ?>
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
									<?php esc_html_e( 'With "Vertical image with the title", the featured image is cropped to 9:16 around its center and the post title is drawn on it. Channels without it receive the featured image as it is. An Instagram channel with it receives the image as a story, which has no caption.', 'wordpress-connect-to-buffer' ); ?>
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
							<label for="wctb-lead"><?php esc_html_e( 'Text before the title', 'wordpress-connect-to-buffer' ); ?></label>
						</th>
						<td>
							<input type="text" id="wctb-lead" class="large-text"
								name="<?php echo esc_attr( $name ); ?>[lead]"
								value="<?php echo esc_attr( $settings['lead'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'A fixed text that tells readers what the title is, such as "New post on My Site". It is placed on the line before the title in the text sent to Buffer, and above the title on the vertical image. Leave empty to send the title alone.', 'wordpress-connect-to-buffer' ); ?>
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

					<tr>
						<th scope="row">
							<label for="wctb-category-add"><?php esc_html_e( 'Categories', 'wordpress-connect-to-buffer' ); ?></label>
						</th>
						<td>
							<ul id="wctb-categories" style="margin-top: 0;">
								<?php foreach ( $settings['categories'] as $category_id ) : ?>
									<?php if ( isset( $category_labels[ $category_id ] ) ) : ?>
										<li>
											<input type="hidden" name="<?php echo esc_attr( $name ); ?>[categories][]" value="<?php echo esc_attr( $category_id ); ?>" />
											<span><?php echo esc_html( $category_labels[ $category_id ] ); ?></span>
											<button type="button" class="button-link button-link-delete wctb-category-remove"><?php esc_html_e( 'Remove', 'wordpress-connect-to-buffer' ); ?></button>
										</li>
									<?php endif; ?>
								<?php endforeach; ?>
							</ul>
							<?php
							wp_dropdown_categories(
								array(
									'taxonomy'          => 'category',
									'hierarchical'      => true,
									'hide_empty'        => false,
									'orderby'           => 'name',
									'name'              => $name . '[category_add]',
									'id'                => 'wctb-category-add',
									'show_option_none'  => __( '— Select a category —', 'wordpress-connect-to-buffer' ),
									'option_none_value' => '0',
								)
							);
							?>
							<button type="button" class="button" id="wctb-category-button"><?php esc_html_e( 'Add', 'wordpress-connect-to-buffer' ); ?></button>
							<p class="description">
								<?php esc_html_e( 'Choose a category and add it. Only posts in at least one of these categories are sent. A category also covers every category below it. Leave the list empty to send posts in any category. If tags are also set, a post must match both.', 'wordpress-connect-to-buffer' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<script>
			( function () {
				var list   = document.getElementById( 'wctb-categories' );
				var select = document.getElementById( 'wctb-category-add' );
				var button = document.getElementById( 'wctb-category-button' );
				var labels = <?php echo wp_json_encode( $category_labels, JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
				var field  = <?php echo wp_json_encode( $name . '[categories][]', JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
				var remove = <?php echo wp_json_encode( __( 'Remove', 'wordpress-connect-to-buffer' ), JSON_HEX_TAG | JSON_HEX_AMP ); ?>;

				if ( ! list || ! select || ! button ) {
					return;
				}

				button.addEventListener( 'click', function () {
					var id = select.value;
					var added, i, item, input, label, link;

					if ( ! id || '0' === id ) {
						return;
					}

					added = list.querySelectorAll( 'input' );
					for ( i = 0; i < added.length; i++ ) {
						if ( added[ i ].value === id ) {
							select.value = '0';
							return;
						}
					}

					item  = document.createElement( 'li' );
					input = document.createElement( 'input' );
					label = document.createElement( 'span' );
					link  = document.createElement( 'button' );

					input.type  = 'hidden';
					input.name  = field;
					input.value = id;

					label.textContent = labels[ id ] || id;

					link.type        = 'button';
					link.className   = 'button-link button-link-delete wctb-category-remove';
					link.textContent = remove;

					item.appendChild( input );
					item.appendChild( document.createTextNode( ' ' ) );
					item.appendChild( label );
					item.appendChild( document.createTextNode( ' ' ) );
					item.appendChild( link );
					list.appendChild( item );

					select.value = '0';
				} );

				list.addEventListener( 'click', function ( event ) {
					if ( event.target.classList.contains( 'wctb-category-remove' ) ) {
						event.target.parentNode.parentNode.removeChild( event.target.parentNode );
					}
				} );
			}() );
			</script>
			<?php endif; ?>
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
			echo '<p>' . esc_html__( 'Nothing has been sent yet.', 'wordpress-connect-to-buffer' ) . '</p>';

			return;
		}

		$names = array();
		foreach ( $channels as $channel ) {
			$names[ $channel['id'] ] = $channel['name'] . ' (' . $channel['service'] . ')';
		}
		?>
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
								$result = __( 'Saved as draft', 'wordpress-connect-to-buffer' );
							} elseif ( $entry['success'] ) {
								$result = __( 'Added to queue', 'wordpress-connect-to-buffer' );
							} else {
								/* translators: %s: error message. */
								$result = sprintf( __( 'Failed: %s', 'wordpress-connect-to-buffer' ), $entry['message'] );
							}

							if ( ! empty( $entry['test'] ) ) {
								/* translators: %s: result of a test. */
								$result = sprintf( __( 'Test: %s', 'wordpress-connect-to-buffer' ), $result );
							}

							echo esc_html( $result );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
