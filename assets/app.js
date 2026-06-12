const contentSelector = '#dashboard-content';
let loading = false;
let analyticsPollTimer = null;
let analyticsCharts = {};
let analyticsRequest = null;
let toastStack = null;
const variantSubtypeOptions = {
  'Solo Account': ['Solo', 'Famhead'],
  'Shared Account': ['Invite', 'Shared Profile', 'Solo Profile', 'Solo Profile 1 Device', 'Solo Profile 2 Devices'],
};

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function ensureToastStack() {
  if (toastStack && document.body.contains(toastStack)) return toastStack;
  toastStack = document.querySelector('[data-toast-stack]');
  if (!toastStack) {
    toastStack = document.createElement('div');
    toastStack.className = 'toast-stack';
    toastStack.setAttribute('data-toast-stack', '');
    document.body.appendChild(toastStack);
  }
  return toastStack;
}

function toastMeta(type) {
  if (type === 'error') {
    return { title: 'Oops!', icon: 'assets/cat7-removebg-preview.png' };
  }
  if (type === 'success') {
    return { title: 'Purrfect!', icon: 'assets/cat2-removebg-preview.png' };
  }
  return { title: 'Heads up', icon: 'assets/cat1-removebg-preview.png' };
}

function showToast(message, type = 'info') {
  const text = String(message || '').trim();
  if (!text) return;

  const stack = ensureToastStack();
  const toast = document.createElement('article');
  toast.className = `toast toast-${type}`;
  const { title, icon } = toastMeta(type);
  toast.innerHTML = `
    <img class="toast-cat" src="${icon}" alt="" aria-hidden="true">
    <div class="toast-copy">
      <strong>${escapeHtml(title)}</strong>
      <span>${escapeHtml(text)}</span>
    </div>
    <button class="toast-close" type="button" aria-label="Dismiss notification">&times;</button>
  `;
  const close = () => {
    if (toast.classList.contains('leaving')) return;
    toast.classList.add('leaving');
    window.setTimeout(() => toast.remove(), 220);
  };
  toast.querySelector('.toast-close')?.addEventListener('click', close);
  stack.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));
  window.setTimeout(close, 4200);
}

function promoteToasts(root) {
  if (!root) return;
  root.querySelectorAll('[data-toast-source]').forEach((source) => {
    showToast(source.textContent, source.dataset.toastType || 'info');
    source.remove();
  });
}

function updateProfitPreview(form) {
  const price = Number(form?.querySelector('[data-sell-price-input]')?.value || 0);
  const cost = Number(form?.querySelector('[data-cost-price-input]')?.value || 0);
  const preview = form?.querySelector('[data-profit-preview]');
  if (preview) preview.textContent = formatCurrency(price - cost);
}

function renderSubtypeOptions(form, selectedType = '', selectedSubtype = '') {
  const subtypeSelect = form?.querySelector('[data-variant-subtype]');
  if (!subtypeSelect) return;
  const options = variantSubtypeOptions[selectedType] || [];
  subtypeSelect.innerHTML = '<option value="">SubType...</option>' + options.map((option) => (
    `<option value="${option}" ${option === selectedSubtype ? 'selected' : ''}>${option}</option>`
  )).join('');
  subtypeSelect.disabled = !options.length;
}

function syncInventoryForm(form, preset = {}) {
  if (!form) return;
  const itemIdInput = form.querySelector('[data-item-id]');
  const productNameInput = form.querySelector('[data-product-name-input]');
  const categorySelect = form.querySelector('[data-category-select]');
  const typeSelect = form.querySelector('[data-variant-type]');
  const subtypeSelect = form.querySelector('[data-variant-subtype]');
  const stockInput = form.querySelector('input[name="stock"]');
  const sellPriceInput = form.querySelector('[data-sell-price-input]');
  const costPriceInput = form.querySelector('[data-cost-price-input]');
  const submitLabel = form.querySelector('[data-submit-label]');
  const title = form.closest('.modal')?.querySelector('[data-inventory-modal-title]');
  const netflixOnly = form.querySelector('[data-netflix-only]');
  const slotBuilder = form.querySelector('[data-slot-builder]');
  const slotCountInput = form.querySelector('[data-slot-count]');
  const accountEmailInput = form.querySelector('[data-account-email-input]');
  const accountPasswordInput = form.querySelector('[data-account-password-input]');
  const notesInput = form.querySelector('[data-notes-input]');

  if (itemIdInput) itemIdInput.value = preset.itemId || '';
  if (productNameInput) productNameInput.value = preset.productName || '';
  if (categorySelect && preset.category) categorySelect.value = preset.category;
  if (typeSelect) typeSelect.value = preset.variantType || '';
  renderSubtypeOptions(form, typeSelect?.value || '', preset.variantSubtype || '');
  if (subtypeSelect && preset.variantSubtype) subtypeSelect.value = preset.variantSubtype;
  if (stockInput && preset.stock !== undefined) stockInput.value = preset.stock;
  if (sellPriceInput && preset.sellPrice !== undefined) sellPriceInput.value = preset.sellPrice;
  if (costPriceInput && preset.costPrice !== undefined) costPriceInput.value = preset.costPrice;
  if (accountEmailInput) accountEmailInput.value = preset.accountEmail || '';
  if (accountPasswordInput) accountPasswordInput.value = preset.accountPassword || '';
  if (notesInput) notesInput.value = preset.notes || '';
  if (submitLabel) submitLabel.textContent = preset.itemId ? 'Save Changes' : 'Save Variant';
  if (title) title.textContent = preset.itemId ? (preset.modalTitle || 'Edit Variant') : (preset.modalTitle || (preset.mode === 'product' ? 'Add Product' : 'Add Variant'));

  const isShared = String(preset.variantType || '').toLowerCase().includes('shared');
  if (netflixOnly) netflixOnly.hidden = !isShared;
  if (slotCountInput) {
    slotCountInput.disabled = !isShared;
    slotCountInput.value = preset.slotValues?.length || 0;
  }
  if (slotBuilder) {
    if (isShared) {
      renderProductSlots(slotBuilder, preset.slotValues?.length || 0, (preset.slotValues || []).map((s) => ({ name: s.label, pin: s.pin_code })));
    } else {
      slotBuilder.innerHTML = '';
    }
  }
  updateProfitPreview(form);
}

