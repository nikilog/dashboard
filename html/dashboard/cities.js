/* ===================== COLORS (same as main) ===================== */

const SOURCE_COLORS = {
  "Епіцентр": "rgb(16,96,193)",
  "Gusar.in.ua": "rgb(125,24,37)",
  "OLX": "rgb(35,229,219)",
  "Hubber": "rgb(209,209,209)",
  "Instagram": "rgb(251,18,137)",
  "Prom": "rgb(127,5,229)",
  "Rozetka": "rgb(5,188,82)",
  "Прямий дзвінок": "rgb(245,245,60)",
};

const STATUS_COLORS = {
  "Новий": "rgb(255,255,255)",
  "В обробці": "rgb(208,208,208)",
  "Очікується оплата": "rgb(27,180,167)",
  "Підтверджено": "rgb(243,231,109)",
  "Чернетка": "rgb(237,204,216)",
  "На відправку": "rgb(236,118,31)",
  "Відправлено": "rgb(55,163,223)",
  "Отримано": "rgb(43,57,194)",
  "Резерв": "rgb(232,251,196)",
  "Повернення в процесі": "rgb(255,120,120)",
  "Переадресація повернення": "rgb(235,166,127)",
  "Продажа": "rgb(18,220,43)",
  "Закрито": "rgb(66,161,0)",
  "Відмова": "rgb(255,0,0)",
  "Повернення": "rgb(255,0,0)",
  "Видалений": "rgb(114,114,114)",
};

const STATUS_TEXT_WHITE = new Set([
  "Відмова",
  "Отримано",
  "Видалений",
  "Відправлено",
  "На відправку"
]);

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

function contrastTextColor(rgbStr){
  const m = rgbStr.match(/(\d+)\D+(\d+)\D+(\d+)/);
  if(!m) return "#111";
  const r = Number(m[1]), g = Number(m[2]), b = Number(m[3]);
  const y = (r*299 + g*587 + b*114) / 1000;
  return y >= 150 ? "#111" : "#fff";
}

function darkerBorder(rgbStr){
  const m = rgbStr.match(/(\d+)\D+(\d+)\D+(\d+)/);
  if(!m) return "rgba(0,0,0,.14)";
  const r = Math.max(0, Math.floor(Number(m[1]) * 0.85));
  const g = Math.max(0, Math.floor(Number(m[2]) * 0.85));
  const b = Math.max(0, Math.floor(Number(m[3]) * 0.85));
  return `rgb(${r},${g},${b})`;
}

/* ===================== GLOBAL STATE ===================== */

let lastData = null;
let isFactMode = false;
let chartMetric = 'turnover';   // turnover | profit | orders
let bindMode = 'sold';       // created | sold

let selectedRegion = null;      // when set -> chart shows cities in region
let geoChart = null;

let ordersSort = { key: null, dir: 'asc' };

/* ===================== HELPERS ===================== */

