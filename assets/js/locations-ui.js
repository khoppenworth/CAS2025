(function () {
  'use strict';

  function appUrl(path) {
    var base = String(window.APP_BASE_URL || '/');
    if (!base.endsWith('/')) base += '/';
    return base + String(path || '').replace(/^\/+/, '');
  }

  function isPath(suffix) {
    return window.location.pathname.replace(/\/+$/, '').endsWith('/' + suffix.replace(/^\/+/, '').replace(/\/+$/, ''));
  }

  function addNavigationLinks() {
    var role = window.APP_USER && window.APP_USER.role ? String(window.APP_USER.role) : '';
    if (role === 'admin') {
      var usersLink = Array.from(document.querySelectorAll('a[href]')).find(function (a) {
        try { return new URL(a.href, window.location.href).pathname.endsWith('/admin/users.php'); } catch (e) { return false; }
      });
      var submenu = usersLink ? usersLink.closest('ul.md-topnav-submenu') : null;
      if (submenu && !submenu.querySelector('[data-location-admin-nav]')) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = appUrl('admin/locations.php');
        a.className = 'md-topnav-link';
        a.dataset.locationAdminNav = '1';
        a.innerHTML = '<span class="md-topnav-link-content"><span class="md-topnav-link-title">Work Locations & GIS</span></span><span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>';
        li.appendChild(a);
        if (isPath('admin/locations.php')) {
          a.classList.add('active');
          a.setAttribute('aria-current', 'page');
        }
        submenu.insertBefore(li, usersLink.closest('li') ? usersLink.closest('li').nextSibling : null);
      }
    }

    var analyticsTabs = document.querySelector('.analytics-tabs');
    if (analyticsTabs && !analyticsTabs.querySelector('[data-location-analytics-tab]')) {
      var gisLink = document.createElement('a');
      gisLink.href = appUrl('admin/analytics_locations.php');
      gisLink.textContent = 'Locations & GIS';
      gisLink.dataset.locationAnalyticsTab = '1';
      if (isPath('admin/analytics_locations.php')) gisLink.classList.add('is-active');
      var links = analyticsTabs.querySelectorAll('a');
      if (links.length > 1) links[1].insertAdjacentElement('afterend', gisLink);
      else analyticsTabs.appendChild(gisLink);
    }
  }

  function createLocationField(payload) {
    var form = document.querySelector('form[data-profile-form]');
    if (!form || form.querySelector('[data-profile-location-select]')) return null;
    var firstFields = form.querySelector('.md-profile-fields');
    if (!firstFields) return null;

    var wrapper = document.createElement('label');
    wrapper.className = 'md-field';
    wrapper.dataset.profileLocationWrapper = '1';
    var label = document.createElement('span');
    label.textContent = 'Work Location / Duty Station';
    var select = document.createElement('select');
    select.name = 'location_id';
    select.dataset.profileLocationSelect = '1';
    select.setAttribute('aria-label', 'Work Location / Duty Station');

    var blank = document.createElement('option');
    blank.value = '0';
    blank.textContent = 'Not assigned';
    select.appendChild(blank);

    (payload.locations || []).forEach(function (location) {
      var option = document.createElement('option');
      option.value = String(location.id);
      option.textContent = location.name + (location.active === false ? ' (inactive)' : '');
      if (Number(payload.current_location_id || 0) === Number(location.id)) option.selected = true;
      select.appendChild(option);
    });

    var hint = document.createElement('small');
    hint.className = 'md-muted';
    hint.textContent = 'Select your EPSS HQ or hub duty station. This is used for location analytics, not questionnaire assignment.';
    wrapper.appendChild(label);
    wrapper.appendChild(select);
    wrapper.appendChild(hint);

    var phoneField = form.querySelector('[data-phone-field]');
    var phoneWrapper = phoneField ? phoneField.closest('label') : null;
    if (phoneWrapper && phoneWrapper.parentNode === firstFields) phoneWrapper.insertAdjacentElement('afterend', wrapper);
    else firstFields.appendChild(wrapper);

    select.dataset.initialValue = select.value;
    return { form: form, select: select, wrapper: wrapper };
  }

  function loadProfileLocation() {
    if (!isPath('profile.php')) return;
    fetch(appUrl('location_profile_api.php'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('Location list request failed');
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.ok) return;
        var field = createLocationField(payload);
        if (!field) return;

        window.addEventListener('load', function () {
          field.form.addEventListener('submit', function (event) {
            if (event.defaultPrevented || field.form.dataset.locationSyncBypass === '1') return;
            if (field.select.value === field.select.dataset.initialValue) return;

            event.preventDefault();
            var csrf = field.form.querySelector('input[name="csrf"]');
            var body = new URLSearchParams();
            body.set('csrf', csrf ? csrf.value : '');
            body.set('location_id', field.select.value || '0');
            field.select.disabled = true;

            fetch(appUrl('location_profile_api.php'), {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
              body: body.toString()
            }).then(function (response) {
              return response.json().then(function (json) { return { ok: response.ok, json: json }; });
            }).then(function (result) {
              if (!result.ok || !result.json || !result.json.ok) {
                throw new Error((result.json && result.json.error) || 'Unable to save work location.');
              }
              field.select.dataset.initialValue = field.select.value;
              field.form.dataset.locationSyncBypass = '1';
              HTMLFormElement.prototype.submit.call(field.form);
            }).catch(function (error) {
              field.select.disabled = false;
              window.alert(error && error.message ? error.message : 'Unable to save work location.');
            });
          });
        }, { once: true });
      })
      .catch(function () {
        // Keep the existing profile usable if the optional location layer cannot load.
      });
  }

  addNavigationLinks();
  loadProfileLocation();
})();
