<?php
// Path resolution and raw read/write for the active theme's own files. Every public function
// here either returns a safe, already-validated absolute path or a WP_Error -- nothing upstream
// should ever touch $_theme_dir or build a path by hand.

defined('ABSPATH') || exit;

const TALKTOYOURBLOG_THEME_EDITOR_ALLOWED_EXTENSIONS = ['php', 'css', 'js'];

/**
 * The directory this plugin is allowed to touch: the *active* theme's own stylesheet directory
 * (a child theme's own folder when one is active, otherwise the theme itself). Never the parent
 * of a child theme -- editing a parent theme's files risks losing the change on its next update,
 * so a site running a child theme is the safer case and the only one this plugin widens scope
 * for by accident.
 */
function talktoyourblog_theme_editor_theme_dir() {
    return wp_normalize_path(realpath(get_stylesheet_directory()));
}

/**
 * Resolves a relative path against the theme directory and makes sure the result cannot
 * escape it (no "../", no symlink pointing outside, no absolute path) and uses an allowed
 * extension. Returns the absolute path on success, WP_Error otherwise.
 *
 * @param string $relative_path As given by the caller, e.g. "style.css" or "template-parts/hero.php".
 * @param bool   $must_exist    Reject a path that does not resolve to an existing file.
 */
function talktoyourblog_theme_editor_resolve_path($relative_path, $must_exist = true) {
    if (!is_string($relative_path) || $relative_path === '') {
        return new WP_Error('talktoyourblog_theme_editor_invalid_path', __('A file path is required.', 'talktoyourblog-theme-editor'));
    }

    $extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
    if (!in_array($extension, TALKTOYOURBLOG_THEME_EDITOR_ALLOWED_EXTENSIONS, true)) {
        return new WP_Error(
            'talktoyourblog_theme_editor_extension_not_allowed',
            sprintf(
                /* translators: %s: comma separated list of allowed file extensions */
                __('Only these file types can be edited: %s.', 'talktoyourblog-theme-editor'),
                implode(', ', TALKTOYOURBLOG_THEME_EDITOR_ALLOWED_EXTENSIONS)
            )
        );
    }

    $theme_dir = talktoyourblog_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('talktoyourblog_theme_editor_no_theme_dir', __('The active theme directory could not be found.', 'talktoyourblog-theme-editor'));
    }

    $candidate = $theme_dir . '/' . ltrim(str_replace('\\', '/', $relative_path), '/');

    if ($must_exist) {
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return new WP_Error('talktoyourblog_theme_editor_not_found', __('That file does not exist in the active theme.', 'talktoyourblog-theme-editor'));
        }
    } else {
        // A new file: realpath() on the file itself would fail, so resolve its parent directory
        // instead and reattach the (already extension-checked) filename.
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            return new WP_Error('talktoyourblog_theme_editor_not_found', __('The containing folder does not exist in the active theme.', 'talktoyourblog-theme-editor'));
        }
        $resolved = $parent . '/' . basename($candidate);
    }

    $resolved = wp_normalize_path($resolved);
    if (strpos($resolved, $theme_dir . '/') !== 0 && $resolved !== $theme_dir) {
        return new WP_Error('talktoyourblog_theme_editor_outside_theme', __('That path is outside the active theme.', 'talktoyourblog-theme-editor'));
    }

    return $resolved;
}

/** Relative path (as the API speaks of it) from an already-resolved absolute path. */
function talktoyourblog_theme_editor_relative_path($absolute_path) {
    $theme_dir = talktoyourblog_theme_editor_theme_dir();
    return ltrim(substr(wp_normalize_path($absolute_path), strlen($theme_dir)), '/');
}

/** sha256 of a file's current content, used so a caller can detect it is editing stale content. */
function talktoyourblog_theme_editor_file_hash($absolute_path) {
    $contents = file_get_contents($absolute_path);
    return $contents === false ? null : hash('sha256', $contents);
}

/** Lists every allowed-extension file under the theme directory, recursively. */
function talktoyourblog_theme_editor_list_files() {
    $theme_dir = talktoyourblog_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('talktoyourblog_theme_editor_no_theme_dir', __('The active theme directory could not be found.', 'talktoyourblog-theme-editor'));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($theme_dir, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, TALKTOYOURBLOG_THEME_EDITOR_ALLOWED_EXTENSIONS, true)) {
            continue;
        }
        $files[] = [
            'path' => talktoyourblog_theme_editor_relative_path($file->getPathname()),
            'size_bytes' => $file->getSize(),
            'modified_at' => gmdate('c', $file->getMTime()),
        ];
    }

    usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

    return $files;
}
