<?php
/**
 * Plugin Name: Takinta USD to Toman Prices for WooCommerce
 * Description: Converts per-product USD base prices using Iran Market Data's free-market USD rate and syncs WooCommerce prices, with per-product manual control.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Takinta Design
 * Text Domain: takinta-usd-toman-pricing
 */

if (!defined('ABSPATH')) { exit; }

const TAKINTA_USD_TOMAN_VERSION = '1.0.1';
const TAKINTA_USD_TOMAN_API_URL = 'https://raw.githubusercontent.com/iran-market/iran-market.github.io/main/data/popular.json';
const TAKINTA_USD_TOMAN_CRON_HOOK = 'takinta_usd_toman_refresh_rate';
const TAKINTA_USD_TOMAN_BATCH_HOOK = 'takinta_usd_toman_sync_batch';
const TAKINTA_USD_TOMAN_RATE_OPTION = 'takinta_usd_toman_last_rate';
const TAKINTA_USD_TOMAN_STATE_OPTION = 'takinta_usd_toman_sync_state';
const TAKINTA_USD_TOMAN_UNIT_OPTION = 'takinta_usd_toman_price_unit';
const TAKINTA_USD_TOMAN_BATCH_SIZE = 50;

function takinta_usd_toman_cron_schedules($schedules) {
    if (!isset($schedules['takinta_every_30_minutes'])) {
        $schedules['takinta_every_30_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => 'هر ۳۰ دقیقه (تکینتا)',
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'takinta_usd_toman_cron_schedules');

function takinta_usd_toman_schedule_refresh() {
    if (!wp_next_scheduled(TAKINTA_USD_TOMAN_CRON_HOOK)) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'takinta_every_30_minutes', TAKINTA_USD_TOMAN_CRON_HOOK);
    }
}
add_action('init', 'takinta_usd_toman_schedule_refresh');
register_activation_hook(__FILE__, 'takinta_usd_toman_schedule_refresh');

function takinta_usd_toman_deactivate() {
    wp_clear_scheduled_hook(TAKINTA_USD_TOMAN_CRON_HOOK);
    wp_clear_scheduled_hook(TAKINTA_USD_TOMAN_BATCH_HOOK);
}
register_deactivation_hook(__FILE__, 'takinta_usd_toman_deactivate');

function takinta_usd_toman_fetch_rate() {
    $response = wp_remote_get(TAKINTA_USD_TOMAN_API_URL, array(
        'timeout' => 15,
        'redirection' => 2,
        'headers' => array(
            'Accept' => 'application/json',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Takinta-USD-Toman-WooCommerce/' . TAKINTA_USD_TOMAN_VERSION,
        ),
    ));
    if (is_wp_error($response)) {
        return new WP_Error('takinta_usd_toman_api_error', 'دریافت نرخ از API ناموفق بود: ' . $response->get_error_message());
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        return new WP_Error('takinta_usd_toman_http_error', 'API پاسخ HTTP ' . $status . ' برگرداند.');
    }
    $payload = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($payload) || empty($payload['data']) || !is_array($payload['data'])) {
        return new WP_Error('takinta_usd_toman_bad_payload', 'ساختار دادهٔ API معتبر نیست.');
    }
    if (!empty($payload['meta']['stale'])) {
        return new WP_Error('takinta_usd_toman_stale_feed', 'دادهٔ بازار در API کهنه اعلام شده است؛ قیمت‌ها تغییر نکردند.');
    }

    $usd = null;
    foreach ($payload['data'] as $item) {
        if (is_array($item) && isset($item['symbol']) && $item['symbol'] === 'USD_IRR_FREE') {
            $usd = $item;
            break;
        }
    }
    if (!$usd || !isset($usd['price']) || !is_numeric($usd['price']) || (float) $usd['price'] <= 0) {
        return new WP_Error('takinta_usd_toman_missing_rate', 'نرخ معتبر USD_IRR_FREE در پاسخ API پیدا نشد.');
    }
    if (isset($usd['currency']) && $usd['currency'] !== 'IRT') {
        return new WP_Error('takinta_usd_toman_wrong_unit', 'واحد نرخ API تومان نیست؛ همگام‌سازی برای جلوگیری از قیمت اشتباه متوقف شد.');
    }
    if (!empty($usd['stale']) || (isset($usd['quality']) && is_array($usd['quality']) && in_array('stale', $usd['quality'], true))) {
        return new WP_Error('takinta_usd_toman_stale_rate', 'نرخ دلار در API کهنه اعلام شده است؛ قیمت‌ها تغییر نکردند.');
    }

    $rate = array(
        'value' => (float) $usd['price'],
        'timestamp' => isset($usd['timestamp']) ? sanitize_text_field($usd['timestamp']) : '',
        'checked_at' => isset($usd['checked_at']) ? sanitize_text_field($usd['checked_at']) : '',
        'published_at' => isset($payload['meta']['published_at']) ? sanitize_text_field($payload['meta']['published_at']) : '',
        'fetched_at' => current_time('mysql', true),
        'source' => 'Iran Market Data / USD_IRR_FREE',
    );
    update_option(TAKINTA_USD_TOMAN_RATE_OPTION, $rate, false);
    return $rate;
}

