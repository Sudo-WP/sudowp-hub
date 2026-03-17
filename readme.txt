=== SudoWP Hub ===
Contributors: sudowp
Tags: github, installer, security, patch, maintenance, abandoned-plugins
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.5.12
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Connects your site to the SudoWP GitHub Organization. Search and install patched, secured, and maintained plugins directly from the dashboard.

== Description ==

**SudoWP Hub** is the official bridge between your WordPress site and the **SudoWP** project.

It effectively transforms the SudoWP GitHub Organization into a private "Plugin Store", allowing you to search, download, and install patched and secured plugins ("Rescued Abandonware") directly from your WordPress dashboard, without manually handling ZIP files.

**Why use SudoWP Hub?**

* **Centralized Repository:** Access the entire catalog of SudoWP's patched plugins and themes in one place.
* **Native Experience:** Designed to look and feel exactly like the native WordPress "Add New" plugin screen.
* **Smart Installation:** GitHub ZIPs usually extract to folders like `plugin-name-main`. SudoWP Hub automatically intercepts the installation to **rename the folder correctly** (e.g., `plugin-name`), ensuring your plugins work as intended and maintain standard paths.
* **Dual Search:** Toggle easily between searching for **Plugins** or **Themes**.
* **Result Caching:** Search results are cached server-side for 5 minutes to minimize GitHub API usage.

**Key Features:**

* Live Search connected to `org:Sudo-WP` (up to 100 results per query).
* AJAX-powered "Install Now" & "Activate" workflow with inline error display.
* **GitHub Token Support:** Avoid API rate limits (403 errors) by adding your Personal Access Token in the settings. Token can be cleared by submitting an empty field.
* Strict SSRF-prevention: all install URLs are validated server-side before download.
* Capability-gated search and install: only users with `install_plugins` can trigger API calls.
* Per-user rate limiting on both search and install AJAX endpoints.

== Installation ==

1. Upload the `sudowp-hub` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. A new menu item **"SudoWP Store"** will appear in your admin dashboard.
4. (Optional) Go to the Configuration section at the bottom of the store page and add a GitHub Personal Access Token (`public_repo` scope) to increase your API rate limits.

== Frequently Asked Questions ==

= Why do I see a "Rate Limit Exceeded" error? =
The GitHub public API allows approximately 60 unauthenticated requests per hour. If you are searching frequently, you may hit this limit. Generate a free Personal Access Token on GitHub (with `public_repo` scope) and paste it into the plugin settings to raise the limit to 5,000 requests/hour.

= How do I remove a saved GitHub token? =
Navigate to the Configuration section on the SudoWP Store page. The input will show a masked placeholder indicating a token is saved. Simply submit the form with the field left empty to clear it.

= Is this safe? =
Yes. SudoWP Hub only searches and installs software from the **Sudo-WP** organization on GitHub. All install URLs are strictly validated on the server side before any download is initiated, preventing SSRF attacks. Only WordPress users with the `install_plugins` capability can trigger searches or installations.

= Why are search results cached? =
To reduce GitHub API calls and improve performance. Results are cached for 5 minutes per unique search term and type. Caches are stored as WordPress transients and are automatically cleared by WordPress.

== Changelog ==

= 1.5.12 =
* Fix: Plugins that were active before an update are now re-activated automatically after the upgrade completes, matching WordPress core behavior. Multisite network-wide activation is preserved.

= 1.5.11 =
* Cleanup: Removed all diagnostic logging. Update mechanism confirmed working correctly via v1.5.10 diagnostics.

= 1.5.10 =
* Debug: Diagnostic logging added to upgrade() and rename_github_source() to trace why updates report success but do not change files on disk. Check wp-content/debug.log after triggering an update.

= 1.5.9 =
* Fix: Plugin updates now use Plugin_Upgrader->upgrade() by injecting the GitHub package URL into the update_plugins transient. This is the same code path WordPress core uses for all plugin updates, ensuring correct filesystem handling, plugin deactivation before file replacement, and temp backup support.

= 1.5.8 =
* Fix: Plugin updates now apply correctly on live servers using FTP/SSH filesystem methods. Replaced manual delete-then-install pattern with Plugin_Upgrader->run() using clear_destination, matching WordPress core update behavior.
* Cleanup: Removed temporary error_log() calls from rename_github_source().

= 1.5.7 =
* Fix: Updates page no longer shows "Update available" immediately after a successful update. Added wp_clean_plugins_cache() to clear_all_update_caches() so WordPress re-reads plugin headers from disk.
* Debug: Temporary error_log() calls added to rename_github_source() to trace folder rename behavior during tag-based installs.

= 1.5.6 =
* Fix: Update install no longer fails when the GitHub org repos API returns empty. Falls back to scanning installed plugins for sudowp-prefixed folders.

= 1.5.5 =
* Fix: Rate-limited responses (HTTP 403/429) from GitHub tag API are now cached for 5 minutes, breaking the retry loop that exhausted the unauthenticated rate limit.
* UX: Updates page shows a warning notice with a link to settings when no GitHub token is configured.

