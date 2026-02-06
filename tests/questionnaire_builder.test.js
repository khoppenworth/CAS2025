const fs = require('fs');
const vm = require('vm');

class FakeElement {
  constructor({ id = null, role = null, value = '', content = '' } = {}) {
    this.id = id;
    this.role = role;
    this.value = value;
    this.content = content;
    this.disabled = false;
    this.innerHTMLValue = '';
    this.dataset = {};
    this.listeners = {};
    this.children = [];
  }

  set innerHTML(v) {
    this.innerHTMLValue = String(v);
    if (this.id === 'qb-list') {
      this.doc?.rebuildCardFromHtml(this.innerHTMLValue);
    }
  }

  get innerHTML() {
    return this.innerHTMLValue;
  }

  addEventListener(type, cb) {
    if (!this.listeners[type]) this.listeners[type] = [];
    this.listeners[type].push(cb);
  }

  dispatch(type, extra = {}) {
    const ev = { target: this, preventDefault() {}, ...extra };
    (this.listeners[type] || []).forEach((cb) => cb(ev));
  }

  getAttribute(name) {
    if (name === 'content') return this.content;
    return null;
  }

  querySelector(sel) {
    if (sel === '[data-role="q-title"]') return this.titleInput || null;
    if (sel === '[data-role="q-description"]') return this.descriptionInput || null;
    if (sel === '[data-role="q-status"]') return this.statusInput || null;
    return null;
  }

  querySelectorAll() {
    return [];
  }

  closest(sel) {
    if (sel === '[data-q]' && this.card) return this.card;
    return null;
  }

  scrollIntoView() {}
  focus() {}
  setAttribute() {}
  removeAttribute() {}
  appendChild() {}
  classList = { toggle() {} };
}

class FakeDocument {
  constructor() {
    this.readyState = 'complete';
    this.listeners = {};
    this.byId = new Map();
    this.metaCsrf = new FakeElement({ content: 'csrf-test' });
    this.metaBase = new FakeElement({ content: '' });
    this.card = null;

    ['qb-add-questionnaire','qb-save','qb-save-floating','qb-publish','qb-open-selected','qb-selector','qb-list','qb-tabs','qb-scroll-top','qb-message','qb-section-nav','qb-page-title']
      .forEach((id) => {
        const el = new FakeElement({ id });
        el.doc = this;
        if (id === 'qb-selector') el.value = 'q-1';
        if (id === 'qb-section-nav') {
          el.dataset = { emptyLabel: 'empty', rootLabel: 'root', untitledLabel: 'untitled' };
        }
        this.byId.set(id, el);
      });
  }

  rebuildCardFromHtml(html) {
    const qMatch = html.match(/data-q="([^"]+)"/);
    if (!qMatch) return;
    const qid = qMatch[1];
    const titleMatch = html.match(/data-role="q-title" value="([^"]*)"/);
    const descMatch = html.match(/<textarea data-role="q-description">([\s\S]*?)<\/textarea>/);
    const statusMatch = html.match(/<option value="(draft|published|inactive)"[^>]*selected/);

    const card = new FakeElement();
    card.qid = qid;
    const titleInput = new FakeElement({ role: 'q-title', value: titleMatch ? titleMatch[1] : '' });
    const descriptionInput = new FakeElement({ role: 'q-description', value: descMatch ? descMatch[1] : '' });
    const statusInput = new FakeElement({ role: 'q-status', value: statusMatch ? statusMatch[1] : 'draft' });
    titleInput.card = card;
    descriptionInput.card = card;
    statusInput.card = card;
    card.titleInput = titleInput;
    card.descriptionInput = descriptionInput;
    card.statusInput = statusInput;
    this.card = card;
  }

  addEventListener(type, cb) {
    if (!this.listeners[type]) this.listeners[type] = [];
    this.listeners[type].push(cb);
  }

  querySelector(sel) {
    if (sel === 'meta[name="csrf-token"]') return this.metaCsrf;
    if (sel === 'meta[name="app-base-url"]') return this.metaBase;
    if (sel.startsWith('#')) return this.byId.get(sel.slice(1)) || null;
    if (sel.startsWith('.qb-card[data-q="')) return this.card;
    if (sel.startsWith('[data-q="')) return this.card;
    return null;
  }

  querySelectorAll(sel) {
    if (sel === '#qb-export-questionnaire') return [];
    if (sel === '[data-role="items"], [data-role="root-items"]') return [];
    if (sel === '[data-role="items"]') return [];
    if (sel === '[data-role="sections"] > .qb-section') return [];
    if (sel === 'button[data-nav]') return [];
    if (sel === '.qb-section-nav-item') return [];
    return [];
  }

  createElement() { return new FakeElement(); }
  get body() { return new FakeElement(); }
}

(async () => {
  const document = new FakeDocument();
  const window = {
    document,
    QB_BOOTSTRAP: [{
      id: 1,
      clientId: 'q-1',
      title: 'Old title',
      description: '',
      status: 'draft',
      sections: [],
      items: [],
    }],
    QB_INITIAL_ACTIVE_ID: 1,
    QB_STRINGS: undefined,
    APP_BASE_URL: '',
    Sortable: undefined,
    crypto: { randomUUID: () => 'uuid-1' },
    matchMedia: () => ({ matches: true }),
    scrollY: 0,
    scrollTo() {},
    addEventListener() {},
    requestAnimationFrame(cb) { cb(); },
    sessionStorage: { getItem() { return null; }, setItem() {} },
    URL,
    URLSearchParams,
  };

  let savedPayload = null;
  const fetch = async (url, opts = {}) => {
    const u = String(url);
    if (u.includes('action=fetch')) {
      return {
        json: async () => ({
          status: 'ok',
          csrf: 'csrf-next',
          questionnaires: window.QB_BOOTSTRAP,
        }),
      };
    }
    if (u.includes('action=save')) {
      savedPayload = JSON.parse(opts.body);
      return {
        json: async () => ({ status: 'ok', csrf: 'csrf-final', message: 'saved', idMap: { questionnaires: {} } }),
      };
    }
    throw new Error(`unexpected fetch: ${u}`);
  };

  const context = {
    window,
    document,
    fetch,
    console,
    URL,
    URLSearchParams,
    sessionStorage: window.sessionStorage,
    setTimeout,
    clearTimeout,
  };
  context.globalThis = context;

  const src = fs.readFileSync('assets/js/questionnaire-builder.js', 'utf8');
  vm.runInNewContext(src, context, { filename: 'questionnaire-builder.js' });

  // edit active questionnaire fields
  document.card.titleInput.value = 'New title';
  document.card.titleInput.dispatch('input');
  document.card.statusInput.value = 'inactive';
  document.card.statusInput.dispatch('change');

  // click floating save
  document.byId.get('qb-save-floating').dispatch('click');

  await new Promise((r) => setTimeout(r, 0));

  if (!savedPayload) throw new Error('save payload was not sent');
  const q = savedPayload.questionnaires?.[0];
  if (!q) throw new Error('missing questionnaire in payload');
  if (q.title !== 'New title') throw new Error(`expected title to persist, got ${q.title}`);
  if (q.status !== 'inactive') throw new Error(`expected status to persist, got ${q.status}`);

  const source = fs.readFileSync('assets/js/questionnaire-builder.js', 'utf8');
  if (!source.includes("[data-role=\"items\"], [data-role=\"root-items\"]")) {
    throw new Error('expected root-items sortable selector to exist');
  }

  console.log('questionnaire_builder.test.js: ok');
})();