function populateSaleProducts(category, selectedProduct = '') {
  const select = document.querySelector('[data-sale-product]');
  if (!select) return;
  const products = [...new Set((getSaleItems()).filter((i) => i.category === category).map((i) => i.product))];
  select.innerHTML = '<option value="">Select product...</option>' + products.map((p) => (
    `<option value="${p}" ${p === selectedProduct ? 'selected' : ''}>${p}</option>`
  )).join('');
  select.disabled = !products.length;
}

function populateSaleTypes(category, product, selectedType = '') {
  const select = document.querySelector('[data-sale-type]');
  if (!select) return;
  const types = [...new Set((getSaleItems()).filter((i) => i.category === category && i.product === product && i.type).map((i) => i.type))];
  select.innerHTML = '<option value="">Type...</option>' + types.map((t) => (
    `<option value="${t}" ${t === selectedType ? 'selected' : ''}>${t}</option>`
  )).join('');
  select.disabled = !types.length;
}

function populateSaleSubtypes(category, product, type, selectedSubtype = '') {
  const select = document.querySelector('[data-sale-subtype]');
  if (!select) return;
  const subtypes = [...new Set((getSaleItems()).filter((i) => i.category === category && i.product === product && i.type === type && i.subtype).map((i) => i.subtype))];
  select.innerHTML = '<option value="">SubType...</option>' + subtypes.map((s) => (
    `<option value="${s}" ${s === selectedSubtype ? 'selected' : ''}>${s}</option>`
  )).join('');
  select.disabled = !subtypes.length;
}

function updateSaleProfitPreview() {
  const price = Number(document.querySelector('[data-sale-price]')?.value || 0);
  const cost = Number(document.querySelector('[data-sale-cost]')?.value || 0);
  const qty = Number(document.querySelector('[data-sale-qty]')?.value || 1);
  const discount = Number(document.querySelector('[data-sale-discount]')?.value || 0);
  const preview = document.querySelector('[data-sale-profit-preview]');
  if (preview) preview.textContent = formatCurrency((price * qty - discount) - (cost * qty));
  updateSaleStockHint(qty);
}

function updateSaleStockHint(qty) {
  const hint = document.querySelector('[data-sale-stock-hint]');
  const qtyInput = document.querySelector('[data-sale-qty]');
  const stock = Number(qtyInput?.max || 0);
  if (!hint || !stock) {
    if (hint) hint.hidden = true;
    return;
  }
  const remaining = Math.max(0, stock - (qty || 0));
  hint.hidden = false;
  hint.textContent = `Will deduct ${qty || 0} slot${qty === 1 ? '' : 's'} · ${remaining} remaining after`;
}

function resolveSaleItem() {
  const category = document.querySelector('[data-sale-category]')?.value || '';
  const product = document.querySelector('[data-sale-product]')?.value || '';
  const type = document.querySelector('[data-sale-type]')?.value || '';
  const subtype = document.querySelector('[data-sale-subtype]')?.value || '';
  const matches = (getSaleItems()).filter((i) => i.category === category && i.product === product && (i.type || '') === type && (i.subtype || '') === subtype);
  const match = matches.length === 1 ? matches[0] : null;
  const idInput = document.querySelector('[data-sale-item-id]');
  const emailInput = document.querySelector('[data-sale-email]');
  const passwordInput = document.querySelector('[data-sale-password]');
  const costInput = document.querySelector('[data-sale-cost]');
  const priceInput = document.querySelector('[data-sale-price]');
  const qtyInput = document.querySelector('[data-sale-qty]');
  const slotField = document.querySelector('[data-sale-slot-field]');
  const slotSelect = document.querySelector('[data-sale-slot]');
  const slotIdInput = document.querySelector('[data-sale-slot-id]');
  const slotDetails = document.querySelector('[data-sale-slot-details]');
  if (slotDetails) slotDetails.hidden = true;
  const availableLabel = document.querySelector('[data-sale-available]');
  if (availableLabel) availableLabel.textContent = matches.length ? `${matches[0].stock} available` : '';
  if (matches.length > 1 && idInput) {
    idInput.value = matches[0].id;
    if (costInput) costInput.value = matches[0].cost;
    if (priceInput) priceInput.value = matches[0].price;
    if (qtyInput) qtyInput.max = matches[0].stock;
    if (emailInput) emailInput.value = '';
    if (passwordInput) passwordInput.value = '';
    if (slotField) slotField.hidden = true;
    if (slotSelect) slotSelect.innerHTML = '<option value="">Select slot...</option>';
    if (slotIdInput) slotIdInput.value = '';
    updateSaleProfitPreview();
    return;
  }
  if (match) {
    if (idInput) idInput.value = match.id;
    if (emailInput) emailInput.value = match.email || '';
    if (passwordInput) passwordInput.value = match.password || '';
    if (costInput) costInput.value = match.cost;
    if (priceInput) priceInput.value = match.price;
    if (qtyInput) qtyInput.max = match.stock;
    const slots = match.slots || [];
    if (slotField) slotField.hidden = !slots.length;
    if (slotSelect) {
      slotSelect.innerHTML = '<option value="">Select slot...</option>' + slots.map((s) => (
        `<option value="${s.id}" data-slot-name="${s.name || ''}" data-slot-pin="${s.pin || ''}">${s.name || 'Slot'} - ${s.pin}</option>`
      )).join('');
    }
    if (slotIdInput) slotIdInput.value = '';
  } else {
    if (idInput) idInput.value = '';
    if (emailInput) emailInput.value = '';
    if (passwordInput) passwordInput.value = '';
    if (slotField) slotField.hidden = true;
    if (slotSelect) slotSelect.innerHTML = '<option value="">Select slot...</option>';
    if (slotIdInput) slotIdInput.value = '';
  }
  updateSaleProfitPreview();
}