function takinta_usd_toman_current_multiplier() {
    return get_option(TAKINTA_USD_TOMAN_UNIT_OPTION, 'toman') === 'rial' ? 10 : 1;
}

function takinta_usd_toman_price_from_usd($usd, $rate) {
    $usd = (float) $usd;
    $rate = (float) $rate;
    if ($usd <= 0 || $rate <= 0) { return false; }
    $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 0;
    return round($usd * $rate * takinta_usd_toman_current_multiplier(), max(0, min(6, $decimals)));
}

function takinta_usd_toman_checkbox_enabled($value) {
    if (!is_scalar($value)) { return false; }
    return in_array(strtolower(trim((string) $value)), array('yes', '1', 'on', 'true'), true);
}

function takinta_usd_toman_update_one_product($product_id, $rate) {
    if (!function_exists('wc_get_product')) { return false; }
    $product_id = absint($product_id);
    $product = wc_get_product($product_id);
    $usd = (float) get_post_meta($product_id, '_takinta_usd_toman_usd_price', true);
    if (!$product || $usd <= 0 || get_post_meta($product_id, '_takinta_usd_toman_auto', true) !== 'yes') { return false; }
    if ($product->is_type('variable') || $product->is_type('grouped')) { return false; }

    $price = takinta_usd_toman_price_from_usd($usd, $rate);
    if ($price === false || $price <= 0) { return false; }
    $formatted_price = function_exists('wc_format_decimal') ? wc_format_decimal($price, wc_get_price_decimals()) : (string) $price;
    $product->set_regular_price($formatted_price);
    // Preserve any separately configured sale price; otherwise make the converted
    // regular price the active price as well.
    if ($product->get_sale_price('edit') === '') { $product->set_price($formatted_price); }
    $product->save();
    update_post_meta($product_id, '_takinta_usd_toman_last_auto_price', $formatted_price);
    update_post_meta($product_id, '_takinta_usd_toman_last_rate', (string) $rate);
    update_post_meta($product_id, '_takinta_usd_toman_last_synced', current_time('mysql', true));
    return true;
}

function takinta_usd_toman_query_products($offset = 0) {
    return new WP_Query(array(
        'post_type' => array('product', 'product_variation'),
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => TAKINTA_USD_TOMAN_BATCH_SIZE,
        'offset' => max(0, (int) $offset),
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ids',
        'no_found_rows' => false,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
        'meta_query' => array(
            'relation' => 'AND',
            array('key' => '_takinta_usd_toman_auto', 'value' => 'yes', 'compare' => '='),
            array('key' => '_takinta_usd_toman_usd_price', 'value' => 0, 'type' => 'DECIMAL(20,6)', 'compare' => '>'),
        ),
    ));
}

