<?php
/**
 * Plugin Name: Takinta Editable Site, Accounts & WooCommerce Orders
 * Description: Imports editable pages, provides accounts and WooCommerce ordering with manual card-to-card payment.
 * Version: 1.4.9
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
const TAKINTA_WC_DRAFT_IMPORT_OFFSET_OPTION = 'takinta_wc_draft_import_offset';
const TAKINTA_WC_DRAFT_IMPORT_CREATED_OPTION = 'takinta_wc_draft_import_created';
const TAKINTA_WC_DRAFT_IMPORT_SKIPPED_OPTION = 'takinta_wc_draft_import_skipped';
const TAKINTA_WC_DRAFT_IMPORT_BATCH_SIZE = 20;
const TAKINTA_WC_DRAFT_IMAGE_OFFSET_OPTION = 'takinta_wc_draft_image_offset';
const TAKINTA_WC_DRAFT_IMAGE_IMPORTED_OPTION = 'takinta_wc_draft_image_imported';
const TAKINTA_WC_DRAFT_IMAGE_SKIPPED_OPTION = 'takinta_wc_draft_image_skipped';
const TAKINTA_WC_DRAFT_IMAGE_UNMATCHED_OPTION = 'takinta_wc_draft_image_unmatched';
const TAKINTA_WC_DRAFT_IMAGE_FAILED_OPTION = 'takinta_wc_draft_image_failed';
const TAKINTA_WC_DRAFT_IMAGE_BATCH_SIZE = 3;

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

function takinta_account_route_from_request() {
    if (empty($_SERVER['REQUEST_URI'])) { return false; }
    $request_path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
    if (!is_string($request_path)) { return false; }
    $request_path = '/' . ltrim(rawurldecode($request_path), '/');
    $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
    $home_path = is_string($home_path) ? '/' . trim(rawurldecode($home_path), '/') : '/';
    if ($home_path !== '/' && ($request_path === $home_path || strpos($request_path, trailingslashit($home_path)) === 0)) {
        $request_path = substr($request_path, strlen($home_path));
    }
    $route = trim($request_path, '/');
    $routes = array(
        'account' => array('fa' => false, 'page' => 'account'),
        'register' => array('fa' => false, 'page' => 'register'),
        'fa/account' => array('fa' => true, 'page' => 'account'),
        'fa/register' => array('fa' => true, 'page' => 'register'),
        'cart' => array('fa' => false, 'page' => 'cart'),
        'fa/cart' => array('fa' => true, 'page' => 'cart'),
    );
    return isset($routes[$route]) ? $routes[$route] : false;
}

function takinta_account_url($is_fa, $register = false) {
    $path = ($is_fa ? '/fa/' : '/') . ($register ? 'register/' : 'account/');
    return home_url($path);
}

function takinta_account_attempt_limited($bucket, $limit, $seconds) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    if ($ip === '') { return false; }
    $key = 'takinta_ac_' . substr(hash_hmac('sha256', $bucket . '|' . $ip, wp_salt('auth')), 0, 32);
    $count = (int) get_transient($key);
    if ($count >= $limit) { return true; }
    set_transient($key, $count + 1, $seconds);
    return false;
}

function takinta_account_post_value($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
}

function takinta_account_process_submission($is_fa, $page) {
    $error = '';
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return $error; }
    $action = sanitize_key(takinta_account_post_value('takinta_account_action'));
    if (!in_array($action, array('login', 'register', 'profile'), true)) { return $error; }
    $nonce = sanitize_text_field(takinta_account_post_value('_takinta_nonce'));
    if (!$nonce || !wp_verify_nonce($nonce, 'takinta_account_' . $action)) {
        return $is_fa ? 'درخواست معتبر نیست. صفحه را تازه‌سازی و دوباره تلاش کن.' : 'This request could not be verified. Refresh the page and try again.';
    }

    if ($action === 'login') {
        if (!empty($_POST['website'])) { return ''; }
        if (takinta_account_attempt_limited('login', 20, 15 * MINUTE_IN_SECONDS)) {
            return $is_fa ? 'تلاش‌های زیادی انجام شده است. کمی بعد دوباره تلاش کن.' : 'Too many attempts. Please try again later.';
        }
        $login = sanitize_text_field(takinta_account_post_value('login'));
        $password = takinta_account_post_value('password');
        if (strlen($password) > 256) {
            return $is_fa ? 'ایمیل/نام کاربری یا گذرواژه درست نیست.' : 'The email/username or password is incorrect.';
        }
        if (is_email($login)) {
            $user_by_email = get_user_by('email', $login);
            if ($user_by_email) { $login = $user_by_email->user_login; }
        }
        $user = wp_signon(array(
            'user_login' => $login,
            'user_password' => $password,
            'remember' => !empty($_POST['remember']),
        ), is_ssl());
        if (is_wp_error($user)) {
            return $is_fa ? 'ایمیل/نام کاربری یا گذرواژه درست نیست.' : 'The email/username or password is incorrect.';
        }
        wp_safe_redirect(add_query_arg('account_status', 'logged-in', takinta_account_url($is_fa)));
        exit;
    }

    if ($action === 'register') {
        if (!empty($_POST['website'])) { return ''; }
        if (takinta_account_attempt_limited('register', 5, HOUR_IN_SECONDS)) {
            return $is_fa ? 'درخواست‌های ثبت‌نام زیادی از این اتصال دریافت شده است. یک ساعت بعد دوباره تلاش کن.' : 'Too many sign-up attempts from this connection. Please try again in an hour.';
        }
        $email = sanitize_email(takinta_account_post_value('email'));
        $first_name = sanitize_text_field(takinta_account_post_value('first_name'));
        $last_name = sanitize_text_field(takinta_account_post_value('last_name'));
        $password = takinta_account_post_value('password');
        $confirm = takinta_account_post_value('password_confirm');
        $consented = takinta_account_post_value('privacy_consent') === '1';
        if (!is_email($email)) {
            return $is_fa ? 'یک نشانی ایمیل معتبر وارد کن.' : 'Enter a valid email address.';
        }
        if (email_exists($email)) {
            return $is_fa ? 'این ایمیل قبلاً ثبت شده است؛ وارد شو یا گذرواژه را بازیابی کن.' : 'This email is already registered. Sign in or reset your password.';
        }
        if (strlen($password) < 12 || strlen($password) > 256) {
            return $is_fa ? 'گذرواژه باید بین ۱۲ تا ۲۵۶ نویسه داشته باشد.' : 'Use a password between 12 and 256 characters.';
        }
        if (!hash_equals($password, $confirm)) {
            return $is_fa ? 'تکرار گذرواژه با آن مطابقت ندارد.' : 'The passwords do not match.';
        }
        if (!$consented) {
            return $is_fa ? 'برای ساخت حساب، تأیید آگاهی از سیاست حریم خصوصی لازم است.' : 'Please acknowledge the privacy notice to create an account.';
        }

        $email_parts = explode('@', $email, 2);
        $base = sanitize_user($email_parts[0], true);
        if (strlen($base) < 3) { $base = 'takinta' . wp_rand(1000, 9999); }
        $base = substr($base, 0, 48);
        $username = $base;
        $suffix = 1;
        while (username_exists($username)) { $username = $base . $suffix++; }
        $display_name = trim($first_name . ' ' . $last_name);
        if ($display_name === '') { $display_name = $username; }
        $user_id = wp_insert_user(array(
            'user_login' => $username,
            'user_pass' => $password,
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $display_name,
            'role' => 'subscriber',
        ));
        if (is_wp_error($user_id)) {
            return $is_fa ? 'ساخت حساب انجام نشد. اطلاعات را بررسی و دوباره تلاش کن.' : 'We could not create the account. Check the details and try again.';
        }
        update_user_meta($user_id, 'takinta_privacy_consent_at', current_time('mysql', true));
        $user = get_user_by('id', $user_id);
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());
        do_action('wp_login', $user->user_login, $user);
        wp_safe_redirect(add_query_arg('account_status', 'created', takinta_account_url($is_fa)));
        exit;
    }

    if ($action === 'profile') {
        if (!is_user_logged_in()) {
            return $is_fa ? 'برای ویرایش حساب ابتدا وارد شو.' : 'Please sign in to edit your account.';
        }
        $user_id = get_current_user_id();
        if (!current_user_can('edit_user', $user_id)) {
            return $is_fa ? 'اجازهٔ ویرایش این حساب را نداری.' : 'You are not allowed to edit this account.';
        }
        $first_name = sanitize_text_field(takinta_account_post_value('first_name'));
        $last_name = sanitize_text_field(takinta_account_post_value('last_name'));
        $display_name = sanitize_text_field(takinta_account_post_value('display_name'));
        if ($display_name === '') { $display_name = trim($first_name . ' ' . $last_name); }
        if ($display_name === '') {
            return $is_fa ? 'نام نمایشی نمی‌تواند خالی باشد.' : 'Display name cannot be empty.';
        }
        $updated = wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $display_name,
        ));
        if (is_wp_error($updated)) {
            return $is_fa ? 'ذخیرهٔ تغییرات انجام نشد.' : 'We could not save your changes.';
        }
        wp_safe_redirect(add_query_arg('account_status', 'saved', takinta_account_url($is_fa)));
        exit;
    }
    return $error;
}

function takinta_account_status_message($is_fa) {
    $status = isset($_GET['account_status']) && is_string($_GET['account_status']) ? sanitize_key(wp_unslash($_GET['account_status'])) : '';
    $messages = $is_fa ? array(
        'created' => 'حساب ساخته شد و وارد شدی.',
        'logged-in' => 'با موفقیت وارد شدی.',
        'saved' => 'اطلاعات حساب ذخیره شد.',
    ) : array(
        'created' => 'Your account has been created and you are signed in.',
        'logged-in' => 'You are now signed in.',
        'saved' => 'Your account details have been saved.',
    );
    if (isset($_GET['loggedout'])) {
        return $is_fa ? 'با موفقیت خارج شدی.' : 'You have signed out.';
    }
    return isset($messages[$status]) ? $messages[$status] : '';
}

function takinta_account_render_page($is_fa, $page, $error = '') {
    $rtl = $is_fa ? ' dir="rtl"' : ' dir="ltr"';
    $lang = $is_fa ? 'fa' : 'en';
    $account_url = takinta_account_url($is_fa);
    $register_url = takinta_account_url($is_fa, true);
    $home_url = home_url($is_fa ? '/fa/' : '/');
    $theme_uri = untrailingslashit(get_template_directory_uri());
    $logo = $theme_uri . '/static-site/images/logo.png';
    $user = wp_get_current_user();
    $privacy_url = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '';
    $notice = takinta_account_status_message($is_fa);
    $text = $is_fa ? array(
        'title' => 'حساب کاربری تکینتا', 'subtitle' => 'حساب خود را مدیریت کن یا برای ساخت حساب جدید ثبت‌نام کن.',
        'back' => 'بازگشت به سایت', 'login' => 'ورود به حساب', 'register' => 'ساخت حساب', 'email' => 'ایمیل',
        'password' => 'گذرواژه', 'confirm' => 'تکرار گذرواژه', 'first' => 'نام', 'last' => 'نام خانوادگی',
        'remember' => 'مرا به خاطر بسپار', 'submit_login' => 'ورود', 'submit_register' => 'ساخت حساب و ورود',
        'forgot' => 'گذرواژه را فراموش کرده‌ای؟', 'have_account' => 'حساب داری؟ وارد شو', 'new_account' => 'حساب نداری؟ ثبت‌نام کن',
        'privacy' => 'با ساخت حساب، از ذخیرهٔ ایمیل و اطلاعات نام برای مدیریت حساب در این سایت آگاه هستم.',
        'privacy_link' => 'سیاست حریم خصوصی', 'dashboard' => 'پروفایل من', 'status_title' => 'وضعیت حساب', 'welcome' => 'خوش آمدی',
        'username' => 'نام کاربری', 'save' => 'ذخیرهٔ تغییرات', 'change_password' => 'تغییر یا بازیابی گذرواژه',
        'logout' => 'خروج', 'account_note' => 'این حساب برای مدیریت اطلاعات پروفایل است و به سفارش یا پرداخت متصل نیست.',
        'password_hint' => 'حداقل ۱۲ نویسه',
    ) : array(
        'title' => 'Takinta account', 'subtitle' => 'Sign in to manage your profile or create a new account.',
        'back' => 'Back to the site', 'login' => 'Sign in', 'register' => 'Create an account', 'email' => 'Email',
        'password' => 'Password', 'confirm' => 'Confirm password', 'first' => 'First name', 'last' => 'Last name',
        'remember' => 'Remember me', 'submit_login' => 'Sign in', 'submit_register' => 'Create account and sign in',
        'forgot' => 'Forgot your password?', 'have_account' => 'Already have an account? Sign in', 'new_account' => 'New here? Create an account',
        'privacy' => 'I understand that my email and name will be stored to manage this site account.',
        'privacy_link' => 'Privacy policy', 'dashboard' => 'My profile', 'status_title' => 'Account status', 'welcome' => 'Welcome',
        'username' => 'Username', 'save' => 'Save changes', 'change_password' => 'Change or reset password',
        'logout' => 'Sign out', 'account_note' => 'This account manages profile details only; it is not connected to orders or payments.',
        'password_hint' => 'At least 12 characters',
    );

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="' . esc_attr($lang) . '"' . $rtl . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><title>' . esc_html($text['title']) . '</title><link rel="stylesheet" href="' . esc_url($theme_uri . '/static-site/assets/css/style.css') . '">';
    if ($is_fa) { echo '<link rel="stylesheet" href="' . esc_url($theme_uri . '/static-site/assets/css/rtl.css') . '">'; }
    echo '<style>';
    echo 'body{margin:0;background:#f6f4f0;color:#202521;font-family:inherit}.ta-account{min-height:100vh;padding:28px 18px 48px}.ta-wrap{width:min(100%,980px);margin:auto}.ta-top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:30px}.ta-logo{display:block;width:170px;max-height:72px;object-fit:contain;mix-blend-mode:multiply;background:transparent}.ta-back{color:#343d37;text-decoration:none;font-weight:600}.ta-heading{margin-bottom:24px}.ta-heading h1{margin:0 0 8px;font-size:clamp(28px,4vw,42px)}.ta-heading p{margin:0;color:#626b65}.ta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:20px}.ta-card{background:#fff;border:1px solid #e6e4de;border-radius:16px;padding:clamp(20px,4vw,32px);box-shadow:0 10px 30px rgba(30,40,34,.06)}.ta-card h2{margin:0 0 20px;font-size:22px}.ta-field{display:block;margin:0 0 15px}.ta-field span{display:block;margin-bottom:6px;font-weight:600}.ta-field input{box-sizing:border-box;width:100%;min-height:46px;padding:10px 12px;border:1px solid #c9cec9;border-radius:8px;background:#fff;color:#202521;font:inherit}.ta-check{display:flex;align-items:flex-start;gap:9px;margin:12px 0 18px;line-height:1.6}.ta-check input{margin-top:5px}.ta-button{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:10px 18px;border:0;border-radius:8px;background:#254a3a;color:#fff;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}.ta-button:hover{background:#183b2d;color:#fff}.ta-links{display:flex;flex-wrap:wrap;gap:14px;margin-top:18px}.ta-links a,.ta-card a{color:#254a3a}.ta-card a.ta-button{color:#fff}.ta-alert{margin:0 0 18px;padding:12px 15px;border-radius:8px;background:#e8f3eb;color:#1f5936}.ta-alert--error{background:#fff0ee;color:#9d3025}.ta-hp{position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden}.ta-details{margin:0 0 22px;padding:14px;background:#f7f8f6;border-radius:9px;line-height:1.8}.ta-actions{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-top:18px}.ta-note{margin:22px 2px 0;color:#657069;font-size:14px;line-height:1.7}.ta-footer{margin-top:32px;color:#69726c;font-size:13px}@media(max-width:560px){.ta-account{padding:18px 12px 32px}.ta-logo{width:130px}.ta-top{margin-bottom:22px}}';
    echo '</style></head><body><main class="ta-account"><div class="ta-wrap"><header class="ta-top"><a href="' . esc_url($home_url) . '" aria-label="Takinta"><img class="ta-logo" src="' . esc_url($logo) . '" alt="Takinta"></a><a class="ta-back" href="' . esc_url($home_url) . '">' . esc_html($text['back']) . '</a></header><div class="ta-heading"><h1>' . esc_html($text['title']) . '</h1><p>' . esc_html($text['subtitle']) . '</p></div>';
    if ($notice !== '') { echo '<div class="ta-alert" role="status">' . esc_html($notice) . '</div>'; }
    if ($error !== '') { echo '<div class="ta-alert ta-alert--error" role="alert">' . esc_html($error) . '</div>'; }

    if (is_user_logged_in()) {
        $profile_nonce = wp_nonce_field('takinta_account_profile', '_takinta_nonce', true, false);
        $lost_password = wp_lostpassword_url($account_url);
        $logout_url = wp_logout_url($account_url);
        echo '<div class="ta-grid"><section class="ta-card"><h2>' . esc_html($text['dashboard']) . '</h2><p>' . esc_html($text['welcome']) . '، <strong>' . esc_html($user->display_name) . '</strong></p><div class="ta-details"><div><strong>' . esc_html($text['username']) . ':</strong> ' . esc_html($user->user_login) . '</div><div><strong>' . esc_html($text['email']) . ':</strong> ' . esc_html($user->user_email) . '</div></div><form method="post" action="' . esc_url($account_url) . '">' . $profile_nonce . '<input type="hidden" name="takinta_account_action" value="profile">';
        echo '<label class="ta-field"><span>' . esc_html($text['first']) . '</span><input name="first_name" autocomplete="given-name" value="' . esc_attr($user->first_name) . '"></label><label class="ta-field"><span>' . esc_html($text['last']) . '</span><input name="last_name" autocomplete="family-name" value="' . esc_attr($user->last_name) . '"></label><label class="ta-field"><span>' . esc_html($is_fa ? 'نام نمایشی' : 'Display name') . '</span><input name="display_name" required value="' . esc_attr($user->display_name) . '"></label><button class="ta-button" type="submit">' . esc_html($text['save']) . '</button></form><div class="ta-actions"><a href="' . esc_url($lost_password) . '">' . esc_html($text['change_password']) . '</a><a href="' . esc_url($logout_url) . '">' . esc_html($text['logout']) . '</a></div></section><section class="ta-card"><h2>' . esc_html($text['status_title']) . '</h2><p>' . esc_html($text['account_note']) . '</p><p>' . esc_html($user->user_email) . '</p></section></div>';
    } else {
        $show_register = ($page === 'register');
        $login_nonce = wp_nonce_field('takinta_account_login', '_takinta_nonce', true, false);
        $register_nonce = wp_nonce_field('takinta_account_register', '_takinta_nonce', true, false);
        $lost_password = wp_lostpassword_url($account_url);
        if ($show_register) {
            echo '<div class="ta-grid"><section class="ta-card"><h2>' . esc_html($text['register']) . '</h2><form method="post" action="' . esc_url($register_url) . '">' . $register_nonce . '<input type="hidden" name="takinta_account_action" value="register"><div class="ta-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>';
            echo '<label class="ta-field"><span>' . esc_html($text['first']) . '</span><input name="first_name" autocomplete="given-name"></label><label class="ta-field"><span>' . esc_html($text['last']) . '</span><input name="last_name" autocomplete="family-name"></label><label class="ta-field"><span>' . esc_html($text['email']) . '</span><input type="email" name="email" autocomplete="email" required></label><label class="ta-field"><span>' . esc_html($text['password']) . ' (' . esc_html($text['password_hint']) . ')</span><input type="password" name="password" autocomplete="new-password" minlength="12" required></label><label class="ta-field"><span>' . esc_html($text['confirm']) . '</span><input type="password" name="password_confirm" autocomplete="new-password" minlength="12" required></label><label class="ta-check"><input type="checkbox" name="privacy_consent" value="1" required><span>' . esc_html($text['privacy']) . ($privacy_url ? ' <a href="' . esc_url($privacy_url) . '" target="_blank" rel="noopener">' . esc_html($text['privacy_link']) . '</a>' : '') . '</span></label><button class="ta-button" type="submit">' . esc_html($text['submit_register']) . '</button></form><div class="ta-links"><a href="' . esc_url($account_url) . '">' . esc_html($text['have_account']) . '</a></div></section></div>';
        } else {
            echo '<div class="ta-grid"><section class="ta-card"><h2>' . esc_html($text['login']) . '</h2><form method="post" action="' . esc_url($account_url) . '">' . $login_nonce . '<input type="hidden" name="takinta_account_action" value="login"><div class="ta-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div><label class="ta-field"><span>' . esc_html($text['email']) . ' / ' . esc_html($text['username']) . '</span><input name="login" autocomplete="username" required></label><label class="ta-field"><span>' . esc_html($text['password']) . '</span><input type="password" name="password" autocomplete="current-password" required></label><label class="ta-check"><input type="checkbox" name="remember" value="1"><span>' . esc_html($text['remember']) . '</span></label><button class="ta-button" type="submit">' . esc_html($text['submit_login']) . '</button></form><div class="ta-links"><a href="' . esc_url($lost_password) . '">' . esc_html($text['forgot']) . '</a></div></section><section class="ta-card"><h2>' . esc_html($text['register']) . '</h2><p>' . esc_html($is_fa ? 'با ایمیل خود حساب بساز و اطلاعات پروفایلت را مدیریت کن.' : 'Create an account with your email to manage your profile.') . '</p><a class="ta-button" href="' . esc_url($register_url) . '">' . esc_html($text['submit_register']) . '</a></section></div>';
        }
    }
    echo '<p class="ta-note">' . esc_html($text['account_note']) . '</p><footer class="ta-footer">© ' . esc_html((string) gmdate('Y')) . ' Takinta</footer></div></main></body></html>';
}

function takinta_cart_url($is_fa) {
    if (class_exists('WooCommerce') && function_exists('wc_get_cart_url')) {
        $url = wc_get_cart_url();
        return add_query_arg('lang', $is_fa ? 'fa' : 'en', $url);
    }
    return home_url($is_fa ? '/fa/cart/' : '/cart/');
}

function takinta_cart_product_document($route) {
    $route = ltrim(str_replace('\\', '/', (string) $route), '/');
    if (!preg_match('#^(?:fa/)?products/[a-z0-9][a-z0-9-]*\.html$#i', $route)) { return ''; }
    $post_id = takinta_editable_find_post_by_route($route);
    if ($post_id) {
        $stored = get_post_meta($post_id, TAKINTA_EDITABLE_HTML_META, true);
        if (is_string($stored) && $stored !== '') { return $stored; }
    }
    $root = realpath(takinta_editable_static_root());
    $file = $root ? realpath($root . DIRECTORY_SEPARATOR . $route) : false;
    if (!$root || !$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) { return ''; }
    $document = file_get_contents($file);
    return is_string($document) ? $document : '';
}

function takinta_cart_product_title($document, $route) {
    if (preg_match('~<h1\b[^>]*\bid="product-title"[^>]*>(.*?)</h1>~is', $document, $matches)) {
        $title = html_entity_decode(trim(wp_strip_all_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($title !== '') { return sanitize_text_field($title); }
    }
    return sanitize_text_field(basename($route, '.html'));
}

function takinta_cart_process_submission($is_fa) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || takinta_account_post_value('takinta_cart_action') !== 'submit_quote') { return ''; }
    $nonce = sanitize_text_field(takinta_account_post_value('_takinta_cart_nonce'));
    if (!$nonce || !wp_verify_nonce($nonce, 'takinta_cart_submit')) {
        return $is_fa ? 'درخواست معتبر نیست. صفحه را تازه‌سازی و دوباره تلاش کن.' : 'This request could not be verified. Refresh the page and try again.';
    }
    if (takinta_account_post_value('website') !== '') {
        wp_safe_redirect(add_query_arg('cart_status', 'sent', takinta_cart_url($is_fa)));
        exit;
    }
    if (takinta_account_attempt_limited('cart_quote', 4, HOUR_IN_SECONDS)) {
        return $is_fa ? 'درخواست‌های زیادی ارسال شده است. کمی بعد دوباره تلاش کن.' : 'Too many requests. Please try again later.';
    }

    $payload = takinta_account_post_value('cart_payload');
    if ($payload === '' || strlen($payload) > 30000) {
        return $is_fa ? 'سبد خالی است یا اطلاعات آن معتبر نیست.' : 'The cart is empty or invalid.';
    }
    $items = json_decode($payload, true);
    if (!is_array($items) || !$items || count($items) > 30) {
        return $is_fa ? 'سبد خالی است یا تعداد کالاها بیش از حد مجاز است.' : 'The cart is empty or contains too many items.';
    }

    $lines = array();
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['route'], $item['id'])) { continue; }
        if (!is_string($item['route']) || !is_string($item['id'])) { continue; }
        $route = ltrim(str_replace('\\', '/', sanitize_text_field($item['route'])), '/');
        if (!preg_match('#^(?:fa/)?products/[a-z0-9][a-z0-9-]*\.html$#i', $route)) { continue; }
        $canonical_id = strpos($route, 'fa/') === 0 ? substr($route, 3) : $route;
        if (!hash_equals($canonical_id, sanitize_text_field($item['id']))) { continue; }
        $raw_quantity = $item['quantity'] ?? null;
        if (!is_int($raw_quantity) && !(is_string($raw_quantity) && ctype_digit($raw_quantity))) { continue; }
        $quantity = (int) $raw_quantity;
        if ($quantity < 1 || $quantity > 999) { continue; }
        $document = takinta_cart_product_document($route);
        if ($document === '') { continue; }
        $title = takinta_cart_product_title($document, $route);
        $lines[] = array('title' => $title, 'quantity' => $quantity, 'url' => takinta_editable_public_url($route));
    }
    if (!$lines) {
        return $is_fa ? 'کالای معتبر برای استعلام در سبد پیدا نشد.' : 'No valid products were found in the cart.';
    }

    $name = sanitize_text_field(takinta_account_post_value('name'));
    $email = sanitize_email(takinta_account_post_value('email'));
    $phone = sanitize_text_field(takinta_account_post_value('phone'));
    $note = sanitize_textarea_field(takinta_account_post_value('message'));
    if ($name === '' || !is_email($email)) {
        return $is_fa ? 'نام و ایمیل معتبر را وارد کن.' : 'Enter your name and a valid email address.';
    }
    if (takinta_account_post_value('privacy_consent') !== '1') {
        return $is_fa ? 'برای ارسال درخواست، تأیید آگاهی از سیاست حریم خصوصی لازم است.' : 'Please acknowledge the privacy notice before sending the request.';
    }
    if (strlen($note) > 4000) {
        return $is_fa ? 'یادداشت بیش از حد طولانی است.' : 'The note is too long.';
    }
    $recipient = apply_filters('takinta_static_contact_recipient', get_option('admin_email'));
    if (!is_email($recipient)) {
        return $is_fa ? 'ایمیل مدیر سایت در تنظیمات وردپرس معتبر نیست.' : 'Set a valid WordPress administration email before sending purchase requests.';
    }

    $subject = $is_fa ? 'درخواست خرید محصولات تکینتا' : 'Takinta product purchase request';
    $body = "Name: {$name}\nEmail: {$email}\nPhone: {$phone}\n\nPurchase request (not a confirmed order; no prices have been submitted):\n";
    foreach ($lines as $line) {
        $body .= sprintf("- %s | Quantity: %d\n  %s\n", $line['title'], $line['quantity'], $line['url']);
    }
    if ($note !== '') { $body .= "\nCustomer note:\n{$note}\n"; }
    $headers = array('Reply-To: ' . $name . ' <' . $email . '>');
    if (!wp_mail($recipient, $subject, $body, $headers)) {
        return $is_fa ? 'ارسال ایمیل انجام نشد. تنظیمات SMTP را بررسی و دوباره تلاش کن.' : 'The request could not be emailed. Check site email/SMTP and try again.';
    }
    wp_safe_redirect(add_query_arg('cart_status', 'sent', takinta_cart_url($is_fa)));
    exit;
}

function takinta_cart_render_page($is_fa, $error = '') {
    $lang = $is_fa ? 'fa' : 'en';
    $rtl = $is_fa ? ' dir="rtl"' : ' dir="ltr"';
    $cart_url = takinta_cart_url($is_fa);
    $home_url = home_url($is_fa ? '/fa/' : '/');
    $browse_url = home_url($is_fa ? '/fa/#categories' : '/#categories');
    $theme_uri = untrailingslashit(get_template_directory_uri());
    $logo = $theme_uri . '/static-site/images/logo.png';
    $privacy_url = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '';
    $nonce = wp_nonce_field('takinta_cart_submit', '_takinta_cart_nonce', true, false);
    $sent = isset($_GET['cart_status']) && is_string($_GET['cart_status']) && sanitize_key(wp_unslash($_GET['cart_status'])) === 'sent';
    $t = $is_fa ? array(
        'title' => 'سبد خرید', 'eyebrow' => 'انتخاب‌های شما', 'intro' => 'محصولات موردنظرتان را یک‌جا جمع کنید و درخواست خریدتان را برای پیگیری بفرستید.',
        'home' => 'بازگشت به سایت', 'empty' => 'سبد خرید شما هنوز خالی است', 'empty_copy' => 'از میان محصولات، موارد موردنظرتان را اضافه کنید؛ می‌توانید تعداد را هم تغییر دهید.',
        'browse' => 'مشاهدهٔ محصولات', 'selected' => 'محصولات انتخاب‌شده', 'quantity' => 'تعداد', 'remove' => 'حذف', 'total' => 'کالا',
        'request_title' => 'تکمیل درخواست خرید', 'request_copy' => 'اطلاعات تماس را وارد کنید تا برای هماهنگی خرید محصولات انتخابی با شما تماس بگیریم.',
        'summary_empty' => 'پس از افزودن محصول، فرم درخواست خرید اینجا نمایش داده می‌شود.', 'name' => 'نام', 'email' => 'ایمیل',
        'phone' => 'تلفن (اختیاری)', 'note' => 'یادداشت یا گزینه‌های مدنظر (اختیاری)', 'submit' => 'ارسال درخواست خرید',
        'privacy' => 'این فرم درخواست خرید شما را برای پیگیری می‌فرستد؛ ثبت نهایی سفارش و پرداخت پس از هماهنگی انجام می‌شود.',
        'consent' => 'می‌پذیرم اطلاعات تماس من برای پیگیری درخواست خریدم استفاده شود.',
        'sent' => 'درخواست خرید شما ارسال شد. تیم ما برای پیگیری با شما تماس می‌گیرد.',
        'account' => 'حساب کاربری', 'no_price' => 'قیمت، موجودی و زمان ارسال پس از بررسی جداگانه تأیید می‌شوند.',
    ) : array(
        'title' => 'Shopping Cart', 'eyebrow' => 'YOUR SELECTION', 'intro' => 'Collect the products you would like to purchase and send a purchase request to our team.',
        'home' => 'Back to the site', 'empty' => 'Your shopping cart is empty', 'empty_copy' => 'Browse the collection and add the products you would like to purchase. You can adjust quantities at any time.',
        'browse' => 'Browse products', 'selected' => 'Selected products', 'quantity' => 'Quantity', 'remove' => 'Remove', 'total' => 'items',
        'request_title' => 'Purchase request', 'request_copy' => 'Leave your contact details so our team can follow up about purchasing the selected products.',
        'summary_empty' => 'Add a product to unlock the purchase-request form.', 'name' => 'Name', 'email' => 'Email',
        'phone' => 'Phone (optional)', 'note' => 'Note or preferred options (optional)', 'submit' => 'Send purchase request',
        'privacy' => 'This sends a purchase request only; it is not a confirmed order and no payment is taken.',
        'consent' => 'I agree that my contact details may be used to follow up on this purchase request.',
        'sent' => 'Your purchase request has been sent. Our team will follow up with you.',
        'account' => 'Account', 'no_price' => 'Prices, availability and delivery timing are confirmed separately by our team.',
    );

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="' . esc_attr($lang) . '"' . $rtl . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><title>' . esc_html($t['title']) . '</title><link rel="stylesheet" href="' . esc_url($theme_uri . '/static-site/assets/css/style.css') . '">';
    if ($is_fa) { echo '<link rel="stylesheet" href="' . esc_url($theme_uri . '/static-site/assets/css/rtl.css') . '">'; }
    echo <<<'CSS'
<style>
[hidden]{display:none!important}
:root{--ta-green:#254a3a;--ta-green-dark:#183a2d;--ta-ink:#18221d;--ta-muted:#6f7771;--ta-line:#e8e8e2;--ta-paper:#fff;--ta-canvas:#f5f4f0}
body{margin:0;background:var(--ta-canvas);color:var(--ta-ink)}
.ta-cart-page{min-height:100vh;padding:0 24px 64px;font-family:var(--font-sans,Arial,sans-serif)}
.ta-cart-wrap{width:min(100%,1120px);margin:0 auto}
.ta-cart-head{height:88px;display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:1px solid rgba(32,48,39,.09);margin-bottom:48px}
.ta-cart-logo{display:block;width:176px;max-height:60px;object-fit:contain;mix-blend-mode:multiply;background:transparent}
.ta-cart-nav{display:flex;align-items:center;gap:10px}.ta-cart-nav a{display:inline-flex;align-items:center;min-height:40px;padding:0 15px;border:1px solid transparent;border-radius:999px;color:#39463e;text-decoration:none;font-size:14px;font-weight:600;transition:background .2s,border-color .2s}.ta-cart-nav a:hover{background:#ecefe9;border-color:#e1e6de}
.ta-cart-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:30px;margin-bottom:30px}.ta-cart-eyebrow{margin:0 0 9px;color:#788274;font-size:11px;font-weight:700;letter-spacing:.16em}.ta-cart-title{margin:0 0 10px;color:var(--ta-ink);font-size:clamp(32px,4.3vw,48px);font-weight:700;line-height:1.08;letter-spacing:-.035em}.ta-cart-intro{max-width:650px;margin:0;color:var(--ta-muted);font-size:16px;line-height:1.7}.ta-cart-count{display:inline-flex;align-items:center;gap:9px;padding:12px 16px;border:1px solid #e1e5dc;border-radius:999px;background:#fbfcf9;color:#405145;font-size:13px;font-weight:650;white-space:nowrap}.ta-cart-count svg{width:17px;height:17px;color:var(--ta-green)}
.ta-cart-layout{display:grid;grid-template-columns:minmax(0,1fr) 370px;gap:22px;align-items:start}.ta-cart-panel,.ta-cart-aside{background:var(--ta-paper);border:1px solid rgba(34,49,39,.08);border-radius:20px;box-shadow:0 14px 42px rgba(23,36,29,.045)}.ta-cart-panel{min-height:430px;padding:26px 28px}.ta-cart-panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-bottom:20px;border-bottom:1px solid var(--ta-line)}.ta-cart-panel-head h2{margin:0;font-size:17px;font-weight:700}.ta-cart-panel-head span{color:#8b928c;font-size:13px}
.ta-cart-empty{min-height:315px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:34px 24px;text-align:center}.ta-cart-empty__icon{width:76px;height:76px;display:grid;place-items:center;margin-bottom:21px;border-radius:50%;background:#f1f3ee;color:#536c59}.ta-cart-empty__icon svg{width:33px;height:33px}.ta-cart-empty h3{margin:0 0 9px;font-size:20px;font-weight:700}.ta-cart-empty p{max-width:430px;margin:0 0 22px;color:var(--ta-muted);font-size:14px;line-height:1.75}.ta-cart-button{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-height:46px;padding:0 20px;border:1px solid var(--ta-green);border-radius:999px;background:var(--ta-green);color:#fff!important;text-decoration:none;font:inherit;font-size:14px;font-weight:650;transition:background .2s,transform .2s}.ta-cart-button:hover{background:var(--ta-green-dark);transform:translateY(-1px)}
.ta-cart-items{display:grid;gap:0;padding:0;margin:0;list-style:none}.ta-cart-item{display:grid;grid-template-columns:52px minmax(0,1fr) auto auto;align-items:center;gap:16px;padding:20px 0;border-bottom:1px solid var(--ta-line)}.ta-cart-item:last-child{border-bottom:0}.ta-cart-item__mark{width:48px;height:48px;display:grid;place-items:center;border-radius:13px;background:#f2f4ef;color:#536c59}.ta-cart-item__mark svg{width:22px;height:22px}.ta-cart-item__name{display:block;margin-bottom:4px;color:#25362b;text-decoration:none;font-size:15px;font-weight:700;line-height:1.45}.ta-cart-item__name:hover{color:var(--ta-green)}.ta-cart-item__caption{color:#8a918b;font-size:12px}.ta-cart-item__qty{display:flex;align-items:center;gap:8px;color:#727b74;font-size:12px;white-space:nowrap}.ta-cart-item__qty input{width:60px;height:38px;box-sizing:border-box;padding:4px 8px;border:1px solid #e1e4dd;border-radius:9px;background:#fff;color:#26342c;text-align:center;font:inherit;font-size:14px}.ta-cart-remove{padding:8px;border:0;background:transparent;color:#8a6b67;text-decoration:none;font:inherit;font-size:12px;cursor:pointer}.ta-cart-remove:hover{color:#a23d32;text-decoration:underline}.ta-cart-summary-line{margin:16px 0 0;padding-top:15px;border-top:1px solid var(--ta-line);color:#59665c;font-size:13px}
.ta-cart-aside{position:sticky;top:24px;padding:26px}.ta-cart-aside__icon{width:42px;height:42px;display:grid;place-items:center;margin-bottom:15px;border-radius:13px;background:#edf2ec;color:var(--ta-green)}.ta-cart-aside__icon svg{width:20px;height:20px}.ta-cart-aside h2{margin:0 0 8px;font-size:20px;font-weight:700}.ta-cart-aside__copy{margin:0 0 20px;color:var(--ta-muted);font-size:13px;line-height:1.65}.ta-cart-summary-empty{padding:16px;border-radius:12px;background:#f7f8f5;color:#737d74;font-size:13px;line-height:1.6}.ta-cart-form{padding-top:18px;border-top:1px solid var(--ta-line)}.ta-cart-fields{display:grid;grid-template-columns:1fr;gap:0}.ta-cart-field{display:block;margin:0 0 13px}.ta-cart-field span{display:block;margin-bottom:6px;color:#566158;font-size:12px;font-weight:650}.ta-cart-field input,.ta-cart-field textarea{box-sizing:border-box;width:100%;min-height:43px;padding:10px 12px;border:1px solid #dfe3dc;border-radius:10px;background:#fff;color:#202a24;font:inherit;font-size:14px;transition:border-color .2s,box-shadow .2s}.ta-cart-field input:focus,.ta-cart-field textarea:focus{outline:none;border-color:#73927b;box-shadow:0 0 0 3px rgba(72,112,81,.1)}.ta-cart-field textarea{min-height:88px;resize:vertical}.ta-cart-consent{display:flex;align-items:flex-start;gap:9px;margin:14px 0;color:#5d675f;font-size:12px;line-height:1.6}.ta-cart-consent input{margin:3px 0 0;accent-color:var(--ta-green)}.ta-cart-consent a{color:var(--ta-green)}.ta-cart-submit{width:100%;min-height:48px;padding:10px 17px;border:0;border-radius:999px;background:var(--ta-green);color:#fff;font:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s}.ta-cart-submit:hover{background:var(--ta-green-dark)}.ta-cart-legal{display:flex;gap:9px;align-items:flex-start;margin:15px 0 0;color:#788078;font-size:11px;line-height:1.6}.ta-cart-legal svg{width:15px;height:15px;flex:0 0 15px;margin-top:1px;color:#67816b}.ta-cart-success,.ta-cart-error{padding:14px 17px;margin:0 0 22px;border:1px solid transparent;border-radius:12px;font-size:14px;line-height:1.6}.ta-cart-success{border-color:#d6e8d8;background:#edf7ee;color:#275b34}.ta-cart-error{border-color:#efd8d4;background:#fff2ef;color:#963c31}.ta-cart-footer{margin-top:28px;color:#9aa099;font-size:12px;text-align:center}
@media(max-width:900px){.ta-cart-layout{grid-template-columns:minmax(0,1fr) 330px;gap:16px}.ta-cart-panel{padding:22px}.ta-cart-aside{padding:22px}}
@media(max-width:700px){.ta-cart-page{padding:0 16px 38px}.ta-cart-head{height:74px;margin-bottom:31px}.ta-cart-logo{width:145px;max-height:52px}.ta-cart-hero{align-items:flex-start;flex-direction:column;gap:18px;margin-bottom:21px}.ta-cart-title{font-size:36px}.ta-cart-intro{font-size:14px}.ta-cart-layout{grid-template-columns:1fr}.ta-cart-aside{position:static}.ta-cart-panel{min-height:300px;padding:18px}.ta-cart-empty{min-height:250px;padding:28px 14px}.ta-cart-item{grid-template-columns:42px minmax(0,1fr) auto;gap:11px;padding:16px 0}.ta-cart-item__mark{width:40px;height:40px}.ta-cart-item__qty{grid-column:2;justify-self:start}.ta-cart-remove{grid-column:3;grid-row:1 / span 2}.ta-cart-nav a{min-height:36px;padding:0 10px;font-size:12px}}
@media(max-width:420px){.ta-cart-page{padding-inline:12px}.ta-cart-logo{width:124px}.ta-cart-nav{gap:2px}.ta-cart-nav a{padding:0 8px}.ta-cart-title{font-size:31px}.ta-cart-count{padding:10px 13px}}
</style></head>
CSS;
    echo '<body><main class="ta-cart-page"><div class="ta-cart-wrap" data-takinta-cart-root data-locale="' . esc_attr($lang) . '"><header class="ta-cart-head"><a href="' . esc_url($home_url) . '" aria-label="Takinta"><img class="ta-cart-logo" src="' . esc_url($logo) . '" alt="Takinta"></a><nav class="ta-cart-nav"><a href="' . esc_url(takinta_account_url($is_fa)) . '">' . esc_html($t['account']) . '</a><a href="' . esc_url($home_url) . '">' . esc_html($t['home']) . '</a></nav></header><section class="ta-cart-hero"><div><p class="ta-cart-eyebrow">' . esc_html($t['eyebrow']) . '</p><h1 class="ta-cart-title">' . esc_html($t['title']) . '</h1><p class="ta-cart-intro">' . esc_html($t['intro']) . '</p></div><div class="ta-cart-count" data-cart-summary hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.1 10.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L20 8H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg><span data-cart-total>0</span> ' . esc_html($t['total']) . '</div></section>';
    if ($sent) { echo '<p class="ta-cart-success" role="status">' . esc_html($t['sent']) . '</p>'; }
    if ($error !== '') { echo '<p class="ta-cart-error" role="alert">' . esc_html($error) . '</p>'; }
    echo '<div class="ta-cart-layout"><section class="ta-cart-panel"><header class="ta-cart-panel-head"><h2>' . esc_html($t['selected']) . '</h2><span>' . esc_html($is_fa ? 'قیمت پس از هماهنگی' : 'Price confirmed after request') . '</span></header><div class="ta-cart-empty" data-cart-empty hidden><div class="ta-cart-empty__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h16l-1.5 12h-13L4 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/><path d="M9.5 13h5"/></svg></div><h3>' . esc_html($t['empty']) . '</h3><p>' . esc_html($t['empty_copy']) . '</p><a class="ta-cart-button" href="' . esc_url($browse_url) . '">' . esc_html($t['browse']) . '</a></div><ul class="ta-cart-items" data-cart-items></ul></section><aside class="ta-cart-aside"><div class="ta-cart-aside__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="m4 7 8 6 8-6"/></svg></div><h2>' . esc_html($t['request_title']) . '</h2><p class="ta-cart-aside__copy">' . esc_html($t['request_copy']) . '</p><p class="ta-cart-summary-empty" data-cart-summary-empty>' . esc_html($t['summary_empty']) . '</p><form class="ta-cart-form" data-cart-form method="post" action="' . esc_url($cart_url) . '" hidden>' . $nonce . '<input type="hidden" name="takinta_cart_action" value="submit_quote"><input type="hidden" name="cart_payload" data-cart-payload value=""><div class="ta-cart-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div><div class="ta-cart-fields"><label class="ta-cart-field"><span>' . esc_html($t['name']) . '</span><input name="name" autocomplete="name" required></label><label class="ta-cart-field"><span>' . esc_html($t['email']) . '</span><input type="email" name="email" autocomplete="email" required></label><label class="ta-cart-field"><span>' . esc_html($t['phone']) . '</span><input name="phone" autocomplete="tel"></label></div><label class="ta-cart-field"><span>' . esc_html($t['note']) . '</span><textarea name="message" maxlength="4000"></textarea></label><label class="ta-cart-consent"><input type="checkbox" name="privacy_consent" value="1" required><span>' . esc_html($t['consent']) . ($privacy_url ? ' <a href="' . esc_url($privacy_url) . '" target="_blank" rel="noopener">' . esc_html($is_fa ? 'سیاست حریم خصوصی' : 'Privacy policy') . '</a>' : '') . '</span></label><button class="ta-cart-submit" type="submit">' . esc_html($t['submit']) . '</button></form><p class="ta-cart-legal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v5c0 5 3.4 8.3 8 10 4.6-1.7 8-5 8-10V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg><span>' . esc_html($t['privacy']) . '</span></p></aside></div><p class="ta-cart-footer">' . esc_html($t['no_price']) . '</p></div></main>' . takinta_cart_runtime_assets($is_fa) . '</body></html>';
}

function takinta_cart_runtime_assets($is_fa) {
    $labels = wp_json_encode(array(
        'added' => $is_fa ? 'به سبد خرید اضافه شد.' : 'Added to your shopping cart.',
        'remove' => $is_fa ? 'حذف' : 'Remove',
        'quantity' => $is_fa ? 'تعداد' : 'Quantity',
        'empty' => $is_fa ? 'سبد خریدت هنوز خالی است.' : 'Your shopping cart is empty.',
        'cart' => $is_fa ? 'سبد خرید' : 'Shopping cart',
        'storageError' => $is_fa ? 'مرورگر اجازهٔ ذخیرهٔ سبد را نمی‌دهد.' : 'Your browser could not save the cart.',
        'maxItems' => $is_fa ? 'سبد حداکثر ۳۰ کالای متفاوت دارد.' : 'The cart can contain up to 30 different products.',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $script = '<script>(function(){"use strict";var KEY="takintaInquiryCartV1",L=' . $labels . ';function read(){try{var v=JSON.parse(localStorage.getItem(KEY)||"[]");if(!Array.isArray(v))return[];return v.map(function(x){if(!x||typeof x.id!=="string"||typeof x.route!=="string"||typeof x.url!=="string")return null;var u=new URL(x.url,location.href);if(u.origin!==location.origin)return null;var q=Math.max(1,Math.min(999,parseInt(x.quantity,10)||1));return{id:x.id,route:x.route,title:String(x.title||x.id).slice(0,240),url:u.href,quantity:q};}).filter(Boolean).slice(0,30);}catch(e){return[];}}function total(c){return c.reduce(function(n,x){return n+x.quantity;},0);}function paintBadges(c){var n=total(c);document.querySelectorAll("[data-takinta-cart-count]").forEach(function(x){x.textContent=n.toLocaleString(document.documentElement.lang==="fa"?"fa-IR":"en-US");});document.querySelectorAll("[data-takinta-cart-link]").forEach(function(x){x.setAttribute("aria-label",L.cart+", "+n);});}function save(c){try{localStorage.setItem(KEY,JSON.stringify(c));paintBadges(c);render();return true;}catch(e){return false;}}function render(){var root=document.querySelector("[data-takinta-cart-root]");if(!root)return;var c=read(),list=root.querySelector("[data-cart-items]"),empty=root.querySelector("[data-cart-empty]"),form=root.querySelector("[data-cart-form]"),summary=root.querySelector("[data-cart-summary]"),summaryEmpty=root.querySelector("[data-cart-summary-empty]");if(!list)return;list.replaceChildren();if(empty)empty.hidden=c.length>0;if(form)form.hidden=c.length===0;if(summary)summary.hidden=c.length===0;if(summaryEmpty)summaryEmpty.hidden=c.length>0;root.querySelectorAll("[data-cart-total]").forEach(function(n){n.textContent=total(c).toLocaleString(document.documentElement.lang==="fa"?"fa-IR":"en-US");});c.forEach(function(item){var li=document.createElement("li");li.className="ta-cart-item";var mark=document.createElement("span");mark.className="ta-cart-item__mark";mark.setAttribute("aria-hidden","true");mark.innerHTML="<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.6\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M4 8h16l-1.5 12h-13L4 8Z\"/><path d=\"M9 9V6a3 3 0 0 1 6 0v3\"/></svg>";var a=document.createElement("a");a.className="ta-cart-item__name";a.href=item.url;a.textContent=item.title;var qty=document.createElement("label");qty.className="ta-cart-item__qty";var text=document.createElement("span");text.textContent=L.quantity;var input=document.createElement("input");input.type="number";input.min="1";input.max="999";input.value=String(item.quantity);input.setAttribute("data-cart-quantity",item.id);input.setAttribute("aria-label",L.quantity+": "+item.title);qty.append(text,input);var remove=document.createElement("button");remove.type="button";remove.className="ta-cart-remove";remove.setAttribute("data-cart-remove",item.id);remove.textContent=L.remove;li.append(mark,a,qty,remove);list.appendChild(li);});paintBadges(c);}document.querySelectorAll("[data-takinta-add-to-cart]").forEach(function(button){button.addEventListener("click",function(){var id=button.getAttribute("data-takinta-product-id"),route=button.getAttribute("data-takinta-product-route"),title=button.getAttribute("data-takinta-product-title"),url=button.getAttribute("data-takinta-product-url"),wrap=button.closest("[data-takinta-cart-controls]"),input=wrap&&wrap.querySelector("[data-takinta-cart-quantity]"),qty=Math.max(1,Math.min(999,parseInt(input&&input.value,10)||1));if(!id||!route||!title||!url)return;var c=read(),found=c.find(function(x){return x.id===id;});if(found)found.quantity=Math.min(999,found.quantity+qty);else if(c.length<30)c.push({id:id,route:route,title:title,url:url,quantity:qty});else{var status=wrap.querySelector("[data-takinta-cart-status]");if(status)status.textContent=L.maxItems;return;}if(!save(c)){var status2=wrap.querySelector("[data-takinta-cart-status]");if(status2)status2.textContent=L.storageError;return;}var status=wrap.querySelector("[data-takinta-cart-status]");if(status)status.textContent=L.added;});});var root=document.querySelector("[data-takinta-cart-root]");if(root){if(new URLSearchParams(location.search).get("cart_status")==="sent"){try{localStorage.removeItem(KEY);}catch(e){}}root.addEventListener("click",function(e){var b=e.target.closest("[data-cart-remove]");if(!b)return;save(read().filter(function(x){return x.id!==b.getAttribute("data-cart-remove");}));});root.addEventListener("change",function(e){var input=e.target.closest("[data-cart-quantity]");if(!input)return;var id=input.getAttribute("data-cart-quantity"),q=Math.max(1,Math.min(999,parseInt(input.value,10)||1));save(read().map(function(x){if(x.id===id)x.quantity=q;return x;}));});var form=root.querySelector("[data-cart-form]");if(form)form.addEventListener("submit",function(e){var c=read();if(!c.length){e.preventDefault();return;}form.querySelector("[data-cart-payload]").value=JSON.stringify(c.map(function(x){return{id:x.id,route:x.route,quantity:x.quantity};}));});}paintBadges(read());render();})();</script>';
    return '<style>.takinta-cart-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px}.takinta-cart-qty{display:inline-flex;align-items:center;gap:7px;font-size:14px}.takinta-cart-qty input{width:76px;min-height:42px;padding:7px;border:1px solid #c8cdc8;border-radius:7px;font:inherit}.takinta-cart-controls .pp-button{cursor:pointer}.takinta-cart-status{flex-basis:100%;min-height:1.4em;font-size:14px;color:#265b3d}.cart-badge[data-takinta-cart-count]{min-width:1.2em;text-align:center}</style>' . $script;
}

function takinta_wc_product_post_for_static_route($route) {
    $slug = sanitize_title(basename((string) $route, '.html'));
    if ($slug === '') { return false; }
    $args = array(
        'post_type' => 'product',
        'post_status' => array('publish', 'draft', 'pending', 'private', 'future', 'trash'),
        'numberposts' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_key' => '_takinta_catalog_slug',
        'meta_value' => $slug,
    );
    $matches = get_posts($args);
    if (!empty($matches)) { return get_post((int) $matches[0]); }
    return get_page_by_path($slug, OBJECT, 'product');
}

function takinta_wc_product_for_static_route($route) {
    if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) { return false; }
    $post = takinta_wc_product_post_for_static_route($route);
    if (!$post || $post->post_status !== 'publish') { return false; }
    $product = wc_get_product($post->ID);
    if (!$product || $product->get_price() === '' || !$product->is_purchasable() || !$product->is_in_stock()) { return false; }
    return $product;
}

function takinta_wc_static_order_status_message($route, $is_fa) {
    if (!class_exists('WooCommerce')) { return ''; }
    $post = takinta_wc_product_post_for_static_route($route);
    if (!$post) {
        return $is_fa ? 'این محصول هنوز در WooCommerce وارد نشده است.' : 'This product has not been imported into WooCommerce yet.';
    }
    if ($post->post_status !== 'publish') {
        return $is_fa ? 'این محصول هنوز پیش‌نویس است؛ قیمت تأییدشده را وارد و محصول را منتشر کنید.' : 'This product is still a draft. Enter its approved price and publish it.';
    }
    $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : false;
    if (!$product || $product->get_price() === '') {
        return $is_fa ? 'برای سفارش، قیمت تأییدشده را در ویرایش محصول وارد کنید.' : 'Enter an approved price for this product to enable ordering.';
    }
    if (!$product->is_in_stock()) {
        return $is_fa ? 'این محصول فعلاً ناموجود است؛ وضعیت موجودی را در WooCommerce بررسی کنید.' : 'This product is currently out of stock. Check its WooCommerce availability.';
    }
    return $is_fa ? 'وضعیت سفارش‌پذیری محصول را در WooCommerce بررسی کنید.' : 'Check this product’s WooCommerce purchase settings.';
}

function takinta_wc_static_order_controls($product, $route, $is_fa) {
    $quantity_label = $is_fa ? 'تعداد' : 'Qty';
    if ($product) {
        $button_label = $is_fa ? 'افزودن به سبد خرید' : 'Add to shopping cart';
        $price_html = wp_kses_post($product->get_price_html());
        return '<div class="takinta-wc-order-controls"><div class="takinta-wc-product-price">' . $price_html . '</div><form class="takinta-cart-controls" method="get" action="' . esc_url(takinta_cart_url($is_fa)) . '"><label class="takinta-cart-qty"><span>' . esc_html($quantity_label) . '</span><input type="number" name="quantity" min="1" max="999" value="1" inputmode="numeric" aria-label="' . esc_attr($quantity_label) . '"></label><input type="hidden" name="add-to-cart" value="' . esc_attr((string) $product->get_id()) . '"><input type="hidden" name="takinta_wc_add_to_cart" value="1"><button type="submit" class="pp-button pp-button--dark">' . esc_html($button_label) . '</button></form></div>';
    }
    $message = takinta_wc_static_order_status_message($route, $is_fa);
    return '<p class="takinta-wc-order-pending" role="status">' . esc_html($message) . '</p>';
}

function takinta_wc_static_order_styles() {
    return '<style>.takinta-wc-order-controls{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 12px}.takinta-wc-product-price{font-size:20px;font-weight:700;color:var(--ta-ink,#26342c)}.takinta-wc-order-controls form{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0}.takinta-wc-order-controls .takinta-cart-qty{display:inline-flex;align-items:center;gap:7px;font-size:14px}.takinta-wc-order-controls .takinta-cart-qty input{width:76px;min-height:42px;padding:7px;border:1px solid #c8cdc8;border-radius:7px;font:inherit}.takinta-wc-order-controls .pp-button{cursor:pointer}.takinta-wc-order-pending{margin:0 0 12px;color:#59665c;font-size:14px;line-height:1.6}</style>';
}

function takinta_wc_static_add_to_cart_redirect($url, $product) {
    if (isset($_REQUEST['takinta_wc_add_to_cart']) && function_exists('wc_get_cart_url')) {
        $cart_url = wc_get_cart_url();
        if (isset($_REQUEST['lang'])) {
            $language = sanitize_key(wp_unslash($_REQUEST['lang']));
            if (in_array($language, array('fa', 'en'), true)) { $cart_url = add_query_arg('lang', $language, $cart_url); }
        }
        return $cart_url;
    }
    return $url;
}
add_filter('woocommerce_add_to_cart_redirect', 'takinta_wc_static_add_to_cart_redirect', 10, 2);

function takinta_cart_transform_static_document($document, $route) {
    $is_fa = strpos($route, 'fa/') === 0;
    $cart_url = takinta_cart_url($is_fa);
    if (preg_match('#^(?:fa/)?products/[a-z0-9][a-z0-9-]*\.html$#i', $route) && strpos($document, 'data-product-quote') !== false) {
        $title = basename($route, '.html');
        if (preg_match('~<h1\b[^>]*\bid="product-title"[^>]*>(.*?)</h1>~is', $document, $title_match)) {
            $decoded = html_entity_decode(trim(wp_strip_all_tags($title_match[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded !== '') { $title = $decoded; }
        }
        $canonical_route = strpos($route, 'fa/') === 0 ? substr($route, 3) : $route;
        $button_label = $is_fa ? 'افزودن به سبد خرید' : 'Add to shopping cart';
        $quantity_label = $is_fa ? 'تعداد' : 'Qty';
        if (class_exists('WooCommerce')) {
            $document = preg_replace('~<a\\b(?=[^>]*\\bdata-product-quote\\b)[^>]*>.*?</a\\s*>~is', '', $document, 1);
            $orderable_product = takinta_wc_product_for_static_route($canonical_route);
            $controls = takinta_wc_static_order_controls($orderable_product, $canonical_route, $is_fa);
            if ($orderable_product) {
                $pricing_note = $is_fa ? 'هزینهٔ ارسال و مالیات بر اساس تنظیمات checkout محاسبه می‌شوند.' : 'Shipping and taxes follow the store settings shown at checkout.';
                $note_written = false;
                $document = preg_replace_callback('~<p\\b(?=[^>]*\\bclass="[^"]*\\b(?:pp-market-note|pp-tax-note)\\b[^"]*")[^>]*>.*?</p\\s*>~is', function($match) use ($pricing_note, &$note_written) {
                    if ($note_written) { return ''; }
                    $note_written = true;
                    return '<p class="pp-market-note">' . esc_html($pricing_note) . '</p>';
                }, $document);
            } else {
                $document = preg_replace('~<p\\b(?=[^>]*\\bclass="[^"]*\\b(?:pp-market-note|pp-tax-note)\\b[^"]*")[^>]*>.*?</p\\s*>~is', '', $document);
            }
        } else {
            $controls = '<div class="takinta-cart-controls" data-takinta-cart-controls><label class="takinta-cart-qty"><span>' . esc_html($quantity_label) . '</span><input type="number" data-takinta-cart-quantity min="1" max="999" value="1" inputmode="numeric" aria-label="' . esc_attr($quantity_label) . '"></label><button type="button" class="pp-button pp-button--dark" data-takinta-add-to-cart data-takinta-product-id="' . esc_attr($canonical_route) . '" data-takinta-product-route="' . esc_attr($route) . '" data-takinta-product-title="' . esc_attr($title) . '" data-takinta-product-url="' . esc_url(takinta_editable_public_url($route)) . '">' . esc_html($button_label) . '</button><span class="takinta-cart-status" data-takinta-cart-status aria-live="polite"></span></div>';
        }
        $document = preg_replace_callback('~<div\b(?=[^>]*\bclass="[^"]*\bpp-actions\b[^"]*")[^>]*>~i', function($match) use ($controls) { return $match[0] . $controls; }, $document, 1);
    }

    $initial_cart_count = 0;
    if (class_exists('WooCommerce') && function_exists('WC') && WC()->cart) {
        $initial_cart_count = (int) WC()->cart->get_cart_contents_count();
    }
    $document = preg_replace_callback('~(<button\b(?=[^>]*\bdata-demo="Cart")[^>]*>)(.*?)</button\s*>~is', function($matches) use ($cart_url, $is_fa, $initial_cart_count) {
        $class = 'icon-btn';
        if (preg_match('~\bclass="([^"]*)"~i', $matches[1], $class_match)) { $class = $class_match[1]; }
        $inner = preg_replace_callback('~<span\b(?=[^>]*\bclass="[^"]*\bcart-badge\b[^"]*")[^>]*>.*?</span>~is', function($badge) use ($initial_cart_count) {
            $opening = strstr($badge[0], '>', true);
            if ($opening === false) { return '<span class="cart-badge" data-takinta-cart-count>' . esc_html((string) $initial_cart_count) . '</span>'; }
            if (strpos($opening, 'data-takinta-cart-count') === false) { $opening .= ' data-takinta-cart-count="1"'; }
            return $opening . '>' . esc_html((string) $initial_cart_count) . '</span>';
        }, $matches[2], 1);
        if (strpos($inner, 'data-takinta-cart-count') === false) { $inner .= '<span class="cart-badge" data-takinta-cart-count>' . esc_html((string) $initial_cart_count) . '</span>'; }
        $label = $is_fa ? 'سبد خرید' : 'Shopping cart';
        return '<a class="' . esc_attr($class) . '" href="' . esc_url($cart_url) . '" aria-label="' . esc_attr($label) . '" data-takinta-cart-link>' . $inner . '</a>';
    }, $document);

    $mobile_label = $is_fa ? 'سبد خرید' : 'Shopping cart';
    return preg_replace_callback('~(<ul\b(?=[^>]*\bclass="[^"]*\bmobile-menu__utility\b[^"]*")[^>]*>.*?)(</ul>)~is', function($matches) use ($cart_url, $mobile_label) {
        return $matches[1] . '<li><a href="' . esc_url($cart_url) . '" data-takinta-cart-link>' . esc_html($mobile_label) . '</a></li>' . $matches[2];
    }, $document, 1);
}

function takinta_wc_render_cart_shortcode_fallback($is_fa) {
    if (!class_exists('WooCommerce') || !function_exists('do_shortcode') || !function_exists('get_header') || !function_exists('get_footer')) { return false; }
    $theme_uri = untrailingslashit(get_template_directory_uri());
    if (function_exists('wp_enqueue_style')) {
        wp_enqueue_style('woocommerce-general');
        wp_enqueue_style('takinta-wc-cart-fallback', $theme_uri . '/static-site/assets/css/style.css', array(), '1.0.4');
        if ($is_fa) { wp_enqueue_style('takinta-wc-cart-fallback-rtl', $theme_uri . '/static-site/assets/css/rtl.css', array('takinta-wc-cart-fallback'), '1.0.4'); }
    }
    get_header();
    $direction = $is_fa ? 'rtl' : 'ltr';
    $title = $is_fa ? 'سبد خرید' : 'Shopping cart';
    $home = home_url($is_fa ? '/fa/' : '/');
    $logo = $theme_uri . '/static-site/images/logo.png';
    echo '<header class="site-header"><div class="container site-header__main"><a class="site-header__logo" href="' . esc_url($home) . '"><img class="site-header__logo-img" src="' . esc_url($logo) . '" alt="Takinta"></a></div></header>';
    echo '<main class="woocommerce takinta-wc-cart-fallback" dir="' . esc_attr($direction) . '" style="max-width:1200px;margin:40px auto;padding:0 20px"><h1>' . esc_html($title) . '</h1>' . do_shortcode('[woocommerce_cart]') . '</main>';
    get_footer();
    exit;
}

function takinta_wc_persian_checkout_route_redirect() {
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || !function_exists('wc_get_checkout_url')) { return; }
    $request_path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $request_path = (string) parse_url($request_path, PHP_URL_PATH);
    $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);
    $home_path = trim($home_path, '/');
    if ($home_path !== '' && strpos(trim($request_path, '/'), $home_path . '/') === 0) {
        $request_path = trim(substr(trim($request_path, '/'), strlen($home_path) + 1), '/');
    } else {
        $request_path = trim($request_path, '/');
    }
    if ($request_path !== 'fa/checkout') { return; }
    wp_safe_redirect(add_query_arg('lang', 'fa', wc_get_checkout_url()));
    exit;
}
add_action('template_redirect', 'takinta_wc_persian_checkout_route_redirect', -3);

function takinta_account_handle_route() {
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
    $route = takinta_account_route_from_request();
    if (!$route) { return; }
    $is_fa = (bool) $route['fa'];
    if (!$is_fa && isset($_GET['lang']) && sanitize_key(wp_unslash($_GET['lang'])) === 'fa') { $is_fa = true; }
    if (!$is_fa && isset($_COOKIE['takinta_commerce_language']) && sanitize_key(wp_unslash($_COOKIE['takinta_commerce_language'])) === 'fa') { $is_fa = true; }
    if ($route['page'] === 'cart') {
        if (class_exists('WooCommerce') && function_exists('wc_get_page_id') && function_exists('wc_get_cart_url')) {
            $wc_cart_page_id = (int) wc_get_page_id('cart');
            if ($wc_cart_page_id > 0) {
                if (is_page($wc_cart_page_id)) { return; } // The configured page must render even when lang=fa is present.
                $cart_url = takinta_cart_url($is_fa);
                $target_path = untrailingslashit((string) parse_url($cart_url, PHP_URL_PATH));
                $request_path = untrailingslashit((string) parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
                if ($target_path !== $request_path) {
                    wp_safe_redirect($cart_url);
                    exit;
                }
                // If the configured URL already matches this route but WordPress did not resolve its page, render a fallback instead of redirecting to itself.
                takinta_wc_render_cart_shortcode_fallback($is_fa);
            }
            takinta_wc_render_cart_shortcode_fallback($is_fa);
        }
        $error = takinta_cart_process_submission($is_fa);
        takinta_cart_render_page($is_fa, $error);
        exit;
    }
    $error = takinta_account_process_submission($route['fa'], $route['page']);
    takinta_account_render_page($route['fa'], $route['page'], $error);
    exit;
}
add_action('template_redirect', 'takinta_account_handle_route', -2);

function takinta_account_link_in_static_document($document, $route) {
    $is_fa = strpos($route, 'fa/') === 0;
    $url = takinta_account_url($is_fa);
    $document = preg_replace_callback('~(<button\b(?=[^>]*\bdata-demo="Account")[^>]*>)(.*?)</button\s*>~is', function($matches) use ($url, $is_fa) {
        $class = 'icon-btn hide-sm';
        $label = $is_fa ? 'حساب کاربری' : 'My account';
        if (preg_match('~\bclass="([^"]*)"~i', $matches[1], $class_match)) { $class = $class_match[1]; }
        if (preg_match('~\baria-label="([^"]*)"~i', $matches[1], $label_match)) { $label = $label_match[1]; }
        return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '" aria-label="' . esc_attr($label) . '">' . $matches[2] . '</a>';
    }, $document);
    $mobile_label = $is_fa ? 'حساب کاربری' : 'My account';
    return preg_replace_callback('~(<ul\b(?=[^>]*\bclass="[^"]*\bmobile-menu__utility\b[^"]*")[^>]*>.*?)(</ul>)~is', function($matches) use ($url, $mobile_label) {
        return $matches[1] . '<li><a href="' . esc_url($url) . '">' . esc_html($mobile_label) . '</a></li>' . $matches[2];
    }, $document, 1);
}

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
    $document = '';
    $post_id = takinta_editable_find_post_by_route($route);
    if ($post_id) {
        $stored_document = get_post_meta($post_id, TAKINTA_EDITABLE_HTML_META, true);
        if (is_string($stored_document)) { $document = $stored_document; }
    }

    // Keep the static theme usable before import and as a fallback for any skipped page.
    if ($document === '') {
        $root = realpath(takinta_editable_static_root());
        $file = $root ? realpath($root . DIRECTORY_SEPARATOR . $route) : false;
        if (!$root || !$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) { return; }
        $document = file_get_contents($file);
        if (!is_string($document) || $document === '') { return; }
    }

    $theme_uri = untrailingslashit(get_template_directory_uri());
    $home_url = untrailingslashit(home_url('/'));
    $document = str_replace('__TAKINTA_THEME_URI__', esc_url_raw($theme_uri), $document);
    $document = str_replace('__TAKINTA_HOME_URL__', esc_url_raw($home_url), $document);
    $document = str_replace(array('https://www.example.com', 'http://www.example.com'), $home_url, $document);
    $document = str_replace($home_url . '/images/', $theme_uri . '/static-site/images/', $document);
    $document = takinta_account_link_in_static_document($document, $route);
    $document = takinta_cart_transform_static_document($document, $route);
    // Bust stale browser/CDN copies of the old placeholder search handler on imported pages.
    $document = preg_replace_callback("~(<script\\b[^>]*\\bsrc\\s*=\\s*)([\"'])([^\"']*assets/js/main\\.js)(?:\\?[^\"']*)?([\"'])~i", function($matches) {
        return $matches[1] . $matches[2] . $matches[3] . '?v=20261102-search' . $matches[4];
    }, $document);
    $runtime_assets = class_exists('WooCommerce') ? takinta_wc_static_order_styles() : takinta_cart_runtime_assets(strpos($route, 'fa/') === 0);
    $document = preg_replace('~</body>~i', $runtime_assets . '</body>', $document, 1);

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Original static HTML, editable only by authorized admins.
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

function takinta_wc_draft_user_can_manage() {
    return current_user_can('manage_options') || current_user_can('manage_woocommerce');
}

function takinta_wc_draft_import_admin_url($args = array()) {
    $url = admin_url('admin.php?page=takinta-wc-draft-import');
    return $args ? add_query_arg($args, $url) : $url;
}

function takinta_wc_draft_import_admin_menu() {
    if (class_exists('WooCommerce')) {
        add_submenu_page('woocommerce', 'ورود محصولات تکینتا به‌صورت پیش‌نویس', 'محصولات پیش‌نویس تکینتا', 'manage_woocommerce', 'takinta-wc-draft-import', 'takinta_wc_draft_import_render_page');
    } else {
        add_management_page('ورود محصولات تکینتا به WooCommerce', 'محصولات پیش‌نویس تکینتا', 'manage_options', 'takinta-wc-draft-import', 'takinta_wc_draft_import_render_page');
    }
}
add_action('admin_menu', 'takinta_wc_draft_import_admin_menu');

function takinta_wc_draft_import_enqueue_admin_script($hook) {
    if (!isset($_GET['page']) || !is_string($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'takinta-wc-draft-import') { return; }
    wp_enqueue_script(
        'takinta-wc-draft-import-admin',
        plugins_url('takinta-wc-draft-import-admin.js', __FILE__),
        array(),
        '1.4.6',
        true
    );
}
add_action('admin_enqueue_scripts', 'takinta_wc_draft_import_enqueue_admin_script');

function takinta_wc_draft_import_catalog() {
    $root = realpath(takinta_editable_static_root());
    $path = $root ? realpath($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'products.json') : false;
    if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path) || !is_readable($path)) {
        return new WP_Error('takinta_catalog_missing', 'فایل کاتالوگ پیدا نشد؛ قالب Takinta Static Site نسخهٔ جدید را نصب یا به‌روزرسانی کن.');
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || empty($decoded['products']) || !is_array($decoded['products'])) {
        return new WP_Error('takinta_catalog_invalid', 'ساختار فایل کاتالوگ معتبر نیست.');
    }
    return array_values($decoded['products']);
}

function takinta_wc_draft_source_excerpt($route) {
    $root = realpath(takinta_editable_static_root());
    $path = $root ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route)) : false;
    if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path) || !is_readable($path)) { return ''; }
    $html = file_get_contents($path);
    if (!is_string($html) || $html === '' || !preg_match_all('~<meta\b[^>]*>~i', $html, $tags)) { return ''; }
    foreach ($tags[0] as $tag) {
        if (!preg_match("~\\bname\\s*=\\s*([\"'])(.*?)\\1~i", $tag, $name) || strtolower($name[2]) !== 'description') { continue; }
        if (!preg_match("~\\bcontent\\s*=\\s*([\"'])(.*?)\\1~is", $tag, $content)) { continue; }
        $text = html_entity_decode(wp_strip_all_tags($content[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return wp_trim_words(sanitize_textarea_field($text), 45, '…');
    }
    return '';
}

function takinta_wc_draft_source_image_url($src, $route) {
    $src = trim((string) $src);
    if ($src === '') { return ''; }
    if (preg_match('~^https?://~i', $src)) { return esc_url_raw($src); }
    if (strpos($src, '//') === 0) { return esc_url_raw((is_ssl() ? 'https:' : 'http:') . $src); }

    $root = realpath(takinta_editable_static_root());
    if (!$root) { return esc_url_raw($src); }
    $src_path = wp_parse_url($src, PHP_URL_PATH);
    if (!is_string($src_path) || $src_path === '') { return ''; }
    $candidates = array(dirname($route) . '/' . $src_path, $src_path);
    foreach ($candidates as $candidate) {
        $candidate = str_replace('/', DIRECTORY_SEPARATOR, ltrim($candidate, '/'));
        $resolved = realpath($root . DIRECTORY_SEPARATOR . $candidate);
        if (!$resolved || strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($resolved)) { continue; }
        $relative = substr($resolved, strlen($root) + 1);
        $theme_uri = untrailingslashit(get_template_directory_uri());
        return esc_url_raw($theme_uri . '/static-site/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative));
    }
    return esc_url_raw($src);
}

function takinta_wc_draft_local_image_path($src, $route) {
    if (preg_match('~^https?://~i', (string) $src) || strpos((string) $src, '//') === 0) { return false; }
    $root = realpath(takinta_editable_static_root());
    $src_path = wp_parse_url((string) $src, PHP_URL_PATH);
    if (!$root || !is_string($src_path) || $src_path === '') { return false; }
    $candidates = array(dirname($route) . '/' . $src_path, $src_path);
    foreach ($candidates as $candidate) {
        $candidate = str_replace('/', DIRECTORY_SEPARATOR, ltrim($candidate, '/'));
        $resolved = realpath($root . DIRECTORY_SEPARATOR . $candidate);
        if ($resolved && strpos($resolved, $root . DIRECTORY_SEPARATOR) === 0 && is_file($resolved) && is_readable($resolved)) { return $resolved; }
    }
    return false;
}

function takinta_wc_draft_find_source_attachment($source_url) {
    $matches = get_posts(array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'numberposts' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_key' => '_takinta_source_image_url',
        'meta_value' => $source_url,
    ));
    return !empty($matches) ? (int) $matches[0] : 0;
}

function takinta_wc_draft_import_product_image($post_id, $item, $route) {
    if (has_post_thumbnail($post_id)) { return array('status' => 'already-set', 'attachment_id' => get_post_thumbnail_id($post_id)); }
    if (empty($item['images'][0]['src']) || !is_string($item['images'][0]['src'])) {
        return new WP_Error('takinta_image_missing', 'برای این محصول نشانی تصویر منبع وجود ندارد.');
    }
    $src = trim($item['images'][0]['src']);
    $source_url = takinta_wc_draft_source_image_url($src, $route);
    if ($source_url === '') { return new WP_Error('takinta_image_url_invalid', 'نشانی تصویر معتبر نیست.'); }
    $existing_attachment = takinta_wc_draft_find_source_attachment($source_url);
    if ($existing_attachment && wp_attachment_is_image($existing_attachment)) {
        if (!set_post_thumbnail($post_id, $existing_attachment)) {
            return new WP_Error('takinta_image_thumbnail_failed', 'تصویر موجود پیدا شد اما تنظیم آن به‌عنوان تصویر شاخص ناموفق بود.');
        }
        update_post_meta($post_id, '_takinta_source_image_import_status', 'reused');
        return array('status' => 'reused', 'attachment_id' => $existing_attachment);
    }

    $filename = sanitize_file_name(basename((string) wp_parse_url($src, PHP_URL_PATH)));
    if ($filename === '' || $filename === '.' || $filename === '/') { $filename = sanitize_title($item['slug'] ?? 'takinta-product') . '.jpg'; }
    $local_file = takinta_wc_draft_local_image_path($src, $route);
    if ($local_file) {
        $tmp = wp_tempnam($filename);
        if (!$tmp) { return new WP_Error('takinta_image_temp_failed', 'ساخت فایل موقت تصویر ناموفق بود.'); }
        if (!copy($local_file, $tmp)) {
            @unlink($tmp);
            return new WP_Error('takinta_image_copy_failed', 'کپی تصویر داخل قالب ناموفق بود.');
        }
    } else {
        $url = $src;
        if (strpos($url, '//') === 0) { $url = (is_ssl() ? 'https:' : 'http:') . $url; }
        $parts = wp_parse_url($url);
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $allowed_hosts = array('www.mandai-design.com', 'cdn.shopify.com');
        if ($scheme !== 'https' || !in_array($host, $allowed_hosts, true)) {
            return new WP_Error('takinta_image_host_blocked', 'نشانی تصویر مجاز نیست؛ فقط HTTPS از میزبان‌های فهرست‌شده پذیرفته می‌شود.');
        }
        if (!empty($parts['user']) || !empty($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return new WP_Error('takinta_image_url_invalid', 'نشانی تصویر شامل اطلاعات کاربری یا درگاه غیرمجاز است.');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam($filename);
        if (!$tmp) { return new WP_Error('takinta_image_temp_failed', 'ساخت فایل موقت تصویر ناموفق بود.'); }
        // Exact HTTPS host allowlist above is the SSRF boundary; do not follow redirects to unlisted hosts.
        $response = wp_remote_get($url, array(
            'timeout' => 8,
            'redirection' => 0,
            'stream' => true,
            'filename' => $tmp,
            'limit_response_size' => 8388608,
            'headers' => array(
                'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif,*/*;q=0.5',
                'User-Agent' => 'Takinta-WP-Catalog-Image-Importer/1.4.3',
            ),
        ));
        if (is_wp_error($response)) {
            @unlink($tmp);
            return new WP_Error('takinta_image_download_failed', 'دریافت تصویر ناموفق بود: ' . $response->get_error_message());
        }
        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            @unlink($tmp);
            return new WP_Error('takinta_image_http_invalid', 'سرور منبع تصویر پاسخ HTTP ناموفق داد (' . $status_code . ').');
        }
    }

    if (!is_file($tmp) || !is_readable($tmp)) {
        @unlink($tmp);
        return new WP_Error('takinta_image_file_missing', 'فایل تصویر دریافت‌شده در سرور پیدا نشد یا قابل‌خواندن نیست.');
    }
    $file_size = filesize($tmp);
    if (!$file_size || $file_size >= 8388608) {
        @unlink($tmp);
        return new WP_Error('takinta_image_size_invalid', 'حجم تصویر صفر یا بیشتر از ۸ مگابایت است.');
    }
    $image_info = @getimagesize($tmp);
    $mime = is_array($image_info) && !empty($image_info['mime']) ? strtolower((string) $image_info['mime']) : '';
    $mime_extensions = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    if (!isset($mime_extensions[$mime])) {
        @unlink($tmp);
        return new WP_Error('takinta_image_type_invalid', 'محتوای دریافت‌شده تصویر معتبر JPG، PNG، WebP یا GIF نیست.');
    }
    $filename = sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME) . '.' . $mime_extensions[$mime]);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $file_array = array('name' => $filename, 'tmp_name' => $tmp, 'type' => $mime);
    $attachment_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return $attachment_id;
    }
    update_post_meta($attachment_id, '_takinta_source_image_url', $source_url);
    $alt = !empty($item['images'][0]['alt']) ? sanitize_text_field($item['images'][0]['alt']) : sanitize_text_field($item['titleEn'] ?? 'Takinta product');
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    if (!set_post_thumbnail($post_id, $attachment_id)) {
        return new WP_Error('takinta_image_thumbnail_failed', 'تصویر به کتابخانه منتقل شد اما تنظیم آن به‌عنوان تصویر شاخص ناموفق بود.');
    }
    update_post_meta($post_id, '_takinta_source_image_import_status', 'imported');
    delete_post_meta($post_id, '_takinta_source_image_import_error');
    return array('status' => 'imported', 'attachment_id' => (int) $attachment_id);
}