function fmtMoney(x) {
  return Number(x || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
}
function fmtPct(x, d = 1) {
  if (x === null || x === undefined || Number.isNaN(Number(x))) return '—';
  return `${Number(x).toFixed(d)}%`;
}
function iso(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}

function cityShortLabel(full) {
  const s = String(full || '').trim();
  if (!s) return '';
  const cutPoints = [s.indexOf(','), s.indexOf('(')].filter(i => i > 0);
  const i = cutPoints.length ? Math.min(...cutPoints) : -1;
  return (i === -1 ? s : s.slice(0, i)).trim();
}

function chartAxisLabel(full) {
  const label = cityShortLabel(full);
  if (label.length <= 18) return label;

  const words = label.split(/\s+/).filter(Boolean);
  const lines = [];
  let line = '';
  for (const word of words) {
    const next = line ? `${line} ${word}` : word;
    if (next.length > 18 && line) {
      lines.push(line);
      line = word;
    } else {
      line = next;
    }
    if (lines.length === 2) break;
  }
  if (line && lines.length < 2) lines.push(line);

  if (lines.length === 1 && lines[0].length > 22) {
    return lines[0].slice(0, 21) + '…';
  }
  return lines;
}

function calcBonusPct(fulfillmentPct){
  if (fulfillmentPct == null || !isFinite(fulfillmentPct)) return 0;
  if (fulfillmentPct <= 100) return Math.min(1, fulfillmentPct * 0.01);
  return Math.min(2, 1 + (fulfillmentPct - 100) * 0.01);
}

function currentBonusPct(){
  const plan = Number(lastData?.global_plan || 0);
  if (!plan) return 0;

  const successProfit = Number(lastData?.kpi?.success_profit || 0);
  const holdProfit    = Number(lastData?.kpi?.hold_profit || 0);
  const base = isFactMode ? successProfit : (successProfit + holdProfit);
  const fulfillment = (base / plan) * 100;

  return calcBonusPct(fulfillment);
}

function currentFulfillmentPct(){
  const plan = Number(lastData?.global_plan || 0);
  if (!plan) return 0;

  const successProfit = Number(lastData?.kpi?.success_profit || 0);
  const holdProfit    = Number(lastData?.kpi?.hold_profit || 0);

  const base = isFactMode ? successProfit : (successProfit + holdProfit);
  return (base / plan) * 100;
}

function showLoader(){
  const o = document.getElementById('loadingOverlay');
  if (o) o.style.display = 'flex';
}
function hideLoader(){
  const o = document.getElementById('loadingOverlay');
  if (o) o.style.display = 'none';
}

const errBox = document.getElementById('err');
function showError(msg){
  if (!errBox) return;
  errBox.style.display = 'block';
  errBox.textContent = msg;
}
function clearError(){
  if (!errBox) return;
  errBox.style.display = 'none';
  errBox.textContent = '';
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

document.getElementById('apply').addEventListener('click', () => {
  selectedRegion = null;
  loadPeriod();
});

// bind radios
document.querySelectorAll('input[name="bindMode"]').forEach(r => {
  r.addEventListener('change', () => {
    bindMode = r.value;
    // Переключаем класс .active на родительском label
    document.querySelectorAll('.bind-opt').forEach(opt => {
      opt.classList.toggle('active', opt.dataset.val === bindMode);
    });
    selectedRegion = null;
    loadPeriod();
  });
});
const checkedBind = document.querySelector('input[name="bindMode"]:checked');
if (checkedBind) bindMode = checkedBind.value;

/* ===================== KPI toggle (same global toggle) ===================== */

document.getElementById('kpiGrid').addEventListener('click', (e) => {
  const card = e.target.closest('.kpi-interactive');
  if (!card) return;
  if (!lastData) return;
  isFactMode = !isFactMode;
  renderAll();
});

/* ===================== metric buttons ===================== */

document.querySelectorAll('.seg-btn[data-metric]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.seg-btn[data-metric]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    chartMetric = btn.dataset.metric;
    renderGeoChart();
  });
});

document.getElementById('geoBackBtn').addEventListener('click', () => {
  selectedRegion = null;
  renderGeoChart();
  renderOrders(); // вернем полный список по текущему периоду
});

/* ===================== FETCH ===================== */

async function loadPeriod(){
  clearError();
  showLoader();

  try{
    const dates = fp.selectedDates || [];
    let from, to;

    if (dates.length >= 2){
      from = iso(dates[0]);
      to   = iso(dates[1]);
    } else {
      from = iso(new Date(now.getFullYear(), now.getMonth(), 1));
      to   = iso(new Date(now.getFullYear(), now.getMonth() + 1, 0));
    }

    const url = `cities_report.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&bind=${encodeURIComponent(bindMode)}`;
    const res = await fetch(url, { cache: 'no-store' });

    if (!res.ok){
      const txt = await res.text().catch(()=> '');
      throw new Error(`HTTP ${res.status}. ${txt || 'cities_report.php error'}`);
    }

    const data = await res.json();
    if (!data || data.ok !== true) throw new Error(data?.error || 'cities_report.php error');

    lastData = data;
    isFactMode = false;
    renderAll();

  }catch(e){
    showError(String(e?.message || e));
  }finally{
    hideLoader();
  }
}

/* ===================== RENDER ALL ===================== */

function renderAll(){
  if (!lastData) return;
  renderKpi();
  renderGeoChart();
  renderOrders();
}

/* ===================== KPI ===================== */

