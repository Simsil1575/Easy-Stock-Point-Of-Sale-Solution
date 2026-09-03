(function () {
  'use strict';

  var STORAGE_KEY = 'pos_browser_session';

  function normalizePath(pathname) {
    var path = (pathname || '/').replace(/\\/g, '/');
    path = path.replace(/\/+$/, '');
    return path === '' ? '/' : path;
  }

  function isLoginPage() {
    var path = normalizePath(window.location.pathname);
    if (path === '/') {
      return true;
    }
    var base = path.split('/').pop() || '';
    return base === 'index.php';
  }

  function clearLoggedInFlag() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (_) {}
  }

  function markLoggedIn() {
    try {
      sessionStorage.setItem(STORAGE_KEY, '1');
    } catch (_) {}
  }

  function hasLoggedInFlag() {
    try {
      return sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch (_) {
      return false;
    }
  }

  function stripLoggedInParam() {
    try {
      var params = new URLSearchParams(window.location.search);
      if (params.get('logged_in') !== '1') {
        return;
      }
      params.delete('logged_in');
      var query = params.toString();
      var nextUrl = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
      history.replaceState({}, '', nextUrl);
    } catch (_) {}
  }

  function redirectToLogout() {
    var root = normalizePath(window.location.pathname).split('/')[1] || '';
    var prefix = '';
    if (root === 'admin' || root === 'manager' || root === 'waitress' || root === 'hubbly') {
      prefix = '../';
    }
    window.location.replace(prefix + 'logout.php?reason=browser_closed');
  }

  if (isLoginPage()) {
    clearLoggedInFlag();
    return;
  }

  var params = new URLSearchParams(window.location.search);
  if (params.get('logged_in') === '1') {
    markLoggedIn();
    stripLoggedInParam();
    return;
  }

  if (!hasLoggedInFlag()) {
    redirectToLogout();
    return;
  }

  markLoggedIn();
})();
