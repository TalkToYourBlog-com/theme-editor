<div align="center">
  <img src=".wordpress-org/svg/theme-symbol-colour.svg" width="96" height="96" alt="TalkToYourBlog Theme Editor logo">

  # TalkToYourBlog Theme Editor

  Lets an AI app build its own, independent WordPress themes — never an existing one.

  [![License: GPLv2](https://img.shields.io/badge/license-GPLv2--or--later-1B1640)](https://www.gnu.org/licenses/gpl-2.0.html)
  [![Requires WordPress](https://img.shields.io/badge/WordPress-6.9%2B-6D4AFF)](https://wordpress.org/)
  [![Latest release](https://img.shields.io/github/v/release/TalkToYourBlog-com/theme-editor?label=latest%20release&color=FF5C5C)](https://github.com/TalkToYourBlog-com/theme-editor/releases/latest)

  Part of **[TalkToYourBlog](https://talktoyourblog.com)** — manage your WordPress blog by talking to Claude, ChatGPT, Cursor, Grok and other AI apps.
</div>

---

## What this is

[TalkToYourBlog](https://talktoyourblog.com) connects an AI app to a WordPress site over WordPress's own REST API — no plugin required for that. What the REST API *can't* reach is theme code: PHP, CSS and JS files. This optional companion plugin adds exactly that, through nine [WordPress Abilities](https://developer.wordpress.org/plugins/wordpress-abilities-api/) (the same `wp-abilities/v1` mechanism plugins like Rank Math or AIOSEO use).

On purpose, it never hands the AI an *existing* theme — not even the active one. It can only create brand-new, independent themes of its own, each prefixed `ai-` (e.g. `ai-landing-page`), living as its own folder directly under `wp-content/themes`.

- **create-theme** — a new `ai-*` theme, seeded with a minimal but complete, working classic theme (`style.css`, `functions.php`, `header.php`, `footer.php`, `index.php`, plus a starting `screenshot.png`), so it shows up under *Appearance → Themes* and already renders.
- **list-themes** / **list-files** / **get-file** — see what exists and read it back, with a content hash so a later edit can detect it changed in between.
- **update-file** — create or edit a PHP/CSS/JS file, refused if the new content fails a syntax check.
- **upload-image** — JPG/PNG/GIF/WebP from base64, bytes checked against the claimed extension; SVG is refused since it's markup, not a picture.
- **delete-file** — text files are snapshotted first, so a deletion can be undone.
- **list-revisions** / **restore-revision** — every earlier version of a file, one call away.

**Themes are never activated automatically.** A site admin always does that from *Appearance → Themes*, same as any theme installed by hand.

## Why it's safe to install

Every file path is resolved and checked against the themes directory, then against an `ai-*` folder it must start inside — `..`, an absolute path, a symlink pointing elsewhere, or a folder name that doesn't start with `ai-` is refused before anything is touched. The plugin cannot read, write or delete a single byte of any theme it didn't create itself, including the one currently active.

## Install

Download the [latest release](https://github.com/TalkToYourBlog-com/theme-editor/releases/latest), upload the zip from *Plugins → Add New → Upload Plugin*, and activate it. There's no settings screen — the abilities register themselves and become callable by anything authenticated to the site with the `edit_themes` capability (administrators, by default).

## Full documentation

See [`readme.txt`](readme.txt) for the complete FAQ, privacy notes and changelog (same format WordPress.org plugin pages use).

---

<div align="center">

Made by [TalkToYourBlog](https://talktoyourblog.com) · also see [Plugin Editor](https://github.com/TalkToYourBlog-com/plugin-editor) and [Event Calendar](https://github.com/TalkToYourBlog-com/event-calendar)

</div>