= 1.5.4 =
* Fix: Tag lookup now caches results per slug (12-hour transient), preventing rate-limit exhaustion when multiple SudoWP plugins are installed.
* Fix: API failures no longer cache a false-negative; only genuine "no tags" results are cached.
* Refactor: All cache-clearing paths now use clear_all_update_caches() to consistently flush per-slug tag caches alongside the main update data.

= 1.5.0 =
* Feature: Automatic daily background update check via wp-cron, modeled on WordPress core update behavior.
* Feature: Updates page now shows time of next scheduled automatic check alongside the last checked timestamp.
* Scheduled event is registered on activation, cleared on deactivation and uninstall, and self-heals on admin_init if missing.

= 1.4.1 =
* Fix: get_repo_latest_version() now fetches up to 20 tags and selects the highest by semver rather than trusting GitHub's creation-date ordering. Resolves incorrect "Up to date" status when tags were created out of version order.

= 1.4.0 =
* Feature: Rewritten Updates page with per-row "Update now" links and bulk "Update Selected" functionality.
* Feature: Updates tab moved to native Plugins list table via views_plugins filter, with pending count badge.
* Feature: New ajax_run_updates() AJAX handler with nonce, capability, and 30-second rate limiting.
* Feature: Tag-based ZIP URL construction for updates, with new validate_github_tag_url() validation method.
* Enhancement: get_repo_latest_version() now returns raw tag name alongside stripped version for accurate ZIP URLs.
* Enhancement: Updates page layout mirrors wp-admin/update-core.php with form, checkboxes, and status columns.
* UX: Inline per-row status feedback during single and bulk update operations.

= 1.3.0 =
* Feature: New "Updates" submenu page showing installed SudoWP plugins with their update status compared to GitHub.
* Feature: Automatic version comparison between installed plugin headers and latest GitHub tags.
* Feature: "Check for Updates" button with rate limiting and 12-hour transient cache.
* Feature: Update count badge on the menu item when updates are pending.
* Refactor: Extracted shared GitHub API request args into reusable get_github_api_args() method.

= 1.2.1 =
* Security: Type-aware capability check (install_themes vs install_plugins) on search and install handlers.
* Security: Applied wp_kses_post output escaping on rendered card HTML.
* Security: Applied wp_unslash on all POST data access.
* Security: Added uninstall.php with token and transient cleanup on plugin deletion.

= 1.2.0 =
* Renamed admin menu item and page title from "SudoWP Store" to "SudoWP Hub".
* Feature: Plugin cards now show installed state. Installed and active plugins show an "Active" badge. Installed but inactive plugins show an "Activate" button linking to the plugins screen. Uninstalled plugins continue to show "Install Now".
* Feature: Theme cards use the same installed-state detection. Installed and active themes show "Active". Installed but inactive themes show "Activate" linking to the themes screen.
* Refactor: GitHub API search results are now cached as raw item arrays rather than rendered HTML, so installed-state detection always reflects the current install/active state rather than the state at cache-fill time.

= 1.1.1 =
* Removed unused $query variable in ajax_search() identified during Session 5a code review (cosmetic dead code, no functional impact).

= 1.1.0 =
* Security: Added `install_plugins` capability check to AJAX search handler.
* Security: Nonces now passed via `wp_localize_script()` - no longer embedded as string literals in inline JS.
* Security: GitHub Authorization header updated from deprecated `token` prefix to `Bearer`.
* Security: Strict validation of all GitHub API response fields before rendering.
* Security: ZIP URLs constructed server-side from validated parts - client-supplied URL is re-validated before install.
* Security: `rename_github_source` now returns a `WP_Error` on failure instead of silently returning the original path.
* Security: `validate_github_url` now also blocks URLs containing query strings, fragments, or credentials.
* Fix: GitHub token can now be cleared by submitting an empty field (with sentinel field logic).
* Fix: Token sanitization strengthened - only alphanumeric + `-` + `_` characters accepted.
* Performance: Search results cached as WordPress transients for 5 minutes.
* Performance: Removed unnecessary `plugin-install` and `updates` script dependencies; plugin now registers its own handle.
* Performance: GitHub API query now requests up to 100 results per page.
* Performance: Added per-user AJAX rate limiting (2-second window) for both search and install.
* Compliance: Added `load_plugin_textdomain()` for full i18n support.
* Compliance: All strings wrapped in translation functions with proper text domain.
* Compliance: All output properly escaped with context-appropriate functions (`esc_html`, `esc_attr`, `esc_url`).
* Compliance: Settings form action now points explicitly to `options.php`.
* UX: Install errors now displayed inline rather than via `alert()`.
* UX: External links include `rel="noopener noreferrer"`.
* UX: GitHub API HTTP status codes (403, 429, non-200) handled explicitly with user-friendly messages.
* Code: Extracted `build_results_html()` and `get_inline_js()` to keep methods focused.
* Code: Added `require_once` for `misc.php` in install handler (needed by some upgrader paths).

= 1.0.0 =
* Initial release.
* Added support for Plugins and Themes search.
* Implemented Smart Folder Renaming for GitHub zipballs.
* Added GitHub Token support for API rate limits.
