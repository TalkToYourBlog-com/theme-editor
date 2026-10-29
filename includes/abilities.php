<?php
// The abilities themselves. Every execute_callback is a thin wrapper over includes/file-access.php,
// includes/validate.php and includes/revisions.php -- the logic lives there, not here.
//
// Every ability requires the real WordPress capability 'edit_themes', checked server-side in
// permission_callback, independent of whatever scope an MCP connection was given: a connection
// cannot see or run a WordPress capability it doesn't have just because this plugin is active.

defined('ABSPATH') || exit;

function talktoyourblog_theme_editor_can_edit_theme() {
    return current_user_can('edit_themes');
}

add_action('wp_abilities_api_init', function () {
    wp_register_ability('talktoyourblog-theme-editor/list-files', [
        'label' => __('List theme files', 'talktoyourblog-theme-editor'),
        'description' => __('Lists the PHP, CSS and JS files in the active theme (or active child theme), with their size and last-modified time.', 'talktoyourblog-theme-editor'),
        'category' => 'talktoyourblog-theme-editor',
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'size_bytes' => ['type' => 'integer'],
                            'modified_at' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
        'execute_callback' => function () {
            $files = talktoyourblog_theme_editor_list_files();
            return is_wp_error($files) ? $files : ['files' => $files];
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('talktoyourblog-theme-editor/get-file', [
        'label' => __('Read a theme file', 'talktoyourblog-theme-editor'),
        'description' => __('Returns the content of one file from the active theme, plus its sha256 hash (pass that hash back as expected_hash to update-file to catch a change made elsewhere in between).', 'talktoyourblog-theme-editor'),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to the theme folder, e.g. "style.css" or "template-parts/hero.php".'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'hash' => ['type' => 'string'],
            ],
        ],
        'execute_callback' => function ($input) {
            $resolved = talktoyourblog_theme_editor_resolve_path($input['path'], true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $content = file_get_contents($resolved);
            if ($content === false) {
                return new WP_Error('talktoyourblog_theme_editor_read_failed', __('The file could not be read.', 'talktoyourblog-theme-editor'));
            }
            return [
                'path' => talktoyourblog_theme_editor_relative_path($resolved),
                'content' => $content,
                'hash' => hash('sha256', $content),
            ];
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('talktoyourblog-theme-editor/update-file', [
        'label' => __('Edit a theme file', 'talktoyourblog-theme-editor'),
        'description' => __('Replaces the content of one existing file in the active theme. Refuses to save PHP/CSS/JS that fails a syntax check, and always snapshots the content it is about to replace first (see list-revisions / restore-revision) -- this never creates a new file, only edits one that is already there.', 'talktoyourblog-theme-editor'),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1],
                'content' => ['type' => 'string'],
                'expected_hash' => ['type' => 'string', 'description' => 'sha256 from a prior get-file call. If given and the file no longer matches it, the edit is refused instead of silently overwriting a change made elsewhere.'],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'hash' => ['type' => 'string'],
                'revision_id' => ['type' => 'integer', 'description' => 'The id of the revision that now holds the content this edit replaced.'],
            ],
        ],
        'execute_callback' => function ($input) {
            $resolved = talktoyourblog_theme_editor_resolve_path($input['path'], true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $previous_content = file_get_contents($resolved);
            if ($previous_content === false) {
                return new WP_Error('talktoyourblog_theme_editor_read_failed', __('The file could not be read.', 'talktoyourblog-theme-editor'));
            }

            if (!empty($input['expected_hash']) && hash('sha256', $previous_content) !== $input['expected_hash']) {
                return new WP_Error('talktoyourblog_theme_editor_conflict', __('The file changed since it was last read; re-read it before editing.', 'talktoyourblog-theme-editor'));
            }

            $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $valid = talktoyourblog_theme_editor_validate_content($extension, $input['content']);
            if (is_wp_error($valid)) {
                return $valid;
            }

            $relative_path = talktoyourblog_theme_editor_relative_path($resolved);
            $revision_id = talktoyourblog_theme_editor_save_revision($relative_path, $previous_content);
            if (is_wp_error($revision_id)) {
                return $revision_id;
            }

            if (file_put_contents($resolved, $input['content']) === false) {
                return new WP_Error('talktoyourblog_theme_editor_write_failed', __('The file could not be written (check folder permissions).', 'talktoyourblog-theme-editor'));
            }

            return [
                'success' => true,
                'hash' => hash('sha256', $input['content']),
                'revision_id' => $revision_id,
            ];
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('talktoyourblog-theme-editor/list-revisions', [
        'label' => __('List a theme file\'s history', 'talktoyourblog-theme-editor'),
        'description' => __('Lists previous versions of one theme file, newest first, each with the id needed to restore it.', 'talktoyourblog-theme-editor'),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'revisions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'created_at' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                            'hash' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
        'execute_callback' => function ($input) {
            $resolved = talktoyourblog_theme_editor_resolve_path($input['path'], true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            return ['revisions' => talktoyourblog_theme_editor_list_revisions(talktoyourblog_theme_editor_relative_path($resolved))];
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('talktoyourblog-theme-editor/restore-revision', [
        'label' => __('Restore a theme file to a previous version', 'talktoyourblog-theme-editor'),
        'description' => __("Writes a previous revision's content back to its file. The file's current content is itself snapshotted first, so a restore can always be undone the same way.", 'talktoyourblog-theme-editor'),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'revision_id' => ['type' => 'integer', 'description' => 'An id returned by list-revisions or by a prior update-file call.'],
            ],
            'required' => ['revision_id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'path' => ['type' => 'string'],
                'hash' => ['type' => 'string'],
                'backup_revision_id' => ['type' => 'integer', 'description' => 'The id of the new revision created from the content this restore just replaced.'],
            ],
        ],
        'execute_callback' => function ($input) {
            $revision = talktoyourblog_theme_editor_get_revision($input['revision_id']);
            if (is_wp_error($revision)) {
                return $revision;
            }

            $resolved = talktoyourblog_theme_editor_resolve_path($revision['path'], true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $valid = talktoyourblog_theme_editor_validate_content($extension, $revision['content']);
            if (is_wp_error($valid)) {
                return $valid;
            }

            $relative_path = talktoyourblog_theme_editor_relative_path($resolved);
            $current_content = file_get_contents($resolved);
            if ($current_content === false) {
                return new WP_Error('talktoyourblog_theme_editor_read_failed', __('The file could not be read.', 'talktoyourblog-theme-editor'));
            }

            $backup_revision_id = talktoyourblog_theme_editor_save_revision($relative_path, $current_content);
            if (is_wp_error($backup_revision_id)) {
                return $backup_revision_id;
            }

            if (file_put_contents($resolved, $revision['content']) === false) {
                return new WP_Error('talktoyourblog_theme_editor_write_failed', __('The file could not be written (check folder permissions).', 'talktoyourblog-theme-editor'));
            }

            return [
                'success' => true,
                'path' => $relative_path,
                'hash' => hash('sha256', $revision['content']),
                'backup_revision_id' => $backup_revision_id,
            ];
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);
});
