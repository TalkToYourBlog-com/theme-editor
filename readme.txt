=== TalkToYourBlog Theme Editor ===
Contributors: mjfutera
Tags: abilities api, mcp, ai, theme editor, developer tools
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional companion for TalkToYourBlog: lets an AI app read, edit and revert the active theme's own files. Nothing else needs it.

== Description ==

TalkToYourBlog connects an AI app (Claude, ChatGPT, Cursor and others) to a WordPress site with no plugin required, using WordPress's own REST API. That stays true with or without this plugin.

What TalkToYourBlog's REST-API approach cannot reach is the active theme's own PHP, CSS and JS files -- WordPress doesn't expose those for editing over its core REST API. This plugin adds exactly that, and nothing else, as five WordPress Abilities (the `wp-abilities/v1` mechanism built into WordPress core since 6.9 -- the same one plugins like Rank Math or AIOSEO use to expose their own features to AI apps):

* **List theme files** -- every PHP/CSS/JS file in the active theme, with size and last-modified time.
* **Read a theme file** -- its content plus a hash, so a later edit can detect if the file changed in between.
* **Edit a theme file** -- replaces an *existing* file's content (this plugin never creates new files). Refused if the new content fails a syntax check, or if the file changed since it was last read.
* **List a file's history** -- every earlier version of one file.
* **Restore a file to a previous version** -- and the version it replaces is itself saved first, so a restore can always be undone the same way.

Every edit snapshots what it replaces before replacing it (the same idea as WordPress's own post revisions, stored the same way) -- there is no separate "draft" or "preview" step, and no second copy of the theme kept anywhere.

**Who should install this:** only a site owner who specifically wants an AI app to edit theme code directly, and understands that means the AI app can write PHP that runs on the site. Nobody else needs to -- TalkToYourBlog's own content, media, menu and settings tools, and most of what the WordPress Abilities API already exposes through other plugins, all work the same without it.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
2. That's it -- there is no settings screen. The abilities register themselves and become callable by anything with WordPress credentials that has the `edit_themes` capability (by default: administrators).

== Frequently Asked Questions ==

= Does TalkToYourBlog need this plugin? =

No. TalkToYourBlog works fully without it. This only adds theme-file editing, which isn't reachable through WordPress's core REST API at all.

= Can only TalkToYourBlog use these abilities? =

No -- once active, these abilities are ordinary WordPress Abilities API entries, reachable by anything authenticated to the site with the `edit_themes` capability, the same as any other plugin's abilities (Rank Math's, AIOSEO's, etc). This plugin does not check which app is calling.

= What stops it from writing outside the theme, or to files like wp-config.php? =

Every path is resolved and checked against the active theme's own folder before any read or write; anything that would resolve outside it, or that isn't a .php, .css or .js file, is refused. New files cannot be created, only existing ones edited.

= What if the AI writes broken PHP? =

The edit is refused before it touches the file if the new content fails a syntax check (a real `php -l` where the server allows it, otherwise a tokenizer-based check that still catches most mistakes). If something still gets through, WordPress's own fatal-error protection (built into core since 5.2) puts the site into recovery mode rather than staying broken, and the previous version of the file is one `restore-revision` call away.

= Where is the history kept, and does it ever get deleted automatically? =

As a private WordPress post type (`talktoyourblog_theme_revision`), the same mechanism core uses for post revisions -- not a separate file store. Nothing is pruned automatically in this version. Deleting the plugin (not just deactivating it) removes all of it.

== External services ==

This plugin does not connect to any external service. Everything it does happens on the WordPress site itself: reading and writing files in the active theme's own folder, and storing revisions in the site's own database.

== Privacy ==

No data about the site or its users is sent anywhere by this plugin. Revisions store the file content, the editing user's ID and a timestamp, in the site's own database, for as long as the plugin stays installed.

== Source code ==

The full, human-readable source of this plugin is public: https://github.com/TalkToYourBlog-com/theme-editor.

== Changelog ==

= 0.2.0 =
* Renamed to TalkToYourBlog Theme Editor (directory slug: talktoyourblog-theme-editor), to match the renamed MFPro Blog MCP -> TalkToYourBlog. No files or revisions are affected.
* Added a "Report a bug or idea" link on the Plugins screen, pointing to the new central issue tracker.

= 0.1.0 =
* First version: list/read/edit theme files, with syntax checking and full revision history.
