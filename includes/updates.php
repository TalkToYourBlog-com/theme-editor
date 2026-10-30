<?php

defined('ABSPATH') || exit;

// Not in the WordPress.org directory, so WordPress never checks for updates on its own.
// This points it at the plugin's GitHub Releases instead, using the built zip attached to
// each release (not the raw repo, which still has .git/.wordpress-org/dist in it).
require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/plugin-update-checker/plugin-update-checker.php';

$tyb_theme_editor_update_checker = YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
    'https://github.com/TalkToYourBlog-com/theme-editor/',
    TYB_THEME_EDITOR_PLUGIN_DIR . 'tyb-theme-editor.php',
    'tyb-theme-editor'
);

$tyb_theme_editor_update_checker->getVcsApi()->enableReleaseAssets();
