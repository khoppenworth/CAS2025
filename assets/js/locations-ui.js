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

  function samePath(anchor, suffix) {
    try {
      return new URL(anchor.href, window.location.href).pathname.endsWith('/' + suffix.replace(/^\/+/, ''));
    } catch (e) {
      return false;
    }
  }

  function addNavigationLinks() {
    var role = window.APP_USER && window.APP_USER.role ? String(window.APP_USER.role) : '';
    if (role === 'admin') {
      var usersLink = Array.from(document.querySelectorAll('a[href]')).find(function (a) {
        return samePath(a, 'admin/users.php');
      });
      var submenu = usersLink ? usersLink.closest('ul.md-topnav-submenu') : null;
      var existingLocationAdmin = submenu ? Array.from(submenu.querySelectorAll('a[href]')).some(function (a) {
        return samePath(a, 'admin/locations.php');
      }) : false;
      if (submenu && !existingLocationAdmin) {
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
    if (analyticsTabs) {
      var hasGisTab = Array.from(analyticsTabs.querySelectorAll('a[href]')).some(function (a) {
        return samePath(a, 'admin/analytics_locations.php');
      });
      if (!hasGisTab) {
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
  }

  function fetchJson(url, options) {
    return fetch(url, options).then(function (response) {
      return response.json().then(function (json) {
        return { ok: response.ok, json: json };
      });
    });
  }

  function saveLocationAssignment(csrf, locationId, userId) {
    var body = new URLSearchParams();
    body.set('csrf', csrf || '');
    body.set('location_id', String(locationId || 0));
    if (userId) body.set('user_id', String(userId));
    return fetchJson(appUrl('location_profile_api.php'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'Accept': 'application/json'
      },
      body: body.toString()
    }).then(function (result) {
      if (!result.ok || !result.json || !result.json.ok) {
        throw new Error((result.json && result.json.error) || 'Unable to save work location.');
      }
      return result.json;
    });
  }

  function addLocationOptions(select, payload, currentLocationId) {
    var blank = document.createElement('option');
    blank.value = '0';
    blank.textContent = 'Not assigned';
    select.appendChild(blank);

    (payload.locations || []).forEach(function (location) {
      var option = document.createElement('option');
      option.value = String(location.id);
      option.textContent = location.name + (location.active === false ? ' (inactive)' : '');
      if (Number(currentLocationId || 0) === Number(location.id)) option.selected = true;
      select.appendChild(option);
    });
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
    addLocationOptions(select, payload, payload.current_location_id || 0);

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

  function resubmitForm(form, submitter) {
    form.dataset.locationSyncBypass = '1';
    if (typeof form.requestSubmit === 'function' && submitter) {
      form.requestSubmit(submitter);
      return;
    }
    if (submitter && submitter.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = submitter.name;
      hidden.value = submitter.value || '1';
      form.appendChild(hidden);
    }
    HTMLFormElement.prototype.submit.call(form);
  }

  function loadProfileLocation() {
    if (!isPath('profile.php')) return;
    fetchJson(appUrl('location_profile_api.php'), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (result) {
      if (!result.ok || !result.json || !result.json.ok) return;
      var field = createLocationField(result.json);
      if (!field) return;

      // Attach immediately. Waiting for window.load here is unreliable because
      // the asynchronous location request may finish after the load event fired.
      field.form.addEventListener('submit', function (event) {
        if (field.form.dataset.locationSyncBypass === '1') return;
        if (!field.form.checkValidity()) return;
        if (field.select.value === field.select.dataset.initialValue) return;

        event.preventDefault();
        var submitter = event.submitter || null;
        var csrf = field.form.querySelector('input[name="csrf"]');
        var selectedValue = field.select.value || '0';
        field.select.disabled = true;

        saveLocationAssignment(csrf ? csrf.value : '', selectedValue, 0)
          .then(function () {
            field.select.disabled = false;
            field.select.dataset.initialValue = selectedValue;
            resubmitForm(field.form, submitter);
          })
          .catch(function (error) {
            field.select.disabled = false;
            window.alert(error && error.message ? error.message : 'Unable to save work location.');
          });
      });
    }).catch(function () {
      // Keep the existing profile usable if the optional location layer cannot load.
    });
  }

  function locationNameById(payload, locationId) {
    var id = Number(locationId || 0);
    if (!id) return 'Not assigned';
    var match = (payload.locations || []).find(function (location) {
      return Number(location.id) === id;
    });
    return match ? match.name + (match.active === false ? ' (inactive)' : '') : 'Unknown location';
  }

  function addCardLocationMeta(card, payload, locationId) {
    var meta = card.querySelector('.md-user-meta');
    if (!meta || meta.querySelector('[data-user-location-meta]')) return;
    var row = document.createElement('div');
    row.dataset.userLocationMeta = '1';
    var dt = document.createElement('dt');
    dt.textContent = 'Work Location';
    var dd = document.createElement('dd');
    dd.dataset.userLocationDisplay = '1';
    dd.textContent = locationNameById(payload, locationId);
    row.appendChild(dt);
    row.appendChild(dd);
    meta.appendChild(row);
  }

  function addUserManagementLocationField(form, card, payload, currentLocationId, userId) {
    var grid = form.querySelector('.md-user-form-grid');
    if (!grid || form.querySelector('[data-user-location-select]')) return;

    var wrapper = document.createElement('label');
    wrapper.className = 'md-field md-field--compact';
    wrapper.dataset.userLocationWrapper = '1';
    var label = document.createElement('span');
    label.textContent = 'Work Location / Duty Station';
    var select = document.createElement('select');
    select.name = 'location_ui_id';
    select.dataset.userLocationSelect = '1';
    addLocationOptions(select, payload, currentLocationId);
    select.dataset.initialValue = select.value;
    wrapper.appendChild(label);
    wrapper.appendChild(select);

    var nextAssessment = form.querySelector('input[name="next_assessment_date"]');
    var nextAssessmentWrapper = nextAssessment ? nextAssessment.closest('label') : null;
    if (nextAssessmentWrapper && nextAssessmentWrapper.parentNode === grid) {
      grid.insertBefore(wrapper, nextAssessmentWrapper);
    } else {
      grid.appendChild(wrapper);
    }

    addCardLocationMeta(card, payload, currentLocationId);

    form.addEventListener('submit', function (event) {
      if (form.dataset.locationSyncBypass === '1') return;
      if (!form.checkValidity()) return;
      if (select.value === select.dataset.initialValue) return;

      event.preventDefault();
      var submitter = event.submitter || form.querySelector('button[name="reset"]');
      var csrf = form.querySelector('input[name="csrf"]');
      var selectedValue = select.value || '0';
      select.disabled = true;

      saveLocationAssignment(csrf ? csrf.value : '', selectedValue, userId)
        .then(function () {
          select.disabled = false;
          select.dataset.initialValue = selectedValue;
          var display = card.querySelector('[data-user-location-display]');
          if (display) display.textContent = locationNameById(payload, selectedValue);
          resubmitForm(form, submitter);
        })
        .catch(function (error) {
          select.disabled = false;
          window.alert(error && error.message ? error.message : 'Unable to save work location.');
        });
    });
  }

  function addListLocationColumn(payload, assignments) {
    var table = document.querySelector('.md-user-table');
    if (!table || table.querySelector('[data-user-location-column]')) return;
    var headerRow = table.querySelector('thead tr');
    if (!headerRow) return;
    var header = document.createElement('th');
    header.dataset.userLocationColumn = '1';
    header.textContent = 'Work Location';
    var headerCells = headerRow.querySelectorAll('th');
    var headerActions = headerCells.length ? headerCells[headerCells.length - 1] : null;
    if (headerActions) headerRow.insertBefore(header, headerActions);
    else headerRow.appendChild(header);

    Array.from(table.querySelectorAll('tbody tr')).forEach(function (row) {
      var manage = row.querySelector('[data-scroll-target]');
      var target = manage ? String(manage.dataset.scrollTarget || '') : '';
      var match = target.match(/user-card-(\d+)$/);
      var userId = match ? Number(match[1]) : 0;
      var cell = document.createElement('td');
      cell.dataset.userLocationColumn = '1';
      cell.textContent = locationNameById(payload, assignments[userId] || 0);
      var cells = row.querySelectorAll('td');
      var actionsCell = cells.length ? cells[cells.length - 1] : null;
      if (actionsCell) row.insertBefore(cell, actionsCell);
      else row.appendChild(cell);
    });
  }

  function loadAdminUserLocations() {
    var role = window.APP_USER && window.APP_USER.role ? String(window.APP_USER.role) : '';
    if (role !== 'admin' || !isPath('admin/users.php')) return;

    fetchJson(appUrl('location_profile_api.php?admin_users=1'), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (result) {
      if (!result.ok || !result.json || !result.json.ok) return;
      var payload = result.json;
      var assignments = {};
      (payload.users || []).forEach(function (user) {
        assignments[Number(user.id)] = Number(user.location_id || 0);
      });

      Array.from(document.querySelectorAll('.md-user-card')).forEach(function (card) {
        var form = card.querySelector('form.md-user-update-form');
        if (!form) return;
        var idInput = form.querySelector('input[name="id"]');
        var userId = idInput ? Number(idInput.value || 0) : 0;
        if (!userId) return;
        addUserManagementLocationField(form, card, payload, assignments[userId] || 0, userId);
      });

      addListLocationColumn(payload, assignments);
    }).catch(function () {
      // User management remains functional if the optional location layer cannot load.
    });
  }

  addNavigationLinks();
  loadProfileLocation();
  loadAdminUserLocations();
})();
