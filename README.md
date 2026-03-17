# SudoWP Hub

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue) ![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b) ![License](https://img.shields.io/badge/License-GPL--2.0-green) ![Version](https://img.shields.io/badge/Version-1.5.12-orange)

A WordPress plugin that connects your wp-admin to the [Sudo-WP GitHub organization](https://github.com/Sudo-WP), allowing you to search, install, and update security-patched plugin forks directly from the dashboard, without downloading or renaming ZIP files manually.

---

## The Problem

Installing a plugin from GitHub has always had a friction problem. GitHub archive ZIPs extract to a folder named `repo-branch` rather than `repo`. WordPress expects the folder to match the plugin slug exactly. Without a manual rename, the plugin either fails to activate or activates under the wrong path.

Keeping those forks up to date is another manual step: check GitHub for new tags, download the ZIP, remove the old plugin directory, upload and rename the new one.

SudoWP Hub removes both steps entirely.

---

## What It Does

### Browse and Install
- Presents all Sudo-WP organization repositories as native-looking plugin cards inside wp-admin, using the same CSS layout as the WordPress Add New plugin screen
- Handles installation via `WP_Upgrader`, the same internal class WordPress uses for all plugin installs
- Intercepts the `upgrader_source_selection` filter to rename the extracted folder from `repo-branch` to the correct slug, server-side, before WordPress finalizes the install
- Caches GitHub API search results for 5 minutes per unique query to reduce API usage
- Supports toggling between plugins and themes

### Updates
- Dedicated Updates page under the SudoWP Hub menu, with a tab on the native Plugins list table
- Compares installed plugin Version headers against the latest GitHub tag (highest semver from up to 20 tags)
- Per-row "Update now" links and bulk "Update Selected" functionality
- Uses `Plugin_Upgrader->upgrade()` via transient injection, the same code path WordPress core uses for all plugin updates
- Automatic daily background update check via wp-cron, with self-healing on admin_init
- Re-activates plugins automatically after a successful update
- Per-slug tag caching (12-hour TTL) with rate-limit-aware caching (5-minute TTL for 403/429 responses)
- Pending update count badge on the menu item

---

## Security Design

SudoWP Hub connects to an external API and performs plugin installations. The following security decisions apply:

**SSRF prevention.** All ZIP download URLs are constructed server-side from validated parts: fixed scheme (`https`), fixed host (`github.com`), fixed organization prefix (`/Sudo-WP/`), a validated slug, and a validated branch or tag segment. Both `validate_github_url()` (branch-based installs) and `validate_github_tag_url()` (tag-based updates) reject any URL containing query strings, fragments, or embedded credentials. Client-supplied URLs are re-validated before `WP_Upgrader` is invoked.

**Capability gating.** All AJAX handlers require appropriate capabilities: `install_plugins` for search and install, `update_plugins` for update operations. The admin menu pages require `install_plugins` to render. Unauthenticated and low-privilege users cannot reach any of the plugin's functionality.

**Nonce handling.** Nonces are passed to JavaScript exclusively via `wp_localize_script()` on plugin-owned script handles (`sudowp-hub-admin` and `sudowp-hub-updates`). They are never embedded as string literals in inline JavaScript.

**Per-user rate limiting.** Transient-based rate limiting is enforced on all AJAX endpoints: 2-second window on search and install, 30-second window on update operations. This prevents rapid repeated requests that could exhaust the GitHub API rate limit from the server's IP.

**Token sanitization.** GitHub Personal Access Tokens are sanitized to `[a-zA-Z0-9_-]` only, trimmed to 255 characters. The `Authorization` header uses the `Bearer` prefix per current GitHub API documentation. A sentinel field distinguishes deliberate token clearing from an empty form submission.

**Slug validation.** The `is_valid_slug()` helper restricts slugs to `[a-zA-Z0-9_-]` before any slug value is used in a regex or filesystem path. This runs before URL validation to prevent ReDoS.

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| GitHub API | Public access (no token required; token recommended) |

---

## Installation

1. Download the latest release ZIP from the [Releases tab](https://github.com/Sudo-WP/sudowp-hub/releases).
2. In wp-admin, go to Plugins - Add New - Upload Plugin.
3. Upload the ZIP and activate.
4. A "SudoWP Hub" item will appear in the admin menu with Browse and Updates subpages.

**Optional:** Add a GitHub Personal Access Token (`public_repo` scope) in the Configuration section at the bottom of the Browse page. Without a token, the GitHub API allows approximately 10 search requests per minute from the server's IP. With a token, the limit rises to 5,000 requests per hour. A token is strongly recommended for the Updates page, which makes multiple API calls per page load.

---

## GitHub Token

To add a token:

1. Go to GitHub - Settings - Developer settings - Personal access tokens - Fine-grained tokens (or classic tokens with `public_repo` scope).
2. Generate a token.
3. Paste it into the Configuration section on the SudoWP Hub Browse page and click Save Settings.

To clear a saved token: leave the field empty and click Save Settings. The plugin uses a sentinel field to distinguish deliberate clearing from an accidental empty submission.

---

## Limitations

- **No file integrity verification.** Hub trusts the GitHub API over HTTPS and relies on the same `WP_Upgrader` pipeline WordPress uses for all installs and updates.
- **Pagination not yet implemented.** The GitHub API caps results at 100 per query. If the Sudo-WP organization grows beyond 100 repositories, results will be silently truncated. A result count indicator and pagination are planned for a future release.
- **Activation URL.** After a fresh install, the Activate link points to `plugins.php` rather than a direct activation URL. This is a known UX gap; direct activation URL construction is planned.

---

## Architecture

Single-file plugin. All logic lives in `sudowp-hub.php` inside the `SudoWP_Hub` class using a singleton pattern. No external dependencies. No build step. No Composer.

Key methods:

| Method | Purpose |
|---|---|
| `init()` | Registers all hooks |
| `ajax_search()` | Handles GitHub API search, caches results as transients |
| `ajax_install()` | Handles plugin/theme installation via `WP_Upgrader` |
| `ajax_check_updates()` | Force-refreshes update data transients |
| `ajax_run_updates()` | Per-row and bulk plugin updates via `Plugin_Upgrader->upgrade()` |
| `build_results_html()` | Renders plugin card HTML from GitHub API items |
| `validate_github_url()` | SSRF prevention for branch-based ZIP URLs |
| `validate_github_tag_url()` | SSRF prevention for tag-based ZIP URLs (updates) |
| `rename_github_source()` | Fixes GitHub ZIP folder naming via `upgrader_source_selection` filter |
| `get_sudowp_org_repos()` | Fetches all repo names from the Sudo-WP org, cached 12h |
| `get_repo_latest_version()` | Fetches tags, returns highest semver as raw + stripped version |
| `get_sudowp_update_data()` | Matches installed plugins to GitHub versions, cached 12h |
| `render_updates_page()` | Renders the Updates admin table with bulk and per-row actions |
| `clear_all_update_caches()` | Clears all update transients including per-slug tag caches |
| `sanitize_token()` | Sanitizes and stores GitHub PAT; handles clearing via sentinel field |
| `get_inline_js()` | Returns Browse page JavaScript as a string |
| `get_updates_inline_js()` | Returns Updates page JavaScript as a string |

JavaScript is inline, passed via `wp_add_inline_script()` on registered handles. The `SudoWPHub` and `SudoWPHubUpdates` JavaScript objects are populated via `wp_localize_script()` with nonces, the AJAX URL, and i18n strings.

---

## Changelog

### 1.5.12
- Fix: Plugins that were active before an update are now re-activated automatically after the upgrade completes, matching WordPress core behavior. Multisite network-wide activation is preserved.

### 1.5.11
- Cleanup: Removed all diagnostic logging. Update mechanism confirmed working correctly.

### 1.5.9
- Fix: Plugin updates now use `Plugin_Upgrader->upgrade()` by injecting the GitHub package URL into the `update_plugins` transient. This is the same code path WordPress core uses for all plugin updates, ensuring correct filesystem handling, plugin deactivation before file replacement, and temp backup support.

### 1.5.8
- Fix: Replaced manual delete-then-install pattern with `Plugin_Upgrader->run()` using `clear_destination` for correct live server behavior.

### 1.5.7
- Fix: Updates page no longer shows "Update available" immediately after a successful update. Added `wp_clean_plugins_cache()` to `clear_all_update_caches()` so WordPress re-reads plugin headers from disk.

### 1.5.6
- Fix: Update install no longer fails when the GitHub org repos API returns empty. Falls back to scanning installed plugins for sudowp-prefixed folders.

### 1.5.5
- Fix: Rate-limited responses (HTTP 403/429) from GitHub tag API are now cached for 5 minutes, breaking the retry loop that exhausted the unauthenticated rate limit.
- UX: Updates page shows a warning notice with a link to settings when no GitHub token is configured.

### 1.5.4
- Fix: Tag lookup now caches results per slug (12-hour transient), preventing rate-limit exhaustion when multiple SudoWP plugins are installed.
- Fix: API failures no longer cache a false-negative; only genuine "no tags" results are cached.
- Refactor: All cache-clearing paths now use `clear_all_update_caches()` to consistently flush per-slug tag caches alongside the main update data.

### 1.5.0
- Feature: Automatic daily background update check via wp-cron, modeled on WordPress core update behavior.
- Feature: Updates page now shows time of next scheduled automatic check alongside the last checked timestamp.
- Scheduled event is registered on activation, cleared on deactivation and uninstall, and self-heals on admin_init if missing.

### 1.4.1
- Fix: `get_repo_latest_version()` now fetches up to 20 tags and selects the highest by semver rather than trusting GitHub's creation-date ordering.

### 1.4.0
- Feature: Rewritten Updates page with per-row "Update now" links and bulk "Update Selected" functionality.
- Feature: Updates tab on native Plugins list table via `views_plugins` filter, with pending count badge.
- Feature: New `ajax_run_updates()` AJAX handler with nonce, capability, and 30-second rate limiting.
- Feature: Tag-based ZIP URL construction for updates, with new `validate_github_tag_url()` validation method.

### 1.3.0
- Feature: New "Updates" submenu page showing installed SudoWP plugins with their update status compared to GitHub.
- Feature: Automatic version comparison between installed plugin headers and latest GitHub tags.
- Feature: "Check for Updates" button with rate limiting and 12-hour transient cache.
- Feature: Update count badge on the menu item when updates are pending.
- Refactor: Extracted shared GitHub API request args into reusable `get_github_api_args()` method.

### 1.2.1
- Security: Type-aware capability check (install_themes vs install_plugins) on search and install handlers.
- Security: Applied `wp_kses_post` output escaping on rendered card HTML.
- Security: Applied `wp_unslash` on all POST data access.
- Security: Added `uninstall.php` with token and transient cleanup on plugin deletion.

### 1.2.0
- Renamed admin menu item and page title from "SudoWP Store" to "SudoWP Hub".
- Feature: Plugin cards now show installed state. Active plugins show an "Active" badge. Inactive installed plugins show an "Activate" button. Uninstalled plugins show "Install Now".
- Feature: Theme cards use the same installed-state detection with the same three states.
- Refactor: GitHub API results cached as raw item arrays rather than rendered HTML, so installed-state detection always reflects current state at render time.

### 1.1.1
- Removed unused variable in `ajax_search()` identified during code review (cosmetic, no functional impact).

### 1.1.0
- Security: Added `install_plugins` capability check to AJAX search handler.
- Security: Nonces moved to `wp_localize_script()` from inline JS string literals.
- Security: GitHub Authorization header updated from deprecated `token` to `Bearer`.
- Security: Strict field-level validation of all GitHub API response items before rendering.
- Security: ZIP URLs constructed server-side; client-supplied URL re-validated before install.
- Security: `rename_github_source()` returns `WP_Error` on filesystem failure instead of silently passing the original path.
- Security: `validate_github_url()` now rejects URLs with query strings, fragments, or embedded credentials.
- Fix: Token can now be cleared by submitting an empty field (sentinel field logic).
- Fix: Token sanitization restricted to `[a-zA-Z0-9_-]`, max 255 characters.
- Performance: Search results cached as WordPress transients for 5 minutes.
- Performance: Plugin registers its own script handle instead of piggybacking on WP core handles.
- Performance: GitHub API requests up to 100 results per page.
- Performance: Per-user AJAX rate limiting (2-second window) on both endpoints.
- Compliance: `load_plugin_textdomain()` added; all strings wrapped in translation functions.
- Compliance: All output uses context-appropriate escaping.
- UX: Install errors displayed inline; `alert()` removed.
- UX: External links include `rel="noopener noreferrer"`.
- UX: GitHub API HTTP 403, 429, and non-200 responses handled with distinct messages.

### 1.0.0
- Initial release.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

## Related

- [SudoWP.com](https://sudowp.com) - Project home and blog
- [Sudo-WP GitHub Organization](https://github.com/Sudo-WP) - All patched plugin forks
- [WPRepublic.com](https://wprepublic.com) - WordPress security research
