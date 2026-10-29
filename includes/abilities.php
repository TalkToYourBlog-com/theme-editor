<?php
// The abilities themselves. Every execute_callback is a thin wrapper over includes/file-access.php,
// includes/validate.php and includes/revisions.php -- the logic lives there, not here.
//
// Every ability requires the real WordPress capability 'edit_themes', checked server-side in
// permission_callback, independent of whatever scope an MCP connection was given: a connection
// cannot see or run a WordPress capability it doesn't have just because this plugin is active.
//
// None of these abilities can touch the theme itself. Every path must live inside a folder
// whose name starts with TALKTOYOURBLOG_THEME_EDITOR_FOLDER_PREFIX ("ai-" by default), created
// through create-folder -- see includes/file-access.php for the boundary check itself.

defined('ABSPATH') || exit;

function talktoyourblog_theme_editor_can_edit_theme() {
    return current_user_can('edit_themes');
}

add_action('wp_abilities_api_init', function () {
    wp_register_ability('talktoyourblog-theme-editor/create-folder', [
        'label' => __('Create an AI template folder', 'talktoyourblog-theme-editor'),
        'description' => sprintf(
            /* translators: 1: required folder prefix, e.g. "ai-" */
            __('Creates a new folder in the active theme for this plugin to work in (or confirms one with that name already exists). The name must start with "%1$s" (e.g. "%1$slanding-page") -- call this once before writing the first file into a new folder. Everything in the theme outside "%1$s*" folders stays off limits to this plugin.', 'talktoyourblog-theme-editor'),
            TALKTOYOURBLOG_THEME_EDITOR_FOLDER_PREFIX
        ),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'description' => 'Folder name, e.g. "ai-landing-page". Must start with "ai-" and contain only lowercase letters, digits and hyphens.'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'created' => ['type' => 'boolean', 'description' => 'False when the folder already existed.'],
            ],
        ],
        'execute_callback' => function ($input) {
            return talktoyourblog_theme_editor_create_folder($input['name']);
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('talktoyourblog-theme-editor/list-folders', [
        'label' => __('List AI template folders', 'talktoyourblog-theme-editor'),
        'description' => __('Lists the folders this plugin has created in the active theme so far.', 'talktoyourblog-theme-editor'),
        'category' => 'talktoyourblog-theme-editor',
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'folders' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
        'execute_callback' => function () {
            $folders = talktoyourblog_theme_editor_list_folders();
            return is_wp_error($folders) ? $folders : ['folders' => $folders];
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('talktoyourblog-theme-editor/list-files', [
        'label' => __('List files', 'talktoyourblog-theme-editor'),
        'description' => __('Lists every PHP, CSS, JS and image file inside this plugin\'s own "ai-*" folders, with size and last-modified time.', 'talktoyourblog-theme-editor'),
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
        'label' => __('Read a file', 'talktoyourblog-theme-editor'),
        'description' => __('Returns the text content of one PHP, CSS or JS file from one of this plugin\'s own "ai-*" folders, plus its sha256 hash (pass that hash back as expected_hash to update-file to catch a change made elsewhere in between). Images are not readable this way -- use list-files for their URL.', 'talktoyourblog-theme-editor'),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to the theme folder, e.g. "ai-template/style.css".'],
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
        'label' => __('Create or edit a file', 'talktoyourblog-theme-editor'),
        'description' => sprintf(
            /* translators: 1: required folder prefix, e.g. "ai-" */
            __('Writes a PHP, CSS or JS file inside one of this plugin\'s own "%1$s*" folders -- creates it if it does not exist yet, or replaces it if it does (snapshotting the previous content first; see list-revisions / restore-revision). Refuses to save PHP/CSS/JS that fails a syntax check. Call create-folder first if the folder does not exist yet.', 'talktoyourblog-theme-editor'),
            TALKTOYOURBLOG_THEME_EDITOR_FOLDER_PREFIX
        ),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1],
                'content' => ['type' => 'string'],
                'expected_hash' => ['type' => 'string', 'description' => 'sha256 from a prior get-file call. If given and the file no longer matches it, the edit is refused instead of silently overwriting a change made elsewhere. Ignored when the file does not exist yet.'],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'created' => ['type' => 'boolean', 'description' => 'True when this call created a new file rather than editing one that already existed.'],
                'hash' => ['type' => 'string'],
                'revision_id' => ['type' => ['integer', 'null'], 'description' => 'The id of the revision that now holds the content this edit replaced, or null for a newly created file (nothing to snapshot).'],
            ],
        ],
        'execute_callback' => function ($input) {
            $resolved = talktoyourblog_theme_editor_resolve_path($input['path'], false);
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $file_existed = file_exists($resolved);
            $previous_content = '';

            if ($file_existed) {
                $previous_content = file_get_contents($resolved);
                if ($previous_content === false) {
                    return new WP_Error('talktoyourblog_theme_editor_read_failed', __('The file could not be read.', 'talktoyourblog-theme-editor'));
                }
                if (!empty($input['expected_hash']) && hash('sha256', $previous_content) !== $input['expected_hash']) {
                    return new WP_Error('talktoyourblog_theme_editor_conflict', __('The file changed since it was last read; re-read it before editing.', 'talktoyourblog-theme-editor'));
                }
            }

            $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $valid = talktoyourblog_theme_editor_validate_content($extension, $input['content']);
            if (is_wp_error($valid)) {
                return $valid;
            }

            $relative_path = talktoyourblog_theme_editor_relative_path($resolved);
            $revision_id = null;
            if ($file_existed) {
                $revision_id = talktoyourblog_theme_editor_save_revision($relative_path, $previous_content);
                if (is_wp_error($revision_id)) {
                    return $revision_id;
                }
            }

            if (file_put_contents($resolved, $input['content']) === false) {
                return new WP_Error('talktoyourblog_theme_editor_write_failed', __('The file could not be written (check folder permissions).', 'talktoyourblog-theme-editor'));
            }

            return [
                'success' => true,
                'created' => !$file_existed,
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

    wp_register_ability('talktoyourblog-theme-editor/upload-image', [
        'label' => __('Upload an image', 'talktoyourblog-theme-editor'),
        'description' => sprintf(
            /* translators: 1: required folder prefix, e.g. "ai-" */
            __('Writes a JPG, PNG, GIF or WebP image inside one of this plugin\'s own "%1$s*" folders, from base64-encoded content -- creates it if it does not exist yet, or overwrites it if it does. SVG is not accepted (it is markup, not a picture, and could carry a script). The actual bytes are checked against the extension, and there is a 5 MB limit. Call create-folder first if the folder does not exist yet.', 'talktoyourblog-theme-editor'),
            TALKTOYOURBLOG_THEME_EDITOR_FOLDER_PREFIX
        ),
        'category' => 'talktoyourblog-theme-editor',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to the theme folder, e.g. "ai-template/assets/img/hero.jpg".'],
                'content_base64' => ['type' => 'string', 'minLength' => 1, 'description' => 'The image file, base64-encoded.'],
            ],
            'required' => ['path', 'content_base64'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'size_bytes' => ['type' => 'integer'],
            ],
        ],
        'execute_callback' => function ($input) {
            return talktoyourblog_theme_editor_save_image($input['path'], $input['content_base64']);
        },
        'permission_callback' => 'talktoyourblog_theme_editor_can_edit_theme',
        'meta' => [
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            'show_in_rest' => true,
        ],
    ]);

    wp_register_ability('talktoyourblog-theme-editor/list-revisions', [
        'label' => __('List a file\'s history', 'talktoyourblog-theme-editor'),
        'description' => __('Lists previous versions of one PHP, CSS or JS file, newest first, each with the id needed to restore it.', 'talktoyourblog-theme-editor'),
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
        'label' => __('Restore a file to a previous version', 'talktoyourblog-theme-editor'),
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
