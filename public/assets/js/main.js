/**
 * main.js — Perla's Flowers public site JavaScript.
 *
 * Vanilla JS only. Alpine.js (loaded via CDN in the layout <head>)
 * handles reactive inline components; this file handles everything else.
 *
 * Sections:
 *   1. Mobile navigation toggle
 *   2. Product category filter
 *   3. Scroll-to-top / nav scroll class
 *   4. CSRF token helper
 *   5. Form submission via fetch
 *   6. Language toggle
 *   7. Notification toast
 */

'use strict';

/* ============================================================
   1. MOBILE NAVIGATION TOGGLE
   ============================================================ */

/**
 * Initialise the mobile hamburger navigation.
 *
 * Toggles the .is-open class on .nav-mobile and flips aria-expanded
 * on .nav-toggle. Closes the drawer on outside click or Escape key.
 *
 * @returns {void}
 *
 * @example
 *   // Called automatically on DOMContentLoaded.
 *   initMobileNav();
 */
function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const drawer = document.querySelector('.nav-mobile');

  if (!toggle || !drawer) return;

  /**
   * Open or close the mobile nav drawer.
   *
   * @param {boolean} open - True to open, false to close.
   * @returns {void}
   */
  function setDrawer(open) {
    toggle.setAttribute('aria-expanded', String(open));
    drawer.classList.toggle('is-open', open);
    document.body.style.overflow = open ? 'hidden' : '';
  }

  toggle.addEventListener('click', () => {
    const isOpen = toggle.getAttribute('aria-expanded') === 'true';
    setDrawer(!isOpen);
  });

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (
      toggle.getAttribute('aria-expanded') === 'true' &&
      !toggle.contains(e.target) &&
      !drawer.contains(e.target)
    ) {
      setDrawer(false);
    }
  });

  // Close on Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
      setDrawer(false);
      toggle.focus();
    }
  });

  // Close when any drawer link is followed
  drawer.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => setDrawer(false));
  });
}

/* ============================================================
   2. PRODUCT CATEGORY FILTER
   ============================================================ */

/**
 * Initialise the product category filter bar.
 *
 * Clicking a .filter-btn with data-category="slug" shows only
 * .product-card elements whose own data-category matches that slug.
 * data-category="all" shows every card.
 *
 * Cards use .is-hidden (display:none via CSS) for show/hide so that
 * the grid reflows cleanly rather than leaving empty holes.
 *
 * @returns {void}
 *
 * @example
 *   // HTML expected on the page:
 *   // <button class="filter-btn active" data-category="all">All</button>
 *   // <button class="filter-btn" data-category="bouquets">Bouquets</button>
 *   // <div class="product-card" data-category="bouquets">...</div>
 */
function initCategoryFilter() {
  const filterBtns = document.querySelectorAll('.filter-btn');
  const productCards = document.querySelectorAll('.product-card');

  if (!filterBtns.length || !productCards.length) return;

  filterBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      const selected = btn.dataset.category;

      // Update active state
      filterBtns.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');

      // Show / hide cards
      productCards.forEach((card) => {
        const match = selected === 'all' || card.dataset.category === selected;
        card.classList.toggle('is-hidden', !match);
      });
    });
  });
}

/* ============================================================
   3. SCROLL-TO-TOP / NAV SCROLL CLASS
   ============================================================ */

/**
 * Add .scrolled to the site nav once the user scrolls past 50px.
 *
 * The CSS uses this class to apply a slight backdrop-blur effect,
 * improving legibility over hero images on scroll.
 *
 * @returns {void}
 */
function initNavScroll() {
  const nav = document.querySelector('nav.site-nav');
  if (!nav) return;

  const THRESHOLD = 50;

  function update() {
    nav.classList.toggle('scrolled', window.scrollY > THRESHOLD);
  }

  // Passive listener — no need to call preventDefault on scroll.
  window.addEventListener('scroll', update, { passive: true });
  update(); // Set correct state on initial load
}

/* ============================================================
   4. CSRF TOKEN HELPER
   ============================================================ */

/**
 * Read the CSRF token from the page's <meta name="csrf-token"> tag.
 *
 * The PHP layout injects this tag. All mutating fetch requests must
 * include this value in an X-CSRF-Token header.
 *
 * @returns {string} The CSRF token string, or an empty string if the
 *   meta tag is absent (prevents fetch from failing with undefined).
 *
 * @example
 *   const token = getCsrfToken();
 *   // token === 'abc123xyz'
 *
 * @throws {void} Never throws — returns '' gracefully when tag is missing.
 */