function renderKpi(){
  const d = lastData;

  const successTurnover = Number(d.kpi.success_amount || 0);
  const holdTurnover    = Number(d.kpi.hold_amount || 0);

  const successProfit = Number(d.kpi.success_profit || 0);
  const holdProfit    = Number(d.kpi.hold_profit || 0);

  const totalTurnover = successTurnover + holdTurnover;
  const totalProfit   = successProfit + holdProfit;

  const plan = Number(d.global_plan || 0);
  const fulfillmentPct = currentFulfillmentPct();
  const bonusPct = calcBonusPct(fulfillmentPct);

  document.getElementById('kpiPlan').textContent = fmtMoney(plan);
  document.getElementById('kpiPlanMeta').textContent =
    `Виконання: ${fmtPct(fulfillmentPct, 1)} • Бонус: ${fmtPct(bonusPct, 2)}`;

  document.getElementById('lblTurnover').textContent = isFactMode ? 'Оборот (Факт)' : 'Оборот (Загальний)';
  document.getElementById('lblProfit').textContent   = isFactMode ? 'Доход (Факт)'  : 'Доход (Загальний)';

  document.getElementById('kpiTurnover').textContent = fmtMoney(isFactMode ? successTurnover : totalTurnover);
  document.getElementById('kpiProfit').textContent   = fmtMoney(isFactMode ? successProfit   : totalProfit);

  const totalOrders = Number(d.kpi.total_orders || 0);
  const successOrders = Number(d.kpi.success_orders || 0);
  document.getElementById('kpiOrders').textContent = String(isFactMode ? successOrders : totalOrders);

  // bottom blocks only in Fact mode
  const show = isFactMode;
  document.getElementById('holdPlanBlock').classList.toggle('show', show);
  document.getElementById('holdTurnoverBlock').classList.toggle('show', show);
  document.getElementById('holdProfitBlock').classList.toggle('show', show);
  document.getElementById('refusalBlock').classList.toggle('show', show);

  document.getElementById('kpiPlanHold').textContent     = fmtMoney(holdTurnover);
  document.getElementById('kpiTurnoverHold').textContent = fmtMoney(holdTurnover);
  document.getElementById('kpiProfitHold').textContent   = fmtMoney(holdProfit);
  document.getElementById('kpiRefusal').textContent      = fmtPct(Number(d.kpi.reject_pct || 0), 1);
}

/* ======================= GEO CHART ======================= */

function ensureGeoChart() {
  if (geoChart) return geoChart;

  const canvas = document.getElementById('geoChart');
  if (!canvas) return null;

  geoChart = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{
        label: '',
        data: [],
        backgroundColor: 'rgba(54,162,235,0.35)',
        borderColor: 'rgba(54,162,235,1)',
        borderWidth: 1,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,

      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => {
              const i = items?.[0]?.dataIndex ?? 0;
              const full = geoChart?._fullLabels?.[i];
              return full || (geoChart?.data?.labels?.[i] || '');
            },
            label: (ctx) => {
              const v = ctx.parsed.y ?? 0;
              return chartMetric === 'orders' ? String(v) : fmtMoney(v);
            }
          }
        }
      },

      scales: {
        x: {
          ticks: {
            autoSkip: false,
            maxRotation: 25,
            minRotation: 0,
          }
        },
        y: {
          beginAtZero: true
        }
      },

      onClick: (evt, elements) => {
        if (!elements || !elements.length) return;

        const idx = elements[0].index;
        const label = geoChart.data.labels[idx];
        const fullLabel = geoChart._fullLabels?.[idx] || label;
        if (!fullLabel) return;

        // если сейчас показаны области → выбираем область
        if (!selectedRegion) {
          selectedRegion = fullLabel;
          renderGeoChart();   // перерисовать график (города)
          renderOrders();     // отфильтровать заказы
        }
      }
    }
  });

  return geoChart;
}

function pickBucketValue(row){
  const bucket = isFactMode ? 'success' : 'total';
  const v = row?.[bucket]?.[chartMetric] ?? 0;
  return Number(v) || 0;
}

