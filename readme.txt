=== PS Local Avatars ===
Contributors: Van Isle Web Solutions
Author URI: https://www.vanislebc.com/
Requires at least: 5.6
Tested up to: 6.8.2
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local avatars with Gravatar fallback. Subscriber-safe uploads, per-site dimension caps, square crops, settings, shortcode, REST API, and role controls.

== Highlights ==
- Upload from device (all roles) + optional Media Library button (can be disabled per role)
- Square sizes generated automatically: 512×512 and **small** (default 256×256) for faster comment lists
- Per-site **dimension caps** and **file-size limits**
- **Serve small in comments** (toggle) to conserve bandwidth
- REST endpoints to manage avatars from front-end apps
- Shortcode `[psla_avatar]` and server-rendered block `psla/avatar`

== Settings ==
**Settings → PS Local Avatars**
- Default behavior (prefer uploaded vs. Gravatar)
- Max file size (KB)
- Max width/height (px) — oversize uploads are downscaled before saving
- **Small serve in comments** (on/off) + **Small size (px)**
- **Disallow Media Library for roles** — users in these roles won’t see the Media Library picker

== Notes ==
- New uploads generate both 512 and small sizes. Older avatars generate the small size **on demand** the first time it’s needed.
- Admins editing a user whose role is disallowed will also not see the Media Library button for that user (use file upload).

== REST API ==
Namespace `psla/v1`: upload via `POST /avatar` (multipart field `file`), set from media via `POST /avatar/from-media`, remove via `DELETE /avatar`, set source via `POST /avatar/source`. Auth required.

== Changelog ==
= 1.3.1 =
* Small serve size (configurable) with on-demand generation + role-based Media Library restrictions
= 1.2.0 =
* Per-site dimension limits + REST endpoints
= 1.1.0 =
* Subscriber-safe uploads, square crop, settings, shortcode & block
= 1.0.0 =
* Initial release
