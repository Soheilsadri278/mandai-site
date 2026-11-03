#!/usr/bin/env python3
"""Build an installable WordPress theme from the static Takinta site."""
from __future__ import annotations

import html
import os
import posixpath
import re
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path
from urllib.parse import quote, urlsplit, urlunsplit

from build_search_index import build_search_index

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "wordpress"
ZIP_PATH = OUT_DIR / "takinta-static-theme.zip"
THEME_SLUG = "takinta-static"
TOKEN_THEME = "__TAKINTA_THEME_URI__"
TOKEN_HOME = "__TAKINTA_HOME_URL__"
COPY_DIRS = ["assets", "images", "fonts", "data", "fa", "pages", "fixtures", "sectors", "systems", "products"]
COPY_ROOT_FILES = ["index.html", "favicon.svg"]

THEME_STYLE = '''/*
Theme Name: Takinta Static Site
Author: Takinta Design
Description: Classic WordPress theme wrapper for the supplied bilingual Takinta static website.
Version: 1.0.8
Requires at least: 6.0
Requires PHP: 7.4
Text Domain: takinta-static
*/
'''

FUNCTIONS_PHP = r'''<?php
/**
 * WordPress route wrapper for the original bilingual static pages.
 * Page markup and assets remain bundled in static-site/.
 */
if (!defined('ABSPATH')) { exit; }

define('TAKINTA_STATIC_DIR', get_template_directory() . '/static-site');
define('TAKINTA_STATIC_THEME_TOKEN', '__TAKINTA_THEME_URI__');
define('TAKINTA_STATIC_HOME_TOKEN', '__TAKINTA_HOME_URL__');

function takinta_static_register_routes() {
    add_rewrite_tag('%takinta_static%', '(.+)');
    add_rewrite_tag('%takinta_contact%', '([0-1])');
    add_rewrite_rule('^contact\.php$', 'index.php?takinta_contact=1', 'top');
    add_rewrite_rule('^fa/?$', 'index.php?takinta_static=fa/index.html', 'top');
    add_rewrite_rule('^(.+\.html)$', 'index.php?takinta_static=$matches[1]', 'top');
}
add_action('init', 'takinta_static_register_routes');

function takinta_static_query_vars($vars) {
    $vars[] = 'takinta_static';
    $vars[] = 'takinta_contact';
    return $vars;
}
add_filter('query_vars', 'takinta_static_query_vars');
add_action('after_switch_theme', 'flush_rewrite_rules');

function takinta_static_redirect_default_home_to_persian() {
    if (is_admin() || is_preview() || !is_front_page()) { return; }
    if (isset($_GET['lang']) && sanitize_key(wp_unslash($_GET['lang'])) === 'en') { return; }
    $request_path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $request_path = untrailingslashit((string) parse_url($request_path, PHP_URL_PATH));
    $home_path = untrailingslashit((string) parse_url(home_url('/'), PHP_URL_PATH));
    if ($request_path !== $home_path) { return; }
    nocache_headers();
    wp_safe_redirect(home_url('/fa/'), 302, 'Takinta Persian default language');
    exit;
}
add_action('template_redirect', 'takinta_static_redirect_default_home_to_persian', -5);

function takinta_static_json($ok, $message = '', $extra = array(), $status = 200) {
    wp_send_json(array_merge(array('ok' => (bool) $ok, 'error' => $ok ? '' : $message), $extra), $status);
}

function takinta_static_contact_endpoint() {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        takinta_static_json(false, 'Method not allowed.', array(), 405);
    }

    // Honeypot: return a quiet success to automated spam submissions.
    if (!empty($_POST['company'])) {
        takinta_static_json(true);
    }

    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if (!$email || !is_email($email)) {
        takinta_static_json(false, 'Please enter a valid email address.', array(), 400);
    }

    $form_type = sanitize_key(wp_unslash($_POST['form_type'] ?? 'contact'));
    if ($form_type === 'newsletter') {
        $subscribers = get_option('takinta_static_newsletter_subscribers', array());
        if (!is_array($subscribers)) { $subscribers = array(); }
        foreach ($subscribers as $saved_email) {
            if (strtolower((string) $saved_email) === strtolower($email)) {
                takinta_static_json(true, '', array('duplicate' => true));
            }
        }
        $subscribers[] = $email;
        update_option('takinta_static_newsletter_subscribers', $subscribers, false);
        takinta_static_json(true, '', array('saved' => true));
    }

    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
    if ($name === '' || $message === '') {
        takinta_static_json(false, 'Please fill in all required fields (name and message).', array(), 400);
    }
    if (strlen($message) > 15000) {
        takinta_static_json(false, 'Message is too long.', array(), 400);
    }

    $recipient = apply_filters('takinta_static_contact_recipient', get_option('admin_email'));
    if (!is_email($recipient)) {
        takinta_static_json(false, 'Set a valid site administration email in WordPress Settings.', array(), 500);
    }
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = sprintf('[%s] New contact message from %s', $site_name, $name);
    $body = "Name: {$name}\nEmail: {$email}\nPhone: {$phone}\nSource: {$source}\n\nMessage:\n{$message}\n";
    $headers = array('Reply-To: ' . $name . ' <' . $email . '>');
    if (!wp_mail($recipient, $subject, $body, $headers)) {
        takinta_static_json(false, 'Could not send the message. Please try again or call us directly.', array(), 500);
    }
    takinta_static_json(true, '', array('mailed' => true));
}

function takinta_static_render_route() {
    if (get_query_var('takinta_contact')) {
        takinta_static_contact_endpoint();
    }

    $route = (string) get_query_var('takinta_static');
    if ($route === '' && is_front_page()) {
        $route = isset($_GET['lang']) && sanitize_key(wp_unslash($_GET['lang'])) === 'en' ? 'index.html' : 'fa/index.html';
    }
    if ($route === '') { return; }

    $route = rawurldecode($route);
    $route = str_replace('\\', '/', $route);
    if (strpos($route, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $route)) {
        status_header(400);
        exit;
    }
    $root = realpath(TAKINTA_STATIC_DIR);
    $file = $root ? realpath($root . DIRECTORY_SEPARATOR . ltrim($route, '/')) : false;
    if (!$root || !$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        return;
    }

    $document = file_get_contents($file);
    if ($document === false) {
        status_header(500);
        exit;
    }
    $theme_uri = untrailingslashit(get_template_directory_uri());
    $home_url = untrailingslashit(home_url('/'));
    $document = str_replace(TAKINTA_STATIC_THEME_TOKEN, esc_url_raw($theme_uri), $document);
    $document = str_replace(TAKINTA_STATIC_HOME_TOKEN, esc_url_raw($home_url), $document);
    $document = str_replace(array('https://www.example.com', 'http://www.example.com'), $home_url, $document);
    $document = str_replace($home_url . '/images/', $theme_uri . '/static-site/images/', $document);

    status_header(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $document;
    exit;
}
add_action('template_redirect', 'takinta_static_render_route', 0);

function takinta_static_newsletter_admin_menu() {
    add_management_page('Takinta Newsletter', 'Takinta Newsletter', 'manage_options', 'takinta-static-newsletter', 'takinta_static_newsletter_admin_page');
}
add_action('admin_menu', 'takinta_static_newsletter_admin_menu');

function takinta_static_newsletter_admin_page() {
    if (!current_user_can('manage_options')) { return; }
    $subscribers = get_option('takinta_static_newsletter_subscribers', array());
    if (!is_array($subscribers)) { $subscribers = array(); }
    if (isset($_GET['export']) && $_GET['export'] === '1') {
        check_admin_referer('takinta_static_newsletter_export');
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=takinta-newsletter-subscribers.csv');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'w');
        fputcsv($output, array('email'));
        foreach ($subscribers as $email) { fputcsv($output, array(sanitize_email($email))); }
        fclose($output);
        exit;
    }
    $export_url = wp_nonce_url(add_query_arg('export', '1', admin_url('tools.php?page=takinta-static-newsletter')), 'takinta_static_newsletter_export');
    echo '<div class="wrap"><h1>Takinta Newsletter</h1>';
    echo '<p>Subscribers are stored in the WordPress database. Connect a mailing-list provider and apply your privacy/consent policy before sending campaigns.</p>';
    echo '<p><strong>Total:</strong> ' . esc_html((string) count($subscribers)) . ' &nbsp; <a class="button button-primary" href="' . esc_url($export_url) . '">Export CSV</a></p>';
    if (!$subscribers) {
        echo '<p>No subscribers yet.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Email</th></tr></thead><tbody>';
        foreach ($subscribers as $email) { echo '<tr><td>' . esc_html(sanitize_email($email)) . '</td></tr>'; }
        echo '</tbody></table>';
    }
    echo '</div>';
}

function takinta_static_theme_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array('search-form', 'comment-form', 'gallery', 'caption', 'style', 'script'));
    add_theme_support('woocommerce');
}
add_action('after_setup_theme', 'takinta_static_theme_setup');

function takinta_static_requested_commerce_language() {
    if (isset($_GET['lang'])) {
        $language = sanitize_key(wp_unslash($_GET['lang']));
        if (in_array($language, array('fa', 'en'), true)) { return $language; }
    }
    if (isset($_COOKIE['takinta_commerce_language'])) {
        $language = sanitize_key(wp_unslash($_COOKIE['takinta_commerce_language']));
        if (in_array($language, array('fa', 'en'), true)) { return $language; }
    }
    return '';
}

function takinta_static_remember_commerce_language() {
    if (is_admin() || headers_sent()) { return; }
    $language = '';
    if (isset($_GET['lang'])) {
        $candidate = sanitize_key(wp_unslash($_GET['lang']));
        if (in_array($candidate, array('fa', 'en'), true)) { $language = $candidate; }
    }
    $request_path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $request_path = (string) parse_url($request_path, PHP_URL_PATH);
    if ($language === '' && preg_match('#^/fa(?:/|$)#', $request_path)) { $language = 'fa'; }
    if ($language === '') { return; }
    if (isset($_COOKIE['takinta_commerce_language']) && $_COOKIE['takinta_commerce_language'] === $language) { return; }
    $options = array(
        'expires' => time() + (30 * DAY_IN_SECONDS),
        'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') { $options['domain'] = COOKIE_DOMAIN; }
    setcookie('takinta_commerce_language', $language, $options);
    $_COOKIE['takinta_commerce_language'] = $language;
}
add_action('init', 'takinta_static_remember_commerce_language', 1);

function takinta_static_is_persian_commerce_request() {
    $language = takinta_static_requested_commerce_language();
    if ($language === 'fa') { return true; }
    if ($language === 'en') { return false; }
    return function_exists('is_rtl') && is_rtl();
}

function takinta_static_switch_to_persian_commerce_locale() {
    if (is_admin() || !takinta_static_is_persian_commerce_request()) { return; }
    if (function_exists('switch_to_locale')) { switch_to_locale('fa_IR'); }
}
add_action('wp', 'takinta_static_switch_to_persian_commerce_locale', 0);

function takinta_static_persian_commerce_document_title($title) {
    if (is_admin() || !takinta_static_is_persian_commerce_request()) { return $title; }
    if (function_exists('is_cart') && is_cart()) { return 'سبد خرید'; }
    if (function_exists('is_checkout') && is_checkout()) { return 'تکمیل سفارش'; }
    return $title;
}
add_filter('pre_get_document_title', 'takinta_static_persian_commerce_document_title');

function takinta_static_persian_cart_translations() {
    return array(
        'Cart' => 'سبد خرید',
        'Shopping cart' => 'سبد خرید',
        'Cart totals' => 'جمع سبد خرید',
        'Checkout' => 'تکمیل سفارش',
        'Contact information' => 'اطلاعات تماس',
        'Email address' => 'نشانی ایمیل',
        'Shipping address' => 'نشانی ارسال',
        'Billing address' => 'نشانی صورتحساب',
        'Country/Region' => 'کشور/منطقه',
        'Iran' => 'ایران',
        'First name' => 'نام',
        'Last name' => 'نام خانوادگی',
        'Company name' => 'نام شرکت',
        'Company (optional)' => 'شرکت (اختیاری)',
        'State/County' => 'استان',
        'Alborz (البرز)' => 'البرز',
        'City' => 'شهر',
        'Address' => 'نشانی',
        'Address line 2' => 'نشانی تکمیلی',
        'Add apartment, suite, etc.' => 'افزودن واحد، سوئیت و غیره',
        '+ Add apartment, suite, etc.' => 'افزودن واحد، سوئیت و غیره',
        'Apartment, suite, etc.' => 'واحد، سوئیت و غیره',
        'Postal code' => 'کد پستی',
        'Phone (optional)' => 'تلفن (اختیاری)',
        'Use same address for billing' => 'استفاده از همین نشانی برای صورتحساب',
        'Shipping options' => 'روش‌های ارسال',
        'Payment options' => 'روش‌های پرداخت',
        'Order summary' => 'خلاصهٔ سفارش',
        'Order notes' => 'یادداشت سفارش',
        'Add a note to your order' => 'افزودن یادداشت به سفارش',
        'Place Order' => 'ثبت سفارش',
        'Place order' => 'ثبت سفارش',
        'Terms and Conditions' => 'شرایط و ضوابط',
        'Privacy Policy' => 'سیاست حفظ حریم خصوصی',
        'and' => 'و',
        'By proceeding with your purchase you agree to our' => 'با ادامهٔ خرید، موافقت می‌کنید با',
        'There are no payment methods available. Please contact us for help placing your order.' => 'در حال حاضر روش پرداختی در دسترس نیست. برای ثبت سفارش با ما تماس بگیرید.',
        'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.' => 'روش ارسالی در دسترس نیست. نشانی را بررسی کنید یا برای راهنمایی با ما تماس بگیرید.',
        'Returning customer?' => 'قبلاً خرید کرده‌اید؟',
        'Click here to login' => 'برای ورود اینجا کلیک کنید',
        'Have a coupon?' => 'کد تخفیف دارید؟',
        'Click here to enter your code' => 'برای واردکردن کد تخفیف اینجا کلیک کنید',
        'Return to cart' => 'بازگشت به سبد خرید',
        'Add coupons' => 'افزودن کد تخفیف',
        'Add a coupon' => 'افزودن کد تخفیف',
        'Add coupon' => 'افزودن کد تخفیف',
        'Coupon code' => 'کد تخفیف',
        'Apply' => 'اعمال',
        'Free shipping' => 'ارسال رایگان',
        'Estimated total' => 'مجموع تقریبی',
        'Proceed to Checkout' => 'ادامه به پرداخت',
        'Proceed to checkout' => 'ادامه به پرداخت',
        'Subtotal' => 'جمع جزء',
        'Total' => 'مجموع',
        'Shipping' => 'هزینهٔ ارسال',
        'Quantity' => 'تعداد',
        'Product' => 'محصول',
        'Price' => 'قیمت',
        'Remove item' => 'حذف کالا',
        'Update cart' => 'به‌روزرسانی سبد خرید',
        'Continue shopping' => 'ادامهٔ خرید',
        'Keep shopping' => 'ادامهٔ خرید',
        'Browse store' => 'مشاهدهٔ فروشگاه',
        'Browse our store' => 'مشاهدهٔ فروشگاه',
        'Return to shop' => 'بازگشت به فروشگاه',
        'Your cart is currently empty!' => 'سبد خرید شما خالی است.',
        'Your cart is currently empty.' => 'سبد خرید شما خالی است.',
        'Your cart is empty.' => 'سبد خرید شما خالی است.',
        'There are no products in your cart.' => 'سبد خرید شما خالی است.',
        'Shipping options will be updated during checkout.' => 'گزینه‌های ارسال هنگام تکمیل سفارش به‌روزرسانی می‌شوند.',
    );
}

function takinta_static_translate_persian_cart_string($translation, $text) {
    if (is_admin() || !takinta_static_is_persian_commerce_request()) { return $translation; }
    $map = takinta_static_persian_cart_translations();
    if (isset($map[$text])) { return $map[$text]; }
    if (isset($map[$translation])) { return $map[$translation]; }
    return $translation;
}
add_filter('gettext', function($translation, $text, $domain) {
    return takinta_static_translate_persian_cart_string($translation, $text);
}, 20, 3);
add_filter('gettext_with_context', function($translation, $text, $context, $domain) {
    return takinta_static_translate_persian_cart_string($translation, $text);
}, 20, 4);

function takinta_static_preserve_persian_commerce_language($url) {
    $language = takinta_static_requested_commerce_language();
    if ($language === '' && takinta_static_is_persian_commerce_request()) { $language = 'fa'; }
    if (in_array($language, array('fa', 'en'), true)) { return add_query_arg('lang', $language, $url); }
    return $url;
}
add_filter('woocommerce_get_cart_url', 'takinta_static_preserve_persian_commerce_language', 20);
add_filter('woocommerce_get_checkout_url', 'takinta_static_preserve_persian_commerce_language', 20);
add_filter('woocommerce_return_to_shop_redirect', 'takinta_static_preserve_persian_commerce_language', 20);

function takinta_static_enqueue_commerce_assets() {
    $commerce_page = function_exists('is_cart') && (is_cart() || is_checkout() || (function_exists('is_account_page') && is_account_page()) || (function_exists('is_woocommerce') && is_woocommerce()));
    if (!$commerce_page) { return; }
    $base = untrailingslashit(get_template_directory_uri()) . '/static-site/assets/css/';
    wp_enqueue_style('takinta-static-commerce', $base . 'style.css', array(), '1.0.8');
    wp_enqueue_style('takinta-static-commerce-product', $base . 'product-page.css', array('takinta-static-commerce'), '1.0.8');
    if (takinta_static_is_persian_commerce_request()) { wp_enqueue_style('takinta-static-commerce-rtl', $base . 'rtl.css', array('takinta-static-commerce'), '1.0.8'); }
}
add_action('wp_enqueue_scripts', 'takinta_static_enqueue_commerce_assets');
'''

