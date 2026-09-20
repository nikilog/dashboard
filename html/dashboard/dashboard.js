/**
 * Dynamic colors from server (populated from meta_options)
 * Fallback: generate color from hash of label
 */
var STATUS_COLORS = {};
var SOURCE_COLORS = {};

const DEFAULT_STATUS_COLORS = {
  "Новий": "#ffffff",
  "В обробці": "#d8d8dc",
  "Очікується оплата": "#1bb4a7",
  "Підтверджено": "#f3e76d",
  "Чернетка": "#edccd8",
  "На відправку": "#ec761f",
  "Відправлено": "#37a3df",
  "Отримано": "#2b39c2",
  "Резерв": "#e8fbc4",
  "Продажа": "#12dc2b",
  "Закрито": "#42a100",
  "Відмова": "#f04438",
  "Повернення": "#f04438",
  "Видалений": "#727272",
};

/** Generate a stable color from a string hash */
function hashColor(str) {
  var hash = 0;
  for (var i = 0; i < str.length; i++) {
    hash = str.charCodeAt(i) + ((hash << 5) - hash);
  }
  var h = Math.abs(hash) % 360;
  return 'hsl(' + h + ',65%,55%)';
}

/** Convert HSL to RGB string for Chart.js */
function hslToRgb(hsl) {
  // Keep as HSL for simplicity - Chart.js supports it
  return hsl;
}

// pick readable text colour on swatches
function parseCssColor(color){
  if (!color) return null;
  const hex = color.trim().match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
  if (hex) {
    let value = hex[1];
    if (value.length === 3) value = value.split('').map(ch => ch + ch).join('');
    return [
      parseInt(value.slice(0, 2), 16),
      parseInt(value.slice(2, 4), 16),
      parseInt(value.slice(4, 6), 16)
    ];
  }
  const m = color.match(/(\d+)\D+(\d+)\D+(\d+)/);
  if(!m) return null;
  return [Number(m[1]), Number(m[2]), Number(m[3])];
}

function contrastTextColor(rgbStr){
  const rgb = parseCssColor(rgbStr);
  if(!rgb) return "#111";
  const r = rgb[0], g = rgb[1], b = rgb[2];
  // относительная яркость
  const y = (r*299 + g*587 + b*114) / 1000;
  return y >= 150 ? "#111" : "#fff";
}

let ordersSort = {
  key: null,   // 'source' | 'status' | 'manager'
  dir: 'asc'   // 'asc' | 'desc'
};

function sortOrders(list){
  if (!ordersSort.key) return list;

  const k = ordersSort.key;
  const dir = ordersSort.dir === 'asc' ? 1 : -1;

  return [...list].sort((a, b) => {
    const av = (a[k] != null ? a[k] : '').toString().toLowerCase();
    const bv = (b[k] != null ? b[k] : '').toString().toLowerCase();
    if (av < bv) return -1 * dir;
    if (av > bv) return  1 * dir;
    return 0;
  });
}



/* ===================== GLOBAL STATE ===================== */

let lastData = null;
let isFactMode = false;        // false = Загальне, true = Факт
let chartMetric = 'turnover'; // turnover | profit | orders
let salesChart = null;
let sourcesPieChart = null;
let bindMode = 'sold'; // created | sold
let onlyCompleted = true;


/* ===================== HELPERS ===================== */
function asNumberArray(v){
  if (!Array.isArray(v)) return [];
  return v.map(x => Number(x) || 0);
}

function safeMetricSeries(s, bucket, metric){
  // bucket: 'success' | 'hold' | 'total'
  const block = s && s[bucket];
  return asNumberArray(block && block[metric]);
}


