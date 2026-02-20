=== SudoWP Hub ===
Contributors: sudowp
Tags: github, installer, security, patch, maintenance, abandoned-plugins
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.1.0
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

= 1.1.0 =
* Security: Added `install_plugins` capability check to AJAX search handler.
* Security: Nonces now passed via `wp_localize_script()` — no longer embedded as string literals in inline JS.
* Security: GitHub Authorization header updated from deprecated `token` prefix to `Bearer`.
* Security: Strict validation of all GitHub API response fields before rendering.
* Security: ZIP URLs constructed server-side from validated parts — client-supplied URL is re-validated before install.
* Security: `rename_github_source` now returns a `WP_Error` on failure instead of silently returning the original path.
* Security: `validate_github_url` now also blocks URLs containing query strings, fragments, or credentials.
* Fix: GitHub token can now be cleared by submitting an empty field (with sentinel field logic).
* Fix: Token sanitization strengthened — only alphanumeric + `-` + `_` characters accepted.
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
