<?php
// Path resolution and raw read/write, scoped to folders this plugin itself owns inside the
// active theme -- never the theme's own files. Every public function here either returns a
// safe, already-validated absolute path or a WP_Error -- nothing upstream should ever touch
// $_theme_dir or build a path by hand.

defined('ABSPATH') || exit;

const TYB_THEME_EDITOR_ALLOWED_EXTENSIONS = ['php', 'css', 'js'];
const TYB_THEME_EDITOR_ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const TYB_THEME_EDITOR_FOLDER_PREFIX = 'ai-';
const TYB_THEME_EDITOR_MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/**
 * The active theme's own root (a child theme's own folder when one is active, otherwise the
 * theme itself). This plugin only ever reads this far to resolve paths -- it is never itself
 * the folder it is allowed to write into; see tyb_theme_editor_is_allowed_folder_name().
 */
function tyb_theme_editor_theme_dir() {
    return wp_normalize_path(realpath(get_stylesheet_directory()));
}

/** Public URL matching tyb_theme_editor_theme_dir(). */
function tyb_theme_editor_theme_dir_url() {
    return untrailingslashit(get_stylesheet_directory_uri());
}

/**
 * Whether $name is a folder name this plugin is allowed to create or write inside -- everything
 * else in the theme (its templates, functions.php, other assets) stays off limits. Lowercase
 * letters, digits and hyphens only, so a folder name is always a safe single path segment.
 */
function tyb_theme_editor_is_allowed_folder_name($name) {
    $pattern = '/^' . preg_quote(TYB_THEME_EDITOR_FOLDER_PREFIX, '/') . '[a-z0-9-]+$/';
    return is_string($name) && preg_match($pattern, $name) === 1;
}

/**
 * Resolves a relative path against the theme directory and makes sure the result cannot
 * escape it, stays inside an allowed "ai-*" folder (no "../", no symlink pointing outside,
 * no absolute path), and uses an allowed extension. Returns the absolute path on success,
 * WP_Error otherwise.
 *
 * @param string     $relative_path       As given by the caller, e.g. "ai-template/style.css".
 * @param bool       $must_exist          Reject a path that does not resolve to an existing file.
 * @param array|null $allowed_extensions  Defaults to the text extensions; pass the image list
 *                                        for image uploads.
 */
function tyb_theme_editor_resolve_path($relative_path, $must_exist = true, $allowed_extensions = null) {
    $allowed_extensions = $allowed_extensions ?: TYB_THEME_EDITOR_ALLOWED_EXTENSIONS;

    if (!is_string($relative_path) || $relative_path === '') {
        return new WP_Error('tyb_theme_editor_invalid_path', __('A file path is required.', 'tyb-theme-editor'));
    }

    $extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions, true)) {
        return new WP_Error(
            'tyb_theme_editor_extension_not_allowed',
            sprintf(
                /* translators: %s: comma separated list of allowed file extensions */
                __('Only these file types can be used here: %s.', 'tyb-theme-editor'),
                implode(', ', $allowed_extensions)
            )
        );
    }

    $theme_dir = tyb_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The active theme directory could not be found.', 'tyb-theme-editor'));
    }

    $normalized = ltrim(str_replace('\\', '/', $relative_path), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return new WP_Error('tyb_theme_editor_invalid_path', __('Invalid path.', 'tyb-theme-editor'));
    }

    $top = explode('/', $normalized)[0];
    if (!tyb_theme_editor_is_allowed_folder_name($top)) {
        return new WP_Error(
            'tyb_theme_editor_outside_scope',
            sprintf(
                /* translators: 1: required folder prefix, e.g. "ai-" */
                __('This plugin can only touch folders starting with "%1$s" (e.g. "%1$stemplate/style.css"). Call create-folder first.', 'tyb-theme-editor'),
                TYB_THEME_EDITOR_FOLDER_PREFIX
            )
        );
    }

    $candidate = $theme_dir . '/' . $normalized;

    if ($must_exist) {
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return new WP_Error('tyb_theme_editor_not_found', __('That file does not exist.', 'tyb-theme-editor'));
        }
        $resolved = wp_normalize_path($resolved);
    } else {
        // A new (or overwritten) file: create its containing folder first, which is itself
        // already confirmed to sit inside an allowed "ai-*" folder above.
        $parent_dir = dirname($candidate);
        if (!wp_mkdir_p($parent_dir)) {
            return new WP_Error('tyb_theme_editor_mkdir_failed', __('The containing folder could not be created.', 'tyb-theme-editor'));
        }
        $resolved_parent = realpath($parent_dir);
        if ($resolved_parent === false) {
            return new WP_Error('tyb_theme_editor_not_found', __('The containing folder could not be found.', 'tyb-theme-editor'));
        }
        $resolved = wp_normalize_path($resolved_parent) . '/' . basename($candidate);
    }

    // Defense in depth: re-check the fully resolved (symlink-free) path is still inside the
    // theme dir and inside an allowed top-level folder, even though both were already checked
    // on the unresolved path above -- a symlink could otherwise smuggle the real location out.
    if (strpos($resolved, $theme_dir . '/') !== 0) {
        return new WP_Error('tyb_theme_editor_outside_theme', __('That path is outside the active theme.', 'tyb-theme-editor'));
    }
    $resolved_top = explode('/', substr($resolved, strlen($theme_dir) + 1))[0];
    if (!tyb_theme_editor_is_allowed_folder_name($resolved_top)) {
        return new WP_Error('tyb_theme_editor_outside_scope', __('That path resolves outside the AI-managed folders.', 'tyb-theme-editor'));
    }

    return $resolved;
}

/** Relative path (as the API speaks of it) from an already-resolved absolute path. */
function tyb_theme_editor_relative_path($absolute_path) {
    $theme_dir = tyb_theme_editor_theme_dir();
    return ltrim(substr(wp_normalize_path($absolute_path), strlen($theme_dir)), '/');
}

