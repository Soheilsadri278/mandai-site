/* ============================================================
   TAKINTA DESIGN — جاوااسکریپت اصلی (بدون وابستگی خارجی)
   ------------------------------------------------------------
   شامل: نوار اعلان چرخشی، هدر چسبان، زیرمنوها، منوی موبایل،
   جستجو، اسلایدرها، آکاردئون سوالات، فرم تماس و خبرنامه، Toast
   ============================================================ */
(function () {
  "use strict";

  /* ── ابزارهای کوچک ─────────────────────────────────────── */
  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };
  var searchIndexUrl = (function () {
    var script = document.currentScript;
    if (!script) {
      var scripts = document.getElementsByTagName("script");
      for (var i = scripts.length - 1; i >= 0; i--) {
        if (/\bmain\.js(?:[?#]|$)/i.test(scripts[i].src || "")) { script = scripts[i]; break; }
      }
    }
    try {
      return script && script.src ? new URL("search-index.json?v=20261102-search", script.src).href : new URL("assets/js/search-index.json?v=20261102-search", document.baseURI).href;
    } catch (e) {
      return "assets/js/search-index.json";
    }
  })();

  /* ════════════════════════════════════════════════════════
     Motion system: editorial reveals, staggered cards, progress
     Content stays visible if motion is reduced or JS is unavailable.
     ════════════════════════════════════════════════════════ */
  (function () {
    var reduceMotion = typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduceMotion) return;

    /* Thin reading-progress accent; transform-only updates avoid layout work. */
    if (document.body && !$(".scroll-progress")) {
      var progress = document.createElement("div");
      progress.className = "scroll-progress";
      progress.setAttribute("aria-hidden", "true");
      progress.style.transformOrigin = getComputedStyle(document.documentElement).direction === "rtl" ? "right center" : "left center";
      document.body.appendChild(progress);
      var progressTicking = false;
      function updateProgress() {
        var total = document.documentElement.scrollHeight - window.innerHeight;
        var ratio = total > 0 ? Math.max(0, Math.min(1, window.scrollY / total)) : 0;
        progress.style.transform = "scaleX(" + ratio + ")";
        progressTicking = false;
      }
      function requestProgressUpdate() {
        if (progressTicking) return;
        progressTicking = true;
        window.requestAnimationFrame(updateProgress);
      }
      window.addEventListener("scroll", requestProgressUpdate, { passive: true });
      window.addEventListener("resize", requestProgressUpdate, { passive: true });
      window.addEventListener("load", requestProgressUpdate, { once: true });
      updateProgress();
    }

    if (!("IntersectionObserver" in window)) return;

    var targets = [];
    function addReveal(el, variant) {
      if (!el || el.classList.contains("motion-reveal") || el.closest(".motion-reveal")) return false;
      el.classList.add("motion-reveal");
      if (variant) el.classList.add(variant);
      targets.push(el);
      return true;
    }

    var revealSelectors = [
      ".intro__inner", ".carousel-section__head", ".experience__media", ".experience__copy",
      ".references__head", ".benefits__heading", ".modular .container", ".video-banner__title",
      ".bestsellers .container > h2", ".help__heading", ".reviews__heading", ".reviews__slider",
      ".faq__heading", ".contact__head", ".sector-alt__title", ".sector-alt__media", ".sector-alt__copy",
      ".sys-intro__title", ".sys-intro__text", ".sector-cta .container", ".custom-sec .container",
      ".steps-sec > .container", ".seo-text__heading", ".ab-hero h1", ".ab-exp > *",
      ".ct-top h1", ".op-top h1", ".sp-intro h1", ".ds-intro h1", ".hw-intro h1",
      ".sp-select h2", ".ds-how h2", ".ab-team h2", ".contact__form", ".faq__list > .faq__item",
      ".pet-intro", ".pet-feature__media", ".pet-feature__copy", ".pet-video__frame", ".pet-video__copy",
      ".pet-section-head", ".pet-look__image", ".pet-planning__image", ".pet-planning__copy",
      ".site-footer__top > *", ".site-footer__col", ".site-footer__bottom > *",
      ".site-footer__payments > li", ".site-footer__socials > *", ".site-footer .newsletter__form"
    ];
    revealSelectors.forEach(function (selector) {
      $$(selector).forEach(function (el) { addReveal(el); });
    });

    /* Reusable grids and horizontal rails reveal in a restrained stagger. */
    var staggerGroups = [
      ".carousel[data-carousel]", ".benefits__grid", ".bestsellers__grid", ".help__grid",
      ".feat-grid", ".mini-grid", ".steps-grid", ".sp-how__grid", ".sp-grid",
      ".ct-cards", ".ab-team__grid", ".fref-grid", ".fsh-grid-wrap", ".pet-product-grid",
      ".pet-related__grid", ".pet-gallery__track", ".faq__list"
    ];
    staggerGroups.forEach(function (selector) {
      $$(selector).forEach(function (group) {
        Array.prototype.forEach.call(group.children, function (item, index) {
          addReveal(item);
          if (item.classList.contains("motion-reveal")) {
            item.style.setProperty("--motion-delay", Math.min(index * 78, 390) + "ms");
          }
        });
      });
    });

    /* Catch-all for content blocks in bespoke page layouts, so no section
       is left static just because it uses a page-specific class name. */
    var contentSelector = "main h1, main h2, main h3, main h4, main p, main figure, main article, main blockquote, main li, main form, main button, main img, main .btn, main table, main details, main summary";
    var contentNodes = $$(contentSelector);
    var parentCounts = new WeakMap();
    contentNodes.forEach(function (el) {
      if (el.closest(".hero, .sys-hero")) return;
      if (addReveal(el)) {
        var parent = el.parentElement;
        var order = parentCounts.get(parent) || 0;
        el.style.setProperty("--motion-delay", Math.min(order * 46, 230) + "ms");
        parentCounts.set(parent, order + 1);
      }
    });

    if (!targets.length) return;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.08, rootMargin: "0px 0px -34px 0px" });

    targets.forEach(function (el) { observer.observe(el); });
    document.documentElement.classList.add("motion-ready");
  })();

  /* ── Toast (اعلان موقت) ────────────────────────────────── */
  var toastContainer = $("[data-toasts]");
  function showToast(title, desc, isError) {
    if (!toastContainer) return;
    if (document.documentElement.dir === "rtl") {
      var titleTranslations = {
        "Error": "خطا", "Message sent": "پیام ارسال شد", "Subscribed": "عضویت انجام شد"
      };
      var descriptionTranslations = {
        "Thank you for your message! We will get back to you shortly.": "از پیام شما سپاسگزاریم. به‌زودی با شما تماس می‌گیریم.",
        "Something went wrong. Please try again or call us directly.": "مشکلی پیش آمد. دوباره تلاش کنید یا مستقیماً با ما تماس بگیرید.",
        "Thanks for subscribing! Please check your inbox.": "از عضویت شما سپاسگزاریم. لطفاً صندوق ورودی ایمیلتان را بررسی کنید.",
        "Subscription failed. Please try again.": "عضویت انجام نشد. لطفاً دوباره تلاش کنید.",
        "In the WordPress version this connects to WooCommerce / the real store logic.": "در نسخهٔ نهایی، این بخش به فروشگاه واقعی متصل خواهد شد."
      };
      title = titleTranslations[title] || title;
      desc = descriptionTranslations[desc] || desc;
      if (title && title.endsWith(" — demo placeholder")) {
        var feature = title.slice(0, -" — demo placeholder".length);
        var features = { "Country selector": "انتخاب کشور و ارز", "Account": "حساب کاربری", "Cart": "سبد خرید", "This feature": "این قابلیت" };
        title = (features[feature] || feature) + " — نسخهٔ نمایشی";
      }
    }
    var el = document.createElement("div");
    el.className = "toast" + (isError ? " toast--error" : "");
    var t = document.createElement("div");
    t.className = "toast__title";
    t.textContent = title;
    el.appendChild(t);
    if (desc) {
      var d = document.createElement("div");
      d.className = "toast__desc";
      d.textContent = desc;
      el.appendChild(d);
    }
    toastContainer.appendChild(el);
    setTimeout(function () {
      el.classList.add("is-leaving");
      setTimeout(function () { el.remove(); }, 320);
    }, 3500);
  }

  /* ── سال جاری در کپی‌رایت ──────────────────────────────── */
  $$("[data-year]").forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });

  /* ════════════════════════════════════════════════════════
     ۱) نوار اعلان چرخشی (TopBar)
     ════════════════════════════════════════════════════════ */
  (function () {
    var dataEl = $("[data-topbar-data]");
    var textEl = $("[data-topbar-text]");
    if (!dataEl || !textEl) return;

    var items = [];
    try { items = JSON.parse(dataEl.textContent); } catch (e) { items = []; }
    if (!items.length) return;

    var index = 0;
    function render() {
      var item = items[index];
      var linkText = item.text;
      if (/^tel:/i.test(item.href || "")) {
        var phone = item.href.slice(4);
        var phoneAt = linkText.lastIndexOf(phone);
        if (phoneAt >= 0 && /^[+\d().\s-]+$/.test(phone)) {
          linkText = linkText.slice(0, phoneAt) + '<bdi dir="ltr">' + phone + '</bdi>' + linkText.slice(phoneAt + phone.length);
        }
      }
      var html = item.href
        ? '<a href="' + item.href + '">' + linkText + "</a>"
        : linkText;
      textEl.innerHTML = html;
      /* ریست انیمیشن */
      textEl.style.animation = "none";
      void textEl.offsetHeight;
      textEl.style.animation = "";
    }
    function next() { index = (index + 1) % items.length; render(); }
    function prev() { index = (index - 1 + items.length) % items.length; render(); }

    var prevBtn = $("[data-topbar-prev]");
    var nextBtn = $("[data-topbar-next]");
    if (prevBtn) prevBtn.addEventListener("click", prev);
    if (nextBtn) nextBtn.addEventListener("click", next);

    if (items.length > 1) {
      setInterval(next, 6000);
    }
  })();

  /* ════════════════════════════════════════════════════════
     ۲) هدر چسبان — سایه بعد از اسکرول
     ════════════════════════════════════════════════════════ */
  (function () {
    var header = $("[data-header]");
    if (!header) return;
    function onScroll() {
      header.classList.toggle("is-scrolled", window.scrollY > 8);
    }
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  })();

  /* ════════════════════════════════════════════════════════
     ۳) زیرمنوها — هاور (دسکتاپ) + اولین کلیک/لمس (موبایل)
     ════════════════════════════════════════════════════════ */
  (function () {
    var items = $$(".nav__item");
    var isTouch = typeof window.matchMedia === "function" && window.matchMedia("(hover: none)").matches;

    /* Submenu links enter in a left-to-right cascade on every open. */
    items.forEach(function (item) {
      var dropdown = $(".nav__dropdown", item);
      if (!dropdown) return;
      var links = $$("li > a", dropdown);
      links.forEach(function (subLink, index) {
        subLink.style.setProperty("--nav-stagger-delay", Math.min(index * 32, 320) + "ms");
      });
      var feature = $(".nav__mega-media", dropdown);
      if (feature) feature.style.setProperty("--nav-stagger-delay", Math.min(links.length * 32, 320) + "ms");
    });

    function closeAll(except) {
      items.forEach(function (item) {
        if (item !== except) {
          item.classList.remove("is-open");
          var link = $(".nav__link", item);
          if (link) link.setAttribute("aria-expanded", "false");
        }
      });
    }

    items.forEach(function (item) {
      var link = $(".nav__link", item);
      var hasDropdown = !!$(".nav__dropdown", item);
      if (!link || !hasDropdown) return;

      link.setAttribute("aria-expanded", "false");

      link.addEventListener("click", function (e) {
        if (isTouch && hasDropdown && !item.classList.contains("is-open")) {
          /* لمس: اولین ضربه زیرمنو را باز می‌کند، دومین ضربه لینک را دنبال می‌کند */
          e.preventDefault();
          closeAll(item);
          item.classList.add("is-open");
          link.setAttribute("aria-expanded", "true");
        } else {
          closeAll(null);
        }
      });
    });

    /* بستن با کلیک بیرون یا کلید Esc */
    document.addEventListener("click", function (e) {
      if (!e.target.closest(".nav__item")) closeAll(null);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") closeAll(null);
    });
  })();

  /* ════════════════════════════════════════════════════════
     ۴) منوی موبایل
     ════════════════════════════════════════════════════════ */
  (function () {
    var menu = $("[data-mobile-menu]");
    var overlay = $("[data-overlay]");
    var openBtn = $("[data-mobile-open]");
    var closeBtn = $("[data-mobile-close]");
    if (!menu || !overlay || !openBtn) return;

    function open() {
      overlay.hidden = false;
      void overlay.offsetHeight; /* force reflow برای ترنزیشن */
      overlay.classList.add("is-open");
      menu.classList.add("is-open");
      menu.setAttribute("aria-hidden", "false");
      document.body.style.overflow = "hidden";
    }
    function close() {
      overlay.classList.remove("is-open");
      menu.classList.remove("is-open");
      menu.setAttribute("aria-hidden", "true");
      document.body.style.overflow = "";
      setTimeout(function () { overlay.hidden = true; }, 300);
    }

    openBtn.addEventListener("click", open);
    if (closeBtn) closeBtn.addEventListener("click", close);
    overlay.addEventListener("click", close);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && menu.classList.contains("is-open")) close();
    });
    /* کلیک روی هر لینک منو → بستن پنل */
    $$("a", menu).forEach(function (a) {
      a.addEventListener("click", close);
    });
  })();

  /* ════════════════════════════════════════════════════════
     ۵) دیالوگ جستجوی واقعی در فهرست دوزبانهٔ سایت
     ════════════════════════════════════════════════════════ */
  (function () {
    var dialog = $("[data-search-dialog]");
    var openBtn = $("[data-search-open]");
    var closeBtn = $("[data-search-close]");
    var form = $("[data-search-form]");
    if (!dialog || !openBtn || !form) return;

    var input = $("input[type=search]", form);
    if (!input) return;
    var isFa = /^fa(?:-|$)/i.test(document.documentElement.lang || "") || document.documentElement.dir === "rtl";
    var text = isFa ? {
      loading: "در حال جست‌وجو…",
      empty: "برای جست‌وجو، نام محصول یا عبارت موردنظر را وارد کنید.",
      noResults: "نتیجه‌ای پیدا نشد. عبارت دیگری را امتحان کنید.",
      failed: "بارگذاری جست‌وجو انجام نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.",
      count: function (shown, total) { return shown.toLocaleString("fa-IR") + " نتیجه از " + total.toLocaleString("fa-IR"); }
    } : {
      loading: "Searching…",
      empty: "Enter a product name or keyword to search.",
      noResults: "No results found. Try another search term.",
      failed: "Search could not load. Check your connection and try again.",
      count: function (shown, total) { return "Showing " + shown.toLocaleString("en-US") + " of " + total.toLocaleString("en-US") + " results"; }
    };
    var results = $("[data-search-results]", dialog);
    if (!results) {
      results = document.createElement("div");
      results.className = "search-dialog__results";
      results.setAttribute("data-search-results", "");
      results.setAttribute("role", "region");
      results.setAttribute("aria-live", "polite");
      form.insertAdjacentElement("afterend", results);
    }
    var indexPromise = null;

    function normalize(value) {
      return String(value || "").normalize("NFKD").toLocaleLowerCase()
        .replace(/[\u064B-\u065F\u0670]/g, "")
        .replace(/[يى]/g, "ی").replace(/ك/g, "ک")
        .replace(/[أإآ]/g, "ا").replace(/ة/g, "ه")
        .replace(/[\u200B-\u200F\uFEFF]/g, " ")
        .replace(/[^\p{L}\p{N}]+/gu, " ").trim();
    }
    function loadIndex() {
      if (!indexPromise) {
        indexPromise = fetch(searchIndexUrl, { credentials: "same-origin", cache: "force-cache" })
          .then(function (response) {
            if (!response.ok) throw new Error("Search index returned " + response.status);
            return response.json();
          })
          .then(function (data) {
            if (!Array.isArray(data)) throw new Error("Invalid search index");
            return data;
          })
          .catch(function (error) { indexPromise = null; throw error; });
      }
      return indexPromise;
    }
    function showMessage(message) {
      results.replaceChildren();
      var status = document.createElement("p");
      status.className = "search-dialog__status";
      status.textContent = message;
      results.appendChild(status);
    }
    function getSiteBase() {
      var logo = $(".site-header__logo[href]");
      var home;
      try { home = new URL(logo ? logo.getAttribute("href") : "/", location.href); }
      catch (e) { home = new URL("/", location.href); }
      home.search = "";
      home.hash = "";
      home.pathname = home.pathname.replace(/(?:fa\/)?index\.html$/i, "");
      if (!home.pathname.endsWith("/")) home.pathname += "/";
      return home;
    }
    function renderMatches(items, query) {
      var tokens = normalize(query).split(/\s+/).filter(Boolean);
      if (!tokens.length) { showMessage(text.empty); return; }
      var phrase = normalize(query);
      var base = getSiteBase();
      var ranked = [];
      items.forEach(function (item) {
        var title = normalize(item.title);
        var body = normalize((item.text || "") + " " + (item.description || ""));
        var haystack = title + " " + body + " " + normalize(item.url);
        var matched = tokens.every(function (token) { return haystack.indexOf(token) !== -1; });
        if (!matched) return;
        var titleHits = tokens.filter(function (token) { return title.indexOf(token) !== -1; }).length;
        var score = tokens.length * 2 + titleHits * 5;
        if (phrase && title.indexOf(phrase) !== -1) score += 8;
        if ((item.lang === "fa") === isFa) score += 2;
        ranked.push({ item: item, score: score });
      });
      ranked.sort(function (a, b) { return b.score - a.score || a.item.title.localeCompare(b.item.title); });
      if (!ranked.length) { showMessage(text.noResults); return; }

      results.replaceChildren();
      var status = document.createElement("p");
      status.className = "search-dialog__status";
      status.textContent = text.count(Math.min(ranked.length, 10), ranked.length);
      results.appendChild(status);
      var list = document.createElement("ul");
      list.className = "search-dialog__list";
      list.setAttribute("role", "list");
      ranked.slice(0, 10).forEach(function (match) {
        var item = match.item;
        var row = document.createElement("li");
        row.className = "search-dialog__item";
        var link = document.createElement("a");
        link.className = "search-dialog__result";
        link.href = new URL(item.url || "", base).href;
        var title = document.createElement("span");
        title.className = "search-dialog__result-title";
        title.textContent = item.title;
        link.appendChild(title);
        var description = item.description || item.text || "";
        if (description) {
          var snippet = document.createElement("span");
          snippet.className = "search-dialog__result-description";
          snippet.textContent = description.length > 190 ? description.slice(0, 187).trim() + "…" : description;
          link.appendChild(snippet);
        }
        row.appendChild(link);
        list.appendChild(row);
      });
      results.appendChild(list);
    }
    function open() {
      dialog.hidden = false;
      setTimeout(function () { input.focus(); }, 50);
      document.body.style.overflow = "hidden";
    }
    function close() {
      dialog.hidden = true;
      document.body.style.overflow = "";
    }

    openBtn.addEventListener("click", open);
    if (closeBtn) closeBtn.addEventListener("click", close);
    dialog.addEventListener("click", function (e) {
      if (e.target === dialog) close();
      if (e.target.closest && e.target.closest(".search-dialog__result")) close();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !dialog.hidden) close();
    });
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var query = input.value.trim();
      if (!query) { showMessage(text.empty); input.focus(); return; }
      showMessage(text.loading);
      loadIndex().then(function (items) { renderMatches(items, query); })
        .catch(function () { showMessage(text.failed); });
    });
  })();

  /* ════════════════════════════════════════════════════════
     ۶) اسلایدرها (اسکرول + دکمه‌های قبلی/بعدی)
        ناوبری با scrollBy روی خود track و لبهٔ منطقی شروع/پایان انجام می‌شود؛
        بنابراین جهت RTL به scrollLeft وابسته نیست.
     ════════════════════════════════════════════════════════ */
  (function () {
    var carousels = $("[data-carousel]") ? $$("[data-carousel]") : [];
    if (!carousels.length) return;

    carousels.forEach(function (track) {
      var section = track.closest("section") || document;
      var prevBtn = $("[data-carousel-prev]", section);
      var nextBtn = $("[data-carousel-next]", section);
      var rtl = getComputedStyle(track).direction === "rtl";

      function slides() {
        return Array.prototype.filter.call(track.children, function (item) {
          return !item.hidden && item.getClientRects().length > 0;
        });
      }
      function rectState(items) {
        var trackRect = track.getBoundingClientRect();
        if (!items.length) return { atStart: true, atEnd: true, index: 0 };
        if (track.scrollWidth <= track.clientWidth + 4) {
          return { atStart: true, atEnd: true, index: 0 };
        }

        var firstRect = items[0].getBoundingClientRect();
        var lastRect = items[items.length - 1].getBoundingClientRect();
        var startOffset = rtl
          ? Math.abs(firstRect.right - trackRect.right)
          : Math.abs(firstRect.left - trackRect.left);
        var endOffset = rtl
          ? Math.abs(lastRect.left - trackRect.left)
          : Math.abs(lastRect.right - trackRect.right);
        var startEdge = rtl ? trackRect.right : trackRect.left;
        var bestIndex = 0;
        var bestDistance = Infinity;

        items.forEach(function (item, index) {
          var rect = item.getBoundingClientRect();
          if (rect.right <= trackRect.left + 1 || rect.left >= trackRect.right - 1) return;
          var edge = rtl ? rect.right : rect.left;
          var distance = Math.abs(edge - startEdge);
          if (distance < bestDistance) {
            bestDistance = distance;
            bestIndex = index;
          }
        });

        return {
          atStart: startOffset <= 4,
          atEnd: endOffset <= 4,
          index: bestIndex
        };
      }
      function update() {
        var items = slides();
        var state = rectState(items);
        if (prevBtn) prevBtn.disabled = state.atStart;
        if (nextBtn) nextBtn.disabled = state.atEnd;
      }
      function go(dir) {
        var items = slides();
        if (!items.length) return;
        var state = rectState(items);
        var index = Math.max(0, Math.min(items.length - 1, state.index + dir));
        if (index === state.index) return;
        var trackRect = track.getBoundingClientRect();
        var itemRect = items[index].getBoundingClientRect();
        var trackStart = rtl ? trackRect.right : trackRect.left;
        var itemStart = rtl ? itemRect.right : itemRect.left;
        track.scrollBy({ left: itemStart - trackStart, behavior: "smooth" });
      }

      [prevBtn, nextBtn].forEach(function (button) {
        if (!button) return;
        /* Pointer-focus can pan the document to an edge button on narrow viewports.
           Keep focus for accessibility, but never let the browser scroll the page for it. */
        button.addEventListener("mousedown", function (event) {
          if (event.button !== 0) return;
          event.preventDefault();
          if (button.focus) button.focus({ preventScroll: true });
        });
      });
      if (prevBtn) prevBtn.addEventListener("click", function (event) {
        event.preventDefault();
        go(-1);
      });
      if (nextBtn) nextBtn.addEventListener("click", function (event) {
        event.preventDefault();
        go(1);
      });

      var ticking = false;
      track.addEventListener("scroll", function () {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () { update(); ticking = false; });
      }, { passive: true });
      window.addEventListener("resize", update);
      requestAnimationFrame(update);
      window.addEventListener("load", update);
    });
  })();

  /* ════════════════════════════════════════════════════════
     ۷) نظرات مشتریان — فلش‌ها/نقطه‌ها فقط با بیش از یک نظر
     ════════════════════════════════════════════════════════ */
  (function () {
    var section = $("[data-reviews]");
    if (!section) return;
    var track = $("[data-carousel]", section);
    var slides = track ? Array.prototype.slice.call(track.children) : [];
    var rtl = track && getComputedStyle(track).direction === "rtl";

    if (slides.length > 1) {
      section.removeAttribute("data-single");
      var dotsWrap = $("[data-reviews-dots]", section);
      if (dotsWrap) {
        slides.forEach(function (_, i) {
          var b = document.createElement("button");
          b.type = "button";
          b.setAttribute("aria-label", "Go to review " + (i + 1));
          b.addEventListener("click", function () {
            var slide = slides[i];
            if (slide) {
              var trackRect = track.getBoundingClientRect();
              var slideRect = slide.getBoundingClientRect();
              var trackStart = rtl ? trackRect.right : trackRect.left;
              var slideStart = rtl ? slideRect.right : slideRect.left;
              track.scrollBy({ left: slideStart - trackStart, behavior: "smooth" });
            }
          });
          dotsWrap.appendChild(b);
        });

        var dots = $$("button", dotsWrap);
        function updateDots() {
          var trackRect = track.getBoundingClientRect();
          var startEdge = rtl ? trackRect.right : trackRect.left;
          var bestIndex = 0;
          var bestDistance = Infinity;
          slides.forEach(function (slide, index) {
            var rect = slide.getBoundingClientRect();
            if (rect.right <= trackRect.left + 1 || rect.left >= trackRect.right - 1) return;
            var edge = rtl ? rect.right : rect.left;
            var distance = Math.abs(edge - startEdge);
            if (distance < bestDistance) {
              bestDistance = distance;
              bestIndex = index;
            }
          });
          dots.forEach(function (dot, i) { dot.classList.toggle("is-active", i === bestIndex); });
        }
        track.addEventListener("scroll", function () {
          requestAnimationFrame(updateDots);
        }, { passive: true });
        updateDots();
      }
    } else {
      section.setAttribute("data-single", "");
    }
  })();

  /* ════════════════════════════════════════════════════════
     ۸) آکاردئون سوالات متداول (هر بار فقط یکی باز است)
     ════════════════════════════════════════════════════════ */
  (function () {
    var buttons = $$(".faq__button");
    if (!buttons.length) return;

    function setPanelState(panel, open) {
      if (!panel) return;
      if (panel._faqCloseTimer) {
        window.clearTimeout(panel._faqCloseTimer);
        panel._faqCloseTimer = null;
      }
      if (open) {
        panel.hidden = false;
        panel.setAttribute("aria-hidden", "false");
        panel.style.setProperty("--faq-height", panel.scrollHeight + "px");
        panel.classList.remove("is-open");
        void panel.offsetHeight;
        panel.classList.add("is-open");
      } else if (!panel.hidden) {
        panel.setAttribute("aria-hidden", "true");
        panel.style.setProperty("--faq-height", panel.scrollHeight + "px");
        panel.classList.remove("is-open");
        panel._faqCloseTimer = window.setTimeout(function () {
          if (!panel.classList.contains("is-open")) panel.hidden = true;
          panel._faqCloseTimer = null;
        }, typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches ? 0 : 390);
      }
    }

    buttons.forEach(function (btn) {
      var initialPanel = document.getElementById(btn.getAttribute("aria-controls"));
      if (initialPanel && initialPanel.hidden) initialPanel.setAttribute("aria-hidden", "true");
      btn.addEventListener("click", function () {
        var expanded = btn.getAttribute("aria-expanded") === "true";
        buttons.forEach(function (other) {
          if (other !== btn) {
            other.setAttribute("aria-expanded", "false");
            setPanelState(document.getElementById(other.getAttribute("aria-controls")), false);
          }
        });
        btn.setAttribute("aria-expanded", expanded ? "false" : "true");
        setPanelState(document.getElementById(btn.getAttribute("aria-controls")), !expanded);
      });
    });

    window.addEventListener("resize", function () {
      buttons.forEach(function (btn) {
        var panel = document.getElementById(btn.getAttribute("aria-controls"));
        if (panel && !panel.hidden && panel.classList.contains("is-open")) {
          panel.style.setProperty("--faq-height", panel.scrollHeight + "px");
        }
      });
    }, { passive: true });
  })();

  /* ════════════════════════════════════════════════════════
     ۹) فرم تماس — ارسال به contact.php
        ✏️ ایمیل گیرنده را داخل contact.php تنظیم کنید.
     ════════════════════════════════════════════════════════ */
  (function () {
    var form = $("[data-contact-form]");
    if (!form) return;
    var status = $("[data-contact-status]");
    var submitBtn = $("[data-contact-submit]");

    function showStatus(message, isError) {
      if (document.documentElement.dir === "rtl") {
        var messages = {
          "Thank you for your message! We will get back to you shortly.": "از پیام شما سپاسگزاریم. به‌زودی با شما تماس می‌گیریم.",
          "Something went wrong. Please try again or call us directly.": "مشکلی پیش آمد. دوباره تلاش کنید یا مستقیماً با ما تماس بگیرید."
        };
        message = messages[message] || message;
      }
      if (!status) { showToast(isError ? "Error" : "Message sent", message, isError); return; }
      status.hidden = false;
      status.textContent = message;
      status.classList.toggle("is-error", !!isError);
      status.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();

      /* Honeypot: اگر پر شده باشد، ربات است */
      var hp = form.querySelector('input[name="company"]');
      if (hp && hp.value) { showStatus("Thank you for your message! We will get back to you shortly.", false); form.reset(); return; }

      /* اعتبارسنجی ساده سمت مرورگر */
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      if (submitBtn) submitBtn.classList.add("is-loading");

      var formData = new FormData(form);
      formData.append("form_type", "contact");

      /* مقصد ارسال: اکشن فرم (برای صفحات زیرمجموعه، ../contact.php) یا پیش‌فرض */
      var endpoint = form.getAttribute("action") || "contact.php";
      fetch(endpoint, { method: "POST", body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            showStatus("Thank you for your message! We will get back to you shortly.", false);
            showToast("Message sent", "Thank you for your message! We will get back to you shortly.");
            form.reset();
          } else {
            showStatus(data.error || "Something went wrong. Please try again or call us directly.", true);
            showToast("Error", data.error || "Something went wrong. Please try again or call us directly.", true);
          }
        })
        .catch(function () {
          showStatus("Something went wrong. Please try again or call us directly.", true);
          showToast("Error", "Something went wrong. Please try again or call us directly.", true);
        })
        .finally(function () {
          if (submitBtn) submitBtn.classList.remove("is-loading");
        });
    });
  })();

  /* ════════════════════════════════════════════════════════
     ۱۰) خبرنامه فوتر — ارسال به contact.php (form_type=newsletter)
     ════════════════════════════════════════════════════════ */
  (function () {
    var form = $("[data-newsletter-form]");
    if (!form) return;
    var btn = $("[data-newsletter-submit]");
    var input = $("input[type=email]", form);

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      if (btn) { btn.disabled = true; btn.textContent = "…"; }

      var formData = new FormData(form);
      formData.append("form_type", "newsletter");

      var endpoint = form.getAttribute("action") || "contact.php";
      fetch(endpoint, { method: "POST", body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            showToast("Subscribed", "Thanks for subscribing! Please check your inbox.");
            form.reset();
          } else {
            showToast("Error", data.error || "Subscription failed. Please try again.", true);
          }
        })
        .catch(function () {
          showToast("Error", "Subscription failed. Please try again.", true);
        })
        .finally(function () {
          if (btn) { btn.disabled = false; btn.textContent = "Subscribe"; }
        });
    });
  })();

  /* Accessible before/after finish comparison used on the menswear page. */
  $$("[data-finish-compare]").forEach(function (compare) {
    var range = $("[data-finish-range]", compare);
    if (!range) return;
    var activePointerId = null;

    function syncComparison() {
      var value = Math.max(0, Math.min(100, Number(range.value) || 0));
      compare.style.setProperty("--compare-position", value + "%");
    }

    function setFromPointer(event) {
      var rect = compare.getBoundingClientRect();
      if (!rect.width) return;
      var value = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100));
      range.value = String(Math.round(value));
      syncComparison();
    }

    range.addEventListener("input", syncComparison);
    range.addEventListener("change", syncComparison);

    /* Use the full comparison canvas as the drag target; keep the range input
       in the tab order for keyboard and assistive-technology users. */
    compare.addEventListener("pointerdown", function (event) {
      if (event.isPrimary === false || (event.pointerType === "mouse" && event.button !== 0)) return;
      activePointerId = event.pointerId;
      if (compare.setPointerCapture) {
        try { compare.setPointerCapture(event.pointerId); } catch (ignore) {}
      }
      if (range.focus) range.focus({ preventScroll: true });
      setFromPointer(event);
      if (event.cancelable) event.preventDefault();
    });
    compare.addEventListener("pointermove", function (event) {
      if (activePointerId === event.pointerId) setFromPointer(event);
    });
    function stopPointer(event) {
      if (activePointerId !== event.pointerId) return;
      activePointerId = null;
      if (compare.releasePointerCapture && compare.hasPointerCapture && compare.hasPointerCapture(event.pointerId)) {
        try { compare.releasePointerCapture(event.pointerId); } catch (ignore) {}
      }
    }
    compare.addEventListener("pointerup", stopPointer);
    compare.addEventListener("pointercancel", stopPointer);
    compare.addEventListener("lostpointercapture", function () { activePointerId = null; });

    syncComparison();
  });

  /* Sortable product grids used on fixture collection pages. */
  $$('[data-product-sort]').forEach(function (control) {
    var grid = $(control.getAttribute('data-sort-target'));
    if (!grid) return;
    var originalProducts = Array.prototype.filter.call(grid.children, function (item) {
      return item.matches && item.matches('.product');
    });
    var locale = (document.documentElement.lang || 'en').toLowerCase();

    function productName(product) {
      var name = $('.product__name', product);
      return (name ? name.textContent : product.getAttribute('aria-label') || '').trim();
    }

    function productPrice(product) {
      var price = $('.product__price', product);
      if (!price) return Number.POSITIVE_INFINITY;
      var normalized = price.textContent
        .replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); })
        .replace(/[٠-٩]/g, function (digit) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)); })
        .replace(/[٬,]/g, '')
        .replace(/٫/g, '.');
      var match = normalized.match(/\d+(?:\.\d+)?/);
      return match ? Number(match[0]) : Number.POSITIVE_INFINITY;
    }

    control.addEventListener('change', function () {
      var mode = control.value;
      var products = originalProducts.slice();
      if (mode === 'price-asc') {
        products.sort(function (a, b) { return productPrice(a) - productPrice(b); });
      } else if (mode === 'price-desc') {
        products.sort(function (a, b) { return productPrice(b) - productPrice(a); });
      } else if (mode === 'name-asc') {
        products.sort(function (a, b) { return productName(a).localeCompare(productName(b), locale, { sensitivity: 'base' }); });
      } else if (mode === 'name-desc') {
        products.sort(function (a, b) { return productName(b).localeCompare(productName(a), locale, { sensitivity: 'base' }); });
      }
      var fragment = document.createDocumentFragment();
      products.forEach(function (product) { fragment.appendChild(product); });
      grid.appendChild(fragment);
    });
  });

  /* ════════════════════════════════════════════════════════
     ۱۱) دکمه‌های نمایشی (حساب / سبد خرید / انتخاب کشور)
         در وردپرس به WooCommerce وصل می‌شوند.
     ════════════════════════════════════════════════════════ */
  $$("[data-demo]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var feature = btn.getAttribute("data-demo") || "This feature";
      showToast(feature + " — demo placeholder", "In the WordPress version this connects to WooCommerce / the real store logic.");
    });
  });
})();
