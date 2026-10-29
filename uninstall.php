<?php
// Runs only on actual deletion (not on deactivation) -- removes the revision history this
// plugin kept. It never touches the theme files themselves, those are the site's own content.

defined('WP_UNINSTALL_PLUGIN') || exit;

$revisions = get_posts([
    'post_type' => 'tyb_theme_revision',
    'post_status' => 'private',
    'posts_per_page' => -1,
    'fields' => 'ids',
]);

foreach ($revisions as $revision_id) {
    wp_delete_post($revision_id, true);
}
