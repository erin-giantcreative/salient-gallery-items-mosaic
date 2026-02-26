/**
 * Gallery Mosaic JS
 * - AJAX filtering (fast, no reload)
 * - Accessible lightbox: focus trap, Esc close, Prev/Next, keyboard support
 * - Tiles are <a> links for crawl + normal browser behavior
 *   - Plain click opens lightbox and prevents navigation
 *   - Cmd/Ctrl/Shift click or middle click keeps normal link behavior
 */
(function ($) {
  "use strict";

  function getItemsFromGrid($grid) {
    return $grid.find("[data-sgim-item]").toArray();
  }

  function trapFocus($dialog, e) {
    const focusable = $dialog
      .find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
      .filter(":visible")
      .toArray();

    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (e.key === "Tab") {
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }

  function isModifiedClick(e) {
    // Let users open a new tab / window, or use middle click without lightbox interception.
    return e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1;
  }

  function initGallery($root) {
    const $grid = $root.find("[data-sgim-grid]");
    const $status = $root.find("[data-sgim-status]");

    const $lightbox = $root.find("[data-sgim-lightbox]");
    const $dialog = $lightbox.find(".sgim__lightbox-dialog");
    const $img = $lightbox.find("[data-sgim-lightbox-img]");
    const $cap = $lightbox.find("[data-sgim-lightbox-caption]");

    let activeIndex = 0;
    let lastFocusedEl = null;

    function setStatus(msg) {
      $status.text(msg || "");
    }

    function openLightbox(index) {
      const items = getItemsFromGrid($grid);
      if (!items.length) return;

      activeIndex = Math.max(0, Math.min(index, items.length - 1));
      const el = items[activeIndex];
      const $el = $(el);

      const full = $el.data("full");
      const title = $el.data("title") || "";
      const caption = $el.data("caption") || "";

      lastFocusedEl = document.activeElement;

      $img.attr("src", full);
      $img.attr("alt", title);
      $cap.text(caption);

      $lightbox.prop("hidden", false);
      $("body").addClass("sgim--modal-open");

      setTimeout(function () {
        $dialog.attr("tabindex", "-1").focus();
      }, 0);
    }

    function closeLightbox() {
      $lightbox.prop("hidden", true);
      $("body").removeClass("sgim--modal-open");

      if (lastFocusedEl && typeof lastFocusedEl.focus === "function") {
        lastFocusedEl.focus();
      }
    }

    function goPrev() {
      const items = getItemsFromGrid($grid);
      if (!items.length) return;
      activeIndex = (activeIndex - 1 + items.length) % items.length;
      openLightbox(activeIndex);
    }

    function goNext() {
      const items = getItemsFromGrid($grid);
      if (!items.length) return;
      activeIndex = (activeIndex + 1) % items.length;
      openLightbox(activeIndex);
    }

    // Open on tile click:
    // - If user is trying to open link normally in a new tab/window, do nothing.
    // - Else prevent navigation and open lightbox.
    $root.on("click", "[data-sgim-item]", function (e) {
      if (isModifiedClick(e)) return;

      e.preventDefault();

      const idx = parseInt($(this).data("index"), 10) || 0;
      openLightbox(idx);
    });

    // Close handlers.
    $root.on("click", "[data-sgim-close]", function () {
      closeLightbox();
    });

    // Prev/Next buttons.
    $root.on("click", "[data-sgim-prev]", function () {
      goPrev();
    });
    $root.on("click", "[data-sgim-next]", function () {
      goNext();
    });

    // Keyboard support while lightbox is open.
    $(document).on("keydown.sgim", function (e) {
      if ($lightbox.prop("hidden")) return;

      if (e.key === "Escape") {
        e.preventDefault();
        closeLightbox();
        return;
      }

      if (e.key === "ArrowLeft") {
        e.preventDefault();
        goPrev();
        return;
      }

      if (e.key === "ArrowRight") {
        e.preventDefault();
        goNext();
        return;
      }

      trapFocus($dialog, e);
    });

    // Clear filters.
    $root.on("click", "[data-sgim-clear]", function () {
      $root.find("[data-sgim-filter]").val("0").trigger("change");
    });

    // Filtering (AJAX).
    let pending = null;

    function fetchFiltered() {
      const market = $root.find('[data-sgim-filter="market"]').val() || "0";
      const product = $root.find('[data-sgim-filter="product"]').val() || "0";
      const project = $root.find('[data-sgim-filter="project"]').val() || "0";

      if (pending && typeof pending.abort === "function") {
        pending.abort();
      }

      setStatus(SGIM?.strings?.loading || "Loading…");

      pending = $.ajax({
        url: SGIM.ajaxUrl,
        method: "POST",
        dataType: "json",
        data: {
          action: "sgim_filter",
          nonce: SGIM.nonce,
          market,
          product,
          project,
          maxItems: SGIM.maxItems,
          orderBy: SGIM.orderBy,
          eagerFirst: SGIM.eagerFirst,
        },
      })
        .done(function (res) {
          if (!res || !res.success) {
            setStatus("");
            return;
          }

          $grid.html(res.data.html || "");
          setStatus("");

          // Re-index tiles so lightbox sequence matches DOM order.
          $grid.find("[data-sgim-item]").each(function (i) {
            $(this).attr("data-index", i);
          });
        })
        .fail(function () {
          setStatus("");
        });
    }

    $root.on("change", "[data-sgim-filter]", function () {
      fetchFiltered();
    });
  }

  $(function () {
    $("[data-sgim]").each(function () {
      initGallery($(this));
    });
  });
})(jQuery);