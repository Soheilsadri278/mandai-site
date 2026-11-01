<?php
/**
 * Plugin Name: Takinta Editable Site Content
 * Description: Imports the bundled bilingual HTML pages into WordPress and serves database-edited versions on their existing .html routes.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Takinta Design
 * Text Domain: takinta-editable
 */

if (!defined('ABSPATH')) { exit; }

const TAKINTA_EDITABLE_POST_TYPE = 'takinta_content';
const TAKINTA_EDITABLE_PATH_META = '_takinta_static_path';
const TAKINTA_EDITABLE_HTML_META = '_takinta_static_document';
const TAKINTA_EDITABLE_OFFSET_OPTION = 'takinta_editable_import_offset';
const TAKINTA_EDITABLE_FORCE_OPTION = 'takinta_editable_import_force';
const TAKINTA_EDITABLE_IMPORTED_COUNT_OPTION = 'takinta_editable_imported_count';
const TAKINTA_EDITABLE_SKIPPED_COUNT_OPTION = 'takinta_editable_skipped_count';
const TAKINTA_EDITABLE_BATCH_SIZE = 20;

function takinta_editable_register_post_type() {
    $labels = array(
        'name' => 'محتوای صفحات سایت',
        'singular_name' => 'صفحهٔ سایت',
        'menu_name' => 'صفحات سایت',
        'add_new_item' => 'افزودن صفحهٔ سایت',
        'edit_item' => 'ویرایش صفحهٔ سایت',
        'all_items' => 'همهٔ صفحات سایت',
        'search_items' => 'جست‌وجوی صفحات سایت',
        'not_found' => 'صفحه‌ای پیدا نشد.',
    );
    register_post_type(TAKINTA_EDITABLE_POST_TYPE, array(
        'labels' => $labels,
        'public' => false,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => false,
        'has_archive' => false,
        'rewrite' => false,
        'query_var' => false,
        'supports' => array('title'),
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'menu_icon' => 'dashicons-edit-page',
    ));
}
add_action('init', 'takinta_editable_register_post_type');

function takinta_editable_admin_columns($columns) {
    $columns['takinta_static_path'] = 'مسیر عمومی';
    return $columns;
}
add_filter('manage_' . TAKINTA_EDITABLE_POST_TYPE . '_posts_columns', 'takinta_editable_admin_columns');

function takinta_editable_admin_column_value($column, $post_id) {
    if ($column !== 'takinta_static_path') { return; }
    $route = get_post_meta($post_id, TAKINTA_EDITABLE_PATH_META, true);
    echo '<code>' . esc_html('/' . ltrim((string) $route, '/')) . '</code>';
}
add_action('manage_' . TAKINTA_EDITABLE_POST_TYPE . '_posts_custom_column', 'takinta_editable_admin_column_value', 10, 2);

function takinta_editable_disable_block_editor($use_block_editor, $post_type) {
    return $post_type === TAKINTA_EDITABLE_POST_TYPE ? false : $use_block_editor;
}
add_filter('use_block_editor_for_post_type', 'takinta_editable_disable_block_editor', 10, 2);

