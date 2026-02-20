<?php
/**
 * Plugin Name: SudoWP Hub
 * Plugin URI:  https://sudowp.com
 * Description: Connects to the SudoWP GitHub organization to search and install patched security plugins and themes directly.
 * Version:     1.1.0
 * Author:      SudoWP
 * Author URI:  https://sudowp.com
 * License:     GPLv2 or later
 * Text Domain: sudowp-hub
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

/**
 * SudoWP Hub - Main Plugin Class
 *
 * Security hardened per WordPress.org plugin guidelines and OWASP recommendations.
 *
 * @version 1.1.0
 */
class SudoWP_Hub {

	/**
	 * GitHub Organization slug (must match exactly).
	 *
	 * @var string
	 */
	private $github_org = 'Sudo-WP';

	/**
	 * GitHub Search API endpoint.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.github.com/search/repositories';

	/**
	 * Transient cache TTL in seconds (5 minutes).
	 *
	 * @var int
	 */
	private $cache_ttl = 300;

	/**
	 * AJAX rate-limit window in seconds.
	 *
	 * @var int
	 */
	private $rate_limit_window = 2;

	/**
	 * Singleton slug stored during install to share with rename filter.
	 * Isolated per-request; low concurrency risk in PHP's single-threaded model.
	 *
	 * @var string|null
	 */
	private $current_install_slug = null;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Return singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			$instance->init();
		}
		return $instance;
	}

	/**
	 * Register all hooks.
	 */
	public function init() {
		// i18n — must run on init, not plugins_loaded, per WP guidelines.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// AJAX handlers — authenticated users only (no nopriv).
		add_action( 'wp_ajax_sudowp_hub_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_sudowp_hub_install', array( $this, 'ajax_install' ) );
	}

	/**
	 * Load plugin text domain for i18n.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'sudowp-hub',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	// -------------------------------------------------------------------------
	// Admin Menu & Settings
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level admin menu page.
	 */
	public function register_menu_page() {
		add_menu_page(
			__( 'SudoWP Store', 'sudowp-hub' ),
			__( 'SudoWP Store', 'sudowp-hub' ),
			'install_plugins',
			'sudowp-hub',
			array( $this, 'render_page' ),
			'dashicons-shield',
			65
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'sudowp_hub_options',
			'sudowp_hub_gh_token',
			array(
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'sudowp_hub_main',
			__( 'GitHub Settings', 'sudowp-hub' ),
			null,
			'sudowp-hub-settings'
		);

		add_settings_field(
			'sudowp_hub_gh_token',
			__( 'Personal Access Token', 'sudowp-hub' ),
			array( $this, 'render_token_field' ),
			'sudowp-hub-settings',
			'sudowp_hub_main'
		);
	}

	/**
	 * Sanitize and store the GitHub token.
	 * Allows clearing the token by submitting an empty value
	 * while a token is flagged as set (via hidden sentinel field).
	 *
	 * @param string $token Raw input value.
	 * @return string Sanitized token, or empty string to clear.
	 */
	public function sanitize_token( $token ) {
		// Nonce already verified by Settings API at this point.

		// Check if the admin deliberately cleared the field
		// (sentinel field is sent when the form was rendered with an existing token).
		$clearing = isset( $_POST['sudowp_hub_clear_token'] ) && '1' === $_POST['sudowp_hub_clear_token'];

		if ( '' === trim( $token ) ) {
			// Empty submission: clear only if sentinel says there was a token.
			return $clearing ? '' : get_option( 'sudowp_hub_gh_token', '' );
		}

		// Strip all whitespace and non-printable characters from the token.
		$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '', trim( $token ) );

		// GitHub tokens are at most 40–255 chars. Enforce a safe ceiling.
		return substr( $sanitized, 0, 255 );
	}

	/**
	 * Render the GitHub token input field.
	 */
	public function render_token_field() {
		$has_token = ! empty( get_option( 'sudowp_hub_gh_token', '' ) );
		?>
		<input
			type="password"
			name="sudowp_hub_gh_token"
			id="sudowp_hub_gh_token"
			value=""
			placeholder="<?php echo $has_token ? esc_attr( str_repeat( '•', 16 ) ) : esc_attr__( 'Paste your token here', 'sudowp-hub' ); ?>"
			class="regular-text"
			autocomplete="new-password"
		/>
		<?php if ( $has_token ) : ?>
			<input type="hidden" name="sudowp_hub_clear_token" value="1" />
			<p class="description">
				<?php esc_html_e( 'A token is currently saved. Submit an empty field to clear it.', 'sudowp-hub' ); ?>
			</p>
		<?php else : ?>
			<input type="hidden" name="sudowp_hub_clear_token" value="0" />
			<p class="description">
				<?php esc_html_e( 'Optional: Add a GitHub Personal Access Token (public_repo scope) to avoid API rate limits.', 'sudowp-hub' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Asset Enqueue
	// -------------------------------------------------------------------------

	/**
	 * Enqueue only the assets our page actually needs.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_sudowp-hub' !== $hook ) {
			return;
		}

		// plugin-list.css gives us the plugin card styles without the full
		// plugin-install bundle. 'plugin-install' CSS is enqueued separately.
		wp_enqueue_style( 'plugin-install' );

		// Register our own script — avoids piggybacking on WP core handles.
		wp_register_script(
			'sudowp-hub-admin',
			'', // Inline only; no external file needed for v1.
			array( 'jquery' ),
			'1.1.0',
			true
		);

		// Pass data to JS safely — never inline nonces as string literals.
		wp_localize_script(
			'sudowp-hub-admin',
			'SudoWPHub',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'searchNonce'  => wp_create_nonce( 'sudowp_search' ),
				'installNonce' => wp_create_nonce( 'sudowp_install' ),
				'i18n'         => array(
					'searching'   => __( 'Searching GitHub…', 'sudowp-hub' ),
					'installing'  => __( 'Installing…', 'sudowp-hub' ),
					'installed'   => __( 'Installed!', 'sudowp-hub' ),
					'failed'      => __( 'Failed', 'sudowp-hub' ),
					'activate'    => __( 'Activate', 'sudowp-hub' ),
					'noResults'   => __( 'No patched components found matching that name.', 'sudowp-hub' ),
					'rateLimited' => __( 'Rate limit exceeded. Add a GitHub token in settings.', 'sudowp-hub' ),
					'apiError'    => __( 'GitHub API error. Please try again.', 'sudowp-hub' ),
				),
			)
		);

		wp_add_inline_script( 'sudowp-hub-admin', $this->get_inline_js() );
		wp_enqueue_script( 'sudowp-hub-admin' );
	}

	/**
	 * Return the inline JS as a string (keeps enqueue_assets readable).
	 *
	 * @return string
	 */
	private function get_inline_js() {
		return <<<'JS'
(function($) {
	'use strict';

	var hub      = SudoWPHub;
	var timer    = null;
	var $list    = null;

	function search() {
		var term = $('#sudowp-search-input').val();
		var type = $('input[name="sudowp_type"]:checked').val();

		$list.html(
			'<div style="padding:20px;"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>'
			+ hub.i18n.searching + '</div>'
		);

		$.post(hub.ajaxUrl, {
			action:  'sudowp_hub_search',
			term:    term,
			type:    type,
			_nonce:  hub.searchNonce
		})
		.done(function(response) {
			if (response.success) {
				$list.html(response.data);
			} else {
				$list.html('<p class="notice notice-error" style="padding:8px 12px;">' + $('<div>').text(response.data).html() + '</p>');
			}
		})
		.fail(function() {
			$list.html('<p class="notice notice-error" style="padding:8px 12px;">' + hub.i18n.apiError + '</p>');
		});
	}

	$(document).ready(function() {
		$list = $('#the-list');

		// Debounced search on keyup.
		$('#sudowp-search-input').on('keyup', function() {
			clearTimeout(timer);
			timer = setTimeout(search, 600);
		});

		// Type toggle.
		$('input[name="sudowp_type"]').on('change', search);

		// Initial load.
		search();

		// Install handler.
		$(document).on('click', '.sudowp-install-now', function(e) {
			e.preventDefault();
			var $btn = $(this);

			if ($btn.hasClass('updating-message') || $btn.hasClass('button-disabled')) {
				return;
			}

			$btn.addClass('updating-message').text(hub.i18n.installing);

			$.post(hub.ajaxUrl, {
				action:   'sudowp_hub_install',
				repo_url: $btn.data('zip'),
				slug:     $btn.data('slug'),
				type:     $btn.data('type'),
				_nonce:   hub.installNonce
			})
			.done(function(response) {
				$btn.removeClass('updating-message');
				if (response.success) {
					$btn.addClass('button-disabled').text(hub.i18n.installed);
					var $activate = $('<a>', {
						href:  response.data.activate_url,
						class: 'button button-primary activate-now',
						text:  hub.i18n.activate
					});
					$btn.after(' ', $activate);
				} else {
					$btn.text(hub.i18n.failed);
					// Display error inline rather than alert() for better UX.
					var $err = $('<span>', {
						class: 'notice-error',
						style: 'margin-left:8px;color:#d63638;',
						text:  response.data
					});
					$btn.after($err);
				}
			})
			.fail(function() {
				$btn.removeClass('updating-message').text(hub.i18n.failed);
			});
		});
	});
}(jQuery));
JS;
	}

	// -------------------------------------------------------------------------
	// Page Render
	// -------------------------------------------------------------------------

	/**
	 * Render the SudoWP Store admin page.
	 */
	public function render_page() {
		// Show settings-saved notice with redirect back to our page.
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'sudowp-hub' )
				. '</p></div>';
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SudoWP Store', 'sudowp-hub' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Search and install patched plugins/themes directly from the SudoWP GitHub Organization.', 'sudowp-hub' ); ?>
			</p>
			<hr class="wp-header-end">

			<div class="wp-filter">
				<ul class="filter-links">
					<li>
						<label>
							<input type="radio" name="sudowp_type" value="plugin" checked>
							<?php esc_html_e( 'Plugins', 'sudowp-hub' ); ?>
						</label>
					</li>
					<li>
						<label>
							<input type="radio" name="sudowp_type" value="theme">
							<?php esc_html_e( 'Themes', 'sudowp-hub' ); ?>
						</label>
					</li>
				</ul>
				<div class="search-form">
					<label class="screen-reader-text" for="sudowp-search-input">
						<?php esc_html_e( 'Search Plugins', 'sudowp-hub' ); ?>
					</label>
					<input
						type="search"
						id="sudowp-search-input"
						class="wp-filter-search"
						placeholder="<?php esc_attr_e( 'Search SudoWP Patches…', 'sudowp-hub' ); ?>"
						aria-describedby="live-search-desc"
					>
				</div>
			</div>

			<br class="clear">

			<div id="the-list" class="widefat plugin-install-network"></div>

			<div style="margin-top:30px;border-top:1px solid #ccc;padding-top:20px;">
				<h3><?php esc_html_e( 'Configuration', 'sudowp-hub' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php
					settings_fields( 'sudowp_hub_options' );
					do_settings_sections( 'sudowp-hub-settings' );
					submit_button( __( 'Save Settings', 'sudowp-hub' ) );
					?>
				</form>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: Search
	// -------------------------------------------------------------------------

	/**
	 * Handle AJAX search requests.
	 * Cached with transients; rate-limited per user.
	 */
	public function ajax_search() {
		// 1. Verify nonce.
		check_ajax_referer( 'sudowp_search', '_nonce' );

		// 2. Capability check — only users who can install plugins may search.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sudowp-hub' ) );
		}

		// 3. Rate limit: one call per $rate_limit_window seconds per user.
		$user_id       = get_current_user_id();
		$rate_key      = 'sudowp_rl_search_' . $user_id;
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait a moment.', 'sudowp-hub' ) );
		}
		set_transient( $rate_key, 1, $this->rate_limit_window );

		// 4. Sanitize input.
		$term  = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		$type  = sanitize_key( $_POST['type'] ?? 'plugin' );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$type = 'plugin';
		}

		// 5. Check cache.
		$cache_key = 'sudowp_search_' . md5( $this->github_org . '|' . $term . '|' . $type );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		// 6. Fetch from GitHub API.
		$query    = 'org:' . rawurlencode( $this->github_org ) . ' ' . rawurlencode( $term );
		$api_url  = add_query_arg(
			array(
				'q'        => 'org:' . $this->github_org . ' ' . $term,
				'per_page' => 100,
			),
			$this->api_url
		);

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'User-Agent' => 'WordPress/SudoWP-Hub-' . get_bloginfo( 'url' ),
				'Accept'     => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
		);

		$token = get_option( 'sudowp_hub_gh_token', '' );
		if ( ! empty( $token ) ) {
			// Use Bearer per current GitHub API docs.
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( __( 'GitHub API Error: ', 'sudowp-hub' ) . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 403 === $status_code || 429 === $status_code ) {
			wp_send_json_error( __( 'GitHub rate limit exceeded. Add a Personal Access Token in settings.', 'sudowp-hub' ) );
		}
		if ( 200 !== $status_code ) {
			wp_send_json_error(
				/* translators: %d: HTTP status code */
				sprintf( __( 'GitHub API returned status %d.', 'sudowp-hub' ), $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['items'] ) || ! is_array( $body['items'] ) ) {
			wp_send_json_error( __( 'Unexpected response from GitHub API.', 'sudowp-hub' ) );
		}

		// 7. Build HTML output.
		$html = $this->build_results_html( $body['items'], $type );

		// 8. Cache result.
		set_transient( $cache_key, $html, $this->cache_ttl );

		wp_send_json_success( $html );
	}

	/**
	 * Build the plugin-card HTML from a list of GitHub repo items.
	 *
	 * @param array  $items GitHub API items array.
	 * @param string $type  'plugin' or 'theme'.
	 * @return string Escaped HTML.
	 */
	private function build_results_html( array $items, $type ) {
		if ( empty( $items ) ) {
			return '<p>' . esc_html__( 'No patched components found matching that name.', 'sudowp-hub' ) . '</p>';
		}

		$date_format = get_option( 'date_format' );
		ob_start();

		foreach ( $items as $repo ) {
			// Validate expected fields exist and are the correct type.
			if (
				! isset( $repo['name'], $repo['html_url'], $repo['default_branch'], $repo['updated_at'] )
				|| ! is_string( $repo['name'] )
				|| ! is_string( $repo['html_url'] )
				|| ! is_string( $repo['default_branch'] )
				|| ! is_string( $repo['updated_at'] )
			) {
				continue; // Skip malformed items silently.
			}

			// Sanitize each value before use.
			$name         = sanitize_text_field( $repo['name'] );
			$description  = isset( $repo['description'] ) && is_string( $repo['description'] )
				? sanitize_text_field( $repo['description'] )
				: '';
			$html_url     = esc_url( $repo['html_url'] );
			$branch       = preg_replace( '/[^a-zA-Z0-9_\-.]/', '', $repo['default_branch'] );
			$stars        = absint( $repo['stargazers_count'] ?? 0 );
			$last_updated = date_i18n( $date_format, strtotime( $repo['updated_at'] ) );

			// Construct ZIP URL from validated parts — never trust the API to return a safe URL.
			$zip_url = sprintf(
				'https://github.com/%s/%s/archive/refs/heads/%s.zip',
				rawurlencode( $this->github_org ),
				rawurlencode( $name ),
				rawurlencode( $branch )
			);
			?>
			<div class="plugin-card">
				<div class="plugin-card-top">
					<div class="name column-name">
						<h3>
							<a href="<?php echo esc_url( $html_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $name ); ?>
							</a>
						</h3>
					</div>
					<div class="action-links">
						<ul class="plugin-action-buttons">
							<li>
								<a class="install-now button sudowp-install-now"
								   data-slug="<?php echo esc_attr( $name ); ?>"
								   data-zip="<?php echo esc_attr( $zip_url ); ?>"
								   data-type="<?php echo esc_attr( $type ); ?>"
								   href="#"><?php esc_html_e( 'Install Now', 'sudowp-hub' ); ?></a>
							</li>
							<li>
								<a href="<?php echo esc_url( $html_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'More Details', 'sudowp-hub' ); ?>
								</a>
							</li>
						</ul>
					</div>
					<div class="desc column-description">
						<p>
							<?php echo $description ? esc_html( $description ) : esc_html__( 'No description provided.', 'sudowp-hub' ); ?>
						</p>
					</div>
				</div>
				<div class="plugin-card-bottom">
					<div class="vers column-rating">
						<?php
						echo esc_html(
							/* translators: %s: date string */
							sprintf( __( 'Last Updated: %s', 'sudowp-hub' ), $last_updated )
						);
						?>
					</div>
					<div class="column-downloaded">
						<?php
						echo esc_html(
							/* translators: %d: star count */
							sprintf( __( 'Stars: %d', 'sudowp-hub' ), $stars )
						);
						?>
					</div>
					<div class="column-compatibility">
						<span class="compatibility-compatible">
							<strong><?php esc_html_e( 'SudoWP Patched', 'sudowp-hub' ); ?></strong>
						</span>
					</div>
				</div>
			</div>
			<?php
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// AJAX: Install
	// -------------------------------------------------------------------------

	/**
	 * Handle AJAX install requests.
	 */
	public function ajax_install() {
		// 1. Verify nonce.
		check_ajax_referer( 'sudowp_install', '_nonce' );

		// 2. Capability check.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sudowp-hub' ) );
		}

		// 3. Rate limit: one install per window per user.
		$user_id  = get_current_user_id();
		$rate_key = 'sudowp_rl_install_' . $user_id;
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( __( 'Please wait before installing another plugin.', 'sudowp-hub' ) );
		}
		set_transient( $rate_key, 1, $this->rate_limit_window );

		// 4. Sanitize input.
		$url  = isset( $_POST['repo_url'] ) ? esc_url_raw( wp_unslash( $_POST['repo_url'] ) ) : '';
		$slug = isset( $_POST['slug'] )     ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$type = isset( $_POST['type'] )     ? sanitize_key( $_POST['type'] ) : 'plugin';

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$type = 'plugin';
		}

		// 5. Strict URL validation (SSRF prevention).
		if ( ! $this->validate_github_url( $url, $slug ) ) {
			wp_send_json_error( __( 'Invalid repository URL. Only SudoWP GitHub repositories are allowed.', 'sudowp-hub' ) );
		}

		// 6. Load upgrader dependencies.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$skin = new WP_Ajax_Upgrader_Skin();

		if ( 'theme' === $type ) {
			$upgrader = new Theme_Upgrader( $skin );
		} else {
			$upgrader = new Plugin_Upgrader( $skin );
		}

		// 7. Store slug for rename filter (cleared immediately after).
		$this->current_install_slug = $slug;
		add_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ), 10, 3 );

		$result = $upgrader->install( $url );

		remove_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ) );
		$this->current_install_slug = null;

		// 8. Evaluate result.
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		if ( is_wp_error( $skin->result ) ) {
			wp_send_json_error( $skin->result->get_error_message() );
		}

		if ( ! $result ) {
			wp_send_json_error( __( 'Installation failed. Check file permissions.', 'sudowp-hub' ) );
		}

		$activate_url = ( 'theme' === $type )
			? admin_url( 'themes.php' )
			: admin_url( 'plugins.php' );

		wp_send_json_success( array( 'activate_url' => $activate_url ) );
	}

	// -------------------------------------------------------------------------
	// Security Helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate that a ZIP URL points strictly to the expected GitHub org and repo.
	 * Prevents SSRF and org/repo spoofing.
	 *
	 * Expected format:
	 *   https://github.com/{org}/{slug}/archive/refs/heads/{branch}.zip
	 *
	 * @param string $url  The URL to validate.
	 * @param string $slug The expected repository name.
	 * @return bool
	 */
	private function validate_github_url( $url, $slug ) {
		if ( empty( $url ) || empty( $slug ) ) {
			return false;
		}

		// Slug must be safe before using it in a regex.
		if ( ! $this->is_valid_slug( $slug ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		// All components must be present; no fragment or query allowed.
		if (
			! $parsed
			|| empty( $parsed['scheme'] )
			|| empty( $parsed['host'] )
			|| empty( $parsed['path'] )
			|| ! empty( $parsed['query'] )
			|| ! empty( $parsed['fragment'] )
			|| ! empty( $parsed['user'] )    // No userinfo (SSRF via creds)
		) {
			return false;
		}

		// Must be HTTPS.
		if ( 'https' !== $parsed['scheme'] ) {
			return false;
		}

		// Exact host match — no subdomains.
		if ( 'github.com' !== $parsed['host'] ) {
			return false;
		}

		// Exact path structure match.
		$expected_prefix = sprintf(
			'/%s/%s/archive/refs/heads/',
			$this->github_org,
			$slug
		);

		if ( strpos( $parsed['path'], $expected_prefix ) !== 0 ) {
			return false;
		}

		// Branch segment after the prefix must be alphanumeric + safe chars, ending in .zip.
		$branch_segment = substr( $parsed['path'], strlen( $expected_prefix ) );
		if ( ! preg_match( '/^[a-zA-Z0-9_\-.]+\.zip$/', $branch_segment ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check that a slug contains only filesystem-safe characters.
	 *
	 * @param string $slug
	 * @return bool
	 */
	private function is_valid_slug( $slug ) {
		return ! empty( $slug ) && (bool) preg_match( '/^[a-zA-Z0-9_\-]+$/', $slug );
	}

	/**
	 * Rename GitHub's extracted folder ({repo}-{branch}) to just {repo}.
	 * Hooked to `upgrader_source_selection`.
	 *
	 * @param string      $source        Current source path.
	 * @param string      $remote_source Temp directory path.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @return string|WP_Error New source path, or original if rename not needed.
	 */
	public function rename_github_source( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;

		if ( empty( $this->current_install_slug ) || ! $this->is_valid_slug( $this->current_install_slug ) ) {
			return $source;
		}

		if ( ! $wp_filesystem ) {
			return $source;
		}

		$new_source = trailingslashit( $remote_source ) . $this->current_install_slug . '/';

		if ( $source === $new_source ) {
			return $source; // Already correctly named.
		}

		if ( ! $wp_filesystem->move( $source, $new_source ) ) {
			return new WP_Error(
				'sudowp_rename_failed',
				__( 'Could not rename the plugin folder. Check filesystem permissions.', 'sudowp-hub' )
			);
		}

		return $new_source;
	}

}

SudoWP_Hub::get_instance();
