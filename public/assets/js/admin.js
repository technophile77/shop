/**
 * admin.js — Perla's Flowers admin dashboard JavaScript.
 *
 * Loaded only on admin pages (body.admin-body). Vanilla JS only.
 *
 * Sections:
 *   1. Sidebar active-link highlighting
 *   2. Mobile sidebar toggle
 *   3. Delete confirmation intercept
 *   4. Image upload preview
 *   5. A/B campaign comparison animation
 *   6. Stat card count-up animation
 *   7. SMS character counter
 */

'use strict';

/* ============================================================
   1. SIDEBAR ACTIVE-LINK HIGHLIGHTING
   ============================================================ */

/**
 * Mark the sidebar link whose href best matches the current URL path.
 *
 * Adds the .active class to the matching anchor so staff can see which
 * section they are in without relying on server-side rendering.
 * Prefers the longest matching path prefix so /admin/quotes/new does
 * not activate both /admin and /admin/quotes.
 *
 * @returns {void}
 *
 * @example
 *   // With URL /admin/quotes/123, the link href="/admin/quotes"
 *   // receives .active while href="/admin" does not.
 */
function initSidebarActiveLink() {
  const links = document.querySelectorAll('.admin-nav a');
  if (!links.length) return;

  const current = window.location.pathname;
  let bestMatch = null;
  let bestLength = 0;

  links.forEach((link) => {
    const href = link.getAttribute('href');
    if (!href) return;

    if (current === href || (current.startsWith(href) && href.length > bestLength)) {
      bestMatch = link;
      bestLength = href.length;
    }
  });

  if (bestMatch) {
    bestMatch.classList.add('active');
    bestMatch.setAttribute('aria-current', 'page');
  }
}

/* ============================================================
   2. MOBILE SIDEBAR TOGGLE
   ============================================================ */

/**
 * Initialise the mobile sidebar drawer and overlay backdrop.
 *
 * The sidebar slides in from the left on mobile; the overlay dims and
 * disables the main content while it is open. Closes on overlay click
 * or Escape key.
 *
 * @returns {void}
 */
function initMobileSidebar() {
  const menuToggle = document.querySelector('.admin-menu-toggle');
  const sidebar = document.querySelector('.admin-sidebar');
  const overlay = document.querySelector('.admin-sidebar-overlay');

  if (!menuToggle || !sidebar) return;

  /**
   * Open or close the sidebar drawer.
   *
   * @param {boolean} open - True to open, false to close.
   * @returns {void}
   */
  function setSidebar(open) {
    sidebar.classList.toggle('is-open', open);
    if (overlay) overlay.classList.toggle('is-visible', open);
    menuToggle.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
  }

  menuToggle.addEventListener('click', () => {
    const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
    setSidebar(!isOpen);
  });

  if (overlay) {
    overlay.addEventListener('click', () => setSidebar(false));
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
      setSidebar(false);
    }
  });
}

/* ============================================================
   3. DELETE CONFIRMATION INTERCEPT
   ============================================================ */

/**
 * Intercept delete-action form submissions with a confirm() dialog.
 *
 * Any <form> with data-confirm="..." will pause submission and display
 * the confirmation message. The form only submits if the user confirms.
 *
 * This prevents accidental deletions of quotes, products, or campaigns
 * from a mis-click on a table-row button.
 *
 * @returns {void}
 *
 * @example
 *   // HTML:
 *   // <form method="POST" action="/admin/products/42/delete"
 *   //       data-confirm="Delete this product? This cannot be undone.">
 *   //   <button type="submit">Delete</button>
 *   // </form>
 */
function initDeleteConfirm() {
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const message = form.dataset.confirm;

    if (message && !window.confirm(message)) {
      e.preventDefault();
    }
  });
}

/* ============================================================
   4. IMAGE UPLOAD PREVIEW
   ============================================================ */

/**
 * Bind file inputs to adjacent preview <img> elements.
 *
 * When the user selects an image file, the thumbnail renders immediately
 * in the page before form submission, so staff can confirm the right
 * file was chosen.
 *
 * Expects the file input to have a data-preview="#img-id" attribute
 * pointing to an <img> element's id.
 *
 * @returns {void}
 *
 * @example
 *   // HTML:
 *   // <input type="file" name="image" accept="image/*"
 *   //        data-preview="#product-preview">
 *   // <img id="product-preview" class="img-preview" alt="Preview">
 */
function initImagePreview() {
  const inputs = document.querySelectorAll('input[type="file"][data-preview]');

  inputs.forEach((input) => {
    input.addEventListener('change', () => {
      const target = document.querySelector(input.dataset.preview);
      if (!target) return;

      const file = input.files[0];
      if (!file || !file.type.startsWith('image/')) return;

      const reader = new FileReader();

      reader.onload = (e) => {
        target.src = e.target.result;
        target.classList.add('is-visible');
      };

      reader.readAsDataURL(file);
    });
  });
}

