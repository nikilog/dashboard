<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Dashboard - Настройки</title>
  <link rel="icon" href="Logo.png">
  <link rel="stylesheet" href="dashboard.css?v=12">
  <style>
    .settings-layout { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 14px; align-items: start; }
    .settings-tabs { display: grid; gap: 8px; }
    .settings-tab { border: 1px solid var(--line); background: #fff; border-radius: 8px; padding: 12px; text-align: left; font-weight: 800; cursor: pointer; color: var(--text); }
    .settings-tab.active { border-color: var(--main); color: var(--main); background: #fff7f8; }
    .settings-panel { display: none; }
    .settings-panel.active { display: block; }
    .settings-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; align-items: end; }
    .settings-grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .settings-field { display: grid; gap: 6px; }
    .settings-field label { font-size: 12px; color: var(--muted); font-weight: 800; }
    .settings-field input, .settings-field select, .settings-field textarea {
      width: 100%; min-height: 40px; border: 1px solid var(--line); border-radius: 8px; padding: 9px 10px; font: inherit; box-sizing: border-box; background: #fff;
    }
    .settings-field textarea { min-height: 128px; resize: vertical; }
    .settings-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .settings-message { margin-bottom: 12px; display: none; }
    .settings-message.show { display: block; }
    .settings-missing { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
    .missing-chip { border: 1px solid var(--line); border-radius: 8px; background: #fff; padding: 8px 10px; cursor: pointer; font-weight: 700; }
    .missing-chip:hover { border-color: var(--main); color: var(--main); }
    .settings-help { color: var(--muted); font-size: 13px; line-height: 1.45; }
    .danger-btn { border-color: rgba(180,35,24,.35); color: #b42318; background: #fff; }
    @media (max-width: 900px) {
      .settings-layout { grid-template-columns: 1fr; }
      .settings-grid, .settings-grid.two { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <button class="icon-btn" id="menuBtn" type="button" aria-label="Меню">
      <span class="burger"></span>
    </button>
    <div class="brand">
      <img src="Logo.png" alt="Logo" class="brand-logo">
      <span class="brand-title">Настройки</span>
    </div>
  </div>
  <div class="topbar-right">
    <a class="btn-outline" href="index.php" style="width:auto;text-decoration:none;display:flex;align-items:center;justify-content:center;padding:0 14px;">Назад</a>
  </div>
</header>

<div class="page">
  <div id="settingsMsg" class="settings-message warning"></div>

  <div class="settings-layout">
    <div class="card">
      <div class="card-head">
        <div class="card-title">Разделы</div>
      </div>
      <div class="card-body">
        <div class="settings-tabs">
          <button class="settings-tab active" type="button" data-tab="options">Field options</button>
          <button class="settings-tab" type="button" data-tab="groups">Группы</button>
          <button class="settings-tab" type="button" data-tab="motivation">Мотивация</button>
          <button class="settings-tab" type="button" data-tab="products">Товары</button>
        </div>
      </div>
    </div>

    <div>
      <section id="tab-options" class="settings-panel active">
        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-title">Редактор field_options</div>
              <div class="card-sub">ID из SalesDrive и понятные названия для сайта</div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-grid">
              <div class="settings-field">
                <label>Поле</label>
                <select id="fieldSelect"></select>
              </div>
              <div class="settings-field">
                <label>ID</label>
                <input id="optionId" type="number" min="1" placeholder="14">
              </div>
              <div class="settings-field">
                <label>Название</label>
                <input id="optionLabel" type="text" placeholder="Имя / источник / организация">
              </div>
              <div class="settings-field">
                <label>Цвет</label>
                <input id="optionColor" type="text" placeholder="#22c55e">
              </div>
            </div>
            <div class="settings-actions" style="margin-top:10px;">
              <button id="saveOptionBtn" class="btn" type="button">Сохранить</button>
              <button id="clearOptionBtn" class="btn-outline" type="button" style="width:auto;padding:0 14px;">Очистить</button>
            </div>

            <div class="settings-help" style="margin-top:14px;">Найдено в заказах, но нет названия:</div>
            <div id="missingOptions" class="settings-missing"></div>
          </div>
          <div class="card-body p0">
            <div class="table-wrap">
              <table class="tbl">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Цвет</th>
                    <th>Обновлено</th>
                    <th class="r">Действия</th>
                  </tr>
                </thead>
                <tbody id="optionsTbody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <section id="tab-groups" class="settings-panel">
        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-title">Группы статусов и сотрудников</div>
              <div class="card-sub">Списки ID через запятую</div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-grid two">
              <div class="settings-field">
                <label>Успешные статусы</label>
                <input id="successStatuses" type="text">
              </div>
              <div class="settings-field">
                <label>Статусы в работе</label>
                <input id="holdStatuses" type="text">
              </div>
              <div class="settings-field">
                <label>Рабочая группа менеджеров</label>
                <input id="managersW" type="text">
              </div>
              <div class="settings-field">
                <label>Исключённые менеджеры</label>
                <input id="managersB" type="text">
              </div>
            </div>
            <div class="settings-actions" style="margin-top:12px;">
              <button id="saveGroupsBtn" class="btn" type="button">Сохранить группы</button>
            </div>
          </div>
        </div>
      </section>

      <section id="tab-motivation" class="settings-panel">
        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-title">Правила мотивации</div>
              <div class="card-sub">План, ставка и отделы для расчёта</div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-grid two">
              <div class="settings-field">
                <label>Глобальный план, грн</label>
                <input id="globalPlan" type="number" step="0.01">
              </div>
              <div class="settings-field">
                <label>Ставка по умолчанию, грн/месяц</label>
                <input id="defaultSalary" type="number" step="0.01">
              </div>
              <div class="settings-field">
                <label>Контент-менеджеры</label>
                <input id="contentManagers" type="text">
              </div>
              <div class="settings-field">
                <label>Менеджеры продаж</label>
                <input id="salesManagers" type="text">
              </div>
            </div>
            <div class="settings-field" style="margin-top:10px;">
              <label>Индивидуальные ставки, по одной строке: ID = ставка</label>
              <textarea id="managerSalaries" placeholder="14 = 17000"></textarea>
            </div>
            <div class="settings-actions" style="margin-top:12px;">
              <button id="saveMotivationBtn" class="btn" type="button">Сохранить мотивацию</button>
            </div>
          </div>
        </div>
      </section>

      <section id="tab-products" class="settings-panel">
        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-title">Справочник товаров</div>
              <div class="card-sub">Загрузка XLSX-экспорта SalesDrive в таблицу products_catalog</div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-grid two">
              <div class="settings-field">
                <label>Товаров в БД</label>
                <input id="productsTotal" type="text" readonly>
              </div>
              <div class="settings-field">
                <label>Последний импорт</label>
                <input id="productsLastImport" type="text" readonly>
              </div>
            </div>
            <div class="settings-field" style="margin-top:12px;">
              <label>Файл XLSX из SalesDrive</label>
              <input id="productsXlsx" type="file" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
            </div>
            <div class="settings-actions" style="margin-top:12px;">
              <button id="uploadProductsBtn" class="btn" type="button">Загрузить товары</button>
            </div>
            <div class="settings-help" style="margin-top:12px;">
              Загружайте полный экспорт SalesDrive: файл полностью заменяет справочник товаров. Продажи и прибыль считаются из заказов, а справочник даёт название, категорию, поставщика, производителя и текущие цены.
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<div id="drawerOverlay" class="drawer-overlay" aria-hidden="true"></div>
<aside id="drawer" class="drawer" aria-hidden="true">
  <div class="drawer-head">
    <div class="drawer-title">Меню</div>
    <button class="icon-btn" id="drawerClose" type="button" aria-label="Закрыть">×</button>
  </div>
  <nav class="drawer-nav">
    <a class="drawer-link" href="index.php">Отчёт по дням</a>
    <a class="drawer-link" href="cities.php">Отчёт по городам</a>
    <a class="drawer-link" href="products.php">Отчёт по товарам</a>
    <a class="drawer-link active" href="settings.php">Настройки</a>
  </nav>
</aside>

<script>
const state = { data: null, field: 'userId' };
const $ = (id) => document.getElementById(id);

function showMsg(text, isError = false) {
  const el = $('settingsMsg');
  el.textContent = text;
  el.className = 'settings-message show ' + (isError ? 'error' : 'warning');
}

function csv(arr) {
  return (arr || []).join(', ');
}

async function api(params, form) {
  const url = 'settings_api.php' + (params ? '?' + new URLSearchParams(params).toString() : '');
  const res = await fetch(url, form ? { method: 'POST', body: form } : undefined);
  const text = await res.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch (e) {
    const preview = text ? text.slice(0, 600) : 'empty response';
    throw new Error(`HTTP ${res.status}: ${preview}`);
  }
  if (!json) throw new Error(`HTTP ${res.status}: empty response`);
  if (!json.ok) throw new Error(json.error || 'Request failed');
  return json;
}

async function loadState(field) {
  if (field) state.field = field;
  state.data = await api({ action: 'state', field: state.field });
  state.field = state.data.selected_field;
  renderAll();
}

function renderAll() {
  const data = state.data;

  $('fieldSelect').innerHTML = data.fields.map(f => `<option value="${escapeHtml(f)}"${f === data.selected_field ? ' selected' : ''}>${escapeHtml(f)}</option>`).join('');
  $('optionsTbody').innerHTML = data.options.map(row => `
    <tr>
      <td>${row.option_id}</td>
      <td>${escapeHtml(row.option_label || '')}</td>
      <td>${escapeHtml(row.option_color || '')}</td>
      <td>${escapeHtml(row.updated_at || '')}</td>
      <td class="r">
        <button class="btn-outline" type="button" style="width:auto;padding:0 10px;" onclick="editOption(${row.option_id}, '${jsStr(row.option_label || '')}', '${jsStr(row.option_color || '')}')">Изм.</button>
        <button class="btn-outline danger-btn" type="button" style="width:auto;padding:0 10px;" onclick="deleteOption(${row.option_id})">Удалить</button>
      </td>
    </tr>
  `).join('');

  const missing = data.missing.filter(item => item.field_name === data.selected_field);
  $('missingOptions').innerHTML = missing.length
    ? missing.map(item => `<button class="missing-chip" type="button" onclick="pickMissing('${jsStr(item.field_name)}', ${item.option_id})">${escapeHtml(item.field_name)}:${item.option_id} (${item.orders})</button>`).join('')
    : '<span class="settings-help">Для этого поля неизвестных ID нет.</span>';

  $('successStatuses').value = csv(data.groups.success_statuses);
  $('holdStatuses').value = csv(data.groups.hold_statuses);
  $('managersW').value = csv(data.groups.managers_w);
  $('managersB').value = csv(data.groups.managers_b);

  $('globalPlan').value = data.rules.global_plan_uah || 0;
  $('defaultSalary').value = data.rules.default_monthly_salary_uah || 17000;
  $('contentManagers').value = csv(data.rules.content_managers);
  $('salesManagers').value = csv(data.rules.sales_managers);
  $('managerSalaries').value = Object.entries(data.rules.managers || {})
    .map(([id, rule]) => `${id} = ${rule.monthly_salary_uah || ''}`)
    .join('\n');

  const pc = data.products_catalog || {};
  $('productsTotal').value = pc.available ? (pc.total || 0) : 'Таблица недоступна';
  $('productsLastImport').value = pc.available ? (pc.last_import || pc.last_update || '—') : (pc.error || '—');
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

function jsStr(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'")
    .replace(/\n/g, '\\n');
}

function editOption(id, label, color) {
  $('optionId').value = id;
  $('optionLabel').value = label;
  $('optionColor').value = color;
  $('optionLabel').focus();
}

function pickMissing(field, id) {
  $('fieldSelect').value = field;
  state.field = field;
  $('optionId').value = id;
  $('optionLabel').value = '';
  $('optionColor').value = '';
  $('optionLabel').focus();
}

async function deleteOption(id) {
  if (!confirm('Удалить запись?')) return;
  const form = new FormData();
  form.set('action', 'delete_option');
  form.set('field_name', state.field);
  form.set('option_id', id);
  await api(null, form);
  showMsg('Запись удалена.');
  await loadState(state.field);
}

document.querySelectorAll('.settings-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.settings-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    $('tab-' + btn.dataset.tab).classList.add('active');
  });
});

$('fieldSelect').addEventListener('change', () => loadState($('fieldSelect').value).catch(e => showMsg(e.message, true)));
$('clearOptionBtn').addEventListener('click', () => {
  $('optionId').value = '';
  $('optionLabel').value = '';
  $('optionColor').value = '';
});

$('saveOptionBtn').addEventListener('click', async () => {
  try {
    const form = new FormData();
    form.set('action', 'save_option');
    form.set('field_name', $('fieldSelect').value);
    form.set('option_id', $('optionId').value);
    form.set('option_label', $('optionLabel').value);
    form.set('option_color', $('optionColor').value);
    await api(null, form);
    showMsg('Field option сохранён.');
    await loadState($('fieldSelect').value);
  } catch (e) { showMsg(e.message, true); }
});

$('saveGroupsBtn').addEventListener('click', async () => {
  try {
    const form = new FormData();
    form.set('action', 'save_groups');
    form.set('success_statuses', $('successStatuses').value);
    form.set('hold_statuses', $('holdStatuses').value);
    form.set('managers_w', $('managersW').value);
    form.set('managers_b', $('managersB').value);
    await api(null, form);
    showMsg('Группы сохранены.');
    await loadState(state.field);
  } catch (e) { showMsg(e.message, true); }
});

$('saveMotivationBtn').addEventListener('click', async () => {
  try {
    const form = new FormData();
    form.set('action', 'save_motivation');
    form.set('global_plan_uah', $('globalPlan').value);
    form.set('default_monthly_salary_uah', $('defaultSalary').value);
    form.set('content_managers', $('contentManagers').value);
    form.set('sales_managers', $('salesManagers').value);
    form.set('manager_salaries', $('managerSalaries').value);
    await api(null, form);
    showMsg('Правила мотивации сохранены.');
    await loadState(state.field);
  } catch (e) { showMsg(e.message, true); }
});

$('uploadProductsBtn').addEventListener('click', async () => {
  try {
    const input = $('productsXlsx');
    if (!input.files || !input.files[0]) {
      showMsg('Выбери XLSX-файл SalesDrive.', true);
      return;
    }
    const form = new FormData();
    form.set('action', 'upload_products_xlsx');
    form.set('products_xlsx', input.files[0]);
    $('uploadProductsBtn').disabled = true;
    showMsg('Загружаю товары, подожди немного...');
    const result = await api(null, form);
    const imported = result.import || {};
    showMsg(`Справочник заменён. Товаров: ${imported.processed || 0}, строк без ID: ${imported.skipped || 0}, повторных ID в файле: ${imported.duplicate_ids || 0}.`);
    input.value = '';
    await loadState(state.field);
  } catch (e) {
    showMsg(e.message, true);
  } finally {
    $('uploadProductsBtn').disabled = false;
  }
});

(function initDrawer(){
  const btn = $('menuBtn');
  const overlay = $('drawerOverlay');
  const closeBtn = $('drawerClose');
  const drawer = $('drawer');
  if (!btn || !overlay || !closeBtn || !drawer) return;
  function openDrawer(){ document.body.classList.add('drawer-open'); drawer.setAttribute('aria-hidden', 'false'); overlay.setAttribute('aria-hidden', 'false'); }
  function closeDrawer(){ document.body.classList.remove('drawer-open'); drawer.setAttribute('aria-hidden', 'true'); overlay.setAttribute('aria-hidden', 'true'); }
  btn.addEventListener('click', openDrawer);
  closeBtn.addEventListener('click', closeDrawer);
  overlay.addEventListener('click', closeDrawer);
})();

loadState('userId').catch(e => showMsg(e.message, true));
</script>
</body>
</html>
