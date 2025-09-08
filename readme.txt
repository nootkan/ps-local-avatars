=== PS Local Avatars ===
Author: Van Isle Web Solutions
Author URI: https://www.vanislebc.com/
Requires at least: 5.6
Tested up to: 6.8.2
Stable tag: 1.3.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local avatars with Gravatar fallback. Subscriber-safe uploads, per-site dimension caps, square crops, small serve for comments, role controls, settings, shortcode, REST API, and live preview.
Fully compatible with WordPress and ClassicPress (2.4.1+).

== Highlights ==
- **WordPress & ClassicPress compatible**: Profile Picture + Admin Bar use your local avatar on both platforms.
- Upload from device (all roles) + optional Media Library button (can be disabled per role)
- Square sizes generated automatically: 512×512 and a small size (default 256×256) for faster comment lists
- Per-site max dimensions and file-size limits
- Serve small in comments (toggle) to conserve bandwidth
- Live preview of device uploads before saving; client-side dimension & size checks
- REST endpoints to manage avatars from front-end apps
- Shortcode `[psla_avatar]` and server-rendered block `psla/avatar`
- **Clear admin note** explaining that in WordPress the preview may not dim until you click “Update User” (ClassicPress may dim immediately)

== Compatibility Notes ==
- **ClassicPress**: Ensure **Settings → Discussion → “Show Avatars”** is enabled; ClassicPress may hide avatars everywhere if this is off. The plugin also safeguards admin screens so avatars remain visible while you edit profiles.
- **WordPress**: Some admin screens don’t visually “dim” the preview when switching to Gravatar until you press **Update User**. The profile page now includes a concise “Heads up” note so users know what to expect.
- Works with modern themes/plugins that call `get_avatar_url()` or `pre_get_avatar_data`. (Optional markup fallback is available for very old templates.)

== Installation ==
1. Upload the plugin and activate it.
2. (Optional) Configure size limits and role options in **Settings → Discussion → Avatars** (or the plugin’s settings section).
3. Edit your user profile to upload or choose an image.

== FAQ ==
= My avatar doesn’t show in ClassicPress. =
Turn on **Settings → Discussion → “Show Avatars.”** ClassicPress short-circuits `get_avatar()` if this is off.

= Why doesn’t the preview dim in WordPress when I click “Remove” or switch to Gravatar? =
That’s normal UI behavior on some WP admin pages. Your profile picture updates right after you press **Update User**. ClassicPress may dim the preview immediately.

= Can I force a full HTML replacement for very old themes? =
Yes. Enable a fallback filter on `get_avatar` to replace the `<img>` markup. (This is optional; most installs don’t need it.)

== Changelog ==
= 1.3.7 =
* Compatibility: Ensure ClassicPress profile & Admin Bar use local avatars by also covering `get_avatar_url`.
* Admin safety: In admin/when the Admin Bar shows, keep avatars visible even if the global “Show Avatars” toggle is off (ClassicPress quirk).
* UX: Add a clear “Heads up” note below the picker explaining WP preview behavior; use `psla-note` class for darker, readable text.
* Docs: Add ClassicPress notes and FAQ.

= 1.3.6 =
* Live preview: Remove button now switches to Gravatar instantly and clears file input
* Media Library selection now shows max-dimension prompt

= 1.3.5 =
* Built-in reminder text under the picker; packaging cleanup

= 1.3.4 =
* Client-side max-dimension prompt before preview

= 1.3.3 =
* Live preview for device uploads; script loads for all roles

= 1.3.2 =
* Fix: device uploads (form enctype) + finer KB step

= 1.3.1 =
* Small serve size (configurable) with on-demand generation + role-based Media Library restrictions

= 1.2.0 =
* Per-site dimension limits + REST endpoints

= 1.1.0 =
* Subscriber-safe uploads, square crop, settings, shortcode & block

= 1.0.0 =
* Initial release
