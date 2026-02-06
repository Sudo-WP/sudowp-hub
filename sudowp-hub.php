<?php
/**
 * Plugin Name: SudoWP Hub
 * Plugin URI:  https://sudowp.com
 * Description: Connects to the SudoWP GitHub organization to search and install patched security plugins and themes directly.
 * Version:     1.0.0
 * Author:      SudoWP
 * Author URI:  https://sudowp.com
 * License:     GPLv2 or later
 * Text Domain: sudowp-hub
 */

defined( 'ABSPATH' ) || exit;

class SudoWP_Hub {

	private $github_org = 'Sudo-WP'; // Your GitHub Organization
	private $api_url    = 'https://api.github.com/search/repositories';
	private $current_install_slug = null; // Store current installation slug safely

	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			$instance->init();
		}
		return $instance;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		
		// AJAX Handlers
		add_action( 'wp_ajax_sudowp_hub_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_sudowp_hub_install', array( $this, 'ajax_install' ) );

		// Settings for GitHub Token (to avoid rate limits)
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

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

	public function register_settings() {
		register_setting( 'sudowp_hub_options', 'sudowp_hub_gh_token', array(
			'sanitize_callback' => array( $this, 'sanitize_token' )
		) );
		
		add_settings_section(
			'sudowp_hub_main',
			'GitHub Settings',
			null,
			'sudowp-hub-settings'
		);
		
		add_settings_field(
			'sudowp_hub_gh_token',
			'Personal Access Token',
			array( $this, 'render_token_field' ),
			'sudowp-hub-settings',
			'sudowp_hub_main'
		);
	}

	public function sanitize_token( $token ) {
		// Only update if a new token is provided (not empty)
		if ( empty( $token ) ) {
			return get_option( 'sudowp_hub_gh_token' );
		}
		// Sanitize the token - remove any whitespace
		return sanitize_text_field( trim( $token ) );
	}

	public function render_token_field() {
    $token = get_option( 'sudowp_hub_gh_token' );
    // Don't show token value for security - use placeholder instead
    $placeholder = ! empty( $token ) ? '••••••••••••••••' : '';
    echo '<input type="password" name="sudowp_hub_gh_token" value="" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" />';
    echo '<p class="description"><strong>Optional:</strong> Keeps the service free and open. Add a GitHub Token only if you experience search issues or rate limits.</p>';
}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_sudowp-hub' !== $hook ) {
			return;
		}
		
		wp_enqueue_style( 'plugin-install' );
		wp_enqueue_script( 'plugin-install' );
		wp_enqueue_script( 'updates' );
		
		// Custom JS for our Hub
		wp_add_inline_script( 'plugin-install', "
			jQuery(document).ready(function($) {
				// Search Handler
				var searchTimer;
				$('#sudowp-search-input').on('keyup', function() {
					clearTimeout(searchTimer);
					var query = $(this).val();
					var type  = $('input[name=\"sudowp_type\"]:checked').val();
					
					$('#the-list').html('<div style=\"padding:20px;\"><span class=\"spinner is-active\" style=\"float:none;\"></span> Searching GitHub...</div>');
					
					searchTimer = setTimeout(function() {
						$.post(ajaxurl, {
							action: 'sudowp_hub_search',
							term: query,
							type: type,
							_nonce: '" . wp_create_nonce( 'sudowp_search' ) . "'
						}, function(response) {
							$('#the-list').html(response.data);
						});
					}, 600);
				});

				// Initial Load
				$('#sudowp-search-input').trigger('keyup');

				// Type Toggle Handler
				$('input[name=\"sudowp_type\"]').on('change', function() {
					$('#sudowp-search-input').trigger('keyup');
				});

				// Install Handler
				$(document).on('click', '.sudowp-install-now', function(e) {
					e.preventDefault();
					var btn = $(this);
					
					if(btn.hasClass('updating-message') || btn.hasClass('button-disabled')) return;
					
					btn.addClass('updating-message').text('Installing...');
					
					$.post(ajaxurl, {
						action: 'sudowp_hub_install',
						repo_url: btn.data('zip'),
						slug: btn.data('slug'),
						type: btn.data('type'), // plugin or theme
						_nonce: '" . wp_create_nonce( 'sudowp_install' ) . "'
					}, function(response) {
						btn.removeClass('updating-message');
						if(response.success) {
							btn.addClass('button-disabled').text('Installed!');
							btn.after(' <a href=\"' + response.data.activate_url + '\" class=\"button button-primary activate-now\">Activate</a>');
						} else {
							btn.text('Failed');
							alert(response.data);
						}
					});
				});
			});
		" );
	}

	public function render_page() {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">SudoWP Store</h1>
			<p class="description">Search and install patched plugins/themes directly from the SudoWP GitHub Organization.</p>
			<hr class="wp-header-end">

			<div class="wp-filter">
				<ul class="filter-links">
					<li>
						<label><input type="radio" name="sudowp_type" value="plugin" checked> Plugins</label>
					</li>
					<li>
						<label><input type="radio" name="sudowp_type" value="theme"> Themes</label>
					</li>
				</ul>
				<div class="search-form">
					<label class="screen-reader-text" for="sudowp-search-input">Search Plugins</label>
					<input type="search" id="sudowp-search-input" class="wp-filter-search" placeholder="Search SudoWP Patches..." aria-describedby="live-search-desc">
				</div>
			</div>

			<br class="clear">

			<div id="the-list" class="widefat plugin-install-network">
				</div>
			
			<div style="margin-top: 30px; border-top: 1px solid #ccc; padding-top: 20px;">
				<h3>Configuration</h3>
				<form method="post" action="options.php">
					<?php settings_fields( 'sudowp_hub_options' ); ?>
					<?php do_settings_sections( 'sudowp-hub-settings' ); ?>
					<?php submit_button(); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Search GitHub
	 */
	public function ajax_search() {
		check_ajax_referer( 'sudowp_search', '_nonce' );

		$term = sanitize_text_field( $_POST['term'] );
		$type = sanitize_text_field( $_POST['type'] ); // plugin or theme
		$token = get_option( 'sudowp_hub_gh_token' );

		// Construct Query: Search inside the Org
		// We prioritize items with 'sudowp' in the name
		$query = "org:{$this->github_org} {$term}";
		if ( 'plugin' === $type ) {
			// Optional: you can tag your repos with 'wordpress-plugin' on GitHub to be more specific
			// For now, we search all repos, assuming most are plugins.
		}

		$args = array(
			'headers' => array( 'User-Agent' => 'WordPress/SudoWP-Hub' )
		);
		if ( ! empty( $token ) ) {
			// Use proper GitHub API authentication format
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $this->api_url . '?q=' . urlencode( $query ), $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'GitHub API Error' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['items'] ) ) {
			wp_send_json_error( 'Rate Limit Exceeded or No Results. Configure Token in settings.' );
		}

		ob_start();
		if ( empty( $body['items'] ) ) {
			echo '<p>No patched components found matching that name.</p>';
		} else {
			foreach ( $body['items'] as $repo ) {
				// Filter logic: Only show Themes if type=theme, else Plugins
				// This relies on naming convention or description. 
				// For this prototype, we list everything found in the org matching the query.
				
				$name = esc_html( $repo['name'] );
				$desc = esc_html( $repo['description'] );
				$zip  = esc_url( $repo['html_url'] . '/archive/refs/heads/' . $repo['default_branch'] . '.zip' );
				$last_updated = date_i18n( get_option( 'date_format' ), strtotime( $repo['updated_at'] ) );
				
				// Standard Plugin Card Layout
				?>
				<div class="plugin-card">
					<div class="plugin-card-top">
						<div class="name column-name">
							<h3>
								<a href="<?php echo esc_url( $repo['html_url'] ); ?>" target="_blank">
									<?php echo $name; ?>
								</a>
							</h3>
						</div>
						<div class="action-links">
							<ul class="plugin-action-buttons">
								<li>
									<a class="install-now button sudowp-install-now" 
									   data-slug="<?php echo esc_attr( $name ); ?>" 
									   data-zip="<?php echo $zip; ?>" 
									   data-type="<?php echo esc_attr( $type ); ?>"
									   href="#">Install Now</a>
								</li>
								<li><a href="<?php echo esc_url( $repo['html_url'] ); ?>" target="_blank">More Details</a></li>
							</ul>
						</div>
						<div class="desc column-description">
							<p><?php echo $desc ? $desc : 'No description provided.'; ?></p>
						</div>
					</div>
					<div class="plugin-card-bottom">
						<div class="vers column-rating">
							Last Updated: <?php echo $last_updated; ?>
						</div>
						<div class="column-downloaded">
							Stars: <?php echo intval( $repo['stargazers_count'] ); ?>
						</div>
						<div class="column-compatibility">
							<span class="compatibility-compatible"><strong>SudoWP Patched</strong></span>
						</div>
					</div>
				</div>
				<?php
			}
		}
		$html = ob_get_clean();
		wp_send_json_success( $html );
	}

	/**
	 * AJAX: Install
	 */
	public function ajax_install() {
		check_ajax_referer( 'sudowp_install', '_nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( 'Permission Denied' );
		}

		$url  = isset( $_POST['repo_url'] ) ? $_POST['repo_url'] : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( $_POST['slug'] ) : '';
		$type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'plugin';

		// SECURITY: Validate that the URL is from the expected GitHub organization
		if ( ! $this->validate_github_url( $url, $slug ) ) {
			wp_send_json_error( 'Invalid repository URL. Only SudoWP GitHub repositories are allowed.' );
		}

		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/file.php';

		// Custom Skin to capture output without printing headers/footers
		$skin = new WP_Ajax_Upgrader_Skin();
		
		if ( 'theme' === $type ) {
			$upgrader = new Theme_Upgrader( $skin );
		} else {
			$upgrader = new Plugin_Upgrader( $skin );
		}

		// Store slug in object property so rename_github_source can access it safely
		$this->current_install_slug = $slug;

		// Add filter to rename the folder (GitHub zipball is repo-branch, we want repo)
		add_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ), 10, 3 );

		// Perform Install
		$result = $upgrader->install( $url );

		// Remove filter and clear stored slug
		remove_filter( 'upgrader_source_selection', array( $this, 'rename_github_source' ) );
		$this->current_install_slug = null;

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} elseif ( is_wp_error( $skin->result ) ) {
			wp_send_json_error( $skin->result->get_error_message() );
		} elseif ( $skin->result ) {
			// Generate Activate URL
			if ( 'theme' === $type ) {
				$activate_url = admin_url( 'themes.php' );
			} else {
				// We need to guess the main file. 
				// Usually slug/slug.php. If not, WP handles activation manually via list.
				$activate_url = admin_url( 'plugins.php' ); 
			}
			wp_send_json_success( array( 'activate_url' => $activate_url ) );
		} else {
			wp_send_json_error( 'Installation failed.' );
		}
	}

	/**
	 * Validate that the URL is from the expected GitHub organization
	 * 
	 * @param string $url The URL to validate
	 * @param string $slug The expected repository slug
	 * @return bool True if valid, false otherwise
	 */
	private function validate_github_url( $url, $slug ) {
		// Parse and validate URL
		$parsed_url = wp_parse_url( $url );
		
		if ( ! $parsed_url || ! isset( $parsed_url['host'] ) || ! isset( $parsed_url['path'] ) ) {
			return false;
		}

		// Must be from github.com
		if ( 'github.com' !== $parsed_url['host'] && 'www.github.com' !== $parsed_url['host'] ) {
			return false;
		}

		// Path should match the expected pattern: /{org}/{repo}/archive/refs/heads/{branch}.zip
		$path_pattern = '/^\/' . preg_quote( $this->github_org, '/' ) . '\/[a-zA-Z0-9_-]+\/archive\/refs\/heads\/[a-zA-Z0-9_-]+\.zip$/';
		
		if ( ! preg_match( $path_pattern, $parsed_url['path'] ) ) {
			return false;
		}

		// Additional validation: slug should be alphanumeric with hyphens/underscores only
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $slug ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Smart Renaming: Fixes GitHub's "Repo-Main" folder name issue
	 */
	public function rename_github_source( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;

		// Use the slug stored during install (already sanitized)
		if ( empty( $this->current_install_slug ) ) {
			return $source;
		}

		// Sanitize slug again for extra safety (defense in depth)
		$safe_slug = preg_replace( '/[^a-zA-Z0-9_-]/', '', $this->current_install_slug );
		
		if ( empty( $safe_slug ) ) {
			return $source;
		}
		
		$new_source = trailingslashit( $remote_source ) . $safe_slug . '/';

		if ( $source !== $new_source ) {
			$wp_filesystem->move( $source, $new_source );
			return $new_source;
		}

		return $source;
	}

}

SudoWP_Hub::get_instance();