function takinta_wc_draft_existing_product($slug) {
    $args = array(
        'post_type' => 'product',
        'post_status' => array('publish', 'draft', 'pending', 'private', 'future', 'trash'),
        'numberposts' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
    );
    $by_source = get_posts(array_merge($args, array('meta_key' => '_takinta_catalog_slug', 'meta_value' => $slug)));
    if (!empty($by_source)) { return (int) $by_source[0]; }
    $by_slug = get_posts(array_merge($args, array('name' => $slug)));
    return !empty($by_slug) ? (int) $by_slug[0] : 0;
}

function takinta_wc_draft_import_batch() {
    if (!takinta_wc_draft_user_can_manage()) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_wc_draft_import_batch');
    if (!class_exists('WooCommerce') || !post_type_exists('product')) {
        wp_safe_redirect(takinta_wc_draft_import_admin_url(array('error' => 'woocommerce')));
        exit;
    }
    $catalog = takinta_wc_draft_import_catalog();
    if (is_wp_error($catalog)) {
        wp_safe_redirect(takinta_wc_draft_import_admin_url(array('error' => 'catalog')));
        exit;
    }
    $total = count($catalog);
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : (int) get_option(TAKINTA_WC_DRAFT_IMPORT_OFFSET_OPTION, 0);
    $offset = min($total, max(0, $offset));
    $batch = array_slice($catalog, $offset, TAKINTA_WC_DRAFT_IMPORT_BATCH_SIZE);
    $created = (int) get_option(TAKINTA_WC_DRAFT_IMPORT_CREATED_OPTION, 0);
    $skipped = (int) get_option(TAKINTA_WC_DRAFT_IMPORT_SKIPPED_OPTION, 0);
    $root = realpath(takinta_editable_static_root());
    $locale = (string) get_option('WPLANG');
    if ($locale === '') { $locale = get_locale(); }
    $prefer_fa = strpos(strtolower($locale), 'fa') === 0;

    foreach ($batch as $item) {
        if (!is_array($item)) { $skipped++; continue; }
        $slug = isset($item['slug']) ? sanitize_title($item['slug']) : '';
        $title_en = isset($item['titleEn']) ? sanitize_text_field($item['titleEn']) : '';
        $title_fa = isset($item['titleFa']) ? sanitize_text_field($item['titleFa']) : '';
        if ($slug === '' || ($title_en === '' && $title_fa === '') || !preg_match('/^[a-z0-9-]+$/', $slug)) { $skipped++; continue; }
        if (takinta_wc_draft_existing_product($slug)) { $skipped++; continue; }

        $route = 'products/' . $slug . '.html';
        $fa_route = 'fa/products/' . $slug . '.html';
        $source_route = $prefer_fa ? $fa_route : $route;
        $source_path = $root ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source_route)) : false;
        if (!$source_path || strpos($source_path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($source_path)) {
            $source_route = $route;
            $source_path = $root ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source_route)) : false;
        }
        if (!$source_path || strpos($source_path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($source_path)) { $skipped++; continue; }

        $title = $prefer_fa ? ($title_fa ?: $title_en) : ($title_en ?: $title_fa);
        $post_id = wp_insert_post(array(
            'post_type' => 'product',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => '',
            'post_excerpt' => takinta_wc_draft_source_excerpt($source_route),
            'post_author' => get_current_user_id(),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ), true);
        if (is_wp_error($post_id) || !$post_id) { $skipped++; continue; }

        if (taxonomy_exists('product_type')) { wp_set_object_terms($post_id, 'simple', 'product_type', false); }
        update_post_meta($post_id, '_takinta_catalog_slug', $slug);
        update_post_meta($post_id, '_takinta_title_en', $title_en);
        update_post_meta($post_id, '_takinta_title_fa', $title_fa);
        update_post_meta($post_id, '_takinta_source_route', $route);
        update_post_meta($post_id, '_takinta_source_route_fa', $fa_route);
        update_post_meta($post_id, '_takinta_source_category', isset($item['category']) ? sanitize_key($item['category']) : '');
        $image_url = '';
        if (!empty($item['images'][0]['src']) && is_string($item['images'][0]['src'])) {
            $image_url = takinta_wc_draft_source_image_url($item['images'][0]['src'], $route);
        }
        update_post_meta($post_id, '_takinta_source_image_url', $image_url);
        $created++;
    }

    $next = min($total, $offset + count($batch));
    update_option(TAKINTA_WC_DRAFT_IMPORT_OFFSET_OPTION, $next, false);
    update_option(TAKINTA_WC_DRAFT_IMPORT_CREATED_OPTION, $created, false);
    update_option(TAKINTA_WC_DRAFT_IMPORT_SKIPPED_OPTION, $skipped, false);
    $args = $next < $total ? array('continue' => '1') : array('complete' => '1');
    wp_safe_redirect(takinta_wc_draft_import_admin_url($args));
    exit;
}
add_action('admin_post_takinta_wc_draft_import_batch', 'takinta_wc_draft_import_batch');

