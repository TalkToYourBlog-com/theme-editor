<?php
// Path resolution and raw read/write, scoped to themes this plugin itself created -- never an
// existing theme, including whichever one is active. Every public function here either returns
// a safe, already-validated absolute path or a WP_Error -- nothing upstream should ever touch
// WP_CONTENT_DIR . '/themes' or build a path by hand.

defined('ABSPATH') || exit;

const TYB_THEME_EDITOR_ALLOWED_EXTENSIONS = ['php', 'css', 'js'];
const TYB_THEME_EDITOR_ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const TYB_THEME_EDITOR_FOLDER_PREFIX = 'ai-';
const TYB_THEME_EDITOR_MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/**
 * The themes directory every WordPress site already has (wp-content/themes), the parent of
 * every theme including whichever one happens to be active. This plugin only ever reads this
 * far to resolve paths -- it is never itself a folder it is allowed to write into; see
 * tyb_theme_editor_is_allowed_folder_name().
 */
function tyb_theme_editor_theme_dir() {
    return wp_normalize_path(realpath(get_theme_root()));
}

/** Public URL matching tyb_theme_editor_theme_dir(). */
function tyb_theme_editor_theme_dir_url() {
    return untrailingslashit(get_theme_root_uri());
}

/**
 * Whether $name is a folder name this plugin is allowed to create or write inside -- every
 * existing theme, including whichever one is active, stays off limits. Lowercase letters,
 * digits and hyphens only, so a folder name is always a safe single path segment.
 */
function tyb_theme_editor_is_allowed_folder_name($name) {
    $pattern = '/^' . preg_quote(TYB_THEME_EDITOR_FOLDER_PREFIX, '/') . '[a-z0-9-]+$/';
    return is_string($name) && preg_match($pattern, $name) === 1;
}

/**
 * Resolves a relative path against the themes directory and makes sure the result cannot
 * escape it, stays inside an allowed "ai-*" theme (no "../", no symlink pointing outside, no
 * absolute path), and uses an allowed extension. Returns the absolute path on success, WP_Error
 * otherwise.
 *
 * @param string     $relative_path       As given by the caller, e.g. "ai-landing-page/style.css".
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
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The themes directory could not be found.', 'tyb-theme-editor'));
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
                __('This plugin can only touch themes whose folder starts with "%1$s" (e.g. "%1$slanding-page/style.css"). Call create-theme first.', 'tyb-theme-editor'),
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
        // already confirmed to sit inside an allowed "ai-*" theme above.
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
    // themes directory and inside an allowed top-level folder, even though both were already
    // checked on the unresolved path above -- a symlink could otherwise smuggle the real
    // location out.
    if (strpos($resolved, $theme_dir . '/') !== 0) {
        return new WP_Error('tyb_theme_editor_outside_scope', __('That path is outside the themes directory.', 'tyb-theme-editor'));
    }
    $resolved_top = explode('/', substr($resolved, strlen($theme_dir) + 1))[0];
    if (!tyb_theme_editor_is_allowed_folder_name($resolved_top)) {
        return new WP_Error('tyb_theme_editor_outside_scope', __('That path resolves outside the AI-managed themes.', 'tyb-theme-editor'));
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
 * The seed files for a brand new classic WordPress theme: real, minimal, working code -- not
 * just a style.css stub. $slug is the folder/theme name (e.g. "ai-landing-page"); $fn_prefix is
 * the same string with hyphens turned to underscores, safe to use in PHP identifiers.
 *
 * @return array<string, string> relative path (inside the theme) => file content
 */
function tyb_theme_editor_starter_theme_files($slug, $fn_prefix) {
    return [
        'style.css' => "/*\nTheme Name: {$slug}\nVersion: 1.0.0\n*/\n",
        'functions.php' => <<<PHP
<?php
defined('ABSPATH') || exit;

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), [], wp_get_theme()->get('Version'));
});

PHP,
        'header.php' => <<<PHP
<?php defined('ABSPATH') || exit; ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

PHP,
        'footer.php' => <<<PHP
<?php defined('ABSPATH') || exit; ?>
<?php wp_footer(); ?>
</body>
</html>

PHP,
        'index.php' => <<<PHP
