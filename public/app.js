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
    headers: opts.raw ? { 'Content-Type': opts.contentType || 'text/plain' } : (opts.body ? { 'Content-Type': 'application/json' } : undefined),
    body: opts.raw ? opts.body : (opts.body ? JSON.stringify(opts.body) : undefined),
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
  depositTypes: [],
  compAccounts: [],
  invoiceAccounts: [], // Firmen "auf Rechnung" (Kürzel + Name)
  cashBalanceCents: 0,
  todayRevenueCents: 0,
  // Kassen: this device's Kasse (null = several exist, device not assigned yet) and all Kassen.
  register: null,
  registers: [],
  adminRegisterId: null, // Kasse shown in Verwaltung → Journal / Kasse
  reportRegister: 'all', // Übersicht / Auswertungen: 'all' = Gesamt, or a Kasse id
  access: { unlocked: false, expiresAt: null, codeConfigured: false },
  posUsers: [],
  posUser: null,
  posSelectedUserId: null,
  showPosPin: false,
  posPinBuf: '',
  posPinError: '',

  view: 'pos',
  adminTab: 'overview',
  cat: 'Alle',
  catFilter: 'Alle',
  focusProduct: null,

  cart: [],
  selected: null,
  payment: 'cash',
  compAccountId: null,
  invoiceAccountId: null,
  invoiceRef: '', // optional Vermerk for the invoice, e.g. who ordered
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

  admin: { kpis: null, daily: null, hourly: null, ranking: null, payments: null, groups: null, products: null, categories: null, depositTypes: null, compAccounts: null, comp: null, invoiceAccounts: null, invoiceSel: null, invoiceStatus: 'open', invoiceFrom: '', invoiceTo: '', invoiceStatement: null, journal: null, journalBefore: null, cashStatus: null, zReports: null, maintenanceCounts: null, recoveryEmail: null, users: null, registers: null, deviceRegisterId: null },
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
  state.depositTypes = data.depositTypes || [];
  state.compAccounts = data.compAccounts || [];
  if (!state.compAccounts.some((a) => a.id === state.compAccountId)) state.compAccountId = null;
  if (state.payment === 'comp' && !state.compAccounts.length) state.payment = 'cash';
  state.invoiceAccounts = data.invoiceAccounts || [];
  if (!state.invoiceAccounts.some((a) => a.id === state.invoiceAccountId)) state.invoiceAccountId = null;
  if (state.payment === 'invoice' && !state.invoiceAccounts.length) state.payment = 'cash';
  state.cashBalanceCents = data.cashBalanceCents;
  state.todayRevenueCents = data.todayRevenueCents;
  state.access = data.access;
  state.posUsers = data.posUsers || [];
  state.posUser = data.posUser || null;
  state.register = data.register || null;
  state.registers = data.registers || [];
  if (!state.registers.some((r) => r.id === state.adminRegisterId)) {
    state.adminRegisterId = state.register ? state.register.id : (state.registers[0] ? state.registers[0].id : null);
  }
  if (state.reportRegister !== 'all' && !state.registers.some((r) => r.id === state.reportRegister)) state.reportRegister = 'all';
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
  const line = state.cart.find((l) => l.productId === product.id && l.kind !== 'return');
  if (line) {
    if (track && line.qty >= product.stock) return showToast('Nur noch ' + product.stock + '× auf Lager');
    line.qty++;
  } else {
    state.cart.push({ key: uid(), productId: product.id, name: product.name, priceCents: product.priceCents, depositCents: product.depositCents || 0, qty: 1 });
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
  // "Ohne Berechnung": the goods value is still recorded (sub), but nothing is collected —
  // no discount to apply, no Pfand charged.
  if (state.payment === 'comp') return { sub, disc: 0, pfand: 0, total: 0 };
  const pfand = state.cart.reduce((s, l) => s + (l.depositCents || 0) * l.qty, 0);
  const disc = Math.min(state.discount, sub);
  return { sub, disc, pfand, total: Math.max(0, sub - disc) + pfand };
}

/**
 * One tap adds a return line to the current cart (qty 1, +1 per further tap) — it is NOT booked
 * yet, just like tapping a normal product tile. It's settled together with whatever else is in
 * the cart in one combined checkout() call, which is exactly the point: a customer returning
 * Krüge while ordering something new sees one net amount, not two separate bookings.
 */
function addDepositReturnToCart(depositTypeId) {
  const type = state.depositTypes.find((t) => t.id === depositTypeId);
  if (!type) return;
  const line = state.cart.find((l) => l.kind === 'return' && l.depositTypeId === type.id);
  if (line) {
    line.qty++;
  } else {
    state.cart.push({ key: uid(), kind: 'return', depositTypeId: type.id, name: type.name, priceCents: 0, depositCents: -type.amountCents, qty: 1 });
  }
  state.tender = 0;
  renderMain();
}