function takinta_wc_draft_import_images_batch() {
    if (!takinta_wc_draft_user_can_manage()) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_wc_draft_import_images_batch');
    if (!class_exists('WooCommerce') || !post_type_exists('product')) {
        wp_safe_redirect(takinta_wc_draft_import_admin_url(array('error' => 'woocommerce')));
        exit;
    }
    $catalog = takinta_wc_draft_import_catalog();
    if (is_wp_error($catalog)) {
        wp_safe_redirect(takinta_wc_draft_import_admin_url(array('error' => 'catalog')));
        exit;
    }
    $total = count($catalog);
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : (int) get_option(TAKINTA_WC_DRAFT_IMAGE_OFFSET_OPTION, 0);
    $offset = min($total, max(0, $offset));
    $batch = array_slice($catalog, $offset, TAKINTA_WC_DRAFT_IMAGE_BATCH_SIZE);
    $imported = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_IMPORTED_OPTION, 0);
    $skipped = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_SKIPPED_OPTION, 0);
    $unmatched = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_UNMATCHED_OPTION, 0);
    $failed = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_FAILED_OPTION, 0);

    foreach ($batch as $item) {
        if (!is_array($item) || empty($item['slug'])) { $skipped++; continue; }
        $slug = sanitize_title($item['slug']);
        $post_id = takinta_wc_draft_existing_product($slug);
        if (!$post_id) { $skipped++; $unmatched++; continue; }
        $product_post = get_post($post_id);
        if (!$product_post || $product_post->post_status === 'trash') { $skipped++; $unmatched++; continue; }
        if (has_post_thumbnail($post_id)) {
            update_post_meta($post_id, '_takinta_source_image_import_status', 'already-set');
            $skipped++;
            continue;
        }

        $result = takinta_wc_draft_import_product_image($post_id, $item, 'products/' . $slug . '.html');
        if (is_wp_error($result)) {
            update_post_meta($post_id, '_takinta_source_image_import_status', 'failed');
            update_post_meta($post_id, '_takinta_source_image_import_error', sanitize_text_field($result->get_error_message()));
            $failed++;
            continue;
        }
        delete_post_meta($post_id, '_takinta_source_image_import_error');
        $imported++;
    }

    $next = min($total, $offset + count($batch));
    update_option(TAKINTA_WC_DRAFT_IMAGE_OFFSET_OPTION, $next, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_IMPORTED_OPTION, $imported, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_SKIPPED_OPTION, $skipped, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_UNMATCHED_OPTION, $unmatched, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_FAILED_OPTION, $failed, false);
    $args = $next < $total ? array('image_continue' => '1') : array('image_complete' => '1');
    wp_safe_redirect(takinta_wc_draft_import_admin_url($args));
    exit;
}
add_action('admin_post_takinta_wc_draft_import_images_batch', 'takinta_wc_draft_import_images_batch');

