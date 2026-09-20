<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard</title>
  <link rel="icon" href="Logo.png">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="dashboard.css?v=13">

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <!-- Chart.js needs eval/new Function. If charts do not render, your CSP must allow it, e.g. script-src ... https://cdn.jsdelivr.net 'unsafe-eval' (set on the server / nginx / Cloudflare). -->
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
        <input type="radio" name="bindMode" value="sold" checked> <span data-i18n="by_update_date">По даті оновлення</span>
      </label>
      <label class="bind-opt" data-val="created">
        <input type="radio" name="bindMode" value="created"> <span data-i18n="by_order_date">До дати заявок</span>
      </label>
    </div>
    <label class="completed-filter" id="completedFilter">
      <input type="checkbox" id="onlyCompleted" checked>
      <span data-i18n="only_completed">Тільки завершені</span>
    </label>

  </div>
</header>

<div class="page">

  <div id="err" class="error" style="display:none;"></div>

  <!-- KPI (click any card => toggle global Fact/Hold mode) -->
  <div class="kpi-grid" id="kpiGrid">
    <button class="kpi-card kpi-interactive" type="button">
      <div class="kpi-label" data-i18n="plan">План</div>
      <div class="kpi-value" id="kpiPlan">—</div>
      <div class="kpi-meta" id="kpiPlanMeta">—</div>

      <div class="kpi-hold-block" id="holdPlanBlock">
        <span class="kpi-sub-label" data-i18n="hold">УТРИМ:</span>
        <span id="kpiPlanHold" class="text-warning">0</span>
      </div>
    </button>

    <button class="kpi-card kpi-interactive" type="button">
      <div class="kpi-label" id="lblProfit" data-i18n="profit">Прибуток</div>
      <div class="kpi-value text-info" id="kpiProfit">—</div>

      <div class="kpi-hold-block" id="holdProfitBlock">
        <span class="kpi-sub-label" data-i18n="hold">УТРИМ:</span>
        <span id="kpiProfitHold" class="text-warning">0</span>
      </div>
    </button>

    <button class="kpi-card kpi-interactive" type="button">
      <div class="kpi-label" id="lblTurnover" data-i18n="turnover">Оборот</div>
      <div class="kpi-value text-success" id="kpiTurnover">—</div>

      <div class="kpi-hold-block" id="holdTurnoverBlock">
        <span class="kpi-sub-label" data-i18n="hold">УТРИМ:</span>
        <span id="kpiTurnoverHold" class="text-warning">0</span>
      </div>
    </button>

    <button class="kpi-card kpi-interactive" type="button">
      <div class="kpi-label" data-i18n="checks">Чеків:</div>
      <div class="kpi-value" id="kpiOrders">—</div>

      <div class="kpi-hold-block" id="refusalBlock">
        <span class="kpi-sub-label" data-i18n="refusals">Відмов:</span>
        <span id="kpiRefusal" class="text-danger">—</span>
      </div>
    </button>
  </div>

  <!-- Chart -->
  <div class="card">
    <div class="card-head">
      <div class="card-title" data-i18n="sales_chart">Графік продажів</div>
      <div class="seg">
        <button class="seg-btn active" data-metric="turnover" data-i18n="turnover">Оборот</button>
        <button class="seg-btn" data-metric="profit" data-i18n="profit">Прибуток</button>
        <button class="seg-btn" data-metric="orders" data-i18n="orders">Замовлення</button>
      </div>
    </div>
  <div class="card-body chart-split">
    <div class="chart-left">
      <div class="chart-scroll">
        <canvas id="salesChart"></canvas>
      </div>
    </div>

    <div class="chart-right">
      <div class="pie-title" data-i18n="order_sources">Джерела замовлень</div>
      <div class="pie-wrap">
        <canvas id="sourcesPie"></canvas>
      </div>
    </div>
   </div>
  </div>

  <!-- Managers table -->
  <div class="card">
    <div class="card-head">
      <div class="card-title" data-i18n="workers">Працівники</div>
      <div class="card-sub"></div>
    </div>
    <div class="card-body p0">
      <div class="table-wrap">
        <table class="tbl">
          <thead>
          <tr>
            <th data-i18n="manager">Менеджер</th>
            <th class="r" data-i18n="daily_rate">Ставка/день</th>
            <th class="r" data-i18n="days">Днів</th>
            <th class="r" data-i18n="salary">Ставка</th>
            <th class="r" data-i18n="turnover">Оборот</th>
            <th class="r" data-i18n="profit">Прибуток</th>
            <th class="r" data-i18n="bonus">Бонус</th>
            <th class="r" data-i18n="total">Усього</th>
          </tr>
          </thead>
          <tbody id="mgrTbody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Orders table (NO amount column) -->
  <div class="card">
    <div class="card-head">
      <div class="card-title" data-i18n="orders">Замовлення</div>
      <div class="card-sub"></div>
    </div>
    <div class="card-body p0">
      <div class="table-wrap" style="max-height:520px; overflow:auto;">
        <table class="tbl">
          <thead>
          <tr>
            <th class="r">ID</th>
            <th class="sortable" data-key="source">
              <span data-i18n="source">Джерело</span> <span class="sort-arrows">↕</span>
            </th>
            <th class="sortable" data-key="status">
              <span data-i18n="status">Статус</span> <span class="sort-arrows">↕</span>
            </th>
            <th class="sortable" data-key="manager">
              <span data-i18n="responsible">Відповідальний</span> <span class="sort-arrows">↕</span>
            </th>

          </tr>
          </thead>
          <tbody id="ordersTbody"></tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- Loader overlay (coin animation) -->
<div id="loadingOverlay" style="display:none">
  <div class="spinner-3d">
    <div class="spinner-face face-front"><img src="Logo.png" alt=""></div>
    <div class="spinner-face face-back"><img src="Logo.png" alt=""></div>
  </div>
</div>


<div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"></div>

<aside id="drawer" class="drawer" aria-hidden="true">
  <div class="drawer-head">
    <div class="drawer-title" data-i18n="menu">Меню</div>
    <button class="icon-btn" id="drawerClose" type="button" aria-label="Закрити" data-i18n-aria="close">
      ✕
    </button>
  </div>

  <nav class="drawer-nav">
    <a class="drawer-link" href="index.php" data-i18n="nav_days">Звіт по днях</a>
    <a class="drawer-link" href="cities.php" data-page="cities" data-i18n="nav_cities">Звіт по містах</a>
    <a class="drawer-link" href="products.php" data-i18n="nav_products">Звіт по товарах</a>
    <a class="drawer-link" href="settings.php" data-i18n="nav_settings">Налаштування</a>
  </nav>
</aside>

<script src="i18n.js?v=1"></script>
<script src="dashboard.js?v=9"></script>
</body>
</html>
