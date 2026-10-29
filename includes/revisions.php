<?php
// History for theme files, kept the same way WordPress already keeps post revisions: as its
// own post type, not a bespoke table. Every write to a theme file snapshots the content it is
// about to replace *before* replacing it, so nothing written through this plugin is ever lost
// without an explicit, separate "restore" call -- including a restore itself, which snapshots
// the file's current content first too.

defined('ABSPATH') || exit;

const TALKTOYOURBLOG_THEME_REVISION_POST_TYPE = 'talktoyourblog_theme_revision';

add_action('init', 'talktoyourblog_theme_editor_register_revision_post_type');

function talktoyourblog_theme_editor_register_revision_post_type() {
    register_post_type(TALKTOYOURBLOG_THEME_REVISION_POST_TYPE, [
        'public' => false,
        'show_ui' => false,
        'show_in_menu' => false,
        'show_in_rest' => false,
        'rewrite' => false,
        'query_var' => false,
        'can_export' => false,
        'delete_with_user' => false,
        'supports' => ['title', 'editor', 'author'],
        'capability_type' => 'talktoyourblog_theme_revision',
        'map_meta_cap' => false,
        // No role is ever granted these capabilities, so nothing outside this plugin's own
        // direct wp_insert_post()/get_posts() calls (which bypass the capability check, same
        // as core's own revision storage does) can create, list or delete them -- including
        // through the block editor or the REST API, since this type isn't shown in either.
    ]);
}

/** @return int|WP_Error The new revision's post ID. */
function talktoyourblog_theme_editor_save_revision($relative_path, $content) {
    $post_id = wp_insert_post([
        'post_type' => TALKTOYOURBLOG_THEME_REVISION_POST_TYPE,
        'post_status' => 'private',
        'post_title' => $relative_path,
        'post_content' => $content,
        'post_author' => get_current_user_id(),
    ], true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, '_talktoyourblog_path', $relative_path);
    update_post_meta($post_id, '_talktoyourblog_hash', hash('sha256', $content));

    return $post_id;
}

/** @return array<int, array{id:int, created_at:string, author:string, hash:string}> */
function talktoyourblog_theme_editor_list_revisions($relative_path, $limit = 50) {
    $posts = get_posts([
        'post_type' => TALKTOYOURBLOG_THEME_REVISION_POST_TYPE,
        'post_status' => 'private',
        'posts_per_page' => max(1, min(200, (int) $limit)),
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_key' => '_talktoyourblog_path',
        'meta_value' => $relative_path,
        'no_found_rows' => true,
    ]);

    return array_map(function ($post) {
        $author = get_userdata($post->post_author);
        return [
            'id' => $post->ID,
            'created_at' => mysql2date('c', $post->post_date_gmt, false),
            'author' => $author ? $author->user_login : __('(deleted user)', 'talktoyourblog-theme-editor'),
            'hash' => get_post_meta($post->ID, '_talktoyourblog_hash', true),
        ];
    }, $posts);
}

/** @return array{path:string, content:string}|WP_Error */
function talktoyourblog_theme_editor_get_revision($revision_id) {
    $post = get_post((int) $revision_id);
    if (!$post || $post->post_type !== TALKTOYOURBLOG_THEME_REVISION_POST_TYPE) {
        return new WP_Error('talktoyourblog_theme_editor_revision_not_found', __('That revision does not exist.', 'talktoyourblog-theme-editor'));
    }

    return [
        'path' => get_post_meta($post->ID, '_talktoyourblog_path', true),
        'content' => $post->post_content,
    ];
}
