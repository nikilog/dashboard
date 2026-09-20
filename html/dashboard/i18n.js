(function () {
  const STORAGE_KEY = 'dashboard_lang';
  const DEFAULT_LANG = 'uk';
  const SUPPORTED = ['uk', 'ru'];

  const dict = {
    uk: {
      menu: 'Меню',
      close: 'Закрити',
      back: 'Назад',
      dashboard: 'Dashboard',
      settings: 'Налаштування',
      nav_days: 'Звіт по днях',
      nav_cities: 'Звіт по містах',
      nav_products: 'Звіт по товарах',
      nav_settings: 'Налаштування',
      language: 'Мова',
      period: 'Період',
      choose_period: 'Оберіть період',
      refresh: 'Оновити',
      by_update_date: 'По даті оновлення',
      by_sale_date: 'До дати продаж',
      by_order_date: 'До дати заявок',
      only_completed: 'Тільки завершені',
      plan: 'План',
      profit: 'Прибуток',
      turnover: 'Оборот',
      checks: 'Чеків:',
      hold: 'УТРИМ:',
      refusals: 'Відмов:',
      sales_chart: 'Графік продажів',
      orders: 'Замовлення',
      workers: 'Працівники',
      manager: 'Менеджер',
      daily_rate: 'Ставка/день',
      days: 'Днів',
      salary: 'Ставка',
      bonus: 'Бонус',
      total: 'Усього',
      source: 'Джерело',
      status: 'Статус',
      responsible: 'Відповідальний',
      order_sources: 'Джерела замовлень',
      cities_title: 'Продажі по областям',
      cities_hint: 'Клікни по області, щоб побачити міста',
      cities_orders_hint: 'Список змінюється при виборі області',
      city_region: 'Область',
      city: 'Місто',
      total_profit: 'Чистий прибуток',
      sold_products: 'Продано товарів',
      avg_check: 'Середній чек',
      selected_period: 'За обраний період',
      margin: 'Маржа',
      units_count: 'Кількість одиниць',
      turnover_per_qty: 'Оборот / Кількість',
      top_products: 'Топ товарів',
      top_suppliers: 'Топ постачальників',
      details: 'Деталізація',
      all_categories: 'Всі категорії',
      value: 'Значення',
      currency_uah_short: 'Грн',
      pieces_short: 'Шт',
      sections: 'Розділи',
      field_options: 'Field options',
      groups: 'Групи',
      motivation: 'Мотивація',
      products: 'Товари',
      field_options_editor: 'Редактор field_options',
      field_options_sub: 'ID із SalesDrive і зрозумілі назви для сайту',
      field: 'Поле',
      name: 'Назва',
      color: 'Колір',
      save: 'Зберегти',
      clear: 'Очистити',
      found_missing_ids: 'Знайдено в замовленнях, але немає назви:',
      updated: 'Оновлено',
      actions: 'Дії',
      status_employee_groups: 'Групи статусів і співробітників',
      comma_id_lists: 'Списки ID через кому',
      success_statuses: 'Успішні статуси',
      hold_statuses: 'Статуси в роботі',
      working_manager_group: 'Робоча група менеджерів',
      excluded_managers: 'Виключені менеджери',
      save_groups: 'Зберегти групи',
      motivation_rules: 'Правила мотивації',
      motivation_sub: 'План, ставка і відділи для розрахунку',
      global_plan: 'Глобальний план, грн',
      default_salary: 'Ставка за замовчуванням, грн/місяць',
      content_managers: 'Контент-менеджери',
      sales_managers: 'Менеджери продажів',
      individual_salaries: 'Індивідуальні ставки, по одному рядку: ID = ставка',
      save_motivation: 'Зберегти мотивацію',
      products_catalog: 'Довідник товарів',
      products_catalog_sub: 'Завантаження XLSX-експорту SalesDrive у таблицю products_catalog',
      products_in_db: 'Товарів у БД',
      last_import: 'Останній імпорт',
      salesdrive_xlsx_file: 'Файл XLSX із SalesDrive',
      upload_products: 'Завантажити товари',
      products_upload_help: 'Після завантаження файл оновлює довідник товарів по ID товару. Продажі й прибуток рахуються із замовлень, а довідник дає назву, категорію, постачальника, виробника і поточні ціни.',
      edit_short: 'Змін.',
      delete: 'Видалити',
      no_unknown_ids: 'Для цього поля невідомих ID немає.',
      choose_xlsx: 'Вибери XLSX-файл SalesDrive.',
      uploading_products: 'Завантажую товари, зачекай...',
      products_updated: 'Товари оновлено.',
      processed: 'Оброблено',
      skipped: 'Пропущено',
    },
    ru: {
      menu: 'Меню',
      close: 'Закрыть',
      back: 'Назад',
      dashboard: 'Dashboard',
      settings: 'Настройки',
      nav_days: 'Отчёт по дням',
      nav_cities: 'Отчёт по городам',
      nav_products: 'Отчёт по товарам',
      nav_settings: 'Настройки',
      language: 'Язык',
      period: 'Период',
      choose_period: 'Выберите период',
      refresh: 'Обновить',
      by_update_date: 'По дате обновления',
      by_sale_date: 'До даты продаж',
      by_order_date: 'До даты заявок',
      only_completed: 'Только завершённые',
      plan: 'План',
      profit: 'Прибыль',
      turnover: 'Оборот',
      checks: 'Чеков:',
      hold: 'УДЕРЖ:',
      refusals: 'Отказов:',
      sales_chart: 'График продаж',
      orders: 'Заказы',
      workers: 'Сотрудники',
      manager: 'Менеджер',
      daily_rate: 'Ставка/день',
      days: 'Дней',
      salary: 'Ставка',
      bonus: 'Бонус',
      total: 'Всего',
      source: 'Источник',
      status: 'Статус',
      responsible: 'Ответственный',
      order_sources: 'Источники заказов',
      cities_title: 'Продажи по областям',
      cities_hint: 'Кликни по области, чтобы увидеть города',
      cities_orders_hint: 'Список меняется при выборе области',
      city_region: 'Область',
      city: 'Город',
      total_profit: 'Чистая прибыль',
      sold_products: 'Продано товаров',
      avg_check: 'Средний чек',
      selected_period: 'За выбранный период',
      margin: 'Маржа',
      units_count: 'Количество единиц',
      turnover_per_qty: 'Оборот / Количество',
      top_products: 'Топ товаров',
      top_suppliers: 'Топ поставщиков',
      details: 'Детализация',
      all_categories: 'Все категории',
      value: 'Значение',
      currency_uah_short: 'Грн',
      pieces_short: 'Шт',
      sections: 'Разделы',
      field_options: 'Field options',
      groups: 'Группы',
      motivation: 'Мотивация',
      products: 'Товары',
      field_options_editor: 'Редактор field_options',
      field_options_sub: 'ID из SalesDrive и понятные названия для сайта',
      field: 'Поле',
      name: 'Название',
      color: 'Цвет',
      save: 'Сохранить',
      clear: 'Очистить',
      found_missing_ids: 'Найдено в заказах, но нет названия:',
      updated: 'Обновлено',
      actions: 'Действия',
      status_employee_groups: 'Группы статусов и сотрудников',
      comma_id_lists: 'Списки ID через запятую',
      success_statuses: 'Успешные статусы',
      hold_statuses: 'Статусы в работе',
      working_manager_group: 'Рабочая группа менеджеров',
      excluded_managers: 'Исключённые менеджеры',
      save_groups: 'Сохранить группы',
      motivation_rules: 'Правила мотивации',
      motivation_sub: 'План, ставка и отделы для расчёта',
      global_plan: 'Глобальный план, грн',
      default_salary: 'Ставка по умолчанию, грн/месяц',
      content_managers: 'Контент-менеджеры',
      sales_managers: 'Менеджеры продаж',
      individual_salaries: 'Индивидуальные ставки, по одной строке: ID = ставка',
      save_motivation: 'Сохранить мотивацию',
      products_catalog: 'Справочник товаров',
      products_catalog_sub: 'Загрузка XLSX-экспорта SalesDrive в таблицу products_catalog',
      products_in_db: 'Товаров в БД',
      last_import: 'Последний импорт',
      salesdrive_xlsx_file: 'Файл XLSX из SalesDrive',
      upload_products: 'Загрузить товары',
      products_upload_help: 'После загрузки файл обновляет справочник товаров по ID товара. Продажи и прибыль считаются из заказов, а справочник даёт название, категорию, поставщика, производителя и текущие цены.',
      edit_short: 'Изм.',
      delete: 'Удалить',
      no_unknown_ids: 'Для этого поля неизвестных ID нет.',
      choose_xlsx: 'Выбери XLSX-файл SalesDrive.',
      uploading_products: 'Загружаю товары, подожди немного...',
      products_updated: 'Товары обновлены.',
      processed: 'Обработано',
      skipped: 'Пропущено',
    }
  };

  function getLang() {
    const saved = localStorage.getItem(STORAGE_KEY);
    return SUPPORTED.includes(saved) ? saved : DEFAULT_LANG;
  }

  function t(key, params) {
    const lang = getLang();
    let value = dict[lang]?.[key] ?? dict[DEFAULT_LANG]?.[key] ?? key;
    if (params) {
      Object.entries(params).forEach(([name, replacement]) => {
        value = value.replaceAll(`{${name}}`, String(replacement));
      });
    }
    return value;
  }

  function applyI18n(root) {
    const scope = root || document;
    const lang = getLang();
    document.documentElement.lang = lang;

    scope.querySelectorAll('[data-i18n]').forEach((el) => {
      el.textContent = t(el.dataset.i18n);
    });
    scope.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      el.setAttribute('placeholder', t(el.dataset.i18nPlaceholder));
    });
    scope.querySelectorAll('[data-i18n-title]').forEach((el) => {
      el.setAttribute('title', t(el.dataset.i18nTitle));
    });
    scope.querySelectorAll('[data-i18n-aria]').forEach((el) => {
      el.setAttribute('aria-label', t(el.dataset.i18nAria));
    });

    const switcher = document.querySelector('[data-lang-switcher]');
    if (switcher) {
      switcher.querySelectorAll('[data-lang]').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.lang === lang);
      });
    }
  }

  function ensureLanguageSwitcher() {
    const drawer = document.querySelector('.drawer');
    if (!drawer || drawer.querySelector('[data-lang-switcher]')) return;

    const box = document.createElement('div');
    box.className = 'lang-switcher';
    box.dataset.langSwitcher = '1';
    box.innerHTML = `
      <div class="lang-switcher-label" data-i18n="language"></div>
      <div class="lang-switcher-buttons">
        <button type="button" data-lang="uk">UK</button>
        <button type="button" data-lang="ru">RU</button>
      </div>
    `;
    drawer.appendChild(box);

    box.querySelectorAll('[data-lang]').forEach((btn) => {
      btn.addEventListener('click', () => {
        localStorage.setItem(STORAGE_KEY, btn.dataset.lang);
        applyI18n(document);
        window.dispatchEvent(new CustomEvent('i18n:changed', { detail: { lang: btn.dataset.lang } }));
      });
    });
  }

  window.DashboardI18n = { t, apply: applyI18n, lang: getLang };

  document.addEventListener('DOMContentLoaded', () => {
    ensureLanguageSwitcher();
    applyI18n(document);
  });
})();
