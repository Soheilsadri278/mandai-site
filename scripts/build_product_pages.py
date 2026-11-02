#!/usr/bin/env python3
"""Generate the bilingual static product pages from data/products.json.

Uses only the Python standard library. Product data is intentionally separate from
page markup so the catalog can later be sourced from WooCommerce instead.
"""
from __future__ import annotations
import html
import json
import re
from pathlib import Path
from urllib.parse import quote

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "data" / "products.json"
OUT_EN = ROOT / "products"
OUT_FA = ROOT / "fa" / "products"

CATEGORY = {
    "en": {
        "shelving": "Shelving systems", "gondola": "Gondola shelving", "rails": "Clothing rails & racks",
        "counters": "Shop counters", "tables": "Display tables", "components": "Display fittings & components",
        "samples": "Material samples", "lighting": "Lighting", "fixtures": "Store fixtures",
    },
    "fa": {
        "shelving": "سیستم‌های قفسه‌بندی", "gondola": "قفسه‌های گوندولا", "rails": "رگال و ریل لباس",
        "counters": "کانترهای فروشگاهی", "tables": "میزهای نمایش", "components": "یراق‌آلات و قطعات نمایشگاهی",
        "samples": "نمونهٔ متریال", "lighting": "روشنایی", "fixtures": "تجهیزات فروشگاهی",
    },
}
FAMILY_TEXT = {
    "en": {
        "shelving": "A shelving layout in the Takinta Design range. The exact dimensions, finish, configuration and included components depend on the selected model. Please confirm the specification and compatibility with your store before ordering.",
        "gondola": "A freestanding gondola display for retail merchandising. Confirm the exact layout, dimensions, shelf configuration and finish for this model before ordering.",
        "rails": "A clothing rail or rack for presenting garments in retail spaces. Width, height, frame finish and base options vary by model; request confirmation of the configuration that fits your space.",
        "counters": "A retail counter for checkout, consultation or reception use, depending on the model. Confirm dimensions, internal layout, finish and delivery configuration before ordering.",
        "tables": "A display table or cube from the Takinta Design range. Dimensions, top material and finish vary by model; contact us to confirm the available configuration.",
        "components": "A component or fitting for a shopfitting system. Compatibility, dimensions, pack quantity and load limits are model-specific; please confirm these details before ordering.",
        "samples": "A material sample to help review the finish before specifying shopfitting furniture. Availability and sample ordering conditions should be confirmed with the team.",
        "lighting": "A lighting product for retail environments. Confirm electrical specifications, mounting requirements and availability for the selected model before ordering.",
        "fixtures": "A store fixture from the Takinta Design collection. Please contact us to confirm its dimensions, materials, available finishes and delivery details.",
    },
    "fa": {
        "shelving": "این محصول چیدمانی از خانوادهٔ قفسه‌بندی Takinta Design است. ابعاد دقیق، روکش، ترکیب اجزا و اقلام همراه به مدل انتخابی بستگی دارد؛ پیش از سفارش، مشخصات و سازگاری آن با فضای فروشگاه را تأیید کنید.",
        "gondola": "این محصول از گروه قفسه‌های ایستادهٔ گوندولا برای نمایش کالا در فروشگاه است. چیدمان، ابعاد، ترکیب طبقات و پرداخت همین مدل را پیش از سفارش تأیید کنید.",
        "rails": "این رگال یا ریل برای نمایش پوشاک در فضای فروشگاهی است. عرض، ارتفاع، پرداخت سازه و گزینه‌های پایه با توجه به مدل فرق می‌کند؛ برای انتخاب متناسب با فضا، مشخصات را استعلام کنید.",
        "counters": "این کانتر بسته به مدل می‌تواند برای صندوق، مشاوره یا پذیرش استفاده شود. ابعاد، چیدمان داخلی، پرداخت و وضعیت تحویل را پیش از سفارش تأیید کنید.",
        "tables": "این محصول میز یا مکعب نمایش از مجموعهٔ Takinta Design است. ابعاد، جنس صفحه و پرداخت در مدل‌های مختلف تفاوت دارد؛ برای تأیید ترکیب قابل سفارش با ما تماس بگیرید.",
        "components": "این محصول قطعه یا یراق‌آلات یک سیستم فروشگاهی است. سازگاری، اندازه، تعداد در بسته و ظرفیت بار مخصوص هر مدل است؛ پیش از سفارش این مشخصات را تأیید کنید.",
        "samples": "این نمونهٔ متریال برای بررسی روکش پیش از انتخاب تجهیزات فروشگاهی است. موجودی و شرایط دریافت نمونه را با تیم فروش هماهنگ کنید.",
        "lighting": "این محصول روشنایی برای فضای فروشگاهی است. مشخصات برق، شیوهٔ نصب و موجودی مدل انتخابی را پیش از سفارش تأیید کنید.",
        "fixtures": "این محصول از تجهیزات فروشگاهی Takinta Design است. برای تأیید ابعاد، جنس، پرداخت‌های قابل انتخاب و جزئیات تحویل با ما تماس بگیرید.",
    },
}
LABELS = {
    "en": {
        "home":"Home", "catalog":"Products", "request":"Request current price & delivery", "call":"Call for consultation",
        "price_title":"Price & availability", "price_note":"Prices, market currency, options and delivery estimates can change. Confirm the current quote before ordering.",
        "sample_note":"Sample item. Confirm the current sample terms and availability before requesting.",
        "options":"Available options", "finish":"Finish / colour", "gallery":"Product gallery", "overview":"Product overview",
        "details":"Product details", "confirm":"Please confirm before ordering", "confirm_text":"Exact dimensions, components, included items, compatibility, finish and lead time are product-specific. We will confirm these against the selected model before an order is placed.",
        "shipping":"Delivery & assembly", "shipping_text":"Preparation time and transport depend on the item, destination and selected configuration. Request a current estimate.",
        "included":"What's included", "included_text":"The listing snapshot does not contain a verified bill of materials for this item. Ask us to confirm the included components.",
        "related":"You may also like", "finish_selected":"Selected finish", "contact":"Contact", "spec_label":"Model", "family":"Product family",
        "back":"Back to products", "email":"Email about this product", "vat":"Final price, VAT and shipping are confirmed at quotation.",
    },
    "fa": {
        "home":"خانه", "catalog":"محصولات", "request":"استعلام قیمت روز و زمان تحویل", "call":"تماس برای مشاوره",
        "price_title":"قیمت و موجودی", "price_note":"قیمت، ارز بازار، گزینه‌ها و زمان تحویل ممکن است تغییر کند؛ پیش از سفارش، استعلام نهایی بگیرید.",
        "sample_note":"این صفحه مربوط به نمونهٔ متریال است. شرایط دریافت و موجودی نمونه را پیش از درخواست هماهنگ کنید.",
        "options":"گزینه‌های موجود", "finish":"رنگ و روکش", "gallery":"گالری محصول", "overview":"دربارهٔ محصول",
        "details":"مشخصات محصول", "confirm":"پیش از سفارش تأیید کنید", "confirm_text":"ابعاد دقیق، قطعات، اقلام همراه، سازگاری، روکش و زمان آماده‌سازی برای هر محصول متفاوت است. پیش از ثبت درخواست، مشخصات مدل انتخابی را با شما تأیید می‌کنیم.",
        "shipping":"ارسال و مونتاژ", "shipping_text":"زمان آماده‌سازی و حمل به کالا، مقصد و ترکیب انتخابی بستگی دارد. برای برآورد روز، استعلام بگیرید.",
        "included":"اقلام همراه", "included_text":"اطلاعات فعلی فهرست کالا، فهرست تأییدشدهٔ قطعات داخل بسته را ندارد. پیش از سفارش، اقلام همراه را از ما بپرسید.",
        "related":"محصولات پیشنهادی", "finish_selected":"روکش انتخاب‌شده", "contact":"ارتباط با ما", "spec_label":"مدل", "family":"خانوادهٔ محصول",
        "back":"بازگشت به محصولات", "email":"ایمیل دربارهٔ این محصول", "vat":"قیمت نهایی، مالیات و هزینهٔ ارسال هنگام استعلام تأیید می‌شوند.",
    },
}