function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

/* ============================================================
   5. FORM SUBMISSION VIA FETCH
   ============================================================ */

/**
 * Progressively-enhanced form submission via the Fetch API.
 *
 * Intercepts the form's default submit, serialises its data, POSTs to
 * its action URL with a CSRF header, then displays success or error
 * feedback without a full page reload.
 *
 * @param {string} formId - The id attribute of the <form> element to bind.
 * @param {Function} [successCallback] - Optional callback invoked with the
 *   parsed JSON response body on a 2xx reply.
 * @returns {void}
 *
 * @example
 *   // Bind a contact form and log the server response:
 *   submitForm('contact-form', (data) => console.log('Sent:', data));
 *
 * @throws {void} Displays a .form-error message on network failure or
 *   non-2xx HTTP status; never throws to the caller.
 */
function submitForm(formId, successCallback) {
  const form = document.getElementById(formId);
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // Clear previous feedback
    const errorEls = form.querySelectorAll('.form-error, .form-success');
    errorEls.forEach((el) => el.remove());

    const submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: {
          'X-CSRF-Token': getCsrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: new FormData(form),
      });

      const data = await response.json();

      if (response.ok) {
        const msg = document.createElement('p');
        msg.className = 'form-success';
        msg.textContent = data.message || 'Submitted successfully.';
        form.insertAdjacentElement('afterend', msg);
        form.reset();

        if (typeof successCallback === 'function') {
          successCallback(data);
        }
      } else {
        const msg = document.createElement('p');
        msg.className = 'form-error';
        msg.textContent = data.message || 'Something went wrong. Please try again.';
        form.insertAdjacentElement('afterend', msg);
      }
    } catch (err) {
      const msg = document.createElement('p');
      msg.className = 'form-error';
      msg.textContent = 'Network error. Please check your connection and try again.';
      form.insertAdjacentElement('afterend', msg);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
}

/* ============================================================
   6. LANGUAGE TOGGLE
   ============================================================ */

/**
 * Initialise the locale switcher button(s).
 *
 * Posts to /lang/{code} then reloads the page so the PHP layout
 * re-renders all strings in the new language.
 *
 * @returns {void}
 *
 * @example
 *   // HTML:
 *   // <button class="lang-toggle" data-lang="es">ES</button>
 */
function initLangToggle() {
  const toggles = document.querySelectorAll('.lang-toggle');

  toggles.forEach((btn) => {
    btn.addEventListener('click', async () => {
      const lang = btn.dataset.lang;
      if (!lang) return;

      try {
        await fetch(`/lang/${lang}`, {
          method: 'POST',
          headers: { 'X-CSRF-Token': getCsrfToken() },
        });
      } catch {
        // Even on network error, attempt reload to let the server handle it.
      }

      window.location.reload();
    });
  });
}

/* ============================================================
   7. NOTIFICATION TOAST
   ============================================================ */

/**
 * Display a floating toast notification that auto-dismisses.
 *
 * Creates a .toast element inside a shared .toast-container that sits
 * fixed at the bottom-centre of the viewport. Removes itself after 4 s
 * (or when clicked). Supports success, error, and info variants.
 *
 * @param {string} message - The text to display inside the toast.
 * @param {'success'|'error'|'info'} [type='success'] - Visual style variant.
 * @returns {void}
 *
 * @example
 *   showToast('Your order has been placed!', 'success');
 *   showToast('Unable to save changes.', 'error');
 */
function showToast(message, type = 'success') {
  // Ensure singleton container
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');
  toast.textContent = message;

  container.appendChild(toast);

  /**
   * Animate out then remove the toast from the DOM.
   *
   * @returns {void}
   */
  function dismiss() {
    toast.classList.add('is-leaving');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
  }

  // Dismiss on click
  toast.addEventListener('click', dismiss);

  // Auto-dismiss after 4 seconds
  setTimeout(dismiss, 4000);
}

/* ============================================================
   INIT — run after DOM is ready
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {
  initMobileNav();
  initCategoryFilter();
  initNavScroll();
  initLangToggle();
});
