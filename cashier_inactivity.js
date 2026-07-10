(() => {
  if (window.CASHIER_INACTIVITY_ENABLED === false) {
    return;
  }

  // Cashier inactivity auto-logout:
  // - If idle timeout reached and cart is empty -> logout immediately
  // - If cart has items -> wait, then logout once cart becomes empty
  // - Mouse movement does not reset the idle timer until the cursor has stopped
  //   moving for MOUSE_STOP_DEBOUNCE_MS (other input resets immediately).
  const DEFAULT_IDLE_SEC = 120;
  const rawSec =
    typeof window.CASHIER_IDLE_TIMEOUT_SECONDS === 'number'
      ? window.CASHIER_IDLE_TIMEOUT_SECONDS
      : DEFAULT_IDLE_SEC;
  const idleSec = Math.max(30, Math.min(3600, Math.floor(rawSec) || DEFAULT_IDLE_SEC));
  const CASHIER_IDLE_TIMEOUT_MS = idleSec * 1000;
  const MOUSE_STOP_DEBOUNCE_MS = 300;
  const CHECK_INTERVAL_MS = 1000;

  let lastActivityAt = Date.now();
  /** Last time the cursor moved (0 = no mouse movement yet this session). */
  let lastMouseMoveAt = 0;
  /** When the cursor was last considered stopped (debounced after mousemove ends). */
  let lastMouseStoppedAt = Date.now();
  let mouseStopTimer = null;
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

  function onMouseMove() {
    lastMouseMoveAt = Date.now();
    clearTimeout(mouseStopTimer);
    mouseStopTimer = setTimeout(() => {
      lastMouseStoppedAt = Date.now();
    }, MOUSE_STOP_DEBOUNCE_MS);
  }

  /** Idle time only accrues after cursor stops (and after other activity timestamps). */
  function getIdleMs() {
    const now = Date.now();
    const movingRecently =
      lastMouseMoveAt > 0 && now - lastMouseMoveAt < MOUSE_STOP_DEBOUNCE_MS;
    if (movingRecently) return 0;
    return now - Math.max(lastActivityAt, lastMouseStoppedAt);
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

    const idleMs = getIdleMs();
    if (idleMs < CASHIER_IDLE_TIMEOUT_MS) return;

    if (hasCartItems()) {
      logoutPending = true;
      return;
    }

    logoutNow();
  }

  ['mousedown', 'keydown', 'touchstart', 'click', 'scroll'].forEach((evt) => {
    document.addEventListener(evt, markActivity, { passive: true });
  });
  document.addEventListener('mousemove', onMouseMove, { passive: true });
  window.addEventListener('focus', markActivity);

  setInterval(tick, CHECK_INTERVAL_MS);
})();
