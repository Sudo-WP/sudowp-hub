<?php
/**
 * Plugin Name: SudoWP Hub
 * Plugin URI:  https://sudowp.com
 * Description: Connects to the SudoWP GitHub organization to search and install patched security plugins and themes directly.
 * Version:     1.4.0
 * Author:      SudoWP
 * Author URI:  https://sudowp.com
 * License:     GPLv2 or later
 * Text Domain: sudowp-hub
 * Domain Path: /languages
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

/**
 * SudoWP Hub - Main Plugin Class
 *
 * Security hardened per WordPress.org plugin guidelines and OWASP recommendations.
 *
 * @version 1.4.0
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
	 * Update check cache TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	private $update_cache_ttl = 43200;

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
		// i18n - must run on init, not plugins_loaded, per WP guidelines.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// AJAX handlers - authenticated users only (no nopriv).
		add_action( 'wp_ajax_sudowp_hub_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_sudowp_hub_install', array( $this, 'ajax_install' ) );
		add_action( 'wp_ajax_sudowp_hub_check_updates', array( $this, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_sudowp_hub_run_updates', array( $this, 'ajax_run_updates' ) );

		// Add "SudoWP Updates" tab to the native Plugins list table.
		add_filter( 'views_plugins', array( $this, 'add_updates_tab_to_plugins' ) );
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
			__( 'SudoWP Hub', 'sudowp-hub' ),
			__( 'SudoWP Hub', 'sudowp-hub' ),
			'install_plugins',
			'sudowp-hub',
			array( $this, 'render_page' ),
			'dashicons-shield',
			65
		);

		// Rename the auto-generated first submenu to "Browse".
		add_submenu_page(
			'sudowp-hub',
			__( 'SudoWP Hub', 'sudowp-hub' ),
			__( 'Browse', 'sudowp-hub' ),
			'install_plugins',
			'sudowp-hub'
		);

		// Register the Updates page with null parent so it does not appear in the sidebar.
		// The page is accessible via admin.php?page=sudowp-hub-updates and linked
		// from the native Plugins list table via the views_plugins filter.
		add_submenu_page(
			null,
			__( 'SudoWP Updates', 'sudowp-hub' ),
			__( 'Updates', 'sudowp-hub' ),
			'update_plugins',
			'sudowp-hub-updates',
			array( $this, 'render_updates_page' )
		);
	}

	/**
	 * Add "SudoWP Updates" tab to the native Plugins list table.
	 * Hooked to the `views_plugins` filter.
	 *
	 * @param array $views Existing views.
	 * @return array Modified views with the SudoWP Updates tab appended.
	 */
	public function add_updates_tab_to_plugins( $views ) {
		$count = $this->get_pending_update_count();
		$url   = admin_url( 'admin.php?page=sudowp-hub-updates' );
		$label = esc_html__( 'SudoWP Updates', 'sudowp-hub' );

		if ( $count > 0 ) {
			$label .= sprintf(
				' <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>',
				$count,
				$count
			);
		}

		$views['sudowp-updates'] = '<a href="' . esc_url( $url ) . '">' . $label . '</a>';

		return $views;
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
		$clearing = isset( $_POST['sudowp_hub_clear_token'] ) && '1' === wp_unslash( $_POST['sudowp_hub_clear_token'] );

		if ( '' === trim( $token ) ) {
			// Empty submission: clear only if sentinel says there was a token.
			return $clearing ? '' : get_option( 'sudowp_hub_gh_token', '' );
		}

		// Strip all whitespace and non-printable characters from the token.
		$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '', trim( $token ) );

		// GitHub tokens are at most 40-255 chars. Enforce a safe ceiling.
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
		// Updates page assets.
		if ( 'admin_page_sudowp-hub-updates' === $hook ) {
			wp_register_script(
				'sudowp-hub-updates',
				'',
				array( 'jquery' ),
				'1.4.0',
				true
			);

			wp_localize_script(
				'sudowp-hub-updates',
				'SudoWPHubUpdates',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'sudowp_check_updates' ),
					'updatesNonce' => wp_create_nonce( 'sudowp_run_updates' ),
					'i18n'         => array(
						'checking'    => __( 'Checking...', 'sudowp-hub' ),
						'updating'    => __( 'Updating...', 'sudowp-hub' ),
						'updated'     => __( 'Updated', 'sudowp-hub' ),
						'updateFailed' => __( 'Update failed', 'sudowp-hub' ),
						'confirmBulk' => __( 'Update %d selected plugins?', 'sudowp-hub' ),
					),
				)
			);

			wp_add_inline_script( 'sudowp-hub-updates', $this->get_updates_inline_js() );
			wp_enqueue_script( 'sudowp-hub-updates' );
			return;
		}

		if ( 'toplevel_page_sudowp-hub' !== $hook ) {
			return;
		}

		// plugin-list.css gives us the plugin card styles without the full
		// plugin-install bundle. 'plugin-install' CSS is enqueued separately.
		wp_enqueue_style( 'plugin-install' );

		// Register our own script - avoids piggybacking on WP core handles.
		wp_register_script(
			'sudowp-hub-admin',
			'', // Inline only; no external file needed for v1.
			array( 'jquery' ),
			'1.4.0',
			true
		);

		// Pass data to JS safely - never inline nonces as string literals.
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
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SudoWP Hub', 'sudowp-hub' ); ?></h1>
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

		// 2. Capability check - only users who can install plugins may search.
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

		// 5. Check cache - stores raw items, not rendered HTML.
		$cache_key     = 'sudowp_search_' . md5( $this->github_org . '|' . $term . '|' . $type );
		$cached_items  = get_transient( $cache_key );
		if ( false !== $cached_items && is_array( $cached_items ) ) {
			wp_send_json_success( $this->build_results_html( $cached_items, $type ) );
		}

		// 6. Fetch from GitHub API.
		$api_url  = add_query_arg(
			array(
				'q'        => 'org:' . $this->github_org . ' ' . $term,
				'per_page' => 100,
			),
			$this->api_url
		);

		$response = wp_remote_get( $api_url, $this->get_github_api_args() );

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

		// 7. Cache the raw items array - not the rendered HTML - so installed-state
		// detection always runs fresh against the current install/active state.
		set_transient( $cache_key, $body['items'], $this->cache_ttl );

		// 8. Build HTML output (always fresh, never cached).
		$html = $this->build_results_html( $body['items'], $type );

		wp_send_json_success( $html );
	}

	/**
	 * Return a map of installed plugin slugs to their status.
	 * Keys are folder slugs (e.g. 'sudowp-log-viewer'). Values: 'active' or 'inactive'.
	 *
	 * @return array<string, string>
	 */
	private function get_installed_plugins_map() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active      = get_option( 'active_plugins', array() );
		$map         = array();

		foreach ( array_keys( $all_plugins ) as $plugin_file ) {
			// plugin_file format: 'folder/file.php' or 'file.php' for single-file plugins.
			$folder = strpos( $plugin_file, '/' ) !== false
				? explode( '/', $plugin_file )[0]
				: pathinfo( $plugin_file, PATHINFO_FILENAME );

			$map[ $folder ] = in_array( $plugin_file, $active, true ) ? 'active' : 'inactive';
		}

		return $map;
	}

	/**
	 * Return a map of installed theme slugs to their status.
	 * Keys are stylesheet slugs. Values: 'active' or 'inactive'.
	 *
	 * @return array<string, string>
	 */
	private function get_installed_themes_map() {
		$active_theme = get_option( 'stylesheet' );
		$map          = array();

		foreach ( array_keys( wp_get_themes() ) as $stylesheet ) {
			$map[ $stylesheet ] = ( $stylesheet === $active_theme ) ? 'active' : 'inactive';
		}

		return $map;
	}

	/**
	 * Build the plugin-card HTML from a list of GitHub repo items.
	 * Installed-state detection is done fresh on every call (not cached)
	 * so that the card correctly reflects the current install/active state.
	 *
	 * @param array  $items GitHub API items array.
	 * @param string $type  'plugin' or 'theme'.
	 * @return string Escaped HTML.
	 */
	private function build_results_html( array $items, $type ) {
		if ( empty( $items ) ) {
			return '<p>' . esc_html__( 'No patched components found matching that name.', 'sudowp-hub' ) . '</p>';
		}

		// Fetch installed state once for the whole list.
		$installed_map = ( 'theme' === $type )
			? $this->get_installed_themes_map()
			: $this->get_installed_plugins_map();

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

			// Construct ZIP URL from validated parts - never trust the API to return a safe URL.
			$zip_url = sprintf(
				'https://github.com/%s/%s/archive/refs/heads/%s.zip',
				rawurlencode( $this->github_org ),
				rawurlencode( $name ),
				rawurlencode( $branch )
			);

			// Determine install status for this repo slug.
			$install_status = $installed_map[ $name ] ?? 'not_installed';

			// Build the action button based on install status.
			if ( 'active' === $install_status ) {
				$action_button = '<a class="button button-disabled" disabled="disabled">'
					. esc_html__( 'Active', 'sudowp-hub' )
					. '</a>';
			} elseif ( 'inactive' === $install_status ) {
				$activate_url = ( 'theme' === $type )
					? admin_url( 'themes.php' )
					: admin_url( 'plugins.php' );
				$action_button = '<a class="button button-primary" href="' . esc_url( $activate_url ) . '">'
					. esc_html__( 'Activate', 'sudowp-hub' )
					. '</a>';
			} else {
				$action_button = '<a class="install-now button sudowp-install-now"'
					. ' data-slug="' . esc_attr( $name ) . '"'
					. ' data-zip="' . esc_attr( $zip_url ) . '"'
					. ' data-type="' . esc_attr( $type ) . '"'
					. ' href="#">'
					. esc_html__( 'Install Now', 'sudowp-hub' )
					. '</a>';
			}
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
							<li><?php echo wp_kses_post($action_button); ?></li>
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

		// 2. Determine type early for capability check.
		$type = isset( $_POST['type'] )     ? sanitize_key( $_POST['type'] ) : 'plugin';
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$type = 'plugin';
		}

		// 3. Capability check.
		$required_cap = ($type === 'theme') ? 'install_themes' : 'install_plugins';
		if (!current_user_can($required_cap)) {
			wp_send_json_error( __( 'Permission denied.', 'sudowp-hub' ) );
		}

		// 4. Rate limit: one install per window per user.
		$user_id  = get_current_user_id();
		$rate_key = 'sudowp_rl_install_' . $user_id;
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( __( 'Please wait before installing another plugin.', 'sudowp-hub' ) );
		}
		set_transient( $rate_key, 1, $this->rate_limit_window );

		// 5. Sanitize input.
		$url  = isset( $_POST['repo_url'] ) ? esc_url_raw( wp_unslash( $_POST['repo_url'] ) ) : '';
		$slug = isset( $_POST['slug'] )     ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';

		// 6. Strict URL validation (SSRF prevention).
		if ( ! $this->validate_github_url( $url, $slug ) ) {
			wp_send_json_error( __( 'Invalid repository URL. Only SudoWP GitHub repositories are allowed.', 'sudowp-hub' ) );
		}

		// 7. Load upgrader dependencies.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$skin = new WP_Ajax_Upgrader_Skin();

		if ( 'theme' === $type ) {
			$upgrader = new Theme_Upgrader( $skin );
		} else {
			$upgrader = new Plugin_Upgrader( $skin );
		}

		// 8. Store slug for rename filter (cleared immediately after).
		$this->current_install_slug = $slug;
		add_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ), 10, 3 );

		$result = $upgrader->install( $url );

		remove_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ) );
		$this->current_install_slug = null;

		// 9. Evaluate result.
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

		// Exact host match - no subdomains.
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
	 * Validate that a ZIP URL points strictly to the expected GitHub tag archive.
	 * Prevents SSRF and org/repo spoofing for tag-based downloads.
	 *
	 * Expected format:
	 *   https://github.com/{org}/{slug}/archive/refs/tags/{tag}.zip
	 *
	 * @param string $url  The URL to validate.
	 * @param string $slug The expected repository name.
	 * @return bool
	 */
	private function validate_github_tag_url( $url, $slug ) {
		if ( empty( $url ) || empty( $slug ) ) {
			return false;
		}

		if ( ! $this->is_valid_slug( $slug ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		if (
			! $parsed
			|| empty( $parsed['scheme'] )
			|| empty( $parsed['host'] )
			|| empty( $parsed['path'] )
			|| ! empty( $parsed['query'] )
			|| ! empty( $parsed['fragment'] )
			|| ! empty( $parsed['user'] )
		) {
			return false;
		}

		if ( 'https' !== $parsed['scheme'] ) {
			return false;
		}

		if ( 'github.com' !== $parsed['host'] ) {
			return false;
		}

		$expected_prefix = sprintf(
			'/%s/%s/archive/refs/tags/',
			$this->github_org,
			$slug
		);

		if ( strpos( $parsed['path'], $expected_prefix ) !== 0 ) {
			return false;
		}

		$tag_segment = substr( $parsed['path'], strlen( $expected_prefix ) );
		if ( ! preg_match( '/^[a-zA-Z0-9_\-.]+\.zip$/', $tag_segment ) ) {
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

	// -------------------------------------------------------------------------
	// Updates: Data Layer
	// -------------------------------------------------------------------------

	/**
	 * Return reusable GitHub API request args (headers, timeout, token).
	 *
	 * @return array
	 */
	private function get_github_api_args() {
		$args = array(
			'timeout' => 10,
			'headers' => array(
				'User-Agent'           => 'WordPress/SudoWP-Hub-' . get_bloginfo( 'url' ),
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
		);

		$token = get_option( 'sudowp_hub_gh_token', '' );
		if ( ! empty( $token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		return $args;
	}

	/**
	 * Get the count of SudoWP plugins with pending updates.
	 * Uses cached data only; never triggers a fresh API call.
	 *
	 * @return int
	 */
	private function get_pending_update_count() {
		$cached = get_transient( 'sudowp_hub_update_data' );
		if ( false === $cached || ! is_array( $cached ) ) {
			return 0;
		}

		return count( array_filter( $cached, function ( $item ) {
			return ! empty( $item['has_update'] );
		} ) );
	}

	/**
	 * Fetch the full list of repo names in the Sudo-WP GitHub org.
	 * Cached with the update TTL to avoid repeated API calls.
	 *
	 * @return string[]
	 */
	private function get_sudowp_org_repos() {
		$cache_key = 'sudowp_hub_org_repos';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$repos    = array();
		$page     = 1;
		$per_page = 100;

		do {
			$url = sprintf(
				'https://api.github.com/orgs/%s/repos?per_page=%d&page=%d',
				rawurlencode( $this->github_org ),
				$per_page,
				$page
			);

			$response = wp_remote_get( $url, $this->get_github_api_args() );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) ) {
				break;
			}

			foreach ( $body as $repo ) {
				if ( isset( $repo['name'] ) && is_string( $repo['name'] ) ) {
					$repos[] = sanitize_text_field( $repo['name'] );
				}
			}

			$page++;
		} while ( count( $body ) === $per_page );

		set_transient( $cache_key, $repos, $this->update_cache_ttl );

		return $repos;
	}

	/**
	 * Fetch the latest tag version for a single GitHub repo.
	 * Returns an array with 'raw' (original tag name) and 'version' (stripped),
	 * or false on failure.
	 *
	 * @param string $repo_slug Repository name.
	 * @return array{raw: string, version: string}|false
	 */
	private function get_repo_latest_version( $repo_slug ) {
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/tags?per_page=1',
			rawurlencode( $this->github_org ),
			rawurlencode( $repo_slug )
		);

		$response = wp_remote_get( $url, $this->get_github_api_args() );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body ) || ! isset( $body[0]['name'] ) ) {
			return false;
		}

		$raw = sanitize_text_field( $body[0]['name'] );

		// Strip leading 'v' or 'V' for version comparison (e.g. v1.2.3 becomes 1.2.3).
		return array(
			'raw'     => $raw,
			'version' => ltrim( $raw, 'vV' ),
		);
	}

	/**
	 * Gather update data for all installed SudoWP plugins.
	 *
	 * @param bool $force_refresh Whether to bypass the transient cache.
	 * @return array[] Each entry has: slug, name, plugin_file, installed_version, latest_version, has_update, repo_url.
	 */
	private function get_sudowp_update_data( $force_refresh = false ) {
		$cache_key = 'sudowp_hub_update_data';

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$org_repos   = $this->get_sudowp_org_repos();

		if ( empty( $org_repos ) ) {
			return array();
		}

		$update_data = array();

		foreach ( $all_plugins as $plugin_file => $plugin_info ) {
			$folder = strpos( $plugin_file, '/' ) !== false
				? explode( '/', $plugin_file )[0]
				: pathinfo( $plugin_file, PATHINFO_FILENAME );

			if ( ! in_array( $folder, $org_repos, true ) ) {
				continue;
			}

			$installed_version = $plugin_info['Version'] ?? '0.0.0';
			$tag_data          = $this->get_repo_latest_version( $folder );

			$has_update     = false;
			$latest_version = false;
			$latest_raw     = false;

			if ( $tag_data ) {
				$latest_version = $tag_data['version'];
				$latest_raw     = $tag_data['raw'];
				if ( version_compare( $latest_version, $installed_version, '>' ) ) {
					$has_update = true;
				}
			}

			$update_data[] = array(
				'slug'              => $folder,
				'name'              => $plugin_info['Name'] ?? $folder,
				'plugin_file'       => $plugin_file,
				'installed_version' => $installed_version,
				'latest_version'    => $latest_version,
				'latest_raw'        => $latest_raw,
				'has_update'        => $has_update,
				'repo_url'          => sprintf( 'https://github.com/%s/%s', $this->github_org, $folder ),
			);
		}

		set_transient( $cache_key, $update_data, $this->update_cache_ttl );

		return $update_data;
	}

	// -------------------------------------------------------------------------
	// Updates: AJAX
	// -------------------------------------------------------------------------

	/**
	 * Handle AJAX request to force-refresh update data.
	 */
	public function ajax_check_updates() {
		check_ajax_referer( 'sudowp_check_updates', '_nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sudowp-hub' ) );
		}

		$user_id  = get_current_user_id();
		$rate_key = 'sudowp_rl_updates_' . $user_id;
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( __( 'Please wait before checking again.', 'sudowp-hub' ) );
		}
		set_transient( $rate_key, 1, $this->rate_limit_window );

		// Clear cached data to force a fresh fetch.
		delete_transient( 'sudowp_hub_update_data' );
		delete_transient( 'sudowp_hub_org_repos' );

		$data = $this->get_sudowp_update_data( true );

		wp_send_json_success( array( 'count' => count( $data ) ) );
	}

	/**
	 * Handle AJAX request to run plugin updates via tag-based ZIP installs.
	 * Follows nonce, capability, rate-limit pattern.
	 */
	public function ajax_run_updates() {
		// 1. Nonce.
		check_ajax_referer( 'sudowp_run_updates', '_nonce' );

		// 2. Capability.
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sudowp-hub' ) );
		}

		// 3. Rate limit: 30-second window per user.
		$user_id  = get_current_user_id();
		$rate_key = 'sudowp_rl_run_updates_' . $user_id;
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( __( 'Please wait before running updates again.', 'sudowp-hub' ) );
		}
		set_transient( $rate_key, 1, 30 );

		// 4. Sanitize input.
		$raw_files = isset( $_POST['plugin_files'] ) ? (array) $_POST['plugin_files'] : array();
		if ( empty( $raw_files ) ) {
			wp_send_json_error( __( 'No plugins selected.', 'sudowp-hub' ) );
		}

		$plugin_files = array();
		foreach ( $raw_files as $file ) {
			$plugin_files[] = sanitize_text_field( wp_unslash( $file ) );
		}

		// 5. Validate each slug against org repos.
		$org_repos = $this->get_sudowp_org_repos();
		$updated   = array();
		$failed    = array();

		// 6. Load upgrader dependencies.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		foreach ( $plugin_files as $plugin_file ) {
			// Extract folder slug from plugin file path.
			$slug = strpos( $plugin_file, '/' ) !== false
				? explode( '/', $plugin_file )[0]
				: pathinfo( $plugin_file, PATHINFO_FILENAME );

			// Validate slug belongs to org.
			if ( ! in_array( $slug, $org_repos, true ) ) {
				$failed[] = array(
					'plugin_file' => $plugin_file,
					'message'     => __( 'Plugin not found in SudoWP organization.', 'sudowp-hub' ),
				);
				continue;
			}

			// Get latest tag.
			$tag_data = $this->get_repo_latest_version( $slug );
			if ( ! $tag_data ) {
				$failed[] = array(
					'plugin_file' => $plugin_file,
					'message'     => __( 'Could not retrieve latest tag from GitHub.', 'sudowp-hub' ),
				);
				continue;
			}

			// Construct tag-based ZIP URL.
			$zip_url = sprintf(
				'https://github.com/%s/%s/archive/refs/tags/%s.zip',
				rawurlencode( $this->github_org ),
				rawurlencode( $slug ),
				rawurlencode( $tag_data['raw'] )
			);

			// Validate the URL.
			if ( ! $this->validate_github_tag_url( $zip_url, $slug ) ) {
				$failed[] = array(
					'plugin_file' => $plugin_file,
					'message'     => __( 'Invalid tag URL. Update skipped.', 'sudowp-hub' ),
				);
				continue;
			}

			// Run the upgrade via Plugin_Upgrader.
			$this->current_install_slug = $slug;
			add_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ), 10, 3 );

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $zip_url );

			remove_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ) );
			$this->current_install_slug = null;

			// Evaluate result.
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'plugin_file' => $plugin_file,
					'message'     => $result->get_error_message(),
				);
			} elseif ( is_wp_error( $skin->result ) ) {
				$failed[] = array(
					'plugin_file' => $plugin_file,
					'message'     => $skin->result->get_error_message(),
				);
			} elseif ( ! $result ) {
				$failed[] = array(
					'plugin_file' => $plugin_file,
					'message'     => __( 'Installation failed. Check file permissions.', 'sudowp-hub' ),
				);
			} else {
				$updated[] = $plugin_file;
			}
		}

		// Clear cached update data to force fresh check on next page load.
		delete_transient( 'sudowp_hub_update_data' );
		delete_transient( 'sudowp_hub_org_repos' );

		wp_send_json_success( array(
			'updated' => $updated,
			'failed'  => $failed,
			'count'   => count( $updated ),
		) );
	}

	// -------------------------------------------------------------------------
	// Updates: Page Render
	// -------------------------------------------------------------------------

	/**
	 * Render the Updates admin page.
	 * Mirrors the layout of wp-admin/update-core.php.
	 */
	public function render_updates_page() {
		$update_data       = $this->get_sudowp_update_data();
		$updates_available = array_filter( $update_data, function ( $item ) {
			return $item['has_update'];
		} );
		$update_count      = count( $updates_available );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SudoWP Updates', 'sudowp-hub' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( empty( $update_data ) ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No SudoWP plugins are currently installed, or the GitHub API could not be reached. Click "Check for Updates" to try again.', 'sudowp-hub' ); ?></p>
				</div>

				<p>
					<button type="button" id="sudowp-check-updates" class="button button-secondary">
						<?php esc_html_e( 'Check for Updates', 'sudowp-hub' ); ?>
					</button>
				</p>

			<?php else : ?>

				<?php if ( $update_count > 0 ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %d: number of plugins with updates */
								esc_html( _n(
									'%d SudoWP plugin has an update available.',
									'%d SudoWP plugins have updates available.',
									$update_count,
									'sudowp-hub'
								) ),
								$update_count
							);
							?>
						</p>
					</div>

					<p class="sudowp-bulk-actions">
						<button type="submit" form="sudowp-bulk-update-form" class="button button-primary sudowp-bulk-submit">
							<?php
							printf(
								/* translators: %d: number of plugins */
								esc_html__( 'Update %d Plugins', 'sudowp-hub' ),
								$update_count
							);
							?>
						</button>
					</p>
				<?php elseif ( ! empty( $update_data ) ) : ?>
					<div class="notice notice-success">
						<p><?php esc_html_e( 'Your SudoWP plugins are all up to date.', 'sudowp-hub' ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" id="sudowp-bulk-update-form">
					<?php wp_nonce_field( 'sudowp_run_updates', '_sudowp_nonce' ); ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column" style="padding:8px;">
									<?php if ( $update_count > 0 ) : ?>
										<input type="checkbox" id="sudowp-select-all" />
									<?php endif; ?>
								</td>
								<th><?php esc_html_e( 'Plugin', 'sudowp-hub' ); ?></th>
								<th><?php esc_html_e( 'Installed Version', 'sudowp-hub' ); ?></th>
								<th><?php esc_html_e( 'Latest Version', 'sudowp-hub' ); ?></th>
								<th><?php esc_html_e( 'Status', 'sudowp-hub' ); ?></th>
								<th><?php esc_html_e( 'Action', 'sudowp-hub' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $update_data as $plugin ) : ?>
								<tr data-plugin="<?php echo esc_attr( $plugin['plugin_file'] ); ?>">
									<td class="check-column" style="padding:8px;">
										<?php if ( $plugin['has_update'] ) : ?>
											<input type="checkbox" name="checked[]" value="<?php echo esc_attr( $plugin['plugin_file'] ); ?>" />
										<?php endif; ?>
									</td>
									<td>
										<a href="<?php echo esc_url( $plugin['repo_url'] ); ?>" target="_blank" rel="noopener noreferrer">
											<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
										</a>
									</td>
									<td><?php echo esc_html( $plugin['installed_version'] ); ?></td>
									<td>
										<?php
										if ( false === $plugin['latest_version'] ) {
											esc_html_e( 'No release tag', 'sudowp-hub' );
										} else {
											echo esc_html( $plugin['latest_version'] );
										}
										?>
									</td>
									<td class="sudowp-status-cell">
										<?php if ( $plugin['has_update'] ) : ?>
											<span style="color:#d63638;font-weight:600;">
												<?php esc_html_e( 'Update available', 'sudowp-hub' ); ?>
											</span>
										<?php elseif ( false === $plugin['latest_version'] ) : ?>
											<span style="color:#996800;">
												<?php esc_html_e( 'No tags found', 'sudowp-hub' ); ?>
											</span>
										<?php else : ?>
											<span style="color:#00a32a;">
												<?php esc_html_e( 'Up to date', 'sudowp-hub' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $plugin['has_update'] ) : ?>
											<a href="#" class="sudowp-update-single" data-plugin="<?php echo esc_attr( $plugin['plugin_file'] ); ?>" data-slug="<?php echo esc_attr( $plugin['slug'] ); ?>">
												<?php esc_html_e( 'Update now', 'sudowp-hub' ); ?>
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>

				<?php if ( $update_count > 0 ) : ?>
					<p class="sudowp-bulk-actions" style="margin-top:12px;">
						<button type="submit" form="sudowp-bulk-update-form" class="button button-primary sudowp-bulk-submit">
							<?php
							printf(
								/* translators: %d: number of plugins */
								esc_html__( 'Update %d Plugins', 'sudowp-hub' ),
								$update_count
							);
							?>
						</button>
					</p>
				<?php endif; ?>

				<p style="margin-top:16px;">
					<button type="button" id="sudowp-check-updates" class="button button-secondary">
						<?php esc_html_e( 'Check for Updates', 'sudowp-hub' ); ?>
					</button>
				</p>

			<?php endif; ?>

			<?php
			$timeout = get_option( '_transient_timeout_sudowp_hub_update_data' );
			if ( $timeout ) {
				$cached_since = (int) $timeout - $this->update_cache_ttl;
				printf(
					'<p class="description" style="margin-top:12px;">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: human-readable time difference */
							__( 'Last checked: %s ago', 'sudowp-hub' ),
							human_time_diff( $cached_since, time() )
						)
					)
				);
			}
			?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Updates: Inline JS
	// -------------------------------------------------------------------------

	/**
	 * Return the inline JS for the Updates page.
	 *
	 * @return string
	 */
	private function get_updates_inline_js() {
		return <<<'JS'
(function($) {
	'use strict';
	var hub = SudoWPHubUpdates;

	// "Check for Updates" button.
	$('#sudowp-check-updates').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		$btn.prop('disabled', true).text(hub.i18n.checking);

		$.post(hub.ajaxUrl, {
			action: 'sudowp_hub_check_updates',
			_nonce: hub.nonce
		})
		.always(function() {
			window.location.reload();
		});
	});

	// "Select all" checkbox toggle.
	$('#sudowp-select-all').on('change', function() {
		$('input[name="checked[]"]').prop('checked', $(this).prop('checked'));
	});

	// "Update now" single inline link.
	$(document).on('click', '.sudowp-update-single', function(e) {
		e.preventDefault();
		var $link = $(this);
		if ($link.hasClass('disabled')) {
			return;
		}

		var pluginFile = $link.data('plugin');
		var $row = $('tr[data-plugin="' + pluginFile + '"]');
		var $status = $row.find('.sudowp-status-cell');

		$link.addClass('disabled').css('pointer-events', 'none').text(hub.i18n.updating);

		$.post(hub.ajaxUrl, {
			action: 'sudowp_hub_run_updates',
			'plugin_files[]': pluginFile,
			_nonce: hub.updatesNonce
		})
		.done(function(response) {
			if (response.success && response.data.updated && response.data.updated.length > 0) {
				$status.html('<span style="color:#00a32a;font-weight:600;">' + hub.i18n.updated + ' - reloading...</span>');
				setTimeout(function() { window.location.reload(); }, 1000);
			} else {
				var msg = hub.i18n.updateFailed;
				if (response.data && response.data.failed && response.data.failed.length > 0) {
					msg = response.data.failed[0].message;
				} else if (response.data && typeof response.data === 'string') {
					msg = response.data;
				}
				$status.html('<span style="color:#d63638;font-weight:600;">' + $('<span>').text(msg).html() + '</span>');
				$link.removeClass('disabled').css('pointer-events', '').text('Update now');
			}
		})
		.fail(function() {
			$status.html('<span style="color:#d63638;font-weight:600;">' + hub.i18n.updateFailed + '</span>');
			$link.removeClass('disabled').css('pointer-events', '').text('Update now');
		});
	});

	// "Update Selected" bulk form submit.
	$('#sudowp-bulk-update-form').on('submit', function(e) {
		e.preventDefault();

		var checked = [];
		$('input[name="checked[]"]:checked').each(function() {
			checked.push($(this).val());
		});

		if (checked.length === 0) {
			return;
		}

		var msg = hub.i18n.confirmBulk.replace('%d', checked.length);
		if (!confirm(msg)) {
			return;
		}

		// Disable all submit buttons during bulk update.
		$('.sudowp-bulk-submit').prop('disabled', true);

		$.post(hub.ajaxUrl, {
			action: 'sudowp_hub_run_updates',
			'plugin_files': checked,
			_nonce: hub.updatesNonce
		})
		.done(function(response) {
			if (response.success) {
				// Mark updated rows.
				if (response.data.updated) {
					$.each(response.data.updated, function(i, pf) {
						var $row = $('tr[data-plugin="' + pf + '"]');
						$row.find('.sudowp-status-cell').html('<span style="color:#00a32a;font-weight:600;">' + hub.i18n.updated + '</span>');
					});
				}
				// Mark failed rows.
				if (response.data.failed) {
					$.each(response.data.failed, function(i, item) {
						var $row = $('tr[data-plugin="' + item.plugin_file + '"]');
						$row.find('.sudowp-status-cell').html('<span style="color:#d63638;font-weight:600;">' + $('<span>').text(item.message).html() + '</span>');
					});
				}
				setTimeout(function() { window.location.reload(); }, 1500);
			} else {
				$('.sudowp-bulk-submit').prop('disabled', false);
			}
		})
		.fail(function() {
			$('.sudowp-bulk-submit').prop('disabled', false);
		});
	});
}(jQuery));
JS;
	}

}

SudoWP_Hub::get_instance();
