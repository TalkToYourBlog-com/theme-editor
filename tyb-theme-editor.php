<?php

/*
 * Plugin Name:       TalkToYourBlog Theme Editor
 * Plugin URI:        https://github.com/TalkToYourBlog-com/theme-editor
 * Description:       Optional companion for TalkToYourBlog. Lets an AI app create and edit its own template folders and images inside the active theme through the WordPress Abilities API -- never the theme's own files. Not required for TalkToYourBlog to work.
 * Version:           0.5.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Michal Futera
 * Author URI:        https://michalfutera.pro/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tyb-theme-editor
 */

defined('ABSPATH') || exit;

define('TYB_THEME_EDITOR_VERSION', '0.5.0');
define('TYB_THEME_EDITOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TYB_THEME_EDITOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TYB_THEME_EDITOR_INCLUDES_DIR', TYB_THEME_EDITOR_PLUGIN_DIR . 'includes');

// The debug log lives in the uploads directory, not the plugin folder, which WordPress replaces
// on every update.
$tyb_theme_editor_uploads = wp_upload_dir(null, false);
define('TYB_THEME_EDITOR_VAR_DIR', trailingslashit($tyb_theme_editor_uploads['basedir']) . 'tyb-theme-editor');
unset($tyb_theme_editor_uploads);

// The Abilities API (wp_register_ability, wp_abilities_api_init, ...) ships in WordPress core
// from 6.9 onward. On an older site this plugin simply has nothing to hook into -- fail quietly
// rather than fatal, so activating it on an old install is a no-op, not a broken admin screen.
if (!function_exists('wp_register_ability')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' .
            esc_html__('TalkToYourBlog Theme Editor needs WordPress 6.9 or later (Abilities API). It is installed but inactive until you update WordPress.', 'tyb-theme-editor') .
            '</p></div>';
    });
    return;
}

require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/logging.php';
require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/file-access.php';
require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/validate.php';
require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/revisions.php';
require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/categories.php';
require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/abilities.php';
require_once TYB_THEME_EDITOR_INCLUDES_DIR . '/admin-log.php';

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $links[] = '<a href="https://issues.michalfutera.com/?project=talktoyourblog-theme-editor" target="_blank" rel="noopener">' .
        esc_html__('Report a bug or idea', 'tyb-theme-editor') .
        '</a>';
    return $links;
});
