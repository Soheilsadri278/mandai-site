<?php
/**
 * Plugin Name: Takinta Editable Site & Accounts
 * Description: Imports editable Takinta pages, adds secure WordPress accounts, and preserves existing .html routes.
 * Version: 1.1.0
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
    echo 'body{margin:0;background:#f6f4f0;color:#202521;font-family:inherit}.ta-account{min-height:100vh;padding:28px 18px 48px}.ta-wrap{width:min(100%,980px);margin:auto}.ta-top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:30px}.ta-logo{display:block;width:170px;max-height:72px;object-fit:contain}.ta-back{color:#343d37;text-decoration:none;font-weight:600}.ta-heading{margin-bottom:24px}.ta-heading h1{margin:0 0 8px;font-size:clamp(28px,4vw,42px)}.ta-heading p{margin:0;color:#626b65}.ta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:20px}.ta-card{background:#fff;border:1px solid #e6e4de;border-radius:16px;padding:clamp(20px,4vw,32px);box-shadow:0 10px 30px rgba(30,40,34,.06)}.ta-card h2{margin:0 0 20px;font-size:22px}.ta-field{display:block;margin:0 0 15px}.ta-field span{display:block;margin-bottom:6px;font-weight:600}.ta-field input{box-sizing:border-box;width:100%;min-height:46px;padding:10px 12px;border:1px solid #c9cec9;border-radius:8px;background:#fff;color:#202521;font:inherit}.ta-check{display:flex;align-items:flex-start;gap:9px;margin:12px 0 18px;line-height:1.6}.ta-check input{margin-top:5px}.ta-button{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:10px 18px;border:0;border-radius:8px;background:#254a3a;color:#fff;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}.ta-button:hover{background:#183b2d;color:#fff}.ta-links{display:flex;flex-wrap:wrap;gap:14px;margin-top:18px}.ta-links a,.ta-card a{color:#254a3a}.ta-card a.ta-button{color:#fff}.ta-alert{margin:0 0 18px;padding:12px 15px;border-radius:8px;background:#e8f3eb;color:#1f5936}.ta-alert--error{background:#fff0ee;color:#9d3025}.ta-hp{position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden}.ta-details{margin:0 0 22px;padding:14px;background:#f7f8f6;border-radius:9px;line-height:1.8}.ta-actions{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-top:18px}.ta-note{margin:22px 2px 0;color:#657069;font-size:14px;line-height:1.7}.ta-footer{margin-top:32px;color:#69726c;font-size:13px}@media(max-width:560px){.ta-account{padding:18px 12px 32px}.ta-logo{width:130px}.ta-top{margin-bottom:22px}}';
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

function takinta_account_handle_route() {
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
    $route = takinta_account_route_from_request();
    if (!$route) { return; }
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
