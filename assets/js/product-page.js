/* Progressive enhancement for the static product page: gallery + enquiry selection. */
(() => {
  'use strict';
  const main = document.querySelector('[data-gallery-main]');
  const thumbs = [...document.querySelectorAll('[data-gallery-thumb]')];
  const finishButtons = [...document.querySelectorAll('[data-finish-choice]')];
  const selected = document.querySelector('[data-selected-finish]');
  const quoteLinks = [...document.querySelectorAll('[data-product-quote]')];
  const productTitle = document.querySelector('h1')?.textContent.trim() || document.title;
  const productUrl = window.location.href.split('#')[0];
  let selectedFinish = finishButtons.find((button) => button.getAttribute('aria-pressed') === 'true')?.dataset.finishChoice || '';

  thumbs.forEach((button) => {
    button.addEventListener('click', () => {
      if (!main) return;
      const src = button.dataset.src;
      if (!src) return;
      main.src = src;
      main.alt = button.dataset.alt || productTitle;
      thumbs.forEach((item) => {
        const active = item === button;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', String(active));
      });
    });
  });

  const refreshQuote = () => {
    quoteLinks.forEach((link) => {
      const subject = (document.documentElement.lang === 'fa' ? 'استعلام محصول: ' : 'Product enquiry: ') + productTitle;
      const body = [
        document.documentElement.lang === 'fa' ? 'لطفاً قیمت روز و مشخصات محصول را اعلام کنید.' : 'Please confirm the current price and product details.',
        productTitle,
        selectedFinish ? (document.documentElement.lang === 'fa' ? 'روکش انتخابی: ' : 'Selected finish: ') + selectedFinish : '',
        productUrl,
      ].filter(Boolean).join('\n');
      link.href = 'mailto:info@mandai-design.com?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
    });
  };

  finishButtons.forEach((button) => {
    button.addEventListener('click', () => {
      selectedFinish = button.dataset.finishChoice || '';
      if (selected) selected.textContent = selectedFinish;
      finishButtons.forEach((item) => {
        const active = item === button;
        item.setAttribute('aria-pressed', String(active));
      });
      refreshQuote();
    });
  });
  refreshQuote();
})();
