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
    var segments = normalizePath(window.location.pathname).split('/').filter(Boolean);
    var root = segments[0] || '';
    var prefix = '';
    if (root === 'admin' || root === 'manager' || root === 'waitress' || root === 'hubbly') {
      prefix = '../';
    }
    window.location.replace(prefix + 'logout.php?reason=browser_closed');
  }

  if (isLoginPage()) {
    clearLoggedInFlag();
  } else {
    var params = new URLSearchParams(window.location.search);
    if (params.get('logged_in') === '1') {
      markLoggedIn();
      stripLoggedInParam();
    } else if (!hasLoggedInFlag()) {
      redirectToLogout();
    } else {
      markLoggedIn();
    }
  }
})();

function showLoader() {
    const loader = document.createElement('div');
    loader.id = 'loader';
    loader.className = 'fixed inset-0 flex items-center justify-center z-50';
    loader.innerHTML = `
        <div class="spinner"></div>
        <style>
            .spinner {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                border: 4px solid;
                border-color: #f3ec78;
                border-right-color: #ffbb00;
                animation: spinner-d3wgkg 0.5s infinite linear;
            }

            @keyframes spinner-d3wgkg {
                to {
                    transform: rotate(1turn);
                }
            }
        </style>
    `;
    document.body.appendChild(loader);
}

function hideLoader() {
    const loader = document.getElementById('loader');
    if (loader) {
        loader.remove();
    }
}