FA_MAP = str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹")

def esc(value: str) -> str:
    return html.escape(str(value or ""), quote=True)

def translate_options(raw: str) -> str:
    exact = {
        "2 options":"۲ گزینه", "3 options":"۳ گزینه", "4 options":"۴ گزینه", "5 options":"۵ گزینه", "6 options":"۶ گزینه",
        "2 steel colors":"۲ رنگ فولادی", "2 colours":"۲ رنگ", "3 wood decors":"۳ طرح چوبی",
        "4 wood decors":"۴ طرح چوبی", "3 wood decors, 2 heights":"۳ طرح چوبی، ۲ ارتفاع", "4 decors":"۴ طرح",
    }
    if raw in exact: return exact[raw]
    return raw.translate(FA_MAP)

def translate_finish(raw: str) -> str:
    exact = {
        "Oak Halifax Nature":"بلوط هالیفاکس طبیعی", "Oak Halifax Tobacco":"بلوط هالیفاکس تنباکویی",
        "Oak Hamilton Nature":"بلوط همیلتون طبیعی", "White":"سفید", "Black":"مشکی",
        "Black steel":"فولاد مشکی", "White steel":"فولاد سفید", "Glass":"شیشه", "Wood":"چوب",
        "Marble Look":"طرح سنگ مرمر", "Stone Grey":"خاکستری سنگی", "Premium White":"سفید پریمیوم",
        "White and wood":"سفید و چوبی", "Wood and anthracite":"چوب و آنتراسیت", "Black with wooden base":"مشکی با پایهٔ چوبی",
        "White with wooden base":"سفید با پایهٔ چوبی", "Black steel and glass":"فولاد مشکی و شیشه",
        "Wood finish, black steel":"روکش چوب با سازهٔ فولادی مشکی", "Wood finish, white steel":"روکش چوب با سازهٔ فولادی سفید",
    }
    if raw in exact: return exact[raw]
    return raw

