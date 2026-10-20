/* ============================================================
   MANDAI DESIGN — جاوااسکریپت اصلی (بدون وابستگی خارجی)
   ------------------------------------------------------------
   شامل: نوار اعلان چرخشی، هدر چسبان، زیرمنوها، منوی موبایل،
   جستجو، اسلایدرها، آکاردئون سوالات، فرم تماس و خبرنامه، Toast
   ============================================================ */
(function () {
  "use strict";

  /* ── ابزارهای کوچک ─────────────────────────────────────── */
  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

  /* ════════════════════════════════════════════════════════
     Motion system: editorial reveals, staggered cards, progress
     Content stays visible if motion is reduced or JS is unavailable.
     ════════════════════════════════════════════════════════ */
  (function () {
    var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
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
      var html = item.href
        ? '<a href="' + item.href + '">' + item.text + "</a>"
        : item.text;
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
    var isTouch = window.matchMedia("(hover: none)").matches;

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
     ۵) دیالوگ جستجو
     ════════════════════════════════════════════════════════ */
  (function () {
    var dialog = $("[data-search-dialog]");
    var openBtn = $("[data-search-open]");
    var closeBtn = $("[data-search-close]");
    var form = $("[data-search-form]");
    if (!dialog || !openBtn) return;

    function open() {
      dialog.hidden = false;
      var input = $("input[type=search]", dialog);
      if (input) setTimeout(function () { input.focus(); }, 50);
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
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !dialog.hidden) close();
    });
    if (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        close();
        showToast("Search is a placeholder", "Connect it to your future product catalog (WordPress search or WooCommerce).");
      });
    }
  })();

  /* ════════════════════════════════════════════════════════
     ۶) اسلایدرها (اسکرول + دکمه‌های قبلی/بعدی)
        کارکرد: هر [data-carousel] با فلش‌های [data-carousel-prev/next]
        لمس و سوایپ به‌صورت بومی با اسکرول افقی کار می‌کند.
     ════════════════════════════════════════════════════════ */
  (function () {
    var carousels = $$("[data-carousel]");
    if (!carousels.length) return;

    carousels.forEach(function (track) {
      var section = track.closest("section") || document;
      var prevBtn = $("[data-carousel-prev]", section);
      var nextBtn = $("[data-carousel-next]", section);

      function step() {
        var slide = track.firstElementChild;
        if (!slide) return 200;
        var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 12;
        return slide.getBoundingClientRect().width + gap;
      }
      function update() {
        var max = track.scrollWidth - track.clientWidth;
        if (prevBtn) prevBtn.disabled = track.scrollLeft <= 4;
        if (nextBtn) nextBtn.disabled = track.scrollLeft >= max - 4;
      }
      function go(dir) {
        track.scrollBy({ left: dir * step(), behavior: "smooth" });
      }

      if (prevBtn) prevBtn.addEventListener("click", function () { go(-1); });
      if (nextBtn) nextBtn.addEventListener("click", function () { go(1); });

      var ticking = false;
      track.addEventListener("scroll", function () {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () { update(); ticking = false; });
      }, { passive: true });
      window.addEventListener("resize", update);
      update();
      /* بعد از لود کامل فونت/تصاویر، وضعیت دکمه‌ها دوباره چک شود */
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
    var slides = track ? track.children.length : 0;

    if (slides > 1) {
      section.removeAttribute("data-single");
      /* ساخت نقطه‌ها */
      var dotsWrap = $("[data-reviews-dots]", section);
      if (dotsWrap) {
        for (var i = 0; i < slides; i++) {
          (function (idx) {
            var b = document.createElement("button");
            b.type = "button";
            b.setAttribute("aria-label", "Go to review " + (idx + 1));
            b.addEventListener("click", function () {
              var slide = track.children[idx];
              if (slide) track.scrollTo({ left: slide.offsetLeft - track.offsetLeft, behavior: "smooth" });
            });
            dotsWrap.appendChild(b);
          })(i);
        }
        /* هایلایت نقطه فعال */
        var dots = $$("button", dotsWrap);
        function updateDots() {
          var x = track.scrollLeft;
          var w = track.clientWidth;
          var active = Math.round(x / Math.max(w, 1));
          dots.forEach(function (d, i) { d.classList.toggle("is-active", i === active); });
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
        }, window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches ? 0 : 390);
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