INDEX_PHP = '''<?php
if (!defined('ABSPATH')) { exit; }
get_header();
$is_commerce = function_exists('is_cart') && (is_cart() || is_checkout() || (function_exists('is_account_page') && is_account_page()) || (function_exists('is_woocommerce') && is_woocommerce()));
if ($is_commerce) {
    $is_fa = function_exists('takinta_static_is_persian_commerce_request') ? takinta_static_is_persian_commerce_request() : is_rtl();
    $home = home_url($is_fa ? '/fa/' : '/');
    $logo = get_template_directory_uri() . '/static-site/images/logo.png';
    $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : '';
    if ($cart_url) { $cart_url = add_query_arg('lang', $is_fa ? 'fa' : 'en', $cart_url); }
    ?>
    <header class="site-header"><div class="container site-header__main" dir="<?php echo esc_attr($is_fa ? 'rtl' : 'ltr'); ?>">
      <a href="<?php echo esc_url($home); ?>" class="site-header__logo" aria-label="Takinta home"><img src="<?php echo esc_url($logo); ?>" alt="Takinta" class="site-header__logo-img"></a>
      <nav class="takinta-commerce-nav" aria-label="Store navigation">
        <a href="<?php echo esc_url($home); ?>"><?php echo esc_html($is_fa ? 'صفحهٔ اصلی' : 'Home'); ?></a>
        <?php if (function_exists('wc_get_cart_url')) : ?><a href="<?php echo esc_url($cart_url); ?>"><?php echo esc_html($is_fa ? 'سبد خرید' : 'Shopping cart'); ?></a><?php endif; ?>
      </nav>
    </div></header>
    <style>
      .takinta-commerce-nav{display:flex;flex:0 0 auto;align-items:center;gap:20px;margin-inline-start:auto;white-space:nowrap}
      .takinta-commerce-nav a{color:inherit;text-decoration:none;font-weight:600}
      .site-header{position:relative;width:100%;z-index:20}
      .site-header .container.site-header__main{box-sizing:border-box;width:100%;max-width:1280px;min-height:88px;height:auto;margin:0 auto;padding:14px clamp(20px,4vw,48px);display:flex!important;flex-direction:row!important;align-items:center!important;justify-content:space-between!important;gap:24px}
      .site-header__logo{display:flex;flex:0 0 220px;width:220px;height:56px;align-items:center}
      .site-header__logo-img{display:block!important;width:220px!important;max-width:220px!important;height:56px!important;max-height:56px!important;object-fit:contain;object-position:center left;background:transparent}
      .takinta-commerce-content{display:block!important;box-sizing:border-box;width:100%!important;max-width:1200px!important;min-width:0;margin:38px auto 64px!important;padding:0 clamp(16px,3vw,32px)!important;text-align:start}
      .takinta-commerce-content>h1{margin:0 0 24px;font-size:clamp(28px,4vw,40px)}
      .takinta-commerce-content .wp-block-woocommerce-cart,.takinta-commerce-content .wc-block-cart{box-sizing:border-box;width:100%!important;max-width:none!important;margin-inline:0!important}
      .takinta-commerce-content .wc-block-components-sidebar-layout{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(280px,360px)!important;align-items:start;gap:clamp(24px,4vw,48px);box-sizing:border-box;width:100%!important;max-width:none!important;margin:0}
      .takinta-commerce-content .wc-block-components-main,.takinta-commerce-content .wc-block-cart__main{grid-column:1;box-sizing:border-box;min-width:0;width:auto;max-width:none}
      .takinta-commerce-content .wc-block-components-sidebar,.takinta-commerce-content .wc-block-cart__sidebar{grid-column:2;box-sizing:border-box;min-width:0;width:auto;max-width:none}
      .takinta-commerce-content .wc-block-cart-items{width:100%;table-layout:auto}
      .takinta-commerce-footer{padding:28px 20px;text-align:center;background:#f4f3f1;color:#59665c}
      @media(max-width:820px){.site-header .container.site-header__main{min-height:76px;padding:10px 20px;gap:14px}.site-header__logo{flex-basis:170px;width:170px;height:48px}.site-header__logo-img{width:170px!important;height:48px!important;max-width:170px!important;max-height:48px!important}.takinta-commerce-nav{gap:12px;font-size:14px}.takinta-commerce-content .wc-block-components-sidebar-layout{grid-template-columns:minmax(0,1fr)!important}.takinta-commerce-content .wc-block-components-main,.takinta-commerce-content .wc-block-cart__main,.takinta-commerce-content .wc-block-components-sidebar,.takinta-commerce-content .wc-block-cart__sidebar{grid-column:1}}
      @media(max-width:520px){.site-header .container.site-header__main{padding-inline:16px}.site-header__logo{flex-basis:140px;width:140px}.site-header__logo-img{width:140px!important}.takinta-commerce-nav{gap:10px;font-size:13px}.takinta-commerce-content{padding-inline:16px!important}}
    </style>
    <main class="woocommerce takinta-commerce-content" dir="<?php echo esc_attr($is_fa ? 'rtl' : 'ltr'); ?>">
      <?php while (have_posts()) : the_post(); ?><h1><?php echo esc_html($is_fa && is_cart() ? 'سبد خرید' : ($is_fa && is_checkout() ? 'تکمیل سفارش' : get_the_title())); ?></h1><?php the_content(); ?><?php endwhile; ?>
    </main>
    <?php if ($is_fa && ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout()))) : ?>
    <?php
    $takinta_fa_product_names = array();
    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $takinta_cart_item) {
            $takinta_product = isset($takinta_cart_item['data']) ? $takinta_cart_item['data'] : null;
            if (!$takinta_product || !is_object($takinta_product) || !method_exists($takinta_product, 'get_id')) { continue; }
            $takinta_product_id = (int) $takinta_product->get_id();
            $takinta_fa_title = get_post_meta($takinta_product_id, '_takinta_title_fa', true);
            if (!$takinta_fa_title && method_exists($takinta_product, 'get_parent_id')) {
                $takinta_parent_id = (int) $takinta_product->get_parent_id();
                if ($takinta_parent_id) { $takinta_fa_title = get_post_meta($takinta_parent_id, '_takinta_title_fa', true); }
            }
            if ($takinta_fa_title && method_exists($takinta_product, 'get_name')) { $takinta_fa_product_names[$takinta_product->get_name()] = $takinta_fa_title; }
        }
    }
    ?>
    <script>
    (function(){
      var labels={
        'Cart':'سبد خرید','Shopping cart':'سبد خرید','Cart totals':'جمع سبد خرید',
        'Checkout':'تکمیل سفارش','Contact information':'اطلاعات تماس','Email address':'نشانی ایمیل',
        'Shipping address':'نشانی ارسال','Billing address':'نشانی صورتحساب','Country/Region':'کشور/منطقه','Iran':'ایران',
        'First name':'نام','Last name':'نام خانوادگی','Company name':'نام شرکت','Company (optional)':'شرکت (اختیاری)',
        'State/County':'استان','Alborz (البرز)':'البرز','City':'شهر','Address':'نشانی','Address line 2':'نشانی تکمیلی',
        'Add apartment, suite, etc.':'افزودن واحد، سوئیت و غیره','+ Add apartment, suite, etc.':'افزودن واحد، سوئیت و غیره',
        'Apartment, suite, etc.':'واحد، سوئیت و غیره','Postal code':'کد پستی','Phone (optional)':'تلفن (اختیاری)',
        'Use same address for billing':'استفاده از همین نشانی برای صورتحساب','Shipping options':'روش‌های ارسال',
        'Payment options':'روش‌های پرداخت','Order summary':'خلاصهٔ سفارش','Order notes':'یادداشت سفارش',
        'Add a note to your order':'افزودن یادداشت به سفارش','Place Order':'ثبت سفارش','Place order':'ثبت سفارش',
        'Terms and Conditions':'شرایط و ضوابط','Privacy Policy':'سیاست حفظ حریم خصوصی','and':'و',
        'By proceeding with your purchase you agree to our':'با ادامهٔ خرید، موافقت می‌کنید با',
        'There are no payment methods available. Please contact us for help placing your order.':'در حال حاضر روش پرداختی در دسترس نیست. برای ثبت سفارش با ما تماس بگیرید.',
        'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.':'روش ارسالی در دسترس نیست. نشانی را بررسی کنید یا برای راهنمایی با ما تماس بگیرید.',
        'Returning customer?':'قبلاً خرید کرده‌اید؟','Click here to login':'برای ورود اینجا کلیک کنید',
        'Have a coupon?':'کد تخفیف دارید؟','Click here to enter your code':'برای واردکردن کد تخفیف اینجا کلیک کنید',
        'Return to cart':'بازگشت به سبد خرید','Cart':'سبد خرید','Cart totals':'جمع سبد خرید',
        'Add coupons':'افزودن کد تخفیف','Add a coupon':'افزودن کد تخفیف','Add coupon':'افزودن کد تخفیف',
        'Coupon code':'کد تخفیف','Apply':'اعمال','Free shipping':'ارسال رایگان',
        'Estimated total':'مجموع تقریبی','Proceed to Checkout':'ادامه به پرداخت','Proceed to checkout':'ادامه به پرداخت',
        'Subtotal':'جمع جزء','Total':'مجموع','Shipping':'هزینهٔ ارسال','Quantity':'تعداد',
        'Product':'محصول','Price':'قیمت','Remove item':'حذف کالا','Update cart':'به‌روزرسانی سبد خرید',
        'Continue shopping':'ادامهٔ خرید','Keep shopping':'ادامهٔ خرید','Browse store':'مشاهدهٔ فروشگاه',
        'Browse our store':'مشاهدهٔ فروشگاه','Return to shop':'بازگشت به فروشگاه',
        'Your cart is currently empty!':'سبد خرید شما خالی است.','Your cart is currently empty.':'سبد خرید شما خالی است.',
        'Your cart is empty.':'سبد خرید شما خالی است.','There are no products in your cart.':'سبد خرید شما خالی است.',
        'Shipping options will be updated during checkout.':'گزینه‌های ارسال هنگام تکمیل سفارش به‌روزرسانی می‌شوند.'
      };
      var products=<?php echo wp_json_encode($takinta_fa_product_names, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>||{};
      Object.keys(products).forEach(function(key){labels[key]=products[key];});
      document.documentElement.lang='fa-IR';
      document.documentElement.dir='rtl';
      var root=document.querySelector('.takinta-commerce-content');
      if(!root)return;
      root.dir='rtl';
      function translate(){
        var walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),node;
        while((node=walker.nextNode())){var text=node.nodeValue,trimmed=text.trim();if(labels[trimmed])node.nodeValue=text.replace(trimmed,labels[trimmed]);}
        root.querySelectorAll('[aria-label],[title],[placeholder]').forEach(function(el){['aria-label','title','placeholder'].forEach(function(attr){var val=el.getAttribute(attr);if(labels[val])el.setAttribute(attr,labels[val]);});});
      }
      translate();
      if(window.MutationObserver){new MutationObserver(translate).observe(root,{childList:true,subtree:true,characterData:true});}
    })();
    </script>
    <?php endif; ?>
    <footer class="takinta-commerce-footer">© <?php echo esc_html((string) gmdate('Y')); ?> Takinta</footer>
    <?php
} else {
    ?>
    <main class="takinta-wp-fallback">
      <h1><?php esc_html_e('Page not found', 'takinta-static'); ?></h1>
      <p><?php esc_html_e('Check the address or return to the home page.', 'takinta-static'); ?></p>
      <p><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'takinta-static'); ?></a></p>
    </main>
    <?php
}
get_footer();
?>
'''
HEADER_PHP = '''<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
'''
FOOTER_PHP = '''<?php wp_footer(); ?>
</body>
</html>
'''
THEME_README = '''TAKINTA STATIC SITE THEME

Install this ZIP from WordPress Admin > Appearance > Themes > Add New > Upload Theme.
After activation, open Settings > Permalinks, choose Post name, and save once.

This theme serves the bundled static HTML pages, assets, and product catalog. Content is not editable as WordPress posts/pages. Search uses the bundled bilingual page index. The companion plugin can import catalog entries as WooCommerce drafts; manually add approved prices and publish products before ordering. It also provides a manual card-to-card gateway; recipient details and manual payment verification must be configured in WooCommerce. Checkout is available only after WooCommerce is configured.

A Persian installation guide (INSTALL-FA.md) is distributed next to this ZIP.
'''