function takinta_editable_register_meta_box() {
    add_meta_box(
        'takinta-editable-html-source',
        'محتوای HTML صفحه',
        'takinta_editable_render_meta_box',
        TAKINTA_EDITABLE_POST_TYPE,
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_' . TAKINTA_EDITABLE_POST_TYPE, 'takinta_editable_register_meta_box');

function takinta_editable_render_meta_box($post) {
    $source = get_post_meta($post->ID, TAKINTA_EDITABLE_HTML_META, true);
    $route = get_post_meta($post->ID, TAKINTA_EDITABLE_PATH_META, true);
    wp_nonce_field('takinta_editable_save_html', 'takinta_editable_nonce');
    echo '<p>این صفحه با HTML اصلی آن نمایش داده می‌شود. متن را در کد ویرایش کن و ساختار تگ‌ها و نشانی فایل‌های CSS/تصاویر را حفظ کن.</p>';
    if ($route) {
        $url = takinta_editable_public_url($route);
        echo '<p><strong>مسیر عمومی:</strong> <code>' . esc_html('/' . ltrim($route, '/')) . '</code> &nbsp; <a href="' . esc_url($url) . '" target="_blank" rel="noopener">پیش‌نمایش صفحه</a></p>';
    }
    echo '<textarea id="takinta-editable-html-source" name="takinta_editable_html_source" rows="32" class="large-text code" spellcheck="false" style="width:100%;font-family:monospace;direction:ltr;white-space:pre;overflow:auto;">' . esc_textarea($source) . '</textarea>';
    echo '<p class="description">برای ویرایش امن‌تر، پیش از تغییرات بزرگ از متن صفحه نسخهٔ پشتیبان بگیر. ذخیرهٔ کد کامل فقط برای مدیرانی فعال است که مجوز HTML خام دارند.</p>';
}

function takinta_editable_save_post($post_id, $post) {
    if (!$post || $post->post_type !== TAKINTA_EDITABLE_POST_TYPE) { return; }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
    if (!isset($_POST['takinta_editable_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['takinta_editable_nonce'])), 'takinta_editable_save_html')) { return; }
    if (!current_user_can('edit_post', $post_id) || !current_user_can('unfiltered_html')) { return; }
    if (!isset($_POST['takinta_editable_html_source']) || !is_string($_POST['takinta_editable_html_source'])) { return; }

    $source = wp_unslash($_POST['takinta_editable_html_source']);
    if (strlen($source) > 5000000) { return; }
    update_post_meta($post_id, TAKINTA_EDITABLE_HTML_META, $source);
}
add_action('save_post_' . TAKINTA_EDITABLE_POST_TYPE, 'takinta_editable_save_post', 10, 2);

function takinta_editable_enqueue_code_editor($hook) {
    if (!in_array($hook, array('post.php', 'post-new.php'), true)) { return; }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== TAKINTA_EDITABLE_POST_TYPE) { return; }
    $settings = wp_enqueue_code_editor(array('type' => 'text/html'));
    if (!$settings) { return; }
    wp_enqueue_script('code-editor');
    wp_enqueue_style('code-editor');
    $json = wp_json_encode($settings);
    wp_add_inline_script('code-editor', 'jQuery(function($){var field=$("#takinta-editable-html-source");if(field.length&&wp.codeEditor){wp.codeEditor.initialize(field,' . $json . ');}});');
}
add_action('admin_enqueue_scripts', 'takinta_editable_enqueue_code_editor');

function takinta_editable_public_url($route) {
    $route = ltrim((string) $route, '/');
    if ($route === 'index.html') { return home_url('/'); }
    if ($route === 'fa/index.html') { return home_url('/fa/'); }
    return home_url('/' . $route);
}

function takinta_editable_find_post_by_route($route) {
    $query = new WP_Query(array(
        'post_type' => TAKINTA_EDITABLE_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => array(array(
            'key' => TAKINTA_EDITABLE_PATH_META,
            'value' => $route,
            'compare' => '=',
        )),
    ));
    return !empty($query->posts) ? (int) $query->posts[0] : 0;
}

function takinta_editable_render_imported_route() {
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
    $route = (string) get_query_var('takinta_static');
    if ($route === '' && is_front_page()) { $route = 'index.html'; }
    if ($route === '') { return; }

    $route = rawurldecode(str_replace('\\', '/', $route));
    if (strpos($route, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $route)) { return; }
    $route = ltrim($route, '/');
    $post_id = takinta_editable_find_post_by_route($route);
    if (!$post_id) { return; }

    $document = get_post_meta($post_id, TAKINTA_EDITABLE_HTML_META, true);
    if (!is_string($document) || $document === '') { return; }

    $theme_uri = untrailingslashit(get_template_directory_uri());
    $home_url = untrailingslashit(home_url('/'));
    $document = str_replace('__TAKINTA_THEME_URI__', esc_url_raw($theme_uri), $document);
    $document = str_replace('__TAKINTA_HOME_URL__', esc_url_raw($home_url), $document);
    $document = str_replace(array('https://www.example.com', 'http://www.example.com'), $home_url, $document);
    $document = str_replace($home_url . '/images/', $theme_uri . '/static-site/images/', $document);

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Full trusted page source is editable by authorized admins.
    exit;
}
add_action('template_redirect', 'takinta_editable_render_imported_route', -1);

function takinta_editable_static_root() {
    return trailingslashit(get_template_directory()) . 'static-site';
}

function takinta_editable_get_html_files() {
    $root = takinta_editable_static_root();
    if (!is_dir($root)) { return array(); }
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'html') { continue; }
        $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
        $files[$relative] = $file->getPathname();
    }
    ksort($files, SORT_STRING);
    return $files;
}

function takinta_editable_extract_title($html, $route) {
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $matches)) {
        $title = html_entity_decode(trim(wp_strip_all_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($title !== '') { return $title; }
    }
    return basename($route, '.html');
}

function takinta_editable_admin_menu() {
    add_management_page('مهاجرت محتوای تکینتا', 'مهاجرت محتوای تکینتا', 'manage_options', 'takinta-editable-import', 'takinta_editable_render_import_page');
}
add_action('admin_menu', 'takinta_editable_admin_menu');

function takinta_editable_render_import_page() {
    if (!current_user_can('manage_options')) { return; }
    $files = takinta_editable_get_html_files();
    $total = count($files);
    $offset = max(0, (int) get_option(TAKINTA_EDITABLE_OFFSET_OPTION, 0));
    $force = (bool) get_option(TAKINTA_EDITABLE_FORCE_OPTION, false);
    $done = $total > 0 && $offset >= $total;
    $continue = isset($_GET['continue']) && sanitize_text_field(wp_unslash($_GET['continue'])) === '1' && !$done;
    $imported = (int) get_option(TAKINTA_EDITABLE_IMPORTED_COUNT_OPTION, 0);
    $skipped = (int) get_option(TAKINTA_EDITABLE_SKIPPED_COUNT_OPTION, 0);

    echo '<div class="wrap"><h1>مهاجرت محتوای تکینتا</h1>';
    echo '<p>این ابزار صفحه‌های HTML داخل قالب نصب‌شده را به محتوای قابل‌ویرایش در پیشخوان تبدیل می‌کند. صفحه‌های عمومی با همان مسیرهای فعلی و همان ظاهر نمایش داده می‌شوند.</p>';
    if (!$total) {
        echo '<div class="notice notice-error"><p>پوشهٔ <code>static-site</code> در قالب فعال پیدا نشد. ابتدا قالب «Takinta Static Site» را فعال کن.</p></div></div>';
        return;
    }
    echo '<p><strong>کل فایل‌ها:</strong> ' . esc_html((string) $total) . ' &nbsp; <strong>پردازش‌شده:</strong> ' . esc_html((string) min($offset, $total)) . ' &nbsp; <strong>واردشده:</strong> ' . esc_html((string) $imported) . ' &nbsp; <strong>بدون بازنویسی ردشده:</strong> ' . esc_html((string) $skipped) . '</p>';
    echo '<progress max="' . esc_attr((string) $total) . '" value="' . esc_attr((string) min($offset, $total)) . '" style="width:min(100%,640px);height:20px"></progress>';

    if ($done) {
        echo '<div class="notice notice-success"><p>مهاجرت تمام شد. صفحه‌ها را از منوی «صفحات سایت» ویرایش کن.</p></div>';
        echo '<p>اگر نیاز به شروع مجدد داری، اول از نسخه‌های ویرایش‌شده پشتیبان بگیر؛ سپس از دکمهٔ شروع مجدد استفاده کن.</p>';
    } elseif ($continue) {
        echo '<p>در حال واردکردن دستهٔ بعدی؛ این صفحه پس از چند لحظه ادامه می‌دهد.</p>';
    } else {
        echo '<p>واردکردن در دسته‌های ۲۰تایی انجام می‌شود تا محدودیت زمان اجرای هاست باعث قطع فرایند نشود. اجرای دوباره به‌صورت پیش‌فرض صفحات موجود را بازنویسی نمی‌کند.</p>';
    }

    if ($done || (!$continue && $offset > 0)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:8px 12px 0 0">';
        echo '<input type="hidden" name="action" value="takinta_editable_reset">';
        wp_nonce_field('takinta_editable_reset');
        submit_button('شروع مجدد از ابتدا', 'secondary', 'submit', false);
        echo '</form>';
    }

    if (!$done) {
        echo '<form id="takinta-editable-import-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="takinta_editable_import_batch">';
        echo '<input type="hidden" name="offset" value="' . esc_attr((string) $offset) . '">';
        wp_nonce_field('takinta_editable_import_batch');
        echo '<label><input type="checkbox" name="replace_existing" value="1" ' . checked($force, true, false) . '> بازنویسی محتوای صفحات موجود (ویرایش‌های دستی آن‌ها پاک می‌شود)</label><br><br>';
        submit_button($offset > 0 ? 'ادامهٔ واردکردن' : 'شروع واردکردن', 'primary', 'submit', false);
        echo '</form>';
    }
    if ($continue) {
        echo '<script>window.setTimeout(function(){var f=document.getElementById("takinta-editable-import-form");if(f)f.submit();},500);</script>';
    }
    echo '<hr><p><strong>نکته:</strong> «ویرایش» در این نسخه یعنی ویرایش HTML صفحه در ویرایشگر کد وردپرس؛ صفحه‌ساز بصری یا فروشگاه WooCommerce در این افزونه فعال نمی‌شود.</p></div>';
}

function takinta_editable_import_batch() {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_editable_import_batch');
    $files = takinta_editable_get_html_files();
    $total = count($files);
    if (!$total) {
        wp_safe_redirect(admin_url('tools.php?page=takinta-editable-import&error=no-files'));
        exit;
    }

    $offset = max(0, (int) ($_POST['offset'] ?? get_option(TAKINTA_EDITABLE_OFFSET_OPTION, 0)));
    $force = !empty($_POST['replace_existing']);
    update_option(TAKINTA_EDITABLE_FORCE_OPTION, $force, false);
    $batch = array_slice($files, $offset, TAKINTA_EDITABLE_BATCH_SIZE, true);
    $imported = (int) get_option(TAKINTA_EDITABLE_IMPORTED_COUNT_OPTION, 0);
    $skipped = (int) get_option(TAKINTA_EDITABLE_SKIPPED_COUNT_OPTION, 0);

    foreach ($batch as $route => $filepath) {
        $document = file_get_contents($filepath);
        if (!is_string($document) || $document === '') { $skipped++; continue; }
        $existing = takinta_editable_find_post_by_route($route);
        if ($existing && !$force) { $skipped++; continue; }

        $title = takinta_editable_extract_title($document, $route);
        if ($existing) {
            $post_id = wp_update_post(array('ID' => $existing, 'post_title' => $title), true);
        } else {
            $post_id = wp_insert_post(array(
                'post_type' => TAKINTA_EDITABLE_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
                'post_name' => 'site-' . substr(hash('sha256', $route), 0, 20),
                'post_content' => '',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ), true);
        }
        if (is_wp_error($post_id) || !$post_id) { $skipped++; continue; }
        update_post_meta($post_id, TAKINTA_EDITABLE_PATH_META, $route);
        update_post_meta($post_id, TAKINTA_EDITABLE_HTML_META, $document);
        $imported++;
    }

    $next = min($total, $offset + count($batch));
    update_option(TAKINTA_EDITABLE_OFFSET_OPTION, $next, false);
    update_option(TAKINTA_EDITABLE_IMPORTED_COUNT_OPTION, $imported, false);
    update_option(TAKINTA_EDITABLE_SKIPPED_COUNT_OPTION, $skipped, false);
    $url = admin_url('tools.php?page=takinta-editable-import');
    if ($next < $total) { $url = add_query_arg('continue', '1', $url); }
    wp_safe_redirect($url);
    exit;
}
add_action('admin_post_takinta_editable_import_batch', 'takinta_editable_import_batch');

function takinta_editable_reset_import() {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_editable_reset');
    update_option(TAKINTA_EDITABLE_OFFSET_OPTION, 0, false);
    update_option(TAKINTA_EDITABLE_IMPORTED_COUNT_OPTION, 0, false);
    update_option(TAKINTA_EDITABLE_SKIPPED_COUNT_OPTION, 0, false);
    update_option(TAKINTA_EDITABLE_FORCE_OPTION, false, false);
    wp_safe_redirect(admin_url('tools.php?page=takinta-editable-import'));
    exit;
}
add_action('admin_post_takinta_editable_reset', 'takinta_editable_reset_import');