function fmtMoney(x) {
  return Number(x || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
}

function fmtPct(x, d) {
  if (d === undefined) d = 1;
  if (x === null || x === undefined || Number.isNaN(Number(x))) return '\u2014';
  return Number(x).toFixed(d) + '%';
}

function iso(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}

function calcBonusPct(fulfillmentPct){
  if (fulfillmentPct == null || !isFinite(fulfillmentPct)) return 0;
  if (fulfillmentPct <= 100) return Math.min(1, fulfillmentPct * 0.01);
  return Math.min(2, 1 + (fulfillmentPct - 100) * 0.01);
}

function setCanvasScrollWidth(canvas, labelsCount, pxPerLabel, minWidth) {
  if (!canvas) return;
  if (pxPerLabel === undefined) pxPerLabel = 70;
  if (minWidth === undefined) minWidth = 900;

  const dip = Math.min(window.devicePixelRatio || 1, 2);
  const maxCssW = Math.floor(8192 / dip);

  let px = pxPerLabel;
  let w = Math.max(minWidth, Math.floor(labelsCount * px));
  if (w > maxCssW) {
    px = Math.max(40, Math.floor(maxCssW / Math.max(labelsCount, 1)));
    w = Math.max(minWidth, Math.floor(labelsCount * px));
  }

  canvas.style.width = w + 'px';
}

function calcReadableAxisMax(values) {
  const nums = values
    .map(v => Number(v) || 0)
    .filter(v => v > 0)
    .sort((a, b) => a - b);
  if (nums.length < 3 || chartMetric === 'orders') return undefined;

  const max = nums[nums.length - 1];
  const second = nums[nums.length - 2] || max;
  const p90 = nums[Math.max(0, Math.floor((nums.length - 1) * 0.9))] || second;
  const base = Math.max(second, p90);

  if (base > 0 && max / base >= 2.5) {
    return Math.ceil((base * 1.35) / 1000) * 1000;
  }

  return undefined;
}


function currentBonusPct(){
  const plan = Number(lastData && lastData.global_plan || 0);
  if (!plan) return 0;

  const success = Number((lastData && lastData.kpi && lastData.kpi.success_profit) || 0);
  const hold    = Number((lastData && lastData.kpi && lastData.kpi.hold_profit) || 0);

  const base = isFactMode ? success : (success + hold);
  const fulfillment = (base / plan) * 100;

  return calcBonusPct(fulfillment);
}


function currentFulfillmentPct(){
  const plan = Number(lastData && lastData.global_plan || 0);
  if (!plan) return 0;

  const success = Number((lastData && lastData.kpi && lastData.kpi.success_profit) || 0);
  const hold    = Number((lastData && lastData.kpi && lastData.kpi.hold_profit) || 0);

  const base = isFactMode ? success : (success + hold);
  return (base / plan) * 100;
}

const WEEKDAYS_UA = ['Нд','Пн','Вт','Ср','Чт','Пт','Сб'];

function weekdayFromDate(dateStr){
  // dateStr = 'YYYY-MM-DD'
  const d = new Date(dateStr + 'T00:00:00');
  return WEEKDAYS_UA[d.getDay()];
}



/* ===================== LOADER (ONLY PERIOD CHANGE) ===================== */

const overlay = document.getElementById('loadingOverlay');
function showLoader() { if (overlay) overlay.style.display = 'flex'; }
function hideLoader() { if (overlay) overlay.style.display = 'none'; }

/* ===================== ERROR ===================== */

const errBox = document.getElementById('err');
function showError(msg) {
  errBox.style.display = 'block';
  errBox.textContent = msg;
}
function clearError() {
  errBox.style.display = 'none';
  errBox.textContent = '';
}

let schemaWarnBox = null;
function ensureSchemaWarnBox() {
  if (schemaWarnBox) return schemaWarnBox;
  schemaWarnBox = document.createElement('div');
  schemaWarnBox.id = 'schemaWarn';
  schemaWarnBox.className = 'warning';
  schemaWarnBox.style.display = 'none';
  if (errBox && errBox.parentNode) {
    errBox.parentNode.insertBefore(schemaWarnBox, errBox.nextSibling);
  }
  return schemaWarnBox;
}

async function checkSchemaAlerts() {
  try {
    const res = await fetch('schema_alerts.php?limit=500', { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();
    if (!data || data.ok !== true || !Array.isArray(data.alerts) || data.alerts.length === 0) return;

    const box = ensureSchemaWarnBox();
    const top = data.alerts.slice(0, 10).map(a => {
      const orders = Array.isArray(a.orders) && a.orders.length ? ` orders: ${a.orders.join(', ')}` : '';
      const sample = a.sample ? ` sample: ${a.sample}` : '';
      return `- [${a.type}] ${a.path}: ${a.message} (${a.count})${orders}${sample}`;
    }).join('\n');

    box.textContent =
      `System diagnostics found items that need attention.\nChecked latest ${data.checked || 0} orders.\n${top}`;
    box.style.display = 'block';
  } catch (_e) {
    // Non-blocking: the dashboard must keep working even if the schema check fails.
  }
}

/* ===================== DATE PICKER ===================== */

const now = new Date();
const fp = flatpickr(document.getElementById('range'), {
  mode: 'range',
  dateFormat: 'd.m.Y',
  defaultDate: [
    new Date(now.getFullYear(), now.getMonth(), 1),
    new Date(now.getFullYear(), now.getMonth() + 1, 0),
  ],
  locale: { firstDayOfWeek: 1 },
});

document.getElementById('apply').addEventListener('click', loadPeriod);

// bind mode radios
document.querySelectorAll('input[name="bindMode"]').forEach(r => {
  r.addEventListener('change', () => {
    bindMode = r.value;     // 'created' or 'sold'
    // Переключаем класс .active на родительском label
    document.querySelectorAll('.bind-opt').forEach(opt => {
      opt.classList.toggle('active', opt.dataset.val === bindMode);
    });
    syncCompletedFilter();
    loadPeriod();           // перезагружаем данные как при смене периода
  });
});

// на старте выставим значение из DOM (на случай если поменяешь checked)
const checkedBind = document.querySelector('input[name="bindMode"]:checked');
if (checkedBind) bindMode = checkedBind.value;
const onlyCompletedInput = document.getElementById('onlyCompleted');
if (onlyCompletedInput) {
  onlyCompleted = onlyCompletedInput.checked;
  onlyCompletedInput.addEventListener('change', () => {
    onlyCompleted = onlyCompletedInput.checked;
    if (bindMode === 'sold') loadPeriod();
  });
}

function syncCompletedFilter() {
  const filter = document.getElementById('completedFilter');
  if (!filter) return;
  filter.classList.toggle('is-hidden', bindMode !== 'sold');
}
syncCompletedFilter();


/* ===================== KPI CLICK (GLOBAL TOGGLE) ===================== */

document.getElementById('kpiGrid').addEventListener('click', (e) => {
  const card = e.target.closest('.kpi-interactive');
  if (!card) return;
  toggleFactMode();
});

function toggleFactMode() {
  if (!lastData) return;
  isFactMode = !isFactMode;
  renderAll();
}

/* ===================== CHART METRIC BUTTONS ===================== */

document.querySelectorAll('.seg-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.seg-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    chartMetric = btn.dataset.metric;
    renderChart(); // без лоадера
  });
});

