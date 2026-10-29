<?php
// A single admin screen: the debug-logging switch and a live viewer for the file it writes to.
// This plugin has no other admin UI -- everything else happens through the Abilities API.
// Modelled on anonymousSalesPlugin's own Error log screen.

defined('ABSPATH') || exit;

function talktoyourblog_theme_editor_register_admin_menu() {
    add_menu_page(
        __('TalkToYourBlog Theme Editor', 'talktoyourblog-theme-editor'),
        __('Theme Editor', 'talktoyourblog-theme-editor'),
        'edit_themes',
        'talktoyourblog-theme-editor',
        'talktoyourblog_theme_editor_render_log_page',
        'dashicons-media-code',
        81
    );
}

add_action('admin_menu', 'talktoyourblog_theme_editor_register_admin_menu');

function talktoyourblog_theme_editor_admin_enqueue_assets($hook_suffix) {
    if (strpos((string) $hook_suffix, 'talktoyourblog-theme-editor') === false) {
        return;
    }

    wp_enqueue_script(
        'talktoyourblog-theme-editor-admin-log',
        TALKTOYOURBLOG_THEME_EDITOR_PLUGIN_URL . 'assets/admin/admin-log.js',
        [],
        TALKTOYOURBLOG_THEME_EDITOR_VERSION,
        true
    );
    wp_localize_script('talktoyourblog-theme-editor-admin-log', 'TalkToYourBlogThemeEditorAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'errorLogNonce' => wp_create_nonce('talktoyourblog_theme_editor_error_log_refresh'),
    ]);

    wp_add_inline_style('common', '
        .talktoyourblog-admin-wrap { max-width: none; width: 100%; margin-right: 0; padding-right: 0; box-sizing: border-box; }
        .talktoyourblog-admin-wrap .card { max-width: none !important; }
        .talktoyourblog-admin-card { padding: 16px; margin: 16px 0 0; width: 100%; box-sizing: border-box; }
        .talktoyourblog-admin-full { width: 100%; max-width: none; }
        .talktoyourblog-admin-wrap .widefat { width: 100%; table-layout: auto; }
    ');
}

add_action('admin_enqueue_scripts', 'talktoyourblog_theme_editor_admin_enqueue_assets');