function renderGeoChart() {
  const C = ensureGeoChart();
  if (!C || !lastData) return;

  const backBtn = document.getElementById('geoBackBtn');
  const title   = document.getElementById('geoTitle');
  const sub     = document.getElementById('geoSub');

  let items = [];

  if (!selectedRegion) {
    // show regions
    title.textContent = 'Продажі по областям';
    sub.textContent   = 'Клікни по області, щоб побачити міста';
    if (backBtn) backBtn.style.display = 'none';

    items = (lastData.geo?.regions || []).map(x => ({
      label: x.label,
      value: pickBucketValue(x),
    }));
  } else {
    // show cities for selected region
    title.textContent = `Міста: ${selectedRegion}`;
    sub.textContent   = 'Список замовлень унизу відфільтровано по області';
    if (backBtn) backBtn.style.display = 'inline-flex';

    items = (lastData.geo?.cities?.[selectedRegion] || []).map(x => ({
      label: x.label,
      value: pickBucketValue(x),
    }));
  }

  // sort desc + limit
  items.sort((a, b) => (b.value - a.value));
  items = items.slice(0, 20);

  const fullLabels = items.map(x => x.label);
  const labels = selectedRegion ? fullLabels.map(chartAxisLabel) : fullLabels.map(chartAxisLabel);
  const values = items.map(x => Number(x.value) || 0);

  C.data.labels = labels;
  C._fullLabels = fullLabels;

  // ✅ ИСПРАВЛЕНИЕ: chartMetric с маленькой буквы
  C.data.datasets[0].label =
    chartMetric === 'turnover'
      ? (isFactMode ? 'Оборот (факт)' : 'Оборот (загальний)')
      : chartMetric === 'profit'
        ? (isFactMode ? 'Дохід (факт)' : 'Дохід (загальний)')
        : (isFactMode ? 'Замовлення (факт)' : 'Замовлення (загальні)');

  C.data.datasets[0].data = values;

  // ВАЖНО: делаем X-scroll ширину по числу меток
  const wrap = document.getElementById('geoCanvasWrap');
  if (wrap) {
    const pxPerLabel = selectedRegion ? 160 : 130;
    const minWidth   = 1100;
    const w = Math.max(minWidth, labels.length * pxPerLabel);
    wrap.style.width = w + 'px';
  }

  C.resize();
  C.update();
}

/* ===================== ORDERS TABLE ===================== */

function sortOrders(list){
  if (!ordersSort.key) return list;
  const k = ordersSort.key;
  const dir = ordersSort.dir === 'asc' ? 1 : -1;

  return [...list].sort((a, b) => {
    const av = (a[k] ?? '').toString().toLowerCase();
    const bv = (b[k] ?? '').toString().toLowerCase();
    if (av < bv) return -1 * dir;
    if (av > bv) return  1 * dir;
    return 0;
  });
}

function renderOrders(){
  const tbody = document.getElementById('ordersTbody');
  tbody.innerHTML = '';

  const hint = document.getElementById('ordersHint');
  hint.textContent = selectedRegion
    ? `Фільтр: ${selectedRegion} • Сортування працює по Джерело/Статус/Відповідальний`
    : `Без фільтра області • Сортування працює по Джерело/Статус/Відповідальний`;

  let list = lastData?.orders || [];
  if (selectedRegion){
    list = list.filter(o => (o.region || '—') === selectedRegion);
  }

  list = sortOrders(list);

  list.forEach(o => {
    const bg = o.status_color || STATUS_COLORS[o.status] || DEFAULT_STATUS_COLORS[o.status] || "rgb(230,230,230)";
    const border = darkerBorder(bg);
    const auto = contrastTextColor(bg);
    const fg = STATUS_TEXT_WHITE.has(o.status) ? "#fff" : auto;

    tbody.insertAdjacentHTML('beforeend', `
      <tr>
        <td class="c">
          <a href="https://gusar.salesdrive.me/ua/index.html?formId=1#/order/update/${o.id}"
             target="_blank"
             class="order-link">${o.id}</a>
        </td>
        <td>${o.source ?? ''}</td>
        <td>
          <span class="status-badge" style="background:${bg};color:${fg};border-color:${border}">
            ${o.status ?? ''}
          </span>
        </td>
        <td>${o.manager ?? ''}</td>
        <td>${o.region ?? '—'}</td>
        <td>${o.city ?? '—'}</td>
      </tr>
    `);
  });
}

// header sort arrows
document.querySelectorAll('th.sortable').forEach(th => {
  th.addEventListener('click', () => {
    const key = th.dataset.key;
    if (ordersSort.key === key) {
      ordersSort.dir = ordersSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
      ordersSort.key = key;
      ordersSort.dir = 'asc';
    }

    document.querySelectorAll('th.sortable')
      .forEach(x => x.classList.remove('asc', 'desc'));

    th.classList.add(ordersSort.dir);
    renderOrders();
  });
});

/* ===================== DRAWER ===================== */

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

/* ===================== INIT ===================== */

hideLoader();
loadPeriod();