/* ===================== LOAD PERIOD (FETCH) ===================== */

async function loadPeriod() {
  clearError();
  showLoader();

  try {
    const dates = fp.selectedDates || [];
    let from, to;

    if (dates.length >= 2) {
      from = iso(dates[0]);
      to = iso(dates[1]);
    } else {
      from = iso(new Date(now.getFullYear(), now.getMonth(), 1));
      to = iso(new Date(now.getFullYear(), now.getMonth() + 1, 0));
    }

    const res = await fetch(
     `motivation.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&bind=${encodeURIComponent(bindMode)}&only_completed=${onlyCompleted ? '1' : '0'}`,
     { cache: 'no-store' }
    );


    if (!res.ok) {
      const txt = await res.text().catch(() => '');
      throw new Error(`HTTP ${res.status}. ${txt || 'motivation.php error'}`);
    }

    const data = await res.json();
    if (!data || data.ok !== true) {
      throw new Error((data && data.error) || 'motivation.php error');
    }

    lastData = data;
    isFactMode = false; // при смене периода всегда "Загальне"
    renderAll();

  } catch (e) {
    showError(String((e && e.message) || e));
  } finally {
    hideLoader();
  }
}

/* ===================== RENDER ALL ===================== */

/** Populate STATUS_COLORS and SOURCE_COLORS from server meta_options */
function applyMetaOptions() {
  if (!lastData || !lastData.meta_options) return;
  var mo = lastData.meta_options;
  
  // Status colors: use label as key (matching what orders[].status contains)
  if (mo.statusId) {
    for (var id in mo.statusId) {
      var opt = mo.statusId[id];
      if (opt.color) {
        STATUS_COLORS[String(id)] = opt.color;
        STATUS_COLORS[opt.label] = opt.color;
      }
      if (!STATUS_COLORS[opt.label] && DEFAULT_STATUS_COLORS[opt.label]) {
        STATUS_COLORS[opt.label] = DEFAULT_STATUS_COLORS[opt.label];
      }
    }
  }
  
  // Source colors
  if (mo.sajt) {
    for (var id in mo.sajt) {
      var opt = mo.sajt[id];
      if (opt.color) {
        SOURCE_COLORS[opt.label] = opt.color;
      }
    }
  }
  
  // For sources without colors, generate from hash
  if (mo.sajt) {
    for (var id in mo.sajt) {
      var label = mo.sajt[id].label;
      if (!SOURCE_COLORS[label]) {
        SOURCE_COLORS[label] = hashColor(label);
      }
    }
  }
}