function takinta_usd_toman_complete_sync($updated, $skipped, $rate) {
    $state = array(
        'status' => 'complete',
        'updated' => (int) $updated,
        'skipped' => (int) $skipped,
        'rate' => (float) $rate,
        'finished_at' => current_time('mysql'),
        'message' => 'همگام‌سازی کامل شد.',
    );
    update_option(TAKINTA_USD_TOMAN_STATE_OPTION, $state, false);
    delete_transient('takinta_usd_toman_sync_lock');
}

function takinta_usd_toman_sync_batch() {
    if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) { return; }
    if (get_transient('takinta_usd_toman_sync_lock')) { return; }
    set_transient('takinta_usd_toman_sync_lock', 1, 5 * MINUTE_IN_SECONDS);

    $rate_data = get_option(TAKINTA_USD_TOMAN_RATE_OPTION, array());
    $rate = isset($rate_data['value']) ? (float) $rate_data['value'] : 0;
    if ($rate <= 0) {
        delete_transient('takinta_usd_toman_sync_lock');
        return;
    }
    $state = get_option(TAKINTA_USD_TOMAN_STATE_OPTION, array());
    $offset = isset($state['offset']) ? (int) $state['offset'] : 0;
    $updated = isset($state['updated']) ? (int) $state['updated'] : 0;
    $skipped = isset($state['skipped']) ? (int) $state['skipped'] : 0;
    $query = takinta_usd_toman_query_products($offset);

    foreach ($query->posts as $product_id) {
        if (takinta_usd_toman_update_one_product($product_id, $rate)) { $updated++; }
        else { $skipped++; }
    }

    $next_offset = $offset + count($query->posts);
    $state = array(
        'status' => ($next_offset < (int) $query->found_posts) ? 'processing' : 'complete',
        'offset' => $next_offset,
        'updated' => $updated,
        'skipped' => $skipped,
        'rate' => $rate,
        'started_at' => isset($state['started_at']) ? $state['started_at'] : current_time('mysql'),
        'finished_at' => ($next_offset < (int) $query->found_posts) ? '' : current_time('mysql'),
        'message' => ($next_offset < (int) $query->found_posts) ? 'در حال به‌روزرسانی دستهٔ بعدی محصولات…' : 'همگام‌سازی کامل شد.',
    );
    update_option(TAKINTA_USD_TOMAN_STATE_OPTION, $state, false);
    delete_transient('takinta_usd_toman_sync_lock');

    if ($next_offset < (int) $query->found_posts) {
        if (!wp_next_scheduled(TAKINTA_USD_TOMAN_BATCH_HOOK)) {
            wp_schedule_single_event(time() + 10, TAKINTA_USD_TOMAN_BATCH_HOOK);
        }
    }
}
add_action(TAKINTA_USD_TOMAN_BATCH_HOOK, 'takinta_usd_toman_sync_batch');

function takinta_usd_toman_start_cycle($rate_data = null) {
    if (!class_exists('WooCommerce')) {
        return new WP_Error('takinta_usd_toman_no_wc', 'WooCommerce فعال نیست.');
    }
    if (is_wp_error($rate_data)) {
        wp_clear_scheduled_hook(TAKINTA_USD_TOMAN_BATCH_HOOK);
        delete_transient('takinta_usd_toman_sync_lock');
        $state = array('status' => 'error', 'message' => $rate_data->get_error_message(), 'finished_at' => current_time('mysql'));
        update_option(TAKINTA_USD_TOMAN_STATE_OPTION, $state, false);
        return $rate_data;
    }
    if (!is_array($rate_data) || empty($rate_data['value'])) { $rate_data = takinta_usd_toman_fetch_rate(); }
    if (is_wp_error($rate_data)) {
        wp_clear_scheduled_hook(TAKINTA_USD_TOMAN_BATCH_HOOK);
        delete_transient('takinta_usd_toman_sync_lock');
        $state = array('status' => 'error', 'message' => $rate_data->get_error_message(), 'finished_at' => current_time('mysql'));
        update_option(TAKINTA_USD_TOMAN_STATE_OPTION, $state, false);
        return $rate_data;
    }

    wp_clear_scheduled_hook(TAKINTA_USD_TOMAN_BATCH_HOOK);
    update_option(TAKINTA_USD_TOMAN_STATE_OPTION, array(
        'status' => 'processing',
        'offset' => 0,
        'updated' => 0,
        'skipped' => 0,
        'rate' => (float) $rate_data['value'],
        'started_at' => current_time('mysql'),
        'message' => 'نرخ دریافت شد؛ به‌روزرسانی محصولات واجد شرایط شروع شد.',
    ), false);
    delete_transient('takinta_usd_toman_sync_lock');
    takinta_usd_toman_sync_batch();
    return $rate_data;
}