<?php
defined('ABSPATH') || exit;
get_header();
?>
<main>
<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
<h1><?php the_title(); ?></h1>
<?php the_content(); ?>
</article>
<?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>

PHP,
    ];
}

/**
 * Creates a new "ai-*" theme (or confirms one with that name already exists): its own folder
 * directly under wp-content/themes, seeded with a minimal but complete, activatable classic
 * theme -- style.css, functions.php, header.php, footer.php, index.php -- so it is listed under
 * Appearance > Themes right away and already renders instead of erroring. This is the only way
 * this plugin ever creates a top-level folder -- update-file and upload-image only ever write
 * inside one that already exists. Creating it never activates it; a site admin does that from
 * Appearance > Themes once it is ready.
 *
 * @return array{path:string, url:string, created:bool, files:string[]}|WP_Error
 */
function tyb_theme_editor_create_theme_folder($name) {
    if (!tyb_theme_editor_is_allowed_folder_name($name)) {
        return new WP_Error(
            'tyb_theme_editor_invalid_folder_name',
            sprintf(
                /* translators: 1: required folder prefix, e.g. "ai-" */
                __('The theme folder name must start with "%1$s" and contain only lowercase letters, digits and hyphens, e.g. "%1$slanding-page".', 'tyb-theme-editor'),
                TYB_THEME_EDITOR_FOLDER_PREFIX
            )
        );
    }

    $theme_dir = tyb_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The themes directory could not be found.', 'tyb-theme-editor'));
    }

    $path = $theme_dir . '/' . $name;
    $already_existed = is_dir($path);

    if (!wp_mkdir_p($path)) {
        return new WP_Error('tyb_theme_editor_mkdir_failed', __('The theme folder could not be created.', 'tyb-theme-editor'));
    }

    $fn_prefix = str_replace('-', '_', $name);
    $created_files = [];
    foreach (tyb_theme_editor_starter_theme_files($name, $fn_prefix) as $relative => $content) {
        $file_path = $path . '/' . $relative;
        if (!file_exists($file_path)) {
            file_put_contents($file_path, $content);
            $created_files[] = $relative;
        }
    }

    return [
        'path' => $name,
        'url' => tyb_theme_editor_theme_dir_url() . '/' . $name,
        'created' => !$already_existed,
        'files' => $created_files,
    ];
}

/** Lists every "ai-*" theme folder under wp-content/themes. */
function tyb_theme_editor_list_theme_folders() {
    $theme_dir = tyb_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The themes directory could not be found.', 'tyb-theme-editor'));
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

/** Lists every allowed-extension file (text or image) inside the plugin's own "ai-*" themes. */
function tyb_theme_editor_list_files() {
    $theme_dir = tyb_theme_editor_theme_dir();
    if (!$theme_dir) {
        return new WP_Error('tyb_theme_editor_no_theme_dir', __('The themes directory could not be found.', 'tyb-theme-editor'));
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
 * Deletes a file inside an "ai-*" theme, after snapshotting its current content first -- writing
 * that snapshot back to the same path with restore-revision re-creates the file, so a deletion
 * is never permanent by accident.
 *
 * @return array{success:bool, revision_id:int}|WP_Error
 */
function tyb_theme_editor_delete_file($relative_path) {
    $resolved = tyb_theme_editor_resolve_path($relative_path, true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $content = file_get_contents($resolved);
    if ($content === false) {
        return new WP_Error('tyb_theme_editor_read_failed', __('The file could not be read.', 'tyb-theme-editor'));
    }

    $relative = tyb_theme_editor_relative_path($resolved);
    $revision_id = tyb_theme_editor_save_revision($relative, $content);
    if (is_wp_error($revision_id)) {
        return $revision_id;
    }

    if (!unlink($resolved)) {
        return new WP_Error('tyb_theme_editor_delete_failed', __('The file could not be deleted (check folder permissions).', 'tyb-theme-editor'));
    }

    return [
        'success' => true,
        'revision_id' => $revision_id,
    ];
}

/**
 * Decodes, validates and writes a base64-encoded image to a path inside an "ai-*" theme. The
 * actual bytes are checked against the extension (not just the file name), and SVG is refused
 * -- it is markup, not a picture, and could carry a script.
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