function takinta_wc_draft_import_images_reset() {
    if (!takinta_wc_draft_user_can_manage()) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_wc_draft_import_images_reset');
    update_option(TAKINTA_WC_DRAFT_IMAGE_OFFSET_OPTION, 0, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_IMPORTED_OPTION, 0, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_SKIPPED_OPTION, 0, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_UNMATCHED_OPTION, 0, false);
    update_option(TAKINTA_WC_DRAFT_IMAGE_FAILED_OPTION, 0, false);
    wp_safe_redirect(takinta_wc_draft_import_admin_url(array('images_reset' => '1')));
    exit;
}
add_action('admin_post_takinta_wc_draft_import_images_reset', 'takinta_wc_draft_import_images_reset');

function takinta_wc_draft_import_reset() {
    if (!takinta_wc_draft_user_can_manage()) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_wc_draft_import_reset');
    update_option(TAKINTA_WC_DRAFT_IMPORT_OFFSET_OPTION, 0, false);
    update_option(TAKINTA_WC_DRAFT_IMPORT_CREATED_OPTION, 0, false);
    update_option(TAKINTA_WC_DRAFT_IMPORT_SKIPPED_OPTION, 0, false);
    wp_safe_redirect(takinta_wc_draft_import_admin_url(array('reset' => '1')));
    exit;
}
add_action('admin_post_takinta_wc_draft_import_reset', 'takinta_wc_draft_import_reset');

