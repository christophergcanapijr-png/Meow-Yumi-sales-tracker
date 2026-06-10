const contentSelector = '#dashboard-content';
let loading = false;
let analyticsPollTimer = null;
let analyticsCharts = {};
let analyticsRequest = null;

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

  document.body.dataset.theme = nextDocument.body.dataset.theme || 'light';
  document.body.style.cssText = nextDocument.body.style.cssText;
  currentContent.classList.add('content-leaving');
  window.setTimeout(() => {
    currentContent.innerHTML = nextContent.innerHTML;
    currentContent.classList.remove('content-leaving');
    currentContent.classList.add('content-entering');
    window.setTimeout(() => currentContent.classList.remove('content-entering'), 260);
    window.setTimeout(() => initAnalyticsDashboard(), 0);
  }, 120);

  document.title = nextDocument.title;
  updateActiveTab(url);
  if (pushState) history.pushState({}, '', url);
  if (url.includes('tab=inventory') || window.location.search.includes('tab=inventory')) {
    window.setTimeout(() => filterCatalog(), 0);
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
    const card = categoryToggle.closest('[data-category-card]');
    const body = card?.querySelector('.category-body');
    if (body) {
      const willOpen = body.hidden;
      document.querySelectorAll('[data-category-card] .category-body').forEach((panel) => {
        if (panel !== body) panel.hidden = true;
      });
      body.hidden = !willOpen;
    }
    return;
  }

  const openModal = event.target.closest('[data-open-modal]');
  if (openModal) {
    const modal = document.getElementById(openModal.dataset.openModal);
    modal?.classList.add('open');
    if (modal) {
      syncInventoryModal(modal);
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
  if (!event.target.matches('[data-category-select]')) return;
  const form = event.target.closest('[data-inventory-form]');
  syncInventoryForm(form);
  if (event.target.value === 'Netflix') {
    renderSlotBuilder(form);
  } else {
    const slotBuilder = form?.querySelector('[data-slot-builder]');
    if (slotBuilder) slotBuilder.innerHTML = '';
  }
});

document.addEventListener('submit', (event) => {
  const form = event.target.closest(`${contentSelector} form`);
  if (!form) return;
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
  if (!event.target.matches('[data-slot-count]')) return;
  const form = event.target.closest('[data-inventory-form]');
  syncInventoryForm(form);
  renderSlotBuilder(form);
});

document.addEventListener('change', (event) => {
  if (event.target.matches('[data-catalog-filter]')) {
    filterCatalog();
  }
});

window.addEventListener('popstate', () => loadDashboard(window.location.href, {}, false));

function renderSlotBuilder(form) {
  if (!form) return;
  const category = form.querySelector('[data-category-select]')?.value;
  const slotBuilder = form.querySelector('[data-slot-builder]');
  const slotCountInput = form.querySelector('[data-slot-count]');
  if (!slotBuilder || !slotCountInput) return;

  const count = Math.max(0, parseInt(slotCountInput.value || '0', 10));
  slotBuilder.hidden = category !== 'Netflix' || count <= 0;
  if (category !== 'Netflix' || count <= 0) {
    slotBuilder.innerHTML = '';
    return;
  }

  slotBuilder.innerHTML = `
    <div class="slot-builder-head">
      <strong>Per-slot PIN setup</strong>
      <small>Choose each slot number and type the PIN for that slot.</small>
    </div>
    <div class="slot-grid">
      ${Array.from({ length: count }, (_, i) => {
        const slot = i + 1;
        const options = Array.from({ length: count }, (_, optionIndex) => {
          const optionSlot = optionIndex + 1;
          return `<option value="${optionSlot}" ${optionSlot === slot ? 'selected' : ''}>Slot ${optionSlot}</option>`;
        }).join('');
        return `
          <div class="slot-row">
            <label>Slot ${slot}
              <select name="slot_numbers[]">${options}</select>
            </label>
            <label>PIN
              <input name="slot_pins[]" type="text" placeholder="PIN for slot ${slot}" required>
            </label>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function syncInventoryModal(modal) {
  syncInventoryForm(modal?.querySelector('[data-inventory-form]'));
}

function syncInventoryForm(form) {
  if (!form) return;
  const category = form.querySelector('[data-category-select]')?.value;
  const customCategoryField = form.querySelector('[data-custom-category-field]');
  const netflixOnly = form.querySelector('[data-netflix-only]');
  const slotBuilder = form.querySelector('[data-slot-builder]');
  const slotCountInput = form.querySelector('[data-slot-count]');

  const isNetflix = category === 'Netflix';
  const isCustom = category === 'Other';
  if (customCategoryField) customCategoryField.hidden = !isCustom;
  if (netflixOnly) netflixOnly.hidden = !isNetflix;
  if (slotBuilder) slotBuilder.hidden = !isNetflix || parseInt(slotCountInput?.value || '0', 10) <= 0;
  if (slotCountInput) slotCountInput.disabled = !isNetflix;

  if (!isNetflix && slotBuilder) {
    slotBuilder.innerHTML = '';
  }
}

function filterCatalog() {
  const search = (document.querySelector('[data-catalog-search]')?.value || '').trim().toLowerCase();
  const filter = document.querySelector('[data-catalog-filter]')?.value || 'all';
  const cards = document.querySelectorAll('[data-category-card]');
  let visibleProducts = 0;

  cards.forEach((card) => {
    const categoryName = card.dataset.categoryName || '';
    const productRows = card.querySelectorAll('[data-product-row]');
    let cardVisible = filter === 'all' || categoryName === filter.toLowerCase();

    productRows.forEach((row) => {
      const rowText = `${row.dataset.productName || ''} ${row.dataset.productCategory || ''}`.trim();
      const matches = !search || rowText.includes(search);
      const showRow = cardVisible && matches;
      row.style.display = showRow ? '' : 'none';
      if (showRow) visibleProducts += 1;
    });

    const anyVisible = Array.from(productRows).some((row) => row.style.display !== 'none');
    card.style.display = anyVisible ? '' : 'none';
    if (cardVisible && anyVisible) {
      card.querySelector('.category-body')?.setAttribute('hidden', '');
    }
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

initAnalyticsDashboard();