ATTR_RE = re.compile(r'(?P<prefix>\b(?:href|src|action|poster|data-src|data-lazy-src|srcset)\s*=\s*)(?P<q>["\'])(?P<value>.*?)(?P=q)', re.I | re.S)
STYLE_RE = re.compile(r'(?P<prefix>\bstyle\s*=\s*)(?P<q>["\'])(?P<value>.*?)(?P=q)', re.I | re.S)
STYLE_BLOCK_RE = re.compile(r'(<style\b[^>]*>)(.*?)(</style\s*>)', re.I | re.S)
CSS_URL_RE = re.compile(r'''url\(\s*(?P<q>["']?)(?P<url>[^)"']+)(?P=q)\s*\)''', re.I)
FORM_RE = re.compile(r'<form\b(?P<attrs>[^>]*)>', re.I | re.S)
LOGO_IMG_RE = re.compile(r'<img\b(?=[^>]*\bsrc\s*=\s*[\"\'][^\"\']*images/logo\.png(?:\?[^\"\']*)?[\"\'])[^>]*>', re.I | re.S)


def normalize_local_path(page_path: str, value_path: str) -> str:
    if value_path.startswith('/'):
        return posixpath.normpath(value_path.lstrip('/'))
    return posixpath.normpath(posixpath.join(posixpath.dirname(page_path), value_path))