function renderAll() {
  if (!lastData) return;
  applyMetaOptions();
  renderKpi();
  renderManagers();
  renderOrders();
  renderChart();
  renderSourcesPie();
}

/* ===================== KPI ===================== */
function renderKpi() {
  const d = lastData;

  const successTurnover = Number(d.kpi.success_amount || 0);
  const holdTurnover    = Number(d.kpi.hold_amount || 0);

  const successProfit = Number(d.kpi.success_profit || 0);
  const holdProfit    = Number(d.kpi.hold_profit || 0);

  const totalTurnover = successTurnover + holdTurnover;
  const totalProfit   = successProfit + holdProfit;

  // --- fulfillment & bonus follow current mode ---
  const plan = Number(d.global_plan || 0);
  const baseForPlan = isFactMode ? successProfit : totalProfit; // <-- profit!
  const fulfillmentPct = currentFulfillmentPct();
  const bonusPct = calcBonusPct(fulfillmentPct);

  // meta under plan
  document.getElementById('kpiPlanMeta').textContent =
    `Виконання: ${fmtPct(fulfillmentPct, 1)} • Бонус: ${fmtPct(bonusPct, 2)}`;

  // labels
  document.getElementById('lblTurnover').textContent =
    isFactMode ? 'Оборот (Факт)' : 'Оборот (Загальний)';
  document.getElementById('lblProfit').textContent =
    isFactMode ? 'Доход (Факт)' : 'Доход (Загальний)';

  // TOP VALUES
  // ПЛАН — завжди той самий
  document.getElementById('kpiPlan').textContent =
    fmtMoney(plan);

  document.getElementById('kpiTurnover').textContent =
    fmtMoney(isFactMode ? successTurnover : totalTurnover);

  document.getElementById('kpiProfit').textContent =
    fmtMoney(isFactMode ? successProfit : totalProfit);

  document.getElementById('kpiOrders').textContent =
    String(isFactMode ? Number(d.kpi.success_orders || 0)
                      : Number(d.kpi.total_orders || 0));

  // bottom blocks (animated)
  const show = isFactMode;
  document.getElementById('holdPlanBlock').classList.toggle('show', show);
  document.getElementById('holdTurnoverBlock').classList.toggle('show', show);
  document.getElementById('holdProfitBlock').classList.toggle('show', show);
  document.getElementById('refusalBlock').classList.toggle('show', show);

  // bottom values
  document.getElementById('kpiPlanHold').textContent =
    fmtMoney(holdTurnover);
  document.getElementById('kpiTurnoverHold').textContent =
    fmtMoney(holdTurnover);
  document.getElementById('kpiProfitHold').textContent =
    fmtMoney(holdProfit);

  document.getElementById('kpiRefusal').textContent =
    fmtPct(Number(d.kpi.reject_pct || 0), 1);
}




/* ===================== MANAGERS TABLE ===================== */

