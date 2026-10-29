<?php
defined('ABSPATH') || exit;

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('talktoyourblog-theme-editor', [
        'label' => __('AI template folders', 'talktoyourblog-theme-editor'),
        'description' => __('Create, read, edit and revert files and images inside this plugin\'s own "ai-*" folders in the active WordPress theme. The rest of the theme is off limits.', 'talktoyourblog-theme-editor'),
    ]);
});
