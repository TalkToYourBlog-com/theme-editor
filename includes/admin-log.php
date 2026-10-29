<?php
// A single admin screen: the debug-logging switch and a live viewer for the file it writes to.
// This plugin has no other admin UI -- everything else happens through the Abilities API.
// Modelled on anonymousSalesPlugin's own Error log screen.

defined('ABSPATH') || exit;

function tyb_theme_editor_register_admin_menu() {
    add_menu_page(
        __('TalkToYourBlog Theme Editor', 'tyb-theme-editor'),
        __('Theme Editor', 'tyb-theme-editor'),
        'edit_themes',
        'tyb-theme-editor',
        'tyb_theme_editor_render_log_page',
        'dashicons-media-code',
        81
    );
}

add_action('admin_menu', 'tyb_theme_editor_register_admin_menu');

function tyb_theme_editor_admin_enqueue_assets($hook_suffix) {
    if (strpos((string) $hook_suffix, 'tyb-theme-editor') === false) {
        return;
    }

    wp_enqueue_script(
        'tyb-theme-editor-admin-log',
        TYB_THEME_EDITOR_PLUGIN_URL . 'assets/admin/admin-log.js',
        [],
        TYB_THEME_EDITOR_VERSION,
        true
    );
    wp_localize_script('tyb-theme-editor-admin-log', 'TybThemeEditorAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'errorLogNonce' => wp_create_nonce('tyb_theme_editor_error_log_refresh'),
    ]);

    wp_add_inline_style('common', '
        .tyb-admin-wrap { max-width: none; width: 100%; margin-right: 0; padding-right: 0; box-sizing: border-box; }
        .tyb-admin-wrap .card { max-width: none !important; }
        .tyb-admin-card { padding: 16px; margin: 16px 0 0; width: 100%; box-sizing: border-box; }
        .tyb-admin-full { width: 100%; max-width: none; }
        .tyb-admin-wrap .widefat { width: 100%; table-layout: auto; }
    ');
}

add_action('admin_enqueue_scripts', 'tyb_theme_editor_admin_enqueue_assets');

function tyb_theme_editor_read_file_tail($file_path, $max_bytes = 131072) {
    $file_path = (string) $file_path;
    $max_bytes = max(1024, (int) $max_bytes);

    if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
        return '';
    }

    $size = filesize($file_path);
    if (!is_int($size) || $size <= 0) {
        return '';
    }

    $offset = max(0, $size - $max_bytes);
    $content = file_get_contents($file_path, false, null, $offset, $max_bytes);
    if (!is_string($content)) {
        return '';
    }

    if ($offset > 0) {
        $first_newline = strpos($content, "\n");
        if ($first_newline !== false) {
            $content = substr($content, $first_newline + 1);
        }
    }

    return trim($content);
}

function tyb_theme_editor_get_error_log_preview($max_bytes = 131072) {
    $log_file = tyb_theme_editor_get_error_log_file_path();
    if (!file_exists($log_file)) {
        return [
            'content' => __('Log file does not exist yet. Turn on debug logging and trigger an action to create the first entries.', 'tyb-theme-editor'),
            'exists' => false,
        ];
    }

    $content = tyb_theme_editor_read_file_tail($log_file, $max_bytes);
    if ($content === '') {
        $content = __('Log file is empty.', 'tyb-theme-editor');
    }

    return [
        'content' => $content,
        'exists' => true,
    ];
}

function tyb_theme_editor_get_log_level_badge_style($level) {
    switch (strtoupper(trim((string) $level))) {
        case 'ERROR':
            return 'background:#fbeaea;color:#8b0000;border:1px solid #f1b5b5;';
        case 'WARNING':
            return 'background:#fff7e6;color:#8a5800;border:1px solid #f0d195;';
        case 'INFO':
            return 'background:#eaf4ff;color:#0b4f8a;border:1px solid #b8d8f5;';
        case 'DEBUG':
            return 'background:#f2f2f4;color:#4d4d57;border:1px solid #d4d4dc;';
        default:
            return 'background:#f7f7f7;color:#555;border:1px solid #dcdcde;';
    }
}

function tyb_theme_editor_parse_log_entries($content, $reverse = true) {
    $raw_lines = preg_split('/\r\n|\r|\n/', (string) $content);
    $entries = [];

    foreach ((array) $raw_lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $level = 'UNKNOWN';
        if (preg_match('/\[(ERROR|WARNING|INFO|DEBUG)\]/i', $line, $matches)) {
            $level = strtoupper((string) $matches[1]);
        }

        $entries[] = ['level' => $level, 'line' => $line];
    }

    if ($reverse && !empty($entries)) {
        $entries = array_reverse($entries);
    }

    return $entries;
}