def is_external(url: str) -> bool:
    return url.startswith(('#', '//')) or bool(re.match(r'^[a-z][a-z0-9+.-]*:', url, re.I))


def preserve_query_fragment(original: str, new_path: str) -> str:
    parts = urlsplit(original)
    return urlunsplit(('', '', new_path, parts.query, parts.fragment))


def rewrite_asset_url(value: str, page_path: str) -> str:
    value = html.unescape(value.strip())
    if not value or is_external(value):
        return value
    parts = urlsplit(value)
    if not parts.path:
        return value
    resolved = normalize_local_path(page_path, parts.path)
    if resolved in ('.', ''):
        return value
    public_path = TOKEN_THEME + '/static-site/' + quote(resolved, safe='/-._~:@!$&\'()*+,;=%')
    query = parts.query
    if resolved.endswith('/assets/js/main.js') or resolved == 'assets/js/main.js':
        query_parts = [part for part in query.split('&') if part and not part.startswith('v=')]
        query_parts.append('v=20261102-search')
        query = '&'.join(query_parts)
    return urlunsplit(('', '', public_path, query, parts.fragment))


def rewrite_site_link(value: str, page_path: str) -> str:
    value = html.unescape(value.strip())
    if not value or is_external(value):
        return value
    parts = urlsplit(value)
    if not parts.path:
        return value
    resolved = normalize_local_path(page_path, parts.path)
    if resolved.lower().endswith('.html') and not (ROOT / resolved).is_file():
        current_dir = posixpath.dirname(page_path)
        if current_dir == 'fixtures' or current_dir.endswith('/fixtures'):
            language_root = current_dir[:-len('fixtures')].rstrip('/')
            candidate = posixpath.join(language_root, 'systems', posixpath.basename(resolved))
            if (ROOT / candidate).is_file():
                resolved = candidate
    if resolved in ('.', ''):
        new_path = TOKEN_HOME + '/'
    elif resolved == 'contact.php' or resolved.endswith('/contact.php'):
        new_path = TOKEN_HOME + '/contact.php'
    else:
        if resolved == 'index.html':
            new_path = TOKEN_HOME + '/index.html'
        else:
            new_path = TOKEN_HOME + '/' + quote(resolved, safe='/-._~:@!$&\'()*+,;=%')
    return urlunsplit(('', '', new_path, parts.query, parts.fragment))


