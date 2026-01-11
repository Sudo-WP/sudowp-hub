=== SudoWP Hub ===
Contributors: sudowp
Tags: github, installer, security, patch, maintenance, abandoned-plugins
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
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

**Key Features:**

* Live Search connected to `org:Sudo-WP`.
* AJAX-powered "Install Now" & "Activate" workflow.
* **GitHub Token Support:** Avoid API rate limits (403 errors) by adding your Personal Access Token in the settings.

== Installation ==

1. Upload the `sudowp-hub` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. A new menu item **"SudoWP Store"** will appear in your admin dashboard.
4. (Optional) If you perform many searches, go to the settings section at the bottom of the store page and add a GitHub Personal Access Token to increase your API rate limits.

== Frequently Asked Questions ==

= Why do I see a "Rate Limit Exceeded" error? =
The GitHub public API allows for a limited number of requests per hour (approx. 60). If you are searching frequently, you may hit this limit. You can generate a free Personal Access Token on GitHub (with `public_repo` scope) and paste it into the plugin settings to bypass this limit.

= Is this safe? =
Yes. SudoWP Hub only searches and installs software from the **Sudo-WP** organization on GitHub, which contains code audited and patched by our team.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added support for Plugins and Themes search.
* Implemented Smart Folder Renaming for GitHub zipballs.
* Added GitHub Token support for API rate limits.