function takinta_usd_toman_scheduled_cycle() {
    $rate = takinta_usd_toman_fetch_rate();
    takinta_usd_toman_start_cycle($rate);
}
add_action(TAKINTA_USD_TOMAN_CRON_HOOK, 'takinta_usd_toman_scheduled_cycle');

function takinta_usd_toman_user_can_manage() {
    return current_user_can('manage_woocommerce') || current_user_can('manage_options');
}

function takinta_usd_toman_admin_menu() {
    if (class_exists('WooCommerce')) {
        add_submenu_page('woocommerce', 'قیمت‌گذاری خودکار دلار به تومان', 'قیمت دلار به تومان', 'manage_woocommerce', 'takinta-usd-toman-pricing', 'takinta_usd_toman_render_admin_page');
    } else {
        add_management_page('قیمت‌گذاری دلار به تومان', 'قیمت دلار به تومان', 'manage_options', 'takinta-usd-toman-pricing', 'takinta_usd_toman_render_admin_page');
    }
}
add_action('admin_menu', 'takinta_usd_toman_admin_menu');

function takinta_usd_toman_admin_notice() {
    if (!is_admin() || !current_user_can('activate_plugins') || class_exists('WooCommerce')) { return; }
    echo '<div class="notice notice-warning"><p>افزونهٔ «قیمت دلار به تومان تکینتا» برای کارکردن به WooCommerce نیاز دارد.</p></div>';
}
add_action('admin_notices', 'takinta_usd_toman_admin_notice');

function takinta_usd_toman_admin_page_url($args = array()) {
    $url = admin_url('admin.php?page=takinta-usd-toman-pricing');
    return $args ? add_query_arg($args, $url) : $url;
}

function takinta_usd_toman_save_settings() {
    if (!takinta_usd_toman_user_can_manage()) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_usd_toman_save_settings');
    $raw_unit = isset($_POST['price_unit']) ? wp_unslash($_POST['price_unit']) : 'toman';
    $unit = is_string($raw_unit) ? sanitize_key($raw_unit) : 'toman';
    if (!in_array($unit, array('toman', 'rial'), true)) { $unit = 'toman'; }
    update_option(TAKINTA_USD_TOMAN_UNIT_OPTION, $unit, false);
    wp_safe_redirect(takinta_usd_toman_admin_page_url(array('saved' => '1')));
    exit;
}
add_action('admin_post_takinta_usd_toman_save_settings', 'takinta_usd_toman_save_settings');

function takinta_usd_toman_manual_sync() {
    if (!takinta_usd_toman_user_can_manage()) { wp_die('Unauthorized', '', array('response' => 403)); }
    check_admin_referer('takinta_usd_toman_manual_sync');
    $rate = takinta_usd_toman_fetch_rate();
    $result = takinta_usd_toman_start_cycle($rate);
    if (is_wp_error($result)) {
        wp_safe_redirect(takinta_usd_toman_admin_page_url(array('sync_error' => '1')));
    } else {
        wp_safe_redirect(takinta_usd_toman_admin_page_url(array('sync_started' => '1')));
    }
    exit;
}
add_action('admin_post_takinta_usd_toman_manual_sync', 'takinta_usd_toman_manual_sync');

