<?php
// /var/www/html/dashboard/products.php
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard — Товари</title>
  <link rel="icon" href="Logo.png">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="dashboard.css?v=13">
  
  <style>
    /* Chart Layout */
    .chart-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
    .chart-col { flex: 1; min-width: 300px; background: #fff; border-radius: 12px; padding: 16px; border: 1px solid var(--line); }
    .chart-col-wide { width: 100%; background: #fff; border-radius: 12px; padding: 16px; border: 1px solid var(--line); margin-bottom: 20px; }
    .chart-h { height: 320px; position: relative; }
    .chart-h-lg { height: 500px; position: relative; }
    
    /* KPI */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .kpi-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid var(--line); display: flex; flex-direction: column; }
    .kpi-label { color: var(--muted); font-size: 13px; margin-bottom: 6px; }
    .kpi-value { font-size: 24px; font-weight: 800; color: var(--text); }
    .kpi-sub { font-size: 13px; margin-top: auto; padding-top: 8px; color: var(--muted); }
    
    /* Breadcrumbs */
    .breadcrumbs { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; font-weight: 500; font-size: 15px; }
    .crumb { cursor: pointer; color: var(--main); border-bottom: 1px dashed var(--main); }
    .crumb:hover { border-bottom-style: solid; }
    .crumb.active { color: var(--muted); border: none; cursor: default; }
    .crumb-sep { color: var(--muted); }

    /* Controls */
    .segmented { display: flex; background: #f4f5f7; padding: 4px; border-radius: 8px; gap: 4px; }
    .seg-btn { border: none; background: transparent; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; color: var(--muted); transition: all 0.2s; }
    .seg-btn.active { background: #fff; color: var(--text); box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
    
    .card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .card-title { font-size: 16px; font-weight: 700; }


  </style>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<header class="topbar">
  <div class="topbar-left">
    <button class="icon-btn" id="menuBtn" type="button" aria-label="Меню" data-i18n-aria="menu">
      <span class="burger"></span>
    </button>

    <div class="brand">
      <img src="Logo.png" alt="Logo" class="brand-logo">
      <span class="brand-title">Dashboard</span>
    </div>
  </div>

  <div class="topbar-right">
    <div class="datebar">
      <label data-i18n="period">Період</label>
      <input id="range" class="input" type="text" placeholder="Оберіть період" data-i18n-placeholder="choose_period" />
      <button id="apply" class="btn" data-i18n="refresh">Оновити</button>
    </div>

    <div class="bindbar tabs" id="bindbar">
      <label class="bind-opt active" data-val="sold">
        <input type="radio" name="bindMode" value="sold" checked> <span data-i18n="by_sale_date">До дати продаж</span>
      </label>
      <label class="bind-opt" data-val="created">
        <input type="radio" name="bindMode" value="created"> <span data-i18n="by_order_date">До дати заявок</span>
      </label>
    </div>
  </div>
</header>

<main class="page">
    
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label" data-i18n="turnover">Оборот</div>
            <div class="kpi-value" id="kpiTurnover">0 ₴</div>
            <div class="kpi-sub" data-i18n="selected_period">За обраний період</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label" data-i18n="total_profit">Чистий прибуток</div>
            <div class="kpi-value" id="kpiProfit">0 ₴</div>
            <div class="kpi-sub" id="kpiMargin">Маржа: 0%</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label" data-i18n="sold_products">Продано товарів</div>
            <div class="kpi-value" id="kpiQty">0 шт</div>
            <div class="kpi-sub" data-i18n="units_count">Кількість одиниць</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label" data-i18n="avg_check">Середній чек</div>
            <div class="kpi-value" id="kpiAvg">0 ₴</div>
            <div class="kpi-sub" data-i18n="turnover_per_qty">Оборот / Кількість</div>
        </div>
    </div>

    <div class="chart-row">
        <div class="chart-col">
            <div class="card-head">
                <div class="card-title" data-i18n="top_products">Топ товарів</div>
                <div class="segmented">
                   <button class="seg-btn active product-metric-btn" data-metric="turnover" onclick="setMetric('turnover')" data-i18n="currency_uah_short">Грн</button>
                   <button class="seg-btn product-metric-btn" data-metric="qty" onclick="setMetric('qty')" data-i18n="pieces_short">Шт</button>
                </div>
            </div>
            <div class="chart-h">
                <canvas id="catChart"></canvas>
            </div>
        </div>
        <div class="chart-col">
            <div class="card-head">
                <div class="card-title" data-i18n="top_suppliers">Топ постачальників</div>
            </div>
            <div class="chart-h">
                <canvas id="suppliersPie"></canvas>
            </div>
        </div>
    </div>

    <div class="chart-col-wide">
        <div class="card-head">
            <div class="card-title" data-i18n="details">Деталізація</div>
        </div>
        <div class="breadcrumbs" id="breadcrumbs">
            <span class="crumb active" data-i18n="all_categories">Всі категорії</span>
        </div>
        <div class="chart-h-lg">
            <canvas id="drillChart"></canvas>
        </div>
    </div>

</main>

<div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"></div>
<aside id="drawer" class="drawer" aria-hidden="true">
  <div class="drawer-head">
    <div class="drawer-title" data-i18n="menu">Меню</div>
    <button class="icon-btn" id="drawerClose" type="button" aria-label="Закрити" data-i18n-aria="close">✕</button>
  </div>
  <nav class="drawer-nav">
    <a class="drawer-link" href="index.php" data-i18n="nav_days">Звіт по днях</a>
    <a class="drawer-link" href="cities.php" data-i18n="nav_cities">Звіт по містах</a>
    <a class="drawer-link active" href="products.php" data-i18n="nav_products">Звіт по товарах</a>
    <a class="drawer-link" href="settings.php" data-i18n="nav_settings">Налаштування</a>
  </nav>
</aside>

<div id="loadingOverlay">
  <div class="spinner-3d">
    <div class="spinner-face face-front"><img src="Logo.png" alt=""></div>
    <div class="spinner-face face-back"><img src="Logo.png" alt=""></div>
  </div>
</div>

<script src="i18n.js?v=1"></script>
<script src="products.js?v=14"></script>
</body>
</html>