/** sha256 of a file's current content, used so a caller can detect it is editing stale content. */
function tyb_theme_editor_file_hash($absolute_path) {
    $contents = file_get_contents($absolute_path);
    return $contents === false ? null : hash('sha256', $contents);
}

/**
 * Creates a new "ai-*" folder at the theme root (or confirms one already exists). This is the
 * only way this plugin ever creates a top-level folder -- update-file and upload-image only
 * ever write inside one that already exists.
 *
 * @return array{path:string, url:string, created:bool}|WP_Error
 */
function tyb_theme_editor_create_folder($name) {
    if (!tyb_theme_editor_is_allowed_folder_name($name)) {
        return new WP_Error(
            'tyb_theme_editor_invalid_folder_name',
            sprintf(
                /* translators: 1: required folder prefix, e.g. "ai-" */
                __('The folder name must start with "%1$s" and contain only lowercase letters, digits and hyphens, e.g. "%1$slanding-page".', 'tyb-theme-editor'),
                TYB_THEME_EDITOR_FOLDER_PREFIX
            )
        );
    }

    $theme_dir = tyb_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The active theme directory could not be found.', 'tyb-theme-editor'));
    }

    $path = $theme_dir . '/' . $name;
    $already_existed = is_dir($path);

    if (!wp_mkdir_p($path)) {
        return new WP_Error('tyb_theme_editor_mkdir_failed', __('The folder could not be created.', 'tyb-theme-editor'));
    }

    $index = $path . '/index.php';
    if (!file_exists($index)) {
        file_put_contents($index, "<?php\n// Silence is golden.\n");
    }

    return [
        'path' => $name,
        'url' => tyb_theme_editor_theme_dir_url() . '/' . $name,
        'created' => !$already_existed,
    ];
}

/** Lists every "ai-*" folder at the theme root. */
function tyb_theme_editor_list_folders() {
    $theme_dir = tyb_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The active theme directory could not be found.', 'tyb-theme-editor'));
    }

    $folders = [];
    foreach (glob($theme_dir . '/' . TYB_THEME_EDITOR_FOLDER_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $folder) {
        $name = basename($folder);
        if (tyb_theme_editor_is_allowed_folder_name($name)) {
            $folders[] = $name;
        }
    }
    sort($folders);

    return $folders;
}

/** Lists every allowed-extension file (text or image) inside the plugin's own "ai-*" folders. */
function tyb_theme_editor_list_files() {
    $theme_dir = tyb_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The active theme directory could not be found.', 'tyb-theme-editor'));
    }

    $all_extensions = array_merge(TYB_THEME_EDITOR_ALLOWED_EXTENSIONS, TYB_THEME_EDITOR_ALLOWED_IMAGE_EXTENSIONS);
    $files = [];

    foreach (glob($theme_dir . '/' . TYB_THEME_EDITOR_FOLDER_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $folder) {
        if (!tyb_theme_editor_is_allowed_folder_name(basename($folder))) {
            continue; // Defensive; the glob pattern already enforces the prefix.
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $all_extensions, true)) {
                continue;
            }
            $files[] = [
                'path' => tyb_theme_editor_relative_path($file->getPathname()),
                'size_bytes' => $file->getSize(),
                'modified_at' => gmdate('c', $file->getMTime()),
            ];
        }
    }

    usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

    return $files;
}

/**
 * Decodes, validates and writes a base64-encoded image to a path inside an "ai-*" folder.
 * The actual bytes are checked against the extension (not just the file name), and SVG is
 * refused -- it is markup, not a picture, and could carry a script.
 *
 * @return array{path:string, url:string, size_bytes:int}|WP_Error
 */
function tyb_theme_editor_save_image($relative_path, $content_base64) {
    $resolved = tyb_theme_editor_resolve_path($relative_path, false, TYB_THEME_EDITOR_ALLOWED_IMAGE_EXTENSIONS);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (!is_string($content_base64) || $content_base64 === '') {
        return new WP_Error('tyb_theme_editor_invalid_image', __('Image content is required.', 'tyb-theme-editor'));
    }

    $binary = base64_decode($content_base64, true);
    if ($binary === false) {
        return new WP_Error('tyb_theme_editor_invalid_image', __('Image content is not valid base64.', 'tyb-theme-editor'));
    }

    if (strlen($binary) > TYB_THEME_EDITOR_MAX_IMAGE_BYTES) {
        return new WP_Error(
            'tyb_theme_editor_image_too_large',
            sprintf(
                /* translators: %d: maximum size in megabytes */
                __('Images must be %d MB or smaller.', 'tyb-theme-editor'),
                TYB_THEME_EDITOR_MAX_IMAGE_BYTES / 1024 / 1024
            )
        );
    }

    $info = @getimagesizefromstring($binary);
    if ($info === false) {
        return new WP_Error('tyb_theme_editor_invalid_image', __('That does not look like a real image file.', 'tyb-theme-editor'));
    }

    $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $expected_mimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    if (($expected_mimes[$extension] ?? null) !== $info['mime']) {
        return new WP_Error(
            'tyb_theme_editor_image_mismatch',
            __("The file's actual content does not match its extension.", 'tyb-theme-editor')
        );
    }

    if (file_put_contents($resolved, $binary) === false) {
        return new WP_Error('tyb_theme_editor_write_failed', __('The image could not be written (check folder permissions).', 'tyb-theme-editor'));
    }

    $relative = tyb_theme_editor_relative_path($resolved);

    return [
        'path' => $relative,
        'url' => tyb_theme_editor_theme_dir_url() . '/' . $relative,
        'size_bytes' => strlen($binary),
    ];
}
