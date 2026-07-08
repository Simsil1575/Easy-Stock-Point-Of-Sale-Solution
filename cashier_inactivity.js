(() => {
  // Cashier inactivity auto-logout:
  // - If idle timeout reached and cart is empty -> logout immediately
  // - If cart has items -> wait, then logout once cart becomes empty
  const CASHIER_IDLE_TIMEOUT_MS = 10 * 1000;
  const CHECK_INTERVAL_MS = 1000;

  let lastActivityAt = Date.now();
  let logoutPending = false;

  function hasCartItems() {
    try {
      if (typeof window.__hasCartItems === 'function') return !!window.__hasCartItems();
      if (typeof window.__isCartEmpty === 'function') return !window.__isCartEmpty();
      if (Array.isArray(window.cart)) return window.cart.length > 0;
    } catch (_) {}
    return false;
  }

  function isCartEmpty() {
    try {
      if (typeof window.__isCartEmpty === 'function') return !!window.__isCartEmpty();
      if (typeof window.__hasCartItems === 'function') return !window.__hasCartItems();
      if (Array.isArray(window.cart)) return window.cart.length === 0;
    } catch (_) {}
    return true;
  }

  function markActivity() {
    lastActivityAt = Date.now();
    logoutPending = false;
  }

  function logoutNow() {
    window.location.href = '/logout.php';
  }

  function tick() {
    // If user was idle while cart had items, logout once cart is empty
    if (logoutPending && isCartEmpty()) {
      logoutNow();
      return;
    }

    const idleMs = Date.now() - lastActivityAt;
    if (idleMs < CASHIER_IDLE_TIMEOUT_MS) return;

    if (hasCartItems()) {
      logoutPending = true;
      return;
    }

    logoutNow();
  }

  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'click', 'scroll'].forEach((evt) => {
    document.addEventListener(evt, markActivity, { passive: true });
  });
  window.addEventListener('focus', markActivity);

  setInterval(tick, CHECK_INTERVAL_MS);
})();