def product_url(lang: str, slug: str) -> str:
    return ("/fa/products/" if lang == "fa" else "/products/") + slug + ".html"

def asset_url(src: str, root_depth: str) -> str:
    """Resolve source-page relative image paths from the repository root."""
    if not src or re.match(r'^(?:[a-z][a-z0-9+.-]*:|//|/|#)', src, re.I):
        return src
    import posixpath
    while src.startswith('../'):
        src = src[3:]
    normalized = posixpath.normpath(src)
    if normalized.startswith('../') or normalized == '..':
        return src
    return root_depth + normalized

def render_site_footer(lang: str, root_depth: str, home: str, lang_url: str) -> str:
    """Reuse the same bilingual footer markup as the main site pages."""
    fa = lang == "fa"
    source = ROOT / ("fa" if fa else "") / "index.html"
    source_html = source.read_text(encoding="utf-8")
    match = re.search(
        r'<footer\b(?=[^>]*\bclass=["\'][^"\']*\bsite-footer\b[^"\']*["\'])[^>]*>.*?</footer>',
        source_html,
        re.I | re.S,
    )
    if not match:
        raise RuntimeError(f"Could not find the shared footer in {source}")

    footer = match.group(0)
    page_prefix = root_depth + ("fa/" if fa else "")

    def rewrite_attribute(m: re.Match) -> str:
        name, quote_char, value = m.group(1), m.group(2), m.group(3)
        if name.lower() == "href":
            if value in {"fa/index.html", "../index.html"}:
                value = lang_url
            elif value.startswith("../index.html#"):
                value = home + value[len("../index.html"):]
            elif value.startswith("#") and value != "#":
                value = home + value
            elif value.startswith(("pages/", "fixtures/")):
                value = page_prefix + value
        elif name.lower() == "src":
            if value.startswith("../images/"):
                value = value[3:]
            if value.startswith("images/"):
                value = root_depth + value
        elif name.lower() == "action" and value.endswith("contact.php"):
            value = root_depth + "contact.php"
        return f'{name}={quote_char}{value}{quote_char}'

    footer = re.sub(r"(href|src|action)=(\"|')(.+?)\2", rewrite_attribute, footer, flags=re.I)
    # The English source relies on the site-wide script for AJAX; give product pages
    # a working no-JavaScript fallback as well as the same enhanced footer behavior.
    footer = re.sub(
        r'(<form\b(?=[^>]*\bclass=["\'][^"\']*\bnewsletter__form\b[^"\']*["\'])[^>]*)(>)',
        lambda m: m.group(1) if re.search(r"\baction=", m.group(1), re.I) else m.group(1) + f' action="{root_depth}contact.php"' + m.group(2),
        footer,
        count=1,
        flags=re.I,
    )
    return footer