def rewrite_srcset(value: str, page_path: str) -> str:
    if value.strip().lower().startswith('data:'):
        return value
    candidates = []
    for candidate in value.split(','):
        bits = candidate.strip().split()
        if not bits:
            continue
        bits[0] = rewrite_asset_url(bits[0], page_path)
        candidates.append(' '.join(bits))
    return ', '.join(candidates)


def rewrite_css_urls(source: str, page_path: str) -> str:
    def replace(match: re.Match) -> str:
        url = match.group('url').strip()
        if is_external(url):
            return match.group(0)
        new_url = rewrite_asset_url(url, page_path)
        return 'url("' + new_url + '")'
    return CSS_URL_RE.sub(replace, source)


def transform_html(source: str, page_path: str) -> str:
    # Apply the supplied Takinta wordmark and make its alternative text match the brand.
    source = source.replace('Mandai Design — Home of Shopfitting', 'Takinta Design logo')
    source = source.replace('Mandai Design؛ دنیای تجهیزات فروشگاهی', 'لوگوی تکینتا دیزاین')
    source = source.replace('Mandai Design — home', 'Takinta Design — home')
    source = source.replace('صفحهٔ اصلی ماندای دیزاین', 'صفحهٔ اصلی تکینتا دیزاین')

    def logo_tag(match: re.Match) -> str:
        tag = match.group(0)
        for attr, value in (('width', '992'), ('height', '234')):
            pattern = re.compile(r'(\b' + attr + r'\s*=\s*)(["\'])(.*?)\2', re.I | re.S)
            if pattern.search(tag):
                tag = pattern.sub(lambda m: m.group(1) + m.group(2) + value + m.group(2), tag, count=1)
        return tag
    source = LOGO_IMG_RE.sub(logo_tag, source)

    def attr_replace(match: re.Match) -> str:
        name = match.group('prefix').split('=')[0].strip().lower()
        value = match.group('value')
        if name == 'srcset':
            rewritten = rewrite_srcset(value, page_path)
        elif name == 'href':
            # HTML documents route through WordPress; other hrefs are theme assets.
            decoded = html.unescape(value.strip())
            path = urlsplit(decoded).path.lower()
            if path.endswith(('.html', '.php')) or path.endswith('/'):
                rewritten = rewrite_site_link(value, page_path)
            else:
                rewritten = rewrite_asset_url(value, page_path)
        elif name == 'action':
            rewritten = rewrite_site_link(value, page_path)
        else:
            rewritten = rewrite_asset_url(value, page_path)
        return match.group('prefix') + match.group('q') + html.escape(rewritten, quote=True) + match.group('q')

    source = ATTR_RE.sub(attr_replace, source)

    def style_replace(match: re.Match) -> str:
        value = rewrite_css_urls(match.group('value'), page_path)
        return match.group('prefix') + match.group('q') + html.escape(value, quote=True) + match.group('q')
    source = STYLE_RE.sub(style_replace, source)
    source = STYLE_BLOCK_RE.sub(lambda m: m.group(1) + rewrite_css_urls(m.group(2), page_path) + m.group(3), source)

    def form_replace(match: re.Match) -> str:
        whole = match.group(0)
        attrs = match.group('attrs')
        if re.search(r'\bdata-(?:contact|newsletter)-form\b', attrs, re.I) and not re.search(r'\baction\s*=', attrs, re.I):
            whole = whole[:-1] + ' action="' + TOKEN_HOME + '/contact.php">'
        return whole
    source = FORM_RE.sub(form_replace, source)
    return source


