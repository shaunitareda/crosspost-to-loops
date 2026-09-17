# Crosspost to Loops

**Plugin Name:** Crosspost to Loops  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Stable Tag:** 1.0.3
**Tested up to:** 6.9  
**Requires at least:** 6.0  
**Requires PHP:** 8.0  

Crossposts video posts from WordPress to Loops.video with one-click OAuth connect.

---

## Description

Crosspost to Loops connects your WordPress site to Loops.video with a simple one-click OAuth flow. When you publish a post containing a video, the plugin automatically uploads it to your Loops.video account — no manual token wrangling required.

### Features

- **One-click OAuth connect** — authorise the plugin directly from the settings page. No API tools needed.
- **Auto-crosspost on publish** — video posts are uploaded to Loops.video automatically when published.
- **Manual crosspost button** — trigger or re-trigger a crosspost from the post editor sidebar.
- **Flexible video detection** — finds videos via WordPress media attachments, `wp:video` blocks in post content, or any custom/ACF meta field.
- **Connected account card** — see your Loops.video profile, video count, and follower count right in the settings page.
- **Test Crosspost tab** — verify your setup end-to-end before going live.
- **Debug Log tab** — timestamped log of all crosspost attempts with colour-coded status.
- **Disconnect button** — revoke access and reconnect at any time.

---

## Installation

1. Download and unzip the plugin.
2. Upload the `crosspost-to-loops` folder to `wp-content/plugins/`.
3. Activate the plugin via **Plugins → Installed Plugins**.
4. Go to **Settings → Crosspost to Loops**.
5. Click **Connect to Loops.video** and follow the prompts.

---

## Video Source Options

| Source | How it works |
|--------|-------------|
| Attached video file | Finds the first `video/*` media attachment linked to the post in the WordPress Media Library. |
| Post content (video block) | Parses the post body for a `wp:video` block and extracts its URL. |
| Custom field | Reads a video URL from the post meta key you specify (compatible with ACF, Meta Box, etc.). |

---

## Frequently Asked Questions

**Does this work with self-hosted Loops instances?**  
Yes. Enter your instance URL in the Instance URL setting before connecting.

**Is the access token stored securely?**  
The token is stored in the WordPress options table, the same place WordPress stores all plugin settings. Access is restricted to administrators.

**Can I crosspost to multiple Loops accounts?**  
Not currently — the plugin supports one connected account at a time.

---

## Changelog

### 1.0.3
- Fixed blank Test Crosspost selector rows by showing concise content snippets for titleless posts, with an untitled post-type fallback when no useful text exists.

### 1.0.2
- Updated Loops OAuth app registration, authorization, and token exchange to use the required `user:read video:create` scope.
- Added the site URL during OAuth client registration.
- Moved the primary OAuth callback to the stable WordPress `admin-post.php?action=ctl_oauth_callback` route while retaining legacy callback compatibility.
- Clarified that OAuth is the normal connection path while manual access-token entry remains available.

### 1.6.0
- Fixed all WordPress Plugin Check errors and warnings
- Replaced `wp_redirect()` with `wp_safe_redirect()` throughout OAuth flow
- Added `wp_unslash()` to all `$_GET`/`$_POST` reads
- Replaced `unlink()` with `wp_delete_file()`
- Replaced `rename()` with `WP_Filesystem_Direct::move()`
- Added `translators:` comments to all `sprintf( __(...) )` calls
- Escaped all output via `esc_html()`, `esc_attr()`, `wp_kses()`
- Removed discouraged `load_plugin_textdomain()` call

### 1.5.0
- Added Test Crosspost tab

### 1.4.0
- Added one-click OAuth connect/disconnect flow

### 1.3.0
- Split settings and debug log into separate tabs
- Added Settings and Debug Log links on the Plugins page

### 1.2.0
- Added connected account card showing avatar, username, video and follower counts

### 1.1.0
- Added eye toggle on access token field
- Added debug log

### 1.0.0
- Initial release

---

## License

This plugin is licensed under the GPLv2 or later. See https://www.gnu.org/licenses/gpl-2.0.html