def render_site_header(lang: str, root_depth: str, home: str, lang_url: str) -> str:
    """Reuse the complete announcement, desktop/mobile header and search UI."""
    fa = lang == "fa"
    source = ROOT / ("fa" if fa else "") / "index.html"
    source_html = source.read_text(encoding="utf-8")
    start_match = re.search(
        r'<div\b(?=[^>]*\bclass=["\'][^"\']*\btopbar\b[^"\']*["\'])[^>]*>',
        source_html,
        re.I,
    )
    if not start_match:
        raise RuntimeError(f"Could not find the shared topbar in {source}")
    end = source_html.find("</header>", start_match.end())
    if end < 0:
        raise RuntimeError(f"Could not find the shared header end in {source}")
    header = source_html[start_match.start():end + len("</header>")]
    page_prefix = root_depth + ("fa/" if fa else "")

    def resolve_href(value: str) -> str:
        if value.startswith(("http:", "https:", "mailto:", "tel:", "//", "data:")) or value == "#":
            return value
        if value.startswith(("index.html#", "../index.html#")):
            return home + value[value.find("#"):]
        if value == "fa/index.html":
            return lang_url
        if value in {"index.html", "../index.html"}:
            return home
        if value.startswith("#"):
            return home + value
        if value.startswith(("pages/", "fixtures/", "systems/", "sectors/")):
            return page_prefix + value
        return value

    # Localize the announcement's data-driven sample link; tel links remain unchanged.
    data_pattern = re.compile(r'(<script\b(?=[^>]*\bdata-topbar-data\b)[^>]*>)(.*?)(</script>)', re.I | re.S)
    def rewrite_data(m: re.Match) -> str:
        items = json.loads(m.group(2))
        for item in items:
            if item.get("href"):
                item["href"] = resolve_href(item["href"])
        return m.group(1) + json.dumps(items, ensure_ascii=False) + m.group(3)
    header = data_pattern.sub(rewrite_data, header, count=1)

    def rewrite_tag(m: re.Match) -> str:
        tag = m.group(0)
        class_match = re.search(r'\bclass=("|\')(.*?)\1', tag, re.I | re.S)
        is_language_switch = bool(class_match and "language-switch" in class_match.group(2).split())
        is_language_switch = is_language_switch or any(marker in tag for marker in ('lang="en"', "lang='en'", 'lang="fa"', "lang='fa'", 'hreflang="en"', "hreflang='en'", 'hreflang="fa"', "hreflang='fa'"))
        def rewrite_attr(a: re.Match) -> str:
            name, quote_char, value = a.group(1), a.group(2), a.group(3)
            if name.lower() == "href":
                value = lang_url if is_language_switch else resolve_href(value)
            elif name.lower() == "src":
                if value.startswith("../images/"):
                    value = value[3:]
                if value.startswith("images/"):
                    value = root_depth + value
            return f'{name}={quote_char}{value}{quote_char}'
        return re.sub(r"(href|src|action)=(\"|')(.+?)\2", rewrite_attr, tag, flags=re.I | re.S)
    def rewrite_markup(m: re.Match) -> str:
        token = m.group(0)
        return token if token.startswith("<!--") else rewrite_tag(m)
    header = re.sub(r"<!--.*?-->|<[^>]+>", rewrite_markup, header, flags=re.S)
    return header

def quote_links(lang: str, product: dict) -> tuple[str,str]:
    title = product["titleFa"] if lang == "fa" else product["titleEn"]
    subject = ("استعلام محصول: " if lang == "fa" else "Product enquiry: ") + title
    body = ("سلام، لطفاً قیمت روز و جزئیات این محصول را اعلام کنید:\n" if lang == "fa" else "Hello, please send the current price and details for this product:\n") + title + "\n" + product_url(lang, product["slug"])
    mail = "mailto:info@mandai-design.com?subject=" + quote(subject) + "&body=" + quote(body)
    phone = "tel:+989212397467"
    return mail, phone