def write_theme_files(theme: Path) -> tuple[int, int]:
    static_root = theme / 'static-site'
    static_root.mkdir(parents=True, exist_ok=True)
    for name in COPY_DIRS:
        src = ROOT / name
        if src.is_dir():
            shutil.copytree(src, static_root / name, dirs_exist_ok=True, ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
    for name in COPY_ROOT_FILES:
        src = ROOT / name
        if src.is_file():
            shutil.copy2(src, static_root / name)
    # Include root-level media referenced by legacy campaign pages, but not scratch screenshots.
    for pattern in ('*.png', '*.jpg', '*.jpeg', '*.webp', '*.svg'):
        for src in ROOT.glob(pattern):
            if src.name.startswith(('debug', '_render')):
                continue
            shutil.copy2(src, static_root / src.name)

    html_pages = list(static_root.rglob('*.html'))
    for page in html_pages:
        rel = page.relative_to(static_root).as_posix()
        source = page.read_text(encoding='utf-8')
        page.write_text(transform_html(source, rel), encoding='utf-8')

    (theme / 'style.css').write_text(THEME_STYLE, encoding='utf-8')
    (theme / 'functions.php').write_text(FUNCTIONS_PHP, encoding='utf-8')
    (theme / 'index.php').write_text(INDEX_PHP, encoding='utf-8')
    (theme / 'header.php').write_text(HEADER_PHP, encoding='utf-8')
    (theme / 'footer.php').write_text(FOOTER_PHP, encoding='utf-8')
    (theme / 'readme.txt').write_text(THEME_README, encoding='utf-8')
    return len(html_pages), sum(p.stat().st_size for p in theme.rglob('*') if p.is_file())


def main() -> int:
    required = [ROOT / 'index.html', ROOT / 'assets' / 'css' / 'style.css', ROOT / 'assets' / 'js' / 'main.js']
    missing = [str(p) for p in required if not p.is_file()]
    if missing:
        print('Missing required site files:', *missing, sep='\n', file=sys.stderr)
        return 2

    build_search_index(ROOT)
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='takinta-wp-') as temp:
        theme = Path(temp) / THEME_SLUG
        theme.mkdir()
        pages, size = write_theme_files(theme)
        with zipfile.ZipFile(ZIP_PATH, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
            for path in sorted(theme.rglob('*')):
                if path.is_file():
                    archive.write(path, Path(THEME_SLUG) / path.relative_to(theme))
    print(f'Built: {ZIP_PATH}')
    print(f'HTML pages bundled: {pages}')
    print(f'Uncompressed theme size: {size / (1024 * 1024):.1f} MiB')
    print(f'ZIP size: {ZIP_PATH.stat().st_size / (1024 * 1024):.1f} MiB')
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
