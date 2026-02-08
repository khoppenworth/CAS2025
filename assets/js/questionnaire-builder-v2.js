const BuilderV2 = (() => {
  const STRINGS = window.QB2_STRINGS || {};
  const STATUS_OPTIONS = ['draft', 'published', 'inactive'];
  const QUESTION_TYPES = ['choice', 'likert', 'text', 'textarea', 'boolean'];

  const selectors = {
    navList: '[data-qb2-nav-list]',
    navEmpty: '[data-qb2-empty]',
    editor: '[data-qb2-editor]',
    placeholder: '[data-qb2-placeholder]',
    message: '[data-qb2-message]',
    addBtn: '[data-qb2-add]',
    cloneBtn: '[data-qb2-clone]',
    saveBtn: '[data-qb2-save]',
    publishBtn: '[data-qb2-publish]',
    modal: '[data-qb2-modal]',
    modalTitle: '#qb2-modal-title',
    modalBody: '[data-qb2-modal-body]',
    modalConfirm: '[data-qb2-modal-confirm]',
    modalCancel: '[data-qb2-modal-cancel]',
    modalClose: '[data-qb2-modal-close]',
  };

  const state = {
    questionnaires: [],
    activeId: null,
    dirty: false,
    saving: false,
    csrf: '',
    modalAction: null,
  };

  const baseMeta = document.querySelector('meta[name="app-base-url"]');
  let appBase = window.APP_BASE_URL || (baseMeta ? baseMeta.content : '/');
  if (!appBase || typeof appBase !== 'string') appBase = '/';
  const normalizedBase = appBase.replace(/\/+$/, '');
  const withBase = (path) => `${normalizedBase}${path.startsWith('/') ? path : '/' + path}`;

  function uuid(prefix = 'tmp') {
    if (window.crypto?.randomUUID) return `${prefix}-${window.crypto.randomUUID()}`;
    return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function toBoolean(value, fallback = false) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    if (typeof value === 'string') {
      const normalized = value.trim().toLowerCase();
      if (['1', 'true', 'yes', 'y', 'on'].includes(normalized)) return true;
      if (['0', 'false', 'no', 'n', 'off', ''].includes(normalized)) return false;
    }
    if (value === null || value === undefined) return fallback;
    return Boolean(value);
  }

  function normalizeStatusValue(value) {
    const normalized = String(value || '').trim().toLowerCase();
    return STATUS_OPTIONS.includes(normalized) ? normalized : 'draft';
  }

  function normalizeOption(option) {
    return {
      id: option.id ?? null,
      clientId: option.clientId || uuid('o'),
      value: option.value || '',
      is_correct: toBoolean(option.is_correct),
    };
  }

  function normalizeItem(item) {
    const options = Array.isArray(item.options)
      ? item.options.map((opt) => normalizeOption(opt))
      : [];
    const type = QUESTION_TYPES.includes(String(item.type || '').toLowerCase())
      ? String(item.type).toLowerCase()
      : 'choice';
    const allowMultiple = type === 'choice' && toBoolean(item.allow_multiple);
    const requiresCorrect = type === 'choice' && !allowMultiple ? toBoolean(item.requires_correct) : false;
    return {
      id: item.id ?? null,
      clientId: item.clientId || uuid('i'),
      linkId: item.linkId || '',
      text: item.text || '',
      type,
      options,
      allow_multiple: allowMultiple,
      is_required: toBoolean(item.is_required),
      is_active: toBoolean(item.is_active, true),
      hasResponses: toBoolean(item.has_responses),
      requires_correct: requiresCorrect,
    };
  }

  function normalizeSection(section) {
    const items = Array.isArray(section.items)
      ? section.items.map((item) => normalizeItem(item))
      : [];
    return {
      id: section.id ?? null,
      clientId: section.clientId || uuid('s'),
      title: section.title || '',
      description: section.description || '',
      is_active: toBoolean(section.is_active, true),
      hasResponses: toBoolean(section.has_responses),
      items,
    };
  }

  function normalizeQuestionnaire(raw) {
    const sections = Array.isArray(raw.sections)
      ? raw.sections.map((section) => normalizeSection(section))
      : [];
    const items = Array.isArray(raw.items)
      ? raw.items.map((item) => normalizeItem(item))
      : [];
    return {
      id: raw.id ?? null,
      clientId: raw.clientId || uuid('q'),
      title: raw.title || 'Untitled Questionnaire',
      description: raw.description || '',
      status: normalizeStatusValue(raw.status),
      version: Number.isFinite(Number(raw.version)) ? Number(raw.version) : 1,
      sections,
      items,
      hasResponses: toBoolean(raw.has_responses),
    };
  }

  function cloneQuestionnaire(source) {
    const cloneOptions = (opts) =>
      opts.map((opt) => ({
        ...opt,
        id: null,
        clientId: uuid('o'),
      }));

    const cloneItems = (items) =>
      items.map((item) => ({
        ...item,
        id: null,
        clientId: uuid('i'),
        options: cloneOptions(item.options || []),
      }));

    const cloneSections = (sections) =>
      sections.map((section) => ({
        ...section,
        id: null,
        clientId: uuid('s'),
        items: cloneItems(section.items || []),
      }));

    return {
      ...source,
      id: null,
      clientId: uuid('q'),
      title: source.title ? `${source.title} (Copy)` : 'Untitled Questionnaire (Copy)',
      status: 'draft',
      version: (source.version || 1) + 1,
      sections: cloneSections(source.sections || []),
      items: cloneItems(source.items || []),
    };
  }

  function showModal({ title, body, onConfirm, onCancel }) {
    const modal = document.querySelector(selectors.modal);
    if (!modal) return;
    const titleEl = modal.querySelector(selectors.modalTitle);
    const bodyEl = modal.querySelector(selectors.modalBody);
    const confirmBtn = modal.querySelector(selectors.modalConfirm);
    const cancelBtn = modal.querySelector(selectors.modalCancel);

    if (titleEl) titleEl.textContent = title || '';
    if (bodyEl) bodyEl.textContent = body || '';
    if (confirmBtn) confirmBtn.textContent = STRINGS.modalConfirm || 'Clone & edit draft';
    if (cancelBtn) cancelBtn.textContent = STRINGS.modalCancel || 'Cancel';

    state.modalAction = { onConfirm, onCancel };
    modal.hidden = false;
  }

  function closeModal() {
    const modal = document.querySelector(selectors.modal);
    if (modal) modal.hidden = true;
    state.modalAction = null;
  }

  function confirmModal() {
    if (state.modalAction?.onConfirm) state.modalAction.onConfirm();
    closeModal();
  }

  function cancelModal() {
    if (state.modalAction?.onCancel) state.modalAction.onCancel();
    closeModal();
  }

  function resetFieldToValue(target, value) {
    if (!target) return;
    if (target.type === 'checkbox' || target.type === 'radio') {
      target.checked = Boolean(value);
    } else {
      target.value = value ?? '';
    }
  }

  function guardPublishedEdit(active, target, applyChange, revertValue) {
    if (normalizeStatusValue(active.status) !== 'published') {
      applyChange();
      return true;
    }
    showModal({
      title: STRINGS.modalTitle || 'Published questionnaire',
      body: STRINGS.modalBody || 'To make changes, create a new draft version. The published questionnaire will remain unchanged.',
      onConfirm: () => {
        const clone = cloneQuestionnaire(active);
        state.questionnaires.unshift(clone);
        state.activeId = clone.clientId;
        applyChange(clone);
        markDirty();
        render();
      },
      onCancel: () => resetFieldToValue(target, revertValue),
    });
    return false;
  }

  function guardPublishedAction(active, action, revertFn) {
    if (normalizeStatusValue(active.status) !== 'published') {
      action();
      return true;
    }
    showModal({
      title: STRINGS.modalTitle || 'Published questionnaire',
      body: STRINGS.modalBody || 'To make changes, create a new draft version. The published questionnaire will remain unchanged.',
      onConfirm: () => {
        const clone = cloneQuestionnaire(active);
        state.questionnaires.unshift(clone);
        state.activeId = clone.clientId;
        action(clone);
        markDirty();
        render();
      },
      onCancel: () => {
        if (typeof revertFn === 'function') revertFn();
      },
    });
    return false;
  }

  function setMessage(text, stateValue = '') {
    const message = document.querySelector(selectors.message);
    if (!message) return;
    message.textContent = text || '';
    message.dataset.state = stateValue;
  }

  function markDirty() {
    state.dirty = true;
  }

  function setActive(id) {
    state.activeId = id;
    render();
  }

  function addQuestionnaire() {
    const next = normalizeQuestionnaire({
      title: STRINGS.untitled || 'Untitled Questionnaire',
      status: 'draft',
      sections: [],
      items: [],
    });
    state.questionnaires.unshift(next);
    setActive(next.clientId);
    markDirty();
    render();
  }

  function addSection(q) {
    q.sections.push({
      id: null,
      clientId: uuid('s'),
      title: '',
      description: '',
      is_active: true,
      hasResponses: false,
      items: [],
    });
    markDirty();
    render();
  }

  function addItem(list) {
    list.push({
      id: null,
      clientId: uuid('i'),
      linkId: '',
      text: '',
      type: 'choice',
      options: [normalizeOption({ value: '' })],
      allow_multiple: false,
      is_required: false,
      is_active: true,
      hasResponses: false,
      requires_correct: false,
    });
    markDirty();
    render();
  }

  function addOption(item) {
    item.options.push(normalizeOption({ value: '' }));
    markDirty();
    render();
  }

  function removeByClientId(list, clientId) {
    const idx = list.findIndex((entry) => entry.clientId === clientId);
    if (idx >= 0) {
      list.splice(idx, 1);
      markDirty();
      render();
    }
  }

  function serializeQuestionnaire(q) {
    return {
      id: q.id || undefined,
      clientId: q.clientId,
      title: q.title,
      description: q.description,
      status: normalizeStatusValue(q.status),
      sections: q.sections.map((section) => serializeSection(section)),
      items: q.items.map((item) => serializeItem(item)),
    };
  }

  function serializeSection(section) {
    return {
      id: section.id || undefined,
      clientId: section.clientId,
      title: section.title,
      description: section.description,
      is_active: section.is_active,
      items: section.items.map((item) => serializeItem(item)),
    };
  }

  function serializeItem(item) {
    return {
      id: item.id || undefined,
      clientId: item.clientId,
      linkId: item.linkId,
      text: item.text,
      type: item.type,
      allow_multiple: item.allow_multiple,
      is_required: item.is_required,
      requires_correct: item.requires_correct,
      is_active: item.is_active,
      options: ['choice', 'likert'].includes(item.type)
        ? item.options.map((opt) => ({
            id: opt.id || undefined,
            clientId: opt.clientId,
            value: opt.value,
            is_correct: opt.is_correct,
          }))
        : [],
    };
  }

  function renderNav() {
    const navList = document.querySelector(selectors.navList);
    const empty = document.querySelector(selectors.navEmpty);
    if (!navList || !empty) return;

    navList.innerHTML = '';
    if (state.questionnaires.length === 0) {
      empty.style.display = 'block';
      return;
    }
    empty.style.display = 'none';
    state.questionnaires.forEach((q) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `qb2-nav-item${q.clientId === state.activeId ? ' is-active' : ''}`;
      button.dataset.qb2Select = q.clientId;
      button.innerHTML = `
        <strong>${escapeHtml(q.title || 'Untitled')}</strong>
        <small>${escapeHtml(normalizeStatusValue(q.status))}</small>
      `;
      navList.appendChild(button);
    });
  }

  function renderEditor() {
    const editor = document.querySelector(selectors.editor);
    const placeholder = document.querySelector(selectors.placeholder);
    if (!editor || !placeholder) return;

    const active = state.questionnaires.find((q) => q.clientId === state.activeId);
    if (!active) {
      placeholder.style.display = 'block';
      editor.querySelectorAll('[data-qb2-panel]').forEach((panel) => panel.remove());
      return;
    }
    placeholder.style.display = 'none';
    editor.querySelectorAll('[data-qb2-panel]').forEach((panel) => panel.remove());

    const overview = document.createElement('section');
    overview.className = 'qb2-panel';
    overview.dataset.qb2Panel = 'overview';
    overview.innerHTML = `
      <h3>${escapeHtml(STRINGS.questionnaireTitle || 'Questionnaire')}</h3>
      <div class="qb2-grid">
        <label class="md-field">
          <span>${escapeHtml(STRINGS.questionnaireTitle || 'Title')}</span>
          <input type="text" data-qb2-field="title" value="${escapeAttr(active.title)}">
        </label>
        <label class="md-field">
          <span>${escapeHtml(STRINGS.questionnaireStatus || 'Status')}</span>
          <select data-qb2-field="status">
            ${STATUS_OPTIONS.map((status) => `<option value="${status}" ${status === normalizeStatusValue(active.status) ? 'selected' : ''}>${escapeHtml(status)}</option>`).join('')}
          </select>
        </label>
        <div class="md-field">
          <span>${escapeHtml(STRINGS.versionLabel || 'Version')}</span>
          <div class="qb2-muted">${escapeHtml(String(active.version || 1))}</div>
        </div>
      </div>
      <label class="md-field">
        <span>${escapeHtml(STRINGS.questionnaireDescription || 'Description')}</span>
        <textarea data-qb2-field="description">${escapeHtml(active.description || '')}</textarea>
      </label>
      ${active.hasResponses ? `<p class="qb2-muted">${escapeHtml(STRINGS.responsesLocked || '')}</p>` : ''}
    `;

    const sections = document.createElement('section');
    sections.className = 'qb2-panel';
    sections.dataset.qb2Panel = 'sections';
    sections.innerHTML = `
      <div class="qb2-grid">
        <h3>${escapeHtml(STRINGS.sectionTitle || 'Sections')}</h3>
        <div class="qb2-actions">
          <button class="md-button md-outline" type="button" data-qb2-add-section>${escapeHtml(STRINGS.addSection || 'Add section')}</button>
        </div>
      </div>
      <div class="qb2-sections"></div>
      <h4>${escapeHtml(STRINGS.addItem || 'Items without section')}</h4>
      <div class="qb2-items" data-qb2-root-items></div>
      <button class="md-button md-outline" type="button" data-qb2-add-root-item>${escapeHtml(STRINGS.addItem || 'Add question')}</button>
    `;

    const sectionsContainer = sections.querySelector('.qb2-sections');
    active.sections.forEach((section) => {
      const sectionEl = document.createElement('div');
      sectionEl.className = 'qb2-section';
      sectionEl.dataset.qb2Section = section.clientId;
      sectionEl.innerHTML = `
        <div class="qb2-grid">
          <label class="md-field">
            <span>${escapeHtml(STRINGS.sectionTitle || 'Section title')}</span>
            <input type="text" data-qb2-section-field="title" value="${escapeAttr(section.title)}">
          </label>
          <label class="md-field">
            <span>${escapeHtml(STRINGS.sectionDescription || 'Section description')}</span>
            <input type="text" data-qb2-section-field="description" value="${escapeAttr(section.description || '')}">
          </label>
        </div>
        <div class="qb2-grid">
          <label class="md-checkbox">
            <input type="checkbox" data-qb2-section-field="is_active" ${section.is_active ? 'checked' : ''} ${section.hasResponses ? 'disabled' : ''}>
            <span>${escapeHtml(STRINGS.active || 'Active')}</span>
          </label>
          <button class="md-button md-ghost" type="button" data-qb2-remove-section ${section.hasResponses ? 'disabled' : ''}>Remove section</button>
          ${section.hasResponses ? `<span class="qb2-muted">${escapeHtml(STRINGS.responsesLocked || '')}</span>` : ''}
        </div>
        <div class="qb2-items" data-qb2-section-items></div>
        <button class="md-button md-outline" type="button" data-qb2-add-item>${escapeHtml(STRINGS.addItem || 'Add question')}</button>
      `;

      const itemsContainer = sectionEl.querySelector('[data-qb2-section-items]');
      section.items.forEach((item) => itemsContainer.appendChild(renderItem(item, section.clientId)));
      sectionsContainer.appendChild(sectionEl);
    });

    const rootItems = sections.querySelector('[data-qb2-root-items]');
    active.items.forEach((item) => rootItems.appendChild(renderItem(item, null)));

    editor.appendChild(overview);
    editor.appendChild(sections);
  }

  function renderItem(item, sectionId) {
    const itemEl = document.createElement('div');
    itemEl.className = 'qb2-item';
    itemEl.dataset.qb2Item = item.clientId;
    if (sectionId) itemEl.dataset.qb2Section = sectionId;

    const optionsHtml = ['choice', 'likert'].includes(item.type)
      ? `
        <div class="qb2-item-options" data-qb2-options>
          ${item.options
            .map((opt) => `
              <div class="qb2-option-row" data-qb2-option="${opt.clientId}">
                <input type="text" data-qb2-option-field="value" value="${escapeAttr(opt.value)}">
                ${item.type === 'choice' && !item.allow_multiple && item.requires_correct
                  ? `<label class="md-checkbox"><input type="radio" name="correct_${item.clientId}" data-qb2-option-field="is_correct" ${opt.is_correct ? 'checked' : ''}><span>Correct</span></label>`
                  : ''}
                <button class="md-button md-ghost" type="button" data-qb2-remove-option>×</button>
              </div>`)
            .join('')}
          <button class="md-button md-outline" type="button" data-qb2-add-option>${escapeHtml(STRINGS.addOption || 'Add option')}</button>
        </div>
      `
      : '';

    itemEl.innerHTML = `
      <div class="qb2-grid">
        <label class="md-field">
          <span>${escapeHtml(STRINGS.itemCode || 'Question code')}</span>
          <input type="text" data-qb2-item-field="linkId" value="${escapeAttr(item.linkId)}">
        </label>
        <label class="md-field">
          <span>${escapeHtml(STRINGS.itemText || 'Question text')}</span>
          <input type="text" data-qb2-item-field="text" value="${escapeAttr(item.text)}">
        </label>
        <label class="md-field">
          <span>${escapeHtml(STRINGS.itemType || 'Type')}</span>
          <select data-qb2-item-field="type">
            ${QUESTION_TYPES.map((type) => `<option value="${type}" ${type === item.type ? 'selected' : ''}>${escapeHtml(type)}</option>`).join('')}
          </select>
        </label>
      </div>
      <div class="qb2-grid">
        <label class="md-checkbox"><input type="checkbox" data-qb2-item-field="is_required" ${item.is_required ? 'checked' : ''}><span>${escapeHtml(STRINGS.required || 'Required')}</span></label>
        <label class="md-checkbox"><input type="checkbox" data-qb2-item-field="is_active" ${item.is_active ? 'checked' : ''} ${item.hasResponses ? 'disabled' : ''}><span>${escapeHtml(STRINGS.active || 'Active')}</span></label>
        <label class="md-checkbox"><input type="checkbox" data-qb2-item-field="allow_multiple" ${item.allow_multiple ? 'checked' : ''} ${item.type !== 'choice' ? 'disabled' : ''}><span>${escapeHtml(STRINGS.allowMultiple || 'Allow multiple')}</span></label>
        <label class="md-checkbox"><input type="checkbox" data-qb2-item-field="requires_correct" ${item.requires_correct ? 'checked' : ''} ${item.type !== 'choice' || item.allow_multiple ? 'disabled' : ''}><span>${escapeHtml(STRINGS.requiresCorrect || 'Require correct answer')}</span></label>
        <button class="md-button md-ghost" type="button" data-qb2-remove-item ${item.hasResponses ? 'disabled' : ''}>Remove</button>
      </div>
      ${optionsHtml}
    `;

    return itemEl;
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/"/g, '&quot;');
  }

  function render() {
    renderNav();
    renderEditor();
  }

  function handleNavClick(event) {
    const button = event.target.closest('[data-qb2-select]');
    if (!button) return;
    setActive(button.dataset.qb2Select);
  }

  function handleEditorInput(event) {
    const active = state.questionnaires.find((q) => q.clientId === state.activeId);
    if (!active) return;

    const target = event.target;
    if (target.matches('[data-qb2-field]')) {
      const field = target.dataset.qb2Field;
      if (field === 'title') {
        const applied = guardPublishedEdit(
          active,
          target,
          (q = active) => {
            q.title = target.value;
          },
          active.title
        );
        if (applied) {
          markDirty();
          renderNav();
        }
        return;
      }
      if (field === 'description') {
        const applied = guardPublishedEdit(
          active,
          target,
          (q = active) => {
            q.description = target.value;
          },
          active.description
        );
        if (applied) {
          markDirty();
          renderNav();
        }
        return;
      }
      if (field === 'status') {
        const nextStatus = normalizeStatusValue(target.value);
        if (normalizeStatusValue(active.status) === 'published' && nextStatus === 'draft') {
          window.alert(STRINGS.invalidStatus || 'Published questionnaires cannot return to Draft. Use Inactive instead.');
          active.status = 'inactive';
        } else {
          active.status = nextStatus;
        }
        markDirty();
        renderNav();
        return;
      }
    }

    const sectionEl = target.closest('[data-qb2-section]');
    const itemEl = target.closest('[data-qb2-item]');

    if (sectionEl && target.matches('[data-qb2-section-field]')) {
      const section = active.sections.find((s) => s.clientId === sectionEl.dataset.qb2Section);
      if (!section) return;
      const field = target.dataset.qb2SectionField;
      if (field === 'title') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            section.title = target.value;
          },
          section.title
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'description') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            section.description = target.value;
          },
          section.description
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'is_active') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            section.is_active = target.checked;
          },
          section.is_active
        );
        if (applied) markDirty();
        return;
      }
      return;
    }

    if (itemEl && target.matches('[data-qb2-item-field]')) {
      const sectionId = itemEl.dataset.qb2Section || null;
      const list = sectionId
        ? active.sections.find((s) => s.clientId === sectionId)?.items
        : active.items;
      if (!list) return;
      const item = list.find((i) => i.clientId === itemEl.dataset.qb2Item);
      if (!item) return;
      const field = target.dataset.qb2ItemField;
      if (field === 'linkId') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.linkId = target.value;
          },
          item.linkId
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'text') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.text = target.value;
          },
          item.text
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'type') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.type = QUESTION_TYPES.includes(target.value) ? target.value : 'choice';
            if (item.type !== 'choice') {
              item.allow_multiple = false;
              item.requires_correct = false;
            }
            if (['choice', 'likert'].includes(item.type) && item.options.length === 0) {
              item.options.push(normalizeOption({ value: '' }));
            }
            render();
          },
          item.type
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'is_required') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.is_required = target.checked;
          },
          item.is_required
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'is_active') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.is_active = target.checked;
          },
          item.is_active
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'allow_multiple') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.allow_multiple = target.checked;
            if (item.allow_multiple) item.requires_correct = false;
            render();
          },
          item.allow_multiple
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'requires_correct') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.requires_correct = target.checked;
            if (!item.requires_correct) {
              item.options.forEach((opt) => (opt.is_correct = false));
            }
            render();
          },
          item.requires_correct
        );
        if (applied) markDirty();
        return;
      }
      return;
    }

    if (itemEl && target.matches('[data-qb2-option-field]')) {
      const sectionId = itemEl.dataset.qb2Section || null;
      const list = sectionId
        ? active.sections.find((s) => s.clientId === sectionId)?.items
        : active.items;
      if (!list) return;
      const item = list.find((i) => i.clientId === itemEl.dataset.qb2Item);
      if (!item) return;
      const optionEl = target.closest('[data-qb2-option]');
      if (!optionEl) return;
      const option = item.options.find((opt) => opt.clientId === optionEl.dataset.qb2Option);
      if (!option) return;
      const field = target.dataset.qb2OptionField;
      if (field === 'value') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            option.value = target.value;
          },
          option.value
        );
        if (applied) markDirty();
        return;
      }
      if (field === 'is_correct') {
        const applied = guardPublishedEdit(
          active,
          target,
          () => {
            item.options.forEach((opt) => (opt.is_correct = opt.clientId === option.clientId));
            render();
          },
          option.is_correct
        );
        if (applied) markDirty();
        return;
      }
    }
  }

  function handleEditorClick(event) {
    const active = state.questionnaires.find((q) => q.clientId === state.activeId);
    if (!active) return;

    if (event.target.matches('[data-qb2-add-section]')) {
      guardPublishedAction(active, (q = active) => addSection(q));
      return;
    }
    if (event.target.matches('[data-qb2-add-root-item]')) {
      guardPublishedAction(active, (q = active) => addItem(q.items));
      return;
    }

    const sectionEl = event.target.closest('[data-qb2-section]');
    if (sectionEl) {
      const section = active.sections.find((s) => s.clientId === sectionEl.dataset.qb2Section);
      if (!section) return;
      if (event.target.matches('[data-qb2-add-item]')) {
        guardPublishedAction(active, (q = active) => {
          const sectionRef = q.sections.find((s) => s.clientId === section.clientId);
          if (sectionRef) addItem(sectionRef.items);
        });
        return;
      }
      if (event.target.matches('[data-qb2-remove-section]')) {
        guardPublishedAction(active, (q = active) => removeByClientId(q.sections, section.clientId));
        return;
      }
    }

    const itemEl = event.target.closest('[data-qb2-item]');
    if (itemEl) {
      const sectionId = itemEl.dataset.qb2Section || null;
      const list = sectionId
        ? active.sections.find((s) => s.clientId === sectionId)?.items
        : active.items;
      if (!list) return;
      if (event.target.matches('[data-qb2-remove-item]')) {
        guardPublishedAction(active, (q = active) => {
          const listRef = sectionId
            ? q.sections.find((s) => s.clientId === sectionId)?.items
            : q.items;
          if (listRef) removeByClientId(listRef, itemEl.dataset.qb2Item);
        });
        return;
      }
      if (event.target.matches('[data-qb2-add-option]')) {
        guardPublishedAction(active, (q = active) => {
          const listRef = sectionId
            ? q.sections.find((s) => s.clientId === sectionId)?.items
            : q.items;
          const itemRef = listRef?.find((i) => i.clientId === itemEl.dataset.qb2Item);
          if (itemRef) addOption(itemRef);
        });
        return;
      }
      if (event.target.matches('[data-qb2-remove-option]')) {
        const optionEl = event.target.closest('[data-qb2-option]');
        if (!optionEl) return;
        guardPublishedAction(active, (q = active) => {
          const listRef = sectionId
            ? q.sections.find((s) => s.clientId === sectionId)?.items
            : q.items;
          const itemRef = listRef?.find((i) => i.clientId === itemEl.dataset.qb2Item);
          if (!itemRef) return;
          removeByClientId(itemRef.options, optionEl.dataset.qb2Option);
        });
      }
    }
  }

  function saveAll(publish = false) {
    if (state.saving) return;
    state.saving = true;
    setMessage(publish ? STRINGS.publishing : STRINGS.saving, '');

    const payload = {
      csrf: state.csrf,
      questionnaires: state.questionnaires.map((q) => serializeQuestionnaire(q)),
    };

    fetch(withBase(`/admin/questionnaire_manage.php?action=${publish ? 'publish' : 'save'}`), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': state.csrf,
      },
      credentials: 'same-origin',
      cache: 'no-store',
      body: JSON.stringify(payload),
    })
      .then((resp) => resp.json())
      .then((data) => {
        if (data?.status !== 'ok') throw new Error(data?.message || STRINGS.error);
        state.csrf = data.csrf || state.csrf;
        const map = data?.idMap?.questionnaires || {};
        state.questionnaires.forEach((q) => {
          if (!q.id && map[q.clientId]) q.id = map[q.clientId];
        });
        state.dirty = false;
        setMessage(publish ? STRINGS.published : STRINGS.saved, 'success');
        return fetchData();
      })
      .catch((err) => setMessage(err.message || STRINGS.error, 'error'))
      .finally(() => {
        state.saving = false;
      });
  }

  function fetchData() {
    return fetch(withBase(`/admin/questionnaire_manage.php?action=fetch&csrf=${encodeURIComponent(state.csrf)}&_ts=${Date.now()}`), {
      headers: {
        'X-CSRF-Token': state.csrf,
      },
      credentials: 'same-origin',
      cache: 'no-store',
    })
      .then((resp) => resp.json())
      .then((payload) => {
        if (payload?.status !== 'ok') throw new Error(payload?.message || 'Failed to load');
        state.csrf = payload.csrf || state.csrf;
        state.questionnaires = Array.isArray(payload.questionnaires)
          ? payload.questionnaires.map((q) => normalizeQuestionnaire(q))
          : [];
        if (!state.activeId && state.questionnaires[0]) {
          state.activeId = state.questionnaires[0].clientId;
        } else if (state.activeId) {
          const stillExists = state.questionnaires.some((q) => q.clientId === state.activeId || `${q.id}` === `${state.activeId}`);
          if (!stillExists && state.questionnaires[0]) state.activeId = state.questionnaires[0].clientId;
        }
        render();
      })
      .catch((err) => setMessage(err.message || STRINGS.error, 'error'));
  }

  function bind() {
    document.querySelector(selectors.navList)?.addEventListener('click', handleNavClick);
    document.querySelector(selectors.editor)?.addEventListener('input', handleEditorInput);
    document.querySelector(selectors.editor)?.addEventListener('change', handleEditorInput);
    document.querySelector(selectors.editor)?.addEventListener('click', handleEditorClick);
    document.querySelector(selectors.addBtn)?.addEventListener('click', addQuestionnaire);
    document.querySelector(selectors.cloneBtn)?.addEventListener('click', () => {
      const active = state.questionnaires.find((q) => q.clientId === state.activeId);
      if (!active) return;
      const clone = cloneQuestionnaire(active);
      state.questionnaires.unshift(clone);
      state.activeId = clone.clientId;
      markDirty();
      render();
    });
    document.querySelector(selectors.saveBtn)?.addEventListener('click', () => saveAll(false));
    document.querySelector(selectors.publishBtn)?.addEventListener('click', () => saveAll(true));
    document.querySelector(selectors.modalConfirm)?.addEventListener('click', confirmModal);
    document.querySelector(selectors.modalCancel)?.addEventListener('click', cancelModal);
    document.querySelector(selectors.modalClose)?.addEventListener('click', cancelModal);
    document.querySelector(selectors.modal)?.addEventListener('click', (event) => {
      if (event.target.matches(selectors.modalClose)) cancelModal();
    });
  }

  function init() {
    bind();
    state.csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetchData();
  }

  return { init };
})();

document.addEventListener('DOMContentLoaded', () => BuilderV2.init());