def option_texts(product: dict, lang: str) -> list[str]:
    vals=[]
    if product.get("variantLabels"):
        vals.extend(product["variantLabels"])
    for row in product.get("optionsEn", []):
        raw=row[0] if isinstance(row,(list,tuple)) else str(row)
        val=translate_options(raw) if lang=="fa" else raw
        if val and val not in vals: vals.append(val)
    return vals

def render_gallery(product: dict, lang: str, root_depth: str) -> str:
    imgs=product.get("images",[])
    if not imgs: return '<div class="pp-gallery__empty" role="img" aria-label="Product image unavailable"></div>'
    first=imgs[0]
    thumb_buttons=[]
    for i,img in enumerate(imgs):
        alt=img.get("alt") or (product['titleFa'] if lang=='fa' else product['titleEn'])
        thumb_buttons.append(f'<button class="pp-thumb{" is-active" if i==0 else ""}" type="button" data-gallery-thumb data-src="{esc(asset_url(img["src"],root_depth))}" data-alt="{esc(alt)}" aria-label="{esc(alt)}" aria-pressed="{"true" if i==0 else "false"}"><img src="{esc(asset_url(img["src"],root_depth))}" alt="" loading="lazy"></button>')
    alt=product["titleFa"] if lang=="fa" else product["titleEn"]
    return f'''<div class="pp-gallery" aria-label="{esc(LABELS[lang]['gallery'])}">
      <div class="pp-gallery__main"><img data-gallery-main src="{esc(asset_url(first['src'],root_depth))}" alt="{esc(alt)}" fetchpriority="high"><span class="pp-gallery__count">{len(imgs)} {"تصویر" if lang=="fa" else "images"}</span></div>
      <div class="pp-gallery__thumbs" role="list">{''.join(thumb_buttons)}</div>
    </div>'''

def render_swatches(product: dict, lang: str, root_depth: str) -> str:
    swatches=product.get("swatches",[])
    labels=LABELS[lang]
    chunks=[]
    for i,sw in enumerate(swatches[:12]):
        en=sw.get("alt","")
        label=translate_finish(en) if lang=="fa" else en
        src=sw.get("src","")
        chunks.append(f'<button class="pp-swatch" type="button" data-finish-choice="{esc(label)}" aria-pressed="{"true" if i==0 else "false"}" title="{esc(label)}"><img src="{esc(asset_url(src,root_depth))}" alt=""><span>{esc(label)}</span></button>')
    if not chunks: return ""
    selected=chunks[0]
    return f'''<fieldset class="pp-finish"><legend>{esc(labels['finish'])}</legend><div class="pp-finish__list">{''.join(chunks)}</div><p class="pp-finish__selected" aria-live="polite"><span>{esc(labels['finish_selected'])}: </span><bdi data-selected-finish>{esc(translate_finish(swatches[0].get('alt','')) if lang=='fa' else swatches[0].get('alt',''))}</bdi></p></fieldset>'''

def render_price(product: dict, lang: str) -> str:
    labels=LABELS[lang]
    snap=product.get("priceSnapshot","")
    if product.get("sourceType")=="sample":
        amount="رایگان" if lang=="fa" else "Free sample"
        return f'<div class="pp-price"><span class="pp-price__label">{esc(labels["price_title"])}</span><strong>{esc(amount)}</strong></div>'
    if snap and not product.get("priceConflict"):
        if lang=="fa":
            raw=snap
            prefix="شروع از " if raw.lower().startswith("from ") else ""
            raw=re.sub(r'^from\s*','',raw,flags=re.I)
            raw=raw.translate(FA_MAP)
            val=f'{prefix}<bdi dir="ltr">{esc(raw)}</bdi>'
            if raw.startswith("$0"): val="رایگان"
        else:
            val=esc(snap)
        return f'<div class="pp-price"><span class="pp-price__label">{esc(labels["price_title"])}</span><strong>{val}</strong></div>'
    msg="برای دریافت قیمت روز استعلام بگیرید" if lang=="fa" else "Request the current price"
    return f'<div class="pp-price pp-price--quote"><span class="pp-price__label">{esc(labels["price_title"])}</span><strong>{esc(msg)}</strong></div>'

