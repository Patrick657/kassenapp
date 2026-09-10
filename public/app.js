'use strict';

/* ---------------------------------------------------------------------
 * Constants & tiny helpers
 * ------------------------------------------------------------------- */
const NOTES_CENTS = [500, 1000, 2000, 5000, 10000];
const COINS_CENTS = [5, 10, 20, 50, 100, 200];
const DENOMS_CENTS = [10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5];

function eur(cents) {
  const sign = cents < 0 ? '-' : '';
  const abs = Math.abs(cents);
  return sign + (abs / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}
function short(cents) {
  const abs = Math.abs(cents);
  if (abs >= 100000) return (cents < 0 ? '-' : '') + (abs / 100000).toFixed(1).replace('.', ',') + 'k €';
  return (cents < 0 ? '-' : '') + Math.round(abs / 100) + ' €';
}
function parseAmount(v) {
  const n = parseFloat(String(v).replace(',', '.'));
  return isNaN(n) ? 0 : n;
}
function toCents(v) { return Math.round(parseAmount(v) * 100); }
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function uid() { return crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(36).slice(2)); }
function breakdown(changeCents) {
  let rest = changeCents;
  const parts = [];
  for (const d of DENOMS_CENTS) {
    const c = Math.floor(rest / d);
    if (c > 0) {
      const label = d >= 100 ? (d / 100) + ' €' : d + ' ct';
      parts.push(c + '×' + label);
      rest -= c * d;
    }
  }
  return parts.slice(0, 4).join(' · ');
}
function fmtDateTime(iso) {
  return new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function fmtDateTimeShort(iso) {
  return new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

async function api(path, opts = {}) {
  const res = await fetch('/api' + path, {
    method: opts.method || 'GET',
    credentials: 'same-origin',
    headers: opts.body ? { 'Content-Type': 'application/json' } : undefined,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  let data = null;
  try { data = await res.json(); } catch (e) { /* empty body */ }
  if (!res.ok) {
    const err = new Error((data && data.error) || ('HTTP ' + res.status));
    err.status = res.status;
    throw err;
  }
  return data;
}

/* ---------------------------------------------------------------------
 * State
 * ------------------------------------------------------------------- */
const state = {
  loaded: false,
  shopName: 'Festkasse',
  settings: { trackStock: true, warnLow: true, cardEnabled: true, requireCode: true },
  products: [],
  cashBalanceCents: 0,
  todayRevenueCents: 0,
  access: { unlocked: false, expiresAt: null, codeConfigured: false },

  view: 'pos',
  adminTab: 'overview',
  cat: 'Alle',
  catFilter: 'Alle',
  focusProduct: null,

  cart: [],
  selected: null,
  payment: 'cash',
  tender: 0,
  discount: 0,

  receipt: null,
  form: null,
  formError: '',

  showPin: false,
  pinBuf: '',
  pinError: '',

  toast: '',
  clock: '',

  admin: { kpis: null, daily: null, hourly: null, ranking: null, payments: null, groups: null, products: null, categories: null, journal: null, journalBefore: null, cashStatus: null, zReports: null, maintenanceCounts: null },
};

let toastTimer = null;
function showToast(msg) {
  clearTimeout(toastTimer);
  state.toast = msg;
  renderToast();
  toastTimer = setTimeout(() => { state.toast = ''; renderToast(); }, 2200);
}

/* ---------------------------------------------------------------------
 * Bootstrap + polling
 * ------------------------------------------------------------------- */
async function loadBootstrap() {
  const data = await api('/bootstrap');
  state.shopName = data.shopName;
  state.settings = data.settings;
  state.products = data.products;
  state.cashBalanceCents = data.cashBalanceCents;
  state.todayRevenueCents = data.todayRevenueCents;
  state.access = data.access;
  state.loaded = true;
}

function tick() {
  state.clock = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
  if (state.access.unlocked && state.access.expiresAt && new Date(state.access.expiresAt).getTime() <= Date.now()) {
    lockLocally('Code abgelaufen · Verwaltung gesperrt');
    renderMain();
  }
  renderHeader();
}

function lockLocally(msg) {
  state.access = { unlocked: false, expiresAt: null, codeConfigured: state.access.codeConfigured };
  state.view = 'pos';
  state.adminTab = 'overview';
  state.form = null;
  if (msg) showToast(msg);
}

async function guardedAdminCall(fn) {
  try {
    return await fn();
  } catch (e) {
    if (e.status === 401) {
      lockLocally('Verwaltung gesperrt · Code erforderlich');
      renderHeader();
      renderMain();
      return null;
    }
    throw e;
  }
}

/* ---------------------------------------------------------------------
 * Cart logic
 * ------------------------------------------------------------------- */
function findProduct(id) { return state.products.find((p) => p.id === id); }

function addToCart(product) {
  const track = state.settings.trackStock && product.trackStock;
  if (track && product.stock <= 0) return showToast(product.name + ' ist ausverkauft');
  const line = state.cart.find((l) => l.productId === product.id);
  if (line) {
    if (track && line.qty >= product.stock) return showToast('Nur noch ' + product.stock + '× auf Lager');
    line.qty++;
  } else {
    state.cart.push({ key: uid(), productId: product.id, name: product.name, priceCents: product.priceCents, qty: 1 });
  }
  state.tender = 0;
  renderMain();
}
function changeQty(key, delta) {
  const line = state.cart.find((l) => l.key === key);
  if (!line) return;
  line.qty += delta;
  state.cart = state.cart.filter((l) => l.qty > 0);
  if (!state.cart.find((l) => l.key === key)) state.selected = null;
  state.tender = 0;
  renderMain();
}
function removeLine(key) {
  state.cart = state.cart.filter((l) => l.key !== key);
  state.selected = null;
  state.tender = 0;
  renderMain();
}
function clearCart() {
  state.cart = [];
  state.tender = 0;
  state.discount = 0;
  state.selected = null;
  renderMain();
}
function totals() {
  const sub = state.cart.reduce((s, l) => s + l.priceCents * l.qty, 0);
  const disc = Math.min(state.discount, sub);
  return { sub, disc, total: Math.max(0, sub - disc) };
}

async function checkout() {
  const { cart, payment, tender } = state;
  if (!cart.length) return showToast('Warenkorb ist leer');
  const { total } = totals();
  if (payment === 'cash' && tender < total) return showToast('Betrag noch nicht ausreichend');
  const clientUuid = uid();
  try {
    const receipt = await api('/sales', {
      method: 'POST',
      body: {
        clientUuid,
        items: cart.map((l) => ({ productId: l.productId, name: l.name, unitCents: l.priceCents, qty: l.qty })),
        discountCents: state.discount,
        payment,
        givenCents: payment === 'cash' ? tender : total,
      },
    });
    state.cart = [];
    state.tender = 0;
    state.discount = 0;
    state.selected = null;
    state.receipt = receipt;
    renderOverlay();
    renderMain();
    await loadBootstrap();
    renderHeader();
    renderMain();
    if (state.settings.trackStock && state.settings.warnLow) {
      const lowNames = receipt.items
        .map((i) => findProduct(i.productId))
        .filter((p) => p && p.stock <= p.stockMin)
        .map((p) => p.name);
      if (lowNames.length) setTimeout(() => showToast('Bestand niedrig: ' + lowNames.join(', ')), 300);
    }
  } catch (e) {
    showToast(e.message);
  }
}

/* ---------------------------------------------------------------------
 * Forms
 * ------------------------------------------------------------------- */
function openForm(f) { state.form = f; state.formError = ''; renderOverlay(); }
function closeForm() { state.form = null; state.formError = ''; renderOverlay(); }
function setFormError(msg) { state.formError = msg; renderOverlay(); }

async function submitForm() {
  const f = state.form;
  if (!f) return;
  try {
    if (f.kind === 'price') {
      const v = toCents(f.price);
      if (v <= 0) return setFormError('Preis muss größer als 0 sein');
      const line = state.cart.find((l) => l.key === f.key);
      if (line) line.priceCents = v;
      state.tender = 0;
      closeForm();
      renderMain();
      showToast('Preis geändert');
      return;
    }
    if (f.kind === 'confirm') {
      await guardedAdminCall(() => f.action(f.code));
      closeForm();
      return;
    }
    if (f.kind === 'category') {
      const name = (f.name || '').trim();
      if (!name) return setFormError('Name fehlt');
      if (f.id) {
        await guardedAdminCall(() => api('/categories/' + f.id, { method: 'PATCH', body: { name } }));
        showToast('Gruppe umbenannt');
      } else {
        await guardedAdminCall(() => api('/categories', { method: 'POST', body: { name, trackStockDefault: !!f.trackStockDefault } }));
        showToast('Gruppe angelegt');
      }
      closeForm();
      await loadGroups();
      renderMain();
      return;
    }
    if (f.kind === 'article') {
      const name = (f.name || '').trim();
      if (!name) return setFormError('Name fehlt');
      const price = toCents(f.price);
      if (price <= 0) return setFormError('Verkaufspreis fehlt');
      if (!f.category) return setFormError('Artikelgruppe fehlt');
      const body = {
        name,
        category: f.category,
        priceCents: price,
        costCents: toCents(f.cost || '0'),
      };
      if (state.settings.trackStock) {
        body.trackStock = !!f.trackStock;
        if (f.trackStock) {
          body.stock = Math.round(parseAmount(f.stock || '0'));
          body.stockMin = Math.round(parseAmount(f.stockMin || '0'));
        }
      }
      await guardedAdminCall(() => (f.id ? api('/products/' + f.id, { method: 'PATCH', body }) : api('/products', { method: 'POST', body })));
      closeForm();
      showToast(f.id ? 'Artikel gespeichert' : 'Artikel angelegt');
      await Promise.all([loadBootstrap(), loadAdminProducts()]);
      renderHeader();
      renderMain();
      return;
    }
    if (f.kind === 'delivery') {
      const qty = Math.round(parseAmount(f.qty || '0'));
      if (qty <= 0) return setFormError('Menge muss größer als 0 sein');
      await guardedAdminCall(() => api('/deliveries', { method: 'POST', body: { productId: f.id, qty, note: f.note || '' } }));
      closeForm();
      showToast(qty + '× ' + f.name + ' eingebucht');
      await Promise.all([loadBootstrap(), loadAdminProducts()]);
      renderHeader();
      renderMain();
      return;
    }
    if (f.kind === 'in' || f.kind === 'out') {
      const amount = toCents(f.amount || '0');
      if (amount <= 0) return setFormError('Betrag muss größer als 0 sein');
      await guardedAdminCall(() => api('/cash-movements', { method: 'POST', body: { type: f.kind, amountCents: amount, note: f.note || '' } }));
      closeForm();
      showToast((f.kind === 'in' ? 'Einlage' : 'Entnahme') + ' ' + eur(amount) + ' gebucht');
      await Promise.all([loadBootstrap(), loadCashStatus()]);
      renderHeader();
      renderMain();
      return;
    }
  } catch (e) {
    setFormError(e.message);
  }
}

/* ---------------------------------------------------------------------
 * Admin data loaders
 * ------------------------------------------------------------------- */
async function loadAdminProducts() {
  const data = await guardedAdminCall(() => api('/admin/products'));
  if (data) state.admin.products = data;
  return data;
}
async function loadOverview() {
  const [kpis, daily, hourly, ranking, payments] = await guardedAdminCall(() => Promise.all([
    api('/reports/kpis'), api('/reports/daily'), api('/reports/hourly'), api('/reports/products'), api('/reports/payments'),
  ])) || [null, null, null, null, null];
  Object.assign(state.admin, { kpis, daily, hourly, ranking, payments });
}
async function loadGroups() {
  const [groups, categories] = await guardedAdminCall(() => Promise.all([api('/reports/groups'), api('/categories')])) || [null, null];
  if (groups) state.admin.groups = groups;
  if (categories) state.admin.categories = categories;
}
async function loadCategories() {
  const data = await guardedAdminCall(() => api('/categories'));
  if (data) state.admin.categories = data;
  return data;
}
async function loadJournal(before) {
  const data = await guardedAdminCall(() => api('/journal' + (before ? '?before=' + before + '&limit=80' : '?limit=80')));
  if (data) {
    state.admin.journal = before ? (state.admin.journal || []).concat(data.items) : data.items;
    state.admin.journalBefore = data.nextBefore;
  }
}
async function loadCashStatus() {
  const [status, zReports] = await guardedAdminCall(() => Promise.all([api('/cash/status'), api('/z-reports')])) || [null, null];
  Object.assign(state.admin, { cashStatus: status, zReports });
}
async function loadMaintenanceCounts() {
  const data = await guardedAdminCall(() => api('/maintenance/status'));
  if (data) state.admin.maintenanceCounts = data;
}

async function enterAdminTab(tab) {
  state.adminTab = tab;
  renderMain();
  if (tab === 'overview') await loadOverview();
  else if (tab === 'groups') await loadGroups();
  else if (tab === 'articles' || tab === 'stock') await Promise.all([loadAdminProducts(), loadCategories()]);
  else if (tab === 'journal') await loadJournal(null);
  else if (tab === 'cash') await loadCashStatus();
  else if (tab === 'settings') await loadMaintenanceCounts();
  renderMain();
}

function goPos() { state.view = 'pos'; renderHeader(); renderMain(); }
async function goAdmin() {
  if (state.settings.requireCode && state.access.codeConfigured && !state.access.unlocked) {
    state.showPin = true; state.pinBuf = ''; state.pinError = '';
    renderOverlay();
    return;
  }
  state.view = 'admin';
  renderHeader();
  await enterAdminTab(state.adminTab);
}
async function doLock() {
  try { await api('/access', { method: 'DELETE' }); } catch (e) { /* ignore */ }
  lockLocally('Verwaltung gesperrt');
  renderHeader();
  renderMain();
}

async function pinPress(k) {
  if (k === 'del') { state.pinBuf = state.pinBuf.slice(0, -1); state.pinError = ''; renderOverlay(); return; }
  if (k === 'x') { state.showPin = false; state.pinBuf = ''; state.pinError = ''; renderOverlay(); return; }
  const buf = (state.pinBuf + k).slice(0, 4);
  state.pinBuf = buf;
  if (buf.length === 4) {
    try {
      const res = await api('/access', { method: 'POST', body: { code: buf } });
      state.access = { unlocked: true, expiresAt: res.expiresAt, codeConfigured: true };
      state.showPin = false; state.pinBuf = ''; state.pinError = '';
      state.view = 'admin';
      renderHeader(); renderOverlay();
      showToast('Freigeschaltet für 2 Stunden');
      await enterAdminTab(state.adminTab);
    } catch (e) {
      state.pinBuf = '';
      state.pinError = e.status === 429 ? 'Zu viele Fehlversuche · später erneut' : 'Falscher Code';
      renderOverlay();
    }
    return;
  }
  state.pinError = '';
  renderOverlay();
}

/* ---------------------------------------------------------------------
 * Rendering — header
 * ------------------------------------------------------------------- */
function leftMinutes() {
  if (!state.access.unlocked || !state.access.expiresAt) return 0;
  return Math.max(1, Math.round((new Date(state.access.expiresAt).getTime() - Date.now()) / 60000));
}
function lockLabel() {
  const m = leftMinutes();
  return 'Sperren · ' + (m >= 60 ? Math.floor(m / 60) + ' h ' + (m % 60) + ' min' : m + ' min');
}

function renderHeader() {
  const el = document.getElementById('header-root');
  const showLock = state.settings.requireCode && state.access.unlocked;
  el.innerHTML = `
    <div class="brand"><span class="name">${esc(state.shopName)}</span><span class="tag">POS + WaWi</span></div>
    <div class="switch">
      <button data-action="go-pos" class="${state.view === 'pos' ? 'active' : ''}">Kasse</button>
      <button data-action="go-admin" class="${state.view === 'admin' ? 'active' : ''}">Verwaltung</button>
    </div>
    <div class="kpis">
      <div class="kpi"><div class="label">Kassenbestand</div><div class="value mono">${eur(state.cashBalanceCents)}</div></div>
      <div class="kpi"><div class="label">Umsatz heute</div><div class="value mono success">${eur(state.todayRevenueCents)}</div></div>
      ${showLock ? `<button data-action="do-lock" class="lock-btn">${esc(lockLabel())}</button>` : ''}
      <div class="clock mono">${esc(state.clock)}</div>
    </div>`;
}

/* ---------------------------------------------------------------------
 * Rendering — POS
 * ------------------------------------------------------------------- */
function renderPos() {
  const CATS_ORDER = ['Speisen', 'Getränke', 'Süßes'];
  const cats = ['Alle'].concat(
    CATS_ORDER.filter((c) => state.products.some((p) => p.category === c)),
    Array.from(new Set(state.products.map((p) => p.category).filter((c) => CATS_ORDER.indexOf(c) < 0)))
  );
  const visible = state.products.filter((p) => state.cat === 'Alle' || p.category === state.cat);
  const showStock = state.settings.trackStock;
  const { sub, disc, total } = totals();
  const change = state.tender - total;
  const cartCount = state.cart.reduce((a, l) => a + l.qty, 0);

  const tiles = visible.map((p) => {
    const tracked = showStock && p.trackStock;
    const out = tracked && p.stock <= 0;
    const low = tracked && p.stock <= p.stockMin;
    let badge = '';
    if (tracked) {
      const cls = out ? 'out' : low ? 'low' : '';
      badge = `<span class="tile-badge mono ${cls}">${out ? 'leer' : p.stock}</span>`;
    }
    return `<button data-action="add-to-cart" data-id="${p.id}" class="tile ${out ? 'out' : ''}">
      <div class="tile-top"><span class="tile-name">${esc(p.name)}</span>${badge}</div>
      <div class="tile-bottom"><span class="tile-price mono">${eur(p.priceCents)}</span><span class="tile-cat">${esc(p.category)}</span></div>
    </button>`;
  }).join('');

  const cartLines = state.cart.map((l) => {
    const open = state.selected === l.key;
    return `<div class="cart-line ${open ? 'selected' : ''}" data-action="select-line" data-key="${l.key}">
      <div class="cart-line-row">
        <span class="cart-line-qty mono">${l.qty}×</span>
        <span class="cart-line-name">${esc(l.name)}</span>
        <span class="cart-line-unit mono">${eur(l.priceCents)}</span>
        <span class="cart-line-sum mono">${eur(l.priceCents * l.qty)}</span>
      </div>
      ${open ? `<div class="cart-line-actions">
        <button data-action="line-dec" data-key="${l.key}">−</button>
        <button data-action="line-inc" data-key="${l.key}">+</button>
        <button data-action="line-price" data-key="${l.key}" class="price-btn">Preis ändern</button>
        <button data-action="line-del" data-key="${l.key}" class="del-btn">Storno</button>
      </div>` : ''}
    </div>`;
  }).join('');

  const discountOptions = [0, 50, 100, 200];
  const discountChips = discountOptions.map((v) => `<button data-action="set-discount" data-value="${v}" class="discount-chip ${state.discount === v ? 'active' : ''}">${v === 0 ? 'kein Rabatt' : '−' + eur(v).replace(' €', '€')}</button>`).join('');

  const canPayReady = state.payment === 'card' || (state.tender >= total && total >= 0);
  const checkoutLabel = state.payment === 'card'
    ? 'Kartenzahlung abschließen · ' + eur(total)
    : (state.tender >= total && state.tender > 0 ? 'Kassieren · Rückgeld ' + eur(Math.max(0, change)) : 'Kassieren · ' + eur(total));

  document.getElementById('main-root').innerHTML = `
  <div class="pos">
    <section class="pos-left">
      <div class="cat-row">${cats.map((c) => `<button data-action="set-cat" data-cat="${esc(c)}" class="cat-chip ${state.cat === c ? 'active' : ''}">${esc(c)}</button>`).join('')}</div>
      <div class="tile-scroll"><div class="tile-grid">${tiles}</div></div>
    </section>
    <aside class="cart">
      <div class="cart-head"><span class="title">Warenkorb · ${cartCount}</span><button data-action="clear-cart" class="clear">Leeren</button></div>
      <div class="cart-lines">
        ${state.cart.length === 0 ? `<div class="cart-empty"><span class="icon">🧾</span><span class="hint">Artikel antippen zum Hinzufügen</span></div>` : cartLines}
      </div>
      <div class="cart-sums">
        <div class="row-between subtotal-row"><span>Zwischensumme</span><span class="mono">${eur(sub)}</span></div>
        <div class="row-between discount-row"><div class="discount-chips">${discountChips}</div><span class="discount-value mono">${disc ? '−' + eur(disc) : ''}</span></div>
        <div class="row-between total-row"><span class="label">Zu zahlen</span><span class="value mono">${eur(total)}</span></div>
        ${state.settings.cardEnabled ? `<div class="pay-switch">
          <button data-action="set-payment" data-value="cash" class="${state.payment === 'cash' ? 'active' : ''}">Bar</button>
          <button data-action="set-payment" data-value="card" class="${state.payment === 'card' ? 'active' : ''}">Karte</button>
        </div>` : ''}
        ${state.payment === 'cash' ? `
        <div class="notes-grid">${NOTES_CENTS.map((v) => `<button data-action="add-tender" data-value="${v}">${eur(v).replace(',00', '')}</button>`).join('')}</div>
        <div class="coins-grid">${COINS_CENTS.map((v) => `<button data-action="add-tender" data-value="${v}">${v >= 100 ? (v / 100) + ' €' : v + ' ct'}</button>`).join('')}</div>
        <div class="tender-actions">
          <button data-action="exact-amount">Passend</button>
          <button data-action="clear-tender">Zurücksetzen</button>
        </div>
        <div class="tender-display">
          <div class="given-box"><div class="label">Gegeben</div><div class="value mono">${eur(state.tender)}</div></div>
          <div class="change-box ${change >= 0 && state.tender > 0 ? 'ready' : ''}">
            <div class="label">Rückgeld</div><div class="value mono">${eur(Math.max(0, change))}</div>
            <div class="change-breakdown">${change > 0 ? breakdown(change) : (state.tender > 0 && total > 0 ? (change < 0 ? 'fehlen ' + eur(-change) : 'passend') : '')}</div>
          </div>
        </div>` : ''}
      </div>
      <div class="cart-footer">
        <button data-action="checkout" class="checkout-btn ${state.cart.length && canPayReady ? 'ready' : ''} ${!state.cart.length ? 'empty' : ''}">${esc(checkoutLabel)}</button>
      </div>
    </aside>
  </div>`;
}

/* ---------------------------------------------------------------------
 * Rendering — Admin
 * ------------------------------------------------------------------- */
function adminTabsList() {
  const tabs = [['overview', 'Übersicht'], ['groups', 'Artikelgruppen'], ['articles', 'Artikel'], ['stock', 'Bestand'], ['journal', 'Journal'], ['cash', 'Kasse'], ['settings', 'Einstellungen']];
  return tabs.filter((t) => t[0] !== 'stock' || state.settings.trackStock);
}

function renderOverviewTab() {
  const a = state.admin;
  if (!a.kpis) return '<p>Lädt…</p>';
  const showStock = state.settings.trackStock;
  const kpiCards = [
    { label: 'Umsatz heute', value: eur(a.kpis.todayRevenueCents), sub: a.kpis.todayCount + ' Bons', color: 'var(--text-3)' },
    { label: 'Umsatz gesamt', value: short(a.kpis.totalRevenueCents), sub: a.kpis.totalCount + ' Bons', color: 'var(--text-3)' },
    { label: 'Rohertrag gesamt', value: short(a.kpis.grossProfitCents), sub: 'VK − EK', color: 'var(--accent)' },
    showStock
      ? { label: 'Artikel unter Meldebestand', value: a.kpis.lowStockCount, sub: a.kpis.lowStockCount ? a.kpis.lowStockNames.join(', ') : 'alles im grünen Bereich', color: a.kpis.lowStockCount ? 'var(--warn-text)' : 'var(--accent)' }
      : { label: 'Ø Bon', value: eur(a.kpis.avgTicketCents), sub: a.kpis.topProductName ? 'Top: ' + a.kpis.topProductName : 'noch keine Verkäufe', color: 'var(--text-3)' },
  ];
  const dayMax = Math.max(1, ...a.daily.map((d) => d.totalCents));
  const hourMax = Math.max(1, ...a.hourly.map((h) => h.totalCents));
  const rankMax = Math.max(1, ...a.ranking.map((r) => r.revenueCents));
  const allTotal = a.payments.cash.sumCents + a.payments.card.sumCents;

  return `
    <div class="kpi-grid">${kpiCards.map((k) => `<div class="kpi-card"><div class="label">${esc(k.label)}</div><div class="value mono">${k.value}</div><div class="sub" style="color:${k.color}">${esc(k.sub)}</div></div>`).join('')}</div>
    <div class="chart-grid">
      <div class="chart-card">
        <div class="title">Einnahmen pro Tag</div><div class="subtitle">Letzte 7 Tage</div>
        <div class="bars-daily">${a.daily.map((d, i) => `<div class="bar-daily-col">
          <div class="bar-daily-val mono">${d.totalCents ? short(d.totalCents) : '–'}</div>
          <div class="bar-daily-rect ${i === a.daily.length - 1 ? 'today' : ''}" style="height:${Math.max(3, Math.round(d.totalCents / dayMax * 118))}px"></div>
          <div class="bar-daily-label">${esc(d.weekday)}</div>
        </div>`).join('')}</div>
      </div>
      <div class="chart-card">
        <div class="title">Stundenverteilung</div><div class="subtitle">9–23 Uhr, alle Tage</div>
        <div class="bars-hourly">${a.hourly.map((h) => `<div class="bar-hourly-col">
          <div class="bar-hourly-rect ${h.totalCents === hourMax && hourMax > 0 ? 'max' : ''}" style="height:${Math.max(2, Math.round(h.totalCents / hourMax * 128))}px"></div>
          <div class="bar-hourly-label">${h.hour}</div>
        </div>`).join('')}</div>
      </div>
    </div>
    <div class="chart-grid">
      <div class="chart-card">
        <div class="title">Umsatz pro Artikel</div><div class="subtitle">Top 7 nach Umsatz</div>
        ${a.ranking.map((r) => `<div class="rank-row" data-action="focus-article" data-id="${r.productId}">
          <div class="rank-row-top"><span class="name">${esc(r.name)}</span><span class="stat mono">${r.qty}× · ${eur(r.revenueCents)}</span></div>
          <div class="rank-bar-bg"><div class="rank-bar-fill" style="width:${Math.round(r.revenueCents / rankMax * 100)}%"></div></div>
        </div>`).join('') || '<p class="sub">Noch keine Verkäufe.</p>'}
      </div>
      <div class="chart-card">
        <div class="title">Zahlungsarten</div>
        ${['cash', 'card'].map((k) => {
          const p = a.payments[k];
          const pct = allTotal ? Math.round(p.sumCents / allTotal * 100) : 0;
          return `<div class="pay-row">
            <div class="pay-row-top"><span class="label">${k === 'cash' ? 'Bar' : 'Karte'}</span><span class="sum mono">${eur(p.sumCents)}</span><span class="pct">${pct}%</span></div>
            <div class="pay-bar-bg"><div class="pay-bar-fill" style="width:${pct}%;background:${k === 'cash' ? 'var(--ink)' : 'var(--accent)'}"></div></div>
            <div class="pay-row-sub">${p.count} Bons</div>
          </div>`;
        }).join('')}
        <div class="avg-ticket-row"><span>Ø Bon</span><span class="mono">${eur(a.payments.avgTicketCents)}</span></div>
      </div>
    </div>`;
}

function renderGroupsTab() {
  const groups = state.admin.groups;
  const cats = state.admin.categories;
  if (!groups || !cats) return '<p>Lädt…</p>';
  const manage = `
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px">
        <div><div class="title">Gruppen verwalten</div><div class="subtitle">${cats.length} Artikelgruppe(n)</div></div>
        <button data-action="new-category" class="btn-primary">+ Neue Gruppe</button>
      </div>
      ${cats.map((c) => `<div class="settings-row" data-action="toggle-category-default" data-id="${c.id}">
        <div><div class="title">${esc(c.name)}</div><div class="hint">Lagerbestand für neue Artikel dieser Gruppe</div></div>
        <div style="display:flex;align-items:center;gap:14px">
          <div class="switch-track ${c.trackStockDefault ? 'on' : ''}"><div class="switch-knob"></div></div>
          <button data-action="rename-category" data-id="${c.id}">Umbenennen</button>
          <button data-action="delete-category" data-id="${c.id}" data-name="${esc(c.name)}" class="del">✕</button>
        </div>
      </div>`).join('') || '<div style="padding:15px 18px;color:var(--text-3);font-size:13px">Noch keine Artikelgruppe angelegt.</div>'}
    </div>`;
  return manage + groups.map((g) => `
    <div class="group-card">
      <div class="group-head" data-action="show-group" data-name="${esc(g.name)}"><span class="name">${esc(g.name)}</span><span class="revenue mono">${eur(g.revenueCents)}</span></div>
      <div class="group-sub">${g.sharePct}% vom Gesamtumsatz · ${g.qty}× verkauft · ${g.count} Artikel</div>
      <div class="group-bar-bg"><div class="group-bar-fill" style="width:${Math.max(2, g.sharePct)}%;background:var(--accent)"></div></div>
      <div class="group-stats">
        <div class="group-stat"><div class="label">Rohertrag</div><div class="value" style="color:var(--accent)">${eur(g.profitCents)}</div></div>
        <div class="group-stat"><div class="label">Ø Artikelumsatz</div><div class="value">${eur(g.avgRevenueCents)}</div></div>
        ${g.stockUnits !== null ? `
        <div class="group-stat"><div class="label">Bestand</div><div class="value">${g.stockUnits} Stk</div></div>
        <div class="group-stat"><div class="label">Lagerwert (EK)</div><div class="value" style="color:${g.lowCount ? 'var(--warn-text)' : 'var(--ink)'}">${eur(g.stockValueCents)}</div></div>` : ''}
      </div>
      <div class="group-items">
        ${g.items.slice(0, 8).map((it) => `<div class="group-item-row" data-action="focus-article" data-id="${it.productId}"><span>${esc(it.name)}</span><span class="mono">${it.qty}× · ${eur(it.revenueCents)}</span></div>`).join('')}
      </div>
      <div class="group-show-all" data-action="show-group" data-name="${esc(g.name)}">Alle Artikel dieser Gruppe</div>
    </div>`).join('');
}

function renderArticlesTab() {
  const rows = state.admin.products;
  if (!rows) return '<p>Lädt…</p>';
  const filtered = rows.filter((p) => state.catFilter === 'Alle' || p.category === state.catFilter);
  const cats = ['Alle'].concat(Array.from(new Set(rows.map((p) => p.category))));
  const showStock = state.settings.trackStock;
  const rowsHtml = filtered.map((p) => {
    const margin = p.priceCents ? Math.round((p.priceCents - p.costCents) / p.priceCents * 100) + '%' : '–';
    const tracked = showStock && p.trackStock;
    const stockColor = !tracked ? 'var(--text-4)' : p.stock <= 0 ? 'var(--err-text)' : p.stock <= p.stockMin ? 'var(--warn-text)' : 'var(--ink)';
    return `<div class="article-row ${state.focusProduct === p.id ? 'focused' : ''}" id="artikel-${p.id}">
      <span>${esc(p.name)}</span>
      <span class="link" data-action="filter-category" data-cat="${esc(p.category)}">${esc(p.category)}</span>
      <span class="num mono">${eur(p.priceCents)}</span>
      <span class="num mono">${eur(p.costCents)}</span>
      <span class="num mono margin-val">${margin}</span>
      <span class="num mono" style="color:${stockColor}">${tracked ? p.stock : '–'}</span>
      <span class="num mono">${p.soldQty ? p.soldQty + '×' : '–'}</span>
      <div class="row-actions">
        <button data-action="edit-article" data-id="${p.id}">Bearbeiten</button>
        <button data-action="remove-article" data-id="${p.id}" class="del">✕</button>
      </div>
    </div>`;
  }).join('');
  return `
    <div class="group-chips">${cats.map((c) => `<button data-action="set-catfilter" data-cat="${esc(c)}" class="group-chip ${state.catFilter === c ? 'active' : ''}">${c === 'Alle' ? 'Alle Gruppen' : esc(c)}</button>`).join('')}</div>
    <div class="table-card">
      <div class="table-head-row">
        <div><div class="title">Artikel</div><div class="subtitle">${filtered.length} Artikel · Marge über Einkaufspreis</div></div>
        <button data-action="new-article" class="btn-primary">+ Neuer Artikel</button>
      </div>
      <div class="table-scroll">
        <div class="article-cols"><span>Artikel</span><span>Kategorie</span><span class="num">VK</span><span class="num">EK</span><span class="num">Marge</span><span class="num">Bestand</span><span class="num">Verkauft</span><span>Aktionen</span></div>
        ${rowsHtml}
      </div>
    </div>`;
}

function renderStockTab() {
  const all = state.admin.products;
  if (!all) return '<p>Lädt…</p>';
  const rows = all.filter((p) => p.trackStock);
  const low = rows.filter((p) => p.stock <= p.stockMin);
  const stockValue = rows.reduce((a, p) => a + p.stock * p.costCents, 0);
  const stockRows = rows.map((p) => {
    const ratioBase = p.stockMin ? p.stockMin * 3 : 1;
    const w = Math.max(3, Math.min(100, Math.round((p.stockMin ? p.stock / ratioBase : 1) * 100)));
    const stateLabel = p.stock <= 0 ? 'ausverkauft' : p.stock <= p.stockMin ? 'nachbestellen' : 'ausreichend';
    const color = p.stock <= 0 ? 'var(--err-text)' : p.stock <= p.stockMin ? 'var(--warn-text)' : 'var(--accent)';
    return `<div class="stock-row">
      <div class="stock-name">${esc(p.name)}<span class="min">Meldebestand ${p.stockMin}</span></div>
      <div class="stock-val mono" style="color:${color}">${p.stock}</div>
      <div style="color:${color};font-size:13px;font-weight:600">${stateLabel}</div>
      <div class="stock-bar-bg"><div class="stock-bar-fill" style="width:${w}%;background:${color}"></div></div>
      <div class="stock-actions"><button data-action="book-delivery" data-id="${p.id}" class="btn-primary">Zugang buchen</button></div>
    </div>`;
  }).join('');
  return `
    ${low.length ? `<div class="banner-warn"><div class="title">Niedriger Bestand</div><div class="list">${low.map((p) => esc(p.name) + ' (' + p.stock + ' / ' + p.stockMin + ')').join(' · ')}</div></div>` : ''}
    <div class="card-box stock-card">
      <div class="table-head-row" style="padding:0 0 12px"><div><div class="title">Bestand &amp; Warenzugang</div><div class="subtitle">Lagerwert (EK): ${eur(stockValue)}${all.length > rows.length ? ' · ' + (all.length - rows.length) + ' Artikel ohne Lagerverwaltung ausgeblendet' : ''}</div></div></div>
      ${stockRows || '<div style="padding:16px 18px;color:var(--text-3);font-size:13px">Kein Artikel mit aktiver Lagerverwaltung.</div>'}
    </div>`;
}

function renderJournalTab() {
  const items = state.admin.journal;
  if (!items) return '<p>Lädt…</p>';
  const tagStyles = { sale: 'background:var(--sale-fill);color:var(--sale-text)', in: 'background:var(--info-fill);color:var(--info-text)', out: 'background:var(--warn-fill);color:var(--warn-text)', close: 'background:var(--ink);color:#fff', delivery: 'background:var(--subtle);color:var(--text-3)' };
  const tagText = { sale: 'Verkauf', in: 'Einlage', out: 'Entnahme', close: 'Z-Abschluss', delivery: 'Warenzugang' };
  const rows = items.map((m) => {
    const clickable = m.type === 'sale' && m.receiptNo;
    const amount = m.type === 'delivery' ? '–' : (m.amountCents > 0 ? '+' : '') + eur(m.amountCents);
    const color = m.type === 'delivery' ? 'var(--text-4)' : m.amountCents < 0 ? 'var(--warn-text)' : 'var(--ink)';
    return `<div class="journal-row ${clickable ? 'clickable' : ''}" ${clickable ? `data-action="open-receipt" data-no="${esc(m.receiptNo)}"` : ''}>
      <span class="mono">${fmtDateTimeShort(m.occurredAt)}</span>
      <span class="journal-tag" style="${tagStyles[m.type] || ''}">${tagText[m.type] || m.type}</span>
      <span>${esc(m.note)}</span>
      <span class="journal-amount mono" style="color:${color}">${amount}</span>
    </div>`;
  }).join('');
  return `<div class="table-card journal-card">
    <div class="table-head-row"><div><div class="title">Kassenbewegungen</div><div class="subtitle">Bon antippen für Belegansicht</div></div></div>
    ${rows}
    ${state.admin.journalBefore ? `<div style="padding:14px;text-align:center"><button data-action="load-more-journal" class="btn-neutral" style="padding:9px 16px;border-radius:9px;font-weight:700">Weitere laden</button></div>` : ''}
  </div>`;
}

function renderCashTab() {
  const status = state.admin.cashStatus;
  const zReports = state.admin.zReports;
  if (!status || !zReports) return '<p>Lädt…</p>';
  return `<div class="cash-grid">
    <div class="card-box">
      <div class="cash-balance-label">Kassenbestand jetzt</div>
      <div class="cash-balance-value mono">${eur(status.balanceCents)}</div>
      <div class="cash-since">Barumsatz seit Abschluss: ${eur(status.cashSinceCloseCents)}</div>
      <div class="cash-actions">
        <div class="cash-actions-row">
          <button data-action="open-cash-in" class="btn-deposit">Einlage</button>
          <button data-action="open-cash-out" class="btn-withdraw">Entnahme</button>
        </div>
        <button data-action="close-day" class="cash-close-btn">Tagesabschluss (Z-Bon)</button>
      </div>
    </div>
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px"><div class="title">Abschlüsse</div></div>
      ${zReports.length === 0 ? '<div class="z-empty">Noch kein Tagesabschluss gebucht.</div>' : zReports.map((z) => `
        <div class="z-list-item">
          <div class="z-list-top"><span>Z-${z.no} · ${new Date(z.closedAt).toLocaleDateString('de-DE')}</span><span class="mono">${eur(z.totalCents)}</span></div>
          <div class="z-list-detail">${z.salesCount} Bons · Bar ${eur(z.cashCents)} · Karte ${eur(z.cardCents)}</div>
        </div>`).join('')}
    </div>
  </div>`;
}

function renderSettingsTab() {
  const cfg = state.settings;
  const counts = state.admin.maintenanceCounts;
  const toggles = [
    ['trackStock', 'Lagerbestand führen', 'Bestände mitzählen, Ausverkauft sperren, Warenzugang buchen'],
    ['warnLow', 'Warnung bei niedrigem Bestand', 'Hinweis beim Kassieren und in der Übersicht'],
    ['cardEnabled', 'Kartenzahlung anbieten', 'Bar/Karte-Umschalter in der Kasse'],
    ['requireCode', 'Änderungen mit Code schützen', 'Freigabe gilt 2 Stunden, danach automatisch gesperrt'],
  ].filter((t) => t[0] !== 'warnLow' || cfg.trackStock);
  return `<div class="settings-card">
    <div class="card-box" style="padding:0">
      <div class="settings-head">Einstellungen · gelten sofort für Kasse und Verwaltung</div>
      <div class="settings-row" style="cursor:default">
        <div><div class="title">Kassenname</div><div class="hint">Erscheint in Kopfzeile und auf dem Bon.</div></div>
        <input id="shop-name-input" class="settings-text-field" type="text" value="${esc(cfg.shopName)}">
      </div>
      ${toggles.map((t) => `<div class="settings-row" data-action="toggle-setting" data-key="${t[0]}">
        <div><div class="title">${esc(t[1])}</div><div class="hint">${esc(t[2])}</div></div>
        <div class="switch-track ${cfg[t[0]] ? 'on' : ''}"><div class="switch-knob"></div></div>
      </div>`).join('')}
    </div>
    <div class="card-box" style="margin-top:12px">
      <div class="form-title" style="margin-bottom:2px">Zugangscode &amp; Löschkennwort</div>
      <div class="form-hint">${state.access.codeConfigured
        ? 'Leer lassen, um einen Code unverändert zu lassen. Beide Codes müssen sich unterscheiden.'
        : 'Noch kein Zugangscode gesetzt — Verwaltung ist deshalb aktuell offen. Lege jetzt beide Codes fest.'}</div>
      <div class="form-field"><label>Neuer Zugangscode</label><input id="access-code-input" type="password" placeholder="z. B. 1234"></div>
      <div class="form-field"><label>Neues Löschkennwort</label><input id="delete-code-input" type="password" placeholder="z. B. 9999"></div>
      <div class="form-error" id="codes-error"></div>
      <div class="form-actions"><button data-action="save-codes" class="primary" style="flex:1">Codes speichern</button></div>
    </div>
    <div class="card-box" style="padding:0;margin-top:12px">
      <div class="data-summary">${counts ? `${counts.sales} Bons · ${counts.movements} Bewegungen · ${counts.products} Artikel gespeichert` : 'Lädt…'}</div>
      <div class="data-actions">
        <button data-action="clear-sales" class="btn-neutral">Verkäufe &amp; Journal löschen</button>
        <button data-action="reset-all" class="btn-danger">Alles zurücksetzen (Demo neu)</button>
      </div>
    </div>
  </div>`;
}

function renderAdmin() {
  const tabs = adminTabsList();
  const tabLabels = { overview: 'Übersicht', groups: 'Artikelgruppen', articles: 'Artikel', stock: 'Bestand', journal: 'Journal', cash: 'Kasse', settings: 'Einstellungen' };
  let content = '';
  if (state.adminTab === 'overview') content = renderOverviewTab();
  else if (state.adminTab === 'groups') content = renderGroupsTab();
  else if (state.adminTab === 'articles') content = renderArticlesTab();
  else if (state.adminTab === 'stock') content = renderStockTab();
  else if (state.adminTab === 'journal') content = renderJournalTab();
  else if (state.adminTab === 'cash') content = renderCashTab();
  else if (state.adminTab === 'settings') content = renderSettingsTab();

  document.getElementById('main-root').innerHTML = `
  <div class="admin">
    <div class="admin-tabs">${tabs.map(([id]) => `<button data-action="set-admintab" data-tab="${id}" class="${state.adminTab === id ? 'active' : ''}">${esc(tabLabels[id])}</button>`).join('')}</div>
    <div class="admin-content">${content}</div>
  </div>`;

  if (state.adminTab === 'settings') {
    const input = document.getElementById('shop-name-input');
    input.addEventListener('change', async () => {
      const name = input.value.trim() || 'Festkasse';
      try {
        await guardedAdminCall(() => api('/settings', { method: 'PATCH', body: { shopName: name } }));
        state.settings.shopName = name;
        state.shopName = name;
        renderHeader();
      } catch (e) { showToast(e.message); }
    });
  }
  if (state.focusProduct && state.adminTab === 'articles') {
    const row = document.getElementById('artikel-' + state.focusProduct);
    if (row) row.scrollIntoView({ block: 'center' });
  }
}

function renderMain() {
  if (state.view === 'pos') renderPos();
  else renderAdmin();
}

/* ---------------------------------------------------------------------
 * Rendering — overlay (receipt / forms / pin)
 * ------------------------------------------------------------------- */
function renderReceiptOverlay() {
  const rc = state.receipt;
  const rows = [{ label: 'Summe', value: eur(rc.subtotalCents), weight: 500, size: '13px' }];
  if (rc.discountCents) rows.push({ label: 'Rabatt', value: '−' + eur(rc.discountCents), weight: 500, size: '13px' });
  rows.push({ label: 'Zu zahlen', value: eur(rc.totalCents), weight: 800, size: '17px' });
  rows.push({ label: rc.payment === 'cash' ? 'Bar gegeben' : 'Kartenzahlung', value: eur(rc.givenCents), weight: 500, size: '13px' });
  if (rc.payment === 'cash') rows.push({ label: 'Rückgeld', value: eur(rc.changeCents), weight: 700, size: '14px' });
  return `<div class="overlay" id="receipt-overlay">
    <div class="receipt-card">
      <div class="receipt-shop">${esc(state.shopName)}</div>
      <div class="receipt-meta">${fmtDateTime(rc.soldAt)} · Bon ${esc(rc.receiptNo)}</div>
      <hr class="receipt-divider">
      ${rc.items.map((i) => `<div class="receipt-line"><span class="qty mono">${i.qty}×</span><span class="name">${esc(i.name)}</span><span class="mono">${eur(i.unitCents * i.qty)}</span></div>`).join('')}
      <hr class="receipt-divider">
      ${rows.map((r) => `<div class="receipt-totals-row" style="font-weight:${r.weight};font-size:${r.size}"><span>${esc(r.label)}</span><span class="mono">${r.value}</span></div>`).join('')}
      <div class="receipt-footer"><div class="thanks">Vielen Dank für Ihren Einkauf</div><button data-action="close-receipt">Weiter</button></div>
    </div>
  </div>`;
}

function renderFormOverlay() {
  const f = state.form;
  const F = (label, key, placeholder, type = 'text') => `<div class="form-field"><label>${esc(label)}</label><input data-form-key="${key}" type="${type}" value="${esc(f[key] ?? '')}" placeholder="${esc(placeholder)}"></div>`;
  let title = '', hint = '', fields = '', submitLabel = 'Speichern', dangerSubmit = false;
  if (f.kind === 'confirm') {
    title = f.title;
    hint = f.hint + (f.noCode ? '' : ' Zum Bestätigen das Löschkennwort eingeben.');
    fields = f.noCode ? '' : F('Löschkennwort', 'code', '••••', 'password');
    submitLabel = f.submitLabel || 'Endgültig löschen';
    dangerSubmit = true;
  } else if (f.kind === 'price') {
    title = 'Preis · ' + f.name;
    fields = F('Neuer Einzelpreis (€)', 'price', '3,50');
    submitLabel = 'Übernehmen';
  } else if (f.kind === 'article') {
    title = f.id ? 'Artikel bearbeiten' : 'Neuer Artikel';
    const cats = state.admin.categories || [];
    const catOptions = cats.length
      ? cats.map((c) => `<option value="${esc(c.name)}" ${f.category === c.name ? 'selected' : ''}>${esc(c.name)}</option>`).join('')
      : '<option value="">Keine Artikelgruppe angelegt</option>';
    fields = F('Bezeichnung', 'name', 'z.B. Bratwurst');
    fields += `<div class="form-field"><label>Artikelgruppe</label><select data-form-key="category" ${cats.length ? '' : 'disabled'}>${catOptions}</select></div>`;
    fields += F('Verkaufspreis (€)', 'price', '3,50') + F('Einkaufspreis (€)', 'cost', '1,20');
    if (state.settings.trackStock) {
      fields += `<div class="settings-row" data-action="toggle-form-trackstock" style="padding:12px 0;cursor:pointer">
        <div><div class="title" style="font-size:13px;font-weight:600">Lagerbestand für diesen Artikel führen</div><div class="hint" style="font-size:11px;color:var(--text-3)">Aus, wenn dieser Artikel nicht gezählt werden soll (z. B. Fassbier)</div></div>
        <div class="switch-track ${f.trackStock ? 'on' : ''}"><div class="switch-knob"></div></div>
      </div>`;
      if (f.trackStock) fields += F('Bestand', 'stock', '100') + F('Meldebestand', 'stockMin', '20');
    }
    if (!cats.length) hint = 'Erst unter "Artikelgruppen" mindestens eine Gruppe anlegen, bevor du einen Artikel speichern kannst.';
  } else if (f.kind === 'category') {
    title = f.id ? 'Gruppe umbenennen' : 'Neue Artikelgruppe';
    fields = F('Name', 'name', 'z. B. Merchandise');
    if (!f.id) {
      fields += `<div class="settings-row" data-action="toggle-form-trackstockdefault" style="padding:12px 0;cursor:pointer">
        <div><div class="title" style="font-size:13px;font-weight:600">Lagerbestand für neue Artikel dieser Gruppe</div><div class="hint" style="font-size:11px;color:var(--text-3)">Vorbelegung, pro Artikel jederzeit änderbar</div></div>
        <div class="switch-track ${f.trackStockDefault ? 'on' : ''}"><div class="switch-knob"></div></div>
      </div>`;
    }
    submitLabel = f.id ? 'Speichern' : 'Anlegen';
  } else if (f.kind === 'delivery') {
    title = 'Warenzugang · ' + f.name;
    fields = F('Menge', 'qty', '50') + F('Notiz / Lieferant', 'note', 'z.B. Metro');
    submitLabel = 'Einbuchen';
  } else if (f.kind === 'in' || f.kind === 'out') {
    title = f.kind === 'in' ? 'Einlage buchen' : 'Entnahme buchen';
    fields = F('Betrag (€)', 'amount', '50,00') + F('Notiz', 'note', f.kind === 'in' ? 'Wechselgeld' : 'Abschöpfung');
    submitLabel = 'Buchen';
  }
  return `<div class="overlay">
    <div class="form-card">
      <div class="form-title">${esc(title)}</div>
      ${hint ? `<div class="form-hint">${esc(hint)}</div>` : ''}
      ${fields}
      <div class="form-error">${esc(state.formError)}</div>
      <div class="form-actions">
        <button data-action="close-form" class="cancel">Abbrechen</button>
        <button data-action="submit-form" class="primary ${dangerSubmit ? 'danger' : ''}">${esc(submitLabel)}</button>
      </div>
    </div>
  </div>`;
}

function renderPinOverlay() {
  const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'x', '0', 'del'];
  return `<div class="overlay">
    <div class="pin-card">
      <div class="pin-title">Verwaltung entsperren</div>
      <div class="pin-sub">Code eingeben · gilt 2 Stunden</div>
      <div class="pin-dots">${[0, 1, 2, 3].map((i) => `<div class="pin-dot ${i < state.pinBuf.length ? 'filled' : ''}"></div>`).join('')}</div>
      <div class="pin-error">${esc(state.pinError)}</div>
      <div class="pin-keys">${keys.map((k) => `<button data-action="pin-press" data-key="${k}" class="${k === 'x' || k === 'del' ? 'ghost' : ''}">${k === 'del' ? '⌫' : k === 'x' ? 'Abbr.' : k}</button>`).join('')}</div>
    </div>
  </div>`;
}

function renderOverlay() {
  const el = document.getElementById('overlay-root');
  if (state.receipt) el.innerHTML = renderReceiptOverlay();
  else if (state.form) el.innerHTML = renderFormOverlay();
  else if (state.showPin) el.innerHTML = renderPinOverlay();
  else el.innerHTML = '';

  if (state.form) {
    el.querySelectorAll('[data-form-key]').forEach((input) => {
      const evt = input.tagName === 'SELECT' ? 'change' : 'input';
      input.addEventListener(evt, () => {
        state.form[input.dataset.formKey] = input.value;
        // New article: prefill the per-article stock toggle from the chosen group's default.
        if (input.dataset.formKey === 'category' && state.form.kind === 'article' && !state.form.id) {
          const cat = (state.admin.categories || []).find((c) => c.name === input.value);
          if (cat) { state.form.trackStock = cat.trackStockDefault; renderOverlay(); }
        }
      });
    });
  }
  if (state.receipt) {
    const backdrop = document.getElementById('receipt-overlay');
    if (backdrop) backdrop.addEventListener('click', (e) => { if (e.target === backdrop) { state.receipt = null; renderOverlay(); } });
  }
}

function renderToast() {
  const el = document.getElementById('toast-root');
  el.innerHTML = state.toast ? `<div class="toast">${esc(state.toast)}</div>` : '';
}

/* ---------------------------------------------------------------------
 * Event delegation
 * ------------------------------------------------------------------- */
function onAction(e) {
  const el = e.target.closest('[data-action]');
  if (!el) return;
  const action = el.dataset.action;
  const d = el.dataset;

  switch (action) {
    case 'go-pos': return goPos();
    case 'go-admin': return void goAdmin();
    case 'do-lock': return void doLock();

    case 'add-to-cart': return addToCart(findProduct(Number(d.id)));
    case 'set-cat': state.cat = d.cat; return renderMain();
    case 'select-line': state.selected = state.selected === d.key ? null : d.key; return renderMain();
    case 'line-inc': return changeQty(d.key, 1);
    case 'line-dec': return changeQty(d.key, -1);
    case 'line-del': return removeLine(d.key);
    case 'line-price': {
      const line = state.cart.find((l) => l.key === d.key);
      openForm({ kind: 'price', key: d.key, name: line.name, price: String(line.priceCents / 100).replace('.', ',') });
      return;
    }
    case 'clear-cart': return clearCart();
    case 'set-discount': state.discount = Number(d.value); state.tender = 0; return renderMain();
    case 'set-payment': state.payment = d.value; if (d.value === 'card') state.tender = 0; return renderMain();
    case 'add-tender': state.tender += Number(d.value); return renderMain();
    case 'exact-amount': state.tender = totals().total; return renderMain();
    case 'clear-tender': state.tender = 0; return renderMain();
    case 'checkout': return void checkout();

    case 'close-receipt': state.receipt = null; return renderOverlay();
    case 'close-form': return closeForm();
    case 'submit-form': return void submitForm();
    case 'pin-press': return void pinPress(d.key);

    case 'set-admintab': return void enterAdminTab(d.tab);
    case 'set-catfilter': state.catFilter = d.cat; state.focusProduct = null; return renderMain();
    case 'filter-category': state.catFilter = d.cat; state.focusProduct = null; state.adminTab = 'articles'; return renderMain();
    case 'focus-article': {
      const id = Number(d.id);
      if (!findProduct(id) && !(state.admin.products || []).find((p) => p.id === id)) { showToast('Artikel wurde gelöscht'); return; }
      const p = (state.admin.products || []).find((x) => x.id === id) || findProduct(id);
      state.adminTab = 'articles';
      state.catFilter = p ? p.category : 'Alle';
      state.focusProduct = id;
      return void enterAdminTab('articles').then(() => { state.focusProduct = id; renderMain(); });
    }
    case 'show-group': state.adminTab = 'articles'; state.catFilter = d.name; state.focusProduct = null; return void enterAdminTab('articles');
    case 'new-category': return openForm({ kind: 'category', name: '', trackStockDefault: true });
    case 'rename-category': {
      const c = (state.admin.categories || []).find((x) => x.id === Number(d.id));
      return openForm({ kind: 'category', id: c.id, name: c.name });
    }
    case 'toggle-category-default': {
      const c = (state.admin.categories || []).find((x) => x.id === Number(d.id));
      return void guardedAdminCall(() => api('/categories/' + c.id, { method: 'PATCH', body: { trackStockDefault: !c.trackStockDefault } }))
        .then(() => loadGroups())
        .then(renderMain)
        .catch((e) => showToast(e.message));
    }
    case 'delete-category': return openForm({
      kind: 'confirm', noCode: true, title: 'Gruppe löschen · ' + d.name,
      hint: 'Nur möglich, solange kein Artikel mehr dieser Gruppe zugeordnet ist.',
      submitLabel: 'Gruppe löschen',
      action: async () => {
        await api('/categories/' + d.id, { method: 'DELETE' });
        showToast('Gruppe gelöscht');
        await loadGroups();
        renderMain();
      },
    });
    case 'toggle-form-trackstockdefault': state.form.trackStockDefault = !state.form.trackStockDefault; return renderOverlay();
    case 'new-article': {
      const cats = state.admin.categories || [];
      const defaultCat = cats[0];
      const f = { kind: 'article', name: '', category: defaultCat ? defaultCat.name : '', price: '', cost: '' };
      if (state.settings.trackStock) { f.trackStock = defaultCat ? defaultCat.trackStockDefault : true; f.stock = '0'; f.stockMin = '10'; }
      return openForm(f);
    }
    case 'edit-article': {
      const p = state.admin.products.find((x) => x.id === Number(d.id));
      const f = { kind: 'article', id: p.id, name: p.name, category: p.category, price: String(p.priceCents / 100).replace('.', ','), cost: String(p.costCents / 100).replace('.', ',') };
      if (state.settings.trackStock) { f.trackStock = p.trackStock; f.stock = String(p.stock); f.stockMin = String(p.stockMin); }
      return openForm(f);
    }
    case 'toggle-form-trackstock': state.form.trackStock = !state.form.trackStock; return renderOverlay();
    case 'remove-article': {
      const p = state.admin.products.find((x) => x.id === Number(d.id));
      return openForm({
        kind: 'confirm', code: '', title: 'Artikel löschen · ' + p.name,
        hint: 'Der Artikel verschwindet aus der Kasse; bereits gebuchte Bons bleiben unverändert.',
        submitLabel: 'Artikel löschen',
        action: async (code) => {
          await api('/products/' + p.id, { method: 'DELETE', body: { deleteCode: code } });
          showToast(p.name + ' gelöscht');
          await Promise.all([loadBootstrap(), loadAdminProducts()]);
          renderHeader(); renderMain();
        },
      });
    }
    case 'book-delivery': {
      const p = state.admin.products.find((x) => x.id === Number(d.id));
      return openForm({ kind: 'delivery', id: p.id, name: p.name, qty: '', note: '' });
    }
    case 'open-receipt': return void api('/receipts/' + d.no).then((rc) => { state.receipt = rc; renderOverlay(); }).catch((e) => showToast(e.message));
    case 'load-more-journal': return void loadJournal(state.admin.journalBefore).then(renderMain);
    case 'open-cash-in': return openForm({ kind: 'in', amount: '', note: '' });
    case 'open-cash-out': return openForm({ kind: 'out', amount: '', note: '' });
    case 'close-day': return void guardedAdminCall(() => api('/z-reports', { method: 'POST' }))
      .then((res) => { if (!res) return; showToast('Z-' + res.no + ' gebucht · ' + eur(res.totalCents) + ' Umsatz'); return Promise.all([loadBootstrap(), loadCashStatus()]); })
      .catch((e) => showToast(e.message))
      .then(() => { renderHeader(); renderMain(); });
    case 'toggle-setting': {
      const key = d.key;
      const next = !state.settings[key];
      return void guardedAdminCall(() => api('/settings', { method: 'PATCH', body: { [key]: next } }))
        .then((res) => { if (!res) return; state.settings = res; if (key === 'cardEnabled' && !next) state.payment = 'cash'; renderHeader(); renderMain(); })
        .catch((e) => showToast(e.message));
    }
    case 'save-codes': {
      const accessInput = document.getElementById('access-code-input');
      const deleteInput = document.getElementById('delete-code-input');
      const errEl = document.getElementById('codes-error');
      const accessCode = accessInput.value.trim();
      const deleteCode = deleteInput.value.trim();
      if (!accessCode && !deleteCode) { errEl.textContent = 'Bitte mindestens einen Code eingeben.'; return; }
      if (accessCode && deleteCode && accessCode === deleteCode) { errEl.textContent = 'Zugangscode und Löschkennwort müssen unterschiedlich sein.'; return; }
      const body = {};
      if (accessCode) body.accessCode = accessCode;
      if (deleteCode) body.deleteCode = deleteCode;
      return void api('/settings/codes', { method: 'PATCH', body })
        .then(async () => {
          accessInput.value = ''; deleteInput.value = ''; errEl.textContent = '';
          state.access.codeConfigured = true;
          // Log in with the code just set so setting it doesn't immediately lock the admin out again.
          if (accessCode && !state.access.unlocked) {
            try {
              const res = await api('/access', { method: 'POST', body: { code: accessCode } });
              state.access = { unlocked: true, expiresAt: res.expiresAt, codeConfigured: true };
            } catch (e) { /* fall through to plain success toast */ }
          }
          showToast('Code(s) gespeichert');
          renderHeader();
          renderMain();
        })
        .catch((e) => { errEl.textContent = e.message; });
    }
    case 'clear-sales': return openForm({
      kind: 'confirm', code: '', title: 'Verkäufe & Journal löschen',
      hint: (state.admin.maintenanceCounts ? state.admin.maintenanceCounts.sales : 0) + ' Bons und Kassenbewegungen werden unwiderruflich entfernt. Artikel und Einstellungen bleiben.',
      submitLabel: 'Endgültig löschen',
      action: async (code) => {
        await api('/maintenance/purge', { method: 'POST', body: { deleteCode: code } });
        showToast('Verkäufe und Journal gelöscht');
        await Promise.all([loadBootstrap(), loadMaintenanceCounts()]);
        renderHeader(); renderMain();
      },
    });
    case 'reset-all': return openForm({
      kind: 'confirm', code: '', title: 'Alles zurücksetzen',
      hint: 'Artikel, Verkäufe, Journal und Abschlüsse werden gelöscht und durch Demo-Daten ersetzt.',
      submitLabel: 'Alles zurücksetzen',
      action: async (code) => {
        await api('/maintenance/reset', { method: 'POST', body: { deleteCode: code } });
        showToast('Alles zurückgesetzt');
        await Promise.all([loadBootstrap(), loadMaintenanceCounts()]);
        renderHeader(); renderMain();
      },
    });
  }
}

/* ---------------------------------------------------------------------
 * Mount
 * ------------------------------------------------------------------- */
async function mount() {
  document.getElementById('app').innerHTML = `
    <header class="header" id="header-root"></header>
    <div id="main-root" style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden"></div>
    <div id="overlay-root"></div>
    <div id="toast-root"></div>`;
  document.getElementById('app').style.cssText = 'height:100vh;display:flex;flex-direction:column;overflow:hidden';
  document.getElementById('app').addEventListener('click', onAction);

  try {
    await loadBootstrap();
  } catch (e) {
    document.getElementById('main-root').innerHTML = '<p style="padding:20px">Verbindung zum Server fehlgeschlagen. Bitte neu laden.</p>';
    return;
  }
  renderHeader();
  renderMain();
  renderOverlay();
  renderToast();
  tick();
  setInterval(tick, 10000);
}

mount();
