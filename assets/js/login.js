(function () {
  'use strict';

  const form = document.querySelector('.md-login-form');
  if (!form) {
    return;
  }

  const passwordRevealButton = form.querySelector('[data-password-reveal]');
  const passwordRevealInput = form.querySelector('[data-password-reveal-input]');

  if (passwordRevealButton && passwordRevealInput) {
    const hidePassword = () => {
      passwordRevealInput.type = 'password';
      passwordRevealButton.setAttribute('aria-pressed', 'false');
    };

    const showPassword = () => {
      passwordRevealInput.type = 'text';
      passwordRevealButton.setAttribute('aria-pressed', 'true');
    };

    passwordRevealButton.setAttribute('aria-pressed', 'false');
    passwordRevealButton.addEventListener('mousedown', (event) => {
      event.preventDefault();
      showPassword();
    });
    passwordRevealButton.addEventListener('mouseup', hidePassword);
    passwordRevealButton.addEventListener('mouseleave', hidePassword);
    passwordRevealButton.addEventListener('blur', hidePassword);
    passwordRevealButton.addEventListener('touchstart', (event) => {
      event.preventDefault();
      showPassword();
    }, { passive: false });
    passwordRevealButton.addEventListener('touchend', hidePassword);
    passwordRevealButton.addEventListener('touchcancel', hidePassword);
    passwordRevealButton.addEventListener('keydown', (event) => {
      if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        showPassword();
      }
    });
    passwordRevealButton.addEventListener('keyup', (event) => {
      if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        hidePassword();
      }
    });
  }

  const storageKeys = {
    credentials: 'hrassess:offlineCredentials',
    pending: 'hrassess:offlineCredentials:pending',
    session: 'hrassess:offlineSession',
    forcedOffline: 'hrassess:connectivity:forcedOffline'
  };

  const messages = {
    unavailable: form.getAttribute('data-offline-unavailable') || 'Offline login is not available yet. Connect to the internet and sign in once to enable offline access.',
    invalid: form.getAttribute('data-offline-invalid') || 'Offline sign-in failed. Double-check your username and password.',
    error: form.getAttribute('data-offline-error') || 'We could not complete offline sign-in. Try again when you have a connection.'
  };

  const hasLocalStorage = (() => {
    try {
      const testKey = '__hrassess_offline_test__';
      window.localStorage.setItem(testKey, '1');
      window.localStorage.removeItem(testKey);
      return true;
    } catch (err) {
      return false;
    }
  })();

  const removeItem = (key) => {
    if (!hasLocalStorage) {
      return;
    }
    try {
      window.localStorage.removeItem(key);
    } catch (err) {
      // Ignore storage failures.
    }
  };

  const readJSON = (key) => {
    if (!hasLocalStorage) {
      return null;
    }
    try {
      const raw = window.localStorage.getItem(key);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        return parsed;
      }
    } catch (err) {
      // Ignore parse errors.
    }
    return null;
  };

  const writeJSON = (key, value) => {
    if (!hasLocalStorage) {
      return false;
    }
    try {
      if (value === null || typeof value === 'undefined') {
        window.localStorage.removeItem(key);
      } else {
        window.localStorage.setItem(key, JSON.stringify(value));
      }
      return true;
    } catch (err) {
      return false;
    }
  };

  const showError = (message) => {
    if (typeof message !== 'string' || message.trim() === '') {
      return;
    }
    let alert = document.querySelector('.md-alert.error');
    if (!alert) {
      alert = document.createElement('div');
      alert.className = 'md-alert error';
      alert.setAttribute('role', 'alert');
      const formPanel = form.closest('.md-login-panel');
      if (formPanel) {
        formPanel.insertBefore(alert, formPanel.firstChild);
      } else {
        form.parentNode.insertBefore(alert, form);
      }
    }
    alert.textContent = message;
    alert.hidden = false;
  };

  const textEncoder = typeof window.TextEncoder === 'function' ? new window.TextEncoder() : null;

  const toHex = (buffer) => {
    if (!buffer) {
      return '';
    }
    const view = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let hex = '';
    for (let i = 0; i < view.length; i += 1) {
      hex += view[i].toString(16).padStart(2, '0');
    }
    return hex;
  };

  const generateSalt = () => {
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      const bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      return toHex(bytes);
    }
    const fallback = Math.random().toString(16).slice(2);
    return fallback.padEnd(32, '0').slice(0, 32);
  };

  const hashCredential = async (username, password, salt) => {
    if (!window.crypto || !window.crypto.subtle || !textEncoder) {
      throw new Error('WebCrypto unavailable');
    }
    const value = `${username}:${password}:${salt}`;
    const data = textEncoder.encode(value);
    const digest = await window.crypto.subtle.digest('SHA-256', data);
    return toHex(digest);
  };

  const storePendingCredentials = async (username, password) => {
    if (!username || !password || !hasLocalStorage) {
      return;
    }
    if (!window.crypto || !window.crypto.subtle || !textEncoder) {
      return;
    }
    try {
      const salt = generateSalt();
      const hash = await hashCredential(username, password, salt);
      writeJSON(storageKeys.pending, {
        username,
        salt,
        hash,
        updatedAt: Date.now()
      });
    } catch (err) {
      // Ignore hashing failures.
    }
  };

  const isForcedOffline = () => {
    if (!hasLocalStorage) {
      return false;
    }
    try {
      return window.localStorage.getItem(storageKeys.forcedOffline) === '1';
    } catch (err) {
      return false;
    }
  };

  const isOfflineMode = () => {
    if (isForcedOffline()) {
      return true;
    }
    return !navigator.onLine;
  };

  const buildFromBase = (path) => {
    const base = (window.APP_BASE_URL || '').toString().replace(/\/+$/, '');
    const normalized = (path || '').toString().replace(/^\/+/, '');
    if (normalized === '') {
      return base === '' ? '/' : `${base}/`;
    }
    return base === '' ? `/${normalized}` : `${base}/${normalized}`;
  };

  const resolveTarget = (target) => {
    if (typeof target !== 'string' || target.trim() === '') {
      return buildFromBase('my_performance.php');
    }
    const trimmed = target.trim();
    if (/^https?:\/\//i.test(trimmed)) {
      return trimmed;
    }
    if (trimmed.startsWith('/')) {
      return trimmed;
    }
    return buildFromBase(trimmed);
  };

  const warmRoutes = () => {
    if (!('serviceWorker' in navigator)) {
      return;
    }
    const raw = form.getAttribute('data-offline-warm-routes') || '';
    if (raw.trim() === '') {
      return;
    }
    const routes = raw.split(',').map((part) => part.trim()).filter((part, index, arr) => part !== '' && arr.indexOf(part) === index);
    if (routes.length === 0) {
      return;
    }
    navigator.serviceWorker.ready.then((registration) => {
      if (!registration || !registration.active) {
        return;
      }
      const urls = routes.map((route) => resolveTarget(route));
      registration.active.postMessage({ type: 'WARM_ROUTE_CACHE', urls });
    }).catch(() => undefined);
  };

  const clearOfflineSession = () => {
    removeItem(storageKeys.session);
  };

  clearOfflineSession();

  const errorAlert = document.querySelector('.md-alert.error');
  if (errorAlert && errorAlert.textContent && errorAlert.textContent.trim() !== '') {
    removeItem(storageKeys.pending);
  }

  let allowNativeSubmit = false;

  const attemptOfflineLogin = async (username, password) => {
    if (!hasLocalStorage) {
      showError(messages.unavailable);
      return;
    }
    const stored = readJSON(storageKeys.credentials) || {};
    const entry = stored && stored[username];
    if (!entry || !entry.hash || !entry.salt) {
      showError(messages.unavailable);
      return;
    }
    if (!window.crypto || !window.crypto.subtle || !textEncoder) {
      showError(messages.error);
      return;
    }
    try {
      const digest = await hashCredential(username, password, entry.salt);
      if (digest !== entry.hash) {
        showError(messages.invalid);
        return;
      }
    } catch (err) {
      showError(messages.error);
      return;
    }
    writeJSON(storageKeys.session, {
      username,
      establishedAt: Date.now(),
      offline: true
    });
    removeItem(storageKeys.pending);
    warmRoutes();
    const target = resolveTarget(form.getAttribute('data-offline-redirect'));
    window.location.href = target;
  };

  const handleSubmit = async () => {
    const usernameField = form.elements.username;
    const passwordField = form.elements.password;
    const username = usernameField ? String(usernameField.value || '').trim() : '';
    const password = passwordField ? String(passwordField.value || '') : '';

    await storePendingCredentials(username, password);

    if (!isOfflineMode()) {
      allowNativeSubmit = true;
      form.submit();
      return;
    }

    if (!username || !password) {
      showError(messages.invalid);
      return;
    }

    await attemptOfflineLogin(username, password);
  };

  form.addEventListener('submit', (event) => {
    if (allowNativeSubmit) {
      allowNativeSubmit = false;
      return;
    }
    event.preventDefault();
    handleSubmit().catch(() => {
      showError(messages.error);
    });
  });
})();