function takinta_usd_toman_render_admin_page() {
    if (!takinta_usd_toman_user_can_manage()) { return; }
    $rate = get_option(TAKINTA_USD_TOMAN_RATE_OPTION, array());
    $state = get_option(TAKINTA_USD_TOMAN_STATE_OPTION, array());
    $unit = get_option(TAKINTA_USD_TOMAN_UNIT_OPTION, 'toman');
    $rate_value = isset($rate['value']) ? (float) $rate['value'] : 0;
    $stamp = !empty($rate['checked_at']) ? $rate['checked_at'] : (!empty($rate['fetched_at']) ? $rate['fetched_at'] : '');

    echo '<div class="wrap"><h1>قیمت‌گذاری خودکار دلار به تومان</h1>';
    if (isset($_GET['saved'])) { echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>'; }
    if (isset($_GET['sync_started'])) { echo '<div class="notice notice-success is-dismissible"><p>نرخ دریافت شد و همگام‌سازی شروع شد؛ در صورت تعداد زیاد محصولات، ادامهٔ آن در پس‌زمینه انجام می‌شود.</p></div>'; }
    if (isset($_GET['sync_error'])) { echo '<div class="notice notice-error"><p>همگام‌سازی شروع نشد. وضعیت و خطا را در بخش پایین بررسی کنید.</p></div>'; }
    echo '<p>نرخ دلار آزاد از <a href="https://iran-market.github.io/" target="_blank" rel="noopener">Iran Market Data</a> دریافت می‌شود. API نرخ را به تومان می‌دهد و معمولاً هر ۳۰ دقیقه به‌روزرسانی می‌کند.</p>';

    if ($rate_value > 0) {
        echo '<div class="notice notice-info"><p><strong>نرخ آخر دریافت‌شده:</strong> ۱ دلار = ' . esc_html(number_format_i18n($rate_value)) . ' تومان';
        if ($stamp) { echo ' &nbsp; <span>زمان ثبت نرخ: ' . esc_html($stamp) . '</span>'; }
        echo '</p></div>';
    } else {
        echo '<div class="notice notice-warning"><p>هنوز نرخ معتبری دریافت نشده است. برای دریافت نرخ و شروع همگام‌سازی، دکمهٔ «دریافت نرخ و همگام‌سازی» را بزنید.</p></div>';
    }
    if (!empty($state['message'])) {
        $notice_class = isset($state['status']) && $state['status'] === 'error' ? 'notice-error' : 'notice-info';
        echo '<div class="notice ' . esc_attr($notice_class) . '"><p><strong>وضعیت:</strong> ' . esc_html($state['message']);
        if (isset($state['updated'])) { echo ' محصولات به‌روزشده: ' . esc_html((string) $state['updated']) . '؛ موارد ردشده: ' . esc_html((string) ($state['skipped'] ?? 0)) . '.'; }
        if (!empty($state['finished_at'])) { echo ' آخرین پایان: ' . esc_html($state['finished_at']) . '.'; }
        echo '</p></div>';
    }

    echo '<h2>واحد قیمت در فروشگاه</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('takinta_usd_toman_save_settings');
    echo '<input type="hidden" name="action" value="takinta_usd_toman_save_settings"><label for="tusd-price-unit">قیمت محصول در WooCommerce برحسب </label> <select id="tusd-price-unit" name="price_unit"><option value="toman" ' . selected($unit, 'toman', false) . '>تومان</option><option value="rial" ' . selected($unit, 'rial', false) . '>ریال (تومان × ۱۰)</option></select> ';
    submit_button('ذخیرهٔ تنظیمات', 'secondary', 'submit', false);
    echo '</form><p class="description">این گزینه باید با واحدی که فروشگاه شما برای قیمت محصولات استفاده می‌کند سازگار باشد. مقدار پیش‌فرض تومان است.</p>';

    echo '<h2>همگام‌سازی</h2><p>فقط محصولاتی به‌روزرسانی می‌شوند که در ویرایش محصول، قیمت پایهٔ دلاری وارد کرده باشید و گزینهٔ «به‌روزرسانی خودکار» را فعال کرده باشید. قیمت‌های دستی و محصولاتی که این گزینه برایشان خاموش است تغییر نمی‌کنند.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('takinta_usd_toman_manual_sync');
    echo '<input type="hidden" name="action" value="takinta_usd_toman_manual_sync">';
    submit_button('دریافت نرخ و همگام‌سازی', 'primary', 'submit', false);
    echo '</form><p>همگام‌سازی خودکار هر ۳۰ دقیقه با WP-Cron اجرا می‌شود. WP-Cron به بازدید سایت وابسته است؛ برای زمان‌بندی دقیق، Cron سرور را طوری تنظیم کنید که wp-cron.php را اجرا کند.</p>';

    echo '<h2>مبنای قیمت هر محصول</h2><ol><li>در پیشخوان به «محصولات ← ویرایش» بروید.</li><li>در بخش اطلاعات محصول ← همگانی، قیمت پایه را به دلار وارد کنید و «به‌روزرسانی خودکار از نرخ دلار» را فعال کنید. برای متغیرها، این اطلاعات را برای هر متغیر وارد کنید.</li><li>افزونه قیمت عادی را با نرخ دلار آزاد به‌روز می‌کند. برای قیمت‌گذاری دستی، تیک به‌روزرسانی خودکار را بردارید و قیمت را در فیلد معمول ووکامرس وارد کنید.</li></ol>';
    echo '<p><strong>نکته:</strong> کاتالوگ فعلی قیمت USD پایه‌ای در WooCommerce ندارد؛ افزونه قیمت دلاری را حدس نمی‌زند. تا وقتی برای محصول قیمت دلار ثبت و به‌روزرسانی خودکار فعال نشود، قیمت آن دست‌نخورده می‌ماند. قیمت حراج جداگانه و دستی باقی می‌ماند.</p>';
    echo '</div>';
}

function takinta_usd_toman_render_product_fields() {
    if (!function_exists('woocommerce_wp_text_input')) { return; }
    echo '<div class="options_group">';
    wp_nonce_field('takinta_usd_toman_save_product', 'takinta_usd_toman_product_nonce');
    global $post;
    $wc_product = isset($post->ID) ? wc_get_product($post->ID) : false;
    if ($wc_product && ($wc_product->is_type('variable') || $wc_product->is_type('grouped'))) {
        echo '<p class="form-field">برای محصولات متغیر، قیمت دلاری و به‌روزرسانی خودکار را برای هر متغیر در بخش زیر وارد کنید؛ محصول گروهی از این قیمت‌گذاری پشتیبانی نمی‌کند.</p></div>';
        return;
    }
    woocommerce_wp_text_input(array(
        'id' => '_takinta_usd_toman_usd_price',
        'label' => 'قیمت پایه (دلار آمریکا)',
        'description' => 'مبلغ پایهٔ محصول را به USD وارد کنید؛ افزونه آن را با نرخ دلار آزاد API به تومان تبدیل می‌کند.',
        'desc_tip' => true,
        'type' => 'number',
        'custom_attributes' => array('step' => '0.01', 'min' => '0'),
        'data_type' => 'price',
    ));
    woocommerce_wp_checkbox(array(
        'id' => '_takinta_usd_toman_auto',
        'cbvalue' => 'yes',
        'label' => 'به‌روزرسانی خودکار از نرخ دلار',
        'description' => 'اگر خاموش باشد، قیمت محصول را دستی در فیلد قیمت عادی WooCommerce وارد کنید؛ افزونه آن را تغییر نمی‌دهد.',
    ));
    echo '</div>';
}
add_action('woocommerce_product_options_pricing', 'takinta_usd_toman_render_product_fields');

function takinta_usd_toman_save_product_fields($product) {
    if (!$product || !is_a($product, 'WC_Product') || !isset($_POST['takinta_usd_toman_product_nonce'])) { return; }
    $nonce = sanitize_text_field(wp_unslash($_POST['takinta_usd_toman_product_nonce']));
    if (!wp_verify_nonce($nonce, 'takinta_usd_toman_save_product') || !current_user_can('edit_post', $product->get_id())) { return; }
    if ($product->is_type('variable') || $product->is_type('grouped')) {
        $product->update_meta_data('_takinta_usd_toman_auto', 'no');
        return;
    }
    if (!isset($_POST['_takinta_usd_toman_usd_price'])) { return; }

    $raw_usd = wp_unslash($_POST['_takinta_usd_toman_usd_price']);
    if (!is_scalar($raw_usd)) { return; }
    $usd = wc_format_decimal(wc_clean($raw_usd), 6);
    if ($usd === '' || (float) $usd < 0) { $usd = ''; }
    $auto_value = isset($_POST['_takinta_usd_toman_auto']) ? wp_unslash($_POST['_takinta_usd_toman_auto']) : '';
    $enabled = takinta_usd_toman_checkbox_enabled($auto_value) && $usd !== '' && (float) $usd > 0;
    $product->update_meta_data('_takinta_usd_toman_usd_price', $usd);
    $product->update_meta_data('_takinta_usd_toman_auto', $enabled ? 'yes' : 'no');
}
add_action('woocommerce_admin_process_product_object', 'takinta_usd_toman_save_product_fields', 20);

function takinta_usd_toman_verify_product_admin_save($product_id) {
    if (!current_user_can('edit_post', $product_id)) { return false; }
    if (isset($_POST['takinta_usd_toman_product_nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['takinta_usd_toman_product_nonce']));
        if (wp_verify_nonce($nonce, 'takinta_usd_toman_save_product')) { return true; }
    }
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        return wp_verify_nonce($nonce, 'update-post_' . $product_id);
    }
    return false;
}

function takinta_usd_toman_save_product_meta_fallback($product_id) {
    if (!takinta_usd_toman_verify_product_admin_save($product_id)) { return; }
    $product = wc_get_product($product_id);
    if (!$product) { return; }
    if ($product->is_type('variable') || $product->is_type('grouped')) {
        update_post_meta($product_id, '_takinta_usd_toman_auto', 'no');
        return;
    }
    if (!isset($_POST['_takinta_usd_toman_usd_price'])) { return; }
    $raw_usd = wp_unslash($_POST['_takinta_usd_toman_usd_price']);
    if (!is_scalar($raw_usd)) { return; }
    $usd = wc_format_decimal(wc_clean($raw_usd), 6);
    if ($usd === '' || (float) $usd < 0) { $usd = ''; }
    $raw_auto = isset($_POST['_takinta_usd_toman_auto']) ? wp_unslash($_POST['_takinta_usd_toman_auto']) : '';
    $enabled = takinta_usd_toman_checkbox_enabled($raw_auto) && $usd !== '' && (float) $usd > 0;
    update_post_meta($product_id, '_takinta_usd_toman_usd_price', $usd);
    update_post_meta($product_id, '_takinta_usd_toman_auto', $enabled ? 'yes' : 'no');
}
add_action('woocommerce_process_product_meta', 'takinta_usd_toman_save_product_meta_fallback', 20, 1);

function takinta_usd_toman_sync_saved_product($product_id) {
    if (!takinta_usd_toman_verify_product_admin_save($product_id) || !function_exists('wc_get_product')) { return; }
    $product = wc_get_product($product_id);
    if (!$product) { return; }
    $product_ids = $product->is_type('variable') ? $product->get_children() : array($product_id);
    $eligible = array();
    foreach ($product_ids as $id) {
        if (get_post_meta($id, '_takinta_usd_toman_auto', true) === 'yes' && (float) get_post_meta($id, '_takinta_usd_toman_usd_price', true) > 0) {
            $eligible[] = (int) $id;
        }
    }
    if (!$eligible) { return; }

    $rate_data = get_option(TAKINTA_USD_TOMAN_RATE_OPTION, array());
    $fetched_at = !empty($rate_data['fetched_at']) ? strtotime($rate_data['fetched_at'] . ' UTC') : false;
    if (empty($rate_data['value']) || !$fetched_at || (time() - $fetched_at) > 25 * MINUTE_IN_SECONDS) {
        $rate_data = takinta_usd_toman_fetch_rate();
    }
    if (is_wp_error($rate_data)) {
        update_option(TAKINTA_USD_TOMAN_STATE_OPTION, array('status' => 'error', 'message' => $rate_data->get_error_message(), 'finished_at' => current_time('mysql')), false);
        return;
    }
    $updated = 0;
    foreach ($eligible as $id) {
        if (takinta_usd_toman_update_one_product($id, $rate_data['value'])) { $updated++; }
    }
    if ($updated) {
        update_option(TAKINTA_USD_TOMAN_STATE_OPTION, array(
            'status' => 'complete',
            'updated' => $updated,
            'skipped' => 0,
            'rate' => (float) $rate_data['value'],
            'finished_at' => current_time('mysql'),
            'message' => 'قیمت محصول پس از ذخیره، با نرخ روز به‌روزرسانی شد.',
        ), false);
    }
}
add_action('woocommerce_process_product_meta', 'takinta_usd_toman_sync_saved_product', 99, 1);

function takinta_usd_toman_render_variation_fields($loop, $variation_data, $variation) {
    $variation_id = isset($variation->ID) ? (int) $variation->ID : 0;
    if (!$variation_id) { return; }
    $usd = get_post_meta($variation_id, '_takinta_usd_toman_usd_price', true);
    $enabled = get_post_meta($variation_id, '_takinta_usd_toman_auto', true) === 'yes';
    echo '<div class="form-row form-row-full"><label style="display:block;margin-top:8px"><strong>قیمت پایه به دلار (USD)</strong></label>';
    echo '<input type="number" class="short wc_input_price" step="0.01" min="0" name="_takinta_usd_toman_usd_price[' . esc_attr((string) $loop) . ']" value="' . esc_attr((string) $usd) . '" placeholder="مثلاً 25.00">';
    echo '<label style="display:block;margin-top:6px"><input type="checkbox" name="_takinta_usd_toman_auto[' . esc_attr((string) $loop) . ']" value="yes" ' . checked($enabled, true, false) . '> به‌روزرسانی خودکار از نرخ دلار</label>';
    echo '<span class="description">با خاموش‌کردن گزینه، قیمت همان متغیر را دستی وارد کنید.</span></div>';
}
add_action('woocommerce_variation_options_pricing', 'takinta_usd_toman_render_variation_fields', 10, 3);

function takinta_usd_toman_save_variation_fields($variation_id, $loop) {
    if (!isset($_POST['takinta_usd_toman_product_nonce'])) { return; }
    $nonce = sanitize_text_field(wp_unslash($_POST['takinta_usd_toman_product_nonce']));
    if (!wp_verify_nonce($nonce, 'takinta_usd_toman_save_product') || !current_user_can('edit_post', $variation_id)) { return; }
    if (!isset($_POST['_takinta_usd_toman_usd_price']) || !is_array($_POST['_takinta_usd_toman_usd_price'])) { return; }

    $prices = wp_unslash($_POST['_takinta_usd_toman_usd_price']);
    $raw_usd = isset($prices[$loop]) && is_scalar($prices[$loop]) ? wc_clean($prices[$loop]) : '';
    $usd = wc_format_decimal($raw_usd, 6);
    if ($usd === '' || (float) $usd < 0) { $usd = ''; }
    $auto_values = isset($_POST['_takinta_usd_toman_auto']) && is_array($_POST['_takinta_usd_toman_auto']) ? wp_unslash($_POST['_takinta_usd_toman_auto']) : array();
    $auto_value = isset($auto_values[$loop]) && is_scalar($auto_values[$loop]) ? sanitize_text_field($auto_values[$loop]) : '';
    $enabled = takinta_usd_toman_checkbox_enabled($auto_value) && $usd !== '' && (float) $usd > 0;
    update_post_meta($variation_id, '_takinta_usd_toman_usd_price', $usd);
    update_post_meta($variation_id, '_takinta_usd_toman_auto', $enabled ? 'yes' : 'no');
}
add_action('woocommerce_save_product_variation', 'takinta_usd_toman_save_variation_fields', 20, 2);
