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

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "wordpress"
ZIP_PATH = OUT_DIR / "takinta-static-theme.zip"
THEME_SLUG = "takinta-static"
TOKEN_THEME = "__TAKINTA_THEME_URI__"
TOKEN_HOME = "__TAKINTA_HOME_URL__"
COPY_DIRS = ["assets", "images", "fonts", "fa", "pages", "fixtures", "sectors", "systems", "products"]
COPY_ROOT_FILES = ["index.html", "favicon.svg"]

THEME_STYLE = '''/*
Theme Name: Takinta Static Site
Author: Takinta Design
Description: Classic WordPress theme wrapper for the supplied bilingual Takinta static website.
Version: 1.0.1
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
        $route = 'index.html';
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
}
add_action('after_setup_theme', 'takinta_static_theme_setup');
'''

INDEX_PHP = '''<?php
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<main class="takinta-wp-fallback">
  <h1><?php esc_html_e('Page not found', 'takinta-static'); ?></h1>
  <p><?php esc_html_e('Check the address or return to the home page.', 'takinta-static'); ?></p>
  <p><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'takinta-static'); ?></a></p>
</main>
<?php get_footer(); ?>
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

This theme serves the bundled static HTML pages and assets. Content is not editable as WordPress posts/pages. Cart, account, search, and payment are not connected to WooCommerce.

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
    return urlunsplit(('', '', public_path, parts.query, parts.fragment))


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
