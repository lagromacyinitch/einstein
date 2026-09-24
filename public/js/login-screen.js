/**
 * Einstein Center — shared login screen (user / staff / admin)
 * Role is inferred from username on the server (admin, staff, or email).
 */
(function () {
  'use strict';

  const ROLE_TARGETS = {
    admin: 'admin.html',
    user: 'user.html',
  };

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function getReturnContext() {
    const params = new URLSearchParams(window.location.search);
    return {
      returnTo: params.get('return') || '',
      program: params.get('program') || '',
      role: params.get('role') || '',
    };
  }

  function buildLoginUrl(opts) {
    const p = new URLSearchParams();
    if (opts.returnTo) p.set('return', opts.returnTo);
    if (opts.program) p.set('program', opts.program);
    const q = p.toString();
    return 'login.html' + (q ? '?' + q : '');
  }

  function getApiBaseUrl() {
    if (window.location.protocol === 'http:' || window.location.protocol === 'https:') {
      return new URL('.', window.location.href).href;
    }
    return 'http://localhost/EINSTEIN-WEB18/';
  }

  function apiUrl(path) {
    return new URL(path, getApiBaseUrl()).href;
  }

  function showError(msg) {
    const el = qs('#login-error');
    if (!el) return;
    if (msg) {
      el.textContent = msg;
      el.classList.add('visible');
    } else {
      el.textContent = '';
      el.classList.remove('visible');
    }
  }

  function hideLoginScreen() {
    const screen = qs('#login-screen');
    if (!screen) return;
    screen.style.opacity = '0';
    screen.style.transition = 'opacity 0.4s';
    setTimeout(() => {
      screen.classList.add('login-screen-hidden');
      screen.style.display = 'none';
    }, 400);
  }

  function showLoginScreen() {
    const screen = qs('#login-screen');
    if (!screen) return;
    screen.classList.remove('login-screen-hidden');
    screen.style.display = 'flex';
    screen.style.opacity = '1';
  }

  function resolveRedirect(data, ctx) {
    if (data.role === 'admin') return ROLE_TARGETS.admin;

    if (ctx.returnTo === 'enroll' && ctx.program) {
      sessionStorage.setItem('einstein-post-login', JSON.stringify({
        action: 'enroll',
        program: ctx.program,
      }));
      return 'main.html';
    }
    if (ctx.returnTo === 'dashboard') return 'user.html';
    if (data.redirect) return data.redirect;
    return ROLE_TARGETS.user;
  }

  let isLoggingIn = false;

  async function submitLogin() {
    if (isLoggingIn) return;

    const userEl = qs('#login-user');
    const passEl = qs('#login-pass');
    const roleEl = qs('#login-role');
    const btn = qs('#login-submit-btn');

    if (!userEl || !passEl) return;

    const username = userEl.value.trim();
    const password = passEl.value;
    const role = roleEl ? roleEl.value : '';

    if (!username || !password) {
      showError('Please enter your username or email and password.');
      return;
    }

    showError('');
    isLoggingIn = true;
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Signing in…';
    }

    try {
      const res = await fetch(apiUrl('api/portal_login.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password, role }),
        credentials: 'include',
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (parseErr) {
        console.error('Login response:', text);
        showError('Server error during login. Check that Apache/MySQL are running and the database is set up.');
        return;
      }

      if (!data.success) {
        showError(data.message || 'Login failed. Please try again.');
        return;
      }

      if (data.role === 'user') {
        if (data.email) localStorage.setItem('userEmail', data.email);
        if (data.user_id) sessionStorage.setItem('userId', data.user_id);
      }

      sessionStorage.setItem('einstein-login-role', data.role);

      const ctx = getReturnContext();
      const target = resolveRedirect(data, ctx);

      if (window.EinsteinLoginConfig && window.EinsteinLoginConfig.embedded) {
        if (data.role === 'admin' && target === 'admin.html') {
          hideLoginScreen();
          if (typeof window.onPortalLoginSuccess === 'function') {
            window.onPortalLoginSuccess(data);
          }
          return;
        }
        if (data.role === 'user') {
          window.location.href = target;
          return;
        }
      }

      window.location.href = target;
    } catch (e) {
      showError('Could not reach the server. Please open the website through its domain and check that the hosting server is available.');
    } finally {
      isLoggingIn = false;
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Sign In';
      }
    }
  }

  function initLoginScreen(options) {
    window.EinsteinLoginConfig = options || {};

    const ctx = getReturnContext();
    const roleEl = qs('#login-role');
    if (roleEl) {
      const preferred = options.defaultRole || ctx.role || 'user';
      if (['user', 'admin'].includes(preferred)) {
        roleEl.value = preferred;
      }
    }

    const passEl = qs('#login-pass');
    const userEl = qs('#login-user');
    const formEl = qs('#login-form');
    const toggleBtn = qs('#toggle-password-btn');

    if (formEl) {
      formEl.addEventListener('submit', (e) => {
        e.preventDefault();
        submitLogin();
      });
    }

    if (passEl) {
      passEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') submitLogin();
      });
    }
    if (userEl) {
      userEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') submitLogin();
      });
    }

    if (toggleBtn && passEl) {
      toggleBtn.addEventListener('click', () => {
        const isHidden = passEl.type === 'password';
        passEl.type = isHidden ? 'text' : 'password';
        toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        toggleBtn.innerHTML = isHidden
          ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
          : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.41 18.41 0 0 1 3.42-4.2"></path><path d="M1 1l22 22"></path><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"></path></svg>';
      });
    }
  }

  async function checkSessionForPage(expectedRole) {
    try {
      const res = await fetch(apiUrl('api/check_session.php'));
      const data = await res.json();
      if (!data.logged_in) return false;

      if (expectedRole && data.role !== expectedRole) {
        if (data.role === 'admin') {
          window.location.href = 'admin.html';
        } else if (data.role === 'user') {
          window.location.href = 'user.html';
        }
        return true;
      }

      if (data.role === 'user' && data.email) {
        localStorage.setItem('userEmail', data.email);
        sessionStorage.setItem('userId', data.user_id);
      }
      sessionStorage.setItem('einstein-login-role', data.role);
      return true;
    } catch {
      return false;
    }
  }

  window.doLogin = submitLogin;
  window.EinsteinLogin = {
    init: initLoginScreen,
    submit: submitLogin,
    hide: hideLoginScreen,
    show: showLoginScreen,
    checkSessionForPage,
    buildLoginUrl,
    getReturnContext,
    apiUrl,
  };

  function startInit() {
    if (document.body && document.body.dataset.autoLoginInit !== 'false') {
      initLoginScreen({ embedded: !!document.body.dataset.portalPage });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startInit);
  } else {
    startInit();
  }
})();