function renderManagers(){
  const tbody = document.getElementById('mgrTbody');
  tbody.innerHTML = '';

  lastData.rows.forEach(r => {
    const amount = isFactMode ? Number(r.fact_amount) : Number(r.total_amount);
    const profit = isFactMode ? Number(r.fact_profit) : Number(r.total_profit);

    const salary = Number(r.salary || 0);
    const bonusPct = Number(r.bonus_pct || 0);
    const bonusAmount = profit * (bonusPct / 100.0);
    const totalPay = salary + bonusAmount;

    tbody.insertAdjacentHTML('beforeend', `
      <tr>
        <td>${r.manager_name}</td>
        <td class="r">${fmtMoney(r.daily_rate)}</td>
        <td class="r">${r.worked_days}</td>
        <td class="r">${fmtMoney(salary)}</td>
        <td class="r">${fmtMoney(amount)}</td>
        <td class="r">${fmtMoney(profit)}</td>
        <td class="r">${fmtMoney(bonusAmount)}</td>
        <td class="r"><strong>${fmtMoney(totalPay)}</strong></td>
      </tr>
    `);
  });
}


function darkerBorder(rgbStr){
  const rgb = parseCssColor(rgbStr);
  if(!rgb) return "rgba(0,0,0,.14)";
  const r = Math.max(0, Math.floor(rgb[0] * 0.85));
  const g = Math.max(0, Math.floor(rgb[1] * 0.85));
  const b = Math.max(0, Math.floor(rgb[2] * 0.85));
  return `rgb(${r},${g},${b})`;
}

/* ===================== ORDERS TABLE ===================== */
const STATUS_TEXT_WHITE = new Set([
  "Відмова",
  "Отримано",
  "Видалений",
  "Відправлено",
  "На відправку"
]);

function renderOrders(){
  const tbody = document.getElementById('ordersTbody');
  tbody.innerHTML = '';

  sortOrders((lastData && lastData.orders) || []).forEach(o => {
    const bg = o.status_color || STATUS_COLORS[String(o.status_id)] || STATUS_COLORS[o.status] || DEFAULT_STATUS_COLORS[o.status] || "rgb(230,230,230)";
    const border = darkerBorder(bg);

    // белый для выбранных статусов + для всех “тёмных” автоматически
    const auto = contrastTextColor(bg); // "#fff" или "#111"
    const fg = STATUS_TEXT_WHITE.has(o.status) ? "#fff" : auto;

    tbody.insertAdjacentHTML('beforeend', `
      <tr>
        <td class="c">
          <a
            href="https://gusar.salesdrive.me/ua/index.html?formId=1#/order/update/${o.id}"
            target="_blank"
            class="order-link"
          >${o.id}</a>
        </td>
        <td>${o.source != null ? o.source : ''}</td>
        <td>
          <span class="status-badge" style="background:${bg};color:${fg};border-color:${border}">
            ${o.status != null ? o.status : ''}
          </span>
        </td>
        <td>${o.manager != null ? o.manager : ''}</td>
      </tr>
    `);
  });
}



/* ===================== CHART ===================== */

function ensureChart() {
  if (salesChart) return salesChart;

  const ctx = document.getElementById('salesChart');
  if (!ctx) return null;

  salesChart = new Chart(ctx, {
    type: 'line',
    data: { labels: [], datasets: [] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: true } },
      scales: {
        x: { ticks: { maxRotation: 0 } },
        y: { beginAtZero: true }
      }
    }
  });
  return salesChart;
}

function renderChart() {
  const c = ensureChart();
  if (!c || !lastData) return;

  const s = lastData.series || {};
  const rawLabels = Array.isArray(s.labels) ? s.labels : [];

  const success = Array.isArray(s.success && s.success[chartMetric]) ? s.success[chartMetric] : [];
  const hold    = Array.isArray(s.hold && s.hold[chartMetric])    ? s.hold[chartMetric]    : [];
  const total   = Array.isArray(s.total && s.total[chartMetric])   ? s.total[chartMetric]   : [];

  // Оставляем даты как labels (НЕ меняем на "Пн/Вт"!)
  c.data.labels = rawLabels;

  // X-axis: показываем дни недели
  if (c.options && c.options.scales && c.options.scales.x && c.options.scales.x.ticks) {
    c.options.scales.x.ticks.callback = function(value, index){
      const dt = rawLabels[index];
      return dt ? weekdayFromDate(dt) : '';
    };
  }

  if (!isFactMode) {
    c.data.datasets = [{
      label: chartMetric === 'turnover'
        ? 'Загальний оборот'
        : chartMetric === 'profit'
          ? 'Загальний доход'
          : 'Всього замовлень',
      data: total.map(x => Number(x) || 0),
    }];
  } else {
    c.data.datasets = [
      {
        label: chartMetric === 'turnover'
          ? 'Факт (Оборот)'
          : chartMetric === 'profit'
            ? 'Факт (Доход)'
            : 'Факт (Замовлення)',
        data: success.map(x => Number(x) || 0),
      },
      {
        label: chartMetric === 'turnover'
          ? 'Утрим (Оборот)'
          : chartMetric === 'profit'
            ? 'Утрим (Доход)'
            : 'Утрим (Замовлення)',
        data: hold.map(x => Number(x) || 0),
      }
    ];
  }

  const canvas = document.getElementById('salesChart');
  setCanvasScrollWidth(canvas, rawLabels.length, 90, 1100);

  const visibleValues = c.data.datasets.flatMap(ds => Array.isArray(ds.data) ? ds.data : []);
  c.options.scales.y.max = calcReadableAxisMax(visibleValues);

  // After CSS width changes, rebuild canvas bitmap size (otherwise Chart stretches a tiny buffer → blur).
  if (typeof c.resize === 'function') {
    c.resize();
  }

  c.update();
}

