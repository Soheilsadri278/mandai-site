/* Pet-store collection interactions: filters, image fallback and quick view. */
(function () {
  "use strict";

  var $ = function (selector, context) { return (context || document).querySelector(selector); };
  var $$ = function (selector, context) { return Array.prototype.slice.call((context || document).querySelectorAll(selector)); };

  /* Use the local pet-shop photo if a reference CDN image is unavailable. */
  $$('[data-local-fallback]').forEach(function (image) {
    function useFallback() {
      if (image.dataset.fallbackUsed) return;
      image.dataset.fallbackUsed = "true";
      image.src = image.getAttribute("data-local-fallback");
    }
    image.addEventListener("error", useFallback);
    if (image.complete && image.naturalWidth === 0) useFallback();
  });

  /* Availability popover. */
  var filterToggle = $ ("[data-filter-toggle]");
  var filterPanel = $ ("[data-filter-panel]");
  var filterChecks = $$ ("[data-availability]");
  var productCards = $$ ("[data-product-grid] .pet-product");
  var productCount = $ ("[data-product-count]");
  var resultCount = $ ("[data-filter-results]");
  if (filterToggle && filterPanel) {
    function closeFilter() {
      filterPanel.hidden = true;
      filterToggle.setAttribute("aria-expanded", "false");
    }
    function updateProducts() {
      var selected = filterChecks.filter(function (input) { return input.checked; }).map(function (input) { return input.value; });
      var shown = 0;
      productCards.forEach(function (card) {
        var visible = !selected.length || selected.indexOf(card.dataset.stock) !== -1;
        card.hidden = !visible;
        if (visible) shown += 1;
      });
      if (productCount) productCount.textContent = "You’re viewing " + (shown ? "1–" + shown + " of " + shown : "0 of 0") + " products";
      if (resultCount) resultCount.textContent = shown + (shown === 1 ? " Result" : " Results");
    }
    filterToggle.addEventListener("click", function () {
      var willOpen = filterPanel.hidden;
      filterPanel.hidden = !willOpen;
      filterToggle.setAttribute("aria-expanded", String(willOpen));
    });
    filterChecks.forEach(function (input) { input.addEventListener("change", updateProducts); });
    $$ ("[data-clear-filters], [data-clear-all]").forEach(function (button) {
      button.addEventListener("click", function () {
        filterChecks.forEach(function (input) { input.checked = false; });
        updateProducts();
      });
    });
    var done = $ ("[data-filter-done]");
    if (done) done.addEventListener("click", closeFilter);
    document.addEventListener("click", function (event) {
      if (!filterPanel.hidden && !event.target.closest(".pet-filter")) closeFilter();
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") closeFilter();
    });
  }

  /* Product Quick View dialog. */
  var quickViewButtons = $$ ("[data-quick-view]");
  if (quickViewButtons.length) {
    var dialog = document.createElement("dialog");
    dialog.className = "pet-quick-dialog";
    dialog.setAttribute("aria-labelledby", "pet-quick-dialog-title");
    dialog.innerHTML = '<button type="button" class="pet-quick-dialog__close" aria-label="Close quick view">×</button><div class="pet-quick-dialog__body"><img alt=""/><div><h2 id="pet-quick-dialog-title"></h2><p class="pet-quick-dialog__price"></p><p class="pet-quick-dialog__unit">Unit price/per</p><a class="pet-quick-dialog__link">View product</a></div></div>';
    document.body.appendChild(dialog);
    var closeButton = $(".pet-quick-dialog__close", dialog);
    var quickImage = $("img", dialog);
    var quickTitle = $("#pet-quick-dialog-title", dialog);
    var quickPrice = $(".pet-quick-dialog__price", dialog);
    var quickLink = $(".pet-quick-dialog__link", dialog);
    quickViewButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        var card = button.closest(".pet-product");
        var image = card && $(".pet-product__image--primary", card);
        var link = card && $(".pet-product__title", card);
        quickImage.src = image ? image.currentSrc || image.src : "";
        quickImage.alt = image ? image.alt : "Pet store shelving";
        quickTitle.textContent = button.dataset.name || "Shelving System Addison";
        quickPrice.textContent = button.dataset.price || "";
        quickLink.href = link ? link.href : "#";
        if (typeof dialog.showModal === "function") dialog.showModal();
        else dialog.setAttribute("open", "");
      });
    });
    closeButton.addEventListener("click", function () { dialog.close(); });
    dialog.addEventListener("click", function (event) {
      if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener("close", function () {
      var active = document.activeElement;
      if (active && active.matches("[data-quick-view]")) active.focus();
    });
  }
})();