async function checkout() {
  const { cart, payment, tender } = state;
  if (!cart.length) return showToast('Warenkorb ist leer');
  const { total } = totals();
  const comp = payment === 'comp';
  if (comp) {
    if (!state.compAccounts.some((a) => a.id === state.compAccountId)) return showToast('Bitte Bereich wählen');
    if (cart.some((l) => l.kind === 'return')) return showToast('Pfand-Rückgabe bitte separat buchen');
  }
  const invoice = payment === 'invoice';
  if (invoice && !state.invoiceAccounts.some((a) => a.id === state.invoiceAccountId)) return showToast('Bitte Firma wählen');
  // A net amount <= 0 (nothing to collect, or the register owes the customer) is always settled
  // in cash — there's nothing for a card to charge, and Pfand is refunded in cash either way.
  // "Auf Rechnung" stays on the invoice even at <= 0: Pfand-Rückgaben are then credited to the Firma.
  const effectivePayment = comp ? 'comp' : invoice ? 'invoice' : (total > 0 ? payment : 'cash');
  if (total > 0 && effectivePayment === 'cash' && tender < total) return showToast('Betrag noch nicht ausreichend');
  const clientUuid = uid();
  try {
    const receipt = await api('/sales', {
      method: 'POST',
      body: {
        clientUuid,
        items: cart.filter((l) => l.kind !== 'return').map((l) => ({ productId: l.productId, name: l.name, unitCents: l.priceCents, qty: l.qty })),
        returns: cart.filter((l) => l.kind === 'return').map((l) => ({ depositTypeId: l.depositTypeId, qty: l.qty })),
        discountCents: comp ? 0 : state.discount,
        payment: effectivePayment,
        compAccountId: comp ? state.compAccountId : null,
        invoiceAccountId: effectivePayment === 'invoice' ? state.invoiceAccountId : null,
        invoiceRef: effectivePayment === 'invoice' ? state.invoiceRef : '',
        givenCents: total > 0 ? (effectivePayment === 'cash' ? tender : total) : 0,
      },
    });
    state.cart = [];
    state.tender = 0;
    state.discount = 0;
    state.selected = null;
    // Always fall back to a paying sale afterwards — the next customer must never be booked
    // "ohne Berechnung" just because the previous booking was.
    if (comp) { state.payment = 'cash'; state.compAccountId = null; }
    // Same for "auf Rechnung": the next customer is a normal paying one again.
    if (invoice) { state.payment = 'cash'; state.invoiceAccountId = null; state.invoiceRef = ''; }
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
    if (f.kind === 'void') {
      const reason = (f.reason || '').trim();
      if (!reason) return setFormError('Bitte einen Grund angeben');
      const rc = await guardedAdminCall(() => api('/receipts/' + encodeURIComponent(f.receiptNo) + '/void', { method: 'POST', body: { reason } }));
      if (!rc) return;
      closeForm();
      showToast('Bon ' + f.receiptNo + ' storniert');
      await loadBootstrap();
      renderHeader();
      await enterAdminTab(state.adminTab);
      return;
    }
    if (f.kind === 'reset-code') {
      const accessCode = (f.accessCode || '').trim();
      if (!accessCode) return setFormError('Neuer Zugangscode fehlt');
      const deleteCode = (f.deleteCode || '').trim() || null;
      const res = await api('/access/reset', { method: 'POST', body: { token: f.token, accessCode, deleteCode } });
      state.access = { unlocked: true, expiresAt: res.expiresAt, codeConfigured: true };
      history.replaceState({}, '', location.pathname);
      closeForm();
      state.view = 'admin';
      showToast('Zugangscode zurückgesetzt');
      renderHeader();
      await enterAdminTab('overview');
      return;
    }
    if (f.kind === 'category') {
      const name = (f.name || '').trim();
      if (!name) return setFormError('Name fehlt');
      const depositTypeId = f.depositTypeId || null;
      if (f.id) {
        await guardedAdminCall(() => api('/categories/' + f.id, { method: 'PATCH', body: { name, depositTypeId } }));
        showToast('Gruppe gespeichert');
      } else {
        await guardedAdminCall(() => api('/categories', { method: 'POST', body: { name, trackStockDefault: !!f.trackStockDefault, depositTypeId } }));
        showToast('Gruppe angelegt');
      }
      closeForm();
      // A rename cascades to every product's category text; keep the POS's own product list
      // (from loadBootstrap(), not loadAdminProducts()) in sync too, not just the Verwaltung view.
      await Promise.all([loadBootstrap(), loadCategories(), loadAdminProducts()]);
      renderHeader();
      renderMain();
      return;
    }
    if (f.kind === 'deposit-type') {
      const name = (f.name || '').trim();
      if (!name) return setFormError('Name fehlt');
      const amountCents = toCents(f.amount || '0');
      if (amountCents <= 0) return setFormError('Betrag muss größer als 0 sein');
      const body = { name, amount: f.amount || '0' };
      await guardedAdminCall(() => (f.id ? api('/deposit-types/' + f.id, { method: 'PATCH', body }) : api('/deposit-types', { method: 'POST', body })));
      closeForm();
      showToast(f.id ? 'Pfand-Option gespeichert' : 'Pfand-Option angelegt');
      await Promise.all([loadDepositTypes(), loadBootstrap()]);
      renderHeader();
      renderMain();
      return;
    }
    if (f.kind === 'invoice-account') {
      const body = { code: (f.code || '').trim(), name: (f.name || '').trim(), address: f.address || '', note: f.note || '' };
      if (!body.code) return setFormError('Kürzel fehlt');
      if (!body.name) return setFormError('Firmenname fehlt');
      const ok = await guardedAdminCall(() => (f.id ? api('/invoice-accounts/' + f.id, { method: 'PATCH', body }) : api('/invoice-accounts', { method: 'POST', body })));
      if (!ok) return;
      closeForm();
      showToast(f.id ? 'Firma gespeichert' : 'Firma angelegt');
      await Promise.all([loadInvoiceAccounts(), loadInvoiceStatement(), loadBootstrap()]);
      renderHeader();
      renderMain();
      return;
    }
    if (f.kind === 'invoice-bill') {
      const res = await guardedAdminCall(() => api('/invoice-accounts/' + f.accountId + '/bill', { method: 'POST', body: { saleIds: f.saleIds, billedRef: f.billedRef || '' } }));
      if (!res) return;
      closeForm();
      showToast(res.changed + ' Bon(s) als abgerechnet markiert');
      await Promise.all([loadInvoiceAccounts(), loadInvoiceStatement()]);
      renderMain();
      return;
    }
    if (f.kind === 'comp-account') {
      const name = (f.name || '').trim();
      if (!name) return setFormError('Name fehlt');
      await guardedAdminCall(() => (f.id ? api('/comp-accounts/' + f.id, { method: 'PATCH', body: { name } }) : api('/comp-accounts', { method: 'POST', body: { name } })));
      closeForm();
      showToast(f.id ? 'Bereich gespeichert' : 'Bereich angelegt');
      await Promise.all([loadCompAccounts(), loadBootstrap()]);
      renderHeader();
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
        sku: (f.sku || '').trim(),
        category: f.category,
        priceCents: price,
        costCents: toCents(f.cost || '0'),
        depositTypeId: f.depositTypeId || null,
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
    if (f.kind === 'user') {
      const name = (f.name || '').trim();
      if (!name) return setFormError('Name fehlt');
      if (!f.id && !/^\d{4}$/.test(f.pin || '')) return setFormError('PIN muss 4-stellig sein');
      if (f.id && f.pin && !/^\d{4}$/.test(f.pin)) return setFormError('PIN muss 4-stellig sein');
      const body = { name, role: f.role, categoryIds: f.role === 'cashier' ? (f.categoryIds || []) : [] };
      if (f.pin) body.pin = f.pin;
      if (f.id) body.active = !!f.active;
      await guardedAdminCall(() => (f.id ? api('/users/' + f.id, { method: 'PATCH', body }) : api('/users', { method: 'POST', body })));
      closeForm();
      showToast(f.id ? 'Benutzer gespeichert' : 'Benutzer angelegt');
      await reloadUsersAndBootstrap();
      renderHeader();
      kickOutIfVerwaltungNowLocked();
      renderMain();
      return;
    }
    if (f.kind === 'register') {
      const name = (f.name || '').trim();
      if (!name) return setFormError('Name fehlt');
      const body = { name, categoryIds: f.categoryIds || [] };
      await guardedAdminCall(() => (f.id ? api('/registers/' + f.id, { method: 'PATCH', body }) : api('/registers', { method: 'POST', body })));
      closeForm();
      showToast(f.id ? 'Kasse gespeichert' : 'Kasse angelegt');
      await Promise.all([loadBootstrap(), loadRegisters()]);
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
      await guardedAdminCall(() => api('/cash-movements', { method: 'POST', body: { type: f.kind, amountCents: amount, note: f.note || '', registerId: state.adminRegisterId } }));
      closeForm();
      showToast((f.kind === 'in' ? 'Einlage' : 'Entnahme') + ' ' + eur(amount) + ' gebucht' + (multiRegister() ? ' · ' + registerName(state.adminRegisterId) : ''));
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
async function importArticlesFile(file) {
  if (!file) return;
  const text = await file.text();
  try {
    const result = await guardedAdminCall(() => api('/products/import', { method: 'POST', raw: true, contentType: 'text/csv; charset=utf-8', body: text }));
    if (!result) return;
    let msg = `Import: ${result.created} neu, ${result.updated} aktualisiert`;
    if (result.errors && result.errors.length) {
      msg += `, ${result.errors.length} Zeile(n) übersprungen`;
      console.warn('Import-Fehler:', result.errors);
    }
    showToast(msg);
    await Promise.all([loadBootstrap(), loadAdminProducts(), loadCategories()]);
    renderHeader();
    renderMain();
  } catch (e) {
    showToast(e.message);
  }
}

async function loadAdminProducts() {
  const data = await guardedAdminCall(() => api('/admin/products'));
  if (data) state.admin.products = data;
  return data;
}
async function loadOverview() {
  const [kpis, daily, hourly, ranking, payments] = await guardedAdminCall(() => Promise.all([
    api('/reports/kpis?' + reportRegisterQuery()), api('/reports/daily?' + reportRegisterQuery()), api('/reports/hourly?' + reportRegisterQuery()), api('/reports/products?' + reportRegisterQuery()), api('/reports/payments?' + reportRegisterQuery()),
  ])) || [null, null, null, null, null];
  Object.assign(state.admin, { kpis, daily, hourly, ranking, payments });
}
async function loadGroups() {
  const [groups, comp] = await guardedAdminCall(() => Promise.all([api('/reports/groups?' + reportRegisterQuery()), api('/reports/comp?' + reportRegisterQuery())])) || [null, null];
  if (groups) Object.assign(state.admin, { groups, comp });
}
async function loadCompAccounts() {
  const data = await guardedAdminCall(() => api('/comp-accounts'));
  if (data) state.admin.compAccounts = data;
  return data;
}
async function loadInvoiceAccounts() {
  const data = await guardedAdminCall(() => api('/invoice-accounts'));
  if (data) state.admin.invoiceAccounts = data;
  return data;
}
function invoiceStatementQuery() {
  const a = state.admin;
  return 'status=' + a.invoiceStatus + (a.invoiceFrom ? '&from=' + a.invoiceFrom : '') + (a.invoiceTo ? '&to=' + a.invoiceTo : '');
}
async function loadInvoiceStatement() {
  if (!state.admin.invoiceSel) { state.admin.invoiceStatement = null; return; }
  const data = await guardedAdminCall(() => api('/invoice-accounts/' + state.admin.invoiceSel + '/statement?' + invoiceStatementQuery()));
  if (data) state.admin.invoiceStatement = data;
}
async function loadInvoiceTab() {
  await Promise.all([loadInvoiceAccounts(), loadInvoiceStatement()]);
  renderMain();
}
async function loadCategories() {
  const data = await guardedAdminCall(() => api('/categories'));
  if (data) state.admin.categories = data;
  return data;
}
async function loadDepositTypes() {
  const data = await guardedAdminCall(() => api('/deposit-types'));
  if (data) state.admin.depositTypes = data;
  return data;
}
async function loadJournal(before) {
  const data = await guardedAdminCall(() => api('/journal?' + adminRegisterQuery() + (before ? '&before=' + before : '') + '&limit=80'));
  if (data) {
    state.admin.journal = before ? (state.admin.journal || []).concat(data.items) : data.items;
    state.admin.journalBefore = data.nextBefore;
  }
}
async function loadCashStatus() {
  const [status, zReports] = await guardedAdminCall(() => Promise.all([api('/cash/status?' + adminRegisterQuery()), api('/z-reports?' + adminRegisterQuery())])) || [null, null];
  Object.assign(state.admin, { cashStatus: status, zReports });
}
async function loadMaintenanceCounts() {
  const data = await guardedAdminCall(() => api('/maintenance/status'));
  if (data) state.admin.maintenanceCounts = data;
}
async function loadRecoveryEmail() {
  const data = await guardedAdminCall(() => api('/settings/codes'));
  if (data) state.admin.recoveryEmail = data.recoveryEmail;
}
async function loadRegisters() {
  const data = await guardedAdminCall(() => api('/registers'));
  if (data) { state.admin.registers = data.registers; state.admin.deviceRegisterId = data.deviceRegisterId; }
}
async function loadUsers() {
  const data = await guardedAdminCall(() => api('/users'));
  if (data) state.admin.users = data;
}

async function enterAdminTab(tab) {
  state.adminTab = tab;
  renderMain();
  if (tab === 'overview') await loadOverview();
  else if (tab === 'analytics') await loadGroups();
  else if (tab === 'groups') await Promise.all([loadCategories(), loadDepositTypes()]);
  else if (tab === 'articles' || tab === 'stock') await Promise.all([loadAdminProducts(), loadCategories(), loadDepositTypes()]);
  else if (tab === 'deposit') await loadDepositTypes();
  else if (tab === 'comp') await loadCompAccounts();
  else if (tab === 'invoice') await loadInvoiceTab();
  else if (tab === 'users') await Promise.all([loadUsers(), loadCategories()]);
  else if (tab === 'registers') await Promise.all([loadRegisters(), loadCategories()]);
  else if (tab === 'journal') await loadJournal(null);
  else if (tab === 'cash') await loadCashStatus();
  else if (tab === 'settings') await Promise.all([loadMaintenanceCounts(), loadRecoveryEmail()]);
  renderMain();
}

function goPos() { state.view = 'pos'; renderHeader(); renderMain(); }
async function goAdmin() {
  if (!canUseAdmin()) {
    showToast(state.posUser ? 'Verwaltung ist für diesen Benutzer nicht verfügbar' : 'Verwaltung ist gesperrt · bitte als Admin an der Kasse anmelden');
    return;
  }
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

async function posPinPress(k) {
  if (k === 'del') { state.posPinBuf = state.posPinBuf.slice(0, -1); state.posPinError = ''; renderOverlay(); return; }
  if (k === 'x') { state.showPosPin = false; state.posSelectedUserId = null; state.posPinBuf = ''; state.posPinError = ''; renderOverlay(); return; }
  const buf = (state.posPinBuf + k).slice(0, 4);
  state.posPinBuf = buf;
  if (buf.length === 4) {
    try {
      const res = await api('/pos/login', { method: 'POST', body: { userId: state.posSelectedUserId, pin: buf } });
      state.showPosPin = false; state.posPinBuf = ''; state.posPinError = ''; state.posSelectedUserId = null;
      state.posUser = res.user;
      if (res.user.role === 'cashier' && state.view === 'admin') {
        state.view = 'pos';
        state.adminTab = 'overview';
      }
      renderOverlay();
      await loadBootstrap();
      renderHeader();
      renderMain();
      showToast('Angemeldet als ' + res.user.name);
    } catch (e) {
      state.posPinBuf = '';
      state.posPinError = e.status === 429 ? 'Zu viele Fehlversuche · später erneut' : 'Falscher PIN';
      renderOverlay();
    }
    return;
  }
  state.posPinError = '';
  renderOverlay();
}

async function posSwitchUser() {
  try { await api('/pos/logout', { method: 'POST' }); } catch (e) { /* ignore */ }
  state.posUser = null;
  // "Wechseln" is reachable from the header on every screen, including Verwaltung — without
  // forcing the view back to Kasse, renderMain() would just re-render whatever admin tab was
  // open instead of showing the Mitarbeiter-Auswahl (renderPos() only shows it when state.view
  // is already 'pos').
  state.view = 'pos';
  state.adminTab = 'overview';
  await loadBootstrap();
  renderHeader();
  renderMain();
  showToast('Benutzer gewechselt');
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

function multiRegister() { return state.registers.length > 1; }
function registerName(id) { const r = state.registers.find((x) => x.id === id); return r ? r.name : ''; }
function adminRegisterQuery() { return 'registerId=' + encodeURIComponent(state.adminRegisterId || ''); }
function reportRegisterQuery() { return 'registerId=' + encodeURIComponent(state.reportRegister); }

/** Kasse picker shown on Journal / Kasse (one Kasse) and Übersicht / Auswertungen (+ Gesamt). */
function registerPicker(action, current, withAll) {
  if (!multiRegister()) return '';
  const opts = (withAll ? [{ id: 'all', name: 'Gesamt' }] : []).concat(state.registers);
  return `<div class="group-chips register-picker">${opts.map((r) => `<button data-action="${action}" data-id="${r.id}" class="group-chip ${current === r.id ? 'active' : ''}">${esc(r.name)}${state.register && r.id === state.register.id ? ' · dieses Gerät' : ''}</button>`).join('')}</div>`;
}

function canUseAdmin() {
  const hasAdminUser = state.posUsers.some((u) => u.role === 'admin');
  if (!hasAdminUser) return true; // no admin POS account set up yet — access code alone still works
  return !!(state.posUser && state.posUser.role === 'admin');
}

/**
 * Creating the first admin-role POS account closes the "access code alone" bootstrap door
 * immediately — if nobody on this device is logged in as that account yet, the very next admin
 * API call would otherwise silently 403. Kick back to Kasse with a clear explanation instead of
 * leaving Verwaltung sitting there half-broken.
 */
function kickOutIfVerwaltungNowLocked() {
  if (state.view === 'admin' && !canUseAdmin()) {
    state.view = 'pos';
    state.adminTab = 'overview';
    showToast('Verwaltung ist jetzt an eine Admin-Anmeldung an der Kasse gebunden · bitte anmelden');
  }
}

/**
 * Reloads the Benutzer list + bootstrap after a create/edit/delete. bootstrap() has no requireAccess()
 * gate, so it always succeeds and is what canUseAdmin()/kickOutIfVerwaltungNowLocked() need; the
 * users list can legitimately 403 here too (e.g. an admin removing their own admin-role account
 * while others exist) — swallow only that case rather than leaving the view stuck mid-refresh.
 */
async function reloadUsersAndBootstrap() {
  await loadBootstrap();
  try {
    await loadUsers();
  } catch (e) {
    if (e.status !== 403) throw e;
  }
}

function renderHeader() {
  const el = document.getElementById('header-root');
  const showLock = state.settings.requireCode && state.access.unlocked;
  el.innerHTML = `
    <div class="brand"><span class="name">${esc(state.shopName)}</span><span class="tag">${multiRegister() ? (state.register ? esc(state.register.name) : 'Keine Kasse') : 'POS + WaWi'}</span></div>
    <div class="switch">
      <button data-action="go-pos" class="${state.view === 'pos' ? 'active' : ''}">Kasse</button>
      ${canUseAdmin() ? `<button data-action="go-admin" class="${state.view === 'admin' ? 'active' : ''}">Verwaltung</button>` : ''}
    </div>
    ${state.posUsers.length ? `<div class="pos-user-badge">
      ${state.posUser
        ? `<span>${esc(state.posUser.name)}</span><button data-action="pos-switch-user">Wechseln</button>`
        : `<span class="pos-user-none">Kein Benutzer angemeldet</span>`}
    </div>` : ''}
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
function renderPosUserSelect() {
  document.getElementById('main-root').innerHTML = `
  <div class="pos-user-select">
    <div class="pos-user-select-title">Wer arbeitet an dieser Kasse?</div>
    <div class="pos-user-tiles">
      ${state.posUsers.map((u) => `<button data-action="pos-pick-user" data-id="${u.id}" class="pos-user-tile">${esc(u.name)}</button>`).join('')}
    </div>
  </div>`;
}

function renderRegisterUnassigned() {
  document.getElementById('main-root').innerHTML = `
  <div class="pos-user-select">
    <div class="pos-user-select-title">Dieses Gerät ist noch keiner Kasse zugeordnet</div>
    <div class="register-unassigned-hint">Ein Admin legt in der Verwaltung unter „Kassen“ fest, welche Kasse dieses Gerät ist. Bis dahin kann hier nicht verkauft werden.</div>
  </div>`;
}

function renderPos() {
  if (multiRegister() && !state.register) return renderRegisterUnassigned();
  if (state.posUsers.length && !state.posUser) return renderPosUserSelect();
  const CATS_ORDER = ['Speisen', 'Getränke', 'Süßes'];
  const cats = ['Alle'].concat(
    CATS_ORDER.filter((c) => state.products.some((p) => p.category === c)),
    Array.from(new Set(state.products.map((p) => p.category).filter((c) => CATS_ORDER.indexOf(c) < 0)))
  );
  const visible = state.products.filter((p) => state.cat === 'Alle' || p.category === state.cat);
  const showStock = state.settings.trackStock;
  const { sub, disc, pfand, total } = totals();
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
      ${p.depositCents ? `<div class="tile-pfand">+ Pfand ${eur(p.depositCents)}</div>` : ''}
    </button>`;
  }).join('');

  const cartLines = state.cart.map((l) => {
    const open = state.selected === l.key;
    if (l.kind === 'return') {
      return `<div class="cart-line return-line ${open ? 'selected' : ''}" data-action="select-line" data-key="${l.key}">
        <div class="cart-line-row">
          <span class="cart-line-qty mono">${l.qty}×</span>
          <span class="cart-line-name">↩ Pfand zurück · ${esc(l.name)}</span>
          <span class="cart-line-sum mono return-amount">${eur(l.depositCents * l.qty)}</span>
        </div>
        ${open ? `<div class="cart-line-actions">
          <button data-action="line-dec" data-key="${l.key}">−</button>
          <button data-action="line-inc" data-key="${l.key}">+</button>
          <button data-action="line-del" data-key="${l.key}" class="del-btn">Entfernen</button>
        </div>` : ''}
      </div>`;
    }
    return `<div class="cart-line ${open ? 'selected' : ''}" data-action="select-line" data-key="${l.key}">
      <div class="cart-line-row">
        <span class="cart-line-qty mono">${l.qty}×</span>
        <span class="cart-line-name">${esc(l.name)}</span>
        <span class="cart-line-unit mono">${eur(l.priceCents)}</span>
        <span class="cart-line-sum mono">${eur(l.priceCents * l.qty)}</span>
      </div>
      ${l.depositCents && state.payment !== 'comp' ? `<div class="cart-line-pfand">zzgl. Pfand ${l.qty}× ${eur(l.depositCents)} = ${eur(l.depositCents * l.qty)}</div>` : ''}
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

  // A net amount <= 0 means nothing to collect (or the register owes the customer) — settled in
  // one tap, no payment method or tender needed (see checkout()'s effectivePayment logic).
  const comp = state.payment === 'comp';
  const compAccount = comp ? state.compAccounts.find((a) => a.id === state.compAccountId) : null;
  const compHasReturn = comp && state.cart.some((l) => l.kind === 'return');
  const invoice = state.payment === 'invoice';
  const invoiceAccount = invoice ? state.invoiceAccounts.find((a) => a.id === state.invoiceAccountId) : null;
  const netNegative = total < 0;
  const netZero = total === 0;
  const canPayReady = comp
    ? !!compAccount && !compHasReturn
    : invoice ? !!invoiceAccount
    : (netNegative || netZero ? true : (state.payment === 'card' || state.tender >= total));
  const checkoutLabel = comp
    ? (compAccount ? 'Ohne Berechnung buchen · ' + compAccount.name : 'Bereich wählen')
    : invoice
    ? (!invoiceAccount ? 'Firma wählen' : total < 0 ? 'Gutschrift buchen · ' + invoiceAccount.code + ' · ' + eur(-total) : 'Auf Rechnung buchen · ' + invoiceAccount.code + ' · ' + eur(total))
    : netNegative
    ? 'Auszahlen · ' + eur(Math.abs(total))
    : netZero
      ? 'Abschließen · 0,00 €'
      : (state.payment === 'card'
        ? 'Kartenzahlung abschließen · ' + eur(total)
        : (state.tender >= total && state.tender > 0 ? 'Kassieren · Rückgeld ' + eur(Math.max(0, change)) : 'Kassieren · ' + eur(total)));

  const returnEligible = state.depositTypes;

  document.getElementById('main-root').innerHTML = `
  <div class="pos">
    <section class="pos-left">
      <div class="cat-row">${cats.map((c) => `<button data-action="set-cat" data-cat="${esc(c)}" class="cat-chip ${state.cat === c ? 'active' : ''}">${esc(c)}</button>`).join('')}</div>
      <div class="tile-scroll"><div class="tile-grid">${tiles}</div></div>
      ${returnEligible.length ? `<div class="pfand-return-row">${returnEligible.map((t) => `<button data-action="add-deposit-return" data-id="${t.id}" class="pfand-return-tile">↩ ${esc(t.name)} · −${eur(t.amountCents)}</button>`).join('')}</div>` : ''}
    </section>
    <aside class="cart">
      <div class="cart-head">
        <span class="title">Warenkorb · ${cartCount}</span>
        <button data-action="clear-cart" class="clear">Leeren</button>
      </div>
      <div class="cart-lines">
        ${state.cart.length === 0 ? `<div class="cart-empty"><span class="icon">🧾</span><span class="hint">Artikel antippen zum Hinzufügen</span></div>` : cartLines}
      </div>
      <div class="cart-sums">
        <div class="row-between subtotal-row"><span>${comp ? 'Warenwert' : 'Zwischensumme'}</span><span class="mono">${eur(sub)}</span></div>
        ${comp ? '' : `<div class="row-between discount-row"><div class="discount-chips">${discountChips}</div><span class="discount-value mono">${disc ? '−' + eur(disc) : ''}</span></div>`}
        ${pfand ? `<div class="row-between pfand-row"><span>${pfand < 0 ? 'Pfand-Rückgabe' : 'zzgl. Pfand'}</span><span class="mono ${pfand < 0 ? 'negative' : ''}">${eur(pfand)}</span></div>` : ''}
        <div class="row-between total-row"><span class="label">${comp ? 'Ohne Berechnung' : invoice ? (netNegative ? 'Gutschrift für Firma' : 'Auf Rechnung') : netNegative ? 'Auszahlung an Kunde' : 'Zu zahlen'}</span><span class="value mono ${netNegative ? 'negative' : ''}">${eur(Math.abs(total))}</span></div>
        ${state.settings.cardEnabled || state.compAccounts.length || state.invoiceAccounts.length ? `<div class="pay-switch">
          <button data-action="set-payment" data-value="cash" class="${state.payment === 'cash' ? 'active' : ''}">Bar</button>
          ${state.settings.cardEnabled ? `<button data-action="set-payment" data-value="card" class="${state.payment === 'card' ? 'active' : ''}">Karte</button>` : ''}
          ${state.invoiceAccounts.length ? `<button data-action="set-payment" data-value="invoice" class="${invoice ? 'active' : ''}">Rechnung</button>` : ''}
          ${state.compAccounts.length ? `<button data-action="set-payment" data-value="comp" class="${comp ? 'active' : ''}">Ohne Berechnung</button>` : ''}
        </div>` : ''}
        ${invoice ? `<div class="comp-box invoice-box">
          <div class="comp-box-label">Auf welche Firma?</div>
          <div class="comp-chips">${state.invoiceAccounts.map((a) => `<button data-action="set-invoice-account" data-id="${a.id}" title="${esc(a.name)}" class="comp-chip invoice-chip ${state.invoiceAccountId === a.id ? 'active' : ''}">${esc(a.code)}</button>`).join('')}</div>
          <div class="invoice-picked">${invoiceAccount ? esc(invoiceAccount.name) : 'Kürzel antippen'}</div>
          <input id="invoice-ref" class="invoice-ref" maxlength="120" placeholder="Vermerk (optional), z. B. wer bestellt hat" value="${esc(state.invoiceRef)}" autocomplete="off">
          <div class="comp-box-hint">Zählt zum Umsatz · kommt nicht in die Kasse · erscheint in der Abrechnung der Firma${state.cart.some((l) => l.kind === 'return') ? ' · Pfand-Rückgabe wird der Firma gutgeschrieben, nicht bar ausgezahlt' : ''}</div>
        </div>` : ''}
        ${comp ? `<div class="comp-box">
          <div class="comp-box-label">Auf welchen Bereich buchen?</div>
          <div class="comp-chips">${state.compAccounts.map((a) => `<button data-action="set-comp-account" data-id="${a.id}" class="comp-chip ${state.compAccountId === a.id ? 'active' : ''}">${esc(a.name)}</button>`).join('')}</div>
          <div class="comp-box-hint">${compHasReturn ? 'Pfand-Rückgabe bitte separat buchen — erst aus dem Warenkorb entfernen.' : 'Wird erfasst, aber nicht kassiert · kein Pfand · zählt nicht zum Umsatz'}</div>
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
        ${netNegative && !invoice ? `<div class="payout-hint">Wird bar ausgezahlt</div>` : ''}
      </div>
      <div class="cart-footer">
        <button data-action="checkout" class="checkout-btn ${state.cart.length && canPayReady ? 'ready' : ''} ${!state.cart.length ? 'empty' : ''}">${esc(checkoutLabel)}</button>
      </div>
    </aside>
  </div>`;
  // Typing the Vermerk only updates state — re-rendering would drop the keyboard focus.
  const refInput = document.getElementById('invoice-ref');
  if (refInput) refInput.addEventListener('input', () => { state.invoiceRef = refInput.value; });
}

/* ---------------------------------------------------------------------
 * Rendering — Admin
 * ------------------------------------------------------------------- */
function adminTabsList() {
  const tabs = [['overview', 'Übersicht'], ['analytics', 'Auswertungen'], ['groups', 'Artikelgruppen'], ['deposit', 'Pfand'], ['comp', 'Ohne Berechnung'], ['invoice', 'Auf Rechnung'], ['articles', 'Artikel'], ['stock', 'Bestand'], ['users', 'Benutzer'], ['registers', 'Kassen'], ['journal', 'Journal'], ['cash', 'Kasse'], ['settings', 'Einstellungen']];
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
  const allTotal = a.payments.cash.sumCents + a.payments.card.sumCents + (a.payments.invoice?.sumCents || 0);

  return registerPicker('set-report-register', state.reportRegister, true) + `
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
        ${['cash', 'card', 'invoice'].filter((k) => k !== 'invoice' || a.payments.invoice?.count).map((k) => {
          const p = a.payments[k];
          const pct = allTotal ? Math.round(p.sumCents / allTotal * 100) : 0;
          return `<div class="pay-row">
            <div class="pay-row-top"><span class="label">${{ cash: 'Bar', card: 'Karte', invoice: 'Auf Rechnung' }[k]}</span><span class="sum mono">${eur(p.sumCents)}</span><span class="pct">${pct}%</span></div>
            <div class="pay-bar-bg"><div class="pay-bar-fill" style="width:${pct}%;background:${k === 'cash' ? 'var(--ink)' : k === 'invoice' ? 'var(--info-text)' : 'var(--accent)'}"></div></div>
            <div class="pay-row-sub">${p.count} Bons</div>
          </div>`;
        }).join('')}
        <div class="avg-ticket-row"><span>Ø Bon</span><span class="mono">${eur(a.payments.avgTicketCents)}</span></div>
      </div>
    </div>`;
}

function renderGroupsTab() {
  const cats = state.admin.categories;
  if (!cats) return '<p>Lädt…</p>';
  return `
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px">
        <div><div class="title">Artikelgruppen</div><div class="subtitle">${cats.length} Artikelgruppe(n)</div></div>
        <button data-action="new-category" class="btn-primary">+ Neue Gruppe</button>
      </div>
      ${cats.map((c) => `<div class="settings-row" data-action="toggle-category-default" data-id="${c.id}">
        <div><div class="title">${esc(c.name)}</div><div class="hint">${c.productCount} Artikel · Lagerbestand-Standard für neue Artikel${c.depositTypeName ? ' · Pfand-Standard ' + esc(c.depositTypeName) + ' (' + eur(c.depositTypeAmountCents) + ')' : ''}</div></div>
        <div style="display:flex;align-items:center;gap:14px">
          <div class="switch-track ${c.trackStockDefault ? 'on' : ''}"><div class="switch-knob"></div></div>
          <button data-action="rename-category" data-id="${c.id}">Bearbeiten</button>
          <button data-action="delete-category" data-id="${c.id}" data-name="${esc(c.name)}" class="del">✕</button>
        </div>
      </div>`).join('') || '<div style="padding:15px 18px;color:var(--text-3);font-size:13px">Noch keine Artikelgruppe angelegt.</div>'}
    </div>`;
}

function renderDepositTab() {
  const types = state.admin.depositTypes;
  if (!types) return '<p>Lädt…</p>';
  return `
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px">
        <div><div class="title">Pfand-Optionen</div><div class="subtitle">Zentral verwaltete Pfandbeträge, wählbar pro Artikelgruppe oder Artikel</div></div>
        <button data-action="new-deposit-type" class="btn-primary">+ Neue Pfand-Option</button>
      </div>
      ${types.map((t) => `<div class="settings-row" data-action="edit-deposit-type" data-id="${t.id}">
        <div><div class="title">${esc(t.name)}</div><div class="hint">${eur(t.amountCents)} · ${t.productCount} Artikel</div></div>
        <button data-action="delete-deposit-type" data-id="${t.id}" data-name="${esc(t.name)}" class="del">✕</button>
      </div>`).join('') || '<div style="padding:15px 18px;color:var(--text-3);font-size:13px">Noch keine Pfand-Option angelegt · lege z.B. "Krug" mit 3,00 € an.</div>'}
    </div>`;
}

function renderCompTab() {
  const accounts = state.admin.compAccounts;
  if (!accounts) return '<p>Lädt…</p>';
  return `
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px">
        <div><div class="title">Bereiche ohne Berechnung</div><div class="subtitle">Ware erfassen, die nicht bezahlt wird (z. B. Band, Helfer) · erscheint in der Kasse als Zahlart „Ohne Berechnung“, zählt nicht zu Umsatz und Kassenbestand</div></div>
        <button data-action="new-comp-account" class="btn-primary">+ Neuer Bereich</button>
      </div>
      ${accounts.map((a) => `<div class="settings-row" data-action="edit-comp-account" data-id="${a.id}">
        <div><div class="title">${esc(a.name)}</div><div class="hint">${a.saleCount} Buchung(en) · Warenwert ${eur(a.valueCents)}</div></div>
        <button data-action="delete-comp-account" data-id="${a.id}" data-name="${esc(a.name)}" class="del">✕</button>
      </div>`).join('') || '<div style="padding:15px 18px;color:var(--text-3);font-size:13px">Noch kein Bereich angelegt · lege z.B. "Band" an, dann erscheint in der Kasse die Zahlart „Ohne Berechnung“.</div>'}
    </div>`;
}

/** Verwaltung → Auf Rechnung: Firmen pflegen, offene Bons je Firma abrechnen. */
function renderInvoiceTab() {
  const a = state.admin;
  const accounts = a.invoiceAccounts;
  if (!accounts) return '<p>Lädt…</p>';
  if (a.invoiceSel) return renderInvoiceStatement();
  const visible = accounts.filter((x) => !x.archived || x.openCount);
  const openSum = visible.reduce((s, x) => s + x.openCents, 0);
  return `
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px">
        <div><div class="title">Firmen auf Rechnung</div><div class="subtitle">Erscheinen in der Kasse unter „Rechnung“ mit ihrem Kürzel · zählt zum Umsatz, nicht zum Kassenbestand${openSum ? ' · offen gesamt ' + eur(openSum) : ''}</div></div>
        <button data-action="new-invoice-account" class="btn-primary">+ Neue Firma</button>
      </div>
      ${visible.map((x) => `<div class="settings-row" data-action="open-invoice-statement" data-id="${x.id}">
        <div style="display:flex;gap:12px;align-items:center;min-width:0">
          <span class="invoice-code">${esc(x.code)}</span>
          <div style="min-width:0"><div class="title">${esc(x.name)}${x.archived ? ' <span class="hint">(gelöscht)</span>' : ''}</div>
            <div class="hint">${x.openCount ? `<b style="color:var(--warn-text)">${x.openCount} offene Bon(s) · ${eur(x.openCents)}</b> · seit ${fmtDateTimeShort(x.firstOpenAt)}` : 'nichts offen'}${x.billedCount ? ' · ' + x.billedCount + ' abgerechnet' : ''}</div></div>
        </div>
        <div class="row-actions">
          <button data-action="open-invoice-statement" data-id="${x.id}">Abrechnung</button>
          ${x.archived ? '' : `<button data-action="edit-invoice-account" data-id="${x.id}">Bearbeiten</button>
          <button data-action="delete-invoice-account" data-id="${x.id}" data-name="${esc(x.code + ' · ' + x.name)}" class="del">✕</button>`}
        </div>
      </div>`).join('') || '<div style="padding:15px 18px;color:var(--text-3);font-size:13px">Noch keine Firma angelegt · lege z.B. „BRW · Brauerei Weber“ an, dann erscheint in der Kasse die Zahlart „Rechnung“.</div>'}
    </div>`;
}

function renderInvoiceStatement() {
  const a = state.admin;
  const st = a.invoiceStatement;
  if (!st) return '<p>Lädt…</p>';
  const acc = st.account, t = st.totals;
  const q = invoiceStatementQuery();
  const statusChip = (v, label) => `<button data-action="set-invoice-status" data-value="${v}" class="group-chip ${a.invoiceStatus === v ? 'active' : ''}">${label}</button>`;
  const openIds = st.sales.filter((s) => !s.billedAt).map((s) => s.id);
  return `
    <div style="margin-bottom:10px"><button data-action="close-invoice-statement" class="btn-neutral" style="padding:8px 14px;border-radius:9px;font-size:13px;font-weight:700">← Alle Firmen</button></div>
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px;align-items:flex-start">
        <div style="display:flex;gap:12px;align-items:flex-start">
          <span class="invoice-code">${esc(acc.code)}</span>
          <div><div class="title">${esc(acc.name)}</div>
            ${acc.address ? `<div class="subtitle" style="white-space:pre-line">${esc(acc.address)}</div>` : ''}
            ${acc.note ? `<div class="subtitle">${esc(acc.note)}</div>` : ''}</div>
        </div>
        <div class="invoice-sum"><div class="subtitle">${t.count} Bon(s)</div><div class="mono">${eur(t.totalCents)}</div></div>
      </div>
      <div class="invoice-filter">
        <div class="group-chips" style="margin:0">${statusChip('open', 'Offen')}${statusChip('billed', 'Abgerechnet')}${statusChip('all', 'Alle')}</div>
        <label>von <input type="date" id="invoice-from" value="${esc(a.invoiceFrom)}"></label>
        <label>bis <input type="date" id="invoice-to" value="${esc(a.invoiceTo)}"></label>
      </div>
      <div class="invoice-actions">
        <a class="btn-neutral" href="/api/invoice-accounts/${acc.id}/statement.pdf?${q}" target="_blank" rel="noopener">PDF öffnen</a>
        <a class="btn-neutral" href="/api/invoice-accounts/${acc.id}/statement.csv?${q}">CSV (Excel)</a>
        ${openIds.length ? `<button data-action="bill-invoice" class="btn-primary">${openIds.length} Bon(s) als abgerechnet markieren</button>` : ''}
      </div>
    </div>
    <div class="card-box">
      <div class="title" style="font-size:14px;font-weight:700;margin-bottom:8px">Artikel</div>
      <table class="inv-table">
        <thead><tr><th class="num">Menge</th><th>Artikel</th><th class="num">Einzel</th><th class="num">Gesamt</th></tr></thead>
        <tbody>${st.items.map((i) => `<tr><td class="num mono">${i.qty}×</td><td>${esc(i.name)}</td><td class="num mono">${eur(i.unitCents)}</td><td class="num mono">${eur(i.sumCents)}</td></tr>`).join('')}
          ${(st.returns || []).map((r) => `<tr style="color:var(--warn-text)"><td class="num mono">${r.qty}×</td><td>↩ Pfand-Rückgabe ${esc(r.name)}</td><td class="num mono">−${eur(r.unitCents)}</td><td class="num mono">−${eur(r.sumCents)}</td></tr>`).join('')}
          ${st.items.length || (st.returns || []).length ? '' : '<tr><td colspan="4" style="color:var(--text-3)">Keine Bons im gewählten Zeitraum.</td></tr>'}</tbody>
        <tfoot>
          <tr><td></td><td>Warenwert</td><td></td><td class="num mono">${eur(t.goodsCents)}</td></tr>
          ${t.discountCents ? `<tr><td></td><td>Rabatt</td><td></td><td class="num mono">−${eur(t.discountCents)}</td></tr>` : ''}
          ${t.depositCents ? `<tr><td></td><td>Pfand</td><td></td><td class="num mono">${eur(t.depositCents)}</td></tr>` : ''}
          ${t.depositReturnedCents ? `<tr><td></td><td>Pfand-Rückgabe (Gutschrift)</td><td></td><td class="num mono">−${eur(t.depositReturnedCents)}</td></tr>` : ''}
          <tr class="sum"><td></td><td>Summe (brutto)</td><td></td><td class="num mono">${eur(t.totalCents)}</td></tr>
        </tfoot>
      </table>
    </div>
    <div class="card-box">
      <div class="title" style="font-size:14px;font-weight:700;margin-bottom:8px">Bons</div>
      <table class="inv-table">
        <thead><tr><th>Bon</th><th>Datum</th>${multiRegister() ? '<th>Kasse</th>' : ''}<th>Vermerk</th><th>Positionen</th><th class="num">Betrag</th><th>Status</th></tr></thead>
        <tbody>${st.sales.map((s) => `<tr>
          <td class="mono"><span class="link" data-action="open-receipt" data-no="${esc(s.receiptNo)}">${esc(s.receiptNo)}</span></td>
          <td>${fmtDateTimeShort(s.soldAt)}</td>${multiRegister() ? `<td>${esc(s.registerName || '')}</td>` : ''}
          <td>${esc(s.ref)}</td>
          <td style="font-size:12px;color:var(--text-2)">${s.lines.map((l) => l.qty + '× ' + esc(l.name)).concat((s.returns || []).map((r) => '↩ ' + r.qty + '× ' + esc(r.name))).join(', ')}</td>
          <td class="num mono">${eur(s.totalCents)}</td>
          <td>${s.billedAt ? `abgerechnet ${fmtDateTimeShort(s.billedAt).split(',')[0]}${s.billedRef ? ' · ' + esc(s.billedRef) : ''} <span class="link" data-action="unbill-invoice" data-id="${s.id}" data-no="${esc(s.receiptNo)}">zurücknehmen</span>` : '<b style="color:var(--warn-text)">offen</b>'}</td>
        </tr>`).join('') || `<tr><td colspan="${multiRegister() ? 7 : 6}" style="color:var(--text-3)">Keine Bons.</td></tr>`}</tbody>
      </table>
    </div>`;
}

function renderUsersTab() {
  const users = state.admin.users;
  const cats = state.admin.categories;
  if (!users || !cats) return '<p>Lädt…</p>';
  return `
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px">
        <div><div class="title">Benutzer</div><div class="subtitle">Steuert, welche Artikel ein Benutzer verkaufen darf · welche Kasse ein Gerät ist, steht unter „Kassen“</div></div>
        <button data-action="new-user" class="btn-primary">+ Neuer Benutzer</button>
      </div>
      ${users.map((u) => {
        const scope = u.role === 'admin'
          ? 'Sieht und bucht alle Artikel'
          : (u.categoryIds.length
            ? u.categoryIds.map((id) => (cats.find((c) => c.id === id) || {}).name).filter(Boolean).join(', ')
            : 'Keine Artikelgruppe zugewiesen · sieht nichts');
        return `<div class="settings-row" data-action="edit-user" data-id="${u.id}">
          <div>
            <div class="title">${esc(u.name)}${u.role === 'admin' ? ' <span class="badge-admin">Admin</span>' : ''}${!u.active ? ' <span class="badge-inactive">deaktiviert</span>' : ''}</div>
            <div class="hint">${esc(scope)}</div>
          </div>
          <button data-action="delete-user" data-id="${u.id}" data-name="${esc(u.name)}" class="del">✕</button>
        </div>`;
      }).join('') || '<div style="padding:15px 18px;color:var(--text-3);font-size:13px">Noch kein Benutzer angelegt · Kasse ist offen für alle.</div>'}
    </div>`;
}

function renderRegistersTab() {
  const regs = state.admin.registers;
  const cats = state.admin.categories;
  if (!regs || !cats) return '<p>Lädt…</p>';
  const deviceId = state.admin.deviceRegisterId;
  return `
    <div class="card-box">
      <div class="table-head-row" style="padding:0 0 12px">
        <div><div class="title">Kassen</div><div class="subtitle">Jede Kasse hat eigenen Kassenbestand, eigenes Journal und eigene Tagesabschlüsse · ${deviceId ? 'dieses Gerät ist ' + esc(registerName(deviceId)) : 'dieses Gerät ist noch keiner Kasse zugeordnet'}</div></div>
        <button data-action="new-register" class="btn-primary">+ Neue Kasse</button>
      </div>
      ${regs.map((r) => {
        const scope = r.categoryIds.length
          ? r.categoryIds.map((id) => (cats.find((c) => c.id === id) || {}).name).filter(Boolean).join(', ')
          : 'Alle Artikelgruppen';
        const isDevice = r.id === deviceId;
        return `<div class="settings-row" data-action="edit-register" data-id="${r.id}">
          <div>
            <div class="title">${esc(r.name)}${isDevice ? ' <span class="badge-admin">dieses Gerät</span>' : ''}</div>
            <div class="hint">${esc(scope)} · ${r.saleCount} Bon(s)</div>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            ${isDevice ? '' : `<button data-action="assign-device-register" data-id="${r.id}" class="btn-neutral" style="padding:8px 12px;border-radius:9px;font-size:12.5px;font-weight:700">Diesem Gerät zuordnen</button>`}
            ${regs.length > 1 && r.saleCount === 0 ? `<button data-action="delete-register" data-id="${r.id}" data-name="${esc(r.name)}" class="del">✕</button>` : ''}
          </div>
        </div>`;
      }).join('')}
    </div>`;
}

function renderAnalyticsTab() {
  const groups = state.admin.groups;
  if (!groups) return '<p>Lädt…</p>';
  const header = `<div class="table-head-row" style="padding:0 0 12px">
    <div><div class="title">Auswertungen</div><div class="subtitle">Nach Artikelgruppe, seit Beginn</div></div>
    <button data-action="email-reports" class="btn-neutral" style="padding:11px 16px;border-radius:10px;font-size:13px;font-weight:700">Per E-Mail senden</button>
  </div>`;
  return registerPicker('set-report-register', state.reportRegister, true) + header + (groups.map((g) => `
    <div class="group-card">
      <div class="group-head" data-action="show-group" data-name="${esc(g.name)}"><span class="name">${esc(g.name)}</span><span class="revenue mono">${eur(g.revenueCents)}</span></div>
      <div class="group-sub">${g.sharePct}% vom Umsatz${state.reportRegister === 'all' ? '' : ' dieser Kasse'} · ${g.qty}× verkauft · ${g.count} Artikel</div>
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
    </div>`).join('') || '<p style="color:var(--text-3);font-size:13px">Noch keine Verkäufe.</p>') + renderCompReport();
}

/** "Ohne Berechnung" per Bereich — deliberately its own block: none of it is in the figures above. */
function renderCompReport() {
  const comp = state.admin.comp || [];
  if (!comp.length) return '';
  const sumValue = comp.reduce((a, c) => a + c.valueCents, 0);
  return `<div class="table-head-row" style="padding:14px 0 12px">
    <div><div class="title">Ohne Berechnung</div><div class="subtitle">Nicht im Umsatz enthalten · Warenwert gesamt ${eur(sumValue)}</div></div>
  </div>` + comp.map((c) => `
    <div class="group-card">
      <div class="group-head" style="cursor:default"><span class="name">${esc(c.name)}</span><span class="revenue mono">${eur(c.valueCents)}</span></div>
      <div class="group-sub">${c.count} Buchung(en) · ${c.qty}× ausgegeben</div>
      <div class="group-stats">
        <div class="group-stat"><div class="label">Warenwert (VK)</div><div class="value">${eur(c.valueCents)}</div></div>
        <div class="group-stat"><div class="label">Wareneinsatz (EK)</div><div class="value" style="color:var(--warn-text)">${eur(c.costCents)}</div></div>
      </div>
      <div class="group-items">
        ${c.items.map((it) => `<div class="group-item-row" style="cursor:default"><span>${esc(it.name)}</span><span class="mono">${it.qty}× · ${eur(it.valueCents)}</span></div>`).join('')}
      </div>
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
      <span>${esc(p.name)}${p.sku ? `<span style="display:block;font-size:10.5px;font-weight:400;color:var(--text-3)">Art.-Nr. ${esc(p.sku)}</span>` : ''}${p.depositCents ? `<span style="display:block;font-size:10.5px;font-weight:400;color:var(--text-3)">Pfand ${eur(p.depositCents)}</span>` : ''}</span>
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
        <div style="display:flex;gap:8px">
          <button data-action="export-articles" class="btn-neutral" style="padding:11px 16px;border-radius:10px;font-size:13px;font-weight:700">Export (CSV)</button>
          <button data-action="import-articles" class="btn-neutral" style="padding:11px 16px;border-radius:10px;font-size:13px;font-weight:700">Import (CSV)</button>
          <input type="file" id="import-file-input" accept=".csv,text/csv" hidden>
          <button data-action="new-article" class="btn-primary">+ Neuer Artikel</button>
        </div>
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
  const tagStyles = { sale: 'background:var(--sale-fill);color:var(--sale-text)', in: 'background:var(--info-fill);color:var(--info-text)', out: 'background:var(--warn-fill);color:var(--warn-text)', close: 'background:var(--ink);color:#fff', delivery: 'background:var(--subtle);color:var(--text-3)', deposit_return: 'background:var(--warn-fill);color:var(--warn-text)', comp: 'background:var(--info-fill);color:var(--info-text)', void: 'background:var(--err-fill);color:var(--err-text)' };
  const tagText = { sale: 'Verkauf', in: 'Einlage', out: 'Entnahme', close: 'Z-Abschluss', delivery: 'Warenzugang', deposit_return: 'Pfand zurück', comp: 'Ohne Berechnung', void: 'Storno' };
  const rows = items.map((m) => {
    const clickable = (m.type === 'sale' || m.type === 'deposit_return' || m.type === 'comp' || m.type === 'void') && m.receiptNo;
    const voidedMark = m.saleVoided && m.type !== 'void' ? ' <span class="journal-voided">storniert</span>' : '';
    const amount = m.type === 'delivery' ? '–' : (m.amountCents > 0 ? '+' : '') + eur(m.amountCents);
    const color = m.type === 'delivery' ? 'var(--text-4)' : m.amountCents < 0 ? 'var(--warn-text)' : 'var(--ink)';
    return `<div class="journal-row ${clickable ? 'clickable' : ''}" ${clickable ? `data-action="open-receipt" data-no="${esc(m.receiptNo)}"` : ''}>
      <span class="mono">${fmtDateTimeShort(m.occurredAt)}</span>
      <span class="journal-tag" style="${tagStyles[m.type] || ''}">${tagText[m.type] || m.type}</span>
      <span class="mono journal-vorgang">${m.receiptNo ? esc(m.receiptNo) : '–'}${voidedMark}</span>
      <div>
        <div>${esc(m.note)}</div>
        ${m.itemsSummary ? `<div class="journal-items">${esc(m.itemsSummary)}</div>` : ''}
      </div>
      <span class="journal-user">${m.userName ? esc(m.userName) : '–'}</span>
      <span class="journal-amount mono" style="color:${color}">${amount}</span>
    </div>`;
  }).join('');
  return registerPicker('set-admin-register', state.adminRegisterId, false) + `<div class="table-card journal-card">
    <div class="table-head-row"><div><div class="title">Kassenbewegungen${multiRegister() ? ' · ' + esc(registerName(state.adminRegisterId)) : ''}</div><div class="subtitle">Bon antippen für Belegansicht</div></div>
      <button data-action="email-journal" class="btn-neutral" style="padding:11px 16px;border-radius:10px;font-size:13px;font-weight:700">Per E-Mail senden</button>
    </div>
    <div class="journal-cols"><span>Datum</span><span>Typ</span><span>Vorgang</span><span>Notiz</span><span>Benutzer</span><span class="num">Betrag</span></div>
    ${rows}
    ${state.admin.journalBefore ? `<div style="padding:14px;text-align:center"><button data-action="load-more-journal" class="btn-neutral" style="padding:9px 16px;border-radius:9px;font-weight:700">Weitere laden</button></div>` : ''}
  </div>`;
}

function renderCashTab() {
  const status = state.admin.cashStatus;
  const zReports = state.admin.zReports;
  if (!status || !zReports) return '<p>Lädt…</p>';
  return registerPicker('set-admin-register', state.adminRegisterId, false) + `<div class="cash-grid">
    <div class="card-box">
      <div class="cash-balance-label">Kassenbestand jetzt${multiRegister() ? ' · ' + esc(registerName(state.adminRegisterId)) : ''}</div>
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
          <div class="z-list-detail">${z.salesCount} Bons · Bar ${eur(z.cashCents)} · Karte ${eur(z.cardCents)}${z.invoiceCents ? ' · Rechnung ' + eur(z.invoiceCents) : ''}
            <button data-action="email-zreport" data-id="${z.id}" data-no="${z.no}" class="z-list-email-btn">Per E-Mail senden</button>
          </div>
        </div>`).join('')}
    </div>
  </div>`;
}

function renderSettingsTab() {
  const cfg = state.settings;
  const counts = state.admin.maintenanceCounts;
  const recoveryEmail = state.admin.recoveryEmail;
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
      <div class="settings-row" style="cursor:default">
        <div><div class="title">Berichts-E-Mail(s)</div><div class="hint">Ziel für "Per E-Mail senden" bei Journal, Auswertungen und Z-Bericht · mehrere Adressen mit Komma trennen</div></div>
        <input id="report-email-input" class="settings-text-field" type="text" placeholder="buchhaltung@example.com" value="${esc(cfg.reportEmail || '')}">
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
      <div class="form-field">
        <label>Wiederherstellungs-E-Mail (optional)</label>
        <input id="recovery-email-input" type="email" placeholder="z. B. du@example.com" value="${esc(recoveryEmail || '')}">
      </div>
      <div class="form-hint" style="margin-top:-6px">Für "Code vergessen?" im PIN-Dialog — falls hinterlegt, kommt dort ein Link zum Zurücksetzen an.</div>
      <div class="form-error" id="codes-error"></div>
      <div class="form-actions"><button data-action="save-codes" class="primary" style="flex:1">Speichern</button></div>
    </div>
    <div class="card-box" style="padding:0;margin-top:12px">
      <div class="data-summary">${counts ? `${counts.sales} Bons · ${counts.movements} Bewegungen · ${counts.products} Artikel gespeichert` : 'Lädt…'}</div>
      <div class="data-actions">
        <button data-action="clear-sales" class="btn-neutral">Verkäufe &amp; Journal löschen</button>
        <button data-action="reset-all" class="btn-danger">Alles zurücksetzen</button>
      </div>
    </div>
    <div class="card-box" style="padding:0;margin-top:12px">
      <div class="data-summary">${counts && counts.products > 0
        ? 'Demo-Daten: nur möglich, solange noch keine Artikel angelegt sind.'
        : 'Demo-Daten: 15 Beispielartikel (Speisen, Getränke, Süßes) zum Ausprobieren einspielen.'}</div>
      <div class="data-actions">
        <button data-action="seed-demo" class="btn-neutral" ${counts && counts.products === 0 ? '' : 'disabled style="opacity:.5;cursor:not-allowed"'}>Demo-Daten einspielen</button>
      </div>
    </div>
  </div>`;
}

function renderAdmin() {
  const tabs = adminTabsList();
  const tabLabels = { overview: 'Übersicht', analytics: 'Auswertungen', groups: 'Artikelgruppen', deposit: 'Pfand', comp: 'Ohne Berechnung', invoice: 'Auf Rechnung', articles: 'Artikel', stock: 'Bestand', users: 'Benutzer', registers: 'Kassen', journal: 'Journal', cash: 'Kasse', settings: 'Einstellungen' };
  let content = '';
  if (state.adminTab === 'overview') content = renderOverviewTab();
  else if (state.adminTab === 'analytics') content = renderAnalyticsTab();
  else if (state.adminTab === 'groups') content = renderGroupsTab();
  else if (state.adminTab === 'deposit') content = renderDepositTab();
  else if (state.adminTab === 'comp') content = renderCompTab();
  else if (state.adminTab === 'invoice') content = renderInvoiceTab();
  else if (state.adminTab === 'articles') content = renderArticlesTab();
  else if (state.adminTab === 'stock') content = renderStockTab();
  else if (state.adminTab === 'users') content = renderUsersTab();
  else if (state.adminTab === 'registers') content = renderRegistersTab();
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
    const reportEmailInput = document.getElementById('report-email-input');
    reportEmailInput.addEventListener('change', async () => {
      const value = reportEmailInput.value.trim();
      try {
        await guardedAdminCall(() => api('/settings', { method: 'PATCH', body: { reportEmail: value } }));
        state.settings.reportEmail = value;
        showToast('Berichts-E-Mail gespeichert');
      } catch (e) { showToast(e.message); }
    });
  }
  if (state.adminTab === 'invoice') {
    for (const [id, key] of [['invoice-from', 'invoiceFrom'], ['invoice-to', 'invoiceTo']]) {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', async () => { state.admin[key] = el.value; await loadInvoiceStatement(); renderMain(); });
    }
  }
  if (state.adminTab === 'articles') {
    const fileInput = document.getElementById('import-file-input');
    if (fileInput) fileInput.addEventListener('change', () => importArticlesFile(fileInput.files[0]));
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
  const net = rc.netCents;
  const rows = [];
  if (rc.items.length) {
    rows.push({ label: 'Summe', value: eur(rc.subtotalCents), weight: 500, size: '13px' });
    if (rc.discountCents) rows.push({ label: 'Rabatt', value: '−' + eur(rc.discountCents), weight: 500, size: '13px' });
    if (rc.depositCents) rows.push({ label: 'zzgl. Pfand', value: eur(rc.depositCents), weight: 500, size: '13px' });
  }
  if (rc.depositReturnedCents) rows.push({ label: 'Pfand-Rückgabe', value: '−' + eur(rc.depositReturnedCents), weight: 500, size: '13px' });
  if (rc.payment === 'invoice') {
    rows.push({ label: (net < 0 ? 'Gutschrift auf Rechnung · ' : 'Auf Rechnung · ') + (rc.invoiceCode || '') + (rc.invoiceName ? ' · ' + rc.invoiceName : ''), value: eur(Math.abs(net)), weight: 800, size: '17px' });
    if (rc.invoiceRef) rows.push({ label: 'Vermerk: ' + rc.invoiceRef, value: '', weight: 500, size: '12px' });
  } else if (rc.payment === 'comp') {
    rows.length = 0;
    rows.push({ label: 'Warenwert', value: eur(rc.subtotalCents), weight: 500, size: '13px' });
    rows.push({ label: 'Ohne Berechnung · ' + (rc.compName || '–'), value: eur(0), weight: 800, size: '17px' });
  } else if (net >= 0) {
    rows.push({ label: 'Zu zahlen', value: eur(net), weight: 800, size: '17px' });
    rows.push({ label: rc.payment === 'cash' ? 'Bar gegeben' : 'Kartenzahlung', value: eur(rc.givenCents), weight: 500, size: '13px' });
    if (rc.payment === 'cash') rows.push({ label: 'Rückgeld', value: eur(rc.changeCents), weight: 700, size: '14px' });
  } else {
    rows.push({ label: 'Auszahlung an Kunde', value: eur(Math.abs(net)), weight: 800, size: '17px' });
  }
  return `<div class="overlay" id="receipt-overlay">
    <div class="receipt-card">
      <div class="receipt-shop">${esc(state.shopName)}</div>
      <div class="receipt-meta">${fmtDateTime(rc.soldAt)} · Bon ${esc(rc.receiptNo)}${multiRegister() && rc.registerName ? ' · ' + esc(rc.registerName) : ''}</div>
      ${rc.voidedAt ? `<div class="receipt-void"><div class="title">Storniert · ${fmtDateTime(rc.voidedAt)}</div><div>${esc(rc.voidReason || '')}</div></div>` : ''}
      <hr class="receipt-divider">
      ${rc.items.map((i) => `<div class="receipt-line"><span class="qty mono">${i.qty}×</span><span class="name">${esc(i.name)}</span><span class="mono">${eur(i.unitCents * i.qty)}</span></div>${i.depositCents ? `<div class="receipt-line" style="color:var(--text-3);font-size:11.5px"><span class="qty"></span><span class="name">davon Pfand ${i.qty}× ${eur(i.depositCents)}</span><span class="mono">${eur(i.depositCents * i.qty)}</span></div>` : ''}`).join('')}
      ${rc.returns.map((r) => `<div class="receipt-line" style="color:var(--warn-text)"><span class="qty mono">${r.qty}×</span><span class="name">↩ Pfand zurück · ${esc(r.name)}${r.credited ? ' (Gutschrift)' : ''}</span><span class="mono">−${eur(r.amountCents)}</span></div>`).join('')}
      <hr class="receipt-divider">
      ${rows.map((r) => `<div class="receipt-totals-row" style="font-weight:${r.weight};font-size:${r.size}"><span>${esc(r.label)}</span><span class="mono">${r.value}</span></div>`).join('')}
      <div class="receipt-footer"><div class="thanks">Vielen Dank für Ihren Einkauf</div>${state.view === 'admin' && canUseAdmin() && rc.voidable ? `<button data-action="void-receipt" data-no="${esc(rc.receiptNo)}" class="receipt-void-btn">Bon stornieren</button>` : ''}<button data-action="close-receipt">Weiter</button></div>
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
  } else if (f.kind === 'void') {
    title = 'Bon stornieren · ' + f.receiptNo;
    hint = 'Der Bon zählt danach nicht mehr zum Umsatz, der Bestand wird zurückgebucht und die Kasse per Gegenbuchung korrigiert. Der Bon bleibt im Journal sichtbar.';
    fields = `<div class="form-field"><label>Grund</label><textarea data-form-key="reason" rows="2" maxlength="255" placeholder="z. B. Falsch getippt, Kunde hat storniert">${esc(f.reason || '')}</textarea></div>`;
    submitLabel = 'Stornieren';
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
    fields += F('Artikelnummer (optional)', 'sku', 'z.B. 10023');
    fields += `<div class="form-field"><label>Artikelgruppe</label><select data-form-key="category" ${cats.length ? '' : 'disabled'}>${catOptions}</select></div>`;
    fields += F('Verkaufspreis (€)', 'price', '3,50') + F('Einkaufspreis (€)', 'cost', '1,20');
    const depositTypes = state.admin.depositTypes || [];
    const depositOptions = '<option value="">Kein Pfand</option>' + depositTypes.map((t) => `<option value="${t.id}" ${f.depositTypeId === t.id ? 'selected' : ''}>${esc(t.name)} · ${eur(t.amountCents)}</option>`).join('');
    fields += `<div class="form-field"><label>Pfand</label><select data-form-key="depositTypeId">${depositOptions}</select></div>`;
    if (!depositTypes.length) hint = 'Lege zuerst unter "Pfand" mindestens eine Option an, falls dieser Artikel Pfand haben soll.';
    if (state.settings.trackStock) {
      fields += `<div class="settings-row" data-action="toggle-form-trackstock" style="padding:12px 0;cursor:pointer">
        <div><div class="title" style="font-size:13px;font-weight:600">Lagerbestand für diesen Artikel führen</div><div class="hint" style="font-size:11px;color:var(--text-3)">Aus, wenn dieser Artikel nicht gezählt werden soll (z. B. Fassbier)</div></div>
        <div class="switch-track ${f.trackStock ? 'on' : ''}"><div class="switch-knob"></div></div>
      </div>`;
      if (f.trackStock) fields += F('Bestand', 'stock', '100') + F('Meldebestand', 'stockMin', '20');
    }
    if (!cats.length) hint = 'Erst unter "Artikelgruppen" mindestens eine Gruppe anlegen, bevor du einen Artikel speichern kannst.';
  } else if (f.kind === 'reset-code') {
    title = 'Zugangscode zurücksetzen';
    hint = 'Neuen Zugangscode festlegen. Löschkennwort nur ändern, wenn nötig.';
    fields = F('Neuer Zugangscode', 'accessCode', 'z. B. 1234') + F('Neues Löschkennwort (optional)', 'deleteCode', 'unverändert lassen');
    submitLabel = 'Zurücksetzen';
  } else if (f.kind === 'category') {
    title = f.id ? 'Gruppe bearbeiten' : 'Neue Artikelgruppe';
    fields = F('Name', 'name', 'z. B. Merchandise');
    if (!f.id) {
      fields += `<div class="settings-row" data-action="toggle-form-trackstockdefault" style="padding:12px 0;cursor:pointer">
        <div><div class="title" style="font-size:13px;font-weight:600">Lagerbestand für neue Artikel dieser Gruppe</div><div class="hint" style="font-size:11px;color:var(--text-3)">Vorbelegung, pro Artikel jederzeit änderbar</div></div>
        <div class="switch-track ${f.trackStockDefault ? 'on' : ''}"><div class="switch-knob"></div></div>
      </div>`;
    }
    const depositTypes = state.admin.depositTypes || [];
    const depositOptions = '<option value="">Kein Pfand</option>' + depositTypes.map((t) => `<option value="${t.id}" ${f.depositTypeId === t.id ? 'selected' : ''}>${esc(t.name)} · ${eur(t.amountCents)}</option>`).join('');
    fields += `<div class="form-field"><label>Pfand-Standard für neue Artikel</label><select data-form-key="depositTypeId">${depositOptions}</select></div>`;
    if (f.id) {
      fields += `<button type="button" data-action="apply-deposit-type-now" class="btn-neutral" style="width:100%;padding:11px;border-radius:10px;font-size:13px;font-weight:700;margin-top:-4px">Jetzt auf alle ${f.productCount || 0} bestehenden Artikel dieser Gruppe anwenden</button>`;
    }
    hint = depositTypes.length ? 'Neue Artikel dieser Gruppe übernehmen diese Auswahl automatisch, bleibt pro Artikel änderbar. Bestehende Artikel bleiben unangetastet, bis du den Button oben nutzt.' : 'Lege zuerst unter "Pfand" mindestens eine Option an, um sie hier zuzuweisen.';
    submitLabel = f.id ? 'Speichern' : 'Anlegen';
  } else if (f.kind === 'comp-account') {
    title = f.id ? 'Bereich bearbeiten' : 'Neuer Bereich ohne Berechnung';
    hint = f.id ? 'Der neue Name gilt für künftige Buchungen; bereits gebuchte behalten den alten.' : 'Wer bekommt Ware, ohne zu zahlen? Z. B. Band, Helfer, Ehrengäste.';
    fields = F('Name', 'name', 'z. B. Band');
    submitLabel = f.id ? 'Speichern' : 'Anlegen';
  } else if (f.kind === 'invoice-account') {
    title = f.id ? 'Firma bearbeiten' : 'Neue Firma auf Rechnung';
    hint = f.id ? 'Änderungen gelten für künftige Buchungen; bereits gebuchte Bons behalten Kürzel und Namen.' : 'Das Kürzel erscheint als Button in der Kasse — kurz halten, z. B. BRW.';
    fields = F('Kürzel (max. 12 Zeichen)', 'code', 'z. B. BRW') + F('Firmenname', 'name', 'z. B. Brauerei Weber GmbH');
    fields += `<div class="form-field"><label>Anschrift (für die Rechnung, optional)</label><textarea data-form-key="address" rows="3" maxlength="1000" placeholder="Straße&#10;PLZ Ort">${esc(f.address || '')}</textarea></div>`;
    fields += F('Notiz (optional)', 'note', 'z. B. Ansprechpartner, Kundennummer');
    submitLabel = f.id ? 'Speichern' : 'Anlegen';
  } else if (f.kind === 'invoice-bill') {
    title = 'Als abgerechnet markieren';
    hint = `${f.count} Bon(s) über ${eur(f.totalCents)} für ${f.accountLabel}. Danach stehen sie nicht mehr unter „Offen“ und können nicht mehr storniert werden. Rücknahme ist pro Bon möglich.`;
    fields = F('Rechnungsnummer (optional)', 'billedRef', 'z. B. RE-2026-017');
    submitLabel = 'Abgerechnet';
  } else if (f.kind === 'deposit-type') {
    title = f.id ? 'Pfand-Option bearbeiten' : 'Neue Pfand-Option';
    fields = F('Name', 'name', 'z. B. Krug') + F('Betrag (€)', 'amount', '3,00');
    submitLabel = f.id ? 'Speichern' : 'Anlegen';
  } else if (f.kind === 'user') {
    title = f.id ? 'Benutzer bearbeiten' : 'Neuer Benutzer';
    const cats = state.admin.categories || [];
    fields = F('Name', 'name', 'z.B. Merch-Stand');
    fields += `<div class="form-field"><label>Rolle</label><select data-form-key="role">
      <option value="cashier" ${f.role === 'cashier' ? 'selected' : ''}>Kassierer · nur zugewiesene Artikelgruppen</option>
      <option value="admin" ${f.role === 'admin' ? 'selected' : ''}>Admin · sieht und bucht alles</option>
    </select></div>`;
    fields += F(f.id ? 'Neuer PIN (leer = unverändert)' : 'PIN (4-stellig)', 'pin', '1234', 'password');
    if (f.role === 'cashier') {
      fields += `<div class="form-field"><label>Sichtbare Artikelgruppen</label>
        <div class="checkbox-list">${cats.length
          ? cats.map((c) => `<label class="checkbox-row"><input type="checkbox" data-action="toggle-form-category" data-cat-id="${c.id}" ${(f.categoryIds || []).includes(c.id) ? 'checked' : ''}> ${esc(c.name)}</label>`).join('')
          : '<div class="hint">Noch keine Artikelgruppe angelegt.</div>'}</div>
      </div>`;
    }
    if (f.id) {
      fields += `<div class="settings-row" data-action="toggle-form-active" style="padding:12px 0;cursor:pointer">
        <div><div class="title" style="font-size:13px;font-weight:600">Aktiv</div><div class="hint" style="font-size:11px;color:var(--text-3)">Deaktivierte Benutzer können sich an der Kasse nicht mehr anmelden</div></div>
        <div class="switch-track ${f.active ? 'on' : ''}"><div class="switch-knob"></div></div>
      </div>`;
    }
    submitLabel = f.id ? 'Speichern' : 'Anlegen';
  } else if (f.kind === 'register') {
    title = f.id ? 'Kasse bearbeiten' : 'Neue Kasse';
    const cats = state.admin.categories || [];
    fields = F('Name', 'name', 'z. B. Kasse 2 · Merch');
    fields += `<div class="form-field"><label>Verkauft diese Artikelgruppen</label>
      <div class="checkbox-list">${cats.length
        ? cats.map((c) => `<label class="checkbox-row"><input type="checkbox" data-action="toggle-form-category" data-cat-id="${c.id}" ${(f.categoryIds || []).includes(c.id) ? 'checked' : ''}> ${esc(c.name)}</label>`).join('')
        : '<div class="hint">Noch keine Artikelgruppe angelegt.</div>'}</div>
    </div>`;
    hint = 'Keine Gruppe angehakt = diese Kasse verkauft alle Artikel. Welche Kasse ein Gerät ist, legst du danach mit „Diesem Gerät zuordnen“ fest.';
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
      <div style="margin-top:14px"><button data-action="forgot-code" style="font-size:12px;font-weight:600;color:var(--text-3);text-decoration:underline">Code vergessen?</button></div>
    </div>
  </div>`;
}

function renderPosPinOverlay() {
  const user = state.posUsers.find((u) => u.id === state.posSelectedUserId);
  const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'x', '0', 'del'];
  return `<div class="overlay">
    <div class="pin-card">
      <div class="pin-title">${esc(user ? user.name : 'Anmelden')}</div>
      <div class="pin-sub">PIN eingeben</div>
      <div class="pin-dots">${[0, 1, 2, 3].map((i) => `<div class="pin-dot ${i < state.posPinBuf.length ? 'filled' : ''}"></div>`).join('')}</div>
      <div class="pin-error">${esc(state.posPinError)}</div>
      <div class="pin-keys">${keys.map((k) => `<button data-action="pos-pin-press" data-key="${k}" class="${k === 'x' || k === 'del' ? 'ghost' : ''}">${k === 'del' ? '⌫' : k === 'x' ? 'Abbr.' : k}</button>`).join('')}</div>
    </div>
  </div>`;
}

function renderOverlay() {
  const el = document.getElementById('overlay-root');
  if (state.receipt) el.innerHTML = renderReceiptOverlay();
  else if (state.form) el.innerHTML = renderFormOverlay();
  else if (state.showPin) el.innerHTML = renderPinOverlay();
  else if (state.showPosPin) el.innerHTML = renderPosPinOverlay();
  else el.innerHTML = '';

  if (state.form) {
    el.querySelectorAll('[data-form-key]').forEach((input) => {
      const evt = input.tagName === 'SELECT' ? 'change' : 'input';
      input.addEventListener(evt, () => {
        state.form[input.dataset.formKey] = input.value;
        // New article: prefill the per-article stock/Pfand defaults from the chosen group.
        if (input.dataset.formKey === 'category' && state.form.kind === 'article' && !state.form.id) {
          const cat = (state.admin.categories || []).find((c) => c.name === input.value);
          if (cat) {
            state.form.trackStock = cat.trackStockDefault;
            state.form.depositTypeId = cat.depositTypeId;
            renderOverlay();
          }
        }
        if (input.dataset.formKey === 'role' && state.form.kind === 'user') {
          renderOverlay();
        }
        if (input.dataset.formKey === 'depositTypeId' && (state.form.kind === 'category' || state.form.kind === 'article')) {
          state.form.depositTypeId = input.value === '' ? null : Number(input.value);
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
    case 'set-payment':
      state.payment = d.value;
      if (d.value !== 'cash') state.tender = 0;
      // The Bereich is picked fresh for every booking, unless there's only one to pick from.
      state.compAccountId = d.value === 'comp' && state.compAccounts.length === 1 ? state.compAccounts[0].id : null;
      state.invoiceAccountId = d.value === 'invoice' && state.invoiceAccounts.length === 1 ? state.invoiceAccounts[0].id : null;
      if (d.value !== 'invoice') state.invoiceRef = '';
      return renderMain();
    case 'set-comp-account': state.compAccountId = Number(d.id); return renderMain();
    case 'set-invoice-account': state.invoiceAccountId = Number(d.id); return renderMain();
    case 'add-tender': state.tender += Number(d.value); return renderMain();
    case 'exact-amount': state.tender = totals().total; return renderMain();
    case 'clear-tender': state.tender = 0; return renderMain();
    case 'checkout': return void checkout();

    case 'close-receipt': state.receipt = null; return renderOverlay();
    case 'void-receipt': state.receipt = null; return openForm({ kind: 'void', receiptNo: d.no, reason: '' });
    case 'close-form': return closeForm();
    case 'submit-form': return void submitForm();
    case 'pin-press': return void pinPress(d.key);
    case 'pos-pick-user': state.posSelectedUserId = Number(d.id); state.showPosPin = true; state.posPinBuf = ''; state.posPinError = ''; return renderOverlay();
    case 'pos-pin-press': return void posPinPress(d.key);
    case 'pos-switch-user': return void posSwitchUser();
    case 'forgot-code': return void api('/access/forgot', { method: 'POST' })
      .then(() => showToast('E-Mail verschickt · Link ist 30 Minuten gültig'))
      .catch((e) => showToast(e.message));

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
    case 'new-category': return openForm({ kind: 'category', name: '', trackStockDefault: true, depositTypeId: null });
    case 'rename-category': {
      const c = (state.admin.categories || []).find((x) => x.id === Number(d.id));
      return openForm({ kind: 'category', id: c.id, name: c.name, depositTypeId: c.depositTypeId, productCount: c.productCount });
    }
    case 'toggle-category-default': {
      const c = (state.admin.categories || []).find((x) => x.id === Number(d.id));
      return void guardedAdminCall(() => api('/categories/' + c.id, { method: 'PATCH', body: { trackStockDefault: !c.trackStockDefault } }))
        .then(() => loadCategories())
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
        await loadCategories();
        renderMain();
      },
    });
    case 'toggle-form-trackstockdefault': state.form.trackStockDefault = !state.form.trackStockDefault; return renderOverlay();
    case 'apply-deposit-type-now': {
      const depositTypeId = state.form.depositTypeId || null;
      return void guardedAdminCall(() => api('/categories/' + state.form.id + '/apply-deposit-type', { method: 'POST', body: { depositTypeId } }))
        .then((res) => {
          if (!res) return;
          showToast(res.updated + ' Artikel aktualisiert');
          // state.products (what the Kasse actually sells at) only comes from loadBootstrap() —
          // loadAdminProducts()/loadCategories() alone left the POS showing stale Pfand until a
          // manual reload, even though the Verwaltung's own Artikel list looked correct.
          return Promise.all([loadBootstrap(), loadAdminProducts(), loadCategories()]);
        })
        .then(() => { renderHeader(); renderMain(); })
        .catch((e) => showToast(e.message));
    }
    case 'new-deposit-type': return openForm({ kind: 'deposit-type', name: '', amount: '' });
    case 'edit-deposit-type': {
      const t = (state.admin.depositTypes || []).find((x) => x.id === Number(d.id));
      if (!t) return;
      return openForm({ kind: 'deposit-type', id: t.id, name: t.name, amount: String(t.amountCents / 100).replace('.', ',') });
    }
    case 'delete-deposit-type': return openForm({
      kind: 'confirm', noCode: true, title: 'Pfand-Option löschen · ' + d.name,
      hint: 'Nur möglich, solange keine Artikelgruppe und kein Artikel diese Option mehr verwendet.',
      submitLabel: 'Pfand-Option löschen',
      action: async () => {
        await api('/deposit-types/' + d.id, { method: 'DELETE' });
        showToast('Pfand-Option gelöscht');
        await loadDepositTypes();
        renderMain();
      },
    });
    case 'new-invoice-account': return openForm({ kind: 'invoice-account', code: '', name: '', address: '', note: '' });
    case 'edit-invoice-account': {
      const a = (state.admin.invoiceAccounts || []).find((x) => x.id === Number(d.id));
      if (!a) return;
      return openForm({ kind: 'invoice-account', id: a.id, code: a.code, name: a.name, address: a.address, note: a.note });
    }
    case 'delete-invoice-account': return openForm({
      kind: 'confirm', noCode: true, title: 'Firma löschen · ' + d.name,
      hint: 'Die Firma verschwindet aus der Kasse. Abgerechnete Bons bleiben in Journal und Auswertung. Geht nur, wenn nichts mehr offen ist.',
      submitLabel: 'Firma löschen',
      action: async () => {
        await api('/invoice-accounts/' + d.id, { method: 'DELETE' });
        showToast('Firma gelöscht');
        await Promise.all([loadInvoiceAccounts(), loadBootstrap()]);
        renderMain();
      },
    });
    case 'open-invoice-statement':
      state.admin.invoiceSel = Number(d.id); state.admin.invoiceStatus = 'open'; state.admin.invoiceFrom = ''; state.admin.invoiceTo = ''; state.admin.invoiceStatement = null;
      renderMain();
      return void loadInvoiceStatement().then(renderMain);
    case 'close-invoice-statement': state.admin.invoiceSel = null; state.admin.invoiceStatement = null; return void loadInvoiceTab();
    case 'set-invoice-status': state.admin.invoiceStatus = d.value; return void loadInvoiceStatement().then(renderMain);
    case 'bill-invoice': {
      const st = state.admin.invoiceStatement;
      if (!st) return;
      const open = st.sales.filter((s) => !s.billedAt);
      return openForm({ kind: 'invoice-bill', accountId: st.account.id, accountLabel: st.account.code + ' · ' + st.account.name, saleIds: open.map((s) => s.id),
        count: open.length, totalCents: open.reduce((s, x) => s + x.totalCents, 0), billedRef: '' });
    }
    case 'unbill-invoice': return openForm({
      kind: 'confirm', noCode: true, title: 'Abrechnung zurücknehmen · Bon ' + d.no,
      hint: 'Der Bon steht danach wieder unter „Offen“ und kommt auf die nächste Abrechnung.',
      submitLabel: 'Zurücknehmen',
      action: async () => {
        await api('/invoice-accounts/' + state.admin.invoiceSel + '/unbill', { method: 'POST', body: { saleIds: [Number(d.id)] } });
        showToast('Bon ' + d.no + ' wieder offen');
        await Promise.all([loadInvoiceAccounts(), loadInvoiceStatement()]);
        renderMain();
      },
    });
    case 'new-comp-account': return openForm({ kind: 'comp-account', name: '' });
    case 'edit-comp-account': {
      const a = (state.admin.compAccounts || []).find((x) => x.id === Number(d.id));
      if (!a) return;
      return openForm({ kind: 'comp-account', id: a.id, name: a.name });
    }
    case 'delete-comp-account': return openForm({
      kind: 'confirm', noCode: true, title: 'Bereich löschen · ' + d.name,
      hint: 'Der Bereich verschwindet aus der Kasse. Bereits gebuchte Vorgänge bleiben in Journal und Auswertung erhalten.',
      submitLabel: 'Bereich löschen',
      action: async () => {
        await api('/comp-accounts/' + d.id, { method: 'DELETE' });
        showToast('Bereich gelöscht');
        await Promise.all([loadCompAccounts(), loadBootstrap()]);
        renderMain();
      },
    });
    case 'set-admin-register': state.adminRegisterId = Number(d.id); return void enterAdminTab(state.adminTab);
    case 'set-report-register': state.reportRegister = d.id === 'all' ? 'all' : Number(d.id); return void enterAdminTab(state.adminTab);
    case 'new-register': return openForm({ kind: 'register', name: '', categoryIds: [] });
    case 'edit-register': {
      const r = (state.admin.registers || []).find((x) => x.id === Number(d.id));
      if (!r) return;
      return openForm({ kind: 'register', id: r.id, name: r.name, categoryIds: r.categoryIds.slice() });
    }
    case 'delete-register': return openForm({
      kind: 'confirm', noCode: true, title: 'Kasse löschen · ' + d.name,
      hint: 'Nur möglich, solange auf dieser Kasse noch nichts gebucht wurde.',
      submitLabel: 'Kasse löschen',
      action: async () => {
        await api('/registers/' + d.id, { method: 'DELETE' });
        showToast('Kasse gelöscht');
        await Promise.all([loadBootstrap(), loadRegisters()]);
        renderHeader(); renderMain();
      },
    });
    case 'assign-device-register': return void guardedAdminCall(() => api('/device/register', { method: 'POST', body: { registerId: Number(d.id) } }))
      .then(async (res) => {
        if (!res) return;
        // The cart may hold articles this Kasse doesn't sell — start clean.
        state.cart = []; state.tender = 0; state.discount = 0; state.selected = null;
        state.adminRegisterId = res.id;
        showToast('Dieses Gerät ist jetzt ' + res.name);
        await Promise.all([loadBootstrap(), loadRegisters()]);
        renderHeader(); renderMain();
      })
      .catch((e) => showToast(e.message));
    case 'new-user': return openForm({ kind: 'user', name: '', role: 'cashier', pin: '', categoryIds: [], active: true });
    case 'edit-user': {
      const u = state.admin.users.find((x) => x.id === Number(d.id));
      if (!u) return;
      return openForm({ kind: 'user', id: u.id, name: u.name, role: u.role, pin: '', categoryIds: u.categoryIds.slice(), active: u.active });
    }
    case 'toggle-form-category': {
      const cid = Number(d.catId);
      const list = state.form.categoryIds || (state.form.categoryIds = []);
      const idx = list.indexOf(cid);
      if (idx >= 0) list.splice(idx, 1); else list.push(cid);
      return;
    }
    case 'toggle-form-active': state.form.active = !state.form.active; return renderOverlay();
    case 'delete-user': return openForm({
      kind: 'confirm', code: '', title: 'Benutzer löschen · ' + d.name,
      hint: 'Der Benutzer kann sich danach nicht mehr an der Kasse anmelden.',
      submitLabel: 'Benutzer löschen',
      action: async (code) => {
        await api('/users/' + d.id, { method: 'DELETE', body: { deleteCode: code } });
        showToast('Benutzer gelöscht');
        await reloadUsersAndBootstrap();
        renderHeader();
        kickOutIfVerwaltungNowLocked();
        renderMain();
      },
    });
    case 'export-articles': window.location.href = '/api/products/export'; return;
    case 'import-articles': return void document.getElementById('import-file-input').click();
    case 'new-article': {
      const cats = state.admin.categories || [];
      const defaultCat = cats[0];
      const f = { kind: 'article', name: '', sku: '', category: defaultCat ? defaultCat.name : '', price: '', cost: '', depositTypeId: defaultCat ? defaultCat.depositTypeId : null };
      if (state.settings.trackStock) { f.trackStock = defaultCat ? defaultCat.trackStockDefault : true; f.stock = '0'; f.stockMin = '10'; }
      return openForm(f);
    }
    case 'edit-article': {
      const p = state.admin.products.find((x) => x.id === Number(d.id));
      const f = { kind: 'article', id: p.id, name: p.name, sku: p.sku || '', category: p.category, price: String(p.priceCents / 100).replace('.', ','), cost: String(p.costCents / 100).replace('.', ','), depositTypeId: p.depositTypeId };
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
    case 'email-journal': return void guardedAdminCall(() => api('/journal/email', { method: 'POST', body: { registerId: state.adminRegisterId } }))
      .then((res) => { if (res) showToast('Journal per E-Mail gesendet'); })
      .catch((e) => showToast(e.message));
    case 'email-reports': return void guardedAdminCall(() => api('/reports/email', { method: 'POST', body: { registerId: state.reportRegister } }))
      .then((res) => { if (res) showToast('Auswertungen per E-Mail gesendet'); })
      .catch((e) => showToast(e.message));
    case 'email-zreport': return void guardedAdminCall(() => api('/z-reports/' + d.id + '/email', { method: 'POST' }))
      .then((res) => { if (res) showToast('Z-' + d.no + ' per E-Mail gesendet'); })
      .catch((e) => showToast(e.message));
    case 'open-cash-in': return openForm({ kind: 'in', amount: '', note: '' });
    case 'open-cash-out': return openForm({ kind: 'out', amount: '', note: '' });
    case 'add-deposit-return': return addDepositReturnToCart(Number(d.id));
    case 'close-day': return void guardedAdminCall(() => api('/z-reports', { method: 'POST', body: { registerId: state.adminRegisterId } }))
      .then((res) => { if (!res) return; showToast((multiRegister() ? registerName(state.adminRegisterId) + ' · ' : '') + 'Z-' + res.no + ' gebucht · ' + eur(res.totalCents) + ' Umsatz'); return Promise.all([loadBootstrap(), loadCashStatus()]); })
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
      const emailInput = document.getElementById('recovery-email-input');
      const errEl = document.getElementById('codes-error');
      const accessCode = accessInput.value.trim();
      const deleteCode = deleteInput.value.trim();
      const recoveryEmail = emailInput.value.trim();
      if (!accessCode && !deleteCode && recoveryEmail === (state.admin.recoveryEmail || '')) { errEl.textContent = 'Nichts zu speichern.'; return; }
      if (accessCode && deleteCode && accessCode === deleteCode) { errEl.textContent = 'Zugangscode und Löschkennwort müssen unterschiedlich sein.'; return; }
      const body = { recoveryEmail };
      if (accessCode) body.accessCode = accessCode;
      if (deleteCode) body.deleteCode = deleteCode;
      return void api('/settings/codes', { method: 'PATCH', body })
        .then(async () => {
          accessInput.value = ''; deleteInput.value = ''; errEl.textContent = '';
          state.access.codeConfigured = true;
          state.admin.recoveryEmail = recoveryEmail;
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
      hint: 'Artikel, Verkäufe, Journal und Abschlüsse werden unwiderruflich gelöscht. Einstellungen, Artikelgruppen und Benutzer bleiben.',
      submitLabel: 'Alles zurücksetzen',
      action: async (code) => {
        await api('/maintenance/reset', { method: 'POST', body: { deleteCode: code } });
        showToast('Alles zurückgesetzt');
        await Promise.all([loadBootstrap(), loadMaintenanceCounts()]);
        renderHeader(); renderMain();
      },
    });
    case 'seed-demo':
      return guardedAdminCall(() => api('/maintenance/demo', { method: 'POST' }))
        .then(async (res) => {
          if (!res) return;
          showToast('Demo-Daten eingespielt');
          await Promise.all([loadBootstrap(), loadMaintenanceCounts()]);
          renderHeader(); renderMain();
        })
        .catch((e) => showToast(e.message));
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

  const resetToken = new URLSearchParams(location.search).get('reset');
  if (resetToken) {
    openForm({ kind: 'reset-code', token: resetToken, accessCode: '', deleteCode: '' });
  }
}

mount();

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