function takinta_wc_draft_import_render_page() {
    if (!takinta_wc_draft_user_can_manage()) { return; }
    echo '<div class="wrap"><h1>انتقال کاتالوگ تکینتا به پیش‌نویس‌های WooCommerce</h1>';
    if (!class_exists('WooCommerce') || !post_type_exists('product')) {
        echo '<div class="notice notice-error"><p>WooCommerce فعال نیست. ابتدا آن را در افزونه‌ها فعال کن.</p></div></div>';
        return;
    }
    $catalog = takinta_wc_draft_import_catalog();
    if (is_wp_error($catalog)) {
        echo '<div class="notice notice-error"><p>' . esc_html($catalog->get_error_message()) . '</p></div></div>';
        return;
    }
    $total = count($catalog);
    $offset = min($total, max(0, (int) get_option(TAKINTA_WC_DRAFT_IMPORT_OFFSET_OPTION, 0)));
    $created = (int) get_option(TAKINTA_WC_DRAFT_IMPORT_CREATED_OPTION, 0);
    $skipped = (int) get_option(TAKINTA_WC_DRAFT_IMPORT_SKIPPED_OPTION, 0);
    $done = $offset >= $total;
    $continue = isset($_GET['continue']) && sanitize_text_field(wp_unslash($_GET['continue'])) === '1' && !$done;
    $image_offset = min($total, max(0, (int) get_option(TAKINTA_WC_DRAFT_IMAGE_OFFSET_OPTION, 0)));
    $image_imported = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_IMPORTED_OPTION, 0);
    $image_skipped = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_SKIPPED_OPTION, 0);
    $image_unmatched = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_UNMATCHED_OPTION, 0);
    $image_failed = (int) get_option(TAKINTA_WC_DRAFT_IMAGE_FAILED_OPTION, 0);
    $images_done = $image_offset >= $total;
    $images_continue = $image_offset > 0 && !$images_done;

    echo '<p>این ابزار ' . esc_html((string) $total) . ' محصول را در بسته‌های ۲۰تایی به‌صورت <strong>پیش‌نویس</strong> می‌سازد؛ صفحهٔ عمومی یا خرید فعالی ایجاد نمی‌شود.</p>';
    echo '<ul><li>عنوان از زبان پیش‌فرض WordPress انتخاب می‌شود و هر دو عنوان فارسی/انگلیسی برای بازبینی ذخیره می‌شوند.</li><li>در مرحله‌ای جداگانه، تصویر منبع به کتابخانهٔ رسانه منتقل و تصویر شاخص محصول می‌شود.</li><li><strong>قیمت، SKU و موجودی وارد نمی‌شوند</strong> و محصول‌ها تا قیمت‌گذاری و انتشار دستی قابل سفارش نیستند.</li><li>محصول‌های موجود یا دارای همان slug بازنویسی نمی‌شوند؛ تصویر شاخصی که از قبل تنظیم شده هم جایگزین نخواهد شد.</li></ul>';
    echo '<p><strong>پیشرفت:</strong> ' . esc_html((string) $offset) . ' / ' . esc_html((string) $total) . ' &nbsp; <strong>ساخته‌شده:</strong> ' . esc_html((string) $created) . ' &nbsp; <strong>ردشده/ازقبل‌موجود:</strong> ' . esc_html((string) $skipped) . '</p>';
    echo '<progress max="' . esc_attr((string) max(1, $total)) . '" value="' . esc_attr((string) $offset) . '" style="width:min(100%,640px);height:20px"></progress>';
    if ($done) {
        echo '<div class="notice notice-success"><p>بررسی کاتالوگ تمام شد. پیش‌نویس‌ها را از مسیر WooCommerce ← محصولات باز کن؛ این ابزار هیچ محصولی را حذف یا منتشر نمی‌کند.</p></div>';
    } elseif ($continue) {
        echo '<p>در حال واردکردن بستهٔ بعدی؛ صفحه تا چند لحظهٔ دیگر ادامه می‌دهد.</p>';
    }
    if (!$done) {
        echo '<form id="takinta-wc-draft-import-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px">';
        echo '<input type="hidden" name="action" value="takinta_wc_draft_import_batch"><input type="hidden" name="offset" value="' . esc_attr((string) $offset) . '">';
        wp_nonce_field('takinta_wc_draft_import_batch');
        submit_button($offset > 0 ? 'ادامهٔ انتقال محصولات' : 'ساخت پیش‌نویس محصولات', 'primary', 'submit', false);
        echo '</form>';
    }
    if ($done || $offset > 0) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-top:10px">';
        echo '<input type="hidden" name="action" value="takinta_wc_draft_import_reset">';
        wp_nonce_field('takinta_wc_draft_import_reset');
        submit_button('شروع دوبارهٔ بررسی (بدون حذف محصول‌ها)', 'secondary', 'submit', false);
        echo '</form>';
    }
    if ($continue) {
        echo '<script>window.setTimeout(function(){var f=document.getElementById("takinta-wc-draft-import-form");if(f)f.submit();},500);</script>';
    }

    echo '<hr><h2>انتقال تصاویر محصولات</h2><p>این مرحله تصویرهای کاتالوگ را به کتابخانهٔ رسانه منتقل می‌کند و به‌عنوان تصویر شاخص محصول می‌گذارد. تصویر شاخصی که از قبل وجود دارد دست‌نخورده می‌ماند. برای دریافت تصویرهای آنلاین، هاست WordPress باید به نشانی منبع دسترسی داشته باشد.</p>';
    if (!$done) {
        echo '<div class="notice notice-warning inline"><p>تصویر محصولات موجود را می‌توانی همین حالا منتقل کنی؛ محصول‌هایی که هنوز ساخته نشده‌اند رد می‌شوند. پس از پایان ساخت پیش‌نویس‌ها، برای آن‌ها «اسکن دوبارهٔ تصویرهای جاافتاده» را اجرا کن.</p></div>';
    }
    echo '<p><strong>پیشرفت تصاویر:</strong> ' . esc_html((string) $image_offset) . ' / ' . esc_html((string) $total) . ' &nbsp; <strong>منتقل/استفاده‌شده:</strong> ' . esc_html((string) $image_imported) . ' &nbsp; <strong>ردشده/دارای تصویر قبلی:</strong> ' . esc_html((string) $image_skipped) . ' &nbsp; <strong>محصول پیدا نشد:</strong> ' . esc_html((string) $image_unmatched) . ' &nbsp; <strong>ناموفق:</strong> ' . esc_html((string) $image_failed) . '</p>';
    echo '<progress max="' . esc_attr((string) max(1, $total)) . '" value="' . esc_attr((string) $image_offset) . '" style="width:min(100%,640px);height:20px"></progress>';
    if ($image_failed > 0) {
        $failed_products = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'numberposts' => 5,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_takinta_source_image_import_status',
            'meta_value' => 'failed',
        ));
        if (!empty($failed_products)) {
            echo '<details style="margin:10px 0"><summary>نمونهٔ دلیل خطا (حداکثر ۵ محصول)</summary><ul>';
            foreach ($failed_products as $failed_id) {
                $error = get_post_meta($failed_id, '_takinta_source_image_import_error', true);
                $title = get_the_title($failed_id);
                $edit_link = get_edit_post_link($failed_id);
                $title_html = $edit_link ? '<a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a>' : esc_html($title);
                echo '<li>' . $title_html . ': ' . esc_html($error ?: 'علت خطا ثبت نشده است.') . '</li>';
            }
            echo '</ul></details>';
        }
    }
    if ($images_done && $done) {
        echo '<div class="notice notice-success"><p>بررسی انتقال تصویرها تمام شد. محصول‌های ناموفق را باز کن تا دلیل در کادر منبع کاتالوگ دیده شود؛ سپس می‌توانی اسکن را دوباره اجرا کنی.</p></div>';
    } elseif ($images_done) {
        echo '<div class="notice notice-warning inline"><p>بررسی فعلی تمام شد، اما محصولاتِ هنوز ساخته‌نشده رد شده‌اند. پس از پایان ساخت پیش‌نویس‌ها، اسکن دوباره را اجرا کن.</p></div>';
    } elseif ($images_continue) {
        echo '<p>در حال انتقال بستهٔ بعدی تصویرها؛ صفحه خودش ادامه می‌دهد.</p>';
    }
    if (!$images_done) {
        echo '<form id="takinta-wc-draft-image-form" data-auto-submit="' . esc_attr($images_continue ? '1' : '0') . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px">';
        echo '<input type="hidden" name="action" value="takinta_wc_draft_import_images_batch"><input type="hidden" name="offset" value="' . esc_attr((string) $image_offset) . '">';
        wp_nonce_field('takinta_wc_draft_import_images_batch');
        submit_button($image_offset > 0 ? 'ادامهٔ انتقال تصاویر' : 'انتقال تصویرهای محصولات فعلی', 'primary', 'submit', false);
        echo '</form>';
    }
    if ($images_done || $image_offset > 0) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-top:10px">';
        echo '<input type="hidden" name="action" value="takinta_wc_draft_import_images_reset">';
        wp_nonce_field('takinta_wc_draft_import_images_reset');
        submit_button('اسکن دوبارهٔ تصویرهای جاافتاده (بدون حذف تصویرها)', 'secondary', 'submit', false);
        echo '</form>';
    }
    echo '<p class="description">برای سفارش‌پذیرشدن هر محصول، قیمت تأییدشده را دستی وارد و آن را منتشر کن؛ برای پرداخت هم شماره کارت مقصد را در WooCommerce ← پیکربندی ← پرداخت‌ها ← Takinta Card-to-card transfer تنظیم کن.</p></div>';
}

