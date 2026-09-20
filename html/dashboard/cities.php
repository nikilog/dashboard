<?php
// cities.php (UI)
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard • Міста</title>
  <link rel="icon" href="Logo.png" />

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <link rel="stylesheet" href="dashboard.css?v=12" />
  <style>
    .geo-head-title { min-width: 0; }
    .geo-title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .geo-title-row .card-title { min-width: 0; overflow-wrap: anywhere; }
    .geo-back-inline { flex: 0 0 auto; }
    @media (max-width: 720px) {
      .geo-head { align-items: flex-start; flex-direction: column; }
      .geo-head .segmented { width: 100%; overflow-x: auto; }
    }
  </style>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

  <div id="loadingOverlay" style="display:none;">
    <div class="spinner-3d">
      <div class="spinner-face face-front"><img src="Logo.png" alt="" /></div>
      <div class="spinner-face face-back"><img src="Logo.png" alt="" /></div>
    </div>
  </div>

  <div id="err" class="error" style="display:none;"></div>

  <div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"></div>

  <aside id="drawer" class="drawer" aria-hidden="true">
    <div class="drawer-head">
      <div class="drawer-title">Меню</div>
      <button class="icon-btn" id="drawerClose" type="button" aria-label="Закрити">✕</button>
    </div>

    <nav class="drawer-nav">
      <a class="drawer-link" href="index.php">Отчёт по дням</a>
      <a class="drawer-link active" href="cities.php">Отчёт по городам</a>
      <a class="drawer-link" href="products.php">Отчёт по товарам</a>
      <a class="drawer-link" href="settings.php">Настройки</a>
    </nav>
  </aside>

  <header class="topbar">
    <div class="topbar-left">
      <button class="icon-btn" id="menuBtn" type="button" aria-label="Меню">
        <span class="burger"></span>
      </button>

      <div class="brand">
        <img src="Logo.png" alt="Logo" class="brand-logo" />
        <span class="brand-title">Dashboard</span>
      </div>
    </div>

    <div class="topbar-right">
      <div class="datebar">
        <label>Період</label>
        <input id="range" class="input" type="text" placeholder="Оберіть період" />
        <button id="apply" class="btn">Оновити</button>
      </div>

      <div class="bindbar tabs" id="bindbar">
        <label class="bind-opt active" data-val="sold">
          <input type="radio" name="bindMode" value="sold" checked /> До дати продаж
        </label>
        <label class="bind-opt" data-val="created">
          <input type="radio" name="bindMode" value="created" /> До дати заявок
        </label>
      </div>
    </div>
  </header>

  <div class="page">

    <div id="kpiGrid" class="kpi-grid">
      <div class="kpi-card kpi-interactive">
        <div class="kpi-label">План</div>
        <div class="kpi-value" id="kpiPlan">—</div>
        <div class="kpi-meta" id="kpiPlanMeta">—</div>

        <div class="kpi-hold-block" id="holdPlanBlock">
          <span class="kpi-sub-label">Утримання:</span>
          <span id="kpiPlanHold">—</span>
        </div>
      </div>

      <div class="kpi-card kpi-interactive">
        <div class="kpi-label" id="lblProfit">Доход (Загальний)</div>
        <div class="kpi-value text-info" id="kpiProfit">—</div>

        <div class="kpi-hold-block" id="holdProfitBlock">
          <span class="kpi-sub-label">Утримання:</span>
          <span id="kpiProfitHold">—</span>
        </div>
      </div>

      <div class="kpi-card kpi-interactive">
        <div class="kpi-label" id="lblTurnover">Оборот (Загальний)</div>
        <div class="kpi-value text-success" id="kpiTurnover">—</div>

        <div class="kpi-hold-block" id="holdTurnoverBlock">
          <span class="kpi-sub-label">Утримання:</span>
          <span id="kpiTurnoverHold">—</span>
        </div>
      </div>

      <div class="kpi-card kpi-interactive">
        <div class="kpi-label">Чеків:</div>
        <div class="kpi-value" id="kpiOrders">—</div>

        <div class="kpi-hold-block" id="refusalBlock">
          <span class="kpi-sub-label">Відмови:</span>
          <span id="kpiRefusal">—</span>
        </div>
      </div>
    </div>

    <section class="card">
      <div class="card-head geo-head">
        <div class="geo-head-title">
          <div class="geo-title-row">
            <div class="card-title" id="geoTitle">Продажі по областям</div>
            <button class="seg-btn geo-back-inline" id="geoBackBtn" style="display:none;">Назад</button>
          </div>
          <div class="card-sub" id="geoSub">Клікни по області, щоб побачити міста</div>
        </div>

        <div class="segmented">
          <button class="seg-btn active" data-metric="turnover">Оборот</button>
          <button class="seg-btn" data-metric="profit">Прибуток</button>
          <button class="seg-btn" data-metric="orders">Замовлення</button>
        </div>
      </div>

      <div class="chart-scroll">
        <div class="chart-h-lg" id="geoCanvasWrap">
          <canvas id="geoChart"></canvas>
        </div>
      </div>
    </section>

    <section class="card p0">
      <div class="card-head">
        <div class="card-title">Замовлення</div>
        <div class="card-sub" id="ordersHint">Список змінюється при виборі області</div>
      </div>

      <div class="table-wrap" style="max-height:520px;">
        <table class="tbl">
          <thead>
            <tr>
              <th>ID</th>
              <th class="sortable" data-key="source">Джерело <span class="sort-arrows"></span></th>
              <th class="sortable" data-key="status">Статус <span class="sort-arrows"></span></th>
              <th class="sortable" data-key="manager">Відповідальний <span class="sort-arrows"></span></th>
              <th>Область</th>
              <th>Місто</th>
            </tr>
          </thead>
          <tbody id="ordersTbody"></tbody>
        </table>
      </div>
    </section>

  </div>

  <script src="cities.js?v=14"></script>
</body>
</html>