function getStockItems() {
  try {
    return JSON.parse(document.getElementById('inventory-stock-modal')?.dataset.stockItems || '[]');
  } catch {
    return [];
  }
}

function getSaleItems() {
  try {
    return JSON.parse(document.getElementById('sale-modal')?.dataset.saleItems || '[]');
  } catch {
    return [];
  }
}

function populateStockProducts(category, selectedId = '') {
  const select = document.querySelector('[data-stock-product]');
  if (!select) return;
  const options = (getStockItems()).filter((i) => i.category === category);
  select.innerHTML = '<option value="">Select...</option>' + options.map((i) => (
    `<option value="${i.id}" ${String(i.id) === String(selectedId) ? 'selected' : ''}>${i.product}${i.label && i.label !== i.product ? ' - ' + i.label : ''}</option>`
  )).join('');
  select.disabled = !options.length;
}

function renderStockPins(count, slots = []) {
  const builder = document.querySelector('[data-stock-pin-builder]');
  if (!builder) return;
  if (!count) {
    builder.innerHTML = '';
    return;
  }
  let html = '';
  for (let i = 0; i < count; i += 1) {
    const slot = slots[i] || {};
    html += `<div class="variant-row-form"><label>Slot ${i + 1} Name<input type="text" name="slot_names[${i}]" placeholder="Name..." value="${slot.name || ''}"></label><label>Slot ${i + 1} PIN<input type="text" name="slot_pins[${i}]" placeholder="PIN..." value="${slot.pin || ''}"></label></div>`;
  }
  builder.innerHTML = html;
}

function renderProductSlots(builder, count, slots = []) {
  if (!builder) return;
  if (!count) {
    builder.innerHTML = '';
    return;
  }
  let html = '';
  for (let i = 0; i < count; i += 1) {
    const slot = slots[i] || {};
    html += `<div class="variant-row-form"><label>Slot ${i + 1} Name<input type="text" name="slot_names[${i}]" placeholder="Name..." value="${slot.name || slot.label || ''}"></label><label>Slot ${i + 1} PIN<input type="text" name="slot_pins[${i}]" placeholder="PIN..." value="${slot.pin || slot.pin_code || ''}"></label></div>`;
  }
  builder.innerHTML = html;
}

function resolveStockItem() {
  const select = document.querySelector('[data-stock-product]');
  const id = select?.value || '';
  const item = (getStockItems()).find((i) => String(i.id) === String(id));
  const idInput = document.querySelector('[data-stock-item-id]');
  const emailInput = document.querySelector('[data-stock-email]');
  const passwordInput = document.querySelector('[data-stock-password]');
  const slotsInput = document.querySelector('[data-stock-slots]');
  const notesInput = document.querySelector('[data-stock-notes]');
  const slotsField = document.querySelector('[data-stock-slots-field]');
  if (idInput) idInput.value = item ? item.id : '';
  if (emailInput) emailInput.value = item?.email || '';
  if (passwordInput) passwordInput.value = item?.password || '';
  if (slotsInput) slotsInput.value = (item?.slots || []).length || '';
  if (notesInput) notesInput.value = item?.notes || '';
  const isShared = (item?.type || '').toLowerCase().includes('shared');
  if (slotsField) slotsField.hidden = !isShared && !(item?.slots || []).length;
  renderStockPins((item?.slots || []).length, item?.slots || []);
}

function setLoading(state) {
  loading = state;
  document.body.classList.toggle('is-loading', state);
}

function updateActiveTab(url) {
  const activeTab = new URL(url, window.location.href).searchParams.get('tab') || 'dashboard';
  document.querySelectorAll('[data-dashboard-nav] a').forEach((link) => {
    const linkTab = new URL(link.href).searchParams.get('tab') || 'dashboard';
    link.classList.toggle('active', linkTab === activeTab);
  });
}

function replaceDashboard(html, url, pushState = true) {
  const nextDocument = new DOMParser().parseFromString(html, 'text/html');
  const nextContent = nextDocument.querySelector(contentSelector);
  const currentContent = document.querySelector(contentSelector);
  if (!nextContent || !currentContent) return false;

  promoteToasts(nextDocument);
  document.body.dataset.theme = nextDocument.body.dataset.theme || 'light';
  document.body.style.cssText = nextDocument.body.style.cssText;
  currentContent.classList.add('content-leaving');
  window.setTimeout(() => {
    currentContent.innerHTML = nextContent.innerHTML;
    currentContent.classList.remove('content-leaving');
    currentContent.classList.add('content-entering');
    window.setTimeout(() => currentContent.classList.remove('content-entering'), 260);
    window.setTimeout(() => initAnalyticsDashboard(), 0);
    if (currentContent.querySelector('[data-inventory-view-tab]')) {
      filterInventory();
    }
  }, 120);

  document.title = nextDocument.title;
  updateActiveTab(url);
  if (pushState) history.pushState({}, '', url);
  if (url.includes('tab=products') || window.location.search.includes('tab=products')) {
    window.setTimeout(() => filterCatalog(), 0);
  }
  if (url.includes('tab=inventory') || window.location.search.includes('tab=inventory')) {
    window.setTimeout(() => filterInventory(), 0);
  }
  return true;
}

async function loadDashboard(url, options = {}, pushState = true) {
  if (loading) return;
  setLoading(true);
  try {
    const response = await fetch(url, {
      ...options,
      headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
    });
    if (response.redirected && response.url.includes('index.php')) {
      window.location.href = response.url;
      return;
    }
    replaceDashboard(await response.text(), url, pushState);
  } catch (error) {
    window.location.href = url;
  } finally {
    setLoading(false);
  }
}

