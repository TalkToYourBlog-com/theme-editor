=== TalkToYourBlog Theme Editor ===
Contributors: mjfutera
Tags: abilities api, mcp, ai, theme editor, developer tools
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional companion for TalkToYourBlog: lets an AI app build its own template folders -- PHP, CSS, JS and images -- inside the active theme, never the theme's own files.

== Description ==

TalkToYourBlog connects an AI app (Claude, ChatGPT, Cursor and others) to a WordPress site with no plugin required, using WordPress's own REST API. That stays true with or without this plugin.

What TalkToYourBlog's REST-API approach cannot reach is theme code -- WordPress doesn't expose PHP/CSS/JS files for editing over its core REST API. This plugin adds exactly that, but on purpose does not hand the AI the whole theme: it can only create and work inside folders of its own, each named with the `ai-` prefix (e.g. `ai-landing-page/`). The theme's actual templates, `functions.php` and anything else are never touched. This is eight WordPress Abilities (the `wp-abilities/v1` mechanism built into WordPress core since 6.9 -- the same one plugins like Rank Math or AIOSEO use to expose their own features to AI apps):

* **Create a folder** -- a new `ai-*` folder in the active theme, the only kind this plugin will ever write into.
* **List folders** -- every `ai-*` folder created so far.
* **List files** -- every PHP/CSS/JS/image file inside those folders, with size and last-modified time.
* **Read a file** -- its content plus a hash, so a later edit can detect if the file changed in between (PHP/CSS/JS only).
* **Create or edit a file** -- writes a PHP, CSS or JS file, creating it if it is new or replacing it if it exists. Refused if the new content fails a syntax check, or if an existing file changed since it was last read.
* **Upload an image** -- writes a JPG, PNG, GIF or WebP file from base64 content, up to 5 MB; the actual bytes are checked against the extension. SVG is refused, since it is markup that could carry a script, not a picture.
* **List a file's history** -- every earlier version of one PHP/CSS/JS file.
* **Restore a file to a previous version** -- and the version it replaces is itself saved first, so a restore can always be undone the same way.

Every edit to an existing file snapshots what it replaces before replacing it (the same idea as WordPress's own post revisions, stored the same way) -- there is no separate "draft" or "preview" step. Image uploads are not snapshotted; re-uploading one simply overwrites it.

**Who should install this:** only a site owner who specifically wants an AI app to build template folders inside the theme, and understands that means the AI app can write PHP that runs on the site. Nobody else needs to -- TalkToYourBlog's own content, media, menu and settings tools, and most of what the WordPress Abilities API already exposes through other plugins, all work the same without it.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
2. That's it -- there is no settings screen. The abilities register themselves and become callable by anything with WordPress credentials that has the `edit_themes` capability (by default: administrators).

== Frequently Asked Questions ==

= Does TalkToYourBlog need this plugin? =

No. TalkToYourBlog works fully without it. This only adds theme-file editing, which isn't reachable through WordPress's core REST API at all.

= Can only TalkToYourBlog use these abilities? =

No -- once active, these abilities are ordinary WordPress Abilities API entries, reachable by anything authenticated to the site with the `edit_themes` capability, the same as any other plugin's abilities (Rank Math's, AIOSEO's, etc). This plugin does not check which app is calling.

= What stops it from writing outside its own folders, or to files like wp-config.php? =

Every path is resolved and checked against the active theme's own folder first, and then against the `ai-*` folder it must start inside -- a path with "..", an absolute path, a symlink pointing elsewhere, or a folder name that doesn't start with `ai-` is refused before anything is read or written. Files must also be an allowed type: .php, .css or .js for code, or .jpg/.jpeg/.png/.gif/.webp for images.

= Can the AI edit my theme's actual templates, or functions.php? =

No. Every ability refuses any path outside an `ai-*` folder. The AI can build out its own folders freely, but it cannot touch header.php, functions.php, style.css at the theme root, or anything else that was already there.

= What if the AI writes broken PHP? =

The edit is refused before it touches the file if the new content fails a syntax check (a real `php -l` where the server allows it, otherwise a tokenizer-based check that still catches most mistakes). If something still gets through, WordPress's own fatal-error protection (built into core since 5.2) puts the site into recovery mode rather than staying broken, and the previous version of the file is one `restore-revision` call away.

= Where is the history kept, and does it ever get deleted automatically? =

As a private WordPress post type (`tyb_theme_revision`), the same mechanism core uses for post revisions -- not a separate file store. Nothing is pruned automatically in this version. Deleting the plugin (not just deactivating it) removes all of it.

== External services ==

This plugin does not connect to any external service. Everything it does happens on the WordPress site itself: reading and writing files in the active theme's own folder, and storing revisions in the site's own database.

== Privacy ==

No data about the site or its users is sent anywhere by this plugin. Revisions store the file content, the editing user's ID and a timestamp, in the site's own database, for as long as the plugin stays installed.

== Source code ==

The full, human-readable source of this plugin is public: https://github.com/TalkToYourBlog-com/theme-editor.

== Changelog ==

= 0.5.0 =
* Fixed a bug that made every edit of an existing file fail: the revision history's own post type name was 29 characters, past WordPress's 20-character limit, so every snapshot attempt failed with a database error under strict SQL mode. Shortened to `tyb_theme_revision`.
* Shortened the plugin's internal prefix throughout (folder, main file, function and constant names, text domain, ability ids, CSS classes) from "talktoyourblog" to "tyb" for consistency and to avoid this class of length limit elsewhere. Nothing user-facing changes; the plugin's name and description stay the same.

= 0.4.0 =
* Added a built-in error log (TalkToYourBlog Theme Editor in the admin menu), modelled on Anonymous Sales Plugin's: off by default, logs every ability call's success or failure with its path and, on failure, the error -- never file or image content. Includes a live viewer with level/search filtering, select, copy and clear.

= 0.3.0 =
* Scoped the plugin down to folders it creates itself (named `ai-*`) instead of the whole active theme -- the theme's own templates and functions.php are no longer reachable at all.
* Added create-folder and list-folders abilities, so the AI can start as many separate template folders as it needs.
* Create-or-edit-a-file can now create a brand new file, not only replace an existing one (within an `ai-*` folder).
* Added an upload-image ability (JPG/PNG/GIF/WebP, 5 MB limit, content checked against the claimed extension; SVG is refused).

= 0.2.0 =
* Renamed to TalkToYourBlog Theme Editor (directory slug: tyb-theme-editor), to match the renamed MFPro Blog MCP -> TalkToYourBlog. No files or revisions are affected.
* Added a "Report a bug or idea" link on the Plugins screen, pointing to the new central issue tracker.

= 0.1.0 =
* First version: list/read/edit theme files, with syntax checking and full revision history.