function takinta_wc_draft_add_source_meta_box() {
    add_meta_box('takinta-wc-draft-source', 'منبع کاتالوگ تکینتا', 'takinta_wc_draft_render_source_meta_box', 'product', 'side', 'default');
}
add_action('add_meta_boxes_product', 'takinta_wc_draft_add_source_meta_box');

function takinta_wc_draft_render_source_meta_box($post) {
    $slug = get_post_meta($post->ID, '_takinta_catalog_slug', true);
    if (!$slug) { echo '<p>این محصول از ابزار انتقال کاتالوگ تکینتا ساخته نشده است.</p>'; return; }
    $title_en = get_post_meta($post->ID, '_takinta_title_en', true);
    $title_fa = get_post_meta($post->ID, '_takinta_title_fa', true);
    $route = get_post_meta($post->ID, '_takinta_source_route', true);
    $fa_route = get_post_meta($post->ID, '_takinta_source_route_fa', true);
    $category = get_post_meta($post->ID, '_takinta_source_category', true);
    $image_url = get_post_meta($post->ID, '_takinta_source_image_url', true);
    $image_status = get_post_meta($post->ID, '_takinta_source_image_import_status', true);
    $image_error = get_post_meta($post->ID, '_takinta_source_image_import_error', true);
    if ($title_en) { echo '<p><strong>English title:</strong><br>' . esc_html($title_en) . '</p>'; }
    if ($title_fa) { echo '<p><strong>عنوان فارسی:</strong><br>' . esc_html($title_fa) . '</p>'; }
    if ($category) { echo '<p><strong>دستهٔ منبع:</strong> ' . esc_html($category) . '</p>'; }
    if ($route) { echo '<p><a href="' . esc_url(home_url('/' . ltrim($route, '/'))) . '" target="_blank" rel="noopener">صفحهٔ انگلیسی</a></p>'; }
    if ($fa_route) { echo '<p><a href="' . esc_url(home_url('/' . ltrim($fa_route, '/'))) . '" target="_blank" rel="noopener">صفحهٔ فارسی</a></p>'; }
    if ($image_url) { echo '<p><a href="' . esc_url($image_url) . '" target="_blank" rel="noopener">تصویر منبع</a></p>'; }
    if ($image_status === 'imported') { echo '<p>تصویر از منبع به کتابخانهٔ رسانه منتقل شده است.</p>'; }
    elseif ($image_status === 'reused') { echo '<p>تصویر منبع از قبل در کتابخانهٔ رسانه بود و استفاده شد.</p>'; }
    elseif ($image_status === 'already-set') { echo '<p>تصویر شاخص قبلی حفظ شده است.</p>'; }
    elseif ($image_status === 'failed') { echo '<p><strong>انتقال تصویر ناموفق:</strong> ' . esc_html($image_error) . '</p>'; }
    elseif ($image_url) { echo '<p>برای انتقال، اسکن تصاویر را از ابزار «محصولات پیش‌نویس تکینتا» اجرا کن.</p>'; }
}

