/**
 * Gallery Mosaic JS
 * - AJAX filtering (no reload)
 * - Infinite scroll pagination (append pages)
 * - Accessible lightbox: focus trap, Esc close, Prev/Next, keyboard support
 * - Tiles are <a> links:
 *   - plain click opens lightbox
 *   - modified click (cmd/ctrl/shift/middle) keeps normal link behavior
 */
(function ($) {
  "use strict";

  function getItemsFromGrid($grid) {
    return $grid.find("[data-sgim-item]").toArray();
  }

  function trapFocus($dialog, e) {
    const focusable = $dialog
      .find(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      )
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
    return e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1;
  }

  function initGallery($root) {
    const $grid = $root.find("[data-sgim-grid]");
    const $status = $root.find("[data-sgim-status]");
    const $sentinel = $root.find("[data-sgim-sentinel]");
    const $loader = $root.find("[data-sgim-loader]");
    const $clearBtn = $root.find("[data-sgim-clear]");

    const $lightbox = $root.find("[data-sgim-lightbox]");
    const $dialog = $lightbox.find(".sgim__lightbox-dialog");
    const $img = $lightbox.find("[data-sgim-lightbox-img]");
    const $cap = $lightbox.find("[data-sgim-lightbox-caption]");

    let activeIndex = 0;
    let lastFocusedEl = null;

    let currentPage = 1;
    let hasMore = true;
    let isLoading = false;
    let pending = null;

    function setStatus(msg, loading) {
      // msg can be empty when done
      $status.attr("aria-busy", loading ? "true" : "false");

      if (loading) {
        $loader.prop("hidden", false);
        $loader.find(".sgim__loader-text").text(msg || "Loading images…");
      } else {
        $loader.prop("hidden", true);
        // keep text minimal so screen readers don't repeat
        // $status.text("");
        // re-insert loader node because we cleared status text above
        // $status.append($loader);
      }
    }

    function updateClearButtonVisibility() {
      const hasActiveFilter = $root
        .find("[data-sgim-filter]")
        .toArray()
        .some((el) => el.value && el.value !== "0");

      $clearBtn.prop("hidden", !hasActiveFilter);
    }

    function reindexTiles() {
      $grid.find("[data-sgim-item]").each(function (i) {
        $(this).attr("data-index", i);
      });
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

    function getFilterState() {
      return {
        market: $root.find('[data-sgim-filter="market"]').val() || "0",
        product: $root.find('[data-sgim-filter="product"]').val() || "0",
        project: $root.find('[data-sgim-filter="project"]').val() || "0",
      };
    }

    function requestPage(page, append) {
      if (isLoading) return;
      isLoading = true;

      // Abort prior request if user changes filters fast
      if (pending && typeof pending.abort === "function") {
        pending.abort();
      }

      setStatus(SGIM?.strings?.loading || "Loading images…", true);

      const f = getFilterState();

      pending = $.ajax({
        url: SGIM.ajaxUrl,
        method: "POST",
        dataType: "json",
        data: {
          action: "sgim_filter",
          nonce: SGIM.nonce,
          market: f.market,
          product: f.product,
          project: f.project,
          orderBy: SGIM.orderBy,
          eagerFirst: SGIM.eagerFirst,
          page: page,
          perPage: SGIM.perPage || 24,
        },
      })
        .done(function (res) {
          if (!res || !res.success) return;

          const html = res.data.html || "";
          hasMore = !!res.data.hasMore;
          currentPage = res.data.page || page;

          if (append) {
            $grid.append(html);
          } else {
            $grid.html(html);
          }

          reindexTiles();
        })
        .always(function () {
          isLoading = false;
          setStatus("", false);
        });
    }

    function resetAndLoad() {
      currentPage = 1;
      hasMore = true;
      requestPage(1, false);
    }

    function loadNextPage() {
      if (!hasMore || isLoading) return;
      requestPage(currentPage + 1, true);
    }

    // Tile click -> lightbox (delegated)
    $root.on("click", "[data-sgim-item]", function (e) {
      if (isModifiedClick(e)) return;
      e.preventDefault();

      const idx = parseInt($(this).data("index"), 10) || 0;
      openLightbox(idx);
    });

    $root.on("click", "[data-sgim-close]", function () {
      closeLightbox();
    });

    $root.on("click", "[data-sgim-prev]", function () {
      goPrev();
    });

    $root.on("click", "[data-sgim-next]", function () {
      goNext();
    });

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

    // Filters
    $root.on("change", "[data-sgim-filter]", function () {
      updateClearButtonVisibility();
      resetAndLoad();
    });

    $root.on("click", "[data-sgim-clear]", function () {
      $root.find("[data-sgim-filter]").val("0");
      updateClearButtonVisibility();
      resetAndLoad();
    });

    // Infinite scroll (only if enabled)
    const infiniteEnabled = parseInt(SGIM.infinite, 10) === 1;

    if (infiniteEnabled && $sentinel.length) {
      if ("IntersectionObserver" in window) {
        const io = new IntersectionObserver(
          function (entries) {
            entries.forEach((entry) => {
              if (entry.isIntersecting) loadNextPage();
            });
          },
          { root: null, rootMargin: "600px 0px", threshold: 0 }
        );

        io.observe($sentinel.get(0));
      } else {
        // Fallback: basic scroll listener for older browsers
        $(window).on("scroll.sgim", function () {
          const nearBottom =
            window.innerHeight + window.scrollY >= document.body.offsetHeight - 800;
          if (nearBottom) loadNextPage();
        });
      }
    }

    // Initial reindex for first page
    reindexTiles();
    updateClearButtonVisibility();
  }

  $(function () {
    $("[data-sgim]").each(function () {
      initGallery($(this));
    });
  });
})(jQuery);