def render_related(product: dict, all_products: list[dict], lang: str, prefix: str, root_depth: str) -> str:
    peers=[p for p in all_products if p["category"]==product["category"] and p["slug"]!=product["slug"] and p.get("images")]
    peers=(peers[:3] if len(peers)>=3 else peers)
    if not peers: peers=[p for p in all_products if p['slug']!=product['slug'] and p.get('images')][:3]
    cards=[]
    for p in peers:
        title=p['titleFa'] if lang=='fa' else p['titleEn']
        img=asset_url(p['images'][0]['src'], root_depth)
        related_category = CATEGORY[lang].get(p["category"], CATEGORY[lang]["fixtures"])
        cards.append(f'<a class="pp-related-card" href="{prefix}{esc(p["slug"])}.html"><img src="{esc(img)}" alt="{esc(title)}" loading="lazy"><span>{esc(title)}</span><small>{esc(related_category)}</small></a>')
    return f'<section class="pp-related"><div class="container"><h2>{esc(LABELS[lang]["related"])}</h2><div class="pp-related__grid">{"".join(cards)}</div></div></section>' if cards else ""

def render_page(product: dict, all_products: list[dict], lang: str) -> str:
    fa=lang=="fa"; l=LABELS[lang]
    en_title=product['titleEn']; title=product['titleFa'] if fa else en_title
    category=CATEGORY[lang].get(product['category'],CATEGORY[lang]['fixtures'])
    desc=product.get('descriptionFa' if fa else 'descriptionEn') or FAMILY_TEXT[lang].get(product['category'],FAMILY_TEXT[lang]['fixtures'])
    desc=desc.replace('{family}',category)
    root_depth='../../' if fa else '../'
    home=root_depth+('fa/index.html' if fa else 'index.html')
    samples=root_depth+('fa/pages/samples.html' if fa else 'pages/samples.html')
    catalog=root_depth+('fa/index.html#categories' if fa else 'index.html#categories')
    lang_url=product_url('en' if fa else 'fa',product['slug'])
    # Product pages live at these static paths in the intended Takinta site deployment.
    canonical='https://www.mandai-design.com'+product_url(lang,product['slug'])
    alt_lang='en' if fa else 'fa'
    desc_meta=desc[:155]
    mail,phone=quote_links(lang,product)
    quote_label=l['request']
    price=render_price(product,lang)
    swatches=render_swatches(product,lang,root_depth)
    opt_texts=option_texts(product,lang)
    opt_html=''
    if opt_texts:
        lis=''.join(f'<li>{esc(x)}</li>' for x in opt_texts[:5])
        opt_html=f'<section class="pp-options"><h2>{esc(l["options"])}</h2><ul>{lis}</ul></section>'
    else:
        opt_html=f'<p class="pp-option-note">{esc("برای اطلاع از رنگ‌ها و ترکیب‌های قابل سفارش، استعلام بگیرید." if fa else "Ask us to confirm the available finishes and configurations.")}</p>'
    if product.get('sourceType')=='sample':
        price_note=l['sample_note']
    else:
        price_note=l['price_note']
    # A compact, honest visual specification list: show only values actually found in the listing snapshot.
    spec_rows=[]
    if product.get('sourceType')=='sample':
        spec_rows=[('نوع' if fa else 'Type','نمونهٔ روکش' if fa else 'Finish sample')]
    elif product.get('quickDelivery'):
        spec_rows.append(('ارسال سریع' if fa else 'Delivery','برچسب ارسال سریع در فهرست محصول دیده شده است' if fa else 'Marked for quick delivery in the listing snapshot'))
    if product.get('referenceHandle'):
        spec_rows.append(('شناسهٔ مرجع' if fa else 'Reference handle',product['referenceHandle']))
    spec_html=''.join(f'<div class="pp-spec"><dt>{esc(k)}</dt><dd><bdi dir="auto">{esc(v)}</bdi></dd></div>' for k,v in spec_rows)
    img_json=[asset_url(i['src'],root_depth) for i in product.get('images',[])]
    first_img=img_json[0] if img_json else ''
    jsonld={"@context":"https://schema.org","@type":"Product","name":title,"description":desc,"image":img_json,"brand":{"@type":"Brand","name":"Takinta Design"},"url":canonical}
    jsonld_text=json.dumps(jsonld,ensure_ascii=False).replace('<','\\u003c')
    related_prefix=root_depth+('fa/products/' if fa else 'products/')
    related=render_related(product,all_products,lang,related_prefix,root_depth)
    body_dir='rtl' if fa else 'ltr'
    lang_code='fa' if fa else 'en'
    logo=root_depth+'images/logo.png'
    global_css=root_depth+'assets/css/style.css'
    lang_css=(root_depth+'assets/css/rtl.css') if fa else ''
    custom_css=root_depth+'assets/css/product-page.css'
    custom_js=root_depth+'assets/js/product-page.js'
    main_js=root_depth+'assets/js/main.js'
    footer=render_site_footer(lang,root_depth,home,lang_url)
    header=render_site_header(lang,root_depth,home,lang_url)
    option_label=''.join(f'<span class="pp-chip">{esc(s)}</span>' for s in opt_texts[:3])
    # Reference handles are migration keys only; show only the human-facing local title.
    return f'''<!doctype html>
<html lang="{lang_code}" dir="{body_dir}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(title)} | Takinta Design</title>
<meta name="description" content="{esc(desc_meta)}"><meta name="robots" content="index,follow,max-image-preview:large">
<link rel="canonical" href="{esc(canonical)}"><link rel="alternate" hreflang="{alt_lang}" href="https://www.mandai-design.com{esc(lang_url)}">
<link rel="alternate" hreflang="{lang_code}" href="{esc(canonical)}">
<meta property="og:type" content="product"><meta property="og:site_name" content="Takinta Design"><meta property="og:title" content="{esc(title)} | Takinta Design"><meta property="og:description" content="{esc(desc_meta)}"><meta property="og:url" content="{esc(canonical)}"><meta property="og:image" content="{esc(first_img)}"><meta property="og:locale" content="{'fa_IR' if fa else 'en_US'}">
<link rel="preload" href="{esc(logo)}" as="image"><link rel="stylesheet" href="{esc(global_css)}">{f'<link rel="stylesheet" href="{esc(lang_css)}">' if lang_css else ''}<link rel="stylesheet" href="{esc(custom_css)}">
<script type="application/ld+json">{jsonld_text}</script>
</head>
<body class="product-page" data-product-slug="{esc(product['slug'])}">
<a class="skip-link" href="#main-content">{"رفتن به محتوای اصلی" if fa else "Skip to main content"}</a>
{header}
<main id="main-content">
<div class="container pp-breadcrumb"><a href="{esc(home)}">{esc(l['home'])}</a><span aria-hidden="true">/</span><a href="{esc(catalog)}">{esc(l['catalog'])}</a><span aria-hidden="true">/</span><span aria-current="page">{esc(title)}</span></div>
<section class="container pp-main" aria-labelledby="product-title">
<div class="pp-layout">{render_gallery(product,lang,root_depth)}
<div class="pp-buy">
<p class="pp-eyebrow">{esc(category)}</p><h1 id="product-title">{esc(title)}</h1>
<div class="pp-rating-note">{'تجهیزات فروشگاهی Takinta Design' if fa else 'Takinta Design store fixture'}</div>
{price}
<p class="pp-price-note">{esc(price_note)}</p>
<p class="pp-tax-note">{esc(l['vat'])}</p>
{swatches}
{opt_html}
<div class="pp-availability"><span class="pp-status-dot" aria-hidden="true"></span><span>{'موجودی و زمان آماده‌سازی: با استعلام تأیید می‌شود' if fa else 'Availability & preparation time: confirm by enquiry'}</span></div>
<div class="pp-actions"><a class="pp-button pp-button--dark" data-product-quote href="{esc(mail)}">{esc(quote_label)}</a><a class="pp-button pp-button--line" href="{esc(phone)}">{esc(l['call'])}</a></div>
<p class="pp-market-note">{esc(l['vat'])}</p>
</div></div>
<div class="pp-content-grid">
<article class="pp-description"><h2>{esc(l['overview'])}</h2><p>{esc(desc)}</p>
<div class="pp-detail-block"><h3>{esc(l['confirm'])}</h3><p>{esc(l['confirm_text'])}</p></div>
<div class="pp-detail-block"><h3>{esc(l['shipping'])}</h3><p>{esc(l['shipping_text'])}</p></div>
<div class="pp-detail-block"><h3>{esc(l['included'])}</h3><p>{esc(l['included_text'])}</p></div>
</article>
<aside class="pp-summary"><h2>{esc(l['details'])}</h2><dl><div class="pp-spec"><dt>{esc(l['family'])}</dt><dd>{esc(category)}</dd></div><div class="pp-spec"><dt>{esc(l['spec_label'])}</dt><dd><bdi dir="auto">{esc(product['slug'])}</bdi></dd></div>{spec_html}</dl><p class="pp-summary__note">{esc('برای دریافت اندازه‌ها، اقلام همراه و راهنمای نصب همین مدل، با ما هماهنگ کنید.' if fa else 'Contact us for the dimensions, included components and installation information for this exact model.')}</p></aside>
</div>
</section>
{related}
<section class="pp-help"><div class="container pp-help__inner"><div><p class="pp-eyebrow">{esc(l['contact'])}</p><h2>{'برای انتخاب محصول مناسب راهنمایی می‌خواهید؟' if fa else 'Need help choosing the right fixture?'}</h2><p>{'مدل، ابعاد فضای فروشگاه و روکش دلخواه را در درخواست خود بنویسید تا تیم ما راهنمایی‌تان کند.' if fa else 'Include the model, your available space and preferred finish in your enquiry so our team can advise you.'}</p></div><a class="pp-button pp-button--dark" data-product-quote href="{esc(mail)}">{esc(l['email'])}</a></div></section>
</main>
{footer}
<script src="{esc(main_js)}" defer></script><script src="{esc(custom_js)}" defer></script>
</body></html>'''