document.addEventListener('click', (event) => {
  const refreshAnalyticsButton = event.target.closest('[data-refresh-analytics]');
  if (refreshAnalyticsButton) {
    refreshAnalytics(true);
    return;
  }

  const refreshDashboard = event.target.closest('[data-refresh-dashboard]');
  if (refreshDashboard) {
    loadDashboard(window.location.href, {}, false);
    return;
  }

  const deleteCategoryButton = event.target.closest('[data-delete-category]');
  if (deleteCategoryButton) {
    const name = deleteCategoryButton.dataset.deleteCategory;
    if (window.confirm(`Delete "${name}" and all items inside it?`)) {
      loadDashboard(
        window.location.href,
        {
          method: 'POST',
          body: new URLSearchParams({ action: 'delete_category', name }),
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        },
        false
      );
    }
    return;
  }

  const accentPreset = event.target.closest('[data-accent-color]');
  if (accentPreset) {
    const form = accentPreset.closest('.customization-form');
    const input = form?.querySelector('[data-accent-input]');
    if (input) {
      input.value = accentPreset.dataset.accentColor;
      form.querySelectorAll('[data-accent-color]').forEach((button) => button.classList.toggle('selected', button === accentPreset));
      document.body.style.setProperty('--user-accent', input.value);
    }
    return;
  }

  const categoryToggle = event.target.closest('[data-toggle-group]');
  if (categoryToggle) {
    const card = categoryToggle.closest('[data-product-card]');
    const body = card ? card.querySelector('.product-body') : categoryToggle.nextElementSibling;
    if (body) {
      const willOpen = body.hidden;
      if (card) {
        document.querySelectorAll('[data-product-card] .product-body').forEach((panel) => {
          if (panel !== body) panel.hidden = true;
        });
      }
      body.hidden = !willOpen;
      if (card) {
        categoryToggle.textContent = willOpen ? 'Hide variants' : 'Show variants';
      } else {
        categoryToggle.textContent = categoryToggle.textContent.replace(willOpen ? 'Show' : 'Hide', willOpen ? 'Hide' : 'Show');
      }
    }
    return;
  }

  const toggleSoldProducts = event.target.closest('[data-toggle-sold-products]');
  if (toggleSoldProducts) {
    const grid = document.querySelector('[data-product-grid]');
    const showSold = toggleSoldProducts.dataset.toggleSoldProducts === 'sold';
    grid?.classList.toggle('show-sold', showSold);
    toggleSoldProducts.parentElement?.querySelectorAll('[data-toggle-sold-products]').forEach((btn) => {
      btn.classList.toggle('active', btn === toggleSoldProducts);
    });
    return;
  }

  const clickCopyInput = event.target.closest('[data-click-copy]');
  if (clickCopyInput) {
    if (clickCopyInput.value) {
      navigator.clipboard?.writeText(clickCopyInput.value);
      const original = clickCopyInput.dataset.placeholderBackup ?? clickCopyInput.placeholder;
      clickCopyInput.dataset.placeholderBackup = original;
      const previousType = clickCopyInput.type;
      clickCopyInput.classList.add('copied-flash');
      const wasPassword = previousType === 'password';
      if (wasPassword) clickCopyInput.type = 'text';
      const realValue = clickCopyInput.value;
      clickCopyInput.value = 'Copied!';
      window.setTimeout(() => {
        clickCopyInput.value = realValue;
        if (wasPassword) clickCopyInput.type = 'password';
        clickCopyInput.classList.remove('copied-flash');
      }, 800);
    }
    return;
  }

  const toggleSalePassword = event.target.closest('[data-toggle-sale-password]');
  if (toggleSalePassword) {
    const input = document.querySelector('[data-sale-password]');
    if (input) {
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      toggleSalePassword.textContent = isHidden ? 'Hide' : 'Show';
    }
    return;
  }

  const togglePinButton = event.target.closest('[data-toggle-pin]');
  if (togglePinButton) {
    const pinValue = togglePinButton.previousElementSibling;
    if (pinValue) {
      const isMasked = pinValue.textContent === '••••••';
      pinValue.textContent = isMasked ? (pinValue.dataset.pinValue || '') : '••••••';
      togglePinButton.textContent = isMasked ? 'Hide' : 'Show';
    }
    return;
  }

  const inventoryViewTab = event.target.closest('[data-inventory-view-tab]');
  if (inventoryViewTab) {
    document.querySelectorAll('[data-inventory-view-tab]').forEach((btn) => btn.classList.toggle('active', btn === inventoryViewTab));
    filterInventory();
    return;
  }

  const openModal = event.target.closest('[data-open-modal]');
  if (openModal) {
    const modal = document.getElementById(openModal.dataset.openModal);
    modal?.classList.add('open');
    if (modal) {
      const form = modal.querySelector('[data-inventory-form]');
      if (form) {
        form.reset();
        syncInventoryForm(form, {
          itemId: openModal.dataset.itemId || '',
          productName: openModal.dataset.productName || '',
          category: openModal.dataset.productCategory || openModal.dataset.category || '',
          variantType: openModal.dataset.variantType || '',
          variantSubtype: openModal.dataset.variantSubtype || '',
          stock: openModal.dataset.stock || '0',
          sellPrice: openModal.dataset.sellPrice || '0',
          costPrice: openModal.dataset.costPrice || '0',
          accountEmail: openModal.dataset.accountEmail || '',
          accountPassword: openModal.dataset.accountPassword || '',
          notes: openModal.dataset.notes || '',
          mode: openModal.dataset.modalTitle === 'Add Product' ? 'product' : 'variant',
          modalTitle: openModal.dataset.modalTitle || '',
          slotValues: (() => { try { return JSON.parse(openModal.dataset.slotValues || '[]'); } catch { return []; } })(),
        });
        const productNameInput = form.querySelector('[data-product-name-input]');
        const categorySelect = form.querySelector('[data-category-select]');
        if (openModal.dataset.itemName && !openModal.dataset.productName) {
          if (productNameInput) productNameInput.value = openModal.dataset.itemName;
        }
        if (openModal.dataset.productName && productNameInput && !productNameInput.value) {
          productNameInput.value = openModal.dataset.productName;
        }
        if (categorySelect && openModal.dataset.productCategory) categorySelect.value = openModal.dataset.productCategory;
        syncInventoryForm(form, {
          itemId: openModal.dataset.itemId || '',
          productName: productNameInput?.value || openModal.dataset.productName || '',
          category: categorySelect?.value || openModal.dataset.productCategory || '',
          variantType: openModal.dataset.variantType || '',
          variantSubtype: openModal.dataset.variantSubtype || '',
          stock: openModal.dataset.stock || '0',
          sellPrice: openModal.dataset.sellPrice || '0',
          costPrice: openModal.dataset.costPrice || '0',
          accountEmail: openModal.dataset.accountEmail || '',
          accountPassword: openModal.dataset.accountPassword || '',
          notes: openModal.dataset.notes || '',
          mode: openModal.dataset.modalTitle === 'Add Product' ? 'product' : 'variant',
          modalTitle: openModal.dataset.modalTitle || '',
          slotValues: (() => { try { return JSON.parse(openModal.dataset.slotValues || '[]'); } catch { return []; } })(),
        });
      }
    }
    return;
  }
  const closeModal = event.target.closest('[data-close-modal]');
  if (closeModal) {
    closeModal.closest('.modal')?.classList.remove('open');
    return;
  }
  if (event.target.classList.contains('modal')) {
    event.target.classList.remove('open');
    return;
  }

  const link = event.target.closest('a');
  if (!link || link.classList.contains('logout-btn')) return;
  if (link.getAttribute('href')?.startsWith('#')) {
    event.preventDefault();
    document.querySelector(link.getAttribute('href'))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  const url = new URL(link.href, window.location.href);
  if (url.origin !== window.location.origin || !url.pathname.endsWith('/dashboard.php')) return;
  event.preventDefault();
  loadDashboard(url.href);
});

document.addEventListener('change', (event) => {
  if (event.target.matches('[data-file-input]')) {
    const label = event.target.closest('.profile-upload-field');
    const name = label?.querySelector('[data-file-name]');
    if (name) {
      name.textContent = event.target.files?.[0]?.name || 'No file selected';
    }
    return;
  }
  if (event.target.matches('[data-variant-type], [data-variant-subtype]')) {
    const form = event.target.closest('[data-inventory-form]');
    const typeValue = form?.querySelector('[data-variant-type]')?.value || '';
    renderSubtypeOptions(form, typeValue, form?.querySelector('[data-variant-subtype]')?.value || '');
    const productName = form?.querySelector('[data-product-name-input]')?.value || '';
    const category = form?.querySelector('[data-category-select]')?.value || '';
    const netflixOnly = form?.querySelector('[data-netflix-only]');
    const slotCountInput = form?.querySelector('[data-slot-count]');
    const slotBuilder = form?.querySelector('[data-slot-builder]');
    const isShared = String(typeValue || '').toLowerCase().includes('shared');
    if (netflixOnly) netflixOnly.hidden = !isShared;
    if (slotCountInput) slotCountInput.disabled = !isShared;
    if (slotBuilder) {
      if (isShared) {
        const existing = [...slotBuilder.querySelectorAll('[name^="slot_pins"]')].map((input, idx) => ({
          pin: input.value,
          name: slotBuilder.querySelectorAll('[name^="slot_names"]')[idx]?.value || '',
        }));
        renderProductSlots(slotBuilder, Number(slotCountInput?.value || 0), existing);
      } else {
        slotBuilder.innerHTML = '';
      }
    }
    updateProfitPreview(form);
    return;
  }
  if (event.target.matches('input[name="theme_mode"]')) {
    const form = event.target.closest('.customization-form');
    form?.querySelectorAll('.theme-choice').forEach((choice) => choice.classList.toggle('selected', choice.contains(event.target)));
    document.body.dataset.theme = event.target.value;
    return;
  }
  if (event.target.matches('[data-accent-input]')) {
    const form = event.target.closest('.customization-form');
    form?.querySelectorAll('[data-accent-color]').forEach((button) => button.classList.toggle('selected', button.dataset.accentColor === event.target.value.toLowerCase()));
    document.body.style.setProperty('--user-accent', event.target.value);
    return;
  }
  if (event.target.matches('[data-stock-category]')) {
    populateStockProducts(event.target.value);
    resolveStockItem();
    return;
  }
  if (event.target.matches('[data-stock-product]')) {
    resolveStockItem();
    return;
  }
  if (event.target.matches('[data-stock-slots]')) {
    const select = document.querySelector('[data-stock-product]');
    const item = (getStockItems()).find((i) => String(i.id) === String(select?.value || ''));
    renderStockPins(Number(event.target.value || 0), item?.slots || []);
    return;
  }
  if (event.target.matches('[data-sale-slot]')) {
    const slotIdInput = document.querySelector('[data-sale-slot-id]');
    if (slotIdInput) slotIdInput.value = event.target.value;
    const selected = event.target.selectedOptions[0];
    const nameInput = document.querySelector('[data-sale-slot-name]');
    const pinInput = document.querySelector('[data-sale-slot-pin]');
    const details = document.querySelector('[data-sale-slot-details]');
    if (event.target.value && selected) {
      if (nameInput) nameInput.value = selected.dataset.slotName || '';
      if (pinInput) pinInput.value = selected.dataset.slotPin || '';
      if (details) details.hidden = false;
    } else {
      if (nameInput) nameInput.value = '';
      if (pinInput) pinInput.value = '';
      if (details) details.hidden = true;
    }
    return;
  }
  if (event.target.matches('[data-sale-category]')) {
    populateSaleProducts(event.target.value);
    populateSaleTypes('', '');
    populateSaleSubtypes('', '', '');
    resolveSaleItem();
    return;
  }
  if (event.target.matches('[data-sale-product]')) {
    const category = document.querySelector('[data-sale-category]')?.value || '';
    populateSaleTypes(category, event.target.value);
    populateSaleSubtypes(category, event.target.value, '');
    resolveSaleItem();
    return;
  }
  if (event.target.matches('[data-sale-type]')) {
    const category = document.querySelector('[data-sale-category]')?.value || '';
    const product = document.querySelector('[data-sale-product]')?.value || '';
    populateSaleSubtypes(category, product, event.target.value);
    resolveSaleItem();
    return;
  }
  if (event.target.matches('[data-sale-subtype]')) {
    resolveSaleItem();
    return;
  }
  if (event.target.matches('[data-sale-qty], [data-sale-discount], [data-sale-price], [data-sale-cost]')) {
    updateSaleProfitPreview();
    return;
  }
  if (event.target.matches('[data-category-select], [data-product-name-input], [data-sell-price-input], [data-cost-price-input], [data-slot-count]')) {
    const form = event.target.closest('[data-inventory-form]');
    const productName = form?.querySelector('[data-product-name-input]')?.value || '';
    const category = form?.querySelector('[data-category-select]')?.value || '';
    const type = form?.querySelector('[data-variant-type]')?.value || '';
    const subtype = form?.querySelector('[data-variant-subtype]')?.value || '';
    const isShared = String(type || '').toLowerCase().includes('shared');
    const netflixOnly = form?.querySelector('[data-netflix-only]');
    const slotBuilder = form?.querySelector('[data-slot-builder]');
    const slotCountInput = form?.querySelector('[data-slot-count]');
    if (netflixOnly) netflixOnly.hidden = !isShared;
    if (slotCountInput) slotCountInput.disabled = !isShared;
    if (slotBuilder) {
      if (isShared) {
        const existing = [...slotBuilder.querySelectorAll('[name^="slot_pins"]')].map((input, idx) => ({
          pin: input.value,
          name: slotBuilder.querySelectorAll('[name^="slot_names"]')[idx]?.value || '',
        }));
        renderProductSlots(slotBuilder, Number(slotCountInput?.value || 0), existing);
      } else {
        slotBuilder.innerHTML = '';
      }
    }
    updateProfitPreview(form);
  }
});

document.addEventListener('submit', (event) => {
  const form = event.target.closest(`${contentSelector} form`);
  if (!form) return;
  if (form.matches('[data-confirm-delete-item]') && !window.confirm(`Delete variant "${form.dataset.confirmDeleteItem}"?`)) {
    event.preventDefault();
    return;
  }
  if (form.matches('[data-confirm-delete-user]') && !window.confirm(`Delete admin "${form.dataset.confirmDeleteUser}"?`)) {
    event.preventDefault();
    return;
  }
  if (form.matches('[data-sale-form]') && !form.querySelector('[data-sale-item-id]')?.value) {
    event.preventDefault();
    showToast('Select a category, product, type, and subtype that match an inventory item.', 'error');
    return;
  }
  event.preventDefault();
  const submitButton = form.querySelector('button[type="submit"], button:not([type])');
  if (submitButton) submitButton.disabled = true;
  loadDashboard(window.location.href, { method: 'POST', body: new FormData(form) }, false);
});

document.addEventListener('input', (event) => {
  if (event.target.matches('[data-catalog-search]')) {
    filterCatalog();
    return;
  }
  if (event.target.matches('[data-inventory-search]')) {
    filterInventory();
    return;
  }
  if (event.target.matches('[data-slot-count], [data-sell-price-input], [data-cost-price-input], [data-product-name-input], [data-variant-subtype]')) {
    const form = event.target.closest('[data-inventory-form]');
    updateProfitPreview(form);
    return;
  }
  if (event.target.matches('[data-sale-qty], [data-sale-discount], [data-sale-price], [data-sale-cost]')) {
    updateSaleProfitPreview();
    return;
  }
});

document.addEventListener('change', (event) => {
  if (event.target.matches('[data-catalog-filter]')) {
    filterCatalog();
  }
  if (event.target.matches('[data-inventory-filter]')) {
    filterInventory();
  }
});

function filterInventory() {
  const search = (document.querySelector('[data-inventory-search]')?.value || '').trim().toLowerCase();
  const filter = document.querySelector('[data-inventory-filter]')?.value || 'all';
  const view = document.querySelector('[data-inventory-view-tab].active')?.dataset.inventoryViewTab || 'active';
  document.querySelectorAll('[data-inventory-row]').forEach((row) => {
    const matchesSearch = !search || (row.dataset.inventoryName || '').includes(search) || (row.textContent || '').toLowerCase().includes(search);
    const matchesCategory = filter === 'all' || row.dataset.inventoryCategory === filter.toLowerCase();
    const matchesView = row.dataset.inventoryView === view;
    row.style.display = matchesSearch && matchesCategory && matchesView ? '' : 'none';
  });
}

window.addEventListener('popstate', () => loadDashboard(window.location.href, {}, false));

if (document.querySelector('[data-inventory-view-tab]')) {
  filterInventory();
}

function filterCatalog() {
  const search = (document.querySelector('[data-catalog-search]')?.value || '').trim().toLowerCase();
  const filter = document.querySelector('[data-catalog-filter]')?.value || 'all';
  const cards = document.querySelectorAll('[data-product-card]');
  let visibleProducts = 0;

  cards.forEach((card) => {
    const productName = (card.dataset.productName || '').toLowerCase();
    const productCategory = (card.dataset.productCategory || '').toLowerCase();
    const rows = Array.from(card.querySelectorAll('[data-product-row]'));
    const rowText = rows.map((row) => `${row.dataset.productName || ''} ${row.textContent || ''}`.trim().toLowerCase()).join(' ');
    const matchesCategory = filter === 'all' || productCategory === filter.toLowerCase();
    const matchesSearch = !search || productName.includes(search) || rowText.includes(search);
    const showCard = matchesCategory && matchesSearch;
    card.style.display = showCard ? '' : 'none';
    if (showCard) visibleProducts += 1;
  });

  const counter = document.querySelector('[data-catalog-count]');
  if (counter) counter.textContent = `${visibleProducts} products`;
}

class AnimatedAnalyticsChart {
  constructor(canvas, tooltip) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.tooltip = tooltip;
    this.data = null;
    this.fromDatasets = [];
    this.hoverIndex = null;
    this.frame = null;
    this.resizeObserver = new ResizeObserver(() => this.draw(1));
    this.resizeObserver.observe(canvas.parentElement);
    canvas.addEventListener('mousemove', (event) => this.handleHover(event));
    canvas.addEventListener('mouseleave', () => this.clearHover());
  }

  destroy() {
    cancelAnimationFrame(this.frame);
    this.resizeObserver.disconnect();
  }

  setData(labels, datasets) {
    this.fromDatasets = datasets.map((dataset, index) => ({
      values: (this.data?.datasets[index]?.values || []).map(Number),
    }));
    this.data = { labels, datasets };
    cancelAnimationFrame(this.frame);
    const startedAt = performance.now();
    const animate = (now) => {
      const progress = Math.min(1, (now - startedAt) / 850);
      this.draw(1 - Math.pow(1 - progress, 3));
      if (progress < 1) this.frame = requestAnimationFrame(animate);
    };
    this.frame = requestAnimationFrame(animate);
  }

  setupCanvas() {
    const rect = this.canvas.getBoundingClientRect();
    const dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
    const width = Math.max(280, rect.width);
    const height = Math.max(220, rect.height);
    if (this.canvas.width !== Math.round(width * dpr) || this.canvas.height !== Math.round(height * dpr)) {
      this.canvas.width = Math.round(width * dpr);
      this.canvas.height = Math.round(height * dpr);
    }
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { width, height };
  }

  draw(progress = 1) {
    if (!this.data) return;
    const { width, height } = this.setupCanvas();
    const ctx = this.ctx;
    const pad = { left: 54, right: 20, top: 18, bottom: 34 };
    const plotWidth = width - pad.left - pad.right;
    const plotHeight = height - pad.top - pad.bottom;
    const labels = this.data.labels;
    const maxValue = Math.max(1, ...this.data.datasets.flatMap((dataset) => dataset.values.map(Number)));
    ctx.clearRect(0, 0, width, height);

    ctx.lineWidth = 1;
    ctx.font = '11px Inter, system-ui, sans-serif';
    ctx.fillStyle = '#8c829b';
    ctx.strokeStyle = 'rgba(199, 181, 232, .38)';
    ctx.setLineDash([4, 5]);
    for (let i = 0; i < 5; i += 1) {
      const y = pad.top + (plotHeight / 4) * i;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(width - pad.right, y);
      ctx.stroke();
      const value = maxValue - (maxValue / 4) * i;
      ctx.fillText(formatCompactCurrency(value), 2, y + 4);
    }
    ctx.setLineDash([]);

    const stepX = labels.length > 1 ? plotWidth / (labels.length - 1) : plotWidth;
    const labelEvery = Math.max(1, Math.ceil(labels.length / 7));
    labels.forEach((label, index) => {
      if (index % labelEvery !== 0 && index !== labels.length - 1) return;
      const x = pad.left + stepX * index;
      ctx.textAlign = 'center';
      ctx.fillStyle = '#8c829b';
      ctx.fillText(label, x, height - 8);
    });
    ctx.textAlign = 'start';

    const renderedPoints = [];
    this.data.datasets.forEach((dataset, datasetIndex) => {
      const from = this.fromDatasets[datasetIndex]?.values || [];
      const values = dataset.values.map((value, index) => {
        const start = Number(from[index] ?? 0);
        return start + (Number(value) - start) * progress;
      });
      const points = values.map((value, index) => ({
        x: pad.left + stepX * index,
        y: pad.top + plotHeight - (value / maxValue) * plotHeight,
        value: Number(dataset.values[index] || 0),
      }));
      renderedPoints.push(points);

      if (dataset.fill && points.length) {
        const gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + plotHeight);
        gradient.addColorStop(0, dataset.fill);
        gradient.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.beginPath();
        ctx.moveTo(points[0].x, pad.top + plotHeight);
        this.traceSmoothLine(ctx, points);
        ctx.lineTo(points[points.length - 1].x, pad.top + plotHeight);
        ctx.closePath();
        ctx.fillStyle = gradient;
        ctx.fill();
      }

      ctx.beginPath();
      this.traceSmoothLine(ctx, points);
      ctx.strokeStyle = dataset.color;
      ctx.lineWidth = dataset.width || 3;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.stroke();

      points.forEach((point) => {
        ctx.beginPath();
        ctx.arc(point.x, point.y, 3.5, 0, Math.PI * 2);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = dataset.color;
        ctx.stroke();
      });
    });

    if (this.hoverIndex !== null && labels[this.hoverIndex] !== undefined) {
      const x = pad.left + stepX * this.hoverIndex;
      ctx.beginPath();
      ctx.moveTo(x, pad.top);
      ctx.lineTo(x, pad.top + plotHeight);
      ctx.strokeStyle = 'rgba(92, 82, 113, .28)';
      ctx.lineWidth = 1;
      ctx.setLineDash([3, 4]);
      ctx.stroke();
      ctx.setLineDash([]);
      renderedPoints.forEach((points, index) => {
        const point = points[this.hoverIndex];
        if (!point) return;
        ctx.beginPath();
        ctx.arc(point.x, point.y, 6, 0, Math.PI * 2);
        ctx.fillStyle = this.data.datasets[index].color;
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 3;
        ctx.stroke();
      });
    }
  }

  traceSmoothLine(ctx, points) {
    if (!points.length) return;
    ctx.moveTo(points[0].x, points[0].y);
    for (let i = 1; i < points.length; i += 1) {
      const previous = points[i - 1];
      const current = points[i];
      const midpoint = (previous.x + current.x) / 2;
      ctx.bezierCurveTo(midpoint, previous.y, midpoint, current.y, current.x, current.y);
    }
  }

  handleHover(event) {
    if (!this.data?.labels.length) return;
    const rect = this.canvas.getBoundingClientRect();
    const left = 54;
    const right = 20;
    const plotWidth = rect.width - left - right;
    const relativeX = Math.max(0, Math.min(plotWidth, event.clientX - rect.left - left));
    this.hoverIndex = this.data.labels.length > 1
      ? Math.round(relativeX / (plotWidth / (this.data.labels.length - 1)))
      : 0;
    const rows = this.data.datasets
      .map((dataset) => `${dataset.name}: ${formatCurrency(dataset.values[this.hoverIndex] || 0)}`)
      .join('<br>');
    this.tooltip.innerHTML = `<strong>${this.data.labels[this.hoverIndex]}</strong><br>${rows}`;
    this.tooltip.style.left = `${event.clientX - rect.left}px`;
    this.tooltip.style.top = `${event.clientY - rect.top}px`;
    this.tooltip.classList.add('show');
    this.draw(1);
  }

  clearHover() {
    this.hoverIndex = null;
    this.tooltip.classList.remove('show');
    this.draw(1);
  }
}

