(function() {
  if (document && document.documentElement) {
    document.documentElement.classList.add('has-js');
  }

  const baseMeta = document.querySelector('meta[name="app-base-url"]');
  let appBase = window.APP_BASE_URL || (baseMeta ? baseMeta.content : '/');
  if (typeof appBase !== 'string' || appBase === '') {
    appBase = '/';
  }
  const normalizedBase = appBase.replace(/\/+$/, '') || '';

  const topnav = document.querySelector('[data-topnav]');
  const toggle = document.querySelector('[data-drawer-toggle]');
  const backdrop = document.querySelector('[data-topnav-backdrop]');
  const body = document.body;
  const mobileMedia = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 900px)') : null;
  const appStrings = (typeof window.APP_STRINGS === 'object' && window.APP_STRINGS !== null)
    ? window.APP_STRINGS
    : {};
  const isMobileView = () => (mobileMedia ? mobileMedia.matches : window.innerWidth <= 900);
  let closeTopnavSubmenus = null;
  const focusableSelector = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  let lastFocusedElement = null;
  const getTopnavFocusables = () => {
    if (!topnav) {
      return [];
    }
    return Array.from(topnav.querySelectorAll(focusableSelector)).filter((el) => {
      if (!(el instanceof HTMLElement)) {
        return false;
      }
      if (el.hasAttribute('disabled')) {
        return false;
      }
      if (el.getAttribute('aria-hidden') === 'true') {
        return false;
      }
      const rects = el.getClientRects();
      return rects.length > 0 && Array.from(rects).some((rect) => rect.width > 0 && rect.height > 0);
    });
  };
  const updateBackdrop = (shouldShow) => {
    if (!backdrop) {
      return;
    }
    if (shouldShow) {
      backdrop.hidden = false;
      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(() => {
          backdrop.classList.add('is-visible');
        });
      } else {
        backdrop.classList.add('is-visible');
      }
    } else {
      backdrop.classList.remove('is-visible');
      backdrop.hidden = true;
    }
  };
  const setBodyScrollLock = (shouldLock) => {
    if (!body) {
      return;
    }
    if (shouldLock) {
      body.classList.add('md-lock-scroll');
    } else {
      body.classList.remove('md-lock-scroll');
    }
  };
  const applyTopnavA11yState = (isOpen) => {
    if (!topnav) {
      return;
    }
    if (isMobileView()) {
      topnav.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      topnav.setAttribute('tabindex', isOpen ? '0' : '-1');
    } else {
      topnav.removeAttribute('aria-hidden');
      topnav.removeAttribute('tabindex');
    }
  };
  const setTopnavOpen = (isOpen) => {
    if (!topnav) {
      return;
    }
    const nextState = Boolean(isOpen);
    if (nextState && isMobileView()) {
      lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }
    topnav.classList.toggle('is-open', nextState);
    if (toggle) {
      toggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
    }
    if (typeof closeTopnavSubmenus === 'function') {
      closeTopnavSubmenus();
    }
    const shouldLock = nextState && isMobileView();
    setBodyScrollLock(shouldLock);
    updateBackdrop(shouldLock);
    applyTopnavA11yState(nextState);
    if (shouldLock) {
      const focusables = getTopnavFocusables();
      const firstFocusable = focusables.length ? focusables[0] : null;
      if (firstFocusable && typeof firstFocusable.focus === 'function') {
        if (typeof window.requestAnimationFrame === 'function') {
          window.requestAnimationFrame(() => {
            firstFocusable.focus();
          });
        } else {
          firstFocusable.focus();
        }
      }
    } else if (!nextState && lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
      lastFocusedElement = null;
    }
  };
  const closeTopnav = () => {
    setTopnavOpen(false);
  };
  if (topnav) {
    const triggers = topnav.querySelectorAll('[data-topnav-trigger]');
    const links = topnav.querySelectorAll('.md-topnav-link');
    const closeButtons = topnav.querySelectorAll('[data-topnav-close]');

    const closeSubmenus = () => {
      triggers.forEach((trigger) => {
        trigger.setAttribute('aria-expanded', 'false');
        const item = trigger.closest('[data-topnav-item]');
        if (item) {
          item.classList.remove('is-open');
        }
      });
    };

    closeTopnavSubmenus = closeSubmenus;

    triggers.forEach((trigger) => {
      trigger.setAttribute('aria-expanded', 'false');
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const item = trigger.closest('[data-topnav-item]');
        if (!item) {
          return;
        }
        const willOpen = !item.classList.contains('is-open');
        closeSubmenus();
        if (willOpen) {
          trigger.setAttribute('aria-expanded', 'true');
          item.classList.add('is-open');
        }
      });
    });

    document.addEventListener('click', (event) => {
      if (topnav.contains(event.target) || (toggle && toggle.contains(event.target)) || (backdrop && backdrop.contains(event.target))) {
        return;
      }
      closeSubmenus();
      closeTopnav();
    });

    topnav.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeSubmenus();
        closeTopnav();
        return;
      }
      if (event.key === 'Tab' && isMobileView() && topnav.classList.contains('is-open')) {
        const focusables = getTopnavFocusables();
        if (!focusables.length) {
          event.preventDefault();
          return;
        }
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = document.activeElement;
        if (event.shiftKey) {
          if (active === first) {
            event.preventDefault();
            last.focus();
          }
        } else if (active === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    links.forEach((link) => {
      link.addEventListener('click', () => {
        closeSubmenus();
        closeTopnav();
      });
    });

    closeButtons.forEach((buttonEl) => {
      buttonEl.addEventListener('click', () => {
        closeSubmenus();
        closeTopnav();
      });
    });
  }

  if (topnav && toggle) {
    toggle.addEventListener('click', () => {
      const willOpen = !topnav.classList.contains('is-open');
      setTopnavOpen(willOpen);
    });
  } else if (toggle) {
    toggle.hidden = true;
    toggle.setAttribute('aria-hidden', 'true');
  }

  if (backdrop) {
    backdrop.addEventListener('click', closeTopnav);
  }

  const syncTopnavForViewport = () => {
    if (!topnav) {
      return;
    }
    if (!isMobileView()) {
      setBodyScrollLock(false);
      updateBackdrop(false);
      topnav.classList.remove('is-open');
      applyTopnavA11yState(true);
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
      }
      lastFocusedElement = null;
    } else {
      const isOpen = topnav.classList.contains('is-open');
      applyTopnavA11yState(isOpen);
      if (!isOpen) {
        setBodyScrollLock(false);
        updateBackdrop(false);
      }
    }
  };

  if (mobileMedia) {
    if (typeof mobileMedia.addEventListener === 'function') {
      mobileMedia.addEventListener('change', syncTopnavForViewport);
    } else if (typeof mobileMedia.addListener === 'function') {
      mobileMedia.addListener(syncTopnavForViewport);
    }
  }

  syncTopnavForViewport();

  if (!document.querySelector('link[rel="manifest"]')) {
    const manifest = document.createElement('link');
    manifest.rel = 'manifest';
    manifest.href = normalizedBase + '/manifest.php';
    document.head.appendChild(manifest);
  }

  const installButton = document.getElementById('appbar-install-btn');
  let deferredInstallPrompt = null;
  const isStandalone = () => {
    const mediaQuery = typeof window.matchMedia === 'function' ? window.matchMedia('(display-mode: standalone)') : null;
    return (mediaQuery && mediaQuery.matches) || window.navigator.standalone === true;
  };
  const updateInstallButtonVisibility = () => {
    if (!installButton) {
      return;
    }
    const shouldShow = Boolean(deferredInstallPrompt) && !isStandalone();
    installButton.hidden = !shouldShow;
    installButton.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    if (!shouldShow) {
      installButton.disabled = false;
    }
  };
  if (installButton) {
    installButton.addEventListener('click', async () => {
      if (!deferredInstallPrompt) {
        updateInstallButtonVisibility();
        return;
      }
      installButton.disabled = true;
      try {
        await deferredInstallPrompt.prompt();
        if (deferredInstallPrompt.userChoice) {
          await deferredInstallPrompt.userChoice.catch(() => undefined);
        }
      } catch (err) {
        // Ignore prompt errors.
      }
      deferredInstallPrompt = null;
      updateInstallButtonVisibility();
      installButton.disabled = false;
      installButton.blur();
    });
  }
  const standaloneMedia = window.matchMedia ? window.matchMedia('(display-mode: standalone)') : null;
  if (standaloneMedia) {
    const handleStandaloneChange = () => updateInstallButtonVisibility();
    if (typeof standaloneMedia.addEventListener === 'function') {
      standaloneMedia.addEventListener('change', handleStandaloneChange);
    } else if (typeof standaloneMedia.addListener === 'function') {
      standaloneMedia.addListener(handleStandaloneChange);
    }
  }
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    updateInstallButtonVisibility();
  });
  window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    updateInstallButtonVisibility();
  });
  updateInstallButtonVisibility();

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(normalizedBase + '/service-worker.js', { scope: normalizedBase + '/' })
        .then((registration) => {
          const role = (window.APP_USER && window.APP_USER.role) ? String(window.APP_USER.role).toLowerCase() : '';
          const baseBuilder = (path) => {
            const trimmed = typeof path === 'string' ? path.replace(/^\/+/, '') : '';
            if (!trimmed) {
              return normalizedBase ? normalizedBase + '/' : '/';
            }
            if (!normalizedBase) {
              return '/' + trimmed;
            }
            return normalizedBase + '/' + trimmed;
          };
          const warmRoutes = ['my_performance.php', 'submit_assessment.php', 'profile.php'];
          if (role === 'admin' || role === 'supervisor') {
            warmRoutes.push('admin/analytics.php', 'admin/users.php');
          }
          const warmUrls = Array.from(new Set(warmRoutes.map(baseBuilder)));
          if (warmUrls.length === 0) {
            return;
          }
          const sendWarmMessage = (worker) => {
            if (worker && typeof worker.postMessage === 'function') {
              worker.postMessage({ type: 'WARM_ROUTE_CACHE', urls: warmUrls });
            }
          };
          if (registration.active) {
            sendWarmMessage(registration.active);
          } else {
            navigator.serviceWorker.ready.then((readyReg) => {
              sendWarmMessage(readyReg.active);
            }).catch(() => { /* ignore readiness errors */ });
          }
        })
        .catch(() => {
          // Ignore registration failures silently
        });
    });
  }
  const brandPickers = document.querySelectorAll('[data-brand-color-picker]');
  brandPickers.forEach((picker) => {
    const input = picker.querySelector('input[type="color"]');
    const valueEl = picker.querySelector('.md-color-value');
    const resetBtn = picker.querySelector('[data-brand-color-reset]');
    const resetField = picker.querySelector('[data-brand-color-reset-field]');
    const rootStyles = window.getComputedStyle(document.documentElement);
    const themePrimary = (rootStyles.getPropertyValue('--app-primary') || rootStyles.getPropertyValue('--brand-primary') || '').trim();
    const defaultColor = (picker.dataset.defaultColor || themePrimary || '').toUpperCase();

    const formatColor = (value) => {
      if (typeof value !== 'string' || value === '') {
        return defaultColor;
      }
      return value.toUpperCase();
    };

    const updateValue = () => {
      if (input && valueEl) {
        valueEl.textContent = formatColor(input.value || defaultColor);
      }
      if (resetField) {
        resetField.value = '0';
      }
    };

    if (input) {
      input.addEventListener('input', updateValue);
      input.addEventListener('change', updateValue);
      updateValue();
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', () => {
        if (input) {
          input.value = defaultColor;
        }
        if (valueEl) {
          valueEl.textContent = defaultColor;
        }
        if (resetField) {
          resetField.value = '1';
        }
      });
    }
  });

  const availableLocales = Array.isArray(window.APP_AVAILABLE_LOCALES) ? window.APP_AVAILABLE_LOCALES : [];
  const localeMap = availableLocales.reduce((acc, loc) => {
    const key = (loc || '').toString().toLowerCase();
    if (key) {
      acc[key] = key;
    }
    return acc;
  }, {});
  if (!localeMap.en) {
    localeMap.en = 'en';
  }
  const defaultLocale = (window.APP_DEFAULT_LOCALE || 'en').toString().toLowerCase();
  const currentLocale = (document.documentElement.getAttribute('lang') || defaultLocale).toLowerCase();

  const ensureTranslateElement = (callback) => {
    if (typeof callback !== 'function') {
      return;
    }
    if (window.__googleTranslateReady && window.__googleTranslateElement) {
      callback(window.__googleTranslateElement);
      return;
    }
    window.__googleTranslateCallbacks = window.__googleTranslateCallbacks || [];
    window.__googleTranslateCallbacks.push(callback);
    if (window.__googleTranslateLoading) {
      return;
    }
    window.__googleTranslateLoading = true;
    if (!document.getElementById('google_translate_element')) {
      const hidden = document.createElement('div');
      hidden.id = 'google_translate_element';
      hidden.className = 'visually-hidden';
      hidden.setAttribute('aria-hidden', 'true');
      document.body.appendChild(hidden);
    }
    window.googleTranslateElementInit = function googleTranslateElementInit() {
      window.__googleTranslateElement = new window.google.translate.TranslateElement({
        pageLanguage: defaultLocale,
        autoDisplay: false,
      }, 'google_translate_element');
      window.__googleTranslateReady = true;
      const queue = window.__googleTranslateCallbacks || [];
      while (queue.length) {
        const cb = queue.shift();
        try {
          cb(window.__googleTranslateElement);
        } catch (err) {
          // Ignore callback errors so later handlers still run.
        }
      }
    };
    const script = document.createElement('script');
    script.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
    script.async = true;
    script.defer = true;
    script.onerror = () => {
      window.__googleTranslateLoading = false;
      window.__googleTranslateCallbacks = [];
    };
    document.head.appendChild(script);
  };

  const applyGoogleTranslate = (targetLocale) => {
    const locale = (targetLocale || '').toLowerCase();
    if (!locale || locale === defaultLocale) {
      return;
    }
    ensureTranslateElement(() => {
      const select = document.querySelector('#google_translate_element select');
      if (!select) {
        return;
      }
      const desiredValue = `${defaultLocale}|${locale}`;
      let optionValue = null;
      for (let i = 0; i < select.options.length; i += 1) {
        const value = select.options[i].value;
        if (value === desiredValue || value.slice(-locale.length - 1) === `|${locale}`) {
          optionValue = value;
          break;
        }
      }
      if (optionValue) {
        select.value = optionValue;
        const evt = document.createEvent('HTMLEvents');
        evt.initEvent('change', true, true);
        select.dispatchEvent(evt);
      }
    });
  };

  const storePendingLocale = (value) => {
    if (!window.sessionStorage) {
      return;
    }
    if (value) {
      window.sessionStorage.setItem('pendingTranslateLocale', value);
    } else {
      window.sessionStorage.removeItem('pendingTranslateLocale');
    }
  };

  const langLinkSelector = '.md-lang-switch a, .lang-switch a, .md-appbar-language';
  document.querySelectorAll(langLinkSelector).forEach((link) => {
    link.addEventListener('click', () => {
      let targetLocale = '';
      try {
        const url = new URL(link.href, window.location.href);
        targetLocale = (url.searchParams.get('lang') || '').toLowerCase();
      } catch (err) {
        targetLocale = (link.getAttribute('data-lang') || '').toLowerCase();
      }
      if (targetLocale && localeMap[targetLocale] && targetLocale !== defaultLocale) {
        storePendingLocale(targetLocale);
      } else {
        storePendingLocale('');
      }
    });
  });

  let pendingLocale = '';
  if (window.sessionStorage) {
    pendingLocale = (window.sessionStorage.getItem('pendingTranslateLocale') || '').toLowerCase();
    if (pendingLocale) {
      window.sessionStorage.removeItem('pendingTranslateLocale');
    }
  }
  const initialLocale = pendingLocale || ((localeMap[currentLocale] && currentLocale !== defaultLocale) ? currentLocale : '');
  if (initialLocale) {
    applyGoogleTranslate(initialLocale);
  }

  const stackableTableSelector = '.md-table';
  const enhancementFlag = 'true';

  const enhanceTable = (table) => {
    if (!table) {
      return;
    }
    if (table.dataset.noMobileStack === 'true' || table.hasAttribute('data-no-mobile-stack')) {
      table.dataset.mobileEnhanced = enhancementFlag;
      return;
    }
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    if (!headers.length) {
      table.dataset.mobileEnhanced = enhancementFlag;
      return;
    }
    const rows = table.querySelectorAll('tbody tr');
    if (!rows.length) {
      table.dataset.mobileEnhanced = enhancementFlag;
      return;
    }
    let labeled = false;
    rows.forEach((row) => {
      Array.from(row.children).forEach((cell, index) => {
        if (!cell || cell.nodeType !== 1) {
          return;
        }
        if (cell.tagName !== 'TD') {
          return;
        }
        if (!cell.hasAttribute('data-label')) {
          const label = headers[index] || headers[headers.length - 1] || '';
          if (label) {
            cell.setAttribute('data-label', label);
            labeled = true;
          }
        } else if ((cell.getAttribute('data-label') || '').trim() !== '') {
          labeled = true;
        }
      });
    });
    if (labeled) {
      table.classList.add('md-table--stacked');
    }
    table.dataset.mobileEnhanced = enhancementFlag;
  };

  const enhanceTables = () => {
    document.querySelectorAll(stackableTableSelector).forEach((table) => {
      enhanceTable(table);
    });
  };

  let tableEnhancementScheduled = false;
  const scheduleTableEnhancement = () => {
    if (tableEnhancementScheduled) {
      return;
    }
    tableEnhancementScheduled = true;
    requestAnimationFrame(() => {
      tableEnhancementScheduled = false;
      enhanceTables();
    });
  };

  enhanceTables();

  if ('MutationObserver' in window) {
    const tableObserver = new MutationObserver(scheduleTableEnhancement);
    tableObserver.observe(document.body, { childList: true, subtree: true });
  }
  window.addEventListener('resize', scheduleTableEnhancement);

  const connectivity = (window.AppConnectivity && typeof window.AppConnectivity.subscribe === 'function')
    ? window.AppConnectivity
    : null;
  const isAppOnline = () => {
    if (connectivity) {
      try {
        return connectivity.isOnline();
      } catch (err) {
        return navigator.onLine !== false;
      }
    }
    return navigator.onLine !== false;
  };

  let offlineBanner = null;
  let offlineHideTimer = null;
  let offlineDismissedWhileOffline = false;

  const offlineMessages = {
    offline: appStrings.offline_banner_offline || 'You are offline. Recent data will stay available until you reconnect.',
    online: appStrings.offline_banner_online || 'Back online. Syncing the latest updates now.',
  };

  const ensureOfflineBanner = () => {
    if (offlineBanner) {
      return offlineBanner;
    }
    offlineBanner = document.createElement('div');
    offlineBanner.className = 'md-offline-banner';
    offlineBanner.setAttribute('role', 'status');
    offlineBanner.setAttribute('aria-live', 'polite');
    offlineBanner.hidden = true;

    const message = document.createElement('span');
    message.className = 'md-offline-banner__message';
    offlineBanner.appendChild(message);

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'md-offline-banner__dismiss';
    const dismissLabel = appStrings.offline_banner_dismiss || 'Dismiss';
    dismiss.textContent = dismissLabel;
    dismiss.setAttribute('aria-label', appStrings.offline_banner_dismiss_aria || dismissLabel || 'Dismiss offline status message');
    dismiss.addEventListener('click', () => {
      if (offlineBanner.dataset.state === 'offline') {
        offlineDismissedWhileOffline = true;
      }
      hideOfflineBanner();
    });
    offlineBanner.appendChild(dismiss);

    document.body.appendChild(offlineBanner);
    return offlineBanner;
  };

  const hideOfflineBanner = () => {
    if (!offlineBanner) {
      return;
    }
    if (offlineHideTimer) {
      clearTimeout(offlineHideTimer);
      offlineHideTimer = null;
    }
    offlineBanner.classList.remove('is-visible');
    offlineHideTimer = setTimeout(() => {
      offlineBanner.hidden = true;
      offlineBanner.dataset.state = '';
    }, 250);
  };

  const showOfflineBanner = (state) => {
    if (state === 'offline' && offlineDismissedWhileOffline) {
      return;
    }
    const banner = ensureOfflineBanner();
    const messageEl = banner.querySelector('.md-offline-banner__message');
    if (!messageEl) {
      return;
    }
    if (offlineHideTimer) {
      clearTimeout(offlineHideTimer);
      offlineHideTimer = null;
    }
    banner.dataset.state = state;
    messageEl.textContent = offlineMessages[state] || '';
    banner.hidden = false;
    banner.classList.add('is-visible');

    if (state === 'online') {
      offlineDismissedWhileOffline = false;
      offlineHideTimer = setTimeout(() => {
        hideOfflineBanner();
      }, 4000);
    }
  };

  const helpToggle = document.querySelector('[data-help-toggle]');
  const helpOverlay = document.querySelector('[data-help-overlay]');
  const helpClose = helpOverlay ? helpOverlay.querySelector('[data-help-close]') : null;
  let helpReturnFocus = null;
  let helpHideTimer = null;

  const setHelpVisibility = (shouldShow) => {
    if (!helpOverlay) {
      return;
    }
    if (shouldShow) {
      if (helpHideTimer) {
        clearTimeout(helpHideTimer);
        helpHideTimer = null;
      }
      helpOverlay.hidden = false;
      helpOverlay.classList.add('is-visible');
      if (helpToggle) {
        helpToggle.setAttribute('aria-expanded', 'true');
      }
      if (helpClose) {
        helpClose.focus({ preventScroll: true });
      }
    } else {
      helpOverlay.classList.remove('is-visible');
      if (helpToggle) {
        helpToggle.setAttribute('aria-expanded', 'false');
      }
      helpHideTimer = window.setTimeout(() => {
        helpOverlay.hidden = true;
        helpHideTimer = null;
      }, 180);
      if (helpReturnFocus && typeof helpReturnFocus.focus === 'function') {
        helpReturnFocus.focus();
      }
    }
  };

  if (helpToggle && helpOverlay) {
    helpToggle.addEventListener('click', () => {
      const isOpen = helpOverlay.classList.contains('is-visible');
      if (!isOpen) {
        helpReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setHelpVisibility(true);
      } else {
        setHelpVisibility(false);
      }
    });
  }

  if (helpClose) {
    helpClose.addEventListener('click', () => {
      setHelpVisibility(false);
    });
  }

  if (helpOverlay) {
    helpOverlay.addEventListener('click', (event) => {
      if (event.target === helpOverlay) {
        setHelpVisibility(false);
      }
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && helpOverlay && helpOverlay.classList.contains('is-visible')) {
      event.preventDefault();
      setHelpVisibility(false);
    }
  });

  const upgradeProgressOverlay = document.querySelector('[data-upgrade-progress]');
  if (upgradeProgressOverlay) {
    const progressMessageEl = upgradeProgressOverlay.querySelector('[data-upgrade-progress-message]');
    const showUpgradeProgress = (messageText) => {
      if (progressMessageEl && typeof messageText === 'string' && messageText.trim() !== '') {
        progressMessageEl.textContent = messageText.trim();
      }
      upgradeProgressOverlay.hidden = false;
      upgradeProgressOverlay.setAttribute('aria-hidden', 'false');
      upgradeProgressOverlay.setAttribute('aria-busy', 'true');
      if (document.body) {
        document.body.classList.add('md-lock-scroll');
      }
      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(() => {
          upgradeProgressOverlay.classList.add('is-visible');
        });
      } else {
        upgradeProgressOverlay.classList.add('is-visible');
      }
    };

    const disableSubmitControls = (form) => {
      if (!(form instanceof HTMLFormElement)) {
        return;
      }
      const interactiveSelector = 'button, input[type="submit"], input[type="button"], input[type="reset"]';
      form.querySelectorAll(interactiveSelector).forEach((control) => {
        if (control instanceof HTMLButtonElement || control instanceof HTMLInputElement) {
          if (control.type === 'hidden') {
            return;
          }
          control.disabled = true;
          control.setAttribute('aria-busy', 'true');
        }
      });
    };

    const upgradeForms = document.querySelectorAll('[data-upgrade-progress-trigger]');
    upgradeForms.forEach((form) => {
      form.addEventListener('submit', () => {
        if (!(form instanceof HTMLFormElement)) {
          return;
        }
        if (form.dataset.upgradeProgressActive === '1') {
          return;
        }
        form.dataset.upgradeProgressActive = '1';
        disableSubmitControls(form);
        const customMessage = form.getAttribute('data-upgrade-progress-message') || '';
        showUpgradeProgress(customMessage);
        if (document.activeElement instanceof HTMLElement) {
          try {
            document.activeElement.blur();
          } catch (err) {
            // Ignore focus errors.
          }
        }
      });
    });
  }

  const offlineStorageKeys = {
    credentials: 'hrassess:offlineCredentials',
    pending: 'hrassess:offlineCredentials:pending',
    session: 'hrassess:offlineSession'
  };

  const hasOfflineStorage = (() => {
    try {
      const testKey = '__hrassess_offline_sync__';
      window.localStorage.setItem(testKey, '1');
      window.localStorage.removeItem(testKey);
      return true;
    } catch (err) {
      return false;
    }
  })();

  const readOfflineJSON = (key, fallback) => {
    if (!hasOfflineStorage) {
      return fallback;
    }
    try {
      const raw = window.localStorage.getItem(key);
      if (!raw) {
        return fallback;
      }
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        return parsed;
      }
    } catch (err) {
      return fallback;
    }
    return fallback;
  };

  const writeOfflineJSON = (key, value) => {
    if (!hasOfflineStorage) {
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

  const syncOfflineCredentials = () => {
    if (!hasOfflineStorage) {
      return;
    }
    const user = window.APP_USER;
    if (!user || !user.username) {
      writeOfflineJSON(offlineStorageKeys.session, null);
      return;
    }
    const username = String(user.username || '');
    if (username === '') {
      writeOfflineJSON(offlineStorageKeys.session, null);
      return;
    }

    const pending = readOfflineJSON(offlineStorageKeys.pending, null);
    if (
      pending
      && typeof pending === 'object'
      && pending.username === username
      && pending.hash
      && pending.salt
    ) {
      const credentials = readOfflineJSON(offlineStorageKeys.credentials, {});
      credentials[username] = {
        hash: pending.hash,
        salt: pending.salt,
        updatedAt: Date.now()
      };
      writeOfflineJSON(offlineStorageKeys.credentials, credentials);
      writeOfflineJSON(offlineStorageKeys.pending, null);
    }

    const session = {
      username,
      fullName: typeof user.full_name === 'string' && user.full_name !== '' ? user.full_name : null,
      updatedAt: Date.now()
    };
    writeOfflineJSON(offlineStorageKeys.session, session);
  };

  syncOfflineCredentials();
  window.addEventListener('pageshow', syncOfflineCredentials);

  let lastConnectivityState = isAppOnline() ? 'online' : 'offline';

  const handleConnectivityUpdate = (state) => {
    const online = state && typeof state.online === 'boolean' ? state.online : isAppOnline();
    const nextState = online ? 'online' : 'offline';
    if (lastConnectivityState === nextState) {
      return;
    }
    if (online) {
      showOfflineBanner('online');
    } else {
      offlineDismissedWhileOffline = false;
      showOfflineBanner('offline');
    }
    lastConnectivityState = nextState;
  };

  if (connectivity) {
    connectivity.subscribe(handleConnectivityUpdate);
  } else {
    window.addEventListener('offline', () => {
      handleConnectivityUpdate({ online: false, forcedOffline: false });
    });
    window.addEventListener('online', () => {
      handleConnectivityUpdate({ online: true, forcedOffline: false });
    });
  }

  if (!isAppOnline()) {
    showOfflineBanner('offline');
  }
})();
