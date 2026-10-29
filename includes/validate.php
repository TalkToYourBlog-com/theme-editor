<?php
// Syntax checking before anything gets written to the live theme. Two tiers: a real `php -l`
// when the host allows shelling out (most do; some managed hosts disable it), and a
// tokenizer-based fallback otherwise that still catches most typos (unclosed braces/strings,
// stray tokens) even though it is not a full parser.

defined('ABSPATH') || exit;

function talktoyourblog_theme_editor_can_shell_exec() {
    if (!function_exists('shell_exec') || !function_exists('escapeshellarg')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('shell_exec', $disabled, true);
}

/**
 * @return true|WP_Error True when the content is syntactically valid (or could not be checked
 *                        at all, in which case a WP_Error with code talktoyourblog_theme_editor_lint_unavailable
 *                        is returned so the caller can decide whether to proceed anyway).
 */
function talktoyourblog_theme_editor_lint_php($content) {
    if (talktoyourblog_theme_editor_can_shell_exec()) {
        $tmp = wp_tempnam('talktoyourblog-theme-editor-lint');
        if ($tmp) {
            file_put_contents($tmp, $content);
            $php_binary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
            $output = shell_exec(escapeshellcmd($php_binary) . ' -l ' . escapeshellarg($tmp) . ' 2>&1');
            @unlink($tmp);
            if (is_string($output)) {
                return strpos($output, 'No syntax errors detected') !== false
                    ? true
                    : new WP_Error('talktoyourblog_theme_editor_syntax_error', trim($output));
            }
        }
        // Fall through to the tokenizer check if the temp file or the shell call failed.
    }

    return talktoyourblog_theme_editor_lint_php_with_tokenizer($content);
}

/**
 * token_get_all() with the TOKEN_PARSE flag (PHP 8+) throws a ParseError on most malformed
 * code instead of silently emitting T_BAD_CHARACTER tokens. It is not a real compiler pass --
 * it will not catch every mistake `php -l` would -- but it is the best check available without
 * shelling out, and it is what this plugin falls back to on hosts where exec is disabled.
 */
function talktoyourblog_theme_editor_lint_php_with_tokenizer($content) {
    if (!defined('TOKEN_PARSE')) {
        // PHP < 8: no parse-mode tokenizer. Nothing meaningful left to check with; say so
        // rather than silently pretending the file is fine.
        return new WP_Error('talktoyourblog_theme_editor_lint_unavailable', __('PHP syntax could not be checked on this server (no shell_exec and PHP is older than 8.0).', 'talktoyourblog-theme-editor'));
    }

    try {
        token_get_all($content, TOKEN_PARSE);
        return true;
    } catch (\ParseError $error) {
        return new WP_Error('talktoyourblog_theme_editor_syntax_error', $error->getMessage());
    }
}

/** CSS/JS get a much cheaper sanity check: balanced braces only, not a real parser either way. */
function talktoyourblog_theme_editor_check_balanced_braces($content) {
    $depth = 0;
    foreach (str_split($content) as $char) {
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth < 0) {
                return new WP_Error('talktoyourblog_theme_editor_syntax_error', __('Unbalanced "}" in the file.', 'talktoyourblog-theme-editor'));
            }
        }
    }
    return $depth === 0
        ? true
        : new WP_Error('talktoyourblog_theme_editor_syntax_error', __('Unbalanced "{" in the file.', 'talktoyourblog-theme-editor'));
}

/** @return true|WP_Error Dispatches to the right check for the file's extension. */
function talktoyourblog_theme_editor_validate_content($extension, $content) {
    if ($extension === 'php') {
        return talktoyourblog_theme_editor_lint_php($content);
    }
    return talktoyourblog_theme_editor_check_balanced_braces($content);
}