/* ============================================================
   5. A/B CAMPAIGN COMPARISON ANIMATION
   ============================================================ */

/**
 * Highlight the winning A/B variant with an animated border.
 *
 * Reads data-winner="a" or data-winner="b" from the .ab-compare
 * container, then adds .is-winner to the matching .ab-variant child
 * after a short delay so the animation is noticeable.
 *
 * @returns {void}
 *
 * @example
 *   // HTML:
 *   // <div class="ab-compare" data-winner="b">
 *   //   <div class="ab-variant" data-variant="a">...</div>
 *   //   <div class="ab-variant" data-variant="b">...</div>
 *   // </div>
 */
function initAbCompare() {
  const container = document.querySelector('.ab-compare');
  if (!container) return;

  const winner = container.dataset.winner;
  if (!winner) return;

  const winnerEl = container.querySelector(`[data-variant="${winner}"]`);
  if (!winnerEl) return;

  // Small delay so the highlight feels deliberate, not instantaneous.
  setTimeout(() => {
    winnerEl.classList.add('is-winner');
  }, 600);
}

/* ============================================================
   6. STAT CARD COUNT-UP ANIMATION
   ============================================================ */

/**
 * Animate numeric .stat-value elements counting up from 0 to their
 * target value on page load.
 *
 * Each .stat-value element carries its final value in the data-target
 * attribute. The animation runs for ~1.2 s using requestAnimationFrame.
 *
 * Only runs if IntersectionObserver is available (no-op in very old
 * browsers, where the final value is shown statically instead).
 *
 * @returns {void}
 *
 * @example
 *   // HTML:
 *   // <div class="stat-value" data-target="47">47</div>
 *   // On load the element counts from 0 to 47 over 1.2 s.
 */
function initStatCounters() {
  const els = document.querySelectorAll('.stat-value[data-target]');
  if (!els.length || typeof IntersectionObserver === 'undefined') return;

  const DURATION = 1200; // ms

  /**
   * Run the count-up animation for a single element.
   *
   * @param {HTMLElement} el - The element whose text content to animate.
   * @param {number} target - The final integer value to reach.
   * @returns {void}
   */
  function animateCounter(el, target) {
    const start = performance.now();

    function tick(now) {
      const elapsed = now - start;
      const progress = Math.min(elapsed / DURATION, 1);
      // Ease out: decelerates as it approaches the target.
      const eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = Math.round(eased * target).toLocaleString();

      if (progress < 1) {
        requestAnimationFrame(tick);
      } else {
        el.textContent = target.toLocaleString();
      }
    }

    requestAnimationFrame(tick);
  }

  const observer = new IntersectionObserver(
    (entries, obs) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const el = entry.target;
        const target = parseInt(el.dataset.target, 10);
        if (!isNaN(target)) animateCounter(el, target);
        obs.unobserve(el);
      });
    },
    { threshold: 0.2 }
  );

  els.forEach((el) => {
    // Show '0' while off-screen, not the final number
    el.textContent = '0';
    observer.observe(el);
  });
}

/* ============================================================
   7. SMS CHARACTER COUNTER
   ============================================================ */

/**
 * Bind a live character counter to SMS compose textareas.
 *
 * Each <textarea data-sms-counter> element gets an adjacent counter
 * paragraph showing "X / 160". Exceeding 160 characters adds
 * .over-limit to the counter element.
 *
 * @returns {void}
 *
 * @example
 *   // HTML:
 *   // <textarea data-sms-counter placeholder="Type your SMS..."></textarea>
 *   // Renders: <p class="char-counter">0 / 160</p> beneath the textarea.
 */
function initSmsCounter() {
  const textareas = document.querySelectorAll('textarea[data-sms-counter]');

  textareas.forEach((ta) => {
    const SMS_LIMIT = 160;

    const counter = document.createElement('p');
    counter.className = 'char-counter';
    counter.setAttribute('aria-live', 'polite');
    counter.textContent = `0 / ${SMS_LIMIT}`;
    ta.insertAdjacentElement('afterend', counter);

    ta.addEventListener('input', () => {
      const len = ta.value.length;
      counter.textContent = `${len} / ${SMS_LIMIT}`;
      counter.classList.toggle('over-limit', len > SMS_LIMIT);
    });
  });
}

/* ============================================================
   INIT — run after DOM is ready
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {
  initSidebarActiveLink();
  initMobileSidebar();
  initDeleteConfirm();
  initImagePreview();
  initAbCompare();
  initStatCounters();
  initSmsCounter();
});
