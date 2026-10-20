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

  /* Shop-the-look hotspots and four-product carousel. */
  (function () {
    var section = $(".pet-look");
    if (!section) return;

    var products = [
      {
        name: "Shelving System Addison – Pet Store – Style 3",
        price: "from $2,361.00",
        href: "https://www.mandai-design.com/products/pet-store-shelving-addison-style3",
        image: "https://www.mandai-design.com/cdn/shop/files/pet-store-shelving-addison-style-3_4.jpg?v=1723630458&width=700",
        fallback: "../images/systems/variants/addison-style3.jpg",
        alt: "Shelving System Addison – Pet Store – Style 3"
      },
      {
        name: "Shelving System Addison – Pet Store – Style 4",
        price: "from $2,676.00",
        href: "https://www.mandai-design.com/products/pet-store-shelving-addison-style4",
        image: "https://www.mandai-design.com/cdn/shop/files/pet-store-shelving-addison-style-4_4.jpg?v=1723635628&width=700",
        fallback: "../images/systems/variants/addison-style4.jpg",
        alt: "Shelving System Addison – Pet Store – Style 4"
      },
      {
        name: "Display Table Fargo, black with wood",
        price: "from $440.00",
        href: "https://www.mandai-design.com/products/display-table-fargo",
        image: "https://www.mandai-design.com/cdn/shop/files/display-table-fargo-cubes-retail-display_3.jpg?v=1744875242&width=700",
        fallback: "../images/product-como.jpg",
        alt: "Display Table Fargo in black steel and wood"
      },
      {
        name: "Shelving System Addison – Pet Store – Style 2",
        price: "from $1,777.00",
        href: "https://www.mandai-design.com/products/pet-store-shelving-addison-style2",
        image: "https://www.mandai-design.com/cdn/shop/files/pet-store-shelving-addison-style-2_4.jpg?v=1723629810&width=700",
        fallback: "../images/systems/variants/addison-style2.jpg",
        alt: "Shelving System Addison – Pet Store – Style 2"
      }
    ];

    var image = $("[data-look-image]", section);
    var imageLink = $("[data-look-image-link]", section);
    var name = $("[data-look-name]", section);
    var price = $("[data-look-price]", section);
    var count = $("[data-look-counter]", section);
    var quickView = $(".pet-look-card__quick", section);
    var hotspots = $$ ("[data-look-hotspot]", section);
    var previous = $ ("[data-look-prev]", section);
    var next = $ ("[data-look-next]", section);
    if (!image || !imageLink || !name || !price || !count) return;

    var current = 0;
    function showProduct(index) {
      current = (index + products.length) % products.length;
      var product = products[current];

      if (image.getAttribute("src") !== product.image) {
        delete image.dataset.fallbackUsed;
        image.dataset.localFallback = product.fallback;
        image.src = product.image;
      }
      image.alt = product.alt;
      imageLink.href = product.href;
      name.textContent = product.name;
      price.textContent = product.price;
      count.textContent = (current + 1) + " / " + products.length;
      if (quickView) {
        quickView.dataset.name = product.name;
        quickView.dataset.price = product.price;
        quickView.dataset.image = product.image;
        quickView.dataset.href = product.href;
      }
      hotspots.forEach(function (hotspot) {
        hotspot.setAttribute("aria-pressed", String(Number(hotspot.dataset.lookIndex) === current));
      });
    }

    hotspots.forEach(function (hotspot) {
      hotspot.addEventListener("click", function () {
        showProduct(Number(hotspot.dataset.lookIndex));
      });
    });
    if (previous) previous.addEventListener("click", function () { showProduct(current - 1); });
    if (next) next.addEventListener("click", function () { showProduct(current + 1); });
    showProduct(0);
  })();

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
        var lookCard = button.closest(".pet-look-card");
        var image = card ? $(".pet-product__image--primary", card) : (lookCard ? $("img", lookCard) : null);
        var link = card ? $(".pet-product__title", card) : (lookCard ? $("[data-look-image-link]", lookCard) : null);
        quickImage.src = image ? (image.currentSrc || image.src) : (button.dataset.image || "");
        quickImage.alt = image ? image.alt : "Pet store shelving";
        quickTitle.textContent = button.dataset.name || "Shelving System Addison";
        quickPrice.textContent = button.dataset.price || "";
        quickLink.href = button.dataset.href || (link ? link.href : "#");
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