function talktoyourblog_theme_editor_read_file_tail($file_path, $max_bytes = 131072) {
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

function talktoyourblog_theme_editor_get_error_log_preview($max_bytes = 131072) {
    $log_file = talktoyourblog_theme_editor_get_error_log_file_path();
    if (!file_exists($log_file)) {
        return [
            'content' => __('Log file does not exist yet. Turn on debug logging and trigger an action to create the first entries.', 'talktoyourblog-theme-editor'),
            'exists' => false,
        ];
    }

    $content = talktoyourblog_theme_editor_read_file_tail($log_file, $max_bytes);
    if ($content === '') {
        $content = __('Log file is empty.', 'talktoyourblog-theme-editor');
    }

    return [
        'content' => $content,
        'exists' => true,
    ];
}

function talktoyourblog_theme_editor_get_log_level_badge_style($level) {
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

function talktoyourblog_theme_editor_parse_log_entries($content, $reverse = true) {
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

function talktoyourblog_theme_editor_handle_refresh_error_log_ajax() {
    if (!current_user_can('edit_themes')) {
        wp_send_json_error(['message' => __('Insufficient permissions.', 'talktoyourblog-theme-editor')], 403);
    }

    check_ajax_referer('talktoyourblog_theme_editor_error_log_refresh', 'nonce');

    $preview = talktoyourblog_theme_editor_get_error_log_preview();
    $entries = talktoyourblog_theme_editor_parse_log_entries((string) ($preview['content'] ?? ''), true);

    wp_send_json_success([
        'entries' => $entries,
        'exists' => !empty($preview['exists']),
        'fetched_at' => current_time('mysql', true),
    ]);
}

add_action('wp_ajax_talktoyourblog_theme_editor_refresh_error_log', 'talktoyourblog_theme_editor_handle_refresh_error_log_ajax');

function talktoyourblog_theme_editor_render_log_page() {
    if (!current_user_can('edit_themes')) {
        return;
    }

    $notice = null;

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['talktoyourblog_theme_editor_log_action'])) {
        check_admin_referer('talktoyourblog_theme_editor_log_action');

        $action = sanitize_key(wp_unslash((string) $_POST['talktoyourblog_theme_editor_log_action']));

        if ($action === 'save_settings') {
            update_option('talktoyourblog_theme_editor_debug_logging', isset($_POST['debug_logging']) ? '1' : '0');
            $notice = ['type' => 'success', 'message' => __('Settings saved.', 'talktoyourblog-theme-editor')];
        } elseif ($action === 'clear') {
            $log_file = talktoyourblog_theme_editor_get_error_log_file_path();
            $ok = @file_put_contents($log_file, '');
            $notice = $ok === false
                ? ['type' => 'error', 'message' => __('Could not clear the log file.', 'talktoyourblog-theme-editor')]
                : ['type' => 'success', 'message' => __('Log file was cleared.', 'talktoyourblog-theme-editor')];
        } elseif ($action === 'write_test') {
            if (!talktoyourblog_theme_editor_logging_enabled()) {
                $notice = ['type' => 'error', 'message' => __('Debug logging is off. Switch it on above first.', 'talktoyourblog-theme-editor')];
            } else {
                talktoyourblog_theme_editor_log_info('Manual test log entry from the admin Error log page.', [
                    'source' => 'admin_log_page',
                    'user_id' => get_current_user_id(),
                ]);
                $notice = ['type' => 'success', 'message' => __('Test log entry written. Use Refresh to load it.', 'talktoyourblog-theme-editor')];
            }
        }
    }

    $log_file = talktoyourblog_theme_editor_get_error_log_file_path();
    $log_exists = file_exists($log_file);
    $log_readable = $log_exists && is_readable($log_file);
    $log_writable = wp_is_writable(dirname($log_file));
    $log_size = $log_exists ? (int) @filesize($log_file) : 0;
    $log_mtime = $log_exists ? (int) @filemtime($log_file) : 0;

    $preview = talktoyourblog_theme_editor_get_error_log_preview();
    $entries = talktoyourblog_theme_editor_parse_log_entries((string) ($preview['content'] ?? ''), true);

    ?>
    <div class="wrap talktoyourblog-admin-wrap">
        <h1><?php esc_html_e('TalkToYourBlog Theme Editor', 'talktoyourblog-theme-editor'); ?></h1>
        <p><?php esc_html_e('This plugin has no other settings -- it only creates and edits files inside its own "ai-*" folders in the active theme, through the WordPress Abilities API.', 'talktoyourblog-theme-editor'); ?></p>

        <?php if ($notice): ?>
            <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
        <?php endif; ?>

        <div class="card talktoyourblog-admin-card talktoyourblog-admin-full">
            <h2><?php esc_html_e('Error log', 'talktoyourblog-theme-editor'); ?></h2>

            <form method="post">
                <?php wp_nonce_field('talktoyourblog_theme_editor_log_action'); ?>
                <input type="hidden" name="talktoyourblog_theme_editor_log_action" value="save_settings" />
                <label><input type="checkbox" name="debug_logging" value="1" <?php checked(talktoyourblog_theme_editor_logging_enabled()); ?> /> <?php esc_html_e('Write a debug log of every ability call (success or failure).', 'talktoyourblog-theme-editor'); ?></label>
                <?php submit_button(__('Save', 'talktoyourblog-theme-editor'), 'secondary', '', false); ?>
                <p class="description"><?php esc_html_e('Off by default. File contents and image bytes are never logged, only the ability name, the path and, on failure, the error.', 'talktoyourblog-theme-editor'); ?></p>
            </form>

            <div style="margin:14px 0;padding:10px 12px;border:1px solid #dcdcde;border-radius:8px;background:#f8f9fa;">
                <strong><?php esc_html_e('Log diagnostics', 'talktoyourblog-theme-editor'); ?></strong>
                <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:6px;font-size:12px;">
                    <span><strong><?php esc_html_e('Path:', 'talktoyourblog-theme-editor'); ?></strong> <code><?php echo esc_html($log_file); ?></code></span>
                    <span><strong><?php esc_html_e('Exists:', 'talktoyourblog-theme-editor'); ?></strong> <?php echo $log_exists ? esc_html__('yes', 'talktoyourblog-theme-editor') : esc_html__('no', 'talktoyourblog-theme-editor'); ?></span>
                    <span><strong><?php esc_html_e('Readable:', 'talktoyourblog-theme-editor'); ?></strong> <?php echo $log_readable ? esc_html__('yes', 'talktoyourblog-theme-editor') : esc_html__('no', 'talktoyourblog-theme-editor'); ?></span>
                    <span><strong><?php esc_html_e('Writable dir:', 'talktoyourblog-theme-editor'); ?></strong> <?php echo $log_writable ? esc_html__('yes', 'talktoyourblog-theme-editor') : esc_html__('no', 'talktoyourblog-theme-editor'); ?></span>
                    <span><strong><?php esc_html_e('Size:', 'talktoyourblog-theme-editor'); ?></strong> <?php echo esc_html((string) $log_size); ?> bytes</span>
                    <span><strong><?php esc_html_e('Updated:', 'talktoyourblog-theme-editor'); ?></strong> <?php echo $log_mtime > 0 ? esc_html(gmdate('Y-m-d H:i:s', $log_mtime) . ' UTC') : '-'; ?></span>
                </div>
                <form method="post" style="margin-top:8px;display:flex;gap:8px;align-items:center;">
                    <?php wp_nonce_field('talktoyourblog_theme_editor_log_action'); ?>
                    <input type="hidden" name="talktoyourblog_theme_editor_log_action" value="write_test" />
                    <?php submit_button(__('Write test log entry', 'talktoyourblog-theme-editor'), 'secondary', '', false); ?>
                    <span class="description"><?php esc_html_e('Use this if the table below is empty, to verify the write/read pipeline.', 'talktoyourblog-theme-editor'); ?></span>
                </form>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px;">
                <label for="talktoyourblog_theme_editor_log_level_filter"><strong><?php esc_html_e('Level:', 'talktoyourblog-theme-editor'); ?></strong></label>
                <select id="talktoyourblog_theme_editor_log_level_filter">
                    <?php foreach (['ALL', 'ERROR', 'WARNING', 'INFO', 'DEBUG'] as $level): ?>
                        <option value="<?php echo esc_attr($level); ?>"><?php echo esc_html($level); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="talktoyourblog_theme_editor_log_search"><strong><?php esc_html_e('Search:', 'talktoyourblog-theme-editor'); ?></strong></label>
                <input type="text" id="talktoyourblog_theme_editor_log_search" class="regular-text" placeholder="<?php esc_attr_e('ability, path, error...', 'talktoyourblog-theme-editor'); ?>" />
                <button type="button" class="button button-small" id="talktoyourblog_theme_editor_log_select_visible"><?php esc_html_e('Select visible', 'talktoyourblog-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="talktoyourblog_theme_editor_log_clear_selection"><?php esc_html_e('Clear selection', 'talktoyourblog-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="talktoyourblog_theme_editor_log_refresh"><?php esc_html_e('Refresh', 'talktoyourblog-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="talktoyourblog_theme_editor_log_copy_selected"><?php esc_html_e('Copy selected', 'talktoyourblog-theme-editor'); ?></button>
                <button type="button" class="button button-small" id="talktoyourblog_theme_editor_log_copy_all"><?php esc_html_e('Copy all', 'talktoyourblog-theme-editor'); ?></button>
                <span id="talktoyourblog_theme_editor_log_count" class="description" style="margin-left:auto;"></span>
            </div>

            <?php if (empty($entries)): ?>
                <p><?php esc_html_e('No log entries found in preview.', 'talktoyourblog-theme-editor'); ?></p>
            <?php else: ?>
                <div style="max-height:560px;overflow:auto;border:1px solid #dcdcde;border-radius:8px;background:#fff;">
                    <table class="widefat striped" style="margin:0;">
                        <thead style="position:sticky;top:0;background:#f6f7f7;z-index:1;">
                            <tr><th style="width:38px;">#</th><th style="width:86px;"><?php esc_html_e('Level', 'talktoyourblog-theme-editor'); ?></th><th><?php esc_html_e('Entry', 'talktoyourblog-theme-editor'); ?></th></tr>
                        </thead>
                        <tbody id="talktoyourblog_theme_editor_log_rows">
                            <?php $index = 1; foreach ($entries as $entry): $level = (string) $entry['level']; $line = (string) $entry['line']; ?>
                                <tr class="talktoyourblog-theme-editor-log-row" data-level="<?php echo esc_attr($level); ?>" data-line="<?php echo esc_attr($line); ?>">
                                    <td><label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="talktoyourblog-theme-editor-log-select" /><span><?php echo esc_html((string) $index); ?></span></label></td>
                                    <td><span style="display:inline-block;min-width:72px;text-align:center;font-weight:700;border-radius:999px;padding:2px 8px;<?php echo esc_attr(talktoyourblog_theme_editor_get_log_level_badge_style($level)); ?>"><?php echo esc_html($level); ?></span></td>
                                    <td><code style="display:block;white-space:pre-wrap;word-break:break-word;color:#8b0000;"><?php echo esc_html($line); ?></code></td>
                                </tr>
                            <?php $index++; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field('talktoyourblog_theme_editor_log_action'); ?>
                <input type="hidden" name="talktoyourblog_theme_editor_log_action" value="clear" />
                <?php submit_button(__('Clear error log', 'talktoyourblog-theme-editor'), 'delete', '', false, ['id' => 'talktoyourblog_theme_editor_clear_error_log_btn']); ?>
                <p class="description" style="color:#b00020;margin-top:8px;"><?php esc_html_e('Clearing logs is not recommended during active troubleshooting.', 'talktoyourblog-theme-editor'); ?></p>
            </form>
        </div>
    </div>
    <?php
}
