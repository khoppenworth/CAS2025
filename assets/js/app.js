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
  const themeToggleButton = document.querySelector('[data-theme-toggle]');
  const themeOverrideStorageKey = 'hrassess:theme:override';
  const darkModeDisabled = body && body.getAttribute('data-disable-dark-mode') === '1';


  const isAssessmentProtected = document.body && document.body.getAttribute('data-assessment-protected') === 'true';
  const hasEditableTarget = (eventTarget) => {
    const el = eventTarget instanceof Element ? eventTarget : null;
    if (!el) {
      return false;
    }
    const editable = el.closest('input, textarea, [contenteditable="true"], [contenteditable=""]');
    if (!editable) {
      return false;
    }
    if (editable instanceof HTMLInputElement) {
      const type = (editable.type || '').toLowerCase();
      return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file'].includes(type);
    }
    return true;
  };

  const installAssessmentContentProtection = () => {
    if (!isAssessmentProtected) {
      return;
    }

    document.addEventListener('contextmenu', (event) => {
      if (hasEditableTarget(event.target)) {
        return;
      }
      event.preventDefault();
    });

    document.addEventListener('copy', (event) => {
      if (hasEditableTarget(event.target)) {
        return;
      }
      event.preventDefault();
    });

    document.addEventListener('cut', (event) => {
      if (hasEditableTarget(event.target)) {
        return;
      }
      event.preventDefault();
    });

    document.addEventListener('paste', (event) => {
      if (hasEditableTarget(event.target)) {
        return;
      }
      event.preventDefault();
    });

    document.addEventListener('keydown', (event) => {
      const key = (event.key || '').toLowerCase();
      const blockedShortcut = (
        (event.ctrlKey || event.metaKey) && ['c', 'x', 'v', 's', 'p', 'u'].includes(key)
      ) || (
        (event.ctrlKey || event.metaKey) && event.shiftKey && ['i', 'j', 'c', 's'].includes(key)
      ) || key === 'printscreen' || key === 'f12';

      if (!blockedShortcut) {
        return;
      }
      if (hasEditableTarget(event.target) && ['c', 'x', 'v'].includes(key)) {
        return;
      }
      event.preventDefault();
    });
  };

  installAssessmentContentProtection();

  const applyTheme = (theme) => {
    const next = theme === 'dark' ? 'dark' : 'light';
    document.body.classList.toggle('theme-dark', next === 'dark');
    document.body.classList.toggle('theme-light', next === 'light');
    document.documentElement.setAttribute('data-theme', next);
    return next;
  };

  const getStoredThemeOverride = () => {
    try {
      const stored = window.localStorage.getItem(themeOverrideStorageKey);
      return stored === 'light' || stored === 'dark' ? stored : null;
    } catch (err) {
      return null;
    }
  };

  const saveThemeOverride = (theme) => {
    try {
      window.localStorage.setItem(themeOverrideStorageKey, theme);
    } catch (err) {
      // Ignore storage limitations.
    }
  };

  const dayOfYear = (date) => {
    const start = new Date(date.getFullYear(), 0, 0);
    const diff = date - start + ((start.getTimezoneOffset() - date.getTimezoneOffset()) * 60000);
    return Math.floor(diff / 86400000);
  };

  const computeSunTimes = (date, latitude, longitude) => {
    const calc = (isSunrise) => {
      const zenith = 90.833;
      const N = dayOfYear(date);
      const lngHour = longitude / 15;
      const t = N + ((isSunrise ? 6 : 18) - lngHour) / 24;
      const M = (0.9856 * t) - 3.289;
      let L = M + (1.916 * Math.sin(M * Math.PI / 180)) + (0.020 * Math.sin(2 * M * Math.PI / 180)) + 282.634;
      L = ((L % 360) + 360) % 360;
      let RA = Math.atan(0.91764 * Math.tan(L * Math.PI / 180)) * 180 / Math.PI;
      RA = ((RA % 360) + 360) % 360;
      const Lquadrant = Math.floor(L / 90) * 90;
      const RAquadrant = Math.floor(RA / 90) * 90;
      RA = (RA + (Lquadrant - RAquadrant)) / 15;
      const sinDec = 0.39782 * Math.sin(L * Math.PI / 180);
      const cosDec = Math.cos(Math.asin(sinDec));
      const cosH = (Math.cos(zenith * Math.PI / 180) - (sinDec * Math.sin(latitude * Math.PI / 180))) / (cosDec * Math.cos(latitude * Math.PI / 180));
      if (cosH > 1 || cosH < -1) {
        return null;
      }
      let H = isSunrise ? 360 - (Math.acos(cosH) * 180 / Math.PI) : (Math.acos(cosH) * 180 / Math.PI);
      H /= 15;
      const T = H + RA - (0.06571 * t) - 6.622;
      let UT = T - lngHour;
      UT = ((UT % 24) + 24) % 24;
      const hr = Math.floor(UT);
      const min = Math.floor((UT - hr) * 60);
      const sec = Math.floor((((UT - hr) * 60) - min) * 60);
      const utcDate = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate(), hr, min, sec));
      return new Date(utcDate.getTime() + (date.getTimezoneOffset() * -60000));
    };

    return { sunrise: calc(true), sunset: calc(false) };
  };

  const detectAutoTheme = (options = {}) => {
    const now = options.now instanceof Date ? options.now : new Date();
    const coords = options.coords || null;
    if (coords && Number.isFinite(coords.latitude) && Number.isFinite(coords.longitude)) {
      const sun = computeSunTimes(now, coords.latitude, coords.longitude);
      if (sun.sunrise instanceof Date && sun.sunset instanceof Date) {
        return now >= sun.sunrise && now < sun.sunset ? 'light' : 'dark';
      }
    }
    const hour = now.getHours();
    return hour >= 7 && hour < 19 ? 'light' : 'dark';
  };

  const applyThemeMode = (overrideTheme = null, coords = null) => {
    const target = overrideTheme || detectAutoTheme({ coords });
    const activeTheme = applyTheme(target);
    if (themeToggleButton) {
      const modeText = overrideTheme ? (appStrings.theme_mode_manual || 'Manual') : (appStrings.theme_mode_auto || 'Auto');
      const nextText = activeTheme === 'dark' ? (appStrings.theme_switch_to_light || 'Switch to light theme') : (appStrings.theme_switch_to_dark || 'Switch to dark theme');
      themeToggleButton.setAttribute('aria-label', nextText);
      themeToggleButton.setAttribute('title', `${modeText}: ${activeTheme}`);
      themeToggleButton.setAttribute('data-theme-mode', overrideTheme ? 'manual' : 'auto');
      themeToggleButton.setAttribute('data-theme-active', activeTheme);
      themeToggleButton.textContent = activeTheme === 'dark' ? '🌙' : '☀️';
    }
    return activeTheme;
  };

  let manualThemeOverride = getStoredThemeOverride();
  if (darkModeDisabled) {
    applyTheme('light');
    if (themeToggleButton) {
      themeToggleButton.hidden = true;
    }
  } else {
    applyThemeMode(manualThemeOverride);

    if (!manualThemeOverride && navigator.geolocation && typeof navigator.geolocation.getCurrentPosition === 'function') {
      navigator.geolocation.getCurrentPosition((position) => {
        applyThemeMode(null, position && position.coords ? position.coords : null);
      }, () => {
        // Ignore geolocation errors and keep time-based fallback.
      }, { maximumAge: 30 * 60 * 1000, timeout: 3000, enableHighAccuracy: false });
    }

    if (themeToggleButton) {
      themeToggleButton.addEventListener('click', () => {
        const currentTheme = document.body.classList.contains('theme-dark') ? 'dark' : 'light';
        const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
        manualThemeOverride = nextTheme;
        saveThemeOverride(nextTheme);
        applyThemeMode(manualThemeOverride);
      });
    }
  }

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
      if (el.hasAttribute('disabled') || el.getAttribute('aria-hidden') === 'true') {
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
        window.requestAnimationFrame(() => backdrop.classList.add('is-visible'));
      } else {
        backdrop.classList.add('is-visible');
      }
      return;
    }
    backdrop.classList.remove('is-visible');
    backdrop.hidden = true;
  };
  const setBodyScrollLock = (shouldLock) => {
    if (!body) {
      return;
    }
    body.classList.toggle('md-lock-scroll', Boolean(shouldLock));
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
    const shouldLock = nextState && isMobileView();
    setBodyScrollLock(shouldLock);
    updateBackdrop(shouldLock);
    applyTopnavA11yState(nextState);

    if (shouldLock) {
      const firstFocusable = getTopnavFocusables()[0] || null;
      if (firstFocusable && typeof firstFocusable.focus === 'function') {
        if (typeof window.requestAnimationFrame === 'function') {
          window.requestAnimationFrame(() => firstFocusable.focus());
        } else {
          firstFocusable.focus();
        }
      }
    } else if (!nextState && lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
      lastFocusedElement = null;
    }
  };

  const closeTopnav = () => setTopnavOpen(false);

  if (topnav) {
    const items = Array.from(topnav.querySelectorAll('[data-topnav-item]')).filter((item) => item instanceof HTMLElement);
    const triggers = items.map((item) => item.querySelector('[data-topnav-trigger]')).filter((trigger) => trigger instanceof HTMLElement);
    const links = topnav.querySelectorAll('.md-topnav-link');
    const closeButtons = topnav.querySelectorAll('[data-topnav-close]');

    let mobilePanel = null;

    const setItemExpanded = (item, expanded) => {
      if (!(item instanceof HTMLElement)) {
        return;
      }
      const trigger = item.querySelector('[data-topnav-trigger]');
      const submenu = item.querySelector('.md-topnav-submenu');
      const isExpanded = Boolean(expanded);
      item.classList.toggle('is-open', isExpanded);
      if (trigger instanceof HTMLElement) {
        trigger.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      }
      if (submenu instanceof HTMLElement) {
        submenu.setAttribute('aria-hidden', isExpanded ? 'false' : 'true');
      }
    };

    const closeSubmenus = (exceptItem = null) => {
      items.forEach((item) => {
        if (exceptItem && item === exceptItem) {
          return;
        }
        setItemExpanded(item, false);
      });
    };
    closeTopnavSubmenus = () => closeSubmenus();

    const openSubmenuForItem = (item) => {
      if (!(item instanceof HTMLElement) || isMobileView()) {
        return;
      }
      closeSubmenus(item);
      setItemExpanded(item, true);
    };

    const closeMobilePanel = () => {
      topnav.classList.remove('md-mobile-panel-open');
      topnav.removeAttribute('data-mobile-active');
      if (mobilePanel instanceof HTMLElement) {
        mobilePanel.classList.remove('is-active');
        mobilePanel.innerHTML = '';
      }
      items.forEach((item) => {
        const trigger = item.querySelector('[data-topnav-trigger]');
        if (trigger instanceof HTMLElement) {
          trigger.setAttribute('aria-expanded', 'false');
        }
      });
    };

    const openMobilePanel = (item) => {
      if (!(item instanceof HTMLElement) || !isMobileView() || !(mobilePanel instanceof HTMLElement)) {
        return;
      }
      const trigger = item.querySelector('[data-topnav-trigger]');
      const submenu = item.querySelector('.md-topnav-submenu');
      const title = item.querySelector('.md-topnav-title');
      if (!(submenu instanceof HTMLElement)) {
        return;
      }
      const heading = title instanceof HTMLElement ? title.textContent.trim() : '';

      items.forEach((candidate) => {
        const candidateTrigger = candidate.querySelector('[data-topnav-trigger]');
        if (candidateTrigger instanceof HTMLElement) {
          candidateTrigger.setAttribute('aria-expanded', candidate === item ? 'true' : 'false');
        }
      });

      mobilePanel.innerHTML = `
        <button type="button" class="md-topnav-mobile-back" data-mobile-back>
          <span aria-hidden="true">&larr;</span>
          <span>${appStrings.mobile_menu_back || 'Back'}</span>
        </button>
        <p class="md-topnav-mobile-heading">${heading}</p>
        <ul class="md-topnav-mobile-panel-list">${submenu.innerHTML}</ul>
      `;
      topnav.classList.add('md-mobile-panel-open');
      topnav.setAttribute('data-mobile-active', heading || 'menu');
      mobilePanel.classList.add('is-active');

      const backButton = mobilePanel.querySelector('[data-mobile-back]');
      if (backButton instanceof HTMLElement) {
        backButton.addEventListener('click', () => {
          closeMobilePanel();
          if (trigger instanceof HTMLElement) {
            trigger.focus();
          }
        });
      }
    };

    items.forEach((item, index) => {
      const trigger = item.querySelector('[data-topnav-trigger]');
      const submenu = item.querySelector('.md-topnav-submenu');
      if (!(trigger instanceof HTMLElement) || !(submenu instanceof HTMLElement)) {
        return;
      }
      if (!submenu.id) {
        submenu.id = `topnav-submenu-${index + 1}`;
      }
      if (!trigger.id) {
        trigger.id = `topnav-trigger-${index + 1}`;
      }
      trigger.setAttribute('aria-controls', submenu.id);
      submenu.setAttribute('aria-labelledby', trigger.id);
      submenu.setAttribute('aria-hidden', 'true');
      setItemExpanded(item, false);

      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (isMobileView()) {
          openMobilePanel(item);
          return;
        }
        const willOpen = !item.classList.contains('is-open');
        closeSubmenus(item);
        setItemExpanded(item, willOpen);
      });

      trigger.addEventListener('focus', () => openSubmenuForItem(item));
      item.addEventListener('mouseenter', () => openSubmenuForItem(item));
      item.addEventListener('mouseleave', (event) => {
        if (isMobileView()) {
          return;
        }
        const nextTarget = event.relatedTarget;
        if (nextTarget instanceof Node && item.contains(nextTarget)) {
          return;
        }
        setItemExpanded(item, false);
      });
    });

    mobilePanel = document.createElement('div');
    mobilePanel.className = 'md-topnav-mobile-panel';
    mobilePanel.setAttribute('data-topnav-mobile-panel', '');
    topnav.appendChild(mobilePanel);

    const closeMenusIfOutside = (target) => {
      if (target instanceof Node && (topnav.contains(target) || (toggle && toggle.contains(target)) || (backdrop && backdrop.contains(target)))) {
        return;
      }
      closeSubmenus();
      closeMobilePanel();
      closeTopnav();
    };

    document.addEventListener('pointerdown', (event) => closeMenusIfOutside(event.target));

    topnav.addEventListener('focusout', (event) => {
      if (isMobileView()) {
        return;
      }
      const nextFocused = event.relatedTarget;
      if (nextFocused instanceof Node && topnav.contains(nextFocused)) {
        return;
      }
      closeSubmenus();
    });

    topnav.addEventListener('mouseleave', () => {
      if (!isMobileView()) {
        closeSubmenus();
      }
    });

    topnav.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeSubmenus();
        closeMobilePanel();
        closeTopnav();
        return;
      }
      if (!isMobileView() && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
        const activeTrigger = event.target instanceof HTMLElement ? event.target.closest('[data-topnav-trigger]') : null;
        if (!(activeTrigger instanceof HTMLElement)) {
          return;
        }
        const currentIndex = triggers.indexOf(activeTrigger);
        if (currentIndex < 0) {
          return;
        }
        event.preventDefault();
        const increment = event.key === 'ArrowRight' ? 1 : -1;
        const nextTrigger = triggers[(currentIndex + increment + triggers.length) % triggers.length];
        if (nextTrigger && typeof nextTrigger.focus === 'function') {
          nextTrigger.focus();
        }
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
        if (event.shiftKey && active === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && active === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    links.forEach((link) => {
      link.addEventListener('click', () => {
        closeSubmenus();
        closeMobilePanel();
        closeTopnav();
      });
    });

    topnav.addEventListener('click', (event) => {
      const panelLink = event.target instanceof HTMLElement ? event.target.closest('.md-topnav-mobile-panel .md-topnav-link') : null;
      if (panelLink) {
        closeMobilePanel();
        closeTopnav();
      }
    });

    closeButtons.forEach((buttonEl) => buttonEl.addEventListener('click', () => {
      closeMobilePanel();
      closeTopnav();
    }));

    const syncTopnavForViewport = () => {
      if (!topnav) {
        return;
      }
      if (!isMobileView()) {
        setBodyScrollLock(false);
        updateBackdrop(false);
        topnav.classList.remove('is-open');
        applyTopnavA11yState(true);
        closeMobilePanel();
        if (toggle) {
          toggle.setAttribute('aria-expanded', 'false');
        }
        closeSubmenus();
        const activeItem = topnav.querySelector('[data-topnav-item].is-active');
        if (activeItem instanceof HTMLElement) {
          setItemExpanded(activeItem, true);
        }
        lastFocusedElement = null;
        return;
      }
      const isOpen = topnav.classList.contains('is-open');
      applyTopnavA11yState(isOpen);
      if (!isOpen) {
        closeMobilePanel();
        setBodyScrollLock(false);
        updateBackdrop(false);
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
