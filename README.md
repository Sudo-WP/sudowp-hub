# SudoWP Hub

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue) ![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b) ![License](https://img.shields.io/badge/License-GPL--2.0-green) ![Version](https://img.shields.io/badge/Version-1.2.0-orange)

A WordPress plugin that connects your wp-admin to the [Sudo-WP GitHub organization](https://github.com/Sudo-WP), allowing you to search and install security-patched plugin forks directly from the dashboard, without downloading or renaming ZIP files manually.

---

## The Problem

Installing a plugin from GitHub has always had a friction problem. GitHub archive ZIPs extract to a folder named `repo-branch` rather than `repo`. WordPress expects the folder to match the plugin slug exactly. Without a manual rename, the plugin either fails to activate or activates under the wrong path.

For an agency maintaining dozens of WordPress sites, this is a recurring manual step with no security benefit and consistent room for human error.

SudoWP Hub removes it entirely.

---

## What It Does

- Presents all Sudo-WP organization repositories as native-looking plugin cards inside wp-admin, using the same CSS layout as the WordPress Add New plugin screen
- Handles installation via `WP_Upgrader`, the same internal class WordPress uses for all plugin installs
- Intercepts the `upgrader_source_selection` filter to rename the extracted folder from `repo-branch` to the correct slug, server-side, before WordPress finalizes the install
- Caches GitHub API search results for 5 minutes per unique query to reduce API usage
- Supports toggling between plugins and themes

---

## Security Design

SudoWP Hub connects to an external API and performs plugin installations. The following security decisions apply:

**SSRF prevention.** All ZIP download URLs are constructed server-side from validated parts: fixed scheme (`https`), fixed host (`github.com`), fixed organization prefix (`/Sudo-WP/`), a validated slug, and a validated branch segment. The `validate_github_url()` method rejects any URL containing query strings, fragments, or embedded credentials. Client-supplied URLs are re-validated before `WP_Upgrader` is invoked.

**Capability gating.** Both the search AJAX handler (`ajax_search()`) and the install AJAX handler (`ajax_install()`) require the `install_plugins` capability. The admin menu page itself requires `install_plugins` to render. Unauthenticated and low-privilege users cannot reach any of the plugin's functionality.

**Nonce handling.** Nonces are passed to JavaScript exclusively via `wp_localize_script()` on a plugin-owned script handle (`sudowp-hub-admin`). They are never embedded as string literals in inline JavaScript.

**Per-user rate limiting.** A 2-second transient window per user ID is enforced on both AJAX endpoints. This prevents rapid repeated requests that could exhaust the GitHub API rate limit from the server's IP.

**Token sanitization.** GitHub Personal Access Tokens are sanitized to `[a-zA-Z0-9_-]` only, trimmed to 255 characters. The `Authorization` header uses the `Bearer` prefix per current GitHub API documentation. A sentinel field distinguishes deliberate token clearing from an empty form submission.

**Slug validation.** The `is_valid_slug()` helper restricts slugs to `[a-zA-Z0-9_-]` before any slug value is used in a regex or filesystem path. This runs before `validate_github_url()` to prevent ReDoS.

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
4. A "SudoWP Hub" item will appear in the admin menu.

**Optional:** Add a GitHub Personal Access Token (`public_repo` scope) in the Configuration section at the bottom of the Store page. Without a token, the GitHub API allows approximately 10 search requests per minute from the server's IP. With a token, the limit rises to 5,000 requests per hour.

---

## GitHub Token

To add a token:

1. Go to GitHub - Settings - Developer settings - Personal access tokens - Fine-grained tokens (or classic tokens with `public_repo` scope).
2. Generate a token.
3. Paste it into the Configuration section on the SudoWP Store page and click Save Settings.

To clear a saved token: leave the field empty and click Save Settings. The plugin uses a sentinel field to distinguish deliberate clearing from an accidental empty submission.

---

## Limitations

- **No update management.** SudoWP Hub installs plugins but does not manage updates. Once a fork is installed, WordPress's native update mechanism applies.
- **No file integrity verification.** Hub trusts the GitHub API over HTTPS and relies on the same `WP_Upgrader` pipeline WordPress uses for all installs.
- **Pagination not yet implemented.** The GitHub API caps results at 100 per query. If the Sudo-WP organization grows beyond 100 repositories, results will be silently truncated. A result count indicator and pagination are planned for a future release.
- **Activation URL.** After install, the Activate link points to `plugins.php` rather than a direct activation URL. This is a known UX gap; direct activation URL construction is planned.

---

## Architecture

Single-file plugin. All logic lives in `sudowp-hub.php` inside the `SudoWP_Hub` class using a singleton pattern. No external dependencies. No build step. No Composer.

Key methods:

| Method | Purpose |
|---|---|
| `init()` | Registers all hooks |
| `ajax_search()` | Handles GitHub API search, caches results as transients |
| `ajax_install()` | Handles plugin/theme installation via `WP_Upgrader` |
| `build_results_html()` | Renders plugin card HTML from GitHub API items |
| `validate_github_url()` | SSRF prevention: strict URL validation before any download |
| `is_valid_slug()` | Validates slug characters before use in regex or paths |
| `rename_github_source()` | Fixes GitHub ZIP folder naming via `upgrader_source_selection` filter |
| `sanitize_token()` | Sanitizes and stores GitHub PAT; handles deliberate clearing via sentinel field |
| `get_inline_js()` | Returns admin JavaScript as a string for `wp_add_inline_script()` |

JavaScript is inline, passed via `wp_add_inline_script()` on the registered `sudowp-hub-admin` handle. The `SudoWPHub` JavaScript object is populated via `wp_localize_script()` with nonces, the AJAX URL, and i18n strings.

---

## Changelog

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
