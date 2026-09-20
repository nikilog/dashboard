
// /var/www/html/dashboard/products.js
let lastData = null; 
let metric = 'turnover'; 
let drillLevel = 0; 
let selCat = null;
let selSub = null;

let catChart = null;
let pieChart = null;
let drillChart = null;

document.addEventListener('DOMContentLoaded', () => {
    initUI();
    loadData();
});

window.addEventListener('i18n:changed', () => {
    if (lastData) renderAll();
});

function tr(key, fallback, params) {
    return window.DashboardI18n?.t(key, params) || fallback || key;
}

// --- Loader ---
function showLoader(show) {
    const el = document.getElementById('loadingOverlay');
    if(el) el.style.display = show ? 'flex' : 'none';
}

function initUI(){
    // Menu
    const btn = document.getElementById('menuBtn');
    const overlay = document.getElementById('drawerOverlay');
    const closeBtn = document.getElementById('drawerClose');
    const drawer = document.getElementById('drawer');

    if (btn && overlay) {
        btn.onclick = () => {
            document.body.classList.add('drawer-open');
            drawer.classList.add('show'); overlay.classList.add('show');
        };
        const close = () => {
            document.body.classList.remove('drawer-open');
            drawer.classList.remove('show'); overlay.classList.remove('show');
        };
        closeBtn.onclick = overlay.onclick = close;
    }

    // Datepicker
    if (window.flatpickr) {
        flatpickr("#range", {
            mode: "range", dateFormat: "Y-m-d",
            defaultDate: [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()],
            locale: { firstDayOfWeek: 1 }
        });
    }

    // Buttons
    document.getElementById('apply').onclick = loadData;

    // Bindbar (Створено/Факт) Logic
    const binds = document.querySelectorAll('.bind-opt');
    binds.forEach(b => {
        b.addEventListener('click', (e) => {
            // Переключаем класс active
            binds.forEach(o => o.classList.remove('active'));
            b.classList.add('active');
            
            // Выбираем radio input внутри (для формы, если нужна)
            const inp = b.querySelector('input');
            if(inp) inp.checked = true;

            // Перезагружаем данные
            loadData();
        });
    });
}

function getBindMode(){
    const el = document.querySelector('.bind-opt.active');
    return el ? el.dataset.val : 'sold';
}

// --- Load ---
async function loadData(){
    const rangeEl = document.getElementById('range');
    let [d1, d2] = (rangeEl ? rangeEl.value : '').split(' to ');
    if(!d1) d1 = new Date().toISOString().slice(0,10);
    if(!d2) d2 = d1;

    const mode = getBindMode(); // 'created' or 'fact'

    showLoader(true);

    try {
        // Передаем параметр bind
        const res = await fetch(`products_data.php?from=${d1}&to=${d2}&bind=${mode}`);
        const json = await res.json();
        
        // Агрегируем данные на клиенте (превращаем плоский список в дерево)
        processData(json);
        
        drillLevel = 0; selCat = null; selSub = null;
        renderAll();
    } catch(e){
        console.error(e);
    } finally {
        setTimeout(() => showLoader(false), 300);
    }
}

// Преобразование сырых данных в структуру для графиков
function processData(rawData){
    // Структура: { categories: {}, subcategories: {}, suppliers: {}, products: {}, productTotals: {} }
    const agg = { categories:{}, subcategories:{}, suppliers:{}, products:{}, productTotals:{} };

    rawData.forEach(item => {
        const cat = item.c || 'Невизначено';
        const sub = item.s || null;
        const sup = item.v || 'Невизначено';
        const productKey = item.pid || item.sku || item.n || 'unknown';
        const name = item.n || productKey;
        const turnover = Number(item.t || 0);
        const profit = Number(item.p || 0);
        const qty = Number(item.q || 0);

        // Categories
        if(!agg.categories[cat]) agg.categories[cat] = {turnover:0, profit:0, qty:0};
        agg.categories[cat].turnover += turnover;
        agg.categories[cat].profit += profit;
        agg.categories[cat].qty += qty;

        // Subcategories (nested by cat). Empty/same names are intentionally skipped.
        if(sub && sub !== cat){
            if(!agg.subcategories[cat]) agg.subcategories[cat] = {};
            if(!agg.subcategories[cat][sub]) agg.subcategories[cat][sub] = {label: sub, turnover:0, profit:0, qty:0};
            agg.subcategories[cat][sub].turnover += turnover;
            agg.subcategories[cat][sub].profit += profit;
            agg.subcategories[cat][sub].qty += qty;
        }

        // Suppliers
        if(!agg.suppliers[sup]) agg.suppliers[sup] = {turnover:0, profit:0, qty:0};
        agg.suppliers[sup].turnover += turnover;
        agg.suppliers[sup].profit += profit;
        agg.suppliers[sup].qty += qty;

        // Overall product top chart.
        if(!agg.productTotals[productKey]) {
            agg.productTotals[productKey] = {label: name, turnover:0, profit:0, qty:0};
        }
        agg.productTotals[productKey].turnover += turnover;
        agg.productTotals[productKey].profit += profit;
        agg.productTotals[productKey].qty += qty;

        // Products by category and optional subcategory.
        if(!agg.products[cat]) agg.products[cat] = {};
        const productBucket = sub && sub !== cat ? sub : '__direct__';
        if(!agg.products[cat][productBucket]) agg.products[cat][productBucket] = {};
        if(!agg.products[cat][productBucket][productKey]) {
            agg.products[cat][productBucket][productKey] = {label: name, turnover:0, profit:0, qty:0};
        }

        agg.products[cat][productBucket][productKey].turnover += turnover;
        agg.products[cat][productBucket][productKey].profit += profit;
        agg.products[cat][productBucket][productKey].qty += qty;
    });

    lastData = agg;
}