/*====================== PIE =====================*/


function ensureSourcesPie(){
  if (sourcesPieChart) return sourcesPieChart;

  const el = document.getElementById('sourcesPie');
  if (!el) return null;

  sourcesPieChart = new Chart(el, {
    type: 'doughnut',
    data: { labels: [], datasets: [{ data: [] }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed || 0;
              const arr = ctx.chart.data.datasets[0].data || [];
              const sum = arr.reduce((a,b)=>a+(+b||0),0) || 0;
              const pct = sum ? ((v/sum)*100).toFixed(1) : '0.0';
              return `${ctx.label}: ${v} (${pct}%)`;
            }
          }
        }
      }
    }
  });

  return sourcesPieChart;
}

function renderSourcesPie(){
  const p = ensureSourcesPie();
  if (!p || !lastData) return;

  const arr = isFactMode
    ? ((lastData.sources && lastData.sources.success) || [])
    : ((lastData.sources && lastData.sources.total) || []);

  const labels = arr.map(x => x.label);
  const data = arr.map(x => x.count);

  const bg = labels.map(lbl => SOURCE_COLORS[lbl] || "rgb(0,0,0)");

  p.data.labels = labels;
  p.data.datasets[0].data = data;
  p.data.datasets[0].backgroundColor = bg;
  p.data.datasets[0].borderWidth = 0;

  p.update();
}


document.querySelectorAll('th.sortable').forEach(th => {
  th.addEventListener('click', () => {
    const key = th.dataset.key;

    // toggle direction
    if (ordersSort.key === key) {
      ordersSort.dir = ordersSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
      ordersSort.key = key;
      ordersSort.dir = 'asc';
    }

    // reset classes
    document.querySelectorAll('th.sortable')
      .forEach(x => x.classList.remove('asc', 'desc'));

    th.classList.add(ordersSort.dir);

    renderOrders(); // перерисовываем таблицу
  });
});



/* ===================== INIT ===================== */

hideLoader();
loadPeriod();
checkSchemaAlerts();



/*==================== MENU ========================*/

(function initDrawer(){
  const btn = document.getElementById('menuBtn');
  const overlay = document.getElementById('drawerOverlay');
  const closeBtn = document.getElementById('drawerClose');
  const drawer = document.getElementById('drawer');

  if (!btn || !overlay || !closeBtn || !drawer) return;

  function openDrawer(){
    document.body.classList.add('drawer-open');
    drawer.setAttribute('aria-hidden', 'false');
    overlay.setAttribute('aria-hidden', 'false');
  }
  function closeDrawer(){
    document.body.classList.remove('drawer-open');
    drawer.setAttribute('aria-hidden', 'true');
    overlay.setAttribute('aria-hidden', 'true');
  }

  btn.addEventListener('click', openDrawer);
  closeBtn.addEventListener('click', closeDrawer);
  overlay.addEventListener('click', closeDrawer);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDrawer();
  });

  const settingsBtn = document.getElementById('settingsBtn');
  if (settingsBtn){
    settingsBtn.addEventListener('click', () => {
      closeDrawer();
      alert('Налаштування — скоро додамо');
    });
  }
})();