function formatCurrency(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatCompactCurrency(value) {
  const number = Number(value || 0);
  if (number >= 1000000) return `₱${(number / 1000000).toFixed(1)}m`;
  if (number >= 1000) return `₱${(number / 1000).toFixed(1)}k`;
  return `₱${Math.round(number)}`;
}

function animateMetric(element, target, isCurrency) {
  const start = Number(element.dataset.lastValue ?? String(element.textContent).replace(/[^\d.-]/g, '')) || 0;
  const startedAt = performance.now();
  const animate = (now) => {
    const progress = Math.min(1, (now - startedAt) / 600);
    const eased = 1 - Math.pow(1 - progress, 3);
    const value = start + (Number(target) - start) * eased;
    element.textContent = isCurrency ? formatCurrency(value) : Math.round(value).toLocaleString('en-PH');
    if (progress < 1) requestAnimationFrame(animate);
    else element.dataset.lastValue = String(target);
  };
  requestAnimationFrame(animate);
}

async function refreshAnalytics(manual = false) {
  const dashboard = document.querySelector('[data-analytics-dashboard]');
  if (!dashboard) return;
  analyticsRequest?.abort();
  analyticsRequest = new AbortController();
  const params = new URLSearchParams(window.location.search);
  const range = params.get('range') || 'daily';
  const date = params.get('date') || new Date().toISOString().slice(0, 10);
  const endpoint = dashboard.dataset.analyticsEndpoint || 'analytics.php';
  const refreshButton = document.querySelector('[data-refresh-analytics]');
  if (manual && refreshButton) refreshButton.disabled = true;

  try {
    const response = await fetch(`${endpoint}?range=${encodeURIComponent(range)}&date=${encodeURIComponent(date)}&_=${Date.now()}`, {
      cache: 'no-store',
      signal: analyticsRequest.signal,
    });
    if (response.redirected && response.url.includes('index.php')) {
      window.location.href = response.url;
      return;
    }
    if (!response.ok) throw new Error('Analytics request failed');
    const data = await response.json();
    ['revenue', 'costs', 'profit', 'count'].forEach((key) => {
      const metric = document.querySelector(`[data-analytics-metric="${key}"]`);
      if (metric) animateMetric(metric, data.metrics[key], key !== 'count');
    });
    document.querySelectorAll('[data-live-updated]').forEach((element) => {
      element.textContent = `Updated ${data.updatedAt}`;
    });

    if (!analyticsCharts.revenue) {
      const canvas = document.querySelector('[data-analytics-chart="revenue"]');
      analyticsCharts.revenue = new AnimatedAnalyticsChart(canvas, canvas.parentElement.querySelector('[data-chart-tooltip]'));
    }
    if (!analyticsCharts.profit) {
      const canvas = document.querySelector('[data-analytics-chart="profit"]');
      analyticsCharts.profit = new AnimatedAnalyticsChart(canvas, canvas.parentElement.querySelector('[data-chart-tooltip]'));
    }
    analyticsCharts.revenue.setData(data.labels, [
      { name: 'Sales', values: data.series.sales, color: '#9b84df', fill: 'rgba(155,132,223,.30)', width: 3.5 },
      { name: 'Costs', values: data.series.costs, color: '#efa3ac', fill: null, width: 2.5 },
    ]);
    analyticsCharts.profit.setData(data.labels, [
      { name: 'Profit', values: data.series.profit, color: '#8fc56e', fill: 'rgba(143,197,110,.30)', width: 3.5 },
    ]);
  } catch (error) {
    if (error.name !== 'AbortError') {
      document.querySelectorAll('[data-live-updated]').forEach((element) => {
        element.textContent = 'Reconnect pending';
      });
    }
  } finally {
    if (refreshButton) refreshButton.disabled = false;
  }
}

function initAnalyticsDashboard() {
  clearInterval(analyticsPollTimer);
  analyticsRequest?.abort();
  Object.values(analyticsCharts).forEach((chart) => chart.destroy());
  analyticsCharts = {};
  if (!document.querySelector('[data-analytics-dashboard]')) return;
  refreshAnalytics();
  analyticsPollTimer = window.setInterval(() => refreshAnalytics(), 10000);
}

promoteToasts(document);
initAnalyticsDashboard();
