<?php
defined('ABSPATH') || exit;

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('tyb-theme-editor', [
        'label' => __('AI themes', 'tyb-theme-editor'),
        'description' => __('Create, read, edit, delete and revert files and images inside this plugin\'s own "ai-*" WordPress themes. Every existing theme, including whichever one is active, is off limits.', 'tyb-theme-editor'),
    ]);
});