def main():
    catalog=json.loads(DATA.read_text(encoding='utf-8'))
    products=catalog['products']
    slugs=[p['slug'] for p in products]
    if len(products)!=305 or len(set(slugs))!=305:
        raise SystemExit(f'Expected 305 unique product records, got {len(products)} / {len(set(slugs))}')
    OUT_EN.mkdir(parents=True,exist_ok=True); OUT_FA.mkdir(parents=True,exist_ok=True)
    for p in products:
        (OUT_EN/f"{p['slug']}.html").write_text(render_page(p,products,'en'),encoding='utf-8')
        (OUT_FA/f"{p['slug']}.html").write_text(render_page(p,products,'fa'),encoding='utf-8')
    # Relink product cards using stable appearance indexes, preserving every other byte in each page.
    groups={}
    for p in products:
        for a in p.get('appearances',[]):
            groups.setdefault((a['page'],a['selector']),{})[a['index']]=p['slug']
    def rewrite(path: Path, selector: str, index_to_slug: dict, lang: str):
        raw=path.read_text(encoding='utf-8')
        pattern=re.compile(r'<a\b[^>]*>',re.I|re.S)
        index=0;matched=0
        def replace_tag(m):
            nonlocal index,matched
            tag=m.group(0)
            cm=re.search(r'\bclass\s*=\s*(["\'])(.*?)\1',tag,re.I|re.S)
            if not cm or selector not in cm.group(2).split(): return tag
            current=index; index+=1
            if current not in index_to_slug:return tag
            hm=re.search(r'\bhref\s*=\s*(["\'])(.*?)\1',tag,re.I|re.S)
            if not hm:return tag
            slug=index_to_slug[current]
            target=(Path('fa/products') if lang=='fa' else Path('products'))/f'{slug}.html'
            rel=Path(__import__('os').path.relpath(target,path.parent)).as_posix()
            newtag=tag[:hm.start(2)]+rel+tag[hm.end(2):]
            matched+=1
            return newtag
        out=pattern.sub(replace_tag,raw)
        if matched!=len(index_to_slug):
            raise SystemExit(f'Anchor rewrite mismatch {path} {selector}: matched {matched}, expected {len(index_to_slug)}')
        if out!=raw:path.write_text(out,encoding='utf-8')
        return matched
    relinked=0
    for (page,selector),idxmap in groups.items():
        source=ROOT/page
        relinked+=rewrite(source,selector,idxmap,'en')
        fa_path=ROOT/'fa'/page
        if not fa_path.exists():raise SystemExit(f'Missing Persian page {fa_path}')
        relinked+=rewrite(fa_path,selector,idxmap,'fa')
    print(f'Generated {len(products)} English + {len(products)} Persian pages; relinked {relinked} product/sample anchors.')

if __name__=='__main__':main()