function renderAll(){
    if(!lastData) return;
    calcKPI();
    buildTopChart();
    buildPieChart();
    updateDrilldown();
}

function setMetric(m){
    metric = m;
    document.querySelectorAll('.product-metric-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.metric === m);
    });
    renderAll();
}

function calcKPI(){
    let t = 0, p = 0, q = 0;
    for(let k in lastData.categories){
        t += lastData.categories[k].turnover;
        p += lastData.categories[k].profit;
        q += lastData.categories[k].qty;
    }
    document.getElementById('kpiTurnover').textContent = t.toLocaleString() + ' ₴';
    document.getElementById('kpiProfit').textContent = p.toLocaleString() + ' ₴';
    document.getElementById('kpiQty').textContent = q.toLocaleString() + ' ' + tr('pieces_short', 'шт').toLowerCase();
    const avg = q > 0 ? Math.round(t / q) : 0;
    document.getElementById('kpiAvg').textContent = avg.toLocaleString() + ' ₴';
    const margin = t > 0 ? ((p/t)*100).toFixed(1) : 0;
    document.getElementById('kpiMargin').textContent = `${tr('margin', 'Маржа')}: ${margin}%`;
}

function getVal(obj){ return metric === 'turnover' ? (obj.turnover||0) : (obj.qty||0); }
function topN(obj, n=15){
    if(!obj) return [];
    const arr = Object.entries(obj).map(([k,v])=>({k, label: v.label || k, v: getVal(v)}));
    arr.sort((a,b)=>b.v - a.v);
    return arr.slice(0, n);
}

function buildTopChart(){
    const ctx = document.getElementById('catChart').getContext('2d');
    if(catChart) catChart.destroy();
    const data = topN(lastData.productTotals, 10);
    catChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(x=>x.label),
            datasets: [{
                label: metric==='turnover' ? tr('turnover', 'Оборот') : tr('units_count', 'Кількість'),
                data: data.map(x=>x.v),
                backgroundColor: '#3b82f6', borderRadius:4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: {display:false} },
            scales: {
                x: { beginAtZero: true },
                y: { ticks: { autoSkip: false } }
            }
        }
    });
}

function buildPieChart(){
    const ctx = document.getElementById('suppliersPie').getContext('2d');
    if(pieChart) pieChart.destroy();
    const data = topN(lastData.suppliers, 6);
    pieChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.map(x=>x.k),
            datasets: [{
                data: data.map(x=>x.v),
                backgroundColor: ['#ef4444', '#f97316', '#f59e0b', '#10b981', '#06b6d4', '#3b82f6'],
                borderWidth:0
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: {position:'right'} }
        }
    });
}

function categoryHasSubcategories(cat){
    return !!lastData?.subcategories?.[cat] && Object.keys(lastData.subcategories[cat]).length > 0;
}
function selectCategory(cat){
    selCat = cat;
    selSub = null;
    drillLevel = categoryHasSubcategories(cat) ? 1 : 2;
    updateDrilldown();
}
function selectSubcategory(sub){ selSub = sub; drillLevel = 2; updateDrilldown(); }
function resetDrill(){ selCat = null; selSub = null; drillLevel = 0; updateDrilldown(); }

function updateDrilldown(){
    const bc = document.getElementById('breadcrumbs');
    bc.innerHTML = '';

    const addSeparator = () => {
        const sep = document.createElement('span');
        sep.className = 'crumb-sep';
        sep.textContent = '>';
        bc.appendChild(sep);
    };
    const addCrumb = (label, active, onClick) => {
        const crumb = document.createElement('span');
        crumb.textContent = label;
        crumb.className = active ? 'crumb active' : 'crumb';
        if (!active && typeof onClick === 'function') crumb.onclick = onClick;
        bc.appendChild(crumb);
    };

    addCrumb(tr('all_categories', 'Всі категорії'), drillLevel === 0, resetDrill);
    
    if(selCat){
        addSeparator();
        addCrumb(selCat, drillLevel === 1 || (drillLevel === 2 && !selSub), () => selectCategory(selCat));
    }
    if(selSub){
        addSeparator();
        addCrumb(selSub, true);
    }

    let data = [], color = '#3b82f6';
    if(drillLevel === 0){
        data = topN(lastData.categories, 20); color = '#3b82f6';
    } else if(drillLevel === 1){
        data = topN(lastData.subcategories[selCat], 20); color = '#10b981';
    } else {
        const bucket = selSub || '__direct__';
        data = topN(lastData.products?.[selCat]?.[bucket], 20); color = '#f59e0b';
    }

    const ctx = document.getElementById('drillChart').getContext('2d');
    if(drillChart) drillChart.destroy();
    
    drillChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(x=>x.label),
            datasets: [{
                label: tr('value', 'Значення'),
                data: data.map(x=>x.v),
                backgroundColor: color, borderRadius:4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: {display:false} },
            onClick: (e, el) => {
                if(!el.length) return;
                const name = data[el[0].index].k;
                if(drillLevel === 0) selectCategory(name);
                else if(drillLevel === 1) selectSubcategory(name);
            }
        }
    });
}
