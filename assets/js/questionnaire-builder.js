/*
 * Questionnaire Builder (re-coded)
 *
 * Consolidated requirements from prior iterations:
 * - Administrators manage multiple questionnaires with draft/published/inactive status.
 * - Each questionnaire can include optional description, sections, root-level items, and
 *   reusable option lists for single-choice and Likert questions.
 * - Items support types: choice, likert, text, textarea, boolean. Choice items may allow
 *   multiple selections; non-choice items ignore the flag.
 * - Single-choice items automatically split scoring evenly.
 *   When single-choice items exist, other question types are excluded from effective scoring.
 * - Manual weights can be normalized to 100%, split evenly across scorable items, or
 *   cleared entirely. A scoring summary displays manual and effective totals plus counts
 *   of scorable/weighted items with contextual warnings.
 * - Items or sections that already have responses cannot be removed or deactivated.
 * - Builder must persist changes via the questionnaire_manage endpoints (fetch, save,
 *   publish, export, upgrade) using CSRF protection and maintain consistent ordering.
 */

const Builder = (() => {
  const selectors = {
    list: '#qb-list',
    tabs: '#qb-tabs',
    addButton: '#qb-add-questionnaire',
    saveButton: '#qb-save',
    publishButton: '#qb-publish',
    exportButton: '#qb-export-questionnaire',
    openButton: '#qb-open-selected',
    message: '#qb-message',
    selector: '#qb-selector',
    sectionNav: '#qb-section-nav',
    metaCsrf: 'meta[name="csrf-token"]',
    scrollTopButton: '#qb-scroll-top',
    pageTitle: '#qb-page-title',
  };

  const QUESTION_TYPES = ['choice', 'likert', 'text', 'textarea', 'boolean'];
  const QUESTION_TYPE_LABELS = {
    choice: 'single-choice',
    likert: 'likert',
    text: 'text',
    textarea: 'textarea',
    boolean: 'boolean',
  };
  const STATUS_OPTIONS = ['draft', 'published', 'inactive'];
  const NON_SCORABLE_TYPES = ['display', 'group', 'section'];
  const LIKERT_DEFAULT_LABELS = [
    '1 - Strongly Disagree',
    '2 - Disagree',
    '3 - Neutral',
    '4 - Agree',
    '5 - Strongly Agree',
  ];

  const STORAGE_KEYS = {
    active: 'hrassess:qb:last-active',
  };

  const STRINGS = window.QB_STRINGS || {
    scoreWeightLabel: 'Score weight (%)',
    scoreWeightHint: 'Only weighted questions contribute to scoring and analytics.',
    scoringSummaryTitle: 'Scoring summary',
    scoringSummaryManualLabel: 'Manual weight total',
    scoringSummaryEffectiveLabel: 'Effective score total',
    scoringSummaryCountLabel: 'Scorable items',
    scoringSummaryWeightedLabel: 'Items counted',
    scoringSummaryActionsLabel: 'Scoring tools',
    normalizeWeights: 'Normalize to 100%',
    evenWeights: 'Split evenly',
    clearWeights: 'Clear weights',
    singleChoiceAutoNote: 'Single-choice questions automatically share 100% of the score in analytics.',
    nonSingleChoiceIgnoredNote: 'While a questionnaire contains single-choice questions, other question types are excluded from scoring.',
    likertAutoNote: 'Likert questions automatically share 100% of the score in analytics.',
    nonLikertIgnoredNote: 'While a questionnaire contains Likert questions, other question types are excluded from scoring.',
    missingWeightsWarning: 'Dashboards will show “Not scored” unless at least one question has weight.',
    manualTotalOffWarning: 'Manual weights currently add up to %s%.',
    manualTotalOk: 'Manual weights currently add up to %s%.',
    noScorableNote: 'Add single-choice or weighted questions to enable scoring.',
    normalizeSuccess: 'Weights normalized to total 100%.',
    normalizeNoop: 'Add weights to questions before normalizing.',
    evenSuccess: 'Split weights evenly across scorable questions.',
    evenNoop: 'Add scorable questions before splitting weights.',
    clearSuccess: 'Cleared all question weights.',
    clearNoop: 'No weights to clear.',
  };

  const state = {
    questionnaires: [],
    activeKey: null,
    navActiveKey: 'root',
    dirty: false,
    loading: false,
    saving: false,
    csrf: '',
  };

  let initialActiveId = window.QB_INITIAL_ACTIVE_ID || null;

  const baseMeta = document.querySelector('meta[name="app-base-url"]');
  let appBase = window.APP_BASE_URL || (baseMeta ? baseMeta.content : '/');
  if (!appBase || typeof appBase !== 'string') appBase = '/';
  const normalizedBase = appBase.replace(/\/+$/, '');
  const withBase = (path) => `${normalizedBase}${path.startsWith('/') ? path : '/' + path}`;

  function uuid(prefix = 'tmp') {
    if (window.crypto?.randomUUID) return `${prefix}-${window.crypto.randomUUID()}`;
    return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
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
      status: STATUS_OPTIONS.includes(String(raw.status || '').toLowerCase())
        ? String(raw.status).toLowerCase()
        : 'draft',
      sections,
      items,
      work_functions: Array.isArray(raw.work_functions) ? [...raw.work_functions] : undefined,
      hasResponses: Boolean(raw.has_responses),
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
      is_active: section.is_active !== false,
      items,
      hasResponses: Boolean(section.has_responses),
    };
  }

  function normalizeItem(item) {
    const options = Array.isArray(item.options)
      ? item.options.map((opt) => normalizeOption(opt))
      : [];
    const type = QUESTION_TYPES.includes(String(item.type || '').toLowerCase())
      ? String(item.type).toLowerCase()
      : 'choice';
    const allowMultiple = type === 'choice' && Boolean(item.allow_multiple);
    const requiresCorrect =
      type === 'choice' && !allowMultiple
        ? Boolean(item.requires_correct ?? options.some((opt) => opt.is_correct))
        : false;
    const normalized = {
      id: item.id ?? null,
      clientId: item.clientId || uuid('i'),
      linkId: item.linkId || '',
      text: item.text || '',
      type,
      options,
      weight_percent: Number.isFinite(Number(item.weight_percent))
        ? Number(item.weight_percent)
        : 0,
      allow_multiple: allowMultiple,
      is_required: Boolean(item.is_required),
      is_active: item.is_active !== false,
      hasResponses: Boolean(item.has_responses),
      requires_correct: requiresCorrect,
    };
    ensureSingleChoiceCorrect(normalized);
    return normalized;
  }

  function normalizeOption(option) {
    return {
      id: option.id ?? null,
      clientId: option.clientId || uuid('o'),
      value: option.value || '',
      is_correct: Boolean(option.is_correct),
    };
  }

  function ensureSingleChoiceCorrect(item) {
    if (item.type !== 'choice' || item.allow_multiple) return;
    if (!item.requires_correct) {
      item.options.forEach((opt) => {
        opt.is_correct = false;
      });
      return;
    }
    if (!item.options.length) return;
    const hasCorrect = item.options.some((opt) => opt.is_correct);
    if (!hasCorrect) {
      item.options[0].is_correct = true;
    } else {
      let found = false;
      item.options.forEach((opt) => {
        if (opt.is_correct && !found) {
          found = true;
          return;
        }
        if (opt.is_correct && found) {
          opt.is_correct = false;
        }
      });
    }
  }

  function init() {
    const csrfMeta = document.querySelector(selectors.metaCsrf);
    if (!csrfMeta) return;
    state.csrf = csrfMeta.getAttribute('content') || '';

    attachStaticListeners();
    primeFromBootstrap();
    fetchData({ silent: true });
  }

  function primeFromBootstrap() {
    const bootstrap = Array.isArray(window.QB_BOOTSTRAP) ? window.QB_BOOTSTRAP : [];
    if (bootstrap.length === 0) return;
    state.questionnaires = bootstrap.map((q) => normalizeQuestionnaire(q));
    ensureActive();
    render();
  }

  function attachStaticListeners() {
    const addBtn = document.querySelector(selectors.addButton);
    const saveBtn = document.querySelector(selectors.saveButton);
    const publishBtn = document.querySelector(selectors.publishButton);
    const exportBtns = document.querySelectorAll(selectors.exportButton);
    const openBtn = document.querySelector(selectors.openButton);
    const selector = document.querySelector(selectors.selector);
    const list = document.querySelector(selectors.list);
    const tabs = document.querySelector(selectors.tabs);
    const scrollTopBtn = document.querySelector(selectors.scrollTopButton);

    addBtn?.addEventListener('click', () => {
      addQuestionnaire();
    });

    saveBtn?.addEventListener('click', () => saveAll(false));
    publishBtn?.addEventListener('click', () => saveAll(true));
    exportBtns.forEach((btn) => btn.addEventListener('click', handleExport));
    openBtn?.addEventListener('click', () => {
      const key = selector?.value;
      if (!key) return;
      setActive(key);
      document.querySelector(selectors.list)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    scrollTopBtn?.addEventListener('click', handleScrollToTop);

    selector?.addEventListener('change', (event) => {
      const key = event.target.value;
      setActive(key);
    });

    tabs?.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-q-key]');
      if (!btn) return;
      const key = btn.getAttribute('data-q-key');
      setActive(key);
    });

    list?.addEventListener('input', handleListInput);
    list?.addEventListener('change', handleListInput);
    list?.addEventListener('click', handleListClick);

    if (scrollTopBtn) {
      window.addEventListener('scroll', toggleScrollTopVisibility, { passive: true });
      toggleScrollTopVisibility();
    }
  }

  function fetchData({ silent = false } = {}) {
    if (state.loading) return;
    state.loading = true;
    if (!silent) renderMessage('Loading questionnaires…');

    const params = new URLSearchParams({ action: 'fetch', csrf: state.csrf });
    fetch(withBase(`/admin/questionnaire_manage.php?${params.toString()}`), {
      headers: { 'X-CSRF-Token': state.csrf },
      credentials: 'same-origin',
    })
      .then((resp) => resp.json())
      .then((payload) => {
        if (payload?.status !== 'ok') throw new Error(payload?.message || 'Failed to load');
        state.csrf = payload.csrf || state.csrf;
        if (state.dirty) {
          return;
        }
        state.questionnaires = Array.isArray(payload.questionnaires)
          ? payload.questionnaires.map((q) => normalizeQuestionnaire(q))
          : [];
        ensureActive();
        state.dirty = false;
        render();
      })
      .catch((err) => renderMessage(err.message || 'Failed to load questionnaires'))
      .finally(() => {
        state.loading = false;
      });
  }

  function ensureActive() {
    if (state.questionnaires.length === 0) {
      state.activeKey = null;
      return;
    }
    if (initialActiveId) {
      const match = state.questionnaires.find(
        (q) => q.clientId === initialActiveId || `${q.id}` === `${initialActiveId}`
      );
      if (match) {
        state.activeKey = match.clientId;
        state.navActiveKey = 'root';
        initialActiveId = null;
        return;
      }
    }
    if (state.activeKey) {
      const exists = state.questionnaires.some((q) => q.clientId === state.activeKey || `${q.id}` === `${state.activeKey}`);
      if (exists) return;
    }
    const remembered = rememberGet(STORAGE_KEYS.active);
    if (remembered) {
      const match = state.questionnaires.find((q) => q.clientId === remembered || `${q.id}` === `${remembered}`);
      if (match) {
        state.activeKey = match.clientId;
        state.navActiveKey = 'root';
        return;
      }
    }
    state.activeKey = state.questionnaires[0].clientId;
    state.navActiveKey = 'root';
  }

  function rememberSet(key, value) {
    try {
      sessionStorage?.setItem(key, value);
    } catch (_) {
      /* ignore */
    }
  }

  function rememberGet(key) {
    try {
      return sessionStorage?.getItem(key) || null;
    } catch (_) {
      return null;
    }
  }

  function setActive(key) {
    if (!key) return;
    state.activeKey = key;
    state.navActiveKey = 'root';
    rememberSet(STORAGE_KEYS.active, key);
    render();
  }

  function addQuestionnaire() {
    const next = normalizeQuestionnaire({
      title: 'Untitled Questionnaire',
      status: 'draft',
      sections: [],
      items: [],
    });
    state.questionnaires.unshift(next);
    state.activeKey = next.clientId;
    state.navActiveKey = 'root';
    rememberSet(STORAGE_KEYS.active, next.clientId);
    markDirty();
    render();
  }

  function markDirty() {
    state.dirty = true;
    const saveBtn = document.querySelector(selectors.saveButton);
    const publishBtn = document.querySelector(selectors.publishButton);
    if (saveBtn) saveBtn.disabled = false;
    if (publishBtn) publishBtn.disabled = false;
  }

  function render() {
    renderSelector();
    renderTabs();
    renderQuestionnaires();
    renderSectionNav();
    toggleSaveButtons();
  }

  function renderSelector() {
    const select = document.querySelector(selectors.selector);
    if (!select) return;
    const options = state.questionnaires
      .map((q) => `<option value="${q.clientId}">${escapeHtml(labelForQuestionnaire(q))}</option>`)
      .join('');
    select.innerHTML = options;
    select.value = state.activeKey || '';
  }

  function renderTabs() {
    const tabs = document.querySelector(selectors.tabs);
    if (!tabs) return;
    const buttons = state.questionnaires
      .map((q) => {
        const active = q.clientId === state.activeKey;
        return `<button type="button" data-q-key="${q.clientId}" class="qb-tab ${active ? 'is-active' : ''}" role="tab" aria-selected="${active}">${escapeHtml(labelForQuestionnaire(q))}</button>`;
      })
      .join('');
    tabs.innerHTML = buttons;
  }

  function renderQuestionnaires() {
    const list = document.querySelector(selectors.list);
    if (!list) return;
    if (state.questionnaires.length === 0) {
      list.innerHTML = '<p class="md-hint">No questionnaires yet. Add one to get started.</p>';
      return;
    }
    const active = state.questionnaires.find((q) => q.clientId === state.activeKey) || state.questionnaires[0];
    const html = buildQuestionnaireCard(active);
    list.innerHTML = html;
    bindSortables();
  }

  function buildQuestionnaireCard(questionnaire) {
    const sectionsHtml = questionnaire.sections.map((section) => buildSectionCard(questionnaire, section)).join('');
    const rootItems = questionnaire.items.map((item) => buildItemRow(questionnaire, null, item)).join('');
    const scoring = renderScoringSummary(questionnaire);

    return `
      <div class="qb-card" data-q="${questionnaire.clientId}">
        <div class="qb-header">
          <div class="qb-field">
            <label>Title</label>
            <input type="text" data-role="q-title" value="${escapeAttr(questionnaire.title)}">
          </div>
          <div class="qb-field">
            <label>Description</label>
            <textarea data-role="q-description">${escapeHtml(questionnaire.description)}</textarea>
          </div>
          <div class="qb-field">
            <label>Status</label>
            <select class="qb-select" data-role="q-status">
              ${STATUS_OPTIONS
                .map((status) => `<option value="${status}" ${status === questionnaire.status ? 'selected' : ''}>${formatStatusLabel(status)}</option>`)
                .join('')}
            </select>
          </div>
        </div>
        <div class="qb-body">
          ${scoring}
          <div class="qb-section-list" data-role="sections" data-q="${questionnaire.clientId}">
            ${sectionsHtml}
          </div>
          <div class="qb-section-actions">
            <button type="button" class="md-button md-primary" data-role="add-section">Add Section</button>
          </div>
          <div class="qb-root-items" data-role="root-items" data-q="${questionnaire.clientId}">
            <h4 class="md-card-title">Items without section</h4>
            ${rootItems || '<p class="md-hint">No items yet.</p>'}
          </div>
          <div class="qb-root-actions">
            <button type="button" class="md-button md-outline" data-role="add-item" data-section="">Add Item</button>
          </div>
        </div>
      </div>
    `;
  }

  function buildSectionCard(questionnaire, section) {
    const items = section.items.map((item) => buildItemRow(questionnaire, section.clientId, item)).join('');
    return `
      <div class="qb-section" data-section="${section.clientId}">
        <div class="qb-section-header">
          <div class="qb-field">
            <label>Section title</label>
            <input type="text" data-role="section-title" value="${escapeAttr(section.title)}">
          </div>
          <div class="qb-field">
            <label>Description</label>
            <textarea data-role="section-description">${escapeHtml(section.description)}</textarea>
          </div>
          <div class="qb-field qb-toggle">
            <label><input type="checkbox" data-role="section-active" ${section.is_active ? 'checked' : ''} ${section.hasResponses ? 'disabled' : ''}> Active</label>
          </div>
          <div class="qb-actions">
            <button type="button" class="md-button md-outline" data-role="remove-section" ${section.hasResponses ? 'disabled' : ''}>Remove section</button>
          </div>
        </div>
        <div class="qb-items" data-role="items" data-section="${section.clientId}">
          ${items || '<p class="md-hint">No questions in this section.</p>'}
        </div>
        <div class="qb-section-actions">
          <button type="button" class="md-button md-outline" data-role="add-item" data-section="${section.clientId}">Add Question</button>
        </div>
      </div>
    `;
  }

  function buildItemRow(questionnaire, sectionClientId, item) {
    const scorable = isScorable(item.type);
    const showRequiresCorrect = item.type === 'choice' && !item.allow_multiple;
    const optionsHtml = ['choice', 'likert'].includes(item.type)
      ? buildOptionsEditor(sectionClientId, item)
      : '';
    const weightControl = scorable
      ? `<label>${STRINGS.scoreWeightLabel}<input type="number" min="0" max="100" step="1" data-role="item-weight" value="${Number(item.weight_percent) || 0}"><span class="md-hint">${STRINGS.scoreWeightHint}</span></label>`
      : '';

    return `
      <div class="qb-item" data-item="${item.clientId}" data-section="${sectionClientId || ''}">
        <div class="qb-item-main">
          <div class="qb-field">
            <label>Question Code</label>
            <input type="text" data-role="item-link" value="${escapeAttr(item.linkId)}">
          </div>
          <div class="qb-field">
            <label>Question</label>
            <input type="text" data-role="item-text" value="${escapeAttr(item.text)}">
          </div>
          <div class="qb-field">
            <label>Type</label>
            <select class="qb-select" data-role="item-type">
              ${QUESTION_TYPES
                .map((type) => `<option value="${type}" ${type === item.type ? 'selected' : ''}>${QUESTION_TYPE_LABELS[type] || type}</option>`)
                .join('')}
            </select>
          </div>
          <div class="qb-field qb-toggle">
            <label><input type="checkbox" data-role="item-required" ${item.is_required ? 'checked' : ''}> Required</label>
          </div>
          <div class="qb-field qb-toggle">
            <label><input type="checkbox" data-role="item-multi" ${item.allow_multiple ? 'checked' : ''} ${item.type !== 'choice' ? 'disabled' : ''}> Allow multiple</label>
          </div>
          ${showRequiresCorrect
            ? `<div class="qb-field qb-toggle">
                <label><input type="checkbox" data-role="item-requires-correct" ${item.requires_correct ? 'checked' : ''}> Require correct answer</label>
              </div>`
            : ''}
          <div class="qb-field qb-toggle">
            <label><input type="checkbox" data-role="item-active" ${item.is_active ? 'checked' : ''} ${item.hasResponses ? 'disabled' : ''}> Active</label>
          </div>
          <div class="qb-actions">
            <button type="button" class="md-button md-outline" data-role="remove-item" ${item.hasResponses ? 'disabled' : ''}>Remove</button>
          </div>
        </div>
        <div class="qb-item-secondary">
          ${weightControl}
          ${optionsHtml}
        </div>
      </div>
    `;
  }

  function buildOptionsEditor(sectionClientId, item) {
    const isSingleChoice = item.type === 'choice' && !item.allow_multiple;
    const showCorrect = isSingleChoice && item.requires_correct;
    const options = item.options.length
      ? item.options
      : item.type === 'likert'
        ? LIKERT_DEFAULT_LABELS.map((label) => normalizeOption({ value: label }))
        : [normalizeOption({ value: '' })];
    const rows = options
      .map(
        (opt) => `
        <div class="qb-option" data-option="${opt.clientId}" data-item="${item.clientId}" data-section="${sectionClientId || ''}">
          ${showCorrect
            ? `<label class="qb-option-correct">
                <input type="radio" name="correct_${item.clientId}" data-role="option-correct" ${opt.is_correct ? 'checked' : ''}>
                <span>Correct</span>
              </label>`
            : ''}
          <input type="text" data-role="option-value" value="${escapeAttr(opt.value)}">
          <button type="button" class="md-button md-ghost" data-role="remove-option">×</button>
        </div>`
      )
      .join('');
    return `
      <div class="qb-options" data-role="options">
        ${showCorrect ? '<p class="qb-hint">Mark the correct answer for each single-choice question.</p>' : ''}
        <div class="qb-options-list">${rows}</div>
        <button type="button" class="md-button md-outline" data-role="add-option" data-item="${item.clientId}" data-section="${sectionClientId || ''}">Add option</button>
      </div>
    `;
  }

  function renderSectionNav() {
    const nav = document.querySelector(selectors.sectionNav);
    if (!nav) return;
    const active = state.questionnaires.find((q) => q.clientId === state.activeKey);
    if (!active) {
      nav.innerHTML = `<p class="qb-section-nav-empty">${nav.dataset.emptyLabel || 'Select a questionnaire to view its sections'}</p>`;
      state.navActiveKey = 'root';
      return;
    }
    const rootLabel = nav.dataset.rootLabel || 'Items without a section';
    const untitled = nav.dataset.untitledLabel || 'Untitled questionnaire';
    const entries = [
      {
        key: 'root',
        label: truncateWithTooltip(active.title?.trim() || untitled),
        helper: truncateWithTooltip(rootLabel),
        count: active.items.length,
      },
      ...active.sections.map((section, idx) => ({
        key: section.clientId,
        label: truncateWithTooltip(section.title?.trim() || `${rootLabel} ${idx + 1}`),
        helper: truncateWithTooltip(section.description?.trim() || 'Section'),
        count: section.items.length,
      })),
    ];
    if (!entries.some((entry) => entry.key === state.navActiveKey)) {
      state.navActiveKey = 'root';
    }
    nav.innerHTML = `
      <ul class="qb-section-nav-list">
        ${entries
          .map(
            (entry) => `
              <li class="qb-section-nav-item" data-nav="${entry.key}">
                <button type="button" class="qb-section-nav-button" data-nav="${entry.key}">
                  <span class="qb-section-nav-label" title="${escapeAttr(entry.label.title)}">${escapeHtml(entry.label.display)}</span>
                  <span class="qb-section-nav-sub" title="${escapeAttr(entry.helper.title)}">${escapeHtml(entry.helper.display)}</span>
                </button>
                <span class="qb-section-nav-count">${entry.count || 0} ${entry.count === 1 ? 'item' : 'items'}</span>
              </li>`
          )
          .join('')}
      </ul>
    `;
    nav.querySelectorAll('button[data-nav]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const targetKey = btn.getAttribute('data-nav');
        state.navActiveKey = targetKey;
        setSectionNavActive(nav, targetKey);
        scrollToSection(targetKey);
      });
    });
    setSectionNavActive(nav, state.navActiveKey || 'root');
  }

  function setSectionNavActive(nav, key) {
    nav.querySelectorAll('.qb-section-nav-item').forEach((item) => {
      const isActive = item.getAttribute('data-nav') === key;
      item.classList.toggle('is-active', isActive);
      const btn = item.querySelector('button');
      if (btn) {
        btn.setAttribute('aria-current', isActive ? 'true' : 'false');
      }
    });
  }

  function scrollToSection(sectionKey) {
    let target;
    if (sectionKey === 'root') {
      target = document.querySelector('.qb-root-items');
    } else {
      target = document.querySelector(`.qb-section[data-section="${sectionKey}"]`);
    }
    target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function toggleScrollTopVisibility() {
    const btn = document.querySelector(selectors.scrollTopButton);
    if (!btn) return;
    const shouldShow = window.scrollY > 280;
    btn.classList.toggle('is-visible', shouldShow);
    if (shouldShow) {
      btn.removeAttribute('aria-hidden');
      btn.removeAttribute('tabindex');
    } else {
      btn.setAttribute('aria-hidden', 'true');
      btn.setAttribute('tabindex', '-1');
    }
  }

  function handleScrollToTop(event) {
    event?.preventDefault?.();
    const focusTarget = document.querySelector(selectors.pageTitle);
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });

    if (!focusTarget) return;
    if (reduceMotion) {
      focusTarget.focus({ preventScroll: true });
      return;
    }

    const waitForTop = () => {
      if (window.scrollY <= 2) {
        focusTarget.focus({ preventScroll: true });
      } else {
        window.requestAnimationFrame(waitForTop);
      }
    };
    window.requestAnimationFrame(waitForTop);
  }

  function toggleSaveButtons() {
    const saveBtn = document.querySelector(selectors.saveButton);
    const publishBtn = document.querySelector(selectors.publishButton);
    const disabled = state.questionnaires.length === 0 || state.saving;
    if (saveBtn) saveBtn.disabled = disabled || (!state.dirty && !state.loading);
    if (publishBtn) publishBtn.disabled = disabled || (!state.dirty && !state.loading);
  }

  function handleListInput(event) {
    const role = event.target.getAttribute('data-role');
    if (!role) return;
    const card = event.target.closest('[data-q]');
    if (!card) return;
    const qid = card.getAttribute('data-q');
    const questionnaire = state.questionnaires.find((q) => q.clientId === qid);
    if (!questionnaire) return;

    switch (role) {
      case 'q-title':
        questionnaire.title = event.target.value;
        renderTabs();
        renderSelector();
        break;
      case 'q-description':
        questionnaire.description = event.target.value;
        break;
      case 'q-status':
        questionnaire.status = event.target.value;
        renderTabs();
        renderSelector();
        break;
      case 'section-title':
      case 'section-description':
      case 'section-active':
      case 'item-link':
      case 'item-text':
      case 'item-type':
      case 'item-required':
      case 'item-multi':
      case 'item-requires-correct':
      case 'item-active':
      case 'item-weight':
      case 'option-value':
      case 'option-correct':
        applyFieldChange(questionnaire, event.target, role);
        break;
      default:
        return;
    }
    markDirty();
    if (['item-type', 'item-multi', 'item-requires-correct'].includes(role)) {
      render();
    }
  }

  function handleListClick(event) {
    const role = event.target.getAttribute('data-role');
    if (!role) return;
    const card = event.target.closest('[data-q]');
    if (!card) return;
    const qid = card.getAttribute('data-q');
    const questionnaire = state.questionnaires.find((q) => q.clientId === qid);
    if (!questionnaire) return;

    switch (role) {
      case 'add-section':
        addSection(questionnaire);
        break;
      case 'remove-section': {
        const sectionId = event.target.closest('[data-section]')?.getAttribute('data-section');
        removeSection(questionnaire, sectionId);
        break;
      }
      case 'add-item': {
        const sectionId = event.target.getAttribute('data-section') || null;
        addItem(questionnaire, sectionId);
        break;
      }
      case 'remove-item': {
        const itemId = event.target.closest('[data-item]')?.getAttribute('data-item');
        const sectionId = event.target.closest('[data-section]')?.getAttribute('data-section') || null;
        removeItem(questionnaire, sectionId, itemId);
        break;
      }
      case 'add-option': {
        const itemId = event.target.getAttribute('data-item');
        const sectionId = event.target.getAttribute('data-section') || null;
        addOption(questionnaire, sectionId, itemId);
        break;
      }
      case 'remove-option': {
        const optionRow = event.target.closest('[data-option]');
        const itemId = optionRow?.getAttribute('data-item');
        const sectionId = optionRow?.getAttribute('data-section') || null;
        const optionId = optionRow?.getAttribute('data-option');
        removeOption(questionnaire, sectionId, itemId, optionId);
        break;
      }
      case 'normalize-weights':
        normalizeWeights(questionnaire);
        break;
      case 'even-weights':
        evenWeights(questionnaire);
        break;
      case 'single-choice-weights':
        autoWeightSingleChoice(questionnaire);
        break;
      case 'clear-weights':
        clearWeights(questionnaire);
        break;
      default:
        return;
    }
    markDirty();
    render();
  }

  function applyFieldChange(questionnaire, input, role) {
    if (role.startsWith('section')) {
      const sectionId = input.closest('[data-section]')?.getAttribute('data-section');
      const section = questionnaire.sections.find((s) => s.clientId === sectionId);
      if (!section) return;
      if (role === 'section-title') section.title = input.value;
      if (role === 'section-description') section.description = input.value;
      if (role === 'section-active' && !section.hasResponses) section.is_active = input.checked;
      return;
    }

    const itemRow = input.closest('[data-item]');
    if (!itemRow) return;
    const itemId = itemRow.getAttribute('data-item');
    const sectionId = itemRow.getAttribute('data-section') || null;
    const item = findItem(questionnaire, sectionId, itemId);
    if (!item) return;

    switch (role) {
      case 'item-link':
        item.linkId = input.value;
        break;
      case 'item-text':
        item.text = input.value;
        break;
      case 'item-type':
        item.type = QUESTION_TYPES.includes(input.value) ? input.value : 'choice';
        if (item.type !== 'choice') {
          item.allow_multiple = false;
          item.requires_correct = false;
        } else if (!item.allow_multiple) {
          item.requires_correct = true;
        }
        if (['choice', 'likert'].includes(item.type) && item.options.length === 0) {
          item.options = item.type === 'likert'
            ? LIKERT_DEFAULT_LABELS.map((label) => normalizeOption({ value: label }))
            : [normalizeOption({ value: '' })];
        }
        ensureSingleChoiceCorrect(item);
        break;
      case 'item-required':
        item.is_required = input.checked;
        break;
      case 'item-multi':
        if (item.type === 'choice') {
          item.allow_multiple = input.checked;
          if (item.allow_multiple) {
            item.requires_correct = false;
          } else if (item.requires_correct !== true) {
            item.requires_correct = true;
          }
          ensureSingleChoiceCorrect(item);
        }
        break;
      case 'item-requires-correct':
        if (item.type === 'choice' && !item.allow_multiple) {
          item.requires_correct = input.checked;
          ensureSingleChoiceCorrect(item);
        }
        break;
      case 'item-active':
        if (!item.hasResponses) item.is_active = input.checked;
        break;
      case 'item-weight':
        item.weight_percent = Number(input.value) || 0;
        break;
      case 'option-value': {
        const optId = input.closest('[data-option]')?.getAttribute('data-option');
        const option = item.options.find((o) => o.clientId === optId);
        if (option) option.value = input.value;
        break;
      }
      case 'option-correct': {
        const optId = input.closest('[data-option]')?.getAttribute('data-option');
        if (!optId || item.type !== 'choice' || item.allow_multiple || !item.requires_correct) {
          break;
        }
        item.options.forEach((opt) => {
          opt.is_correct = opt.clientId === optId;
        });
        break;
      }
      default:
        break;
    }
  }

  function addSection(questionnaire) {
    questionnaire.sections.push(
      normalizeSection({
        title: '',
        description: '',
        is_active: true,
        items: [],
      })
    );
  }

  function removeSection(questionnaire, sectionClientId) {
    const index = questionnaire.sections.findIndex((s) => s.clientId === sectionClientId);
    if (index === -1) return;
    const section = questionnaire.sections[index];
    if (section.hasResponses) return;
    questionnaire.sections.splice(index, 1);
  }

  function addItem(questionnaire, sectionClientId) {
    const target = sectionClientId
      ? questionnaire.sections.find((s) => s.clientId === sectionClientId)?.items
      : questionnaire.items;
    if (!target) return;
    target.push(
      normalizeItem({
        linkId: '',
        text: '',
        type: 'choice',
        options: [
          { value: '', is_correct: true },
          { value: '' },
        ],
        weight_percent: 0,
        requires_correct: true,
      })
    );
  }

  function removeItem(questionnaire, sectionClientId, itemClientId) {
    const collection = sectionClientId
      ? questionnaire.sections.find((s) => s.clientId === sectionClientId)?.items
      : questionnaire.items;
    if (!collection) return;
    const idx = collection.findIndex((item) => item.clientId === itemClientId);
    if (idx === -1) return;
    if (collection[idx].hasResponses) return;
    collection.splice(idx, 1);
  }

  function findItem(questionnaire, sectionClientId, itemClientId) {
    const collection = sectionClientId
      ? questionnaire.sections.find((s) => s.clientId === sectionClientId)?.items
      : questionnaire.items;
    if (!collection) return null;
    return collection.find((item) => item.clientId === itemClientId) || null;
  }

  function addOption(questionnaire, sectionClientId, itemClientId) {
    const item = findItem(questionnaire, sectionClientId, itemClientId);
    if (!item) return;
    item.options.push(normalizeOption({ value: '' }));
    ensureSingleChoiceCorrect(item);
  }

  function removeOption(questionnaire, sectionClientId, itemClientId, optionClientId) {
    const item = findItem(questionnaire, sectionClientId, itemClientId);
    if (!item) return;
    const idx = item.options.findIndex((opt) => opt.clientId === optionClientId);
    if (idx >= 0) item.options.splice(idx, 1);
    ensureSingleChoiceCorrect(item);
  }

  function computeScoring(questionnaire) {
    const items = collectItems(questionnaire);
    const scorable = items.filter((item) => isScorable(item.type));
    const singleChoiceItems = scorable.filter((item) => item.type === 'choice' && !item.allow_multiple);
    const likertItems = scorable.filter((item) => item.type === 'likert');
    const manualTotal = scorable.reduce((sum, item) => sum + (Number(item.weight_percent) || 0), 0);
    let effectiveTotal = manualTotal;
    let weightedCount = scorable.filter((item) => Number(item.weight_percent) > 0).length;

    if (singleChoiceItems.length > 0) {
      const autoWeight = 100 / singleChoiceItems.length;
      effectiveTotal = singleChoiceItems.reduce((sum, item) => {
        const explicit = Number(item.weight_percent) || 0;
        return sum + (explicit > 0 ? explicit : autoWeight);
      }, 0);
      weightedCount = singleChoiceItems.length;
    } else if (likertItems.length > 0) {
      const autoWeight = 100 / likertItems.length;
      effectiveTotal = likertItems.reduce((sum, item) => {
        const explicit = Number(item.weight_percent) || 0;
        return sum + (explicit > 0 ? explicit : autoWeight);
      }, 0);
      weightedCount = likertItems.length;
    }

    return {
      manualTotal,
      effectiveTotal,
      scorableCount: scorable.length,
      weightedCount,
      hasSingleChoice: singleChoiceItems.length > 0,
      hasLikert: likertItems.length > 0,
      canNormalize: manualTotal > 0 && manualTotal !== 100,
      canDistribute: scorable.length > 0,
      canClear: scorable.some((item) => Number(item.weight_percent) > 0),
    };
  }

  function renderScoringSummary(questionnaire) {
    const summary = computeScoring(questionnaire);
    const warnings = [];
    if (summary.scorableCount === 0) warnings.push(STRINGS.noScorableNote);
    if (summary.weightedCount === 0) warnings.push(STRINGS.missingWeightsWarning);
    if (summary.hasSingleChoice) {
      warnings.push(STRINGS.singleChoiceAutoNote, STRINGS.nonSingleChoiceIgnoredNote);
    } else if (summary.hasLikert) {
      warnings.push(STRINGS.likertAutoNote, STRINGS.nonLikertIgnoredNote);
    }

    const actions = [
      { role: 'normalize-weights', label: STRINGS.normalizeWeights, enabled: summary.canNormalize },
      { role: 'even-weights', label: STRINGS.evenWeights, enabled: summary.canDistribute },
      { role: 'single-choice-weights', label: 'Auto-weight single-choice', enabled: summary.scorableCount > 0 },
      { role: 'clear-weights', label: STRINGS.clearWeights, enabled: summary.canClear },
    ]
      .map(
        (action) =>
          `<button type="button" class="md-button md-ghost" data-role="${action.role}" ${action.enabled ? '' : 'disabled'}>${escapeHtml(action.label)}</button>`
      )
      .join('');

    const manualLabel = (summary.manualTotal === 100 ? STRINGS.manualTotalOk : STRINGS.manualTotalOffWarning).replace(
      '%s',
      summary.manualTotal.toFixed(1)
    );

    return `
      <div class="qb-scoring">
        <h4>${STRINGS.scoringSummaryTitle}</h4>
        <dl class="qb-scoring-grid">
          <div><dt>${STRINGS.scoringSummaryManualLabel}</dt><dd>${manualLabel}</dd></div>
          <div><dt>${STRINGS.scoringSummaryEffectiveLabel}</dt><dd>${summary.effectiveTotal.toFixed(1)}%</dd></div>
          <div><dt>${STRINGS.scoringSummaryCountLabel}</dt><dd>${summary.scorableCount}</dd></div>
          <div><dt>${STRINGS.scoringSummaryWeightedLabel}</dt><dd>${summary.weightedCount}</dd></div>
        </dl>
        <div class="qb-scoring-actions"><span>${STRINGS.scoringSummaryActionsLabel}</span>${actions}</div>
        ${warnings.length ? `<ul class="qb-scoring-warnings">${warnings.map((w) => `<li>${escapeHtml(w)}</li>`).join('')}</ul>` : ''}
      </div>
    `;
  }

  function normalizeWeights(questionnaire) {
    const items = collectItems(questionnaire).filter((item) => isScorable(item.type));
    const total = items.reduce((sum, item) => sum + (Number(item.weight_percent) || 0), 0);
    if (total <= 0) return renderMessage(STRINGS.normalizeNoop);
    items.forEach((item) => {
      const current = Number(item.weight_percent) || 0;
      item.weight_percent = ((current / total) * 100).toFixed(2);
    });
    renderMessage(STRINGS.normalizeSuccess);
  }

  function evenWeights(questionnaire) {
    const items = collectItems(questionnaire).filter((item) => isScorable(item.type));
    if (items.length === 0) return renderMessage(STRINGS.evenNoop);
    const weight = (100 / items.length).toFixed(2);
    items.forEach((item) => {
      item.weight_percent = Number(weight);
    });
    renderMessage(STRINGS.evenSuccess);
  }

  function autoWeightSingleChoice(questionnaire) {
    const items = collectItems(questionnaire);
    const singleChoiceItems = items.filter((item) => item.type === 'choice' && !item.allow_multiple);
    const targetItems = singleChoiceItems.length > 0 ? singleChoiceItems : items.filter((item) => item.type === 'likert');
    if (targetItems.length === 0) return renderMessage(STRINGS.evenNoop);
    const weight = (100 / targetItems.length).toFixed(2);
    targetItems.forEach((item) => {
      item.weight_percent = Number(weight);
    });
    renderMessage(STRINGS.normalizeSuccess);
  }

  function clearWeights(questionnaire) {
    const items = collectItems(questionnaire).filter((item) => isScorable(item.type));
    if (!items.some((item) => Number(item.weight_percent) > 0)) return renderMessage(STRINGS.clearNoop);
    items.forEach((item) => {
      item.weight_percent = 0;
    });
    renderMessage(STRINGS.clearSuccess);
  }

  function isScorable(type) {
    return !NON_SCORABLE_TYPES.includes(type) && QUESTION_TYPES.includes(type);
  }

  function collectItems(questionnaire) {
    const items = [...questionnaire.items];
    questionnaire.sections.forEach((section) => items.push(...section.items));
    return items;
  }

  function formatStatusLabel(status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'published') return 'Published';
    if (normalized === 'inactive') return 'Inactive';
    return 'Draft';
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#96;');
  }

  function truncateWithTooltip(value, limit = 50) {
    const full = String(value || '').trim();
    if (full.length <= limit) return { display: full, title: full };
    return { display: `${full.slice(0, limit)}…`, title: full };
  }

  function slugify(value) {
    return String(value || '')
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/(^-|-$)+/g, '')
      || 'questionnaire-export';
  }

  function labelForQuestionnaire(q) {
    const label = q.title?.trim() || 'Untitled Questionnaire';
    const suffix = q.status === 'published' ? ' (Published)' : q.status === 'inactive' ? ' (Inactive)' : '';
    return `${label}${suffix}`;
  }

  function renderMessage(text, tone = 'info') {
    const message = document.querySelector(selectors.message);
    if (!message) return;
    message.textContent = text || '';
    if (tone === 'error') {
      message.dataset.state = 'error';
    } else if (tone === 'success') {
      message.dataset.state = 'success';
    } else {
      message.dataset.state = '';
    }
  }

  function bindSortables() {
    if (!window.Sortable) return;
    const sectionContainer = document.querySelector('[data-role="sections"]');
    if (sectionContainer) {
      Sortable.create(sectionContainer, {
        animation: 150,
        handle: '.qb-section-header',
        onEnd: () => {
          reorderSections();
          markDirty();
        },
      });
    }

    document.querySelectorAll('[data-role="items"]').forEach((container) => {
      Sortable.create(container, {
        animation: 150,
        handle: '.qb-item-main',
        onEnd: () => {
          reorderItems();
          markDirty();
        },
      });
    });
  }

  function reorderSections() {
    const active = state.questionnaires.find((q) => q.clientId === state.activeKey);
    if (!active) return;
    const ordered = Array.from(document.querySelectorAll('[data-role="sections"] > .qb-section')).map((el) =>
      el.getAttribute('data-section')
    );
    active.sections.sort((a, b) => ordered.indexOf(a.clientId) - ordered.indexOf(b.clientId));
  }

  function reorderItems() {
    const active = state.questionnaires.find((q) => q.clientId === state.activeKey);
    if (!active) return;
    document.querySelectorAll('[data-role="items"]').forEach((container) => {
      const sectionId = container.getAttribute('data-section');
      const list = sectionId
        ? active.sections.find((s) => s.clientId === sectionId)?.items
        : active.items;
      if (!list) return;
      const ordered = Array.from(container.querySelectorAll('[data-item]')).map((el) => el.getAttribute('data-item'));
      list.sort((a, b) => ordered.indexOf(a.clientId) - ordered.indexOf(b.clientId));
    });
  }

  function saveAll(publish = false) {
    if (state.saving) return;
    state.saving = true;
    toggleSaveButtons();
    renderMessage(publish ? 'Publishing…' : 'Saving…');

    const payload = {
      csrf: state.csrf,
      questionnaires: state.questionnaires.map((q) => serializeQuestionnaire(q, publish)),
    };

    fetch(withBase(`/admin/questionnaire_manage.php?action=${publish ? 'publish' : 'save'}`), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': state.csrf,
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    })
      .then((resp) => resp.json())
      .then((data) => {
        if (data?.status !== 'ok') throw new Error(data?.message || 'Failed to save');
        state.csrf = data.csrf || state.csrf;
        state.dirty = false;
        renderMessage(data.message || 'Changes saved', 'success');
        fetchData({ silent: true });
      })
      .catch((err) => renderMessage(err.message || 'Save failed', 'error'))
      .finally(() => {
        state.saving = false;
        toggleSaveButtons();
      });
  }

  function serializeQuestionnaire(questionnaire, publish) {
    const base = {
      id: questionnaire.id || undefined,
      clientId: questionnaire.clientId,
      title: questionnaire.title,
      description: questionnaire.description,
      status: publish ? 'published' : questionnaire.status,
      sections: questionnaire.sections.map((section, idx) => serializeSection(section, idx + 1)),
      items: questionnaire.items.map((item, idx) => serializeItem(item, idx + 1)),
    };
    if (Array.isArray(questionnaire.work_functions)) {
      base.work_functions = [...questionnaire.work_functions];
    }
    return base;
  }

  function serializeSection(section, orderIndex) {
    return {
      id: section.id || undefined,
      clientId: section.clientId,
      title: section.title,
      description: section.description,
      order_index: orderIndex,
      is_active: section.is_active,
      items: section.items.map((item, idx) => serializeItem(item, idx + 1)),
    };
  }

  function serializeItem(item, orderIndex) {
    return {
      id: item.id || undefined,
      clientId: item.clientId,
      linkId: item.linkId,
      text: item.text,
      type: item.type,
      order_index: orderIndex,
      weight_percent: Number(item.weight_percent) || 0,
      allow_multiple: item.allow_multiple && item.type === 'choice',
      is_required: item.is_required,
      is_active: item.is_active,
      options: ['choice', 'likert'].includes(item.type)
        ? item.options.map((opt, idx) => ({
          id: opt.id || undefined,
          clientId: opt.clientId,
          value: opt.value,
          is_correct: Boolean(opt.is_correct),
          order_index: idx + 1,
        }))
        : [],
    };
  }

  function handleExport() {
    const active = state.questionnaires.find((q) => q.clientId === state.activeKey);
    if (!active?.id) {
      renderMessage('Save questionnaire before exporting.', 'error');
      return;
    }
    renderMessage('Preparing export…');
    const params = new URLSearchParams({ action: 'export', id: active.id, csrf: state.csrf });
    const url = withBase(`/admin/questionnaire_manage.php?${params.toString()}`);

    fetch(url, { credentials: 'same-origin' })
      .then((resp) => {
        if (!resp.ok) throw new Error('Export failed');
        const disposition = resp.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/i);
        const filename = match?.[1] || `${slugify(labelForQuestionnaire(active))}.xml`;
        return resp.blob().then((blob) => ({ blob, filename }));
      })
      .then(({ blob, filename }) => {
        const blobUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(blobUrl);
        renderMessage('Questionnaire exported successfully.', 'success');
      })
      .catch((err) => {
        renderMessage(err.message || 'Export failed', 'error');
      });
  }

  return { init };
})();

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => Builder.init());
} else {
  Builder.init();
}
