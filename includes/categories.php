<?php
defined('ABSPATH') || exit;

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('talktoyourblog-theme-editor', [
        'label' => __('Theme files', 'talktoyourblog-theme-editor'),
        'description' => __("Read, edit and revert the active WordPress theme's own PHP, CSS and JS files.", 'talktoyourblog-theme-editor'),
    ]);
});