function takinta_wc_card_transfer_init_gateway() {
    if (!class_exists('WC_Payment_Gateway') || class_exists('Takinta_WC_Card_Transfer_Gateway')) { return; }

    class Takinta_WC_Card_Transfer_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'takinta_card_transfer';
            $this->icon = '';
            $this->has_fields = false;
            $this->method_title = 'Takinta Card-to-card transfer';
            $this->method_description = 'Manual card-to-card payment. Orders stay on hold until an administrator verifies the transfer.';
            $this->supports = array('products');
            $this->init_form_fields();
            $this->init_settings();
            $this->enabled = $this->get_option('enabled', 'yes');
            $this->title = $this->get_option('title', 'کارت‌به‌کارت / Card-to-card transfer');
            $this->description = $this->get_option('description', 'مبلغ سفارش را به کارت مقصد منتقل کنید؛ سفارش پس از بررسی دستی تأیید می‌شود. / Transfer the order total to the card below; payment is confirmed manually.');
            $this->account_name = $this->get_option('account_name', '');
            $this->card_number = $this->get_option('card_number', '');
            $this->bank_name = $this->get_option('bank_name', '');
            $this->instructions = $this->get_option('instructions', 'شماره سفارش را در توضیحات انتقال درج کنید. پس از بررسی و تأیید پرداخت، وضعیت سفارش به‌روزرسانی می‌شود. / Include the order number with your transfer. The order status is updated after manual verification.');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'فعال‌سازی',
                    'type' => 'checkbox',
                    'label' => 'فعال‌کردن پرداخت کارت‌به‌کارت',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => 'عنوان روش پرداخت',
                    'type' => 'text',
                    'default' => 'کارت‌به‌کارت / Card-to-card transfer',
                    'desc_tip' => true,
                    'description' => 'عنوانی که مشتری در صفحهٔ پرداخت می‌بیند.',
                ),
                'description' => array(
                    'title' => 'توضیح در صفحهٔ پرداخت',
                    'type' => 'textarea',
                    'default' => 'مبلغ سفارش را به کارت مقصد منتقل کنید؛ سفارش پس از بررسی دستی تأیید می‌شود. / Transfer the order total to the card below; payment is confirmed manually.',
                ),
                'account_name' => array(
                    'title' => 'نام صاحب کارت',
                    'type' => 'text',
                    'default' => '',
                ),
                'card_number' => array(
                    'title' => 'شماره کارت مقصد',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'این شماره در صفحهٔ پرداخت و راهنمای سفارش به مشتری نمایش داده می‌شود. آن را فقط در پیشخوان امن WordPress وارد کنید، نه در کد یا مخزن GitHub.',
                ),
                'bank_name' => array(
                    'title' => 'نام بانک (اختیاری)',
                    'type' => 'text',
                    'default' => '',
                ),
                'instructions' => array(
                    'title' => 'راهنمای پرداخت پس از ثبت سفارش',
                    'type' => 'textarea',
                    'default' => 'شماره سفارش را در توضیحات انتقال درج کنید. پس از بررسی و تأیید پرداخت، وضعیت سفارش به‌روزرسانی می‌شود. / Include the order number with your transfer. The order status is updated after manual verification.',
                    'description' => 'این راهنما در صفحهٔ تشکر و ایمیل سفارشِ در انتظار پرداخت نمایش داده می‌شود.',
                ),
            );
        }

        public function is_available() {
            return parent::is_available() && trim((string) $this->card_number) !== '';
        }

        public function payment_fields() {
            if ($this->description !== '') { echo wpautop(wp_kses_post($this->description)); }
            echo '<div class="takinta-card-transfer-details">';
            if (trim((string) $this->account_name) !== '') {
                echo '<p><strong>به نام / Account holder:</strong> ' . esc_html($this->account_name) . '</p>';
            }
            if (trim((string) $this->card_number) !== '') {
                echo '<p><strong>شماره کارت / Card number:</strong> <code dir="ltr">' . esc_html($this->card_number) . '</code></p>';
            }
            if (trim((string) $this->bank_name) !== '') {
                echo '<p><strong>بانک / Bank:</strong> ' . esc_html($this->bank_name) . '</p>';
            }
            echo '<p>پرداخت به‌صورت دستی بررسی می‌شود و ثبت سفارش به معنی تأیید واریز نیست. / Payment is verified manually; placing an order does not confirm receipt.</p></div>';
        }

        private function render_order_instructions($order, $plain_text = false) {
            if (!$order || $order->get_payment_method() !== $this->id) { return; }
            $parts = array();
            if (trim((string) $this->instructions) !== '') { $parts[] = trim(wp_strip_all_tags($this->instructions)); }
            if (trim((string) $this->account_name) !== '') { $parts[] = 'به نام / Account holder: ' . trim((string) $this->account_name); }
            if (trim((string) $this->card_number) !== '') { $parts[] = 'شماره کارت / Card number: ' . trim((string) $this->card_number); }
            if (trim((string) $this->bank_name) !== '') { $parts[] = 'بانک / Bank: ' . trim((string) $this->bank_name); }
            if (!$parts) { return; }
            if ($plain_text) {
                echo "\n" . implode("\n", $parts) . "\n";
                return;
            }
            echo '<section class="takinta-card-transfer-instructions"><h2>راهنمای پرداخت کارت‌به‌کارت / Card-to-card payment instructions</h2><p>' . implode('</p><p>', array_map('esc_html', $parts)) . '</p></section>';
        }

        public function thankyou_page($order_id) {
            $this->render_order_instructions(wc_get_order($order_id));
        }

        public function email_instructions($order, $sent_to_admin, $plain_text = false) {
            if ($sent_to_admin || !$order || $order->get_status() !== 'on-hold') { return; }
            $this->render_order_instructions($order, $plain_text);
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) { return array('result' => 'failure'); }
            $order->update_status('on-hold', 'Awaiting manual card-to-card payment verification.');
            if (function_exists('wc_reduce_stock_levels')) { wc_reduce_stock_levels($order_id); }
            if (function_exists('WC') && WC()->cart) { WC()->cart->empty_cart(); }
            return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        }
    }
}
add_action('plugins_loaded', 'takinta_wc_card_transfer_init_gateway', 11);

function takinta_wc_card_transfer_add_gateway($gateways) {
    if (!class_exists('Takinta_WC_Card_Transfer_Gateway') && class_exists('WC_Payment_Gateway')) {
        takinta_wc_card_transfer_init_gateway();
    }
    if (class_exists('Takinta_WC_Card_Transfer_Gateway')) { $gateways[] = 'Takinta_WC_Card_Transfer_Gateway'; }
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'takinta_wc_card_transfer_add_gateway', 20);

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