function tyb_theme_editor_handle_refresh_error_log_ajax() {
    if (!current_user_can('edit_themes')) {
        wp_send_json_error(['message' => __('Insufficient permissions.', 'tyb-theme-editor')], 403);
    }

    check_ajax_referer('tyb_theme_editor_error_log_refresh', 'nonce');

    $preview = tyb_theme_editor_get_error_log_preview();
    $entries = tyb_theme_editor_parse_log_entries((string) ($preview['content'] ?? ''), true);

    wp_send_json_success([
        'entries' => $entries,
        'exists' => !empty($preview['exists']),
        'fetched_at' => current_time('mysql', true),
    ]);
}

add_action('wp_ajax_tyb_theme_editor_refresh_error_log', 'tyb_theme_editor_handle_refresh_error_log_ajax');

function tyb_theme_editor_render_log_page() {
    if (!current_user_can('edit_themes')) {
        return;
    }

    $notice = null;

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tyb_theme_editor_log_action'])) {
        check_admin_referer('tyb_theme_editor_log_action');

        $action = sanitize_key(wp_unslash((string) $_POST['tyb_theme_editor_log_action']));

        if ($action === 'save_settings') {
            update_option('tyb_theme_editor_debug_logging', isset($_POST['debug_logging']) ? '1' : '0');
            $notice = ['type' => 'success', 'message' => __('Settings saved.', 'tyb-theme-editor')];
        } elseif ($action === 'clear') {
            $log_file = tyb_theme_editor_get_error_log_file_path();
            $ok = @file_put_contents($log_file, '');
            $notice = $ok === false
                ? ['type' => 'error', 'message' => __('Could not clear the log file.', 'tyb-theme-editor')]
                : ['type' => 'success', 'message' => __('Log file was cleared.', 'tyb-theme-editor')];
        } elseif ($action === 'write_test') {
            if (!tyb_theme_editor_logging_enabled()) {
                $notice = ['type' => 'error', 'message' => __('Debug logging is off. Switch it on above first.', 'tyb-theme-editor')];
            } else {
                tyb_theme_editor_log_info('Manual test log entry from the admin Error log page.', [
                    'source' => 'admin_log_page',
                    'user_id' => get_current_user_id(),
                ]);
                $notice = ['type' => 'success', 'message' => __('Test log entry written. Use Refresh to load it.', 'tyb-theme-editor')];
            }
        }
    }

    $log_file = tyb_theme_editor_get_error_log_file_path();
    $log_exists = file_exists($log_file);
    $log_readable = $log_exists && is_readable($log_file);
    $log_writable = wp_is_writable(dirname($log_file));
    $log_size = $log_exists ? (int) @filesize($log_file) : 0;
    $log_mtime = $log_exists ? (int) @filemtime($log_file) : 0;

    $preview = tyb_theme_editor_get_error_log_preview();
    $entries = tyb_theme_editor_parse_log_entries((string) ($preview['content'] ?? ''), true);

    ?>
    <div class="wrap tyb-admin-wrap">
        <h1><?php esc_html_e('TalkToYourBlog Theme Editor', 'tyb-theme-editor'); ?></h1>
        <p><?php esc_html_e('This plugin has no other settings -- it only creates and edits files inside its own "ai-*" folders in the active theme, through the WordPress Abilities API.', 'tyb-theme-editor'); ?></p>

        <?php if ($notice): ?>
            <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
        <?php endif; ?>

        <div class="card tyb-admin-card tyb-admin-full">
            <h2><?php esc_html_e('Error log', 'tyb-theme-editor'); ?></h2>

            <form method="post">
                <?php wp_nonce_field('tyb_theme_editor_log_action'); ?>
                <input type="hidden" name="tyb_theme_editor_log_action" value="save_settings" />
                <label><input type="checkbox" name="debug_logging" value="1" <?php checked(tyb_theme_editor_logging_enabled()); ?> /> <?php esc_html_e('Write a debug log of every ability call (success or failure).', 'tyb-theme-editor'); ?></label>
                <?php submit_button(__('Save', 'tyb-theme-editor'), 'secondary', '', false); ?>
                <p class="description"><?php esc_html_e('Off by default. File contents and image bytes are never logged, only the ability name, the path and, on failure, the error.', 'tyb-theme-editor'); ?></p>
            </form>

            <div style="margin:14px 0;padding:10px 12px;border:1px solid #dcdcde;border-radius:8px;background:#f8f9fa;">
                <strong><?php esc_html_e('Log diagnostics', 'tyb-theme-editor'); ?></strong>
                <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:6px;font-size:12px;">
                    <span><strong><?php esc_html_e('Path:', 'tyb-theme-editor'); ?></strong> <code><?php echo esc_html($log_file); ?></code></span>
                    <span><strong><?php esc_html_e('Exists:', 'tyb-theme-editor'); ?></strong> <?php echo $log_exists ? esc_html__('yes', 'tyb-theme-editor') : esc_html__('no', 'tyb-theme-editor'); ?></span>
                    <span><strong><?php esc_html_e('Readable:', 'tyb-theme-editor'); ?></strong> <?php echo $log_readable ? esc_html__('yes', 'tyb-theme-editor') : esc_html__('no', 'tyb-theme-editor'); ?></span>
                    <span><strong><?php esc_html_e('Writable dir:', 'tyb-theme-editor'); ?></strong> <?php echo $log_writable ? esc_html__('yes', 'tyb-theme-editor') : esc_html__('no', 'tyb-theme-editor'); ?></span>
                    <span><strong><?php esc_html_e('Size:', 'tyb-theme-editor'); ?></strong> <?php echo esc_html((string) $log_size); ?> bytes</span>
                    <span><strong><?php esc_html_e('Updated:', 'tyb-theme-editor'); ?></strong> <?php echo $log_mtime > 0 ? esc_html(gmdate('Y-m-d H:i:s', $log_mtime) . ' UTC') : '-'; ?></span>
                </div>
                <form method="post" style="margin-top:8px;display:flex;gap:8px;align-items:center;">
                    <?php wp_nonce_field('tyb_theme_editor_log_action'); ?>
                    <input type="hidden" name="tyb_theme_editor_log_action" value="write_test" />
                    <?php submit_button(__('Write test log entry', 'tyb-theme-editor'), 'secondary', '', false); ?>
                    <span class="description"><?php esc_html_e('Use this if the table below is empty, to verify the write/read pipeline.', 'tyb-theme-editor'); ?></span>
                </form>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px;">
                <label for="tyb_theme_editor_log_level_filter"><strong><?php esc_html_e('Level:', 'tyb-theme-editor'); ?></strong></label>
                <select id="tyb_theme_editor_log_level_filter">
                    <?php foreach (['ALL', 'ERROR', 'WARNING', 'INFO', 'DEBUG'] as $level): ?>
                        <option value="<?php echo esc_attr($level); ?>"><?php echo esc_html($level); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="tyb_theme_editor_log_search"><strong><?php esc_html_e('Search:', 'tyb-theme-editor'); ?></strong></label>
                <input type="text" id="tyb_theme_editor_log_search" class="regular-text" placeholder="<?php esc_attr_e('ability, path, error...', 'tyb-theme-editor'); ?>" />
                <button type="button" class="button button-small" id="tyb_theme_editor_log_select_visible"><?php esc_html_e('Select visible', 'tyb-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="tyb_theme_editor_log_clear_selection"><?php esc_html_e('Clear selection', 'tyb-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="tyb_theme_editor_log_refresh"><?php esc_html_e('Refresh', 'tyb-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="tyb_theme_editor_log_copy_selected"><?php esc_html_e('Copy selected', 'tyb-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="tyb_theme_editor_log_copy_all"><?php esc_html_e('Copy all', 'tyb-theme-editor'); ?></button>
                <span id="tyb_theme_editor_log_count" class="description" style="margin-left:auto;"></span>
            </div>

            <?php if (empty($entries)): ?>
                <p><?php esc_html_e('No log entries found in preview.', 'tyb-theme-editor'); ?></p>
            <?php else: ?>
                <div style="max-height:560px;overflow:auto;border:1px solid #dcdcde;border-radius:8px;background:#fff;">
                    <table class="widefat striped" style="margin:0;">
                        <thead style="position:sticky;top:0;background:#f6f7f7;z-index:1;">
                            <tr><th style="width:38px;">#</th><th style="width:86px;"><?php esc_html_e('Level', 'tyb-theme-editor'); ?></th><th><?php esc_html_e('Entry', 'tyb-theme-editor'); ?></th></tr>
                        </thead>
                        <tbody id="tyb_theme_editor_log_rows">
                            <?php $index = 1; foreach ($entries as $entry): $level = (string) $entry['level']; $line = (string) $entry['line']; ?>
                                <tr class="tyb-theme-editor-log-row" data-level="<?php echo esc_attr($level); ?>" data-line="<?php echo esc_attr($line); ?>">
                                    <td><label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="tyb-theme-editor-log-select" /><span><?php echo esc_html((string) $index); ?></span></label></td>
                                    <td><span style="display:inline-block;min-width:72px;text-align:center;font-weight:700;border-radius:999px;padding:2px 8px;<?php echo esc_attr(tyb_theme_editor_get_log_level_badge_style($level)); ?>"><?php echo esc_html($level); ?></span></td>
                                    <td><code style="display:block;white-space:pre-wrap;word-break:break-word;color:#8b0000;"><?php echo esc_html($line); ?></code></td>
                                </tr>
                            <?php $index++; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field('tyb_theme_editor_log_action'); ?>
                <input type="hidden" name="tyb_theme_editor_log_action" value="clear" />
                <?php submit_button(__('Clear error log', 'tyb-theme-editor'), 'delete', '', false, ['id' => 'tyb_theme_editor_clear_error_log_btn']); ?>
                <p class="description" style="color:#b00020;margin-top:8px;"><?php esc_html_e('Clearing logs is not recommended during active troubleshooting.', 'tyb-theme-editor'); ?></p>
            </form>
        </div>
    </div>
    <?php
}
