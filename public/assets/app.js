(() => {
  'use strict';

  const desktop = document.getElementById('desktop');
  if (!desktop) return;
  const taskbar = document.getElementById('taskbar');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const permissions = new Set(JSON.parse(document.getElementById('access-permissions').textContent));
  const windows = new Map();
  let topZ = 100;
  let sequence = 0;
  let activeRelationMenu = null;
  let activeRelationTrigger = null;
  let restoringWindows = false;
  let persistenceTimer = null;
  const windowStorageKey = `cinius.desktop.windows.v1:${desktop.dataset.userId || 'default'}`;

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

  const integerFormatter = new Intl.NumberFormat('tr-TR', {maximumFractionDigits: 0});
  const formatMoney = (value, currency = 'TL') => {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);
    return `${integerFormatter.format(Math.round(number))} ${currency || 'TL'}`;
  };

  const moneyColumnSet = data => new Set(data.money_columns || []);
  const displayedCellValue = (data, column, value) => moneyColumnSet(data).has(column)
    ? formatMoney(value, data.currency)
    : String(value ?? '');

  function windowGeometry(win) {
    return {
      left: win.el.style.left,
      top: win.el.style.top,
      width: win.el.style.width,
      height: win.el.style.height,
    };
  }

  function serializeWindow(win) {
    const base = {
      kind: win.kind,
      key: win.key,
      geometry: windowGeometry(win),
      restoreRect: win.restoreRect || null,
      minimized: Boolean(win.minimized),
      maximized: Boolean(win.maximized),
      z: Number(win.el.style.zIndex) || 0,
    };
    const s = win.state || {};
    if (win.kind === 'table') return {...base, state: {table: s.table, label: s.label, filter: s.filter || null, periodFilter: s.periodFilter || null, titleLookup: s.titleLookup || null, page: s.page, perPage: s.perPage, q: s.q || '', sort: s.sort, dir: s.dir}};
    if (win.kind === 'report') return {...base, state: {report: s.report, id: s.id, label: s.label, titleLookup: s.titleLookup || null, page: s.page, perPage: s.perPage, q: s.q || '', sort: s.sort, dir: s.dir}};
    if (win.kind === 'business_report') return {...base, state: {report: s.report, label: s.label, page: s.page, perPage: s.perPage, sort: s.sort, dir: s.dir, grouping: s.grouping, preset: s.preset, start: s.start || '', end: s.end || '', chartExpanded: Boolean(s.chartExpanded)}};
    if (win.kind === 'invoice') return {...base, state: {id: s.id, page: s.page, perPage: s.perPage, q: s.q || '', sort: s.sort, dir: s.dir}};
    if (win.kind === 'editor') return {...base, state: {table: s.table, label: s.label, id: s.id || null, parentKey: s.parentKey || null}};
    if (win.kind === 'settings') return {...base, state: {section: s.section}};
    if (win.kind === 'production_list') return {...base, state: {view: s.view, label: s.label, page: s.page, perPage: s.perPage, q: s.q || '', sort: s.sort, dir: s.dir}};
    if (win.kind === 'production_report') return {...base, state: {report: s.report, label: s.label, page: s.page, perPage: s.perPage, sort: s.sort, dir: s.dir, grouping: s.grouping, preset: s.preset, start: s.start || '', end: s.end || '', chartExpanded: Boolean(s.chartExpanded)}};
    if (win.kind === 'production_project') return {...base, state: {id: s.id || null}};
    return null;
  }

  function saveOpenWindows() {
    if (restoringWindows) return;
    const descriptors = [...windows.values()].map(serializeWindow).filter(Boolean);
    try { localStorage.setItem(windowStorageKey, JSON.stringify(descriptors)); } catch (_) {}
  }

  function scheduleSaveOpenWindows() {
    if (restoringWindows) return;
    clearTimeout(persistenceTimer);
    persistenceTimer = setTimeout(saveOpenWindows, 120);
  }

  function storedWindowDescriptors() {
    try {
      const parsed = JSON.parse(localStorage.getItem(windowStorageKey) || '[]');
      return Array.isArray(parsed) ? parsed.filter(item => item && ['table', 'report', 'business_report', 'invoice', 'editor', 'settings', 'production_list', 'production_report', 'production_project'].includes(item.kind) && item.state)
        .filter(item => item.kind !== 'production_list' || ['all','balances'].includes(item.state.view)) : [];
    } catch (_) {
      return [];
    }
  }

  const toast = (message, type = 'success') => {
    const el = document.createElement('div');
    el.className = `rounded-lg border px-4 py-3 text-sm shadow-xl ${type === 'error' ? 'border-red-300 bg-red-50 text-red-800' : 'border-emerald-300 bg-emerald-50 text-emerald-800'}`;
    el.textContent = message;
    document.getElementById('toast-region').append(el);
    setTimeout(() => el.remove(), 4500);
  };

  function closeRelationMenu() {
    activeRelationMenu?.remove();
    activeRelationMenu = null;
    activeRelationTrigger = null;
  }

  function openRelationMenu(trigger, items, onSelect) {
    if (activeRelationTrigger === trigger) {
      closeRelationMenu();
      return;
    }
    closeRelationMenu();
    const menu = document.createElement('div');
    menu.dataset.relationFloatingMenu = 'true';
    menu.className = 'fixed z-[30000] min-w-56 max-w-80 overflow-auto rounded-lg border border-slate-300 bg-white py-1 shadow-2xl';
    menu.style.maxHeight = `${Math.max(120, Math.min(288, window.innerHeight - 16))}px`;
    menu.innerHTML = items.map((item, index) => `<button type="button" data-floating-relation="${index}" class="block w-full whitespace-nowrap px-3 py-2 text-left text-xs text-slate-700 hover:bg-blue-50 hover:text-blue-800">${escapeHtml(item.action.label)}</button>`).join('');
    document.body.append(menu);
    activeRelationMenu = menu;
    activeRelationTrigger = trigger;

    const triggerRect = trigger.getBoundingClientRect();
    const gap = 6;
    const edge = 8;
    const menuWidth = menu.offsetWidth;
    const menuHeight = menu.offsetHeight;
    let left = triggerRect.right + gap;
    if (left + menuWidth > window.innerWidth - edge) left = triggerRect.left - menuWidth - gap;
    left = Math.max(edge, Math.min(left, window.innerWidth - menuWidth - edge));
    let top = triggerRect.top;
    if (top + menuHeight > window.innerHeight - edge) top = window.innerHeight - menuHeight - edge;
    top = Math.max(edge, top);
    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;

    menu.querySelectorAll('[data-floating-relation]').forEach(button => button.onclick = event => {
      event.preventDefault(); event.stopPropagation();
      const item = items[Number(button.dataset.floatingRelation)];
      closeRelationMenu();
      onSelect(item);
    });
  }

  document.addEventListener('pointerdown', event => {
    if (!activeRelationMenu) return;
    if (activeRelationMenu.contains(event.target) || activeRelationTrigger?.contains(event.target)) return;
    closeRelationMenu();
  });
  window.addEventListener('resize', closeRelationMenu);

  const api = async (url, options = {}) => {
    const response = await fetch(`api.php${url}`, {
      ...options,
      cache: 'no-store',
      headers: { 'Accept': 'application/json', ...(options.body ? {'Content-Type': 'application/json', 'X-CSRF-Token': csrf} : {}), ...(options.headers || {}) }
    });
    const data = await response.json().catch(() => ({error: 'Invalid server response.'}));
    if (response.status === 401) location.reload();
    if (!response.ok) throw new Error(data.error || 'Request failed.');
    return data;
  };

  const productionApi = async (url, options = {}) => {
    const response = await fetch(`production_api.php${url}`, {
      ...options,
      cache: 'no-store',
      headers: { 'Accept': 'application/json', ...(options.body ? {'Content-Type': 'application/json', 'X-CSRF-Token': csrf} : {}), ...(options.headers || {}) }
    });
    const data = await response.json().catch(() => ({error: 'Geçersiz WebCin sunucu yanıtı.'}));
    if (response.status === 401) location.reload();
    if (!response.ok) throw new Error(data.error || 'WebCin isteği tamamlanamadı.');
    return data;
  };

  function refreshAfterCascadeDelete(table, id, sourceWin) {
    [...windows.values()].forEach(win => {
      const isDeletedEditor = win.kind === 'editor' && win.state?.table === table && Number(win.state?.id) === Number(id);
      const isDeletedInvoice = table === 'faturabaslik' && win.kind === 'invoice' && Number(win.state?.id) === Number(id);
      const isDeletedReport = win.kind === 'report' && Number(win.state?.id) === Number(id) &&
        ((table === 'asp_urun' && win.state?.report === 'product_sales') || (table === 'adresdefteri' && win.state?.report === 'author_sales'));
      if (win === sourceWin || isDeletedEditor || isDeletedInvoice || isDeletedReport) {
        win.dirty = false;
        closeWindow(win);
      } else if (win.kind === 'table') {
        loadGrid(win);
      } else if (win.kind === 'invoice') {
        loadInvoice(win);
      } else if (win.kind === 'report') {
        loadReport(win);
      } else if (win.kind === 'business_report') {
        loadBusinessReport(win);
      }
    });
  }

  async function requestCascadeDelete(table, id, sourceWin) {
    let preview;
    try {
      preview = await api(`?action=delete_preview&table=${encodeURIComponent(table)}&id=${encodeURIComponent(id)}`);
    } catch (error) {
      toast(error.message, 'error');
      return;
    }
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 flex items-center justify-center bg-slate-950/60 p-4';
    overlay.style.zIndex = '50000';
    const groups = preview.groups.map(group => {
      const ids = group.ids.length ? group.ids.map(value => `#${escapeHtml(value)}`).join(', ') : '—';
      const actionLabel = group.action === 'update' ? 'yeniden hesaplanacak' : 'silinecek';
      return `<div class="rounded border border-slate-200 bg-slate-50 p-3"><div class="flex items-start gap-3"><div class="min-w-0 flex-1"><div class="font-medium text-slate-800">${escapeHtml(group.label)}</div><div class="text-xs text-slate-500">${escapeHtml(group.table)} · ${actionLabel}</div></div><strong class="rounded bg-white px-2 py-1 text-sm text-slate-800">${Number(group.count).toLocaleString('tr-TR')}</strong></div><div class="mt-2 break-words text-xs text-slate-500">${ids}${group.truncated ? ' … (ilk 200 ID gösteriliyor)' : ''}</div></div>`;
    }).join('');
    overlay.innerHTML = `<div class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-slate-300 bg-white shadow-2xl"><div class="border-b border-slate-200 px-5 py-4"><h2 class="text-lg font-semibold text-slate-900">${escapeHtml(preview.title)} silinsin mi?</h2><p class="mt-1 text-sm text-red-700">${escapeHtml(preview.warning)}</p></div><div class="min-h-0 flex-1 overflow-auto p-5"><div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4"><span class="text-sm text-red-800"><strong>${Number(preview.delete_group_count).toLocaleString('tr-TR')}</strong> silme grubundaki kayıtlar kaldırılacak. Her grubun kesin sayısı aşağıda gösterilir; plan onay anında veritabanında yeniden doğrulanır.</span></div><div class="space-y-2">${groups || '<p class="text-sm text-slate-500">İlişkili kayıt bulunamadı.</p>'}</div></div><div class="flex shrink-0 justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" class="toolbar-button" data-cancel>Vazgeç</button><button type="button" class="toolbar-button bg-red-600 text-white hover:bg-red-700" data-confirm>İlişkili Kayıtlarla Birlikte Sil</button></div></div>`;
    document.body.append(overlay);
    const cancel = () => overlay.remove();
    overlay.querySelector('[data-cancel]').onclick = cancel;
    overlay.addEventListener('pointerdown', event => { if (event.target === overlay) cancel(); });
    overlay.querySelector('[data-confirm]').onclick = async event => {
      const button = event.currentTarget;
      button.disabled = true;
      button.textContent = 'Siliniyor…';
      try {
        const result = await api('?action=delete_record', {method: 'POST', body: JSON.stringify({table, id})});
        overlay.remove();
        toast(`${Number(result.total || 0).toLocaleString('tr-TR')} kayıt silindi.`);
        refreshAfterCascadeDelete(table, id, sourceWin);
      } catch (error) {
        toast(error.message, 'error');
        button.disabled = false;
        button.textContent = 'İlişkili Kayıtlarla Birlikte Sil';
      }
    };
  }

  function activateWindow(win) {
    topZ += 1;
    win.el.style.zIndex = topZ;
    windows.forEach(item => item.task.classList.remove('bg-blue-600'));
    win.task.classList.add('bg-blue-600');
    scheduleSaveOpenWindows();
  }

  function updateEmptyState() {
    document.getElementById('empty-desktop').classList.toggle('hidden', windows.size > 0);
  }

  function closeWindow(win) {
    if (win.dirty && !confirm('Kaydedilmemiş değişiklikler kaybolacak. Pencere kapatılsın mı?')) return;
    win.el.remove();
    win.task.remove();
    windows.delete(win.key);
    updateEmptyState();
    scheduleSaveOpenWindows();
  }

  function minimizeWindow(win) {
    win.el.classList.add('hidden');
    win.minimized = true;
    win.task.classList.remove('bg-blue-600');
    scheduleSaveOpenWindows();
  }

  function restoreWindow(win) {
    win.el.classList.remove('hidden');
    win.minimized = false;
    activateWindow(win);
    scheduleSaveOpenWindows();
  }

  function maximizeWindow(win) {
    if (!win.maximized) {
      win.restoreRect = {left: win.el.style.left, top: win.el.style.top, width: win.el.style.width, height: win.el.style.height};
      Object.assign(win.el.style, {left: '0px', top: '0px', width: `${desktop.clientWidth}px`, height: `${desktop.clientHeight}px`});
    } else {
      Object.assign(win.el.style, win.restoreRect);
    }
    win.maximized = !win.maximized;
    scheduleSaveOpenWindows();
  }

  const pixelValue = (value, fallback) => {
    const parsed = Number.parseFloat(String(value ?? ''));
    return Number.isFinite(parsed) ? parsed : fallback;
  };

  function fitRectInsideDesktop(rect = {}, fallback = {}) {
    const desktopWidth = Math.max(1, desktop.clientWidth);
    const desktopHeight = Math.max(1, desktop.clientHeight);
    const minimumWidth = Math.min(280, desktopWidth);
    const minimumHeight = Math.min(180, desktopHeight);
    const width = Math.max(minimumWidth, Math.min(pixelValue(rect.width, fallback.width ?? 960), desktopWidth));
    const height = Math.max(minimumHeight, Math.min(pixelValue(rect.height, fallback.height ?? 620), desktopHeight));
    const left = Math.max(0, Math.min(pixelValue(rect.left, fallback.left ?? 0), desktopWidth - width));
    const top = Math.max(0, Math.min(pixelValue(rect.top, fallback.top ?? 0), desktopHeight - height));
    return {left: `${left}px`, top: `${top}px`, width: `${width}px`, height: `${height}px`};
  }

  function fitWindowInsideDesktop(win) {
    if (win.maximized) {
      Object.assign(win.el.style, {left: '0px', top: '0px', width: `${desktop.clientWidth}px`, height: `${desktop.clientHeight}px`});
      return;
    }
    Object.assign(win.el.style, fitRectInsideDesktop(windowGeometry(win)));
  }

  function makeDraggable(win, handle) {
    handle.addEventListener('pointerdown', event => {
      if (event.target.closest('button') || win.maximized) return;
      activateWindow(win);
      const startX = event.clientX, startY = event.clientY;
      const startLeft = win.el.offsetLeft, startTop = win.el.offsetTop;
      handle.setPointerCapture(event.pointerId);
      const move = e => {
        const left = startLeft + e.clientX - startX;
        const top = startTop + e.clientY - startY;
        win.el.style.left = `${left}px`; win.el.style.top = `${top}px`;
      };
      const up = () => { handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', up); scheduleSaveOpenWindows(); };
      handle.addEventListener('pointermove', move); handle.addEventListener('pointerup', up);
    });
  }

  function updateWindowTitle(win, title) {
    win.title = title;
    win.el.querySelector('[data-window-title]').textContent = title;
    win.el.querySelector('[data-window-title]').title = title;
    win.task.textContent = title;
    win.task.title = title;
  }

  function createWindow(key, title, width = 960, height = 620, options = {}) {
    if (windows.has(key)) { restoreWindow(windows.get(key)); return windows.get(key); }
    sequence += 1;
    const el = document.createElement('section');
    el.className = `desktop-window${options.relationship ? ' relationship-window' : ''}`;
    const initialRect = fitRectInsideDesktop({
      width,
      height,
      left: 20 + (sequence % 9) * 24,
      top: 16 + (sequence % 7) * 22,
    }, {width, height});
    Object.assign(el.style, initialRect, {resize: 'both'});
    el.innerHTML = `<header class="window-titlebar"><span class="mr-1 text-blue-300">◆</span><span class="min-w-0 flex-1 truncate font-medium" data-window-title title="${escapeHtml(title)}">${escapeHtml(title)}</span><button class="window-button" data-action="min" title="Küçült">—</button><button class="window-button" data-action="max" title="Büyüt">□</button><button class="window-button hover:bg-red-600" data-action="close" title="Kapat">×</button></header><div class="window-body min-h-0 flex-1 overflow-hidden bg-slate-100"><div class="flex h-full items-center justify-center text-sm text-slate-500">Yükleniyor…</div></div>`;
    desktop.append(el);
    const task = document.createElement('button');
    task.className = 'max-w-56 truncate rounded bg-slate-700 px-3 py-1.5 text-xs hover:bg-slate-600';
    task.textContent = title; taskbar.append(task);
    const win = {key, title, el, task, body: el.querySelector('.window-body'), minimized: false, maximized: false, dirty: false};
    windows.set(key, win); updateEmptyState(); activateWindow(win);
    makeDraggable(win, el.querySelector('.window-titlebar'));
    el.addEventListener('pointerdown', () => activateWindow(win));
    el.querySelector('[data-action="min"]').onclick = () => minimizeWindow(win);
    el.querySelector('[data-action="max"]').onclick = () => maximizeWindow(win);
    el.querySelector('[data-action="close"]').onclick = () => closeWindow(win);
    el.querySelector('.window-titlebar').ondblclick = event => { if (!event.target.closest('button')) maximizeWindow(win); };
    task.onclick = () => win.minimized ? restoreWindow(win) : (Number(win.el.style.zIndex) === topZ ? minimizeWindow(win) : activateWindow(win));
    new ResizeObserver(() => scheduleSaveOpenWindows()).observe(el);
    return win;
  }

  function dataUrlForWindow(win, page, perPage) {
    const s = win.state;
    if (win.kind === 'production_list') {
      return `?action=list&view=${encodeURIComponent(s.view)}&page=${page}&per_page=${perPage}&q=${encodeURIComponent(s.q)}&sort=${encodeURIComponent(s.sort)}&dir=${s.dir}`;
    }
    if (win.kind === 'production_report') {
      return `?action=report&report=${encodeURIComponent(s.report)}&grouping=${encodeURIComponent(s.grouping)}&preset=${encodeURIComponent(s.preset)}&start=${encodeURIComponent(s.start || '')}&end=${encodeURIComponent(s.end || '')}&page=${page}&per_page=${perPage}&sort=${encodeURIComponent(s.sort || '')}&dir=${s.dir}`;
    }
    if (win.kind === 'invoice') {
      return `?action=invoice&id=${encodeURIComponent(s.id)}&page=${page}&per_page=${perPage}&q=${encodeURIComponent(s.q)}&sort=${encodeURIComponent(s.sort)}&dir=${s.dir}`;
    }
    if (win.kind === 'report') {
      return `?action=report&report=${encodeURIComponent(s.report)}&id=${encodeURIComponent(s.id)}&page=${page}&per_page=${perPage}&q=${encodeURIComponent(s.q)}&sort=${encodeURIComponent(s.sort)}&dir=${s.dir}`;
    }
    if (win.kind === 'business_report') {
      return `?action=business_report&report=${encodeURIComponent(s.report)}&grouping=${encodeURIComponent(s.grouping)}&preset=${encodeURIComponent(s.preset)}&start=${encodeURIComponent(s.start || '')}&end=${encodeURIComponent(s.end || '')}&page=${page}&per_page=${perPage}&sort=${encodeURIComponent(s.sort || '')}&dir=${s.dir}`;
    }
    const filterQuery = s.filter ? `&filter_column=${encodeURIComponent(s.filter.column)}&filter_value=${encodeURIComponent(s.filter.value)}` : '';
    const periodQuery = s.periodFilter ? `&period_start=${encodeURIComponent(s.periodFilter.start)}&period_end=${encodeURIComponent(s.periodFilter.end)}` : '';
    return `?action=list&table=${encodeURIComponent(s.table)}&page=${page}&per_page=${perPage}&q=${encodeURIComponent(s.q)}&sort=${encodeURIComponent(s.sort)}&dir=${s.dir}${filterQuery}${periodQuery}`;
  }

  async function fetchAllWindowRows(win) {
    const rows = [];
    let columns = [];
    let page = 1;
    let pages = 1;
    let moneyColumns = [];
    let currency = 'TL';
    let totals = {};
    let numericColumns = [];
    do {
      const data = ['production_list', 'production_report'].includes(win.kind)
        ? await productionApi(dataUrlForWindow(win, page, 1000))
        : await api(dataUrlForWindow(win, page, 1000));
      columns = data.columns;
      moneyColumns = data.money_columns || [];
      currency = data.currency || currency;
      totals = data.totals || totals;
      numericColumns = data.numeric_columns || numericColumns;
      rows.push(...data.rows);
      pages = data.pages;
      page += 1;
    } while (page <= pages);
    return {columns, rows, money_columns: moneyColumns, currency, totals, numeric_columns:numericColumns};
  }

  function reportTotalRow(data) {
    if (!data.totals || Object.keys(data.totals).length === 0 || !data.columns?.length) return null;
    const row={};
    data.columns.forEach(column=>{row[column]=Object.prototype.hasOwnProperty.call(data.totals,column)?data.totals[column]:'';});
    row[data.columns[0]]='TOPLAM';
    return row;
  }

  const safeFilename = title => String(title).replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, ' ').trim() || 'tablo';
  const csvCell = value => `"${String(value ?? '').replaceAll('"', '""')}"`;

  async function exportWindowToExcel(win, button) {
    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Hazırlanıyor…';
    try {
      const data = await fetchAllWindowRows(win);
      const {columns, rows} = data;
      const totalRow=reportTotalRow(data);
      const exportRows=totalRow?[...rows,totalRow]:rows;
      const csv = '\uFEFF' + [columns.map(csvCell).join(';'), ...exportRows.map(row => columns.map(column => csvCell(displayedCellValue(data, column, row[column]))).join(';'))].join('\r\n');
      const url = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
      const link = document.createElement('a');
      link.href = url;
      link.download = `${safeFilename(win.title)}.csv`;
      document.body.append(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      toast(`${rows.length.toLocaleString('tr-TR')} satır Excel uyumlu CSV olarak dışa aktarıldı.`);
    } catch (error) {
      toast(error.message, 'error');
    } finally {
      button.disabled = false;
      button.textContent = original;
    }
  }

  async function printWindowTable(win, button) {
    const printTab = window.open('about:blank', '_blank');
    if (!printTab) {
      toast('Yazdırma sekmesi açılamadı. Tarayıcının açılır pencere iznini kontrol edin.', 'error');
      return;
    }
    printTab.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Tablo hazırlanıyor…</title></head><body style="font-family:Arial,sans-serif;padding:24px">Tablo hazırlanıyor…</body></html>');
    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Hazırlanıyor…';
    try {
      const data = await fetchAllWindowRows(win);
      const {columns, rows} = data;
      const moneyColumns = moneyColumnSet(data);
      const header = columns.map(column => `<th${moneyColumns.has(column) ? ' style="text-align:right"' : ''}>${escapeHtml(column)}</th>`).join('');
      const totalRow=reportTotalRow(data);
      const body = rows.map(row => `<tr>${columns.map(column => `<td${moneyColumns.has(column) ? ' style="text-align:right;white-space:nowrap"' : ''}>${escapeHtml(displayedCellValue(data, column, row[column]))}</td>`).join('')}</tr>`).join('')+(totalRow?`<tr style="font-weight:bold;background:#dbeafe">${columns.map(column=>`<td${moneyColumns.has(column)?' style="text-align:right;white-space:nowrap"':''}>${escapeHtml(displayedCellValue(data,column,totalRow[column]))}</td>`).join('')}</tr>`:'');
      printTab.document.open();
      printTab.document.write(`<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>${escapeHtml(win.title)}</title><style>body{font-family:Arial,sans-serif;color:#0f172a;margin:24px}header{display:flex;align-items:center;gap:16px;margin-bottom:18px}h1{font-size:20px;margin:0;flex:1}button{border:1px solid #94a3b8;border-radius:5px;background:#fff;padding:8px 14px;cursor:pointer}table{width:100%;border-collapse:collapse;font-size:10px}th,td{border:1px solid #cbd5e1;padding:5px;text-align:left;vertical-align:top}th{background:#e2e8f0}tr:nth-child(even){background:#f8fafc}@media print{body{margin:0}header button{display:none}thead{display:table-header-group}tr{break-inside:avoid}}</style></head><body><header><h1>${escapeHtml(win.title)}</h1><span>${rows.length.toLocaleString('tr-TR')} kayıt</span><button type="button" onclick="window.print()">Yazdır / PDF</button></header><table><thead><tr>${header}</tr></thead><tbody>${body}</tbody></table></body></html>`);
      printTab.document.close();
    } catch (error) {
      printTab.document.body.innerHTML = `<p style="color:#b91c1c">${escapeHtml(error.message)}</p>`;
    } finally {
      button.disabled = false;
      button.textContent = original;
    }
  }

  function initializeOutputButtons(win) {
    win.body.querySelector('[data-export]').onclick = event => exportWindowToExcel(win, event.currentTarget);
    win.body.querySelector('[data-print]').onclick = event => printWindowTable(win, event.currentTarget);
  }

  async function openTable(table, label, filter = null, relationshipContext = null, restoredState = null, periodFilter = null) {
    periodFilter = periodFilter || restoredState?.periodFilter || null;
    const filterKey = filter ? `:${filter.column}:${filter.value}` : '';
    const periodKey = periodFilter ? `:${periodFilter.start}:${periodFilter.end}` : '';
    const relationshipTitle = relationshipContext?.title || '';
    const context = relationshipTitle ? `${relationshipTitle} (#${filter.value})` : `#${filter?.value}`;
    const windowTitle = filter ? `${label} — ${context}` : periodFilter ? `${label} — ${periodFilter.start} / ${periodFilter.end}` : label;
    const win = createWindow(`table:${table}${filterKey}${periodKey}`, windowTitle, 960, 620, {relationship: Boolean(filter || periodFilter)});
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'table';
    win.state = {...{page: 1, perPage: 250, q: '', sort: 'ID', dir: 'desc'}, ...(restoredState || {}), table, label, filter, periodFilter, titleLookup: relationshipContext?.lookup || restoredState?.titleLookup || null};
    scheduleSaveOpenWindows();
    win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex shrink-0 items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button" data-new>＋ Yeni</button><button class="toolbar-button" data-refresh>↻ Yenile</button><button class="toolbar-button" data-export>⇩ Excel</button><button class="toolbar-button" data-print>▧ Yazdır / PDF</button>${filter ? `<span class="rounded-full border border-blue-300 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-800">${escapeHtml(filter.column)} = #${escapeHtml(filter.value)}</span>` : ''}${periodFilter ? `<span class="rounded-full border border-indigo-300 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-800">${escapeHtml(periodFilter.start)} → ${escapeHtml(periodFilter.end)}</span>` : ''}<div class="ml-auto relative"><input class="form-input w-72 pr-9" data-search placeholder="Ara…"><span class="absolute right-3 top-2 text-slate-400">⌕</span></div></div><div class="min-h-0 flex-1 overflow-auto bg-white" data-grid></div><div class="flex h-11 shrink-0 items-center gap-3 border-t border-slate-300 bg-slate-100 px-3 text-sm"><span data-summary class="whitespace-nowrap text-slate-500"></span><label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-600">Satır <select data-per-page class="rounded border border-slate-300 bg-white px-2 py-1 text-xs"><option value="50">50</option><option value="100">100</option><option value="250" selected>250</option><option value="500">500</option><option value="1000">1000</option></select></label><span data-join-legend class="hidden text-xs text-blue-700">* İlişkili tablodan gösterilen değer</span><div class="ml-auto flex items-center gap-2"><button class="toolbar-button h-8" data-prev>←</button><span data-page></span><button class="toolbar-button h-8" data-next>→</button></div></div></div>`;
    const search = win.body.querySelector('[data-search]');
    let searchTimer;
    search.value = win.state.q;
    search.oninput = () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { win.state.q = search.value; win.state.page = 1; scheduleSaveOpenWindows(); loadGrid(win); }, 300); };
    win.body.querySelector('[data-refresh]').onclick = () => loadGrid(win);
    win.body.querySelector('[data-new]').onclick = () => openEditor(table, label, null, win);
    initializeOutputButtons(win);
    win.body.querySelector('[data-per-page]').onchange = event => { win.state.perPage = Number(event.target.value); win.state.page = 1; scheduleSaveOpenWindows(); loadGrid(win); };
    win.body.querySelector('[data-prev]').onclick = () => { if (win.state.page > 1) { win.state.page--; scheduleSaveOpenWindows(); loadGrid(win); } };
    win.body.querySelector('[data-next]').onclick = () => { if (win.state.page < (win.state.pages || 1)) { win.state.page++; scheduleSaveOpenWindows(); loadGrid(win); } };
    await loadGrid(win);
    return win;
  }

  async function loadGrid(win) {
    const grid = win.body.querySelector('[data-grid]');
    grid.innerHTML = '<div class="p-8 text-center text-sm text-slate-500">Kayıtlar yükleniyor…</div>';
    try {
      const s = win.state;
      const data = await api(dataUrlForWindow(win,s.page,s.perPage));
      s.pages = data.pages;
      s.perPage = data.per_page;
      const joinedColumns = new Set(data.joined_columns || []);
      const moneyColumns = moneyColumnSet(data);
      const openPrimaryRow = (row, rowId) => {
        const invoiceId = invoiceIdForTableRow(s.table, row);
        if (invoiceId > 0) openInvoice(invoiceId);
        else openEditor(s.table, s.label, Number(rowId), win);
      };
      const actionsForRow = row => {
        const invoiceId = invoiceIdForTableRow(s.table, row);
        const primary = {action: {label: invoiceId > 0 ? 'Faturayı Aç' : 'Kaydı Aç'}, primary: true};
        const relations = (data.row_actions || []).map((action, index) => ({action, index, value: row[`__raw_${action.value}`], title: row[`__title_${action.value}`]})).filter(item => item.value !== null && item.value !== '' && String(item.value) !== '0');
        return [primary, ...relations];
      };
      const header = data.columns.map(column => `<th data-sort="${escapeHtml(column)}" title="${joinedColumns.has(column) ? 'İlişkili tablodan gösterilen değer' : ''}" class="sticky top-0 cursor-pointer whitespace-nowrap border-b border-r border-slate-300 bg-slate-200 px-3 py-2 ${moneyColumns.has(column) ? 'text-right' : 'text-left'} text-xs font-semibold text-slate-700 hover:bg-slate-300">${escapeHtml(column)}${joinedColumns.has(column) ? '<span class="ml-0.5 text-blue-700">*</span>' : ''}${data.sort === column ? (data.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('');
      const rows = data.rows.map((row, rowIndex) => {
        const availableActions = actionsForRow(row);
        const actionCell = `<td class="sticky left-0 z-10 w-4 border-r border-slate-200 bg-slate-50 px-2 py-1 text-xs"><button type="button" data-row-action-trigger="${rowIndex}" class="w-full select-none rounded border border-slate-300 bg-white px-2 py-1 text-center font-medium text-blue-700 hover:bg-blue-50 disabled:cursor-not-allowed disabled:text-slate-400" ${availableActions.length ? '' : 'disabled'}>+</button></td>`;
        return `<tr data-id="${escapeHtml(row.ID)}" class="cursor-pointer border-b border-slate-200 hover:bg-blue-50">${actionCell}${data.columns.map(column => { const rawValue = row[column]; const value = moneyColumns.has(column) ? formatMoney(rawValue, data.currency) : String(rawValue ?? ''); const stateClass = joinedColumns.has(column) && value.startsWith('Silinmiş kayıt') ? ' font-medium italic text-red-700' : joinedColumns.has(column) && value === '—' ? ' text-slate-400' : ''; const moneyClass = moneyColumns.has(column) ? ' text-right tabular-nums' : ''; return `<td class="max-w-72 truncate whitespace-nowrap border-r border-slate-100 px-3 py-2 text-xs${stateClass}${moneyClass}" title="${escapeHtml(value)}">${escapeHtml(value)}</td>`; }).join('')}</tr>`;
      }).join('');
      grid.innerHTML = `<table class="min-w-full border-collapse"><thead><tr><th class="sticky left-0 top-0 z-30 border-b border-r border-slate-300 bg-slate-200 px-2 py-2 text-left text-xs font-semibold"></th>${header}</tr></thead><tbody>${rows || `<tr><td colspan="${data.columns.length + 1}" class="p-10 text-center text-sm text-slate-500">Kayıt bulunamadı.</td></tr>`}</tbody></table>`;
      grid.querySelectorAll('th[data-sort]').forEach(th => th.onclick = () => { const name = th.dataset.sort; s.dir = s.sort === name && s.dir === 'asc' ? 'desc' : 'asc'; s.sort = name; s.page = 1; scheduleSaveOpenWindows(); loadGrid(win); });
      grid.querySelectorAll('tr[data-id]').forEach((tableRow, rowIndex) => {
        const row = data.rows[rowIndex];
        const invoiceId = invoiceIdForTableRow(s.table, row);
        if (invoiceId > 0) tableRow.title = 'Çift tıklayın: faturayı aç';
        tableRow.ondblclick = () => openPrimaryRow(row, tableRow.dataset.id);
      });
      grid.querySelectorAll('[data-row-action-trigger]').forEach(trigger => trigger.onclick = event => {
        event.preventDefault(); event.stopPropagation();
        const row = data.rows[Number(trigger.dataset.rowActionTrigger)];
        const items = actionsForRow(row);
        openRelationMenu(trigger, items, item => {
          if (item.primary) {
            openPrimaryRow(row, row.ID);
            return;
          }
          const context = {title: item.title, lookup: item.action.title_lookup ? {...item.action.title_lookup, id: item.value} : null};
          if (item.action.report) openReport(item.action.report, item.value, item.action.label, context);
          else if (item.action.target === 'faturabaslik' && item.action.filter === 'ID') openInvoice(item.value);
          else openTable(item.action.target, item.action.label, {column: item.action.filter, value: item.value}, context);
        });
      });
      grid.addEventListener('scroll', closeRelationMenu, {passive: true});
      const firstVisible = data.total === 0 ? 0 : ((data.page - 1) * data.per_page) + 1;
      const lastVisible = data.total === 0 ? 0 : firstVisible + data.rows.length - 1;
      win.body.querySelector('[data-summary]').textContent = `${firstVisible.toLocaleString('tr-TR')}–${lastVisible.toLocaleString('tr-TR')} / ${data.total.toLocaleString('tr-TR')} kayıt`;
      win.body.querySelector('[data-per-page]').value = String(data.per_page);
      win.body.querySelector('[data-join-legend]').classList.toggle('hidden', joinedColumns.size === 0);
      win.body.querySelector('[data-page]').textContent = `${data.page} / ${data.pages}`;
      win.body.querySelector('[data-prev]').disabled = data.page <= 1;
      win.body.querySelector('[data-next]').disabled = data.page >= data.pages;
    } catch (error) { grid.innerHTML = `<div class="p-8 text-center text-sm text-red-700">${escapeHtml(error.message)}</div>`; }
  }

  function invoiceIdForTableRow(table, row) {
    const fields = {
      faturabaslik: 'ID',
      faturadetay: '__raw_faturaID',
      kasa: '__raw_FaturaID',
      banka_kasa: '__raw_faturaID',
    };
    const field = fields[table];
    return field ? Number(row?.[field] || 0) : 0;
  }

  const invoiceMoney = (value, currency) => formatMoney(value ?? 0, currency);

  async function openInvoice(id, restoredState = null) {
    id = Number(id);
    if (!Number.isInteger(id) || id < 1) return;
    const win = createWindow(`invoice:${id}`, `Fatura #${id}`, 1180, 760);
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'invoice';
    win.state = {...{page: 1, perPage: 250, q: '', sort: 'ID', dir: 'asc'}, ...(restoredState || {}), id};
    scheduleSaveOpenWindows();
    win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex shrink-0 items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button" data-edit-header>✎ Başlığı Düzenle</button><button class="toolbar-button bg-red-600 text-white hover:bg-red-700" data-delete-invoice>⌫ Sil</button><button class="toolbar-button" data-refresh>↻ Yenile</button><button class="toolbar-button" data-export>⇩ Excel</button><button class="toolbar-button" data-print>▧ Yazdır / PDF</button><div class="ml-auto relative"><input class="form-input w-72 pr-9" data-search placeholder="Fatura satırlarında ara…"><span class="absolute right-3 top-2 text-slate-400">⌕</span></div></div><div class="shrink-0 border-b border-slate-300 bg-white p-3" data-invoice-header><div class="text-sm text-slate-500">Fatura başlığı yükleniyor…</div></div><div class="min-h-0 flex-1 overflow-auto bg-white" data-grid></div><div class="flex h-11 shrink-0 items-center gap-3 border-t border-slate-300 bg-slate-100 px-3 text-sm"><span data-summary class="whitespace-nowrap text-slate-500"></span><label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-600">Satır <select data-per-page class="rounded border border-slate-300 bg-white px-2 py-1 text-xs"><option value="50">50</option><option value="100">100</option><option value="250" selected>250</option><option value="500">500</option><option value="1000">1000</option></select></label><div class="ml-auto flex items-center gap-2"><button class="toolbar-button h-8" data-prev>←</button><span data-page></span><button class="toolbar-button h-8" data-next>→</button></div></div></div>`;
    const search = win.body.querySelector('[data-search]');
    search.value = win.state.q;
    let searchTimer;
    search.oninput = () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { win.state.q = search.value; win.state.page = 1; scheduleSaveOpenWindows(); loadInvoice(win); }, 300); };
    win.body.querySelector('[data-refresh]').onclick = () => loadInvoice(win);
    win.body.querySelector('[data-edit-header]').onclick = () => openEditor('faturabaslik', 'Faturalar', id, win);
    win.body.querySelector('[data-delete-invoice]').onclick = () => requestCascadeDelete('faturabaslik', id, win);
    initializeOutputButtons(win);
    win.body.querySelector('[data-per-page]').onchange = event => { win.state.perPage = Number(event.target.value); win.state.page = 1; scheduleSaveOpenWindows(); loadInvoice(win); };
    win.body.querySelector('[data-prev]').onclick = () => { if (win.state.page > 1) { win.state.page--; scheduleSaveOpenWindows(); loadInvoice(win); } };
    win.body.querySelector('[data-next]').onclick = () => { if (win.state.page < (win.state.pages || 1)) { win.state.page++; scheduleSaveOpenWindows(); loadInvoice(win); } };
    await loadInvoice(win);
    return win;
  }

  async function loadInvoice(win) {
    const grid = win.body.querySelector('[data-grid]');
    grid.innerHTML = '<div class="p-8 text-center text-sm text-slate-500">Fatura satırları yükleniyor…</div>';
    try {
      const s = win.state;
      const data = await api(dataUrlForWindow(win, s.page, s.perPage));
      s.pages = data.pages;
      s.perPage = data.per_page;
      updateWindowTitle(win, data.title);
      const h = data.header;
      const address = [h.AliciAdres, h.AliciIlce, h.AliciSehir].filter(Boolean).join(' / ') || '—';
      win.body.querySelector('[data-invoice-header]').innerHTML = `<div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4"><div><span class="block text-xs text-slate-500">Fatura No / ID</span><strong>${escapeHtml(h.faturaNo || '—')} <span class="font-normal text-slate-400">#${escapeHtml(h.ID)}</span></strong></div><div><span class="block text-xs text-slate-500">Tarih / Vade</span><strong>${escapeHtml(h.FaturaTarih || '—')}</strong><span class="ml-2 text-xs text-slate-500">${escapeHtml(h.FaturaVade || '')}</span></div><div><span class="block text-xs text-slate-500">Cari / Alıcı</span><strong>${escapeHtml(h.Musteri || '—')}</strong><span class="ml-2 text-xs text-slate-500">${escapeHtml(h.AliciTelefon || '')}</span></div><div><span class="block text-xs text-slate-500">Tür / Ödeme</span><strong>${escapeHtml(h.FaturaTuruAdi || '—')}</strong><span class="ml-2 text-xs text-slate-500">${Number(h.odemeYapildi) ? 'Ödendi' : 'Ödenmedi'}</span></div><div class="md:col-span-2"><span class="block text-xs text-slate-500">Teslimat adresi</span><span>${escapeHtml(address)}</span></div><div class="text-right tabular-nums"><span class="block text-xs text-slate-500">Ara toplam / İndirim / KDV</span><span>${invoiceMoney(h.OnToplam, data.currency)} / ${invoiceMoney(h.Indirim, data.currency)} / ${invoiceMoney(h.KDV, data.currency)}</span></div><div class="rounded bg-emerald-50 px-3 py-1 text-right tabular-nums text-emerald-800"><span class="block text-xs">Genel Toplam</span><strong class="text-lg">${invoiceMoney(h.Toplam, data.currency)}</strong></div>${h.FaturaNotlar ? `<div class="md:col-span-4"><span class="block text-xs text-slate-500">Notlar</span><span>${escapeHtml(h.FaturaNotlar)}</span></div>` : ''}</div>`;
      const moneyColumns = moneyColumnSet(data);
      const header = data.columns.map(column => `<th data-sort="${escapeHtml(column)}" class="sticky top-0 cursor-pointer whitespace-nowrap border-b border-r border-slate-300 bg-slate-200 px-3 py-2 ${moneyColumns.has(column) ? 'text-right' : 'text-left'} text-xs font-semibold text-slate-700 hover:bg-slate-300">${escapeHtml(column)}${data.sort === column ? (data.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('');
      const rows = data.rows.map(row => `<tr data-id="${escapeHtml(row.ID)}" class="cursor-pointer border-b border-slate-200 hover:bg-blue-50">${data.columns.map(column => { const value=moneyColumns.has(column)?formatMoney(row[column],data.currency):String(row[column]??''); return `<td class="max-w-80 truncate whitespace-nowrap border-r border-slate-100 px-3 py-2 text-xs${moneyColumns.has(column)?' text-right tabular-nums':''}" title="${escapeHtml(value)}">${escapeHtml(value)}</td>`; }).join('')}</tr>`).join('');
      grid.innerHTML = `<table class="min-w-full border-collapse"><thead><tr>${header}</tr></thead><tbody>${rows || `<tr><td colspan="${data.columns.length}" class="p-10 text-center text-sm text-slate-500">Fatura satırı bulunamadı.</td></tr>`}</tbody></table>`;
      grid.querySelectorAll('th[data-sort]').forEach(th => th.onclick = () => { const name = th.dataset.sort; s.dir = s.sort === name && s.dir === 'asc' ? 'desc' : 'asc'; s.sort = name; s.page = 1; scheduleSaveOpenWindows(); loadInvoice(win); });
      grid.querySelectorAll('tr[data-id]').forEach(row => row.ondblclick = () => openEditor('faturadetay', 'Fatura Satırları', Number(row.dataset.id), win));
      const firstVisible = data.total === 0 ? 0 : ((data.page - 1) * data.per_page) + 1;
      const lastVisible = data.total === 0 ? 0 : firstVisible + data.rows.length - 1;
      win.body.querySelector('[data-summary]').textContent = `${firstVisible.toLocaleString('tr-TR')}–${lastVisible.toLocaleString('tr-TR')} / ${data.total.toLocaleString('tr-TR')} fatura satırı`;
      win.body.querySelector('[data-page]').textContent = `${data.page} / ${data.pages}`;
      win.body.querySelector('[data-per-page]').value = String(data.per_page);
      win.body.querySelector('[data-prev]').disabled = data.page <= 1;
      win.body.querySelector('[data-next]').disabled = data.page >= data.pages;
    } catch (error) {
      grid.innerHTML = `<div class="p-8 text-center text-sm text-red-700">${escapeHtml(error.message)}</div>`;
    }
  }

  async function openReport(report, id, label, relationshipContext = null, restoredState = null) {
    const relationshipTitle = relationshipContext?.title || '';
    const initialTitle = `${label} — ${relationshipTitle ? `${relationshipTitle} ` : ''}(#${id})`;
    const win = createWindow(`report:${report}:${id}`, initialTitle, 1100, 680, {relationship: true});
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'report';
    win.state = {...{page: 1, perPage: 250, q: '', sort: 'FaturaTarih', dir: 'desc'}, ...(restoredState || {}), report, id, label, titleLookup: relationshipContext?.lookup || restoredState?.titleLookup || null};
    scheduleSaveOpenWindows();
    win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex shrink-0 items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button" data-refresh>↻ Yenile</button><button class="toolbar-button" data-export>⇩ Excel</button><button class="toolbar-button" data-print>▧ Yazdır / PDF</button><span class="rounded-full border border-blue-300 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-800">Kayıt #${escapeHtml(id)}</span><div class="ml-auto relative"><input class="form-input w-72 pr-9" data-search placeholder="Fatura, cari veya ürün ara…"><span class="absolute right-3 top-2 text-slate-400">⌕</span></div></div><div class="grid grid-cols-2 gap-px border-b border-slate-300 bg-slate-300 text-sm"><div class="bg-emerald-50 px-4 py-2 text-emerald-800"><span class="text-xs">Net Satış Adedi</span><strong class="ml-2" data-net-quantity>—</strong></div><div class="bg-emerald-50 px-4 py-2 text-emerald-800"><span class="text-xs">Net Satış Tutarı</span><strong class="ml-2" data-net-total>—</strong></div></div><div class="min-h-0 flex-1 overflow-auto bg-white" data-grid></div><div class="flex h-11 shrink-0 items-center gap-3 border-t border-slate-300 bg-slate-100 px-3 text-sm"><span data-summary class="whitespace-nowrap text-slate-500"></span><label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-600">Satır <select data-per-page class="rounded border border-slate-300 bg-white px-2 py-1 text-xs"><option value="50">50</option><option value="100">100</option><option value="250" selected>250</option><option value="500">500</option><option value="1000">1000</option></select></label><div class="ml-auto flex items-center gap-2"><button class="toolbar-button h-8" data-prev>←</button><span data-page></span><button class="toolbar-button h-8" data-next>→</button></div></div></div>`;
    const search = win.body.querySelector('[data-search]');
    let searchTimer;
    search.value = win.state.q;
    search.oninput = () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { win.state.q = search.value; win.state.page = 1; scheduleSaveOpenWindows(); loadReport(win); }, 300); };
    win.body.querySelector('[data-refresh]').onclick = () => loadReport(win);
    initializeOutputButtons(win);
    win.body.querySelector('[data-per-page]').onchange = event => { win.state.perPage = Number(event.target.value); win.state.page = 1; scheduleSaveOpenWindows(); loadReport(win); };
    win.body.querySelector('[data-prev]').onclick = () => { if (win.state.page > 1) { win.state.page--; scheduleSaveOpenWindows(); loadReport(win); } };
    win.body.querySelector('[data-next]').onclick = () => { if (win.state.page < (win.state.pages || 1)) { win.state.page++; scheduleSaveOpenWindows(); loadReport(win); } };
    await loadReport(win);
    return win;
  }

  async function loadReport(win) {
    const grid = win.body.querySelector('[data-grid]');
    grid.innerHTML = '<div class="p-8 text-center text-sm text-slate-500">İlişkili satışlar yükleniyor…</div>';
    try {
      const s = win.state;
      const data = await api(`?action=report&report=${encodeURIComponent(s.report)}&id=${encodeURIComponent(s.id)}&page=${s.page}&per_page=${s.perPage}&q=${encodeURIComponent(s.q)}&sort=${encodeURIComponent(s.sort)}&dir=${s.dir}`);
      s.pages = data.pages; s.perPage = data.per_page;
      updateWindowTitle(win, `${data.title} (#${s.id})`);
      const moneyColumns = moneyColumnSet(data);
      const header = data.columns.map(column => `<th data-sort="${escapeHtml(column)}" class="sticky top-0 cursor-pointer whitespace-nowrap border-b border-r border-slate-300 bg-slate-200 px-3 py-2 ${moneyColumns.has(column) ? 'text-right' : 'text-left'} text-xs font-semibold text-slate-700 hover:bg-slate-300">${escapeHtml(column)}${data.sort === column ? (data.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('');
      const rows = data.rows.map(row => `<tr data-id="${escapeHtml(row.ID)}" class="cursor-pointer border-b border-slate-200 hover:bg-blue-50">${data.columns.map(column => { const value=moneyColumns.has(column)?formatMoney(row[column],data.currency):String(row[column]??''); return `<td class="max-w-80 truncate whitespace-nowrap border-r border-slate-100 px-3 py-2 text-xs${moneyColumns.has(column)?' text-right tabular-nums':''}" title="${escapeHtml(value)}">${escapeHtml(value)}</td>`; }).join('')}</tr>`).join('');
      grid.innerHTML = `<table class="min-w-full border-collapse"><thead><tr>${header}</tr></thead><tbody>${rows || `<tr><td colspan="${data.columns.length}" class="p-10 text-center text-sm text-slate-500">Satış kaydı bulunamadı.</td></tr>`}</tbody>${reportTotalsFooter(data)}</table>`;
      grid.querySelectorAll('th[data-sort]').forEach(th => th.onclick = () => { const name = th.dataset.sort; s.dir = s.sort === name && s.dir === 'asc' ? 'desc' : 'asc'; s.sort = name; s.page = 1; scheduleSaveOpenWindows(); loadReport(win); });
      grid.querySelectorAll('tr[data-id]').forEach((reportRow, rowIndex) => {
        const invoiceId = Number(data.rows[rowIndex]?.__faturaID || 0);
        if (invoiceId > 0) reportRow.title = 'Çift tıklayın: faturayı aç';
        reportRow.ondblclick = () => {
          if (invoiceId > 0) openInvoice(invoiceId);
          else toast('Bu satırın bağlı faturası bulunamadı.', 'error');
        };
      });
      const firstVisible = data.total === 0 ? 0 : ((data.page - 1) * data.per_page) + 1;
      const lastVisible = data.total === 0 ? 0 : firstVisible + data.rows.length - 1;
      win.body.querySelector('[data-summary]').textContent = `${firstVisible.toLocaleString('tr-TR')}–${lastVisible.toLocaleString('tr-TR')} / ${data.total.toLocaleString('tr-TR')} kayıt`;
      win.body.querySelector('[data-page]').textContent = `${data.page} / ${data.pages}`;
      win.body.querySelector('[data-per-page]').value = String(data.per_page);
      win.body.querySelector('[data-prev]').disabled = data.page <= 1;
      win.body.querySelector('[data-next]').disabled = data.page >= data.pages;
      win.body.querySelector('[data-net-quantity]').textContent = Number(data.summary.net_quantity || 0).toLocaleString('tr-TR');
      win.body.querySelector('[data-net-total]').textContent = formatMoney(data.summary.net_total || 0, data.currency);
    } catch (error) { grid.innerHTML = `<div class="p-8 text-center text-sm text-red-700">${escapeHtml(error.message)}</div>`; }
  }

  const reportNumber = value => {
    const number = Number(value);
    if (!Number.isFinite(number)) return escapeHtml(value);
    return number.toLocaleString('tr-TR', {minimumFractionDigits: Number.isInteger(number) ? 0 : 2, maximumFractionDigits: 2});
  };

  function reportTotalsFooter(data, leadingCells = 0) {
    const totalRow=reportTotalRow(data);
    if(!totalRow)return '';
    const moneyColumns=moneyColumnSet(data),numericColumns=new Set(data.numeric_columns||Object.keys(data.totals||{}));
    const leading=Array.from({length:leadingCells},()=>'<td class="sticky bottom-0 border-r border-blue-300 bg-blue-100 px-2 py-2"></td>').join('');
    const cells=data.columns.map((column,index)=>{
      const raw=totalRow[column];
      const value=index===0?'TOPLAM':raw===''?'':moneyColumns.has(column)?formatMoney(raw,data.currency):numericColumns.has(column)?reportNumber(raw):String(raw??'');
      const align=moneyColumns.has(column)||numericColumns.has(column)?' text-right tabular-nums':' text-left';
      return `<td class="sticky bottom-0 whitespace-nowrap border-t-2 border-r border-blue-300 bg-blue-100 px-3 py-2 text-xs font-bold text-blue-950${align}">${escapeHtml(value)}</td>`;
    }).join('');
    return `<tfoot><tr>${leading}${cells}</tr></tfoot>`;
  }

  function renderBusinessChart(container, data) {
    const series = data.chart_series || [];
    const rows = (data.chart_rows || []).slice(0, 40);
    if (!series.length || rows.length < 2) { container.classList.add('hidden'); container.innerHTML = ''; return false; }
    const values = rows.flatMap(row => series.map(name => Math.abs(Number(row[name]) || 0)));
    const maximum = Math.max(1, ...values);
    const colors = ['#2563eb','#059669','#dc2626','#d97706','#7c3aed'];
    const width = 960, height = 190, left = 48, right = 16, top = 18, bottom = 32;
    const plotWidth = width - left - right, plotHeight = height - top - bottom;
    const lines = series.map((name, seriesIndex) => {
      const points = rows.map((row, index) => {
        const x = left + (rows.length === 1 ? plotWidth / 2 : index * plotWidth / (rows.length - 1));
        const y = top + plotHeight - ((Number(row[name]) || 0) / maximum) * plotHeight;
        return `${x.toFixed(1)},${Math.max(top, Math.min(top + plotHeight, y)).toFixed(1)}`;
      }).join(' ');
      return `<polyline fill="none" stroke="${colors[seriesIndex % colors.length]}" stroke-width="2.5" points="${points}"/>`;
    }).join('');
    const legends = series.map((name, index) => `<span class="flex items-center gap-1"><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:${colors[index % colors.length]}"></i>${escapeHtml(name)}</span>`).join('');
    container.innerHTML = `<div class="flex items-center gap-4 border-b border-slate-200 px-3 py-2 text-xs text-slate-600">${legends}<span class="ml-auto">İlk ${rows.length} satır</span></div><svg class="block h-48 w-full bg-white" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none"><line x1="${left}" y1="${top + plotHeight}" x2="${width-right}" y2="${top + plotHeight}" stroke="#cbd5e1"/><line x1="${left}" y1="${top}" x2="${left}" y2="${top+plotHeight}" stroke="#cbd5e1"/>${lines}</svg>`;
    return true;
  }

  function updateBusinessChartVisibility(win, hasChart = true) {
    const chart = win.body.querySelector('[data-chart]');
    const button = win.body.querySelector('[data-chart-toggle]');
    if (!hasChart) win.state.chartExpanded = false;
    chart.classList.toggle('hidden', !hasChart || !win.state.chartExpanded);
    button.disabled = !hasChart;
    button.textContent = win.state.chartExpanded && hasChart ? '▴ Grafiği gizle' : '▾ Grafiği göster';
  }

  async function openBusinessReport(report, label, restoredState = null) {
    const win = createWindow(`business-report:${report}`, label, 1240, 760);
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'business_report';
    win.state = {...{page:1,perPage:250,sort:'',dir:'asc',grouping:'monthly',preset:'this_year',start:'',end:'',chartExpanded:false}, ...(restoredState || {}), report, label};
    scheduleSaveOpenWindows();
    win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button" data-refresh>↻ Yenile</button><label class="text-xs text-slate-600">Görünüm <select class="rounded border border-slate-300 bg-white px-2 py-2 text-xs" data-grouping><option value="daily">Günlük</option><option value="monthly">Aylık</option></select></label><label class="text-xs text-slate-600">Aralık <select class="rounded border border-slate-300 bg-white px-2 py-2 text-xs" data-preset><option value="current_month">Bu ay</option><option value="last_month">Geçen ay</option><option value="last_3_months">Son 3 ay</option><option value="this_year">Bu yıl</option><option value="last_year">Geçen yıl</option><option value="custom">Serbest aralık</option></select></label><label class="hidden text-xs text-slate-600" data-custom-date>Başlangıç <input class="rounded border border-slate-300 bg-white px-2 py-1.5 text-xs" type="date" data-start></label><label class="hidden text-xs text-slate-600" data-custom-date>Bitiş <input class="rounded border border-slate-300 bg-white px-2 py-1.5 text-xs" type="date" data-end></label><span class="rounded-full border border-blue-300 bg-blue-50 px-3 py-1 text-xs text-blue-800" data-range-label></span><div class="ml-auto flex gap-2"><button class="toolbar-button" data-chart-toggle>▾ Grafiği göster</button><button class="toolbar-button" data-export>⇩ Excel</button><button class="toolbar-button" data-print>▧ Yazdır / PDF</button></div></div><div class="hidden shrink-0 border-b border-slate-300 bg-white" data-chart></div><div class="min-h-0 flex-1 overflow-auto bg-white" data-grid></div><div class="flex h-11 shrink-0 items-center gap-3 border-t border-slate-300 bg-slate-100 px-3 text-sm"><span data-summary class="whitespace-nowrap text-slate-500"></span><label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-600">Satır <select data-per-page class="rounded border border-slate-300 bg-white px-2 py-1 text-xs"><option value="50">50</option><option value="100">100</option><option value="250">250</option><option value="500">500</option><option value="1000">1000</option></select></label><div class="ml-auto flex items-center gap-2"><button class="toolbar-button h-8" data-prev>←</button><span data-page></span><button class="toolbar-button h-8" data-next>→</button></div></div></div>`;
    const s = win.state;
    const grouping = win.body.querySelector('[data-grouping]');
    const preset = win.body.querySelector('[data-preset]');
    const start = win.body.querySelector('[data-start]');
    const end = win.body.querySelector('[data-end]');
    grouping.value = s.grouping; preset.value = s.preset; start.value = s.start; end.value = s.end;
    const toggleCustom = () => win.body.querySelectorAll('[data-custom-date]').forEach(item => item.classList.toggle('hidden', preset.value !== 'custom'));
    toggleCustom();
    grouping.onchange = () => { s.grouping=grouping.value; s.page=1; scheduleSaveOpenWindows(); loadBusinessReport(win); };
    preset.onchange = () => { s.preset=preset.value; s.page=1; toggleCustom(); if(s.preset!=='custom'){s.start='';s.end='';loadBusinessReport(win);} scheduleSaveOpenWindows(); };
    const customChanged = () => { s.start=start.value;s.end=end.value;if(s.start&&s.end){s.page=1;scheduleSaveOpenWindows();loadBusinessReport(win);} };
    start.onchange=customChanged; end.onchange=customChanged;
    win.body.querySelector('[data-refresh]').onclick=()=>loadBusinessReport(win);
    win.body.querySelector('[data-chart-toggle]').onclick=()=>{s.chartExpanded=!s.chartExpanded;updateBusinessChartVisibility(win,Boolean(win.body.querySelector('[data-chart]').innerHTML));scheduleSaveOpenWindows();};
    initializeOutputButtons(win);
    win.body.querySelector('[data-per-page]').value=String(s.perPage);
    win.body.querySelector('[data-per-page]').onchange=e=>{s.perPage=Number(e.target.value);s.page=1;scheduleSaveOpenWindows();loadBusinessReport(win);};
    win.body.querySelector('[data-prev]').onclick=()=>{if(s.page>1){s.page--;scheduleSaveOpenWindows();loadBusinessReport(win);}};
    win.body.querySelector('[data-next]').onclick=()=>{if(s.page<(s.pages||1)){s.page++;scheduleSaveOpenWindows();loadBusinessReport(win);}};
    await loadBusinessReport(win);
    return win;
  }

  const executivePeriodSections = [
    {table:'faturabaslik',label:'Faturalar'},
    {table:'kasa',label:'Kasa Hareketleri'},
    {table:'banka_kasa',label:'Banka Hareketleri'},
    {table:'hesaplar',label:'Banka Hesapları'},
    {table:'kategoriler',label:'Finans Kategorileri'},
    {table:'cek_senet_kasa',label:'Çek / Senet'},
  ];

  function executivePeriodRange(period, grouping) {
    const value=String(period||'');
    if(grouping==='daily'&&/^\d{4}-\d{2}-\d{2}$/.test(value)) return {start:value,end:value};
    const match=value.match(/^(\d{4})-(\d{2})$/);
    if(!match) return null;
    const year=Number(match[1]),month=Number(match[2]);
    const lastDay=new Date(Date.UTC(year,month,0)).getUTCDate();
    return {start:value+'-01',end:`${value}-${String(lastDay).padStart(2,'0')}`};
  }

  function openExecutivePeriodSection(section, period, grouping) {
    const range=executivePeriodRange(period,grouping);
    if(!range){toast('Rapor dönemi açılamadı.','error');return;}
    openTable(section.table,section.label,null,null,null,range);
  }

  async function loadBusinessReport(win) {
    const grid=win.body.querySelector('[data-grid]');
    grid.innerHTML='<div class="p-8 text-center text-sm text-slate-500">Rapor hazırlanıyor…</div>';
    try {
      const s=win.state;
      const data=await api(dataUrlForWindow(win,s.page,s.perPage));
      s.pages=data.pages;s.perPage=data.per_page;s.sort=data.sort;s.dir=data.dir;s.preset=data.range.preset;s.start=data.range.start;s.end=data.range.end;
      updateWindowTitle(win,`${data.title} — ${data.range.start} / ${data.range.end}`);
      win.body.querySelector('[data-range-label]').textContent=`${data.range.start} → ${data.range.end}`;
      win.body.querySelector('[data-start]').value=s.start;win.body.querySelector('[data-end]').value=s.end;
      const moneyColumns=moneyColumnSet(data);
      const hasExecutiveDrilldown=s.report==='executive_overview';
      const header=data.columns.map(column=>`<th data-sort="${escapeHtml(column)}" class="sticky top-0 cursor-pointer whitespace-nowrap border-b border-r border-slate-300 bg-slate-200 px-3 py-2 ${moneyColumns.has(column)?'text-right':'text-left'} text-xs font-semibold text-slate-700 hover:bg-slate-300">${escapeHtml(column)}${data.sort===column?(data.dir==='asc'?' ↑':' ↓'):''}</th>`).join('');
      const rows=data.rows.map((row,rowIndex)=>`<tr ${hasExecutiveDrilldown?`data-executive-period="${escapeHtml(row['Dönem']||'')}" title="Çift tıklayın: dönemin faturalarını aç"`:''} class="${hasExecutiveDrilldown?'cursor-pointer ':''}border-b border-slate-200 hover:bg-blue-50">${hasExecutiveDrilldown?`<td class="sticky left-0 z-10 w-4 border-r border-slate-200 bg-slate-50 px-2 py-1 text-xs"><button type="button" data-executive-row-action="${rowIndex}" class="w-full select-none rounded border border-slate-300 bg-white px-2 py-1 text-center font-medium text-blue-700 hover:bg-blue-50">+</button></td>`:''}${data.columns.map(column=>{const value=moneyColumns.has(column)?formatMoney(row[column],data.currency):(typeof row[column]==='number'?reportNumber(row[column]):String(row[column]??''));return `<td class="max-w-80 truncate whitespace-nowrap border-r border-slate-100 px-3 py-2 text-xs${moneyColumns.has(column)?' text-right tabular-nums':''}" title="${escapeHtml(value)}">${escapeHtml(value)}</td>`;}).join('')}</tr>`).join('');
      grid.innerHTML=`<table class="min-w-full border-collapse"><thead><tr>${hasExecutiveDrilldown?'<th class="sticky left-0 top-0 z-30 border-b border-r border-slate-300 bg-slate-200 px-2 py-2"></th>':''}${header}</tr></thead><tbody>${rows||`<tr><td colspan="${data.columns.length+(hasExecutiveDrilldown?1:0)}" class="p-10 text-center text-sm text-slate-500">Seçilen aralıkta veri bulunamadı.</td></tr>`}</tbody>${reportTotalsFooter(data,hasExecutiveDrilldown?1:0)}</table>`;
      grid.querySelectorAll('th[data-sort]').forEach(th=>th.onclick=()=>{const name=th.dataset.sort;s.dir=s.sort===name&&s.dir==='asc'?'desc':'asc';s.sort=name;s.page=1;scheduleSaveOpenWindows();loadBusinessReport(win);});
      if(hasExecutiveDrilldown){
        grid.querySelectorAll('[data-executive-period]').forEach(row=>row.ondblclick=()=>openExecutivePeriodSection(executivePeriodSections[0],row.dataset.executivePeriod,s.grouping));
        grid.querySelectorAll('[data-executive-row-action]').forEach(trigger=>trigger.onclick=event=>{
          event.preventDefault();event.stopPropagation();
          const row=data.rows[Number(trigger.dataset.executiveRowAction)];
          const period=row['Dönem'];
          const items=executivePeriodSections.map(section=>({action:{label:section.label},section}));
          openRelationMenu(trigger,items,item=>openExecutivePeriodSection(item.section,period,s.grouping));
        });
        grid.addEventListener('scroll',closeRelationMenu,{passive:true});
      }
      const hasChart=renderBusinessChart(win.body.querySelector('[data-chart]'),data);
      updateBusinessChartVisibility(win,hasChart);
      const first=data.total===0?0:(data.page-1)*data.per_page+1,last=data.total===0?0:first+data.rows.length-1;
      win.body.querySelector('[data-summary]').textContent=`${first.toLocaleString('tr-TR')}–${last.toLocaleString('tr-TR')} / ${data.total.toLocaleString('tr-TR')} satır`;
      win.body.querySelector('[data-page]').textContent=`${data.page} / ${data.pages}`;
      win.body.querySelector('[data-per-page]').value=String(data.per_page);
      win.body.querySelector('[data-prev]').disabled=data.page<=1;win.body.querySelector('[data-next]').disabled=data.page>=data.pages;
    } catch(error){grid.innerHTML=`<div class="p-8 text-center text-sm text-red-700">${escapeHtml(error.message)}</div>`;updateBusinessChartVisibility(win,false);}
  }

  function inputForField(field, value, table = '') {
    const name = escapeHtml(field.name), raw = value ?? field.default ?? '';
    if (field.extra.toLowerCase().includes('auto_increment')) return `<input class="form-input bg-slate-100" value="${escapeHtml(raw)}" disabled>`;
    if (field.value_labels && Object.keys(field.value_labels).length) {
      const options = Object.entries(field.value_labels).map(([stored, label]) => `<option value="${escapeHtml(stored)}" ${String(raw) === String(stored) ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('');
      return `<select class="form-input" name="${name}">${options}</select>`;
    }
    if (table === 'adresdefteri' && field.name === 'Kisisel') {
      const personal = Number(raw) === 1;
      return `<div class="flex gap-4 rounded border border-slate-300 bg-white px-3 py-2"><label class="flex items-center gap-2"><input type="radio" name="Kisisel" value="0" ${personal ? '' : 'checked'}> Kurumsal</label><label class="flex items-center gap-2"><input type="radio" name="Kisisel" value="1" ${personal ? 'checked' : ''}> Kişisel</label></div>`;
    }
    if (table === 'adresdefteri' && ['ulkeID', 'Sehir', 'Ilce'].includes(field.name)) {
      return `<select class="form-input" name="${name}" data-location="${name}" data-current="${escapeHtml(raw)}"><option value="">Yükleniyor…</option></select>`;
    }
    if (table === 'adresdefteri' && field.name === 'gizle') {
      const enabled = Number(raw) === 1;
      return `<button type="button" class="toolbar-button ${enabled ? 'bg-red-700 text-white' : ''}" data-toggle-input data-on-label="Gizli: Açık" data-off-label="Gizli: Kapalı">${enabled ? 'Gizli: Açık' : 'Gizli: Kapalı'}</button><input type="hidden" name="gizle" value="${enabled ? 1 : 0}">`;
    }
    if (field.relation) {
      const [target, label] = field.relation;
      if (table === 'asp_urun' && ['ureticiID', 'birimID', 'urunGrupID', 'CinYazarID'].includes(field.name)) {
        return `<div class="flex items-center gap-3 py-2" data-product-lookup data-target="${escapeHtml(target)}" data-label="${escapeHtml(label)}" data-field-name="${name}" data-nullable="${field.nullable ? 'true' : 'false'}" data-missing="${escapeHtml(field.missing_label || 'Bağlantı bulunamadı')}"><input type="hidden" name="${name}" value="${escapeHtml(raw)}"><span class="min-w-0 flex-1 break-words text-sm" data-lookup-label>${raw && Number(raw) !== 0 ? escapeHtml(`#${raw}`) : 'Seçilmedi'}</span><button type="button" class="shrink-0 text-sm text-blue-700 underline" data-edit-lookup aria-label="${name} seçimini düzenle">Düzenle</button></div>`;
      }
      return `<div class="relation-control relative" data-relation data-target="${escapeHtml(target)}" data-label="${escapeHtml(label)}" data-missing="${escapeHtml(field.missing_label || 'Bağlantı bulunamadı')}"><input type="hidden" name="${name}" value="${escapeHtml(raw)}"><input class="form-input" data-relation-search value="${raw ? escapeHtml(`#${raw}`) : ''}" placeholder="İsim veya ID ara…" autocomplete="off"><div class="absolute z-20 mt-1 hidden max-h-52 w-full overflow-auto rounded border border-slate-300 bg-white shadow-xl" data-options></div></div>`;
    }
    if (/longtext|text/i.test(field.type) || /varchar\(([3-9]\d\d|\d{4,})\)/i.test(field.type)) return `<textarea class="form-input min-h-24" name="${name}">${escapeHtml(raw)}</textarea>`;
    if (/datetime|timestamp/i.test(field.type)) { const formatted = String(raw).startsWith('0000-00-00') ? '' : String(raw).replace(' ', 'T').slice(0, 16); return `<input class="form-input" type="datetime-local" name="${name}" value="${escapeHtml(formatted)}">`; }
    if (/^date/i.test(field.type)) return `<input class="form-input" type="date" name="${name}" value="${escapeHtml(String(raw).startsWith('0000-00-00') ? '' : raw)}">`;
    if (/int|double|float|decimal/i.test(field.type)) return `<input class="form-input" type="number" step="any" name="${name}" value="${escapeHtml(raw)}">`;
    return `<input class="form-input" name="${name}" value="${escapeHtml(raw)}">`;
  }

  async function initializeCariEditor(form) {
    const country = form.querySelector('[data-location="ulkeID"]');
    const city = form.querySelector('[data-location="Sehir"]');
    const district = form.querySelector('[data-location="Ilce"]');
    if (!country || !city || !district) return;
    const cityRow = city.closest('[data-field]');
    const districtRow = district.closest('[data-field]');
    const foreignCityRow = form.querySelector('[data-field="sehirAD"]');
    const optionHtml = (items, selected, placeholder) => `<option value="0">${escapeHtml(placeholder)}</option>` + items.map(item => `<option value="${escapeHtml(item.ID)}" ${String(item.ID) === String(selected) ? 'selected' : ''}>${escapeHtml(item.label ?? '')}</option>`).join('');
    const loadLocations = async (countryId, cityId, preserveDistrict = false) => {
      const data = await api(`?action=locations&table=adresdefteri&country_id=${encodeURIComponent(countryId)}&city_id=${encodeURIComponent(cityId)}`);
      if (!country.options.length || country.options[0]?.textContent === 'Yükleniyor…') country.innerHTML = optionHtml(data.countries, country.dataset.current || countryId, 'Ülke seçin');
      city.innerHTML = optionHtml(data.cities, cityId, 'Şehir seçin');
      district.innerHTML = optionHtml(data.districts, preserveDistrict ? district.dataset.current : 0, 'İlçe seçin');
      const domestic = Number(countryId) === 1;
      cityRow?.classList.toggle('hidden', !domestic);
      districtRow?.classList.toggle('hidden', !domestic);
      foreignCityRow?.classList.toggle('hidden', domestic);
    };
    const initialCountry = Number(country.dataset.current || 1);
    const initialCity = Number(city.dataset.current || 0);
    await loadLocations(initialCountry, initialCity, true);
    country.onchange = async () => { await loadLocations(Number(country.value), 0); form.dispatchEvent(new Event('input', {bubbles: true})); };
    city.onchange = async () => { await loadLocations(Number(country.value), Number(city.value)); form.dispatchEvent(new Event('input', {bubbles: true})); };
    form.querySelectorAll('input[name="Kisisel"]').forEach(radio => radio.onchange = () => {
      const personal = radio.value === '1' && radio.checked;
      form.querySelector('[data-field="FirmaAd"]')?.classList.toggle('hidden', personal);
      form.querySelector('[data-field="Ad"]')?.classList.toggle('hidden', !personal);
      form.querySelector('[data-field="Soyad"]')?.classList.toggle('hidden', !personal);
    });
    form.querySelector('input[name="Kisisel"]:checked')?.dispatchEvent(new Event('change'));
    form.querySelectorAll('[data-toggle-input]').forEach(button => button.onclick = () => {
      const hidden = button.nextElementSibling;
      hidden.value = hidden.value === '1' ? '0' : '1';
      button.textContent = hidden.value === '1' ? button.dataset.onLabel : button.dataset.offLabel;
      button.classList.toggle('bg-red-700', hidden.value === '1');
      button.classList.toggle('text-white', hidden.value === '1');
      form.dispatchEvent(new Event('input', {bubbles: true}));
    });
  }

  function openProductLookup(control) {
    const hidden = control.querySelector('input[type="hidden"]');
    const label = control.querySelector('[data-lookup-label]');
    const trigger = control.querySelector('[data-edit-lookup]');
    const parentTitle = control.closest('.desktop-window').querySelector('[data-window-title]').textContent;
    const dialog = document.createElement('dialog');
    dialog.className = 'product-lookup-dialog rounded-lg border border-slate-400 bg-white p-0 text-slate-900 shadow-2xl';
    dialog.setAttribute('aria-labelledby', 'product-lookup-title');
    dialog.innerHTML = `<div class="flex h-full flex-col"><header class="flex shrink-0 items-center gap-3 border-b border-slate-300 bg-slate-100 p-3"><div class="min-w-0 flex-1"><h2 id="product-lookup-title" class="font-semibold">${escapeHtml(control.dataset.fieldName)} — Seçim</h2><p class="truncate text-xs text-slate-500">${escapeHtml(parentTitle)}</p></div><button type="button" class="toolbar-button" data-cancel aria-label="Kapat">×</button></header><div class="shrink-0 p-3"><label class="text-sm">İsim veya ID ile filtrele<input type="search" class="form-input mt-1" data-search autocomplete="off"></label></div><div class="min-h-0 flex-1 overflow-auto border-y border-slate-200 p-2" data-options></div><p class="shrink-0 px-3 py-2 text-xs text-slate-500" role="status" aria-live="polite" data-status></p><footer class="flex shrink-0 flex-wrap items-center gap-2 p-3"><button type="button" class="toolbar-button" data-prev>← Önceki</button><button type="button" class="toolbar-button" data-next>Sonraki →</button><div class="ml-auto flex gap-2"><button type="button" class="toolbar-button" data-cancel>Vazgeç</button><button type="button" class="toolbar-button" data-choose disabled>Seç</button></div></footer></div>`;
    const search = dialog.querySelector('[data-search]');
    const options = dialog.querySelector('[data-options]');
    const status = dialog.querySelector('[data-status]');
    const choose = dialog.querySelector('[data-choose]');
    const previous = dialog.querySelector('[data-prev]');
    const next = dialog.querySelector('[data-next]');
    let page = 1, selected = null, requestVersion = 0, timer, controller;
    const load = async () => {
      const version = ++requestVersion;
      controller?.abort();
      controller = new AbortController();
      selected = null; choose.disabled = true; previous.disabled = true; next.disabled = true;
      options.textContent = 'Yükleniyor…'; status.textContent = '';
      try {
        const data = await api(`?action=lookup&table=${encodeURIComponent(control.dataset.target)}&label=${encodeURIComponent(control.dataset.label)}&q=${encodeURIComponent(search.value.trim())}&page=${page}`, {signal:controller.signal});
        if (!dialog.open || version !== requestVersion) return;
        const items = [...(control.dataset.nullable === 'true' ? [{ID:'', label:'Seçilmedi'}] : []), ...data.options];
        options.innerHTML = items.map((item, index) => `<label class="flex cursor-pointer items-center gap-3 rounded border-b border-slate-100 p-3 text-sm hover:bg-blue-50"><input type="radio" name="product-lookup-choice" value="${index}" ${String(item.ID) === hidden.value ? 'checked' : ''}><span>${escapeHtml(item.label || `#${item.ID}`)}</span>${item.ID !== '' ? `<span class="ml-auto text-xs text-slate-500">#${escapeHtml(item.ID)}</span>` : ''}</label>`).join('') || '<p class="p-3 text-sm text-slate-500">Sonuç yok.</p>';
        const checked = options.querySelector('input:checked');
        if (checked) { selected = items[Number(checked.value)]; choose.disabled = false; }
        options.querySelectorAll('input').forEach(input => input.onchange = () => { selected = items[Number(input.value)]; choose.disabled = false; });
        previous.disabled = page <= 1; next.disabled = !data.has_more;
        status.textContent = `Sayfa ${page} · ${data.options.length} seçenek`;
      } catch (error) {
        if (version !== requestVersion || !dialog.open || error.name === 'AbortError') return;
        options.textContent = 'Liste yüklenemedi.'; status.textContent = error.message;
        previous.disabled = page <= 1;
      }
    };
    search.oninput = () => {
      clearTimeout(timer); controller?.abort(); ++requestVersion;
      selected = null; choose.disabled = true; previous.disabled = true; next.disabled = true;
      options.textContent = 'Yükleniyor…'; page = 1;
      timer = setTimeout(load, 250);
    };
    previous.onclick = () => { page--; load(); };
    next.onclick = () => { page++; load(); };
    choose.onclick = () => {
      if (!selected) return;
      const changed = hidden.value !== String(selected.ID);
      hidden.value = String(selected.ID);
      label.textContent = selected.label || (selected.ID === '' ? 'Seçilmedi' : `#${selected.ID}`);
      if (changed) hidden.dispatchEvent(new Event('input', {bubbles:true}));
      dialog.close();
    };
    dialog.querySelectorAll('[data-cancel]').forEach(button => button.onclick = () => dialog.close());
    dialog.addEventListener('close', () => {
      clearTimeout(timer); controller?.abort(); ++requestVersion;
      dialog.remove(); trigger.focus();
    }, {once:true});
    document.body.append(dialog);
    dialog.showModal();
    search.focus();
    load();
  }

  async function initializeRelations(container) {
    container.querySelectorAll('[data-product-lookup]').forEach(async control => {
      const hidden = control.querySelector('input[type="hidden"]');
      const label = control.querySelector('[data-lookup-label]');
      const initialValue = hidden.value;
      control.querySelector('[data-edit-lookup]').onclick = () => openProductLookup(control);
      if (!initialValue || Number(initialValue) === 0) return;
      try {
        const data = await api(`?action=lookup&table=${encodeURIComponent(control.dataset.target)}&label=${encodeURIComponent(control.dataset.label)}&id=${encodeURIComponent(initialValue)}`);
        if (hidden.value === initialValue) label.textContent = data.options[0]?.label || `#${initialValue} — ${control.dataset.missing}`;
      } catch (error) {
        if (hidden.value === initialValue) label.textContent = `#${initialValue} — ${error.message}`;
      }
    });
    container.querySelectorAll('[data-relation]').forEach(async control => {
      const visible = control.querySelector('[data-relation-search]'), hidden = control.querySelector('input[type="hidden"]'), options = control.querySelector('[data-options]');
      const render = items => {
        options.innerHTML = items.map(item => `<button type="button" data-value="${escapeHtml(item.ID)}" data-text="${escapeHtml(item.label ?? '')}" class="block w-full px-3 py-2 text-left text-sm hover:bg-blue-50"><b>#${escapeHtml(item.ID)}</b> ${escapeHtml(item.label ?? '')}</button>`).join('') || '<div class="p-3 text-sm text-slate-500">Sonuç yok.</div>';
        options.classList.remove('hidden');
        options.querySelectorAll('button').forEach(button => button.onclick = () => { hidden.value = button.dataset.value; visible.value = `#${button.dataset.value} — ${button.dataset.text}`; options.classList.add('hidden'); visible.dispatchEvent(new Event('change', {bubbles: true})); });
      };
      if (hidden.value && Number(hidden.value) > 0) {
        try { const data = await api(`?action=lookup&table=${encodeURIComponent(control.dataset.target)}&label=${encodeURIComponent(control.dataset.label)}&id=${encodeURIComponent(hidden.value)}`); if (data.options[0]) visible.value = `#${data.options[0].ID} — ${data.options[0].label ?? ''}`; else { visible.value = `#${hidden.value} — ${control.dataset.missing}`; visible.classList.add('border-red-300', 'bg-red-50', 'text-red-700'); } } catch (_) {}
      }
      let timer;
      visible.oninput = () => { hidden.value = ''; clearTimeout(timer); timer = setTimeout(async () => { try { const data = await api(`?action=lookup&table=${encodeURIComponent(control.dataset.target)}&label=${encodeURIComponent(control.dataset.label)}&q=${encodeURIComponent(visible.value.replace(/^#/, ''))}`); render(data.options); } catch (error) { toast(error.message, 'error'); } }, 250); };
      visible.onfocus = () => { if (visible.value === '') visible.dispatchEvent(new Event('input')); };
      visible.onblur = () => setTimeout(() => options.classList.add('hidden'), 180);
    });
  }

  async function openEditor(table, label, id, parentWin, restoreOptions = {}) {
    const key = restoreOptions.key || (id ? `edit:${table}:${id}` : `new:${table}:${Date.now()}`);
    const win = createWindow(key, `${id ? 'Düzenle' : 'Yeni'} — ${label}`, 820, 680);
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'editor';
    win.state = {table, label, id: id || null, parentKey: parentWin?.key || restoreOptions.parentKey || null};
    scheduleSaveOpenWindows();
    try {
      const [metadata, recordData] = await Promise.all([api(`?action=metadata&table=${encodeURIComponent(table)}`), id ? api(`?action=record&table=${encodeURIComponent(table)}&id=${id}`) : Promise.resolve({record: {}})]);
      const record = recordData.record;
      const fields = metadata.fields.map(field => `<div class="block ${/longtext|text/i.test(field.type) ? 'md:col-span-2' : ''}" data-field="${escapeHtml(field.name)}"><span class="mb-1 flex items-center gap-2 text-xs font-semibold text-slate-600"><span>${escapeHtml(field.name)}</span><span class="font-normal text-slate-400">${escapeHtml(field.type)}</span>${field.nullable ? '' : '<span class="text-red-500">*</span>'}</span>${inputForField(field, record[field.name], table)}${field.comment ? `<span class="mt-1 block text-xs text-slate-400">${escapeHtml(field.comment)}</span>` : ''}</div>`).join('');
      const deleteButton = id && ['adresdefteri', 'asp_urun'].includes(table) ? '<button class="toolbar-button bg-red-600 text-white hover:bg-red-700" type="button" data-delete-record>⌫ Sil</button>' : '';
      const editorActions = id && table === 'adresdefteri' ? (metadata.row_actions || []).map((action, index) => {
        const value = action.value === 'ID' ? id : record[action.value];
        return value !== null && value !== '' && String(value) !== '0' ? {...action, value, index} : null;
      }).filter(Boolean) : [];
      const shortcutBar = editorActions.length ? `<div class="flex shrink-0 items-center gap-2 overflow-x-auto border-b border-slate-300 bg-blue-50 px-2 py-1.5"><span class="shrink-0 text-xs font-semibold text-blue-800">Bağlantılar</span>${editorActions.map((action, index) => `<button class="toolbar-button h-8 shrink-0" type="button" data-editor-shortcut="${index}">${escapeHtml(action.label)}</button>`).join('')}</div>` : '';
      win.body.innerHTML = `<form class="flex h-full flex-col" data-editor><div class="flex shrink-0 items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button bg-blue-600 text-white hover:bg-blue-700" type="submit">✓ Kaydet</button><button class="toolbar-button" type="button" data-close>İptal</button>${deleteButton}${metadata.transactional ? '<span class="ml-auto rounded bg-amber-100 px-2 py-1 text-xs text-amber-800">İşlem tablosu: dikkatli düzenleyin</span>' : ''}</div>${shortcutBar}<div class="min-h-0 flex-1 overflow-auto p-4"><div class="grid grid-cols-1 gap-4 md:grid-cols-2">${fields}</div></div></form>`;
      const form = win.body.querySelector('form');
      await initializeRelations(form);
      if (table === 'adresdefteri') await initializeCariEditor(form);
      form.addEventListener('input', () => win.dirty = true);
      form.querySelector('[data-close]').onclick = () => closeWindow(win);
      if (form.querySelector('[data-delete-record]')) form.querySelector('[data-delete-record]').onclick = () => requestCascadeDelete(table, id, win);
      form.querySelectorAll('[data-editor-shortcut]').forEach(button => button.onclick = async () => {
        const action = editorActions[Number(button.dataset.editorShortcut)];
        const title = String(record.FirmaAd || `${record.Ad || ''} ${record.Soyad || ''}`.trim() || `Kayıt #${id}`);
        const titleLookup = action.title_lookup ? {...action.title_lookup, id:action.value} : null;
        const context = titleLookup ? await resolveStoredRelationshipContext(titleLookup) : {title, lookup:null};
        if (action.report) openReport(action.report, action.value, action.label, context);
        else if (action.target === 'faturabaslik' && action.filter === 'ID') openInvoice(action.value);
        else openTable(action.target, action.label, {column:action.filter, value:action.value}, context);
      });
      form.onsubmit = async event => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]'); button.disabled = true; button.textContent = 'Kaydediliyor…';
        const payload = Object.fromEntries(new FormData(form).entries());
        if (id) payload.ID = id;
        try {
          const data = await api(`?action=save&table=${encodeURIComponent(table)}`, {method: 'POST', body: JSON.stringify({record: payload})});
          win.dirty = false; toast(`Kayıt #${data.id} kaydedildi.`);
          if (parentWin?.kind === 'invoice') loadInvoice(parentWin);
          else if (parentWin) loadGrid(parentWin);
          closeWindow(win);
        } catch (error) { toast(error.message, 'error'); button.disabled = false; button.textContent = '✓ Kaydet'; }
      };
    } catch (error) { win.body.innerHTML = `<div class="p-8 text-center text-red-700">${escapeHtml(error.message)}</div>`; }
    return win;
  }

  function applyStoredWindowLayout(win, descriptor) {
    const geometry = descriptor.geometry || {};
    const fittedGeometry = fitRectInsideDesktop(geometry, windowGeometry(win));
    const restoreRect = fitRectInsideDesktop(descriptor.restoreRect || geometry, fittedGeometry);
    win.restoreRect = restoreRect;
    win.maximized = false;
    Object.assign(win.el.style, fittedGeometry);
    if (descriptor.maximized) {
      Object.assign(win.el.style, restoreRect);
      maximizeWindow(win);
    }
    if (descriptor.minimized) minimizeWindow(win);
  }

  async function openCurrencySettings(restoredState = null) {
    const win = createWindow('settings:currency', 'Para Birimi Ayarı', 520, 300);
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'settings';
    win.state = {...(restoredState || {}), section: 'currency'};
    scheduleSaveOpenWindows();
    try {
      const data = await api('?action=settings&section=currency');
      const options = data.options.map(item => `<option value="${escapeHtml(item.value)}" ${item.value === data.currency ? 'selected' : ''}>${escapeHtml(item.label)}</option>`).join('');
      win.body.innerHTML = `<form class="flex h-full flex-col" data-settings-form><div class="flex-1 space-y-4 overflow-auto p-6"><div><h2 class="text-lg font-semibold text-slate-800">Varsayılan para birimi</h2><p class="mt-1 text-sm text-slate-500">Uygulamadaki tüm finansal işlemler için tek bir para birimi kullanılır.</p></div><label class="block"><span class="mb-1 block text-sm font-medium text-slate-700">Para birimi</span><select name="currency" class="form-input">${options}</select></label></div><div class="flex shrink-0 justify-end gap-2 border-t border-slate-300 bg-slate-100 p-3"><button type="button" class="toolbar-button" data-cancel>Vazgeç</button><button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Kaydet</button></div></form>`;
      const form = win.body.querySelector('[data-settings-form]');
      form.querySelector('[data-cancel]').onclick = () => closeWindow(win);
      form.onsubmit = async event => {
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
          const result = await api('?action=save_settings', {method: 'POST', body: JSON.stringify({section: 'currency', currency: form.elements.currency.value})});
          toast(`Varsayılan para birimi ${result.currency} olarak kaydedildi.`);
        } catch (error) {
          toast(error.message, 'error');
        } finally {
          submit.disabled = false;
        }
      };
    } catch (error) {
      win.body.innerHTML = `<div class="p-8 text-center text-red-700">${escapeHtml(error.message)}</div>`;
    }
    return win;
  }

  const productionInput = (name, definition, value) => {
    const label = escapeHtml(definition.label || name);
    const common = `name="${escapeHtml(name)}" data-production-field="${escapeHtml(name)}"`;
    let control = '';
    if (definition.type === 'boolean') {
      control = `<label class="flex h-9 items-center gap-2 rounded border border-slate-300 bg-white px-3"><input type="checkbox" ${common} value="1" ${Number(value) !== 0 ? 'checked' : ''}><span class="text-sm">Evet / Açık</span></label>`;
    } else if (definition.type === 'select') {
      const options = [''].concat(definition.options || []).map(option => `<option value="${escapeHtml(option)}" ${String(value ?? '') === option ? 'selected' : ''}>${escapeHtml(option || '— Seçiniz —')}</option>`).join('');
      control = `<select ${common} class="form-input">${options}</select>`;
    } else if (definition.type === 'textarea') {
      control = `<textarea ${common} class="form-input min-h-24">${escapeHtml(value ?? '')}</textarea>`;
    } else {
      const type = definition.type === 'money' || definition.type === 'number' ? 'number' : definition.type === 'email' ? 'email' : definition.type === 'date' ? 'date' : 'text';
      const step = definition.type === 'money' ? ' step="0.01"' : definition.type === 'number' ? ' step="1"' : '';
      const shown = definition.type === 'date' && value ? String(value).slice(0, 10) : value ?? '';
      control = `<input ${common} type="${type}"${step} value="${escapeHtml(shown)}" class="form-input" ${definition.required ? 'required' : ''}>`;
    }
    return `<label class="block ${definition.type === 'textarea' ? 'md:col-span-2' : ''}"><span class="mb-1 block text-xs font-semibold text-slate-600">${label}${definition.required ? ' <span class="text-red-500">*</span>' : ''}</span>${control}</label>`;
  };

  async function openProductionReport(report, label, restoredState = null) {
    const win = createWindow(`production-report:${report}`, label, 1240, 760);
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'production_report';
    win.state = {...{page:1,perPage:250,sort:'',dir:'asc',grouping:'monthly',preset:'this_year',start:'',end:'',chartExpanded:false}, ...(restoredState || {}), report, label};
    scheduleSaveOpenWindows();
    win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button" data-refresh>↻ Yenile</button><label class="text-xs text-slate-600">Görünüm <select class="rounded border border-slate-300 bg-white px-2 py-2 text-xs" data-grouping><option value="monthly">Aylık</option><option value="yearly">Yıllık</option></select></label><label class="text-xs text-slate-600">Aralık <select class="rounded border border-slate-300 bg-white px-2 py-2 text-xs" data-preset><option value="current_month">Bu ay</option><option value="last_month">Geçen ay</option><option value="last_3_months">Son 3 ay</option><option value="this_year">Bu yıl</option><option value="last_year">Geçen yıl</option><option value="custom">Serbest aralık</option></select></label><label class="hidden text-xs text-slate-600" data-custom-date>Başlangıç <input class="rounded border border-slate-300 bg-white px-2 py-1.5 text-xs" type="date" data-start></label><label class="hidden text-xs text-slate-600" data-custom-date>Bitiş <input class="rounded border border-slate-300 bg-white px-2 py-1.5 text-xs" type="date" data-end></label><span class="rounded-full border border-blue-300 bg-blue-50 px-3 py-1 text-xs text-blue-800" data-range-label></span><div class="ml-auto flex gap-2"><button class="toolbar-button" data-chart-toggle>▾ Grafiği göster</button><button class="toolbar-button" data-export>⇩ Excel</button><button class="toolbar-button" data-print>▧ Yazdır / PDF</button></div></div><div class="hidden shrink-0 border-b border-slate-300 bg-white" data-chart></div><div class="min-h-0 flex-1 overflow-auto bg-white" data-grid></div><div class="flex h-11 shrink-0 items-center gap-3 border-t border-slate-300 bg-slate-100 px-3 text-sm"><span data-summary class="whitespace-nowrap text-slate-500"></span><label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-600">Satır <select data-per-page class="rounded border border-slate-300 bg-white px-2 py-1 text-xs"><option value="50">50</option><option value="100">100</option><option value="250">250</option><option value="500">500</option><option value="1000">1000</option></select></label><div class="ml-auto flex items-center gap-2"><button class="toolbar-button h-8" data-prev>←</button><span data-page></span><button class="toolbar-button h-8" data-next>→</button></div></div></div>`;
    const s = win.state;
    const grouping = win.body.querySelector('[data-grouping]');
    const preset = win.body.querySelector('[data-preset]');
    const start = win.body.querySelector('[data-start]');
    const end = win.body.querySelector('[data-end]');
    grouping.value=s.grouping; preset.value=s.preset; start.value=s.start; end.value=s.end;
    const toggleCustom=()=>win.body.querySelectorAll('[data-custom-date]').forEach(item=>item.classList.toggle('hidden',preset.value!=='custom'));
    toggleCustom();
    grouping.onchange=()=>{s.grouping=grouping.value;s.page=1;scheduleSaveOpenWindows();loadProductionReport(win);};
    preset.onchange=()=>{s.preset=preset.value;s.page=1;toggleCustom();if(s.preset!=='custom'){s.start='';s.end='';loadProductionReport(win);}scheduleSaveOpenWindows();};
    const customChanged=()=>{s.start=start.value;s.end=end.value;if(s.start&&s.end){s.page=1;scheduleSaveOpenWindows();loadProductionReport(win);}};
    start.onchange=customChanged;end.onchange=customChanged;
    win.body.querySelector('[data-refresh]').onclick=()=>loadProductionReport(win);
    win.body.querySelector('[data-chart-toggle]').onclick=()=>{s.chartExpanded=!s.chartExpanded;updateBusinessChartVisibility(win,Boolean(win.body.querySelector('[data-chart]').innerHTML));scheduleSaveOpenWindows();};
    initializeOutputButtons(win);
    win.body.querySelector('[data-per-page]').value=String(s.perPage);
    win.body.querySelector('[data-per-page]').onchange=event=>{s.perPage=Number(event.target.value);s.page=1;scheduleSaveOpenWindows();loadProductionReport(win);};
    win.body.querySelector('[data-prev]').onclick=()=>{if(s.page>1){s.page--;scheduleSaveOpenWindows();loadProductionReport(win);}};
    win.body.querySelector('[data-next]').onclick=()=>{if(s.page<(s.pages||1)){s.page++;scheduleSaveOpenWindows();loadProductionReport(win);}};
    await loadProductionReport(win);
    return win;
  }

  async function loadProductionReport(win) {
    const grid=win.body.querySelector('[data-grid]');
    grid.innerHTML='<div class="p-8 text-center text-sm text-slate-500">Rapor hazırlanıyor…</div>';
    try {
      const s=win.state;
      const data=await productionApi(dataUrlForWindow(win,s.page,s.perPage));
      s.pages=data.pages;s.perPage=data.per_page;s.sort=data.sort;s.dir=data.dir;s.preset=data.range.preset;s.start=data.range.start;s.end=data.range.end;
      updateWindowTitle(win,`${data.title} — ${data.range.start} / ${data.range.end}`);
      win.body.querySelector('[data-range-label]').textContent=`${data.range.start} → ${data.range.end}`;
      win.body.querySelector('[data-start]').value=s.start;win.body.querySelector('[data-end]').value=s.end;
      const numericColumns=new Set(data.columns.filter(column=>(data.rows||[]).some(row=>typeof row[column]==='number')));
      const header=data.columns.map(column=>`<th data-sort="${escapeHtml(column)}" class="sticky top-0 cursor-pointer whitespace-nowrap border-b border-r border-slate-300 bg-slate-200 px-3 py-2 ${numericColumns.has(column)?'text-right':'text-left'} text-xs font-semibold text-slate-700 hover:bg-slate-300">${escapeHtml(column)}${data.sort===column?(data.dir==='asc'?' ↑':' ↓'):''}</th>`).join('');
      const rows=data.rows.map(row=>`<tr class="border-b border-slate-200 hover:bg-blue-50">${data.columns.map(column=>{const numeric=typeof row[column]==='number';const value=numeric?reportNumber(row[column]):String(row[column]??'');return `<td class="max-w-80 truncate whitespace-nowrap border-r border-slate-100 px-3 py-2 text-xs${numeric?' text-right tabular-nums':''}" title="${escapeHtml(value)}">${escapeHtml(value)}</td>`;}).join('')}</tr>`).join('');
      grid.innerHTML=`<table class="min-w-full border-collapse"><thead><tr>${header}</tr></thead><tbody>${rows||`<tr><td colspan="${data.columns.length}" class="p-10 text-center text-sm text-slate-500">Seçilen aralıkta veri bulunamadı.</td></tr>`}</tbody>${reportTotalsFooter(data)}</table>`;
      grid.querySelectorAll('th[data-sort]').forEach(th=>th.onclick=()=>{const name=th.dataset.sort;s.dir=s.sort===name&&s.dir==='asc'?'desc':'asc';s.sort=name;s.page=1;scheduleSaveOpenWindows();loadProductionReport(win);});
      const hasChart=renderBusinessChart(win.body.querySelector('[data-chart]'),data);
      updateBusinessChartVisibility(win,hasChart);
      const first=data.total===0?0:(data.page-1)*data.per_page+1,last=data.total===0?0:first+data.rows.length-1;
      win.body.querySelector('[data-summary]').textContent=`${first.toLocaleString('tr-TR')}–${last.toLocaleString('tr-TR')} / ${data.total.toLocaleString('tr-TR')} satır`;
      win.body.querySelector('[data-page]').textContent=`${data.page} / ${data.pages}`;
      win.body.querySelector('[data-per-page]').value=String(data.per_page);
      win.body.querySelector('[data-prev]').disabled=data.page<=1;win.body.querySelector('[data-next]').disabled=data.page>=data.pages;
    } catch(error){grid.innerHTML=`<div class="p-8 text-center text-sm text-red-700">${escapeHtml(error.message)}</div>`;updateBusinessChartVisibility(win,false);}
  }

  async function openProductionList(view = 'all', label = 'WebCin Projeleri', restoredState = null) {
    const win = createWindow(`production-list:${view}`, label, 1240, 720);
    if (win.loaded) return win;
    win.loaded = true;
    win.kind = 'production_list';
    win.state = {...{view, label, page: 1, perPage: 250, q: '', sort: 'ID', dir: 'desc'}, ...(restoredState || {}), view, label};
    scheduleSaveOpenWindows();
    win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex shrink-0 items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button" data-new>＋ Yeni</button><button class="toolbar-button" data-refresh>↻ Yenile</button><button class="toolbar-button" data-export>⇩ Excel</button><button class="toolbar-button" data-print>▧ Yazdır / PDF</button><div class="ml-auto relative"><input class="form-input w-80 pr-9" data-search placeholder="Kitap, yazar, e-posta, ISBN veya sorumlu ara…"><span class="absolute right-3 top-2 text-slate-400">⌕</span></div></div><div class="min-h-0 flex-1 overflow-auto bg-white" data-grid></div><div class="flex h-11 shrink-0 items-center gap-3 border-t border-slate-300 bg-slate-100 px-3 text-sm"><span data-summary class="whitespace-nowrap text-slate-500"></span><label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-600">Satır <select data-per-page class="rounded border border-slate-300 bg-white px-2 py-1 text-xs"><option>50</option><option>100</option><option selected>250</option><option>500</option><option>1000</option></select></label><span class="text-xs text-blue-700">E-posta → Cari Kart → Ürün bağlantısı</span><div class="ml-auto flex items-center gap-2"><button class="toolbar-button h-8" data-prev>←</button><span data-page></span><button class="toolbar-button h-8" data-next>→</button></div></div></div>`;
    const search = win.body.querySelector('[data-search]');
    search.value = win.state.q;
    let timer;
    search.oninput = () => { clearTimeout(timer); timer = setTimeout(() => { win.state.q = search.value; win.state.page = 1; scheduleSaveOpenWindows(); loadProductionList(win); }, 300); };
    win.body.querySelector('[data-new]').hidden = !permissions.has('production:new');
    win.body.querySelector('[data-new]').onclick = () => openProductionProject(null);
    win.body.querySelector('[data-refresh]').onclick = () => loadProductionList(win);
    initializeOutputButtons(win);
    win.body.querySelector('[data-per-page]').onchange = event => { win.state.perPage = Number(event.target.value); win.state.page = 1; scheduleSaveOpenWindows(); loadProductionList(win); };
    win.body.querySelector('[data-prev]').onclick = () => { if (win.state.page > 1) { win.state.page--; scheduleSaveOpenWindows(); loadProductionList(win); } };
    win.body.querySelector('[data-next]').onclick = () => { if (win.state.page < (win.state.pages || 1)) { win.state.page++; scheduleSaveOpenWindows(); loadProductionList(win); } };
    await loadProductionList(win);
    return win;
  }

  async function loadProductionList(win) {
    const grid = win.body.querySelector('[data-grid]');
    grid.innerHTML = '<div class="p-8 text-center text-sm text-slate-500">WebCin kayıtları yükleniyor…</div>';
    try {
      const s = win.state;
      const data = await productionApi(dataUrlForWindow(win, s.page, s.perPage));
      s.pages = data.pages; s.perPage = data.per_page;
      const money = moneyColumnSet(data);
      const sortable = new Set(data.sortable_columns || []);
      const header = data.columns.map(column => `<th ${sortable.has(column) ? `data-sort="${escapeHtml(column)}"` : ''} class="sticky top-0 whitespace-nowrap border-b border-r border-slate-300 bg-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 ${sortable.has(column) ? 'cursor-pointer hover:bg-slate-300' : ''} ${money.has(column) ? 'text-right' : 'text-left'}">${escapeHtml(column)}${data.sort === column ? (data.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('');
      const rows = data.rows.map((row, rowIndex) => `<tr data-production-id="${escapeHtml(row.ID)}" class="cursor-pointer border-b border-slate-200 hover:bg-blue-50"><td class="sticky left-0 z-10 w-4 border-r border-slate-200 bg-slate-50 px-2 py-1 text-xs"><button type="button" data-production-row-action="${rowIndex}" class="w-full select-none rounded border border-slate-300 bg-white px-2 py-1 text-center font-medium text-blue-700 hover:bg-blue-50">+</button></td>${data.columns.map(column => { const value = money.has(column) ? formatMoney(row[column], data.currency) : String(row[column] ?? ''); const state = (column === 'CariBaglantisi' || column === 'UrunBaglantisi') && Number(row[column]) === 0 ? ' text-red-700' : ''; return `<td class="max-w-72 truncate whitespace-nowrap border-r border-slate-100 px-3 py-2 text-xs${money.has(column) ? ' text-right tabular-nums' : ''}${state}" title="${escapeHtml(value)}">${escapeHtml(value)}</td>`; }).join('')}</tr>`).join('');
      grid.innerHTML = `<table class="min-w-full border-collapse"><thead><tr><th class="sticky left-0 top-0 z-30 border-b border-r border-slate-300 bg-slate-200 px-2 py-2 text-left text-xs font-semibold"></th>${header}</tr></thead><tbody>${rows || `<tr><td colspan="${data.columns.length + 1}" class="p-10 text-center text-sm text-slate-500">WebCin kaydı bulunamadı.</td></tr>`}</tbody></table>`;
      grid.querySelectorAll('[data-sort]').forEach(th => th.onclick = () => { const name = th.dataset.sort; s.dir = s.sort === name && s.dir === 'asc' ? 'desc' : 'asc'; s.sort = name; s.page = 1; scheduleSaveOpenWindows(); loadProductionList(win); });
      grid.querySelectorAll('[data-production-id]').forEach(row => row.ondblclick = () => openProductionProject(Number(row.dataset.productionId)));
      grid.querySelectorAll('[data-production-row-action]').forEach(trigger => trigger.onclick = event => {
        event.preventDefault(); event.stopPropagation();
        const row = data.rows[Number(trigger.dataset.productionRowAction)];
        const accounts = Array.isArray(row.__accounts) ? row.__accounts : [];
        const items = [
          {action:{label:'WebCin Kaydını Aç'}, primary:true},
          ...accounts.map(account => ({action:{label:accounts.length === 1 ? 'Cari Kartlar' : `Cari Kartlar — ${account.name || `#${account.ID}`}`}, accountId:Number(account.ID)})),
        ];
        openRelationMenu(trigger, items, item => {
          if (item.primary) openProductionProject(Number(row.ID));
          else if (item.accountId > 0) openEditor('adresdefteri', 'Cari Kartlar', item.accountId, null);
        });
      });
      grid.addEventListener('scroll', closeRelationMenu, {passive:true});
      const first = data.total ? (data.page - 1) * data.per_page + 1 : 0;
      const last = data.total ? first + data.rows.length - 1 : 0;
      win.body.querySelector('[data-summary]').textContent = `${first.toLocaleString('tr-TR')}–${last.toLocaleString('tr-TR')} / ${data.total.toLocaleString('tr-TR')} kayıt`;
      win.body.querySelector('[data-page]').textContent = `${data.page} / ${data.pages}`;
      win.body.querySelector('[data-per-page]').value = String(data.per_page);
      win.body.querySelector('[data-prev]').disabled = data.page <= 1;
      win.body.querySelector('[data-next]').disabled = data.page >= data.pages;
    } catch (error) { grid.innerHTML = `<div class="p-8 text-center text-sm text-red-700">${escapeHtml(error.message)}</div>`; }
  }

  function productionTransactionTable(type, rows, projectId, currency) {
    const label = type === 'expense' ? 'WebCin Maliyeti' : 'Alınan Ödemeler';
    const body = rows.map(row => `<tr data-production-transaction data-type="${type}" data-id="${escapeHtml(row.ID)}"><td class="p-1"><input type="date" data-date class="form-input" value="${escapeHtml(String(row.tarih || '').slice(0,10))}"></td><td class="p-1"><input data-description class="form-input" value="${escapeHtml(row.aciklama || '')}"></td><td class="p-1"><input type="number" step="0.01" data-amount class="form-input text-right" value="${escapeHtml(row.tutar || 0)}"></td><td class="p-1"><button type="button" class="toolbar-button h-8" data-save-transaction>Kaydet</button></td></tr>`).join('');
    return `<section class="rounded border border-slate-300 bg-white"><header class="flex items-center border-b border-slate-300 bg-slate-100 px-3 py-2"><strong>${label}</strong><span class="ml-auto text-xs text-slate-500">${rows.length.toLocaleString('tr-TR')} hareket · ${escapeHtml(currency)}</span></header><div class="overflow-auto"><table class="min-w-full text-xs"><thead><tr class="bg-slate-50"><th class="p-2 text-left">Tarih</th><th class="p-2 text-left">Açıklama</th><th class="p-2 text-right">Tutar</th><th></th></tr></thead><tbody>${body}<tr data-production-transaction data-type="${type}" data-id="0" class="border-t border-blue-200 bg-blue-50"><td class="p-1"><input type="date" data-date class="form-input" value="${new Date().toISOString().slice(0,10)}"></td><td class="p-1"><input data-description class="form-input" placeholder="Yeni hareket açıklaması"></td><td class="p-1"><input type="number" step="0.01" data-amount class="form-input text-right" value="0"></td><td class="p-1"><button type="button" class="toolbar-button h-8 bg-blue-600 text-white" data-save-transaction>Ekle</button></td></tr></tbody></table></div></section>`;
  }

  async function openProductionProject(id = null, restoredState = null) {
    id = id ? Number(id) : null;
    const key = id ? `production-project:${id}` : `production-project:new:${Date.now()}`;
    const win = createWindow(key, id ? `WebCin Kaydı #${id}` : 'Yeni WebCin Kaydı', 1180, 780, {relationship: Boolean(id)});
    if (win.loaded) return win;
    win.loaded = true; win.kind = 'production_project'; win.state = {...(restoredState || {}), id};
    scheduleSaveOpenWindows();
    await loadProductionProject(win);
    return win;
  }

  async function loadProductionProject(win) {
    win.body.innerHTML = '<div class="p-8 text-center text-sm text-slate-500">WebCin kaydı yükleniyor…</div>';
    try {
      const data = win.state.id ? await productionApi(`?action=detail&id=${win.state.id}`) : {...await productionApi('?action=metadata'), record: {}, expenses: [], payments: [], summary: {cost:0,payment:0,balance:0}, main_links: {accounts:[],products:[]}};
      const record = data.record || {};
      if (win.state.id) updateWindowTitle(win, `WebCin — ${record.KitabinAdi || 'Adsız kitap'} (#${win.state.id})`);
      const groups = Object.entries(data.groups || {}).map(([group, fields], index) => `<details class="rounded border border-slate-300 bg-white" ${index < 3 ? 'open' : ''}><summary class="cursor-pointer bg-slate-100 px-3 py-2 text-sm font-semibold">${escapeHtml(group)}</summary><div class="grid grid-cols-1 gap-3 p-3 md:grid-cols-2">${Object.entries(fields).map(([name, definition]) => productionInput(name, definition, record[name])).join('')}</div></details>`).join('');
      const accounts = (data.main_links?.accounts || []).map(account => `<div class="flex flex-wrap items-center gap-2 rounded border border-blue-200 bg-blue-50 p-2 text-xs"><strong>#${escapeHtml(account.ID)} — ${escapeHtml(account.name || 'Adsız cari')}</strong><span class="text-slate-500">${escapeHtml(account.eposta || '')}</span><button type="button" class="toolbar-button h-8 ml-auto" data-open-main-account="${escapeHtml(account.ID)}">Cariyi Aç</button><button type="button" class="toolbar-button h-8" data-open-main-products="${escapeHtml(account.ID)}" data-account-name="${escapeHtml(account.name || '')}">Ürünleri Aç</button><button type="button" class="toolbar-button h-8" data-open-author-sales="${escapeHtml(account.ID)}" data-account-name="${escapeHtml(account.name || '')}">Tüm Satışlar</button></div>`).join('');
      const products = (data.main_links?.products || []).map(product => `<tr class="border-t border-slate-200"><td class="p-2">#${escapeHtml(product.ID)}</td><td class="p-2">${escapeHtml(product.isbn || '')}</td><td class="p-2 font-medium">${escapeHtml(product.kitapadi || '')}</td><td class="p-2 text-right">${Number(product.metrics?.['Satış'] || 0).toLocaleString('tr-TR')}</td><td class="p-2 text-right">${Number(product.metrics?.['İade'] || 0).toLocaleString('tr-TR')}</td><td class="p-2 text-right">${Number(product.metrics?.['Yazara Verilen'] || 0).toLocaleString('tr-TR')}</td><td class="p-2"><button type="button" class="toolbar-button h-8" data-open-product-sales="${escapeHtml(product.ID)}" data-product-name="${escapeHtml(product.kitapadi || '')}">Satış Geçmişi</button></td></tr>`).join('');
      const placeholderEmail = String(record.YazarinEpostasi || '').trim().toLocaleLowerCase('tr-TR') === 'admin@ciniusyayinlari.com';
      const linksPanel = placeholderEmail ? '' : `<section class="rounded border border-blue-300 bg-white"><div class="space-y-2 p-2">${accounts || '<div class="rounded border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900">Bu e-posta ile eşleşen cari kart bulunamadı.</div>'}</div><div class="overflow-auto"><table class="min-w-full text-xs"><thead><tr class="bg-slate-100"><th class="p-2 text-left">ID</th><th class="p-2 text-left">ISBN</th><th class="p-2 text-left">Ürün</th><th class="p-2 text-right">Satış</th><th class="p-2 text-right">İade</th><th class="p-2 text-right">Yazara verilen</th><th></th></tr></thead><tbody>${products || '<tr><td colspan="7" class="p-5 text-center text-slate-500">Bağlı ürün bulunamadı.</td></tr>'}</tbody></table></div></section>`;
      const showFinancialSummary = [data.summary?.cost, data.summary?.payment, data.summary?.balance].some(value => Number(value || 0) !== 0);
      const financialSummary = showFinancialSummary ? `<div class="flex items-center gap-5 overflow-x-auto whitespace-nowrap rounded border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs"><span class="text-slate-600">WebCin maliyeti <strong class="ml-1 text-slate-900">${formatMoney(data.summary.cost,data.currency)}</strong></span><span class="text-emerald-700">Alınan ödeme <strong class="ml-1">${formatMoney(data.summary.payment,data.currency)}</strong></span><span class="text-amber-800">Kalan <strong class="ml-1">${formatMoney(data.summary.balance,data.currency)}</strong></span></div>` : '';
      const transactions = win.state.id ? `<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">${productionTransactionTable('expense', data.expenses || [], win.state.id, data.currency)}${productionTransactionTable('payment', data.payments || [], win.state.id, data.currency)}</div>` : '';
      win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex shrink-0 items-center gap-2 border-b border-slate-300 bg-slate-200 p-2"><button class="toolbar-button bg-blue-600 text-white" data-save-project>✓ Kaydet</button>${win.state.id ? '<button class="toolbar-button" data-refresh>↻ Yenile</button>' : ''}<span class="ml-auto text-xs text-slate-500">CUP veritabanı: ayrı bağlantı</span></div><div class="min-h-0 flex-1 space-y-4 overflow-auto p-4">${win.state.id ? `${financialSummary}${linksPanel}` : ''}<form data-production-form class="space-y-3">${groups}</form>${transactions}</div></div>`;
      const form = win.body.querySelector('[data-production-form]');
      form.addEventListener('input', () => win.dirty = true);
      win.body.querySelector('[data-save-project]').onclick = async () => {
        const recordPayload = Object.fromEntries(new FormData(form).entries());
        form.querySelectorAll('input[type="checkbox"][data-production-field]').forEach(input => { recordPayload[input.name] = input.checked ? 1 : 0; });
        if (win.state.id) recordPayload.ID = win.state.id;
        try {
          const saved = await productionApi('?action=save', {method:'POST',body:JSON.stringify({record:recordPayload})});
          win.dirty = false; toast(`WebCin kaydı #${saved.id} kaydedildi.`);
          [...windows.values()].filter(item => item.kind === 'production_list').forEach(loadProductionList);
          if (!win.state.id) { closeWindow(win); openProductionProject(saved.id); } else loadProductionProject(win);
        } catch (error) { toast(error.message,'error'); }
      };
      if (win.body.querySelector('[data-refresh]')) win.body.querySelector('[data-refresh]').onclick = () => loadProductionProject(win);
      win.body.querySelectorAll('[data-save-transaction]').forEach(button => button.onclick = async () => {
        const row = button.closest('[data-production-transaction]');
        try {
          await productionApi('?action=save_transaction',{method:'POST',body:JSON.stringify({type:row.dataset.type,record:{ID:Number(row.dataset.id),yazarID:win.state.id,tarih:row.querySelector('[data-date]').value,aciklama:row.querySelector('[data-description]').value,tutar:row.querySelector('[data-amount]').value}})});
          toast('WebCin hareketi kaydedildi.'); loadProductionProject(win);
        } catch (error) { toast(error.message,'error'); }
      });
      win.body.querySelectorAll('[data-open-main-account]').forEach(button => button.onclick = () => openEditor('adresdefteri','Cari Kartlar',Number(button.dataset.openMainAccount),null));
      win.body.querySelectorAll('[data-open-main-products]').forEach(button => button.onclick = () => openTable('asp_urun','Ürünler / Kitaplar',{column:'CinYazarID',value:Number(button.dataset.openMainProducts)},{title:button.dataset.accountName,lookup:{table:'adresdefteri',label:'FirmaAd',id:Number(button.dataset.openMainProducts)}}));
      win.body.querySelectorAll('[data-open-author-sales]').forEach(button => button.onclick = () => openReport('author_sales',Number(button.dataset.openAuthorSales),'Satış Geçmişi',{title:button.dataset.accountName,lookup:{table:'adresdefteri',label:'FirmaAd',id:Number(button.dataset.openAuthorSales)}}));
      win.body.querySelectorAll('[data-open-product-sales]').forEach(button => button.onclick = () => openReport('product_sales',Number(button.dataset.openProductSales),'Satış Geçmişi',{title:button.dataset.productName,lookup:{table:'asp_urun',label:'kitapadi',id:Number(button.dataset.openProductSales)}}));
    } catch (error) { win.body.innerHTML = `<div class="p-8 text-center text-red-700">${escapeHtml(error.message)}</div>`; }
  }

  async function resolveStoredRelationshipContext(titleLookup) {
    if (!titleLookup?.table || !titleLookup?.label || !titleLookup?.id) return null;
    try {
      const data = await api(`?action=lookup&table=${encodeURIComponent(titleLookup.table)}&label=${encodeURIComponent(titleLookup.label)}&id=${encodeURIComponent(titleLookup.id)}`);
      const item = data.options?.[0];
      return {title: item ? (item.label || `Adsız kayıt (#${titleLookup.id})`) : `Silinmiş kayıt (#${titleLookup.id})`, lookup: titleLookup};
    } catch (_) {
      return {title: `Kayıt #${titleLookup.id}`, lookup: titleLookup};
    }
  }

  async function restoreOpenWindows() {
    const unavailableTables = new Set(['adres_agac', 'adres_defter_agac_iliskileri', 'depolar', 'depobaslik', 'depodetay', 'faturadetay', 'kurlar', 'kurhareketleri', 'odemeler', 'odemefatura', 'adresdefteri2', 'faturabaski', 'gelir', 'gider', 'nakitakis', 'stoktablo']);
    const descriptors = storedWindowDescriptors().sort((a, b) => (Number(a.z) || 0) - (Number(b.z) || 0));
    if (descriptors.length === 0) return;
    restoringWindows = true;
    try {
      for (const descriptor of descriptors) {
        const s = descriptor.state;
        let permission = ({table:`table:${s.table}`, editor:`table:${s.table}`, invoice:'table:faturabaslik', report:`table:${s.report === 'product_sales' ? 'asp_urun' : 'adresdefteri'}`, business_report:`report:${s.report}`, settings:'settings:currency', production_list:`production:${s.view === 'balances' ? 'balances' : 'all'}`, production_report:`production:report:${s.report}`, production_project:s.id ? (permissions.has('production:all') ? 'production:all' : permissions.has('production:balances') ? 'production:balances' : 'production:new') : 'production:new'})[descriptor.kind];
        if (permission && !permissions.has(permission)) continue;
        if (typeof s.label === 'string') s.label = s.label.replaceAll('Üretim', 'WebCin');
        let win = null;
        if (descriptor.kind === 'table' && s.table && s.label) {
          if (unavailableTables.has(s.table)) continue;
          const context = await resolveStoredRelationshipContext(s.titleLookup);
          win = await openTable(s.table, s.label, s.filter || null, context, s);
        } else if (descriptor.kind === 'report' && s.report && s.id && s.label) {
          const context = await resolveStoredRelationshipContext(s.titleLookup);
          win = await openReport(s.report, s.id, s.label, context, s);
        } else if (descriptor.kind === 'business_report' && s.report && s.label) {
          win = await openBusinessReport(s.report, s.label, s);
        } else if (descriptor.kind === 'invoice' && s.id) {
          win = await openInvoice(s.id, s);
        } else if (descriptor.kind === 'editor' && s.table && s.label) {
          if (unavailableTables.has(s.table)) continue;
          win = await openEditor(s.table, s.label, s.id || null, s.parentKey ? windows.get(s.parentKey) || null : null, {key: descriptor.key, parentKey: s.parentKey || null});
        } else if (descriptor.kind === 'settings' && s.section === 'currency') {
          win = await openCurrencySettings(s);
        } else if (descriptor.kind === 'production_list') {
          win = await openProductionList(s.view || 'all', s.label || 'WebCin Projeleri', s);
        } else if (descriptor.kind === 'production_report' && s.report && s.label) {
          win = await openProductionReport(s.report, s.label, s);
        } else if (descriptor.kind === 'production_project') {
          win = await openProductionProject(s.id || null, s);
        }
        if (win) applyStoredWindowLayout(win, descriptor);
      }
    } finally {
      restoringWindows = false;
      saveOpenWindows();
    }
  }

  async function usersApi(payload) {
    const response = await fetch('users_api.php', {
      method: payload ? 'POST' : 'GET', cache: 'no-store',
      headers: {'Accept':'application/json', ...(payload ? {'Content-Type':'application/json', 'X-CSRF-Token':csrf} : {})},
      ...(payload ? {body:JSON.stringify(payload)} : {})
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Kullanıcı işlemi başarısız.');
    return data;
  }

  async function openUsers() {
    const win = createWindow('users', 'Kullanıcı Yönetimi', 850, 550);
    try {
      const data = await usersApi();
      win.body.innerHTML = `<div class="flex h-full flex-col"><div class="flex gap-2 border-b border-slate-300 p-2"><button class="toolbar-button" data-add-user>+ Yeni Kullanıcı</button><button class="toolbar-button" data-refresh-users>Yenile</button></div><div class="flex-1 overflow-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-200"><tr><th class="p-3">Kullanıcı Adı</th><th class="p-3">Rol</th><th class="p-3">Hesap</th></tr></thead><tbody>${data.users.map((user, i) => `<tr class="cursor-pointer border-b border-slate-200 hover:bg-blue-50" data-user-row="${i}"><td class="p-3"><button class="text-blue-700 underline">${escapeHtml(user.username)}</button></td><td class="p-3">${user.role === 'administrator' ? 'Yönetici' : 'Kullanıcı'}</td><td class="p-3">${user.protected ? 'Ana yönetici' : ''}</td></tr>`).join('')}</tbody></table></div></div>`;
      win.body.querySelector('[data-add-user]').onclick = () => openUserEditor(null, data.menus);
      win.body.querySelector('[data-refresh-users]').onclick = openUsers;
      win.body.querySelectorAll('[data-user-row]').forEach(row => row.onclick = () => openUserEditor(data.users[Number(row.dataset.userRow)], data.menus));
    } catch (error) { win.body.textContent = error.message; }
  }

  function openUserEditor(user, menus) {
    const key = `user:${user?.id || 'new'}`;
    if (windows.has(key)) { restoreWindow(windows.get(key)); return; }
    const win = createWindow(key, user ? `Kullanıcı — ${user.username}` : 'Yeni Kullanıcı', 700, 700);
    if (user?.id === 'environment') {
      win.body.innerHTML = '<p class="p-5 text-sm">Ana yönetici tüm menülere erişebilir ve normal kullanıcı yapılamaz. Kullanıcı adı ve parola sunucudaki .env dosyasından yönetilir.</p>';
      return;
    }
    const selected = new Set(user?.permissions || []);
    const checkbox = (key, label) => `<label class="flex items-center gap-2 py-1 text-sm"><input type="checkbox" name="permissions" value="${escapeHtml(key)}" ${selected.has(key) ? 'checked' : ''}>${escapeHtml(label)}</label>`;
    win.body.innerHTML = `<form class="flex h-full flex-col" data-user-form><div class="flex-1 space-y-4 overflow-auto p-4"><label class="block text-sm">Kullanıcı Adı<input class="mt-1 w-full rounded border border-slate-300 p-2" name="username" required minlength="3" maxlength="80" autocomplete="off" value="${escapeHtml(user?.username || '')}"></label><label class="block text-sm">${user ? 'Yeni Parola (değiştirmemek için boş bırakın)' : 'Parola'}<input class="mt-1 w-full rounded border border-slate-300 p-2" name="password" type="password" minlength="12" ${user ? '' : 'required'} autocomplete="new-password"></label><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="admin" ${user?.role === 'administrator' ? 'checked' : ''} ${user?.protected ? 'disabled' : ''}>Yönetici${user?.protected ? ' (ana yönetici, değiştirilemez)' : ''}</label><p class="text-xs text-slate-500">Yöneticiler tüm menüleri görür. Normal kullanıcılar için ana menüyü ve erişilecek alt menüleri seçin.</p><div data-permission-fields>${Object.entries(menus).map(([group, items]) => {
      const available = Object.entries(items).filter(([, item]) => !item.admin);
      if (!available.length) return '';
      return `<fieldset class="mb-3 rounded border border-slate-300 p-3"><legend class="px-1 font-semibold">${escapeHtml(group)}</legend>${checkbox(`menu:${group}`, `${group} menüsünü göster`)}<div class="ml-4" data-menu-children>${available.map(([key, item]) => checkbox(key, `${item.subgroup ? item.subgroup + ' / ' : ''}${item.label}`)).join('')}</div></fieldset>`;
    }).join('')}</div><p class="text-sm text-red-700" data-user-error role="alert"></p></div><div class="border-t border-slate-300 p-3"><button class="toolbar-button" type="submit">Kaydet</button></div></form>`;
    const form = win.body.querySelector('form');
    const admin = form.elements.admin;
    const updatePermissions = () => {
      form.querySelector('[data-permission-fields]').hidden = admin.checked;
      form.querySelectorAll('fieldset').forEach(fieldset => {
        const parent = fieldset.querySelector('input');
        fieldset.querySelectorAll('[data-menu-children] input').forEach(input => { input.disabled = !parent.checked; });
      });
    };
    form.oninput = () => { win.dirty = true; updatePermissions(); };
    updatePermissions();
    form.onsubmit = async event => {
      event.preventDefault();
      const button = form.querySelector('[type="submit"]');
      button.disabled = true;
      try {
        await usersApi({id:user?.id || '', username:form.elements.username.value, password:form.elements.password.value, role:admin.checked ? 'administrator' : 'user', permissions:[...form.querySelectorAll('[name="permissions"]:checked')].filter(input => !input.disabled).map(input => input.value)});
        win.dirty = false; closeWindow(win); toast('Kullanıcı kaydedildi.');
        // Refresh navigation when editing the currently signed-in account.
        if (String(user?.id) === desktop.dataset.userId) location.reload();
        else await openUsers();
      } catch (error) { form.querySelector('[data-user-error]').textContent = error.message; }
      finally { button.disabled = false; }
    };
  }

  document.querySelectorAll('[data-open-users]').forEach(button => button.onclick = openUsers);

  document.querySelectorAll('[data-open-table]').forEach(button => button.onclick = () => openTable(button.dataset.openTable, button.dataset.label));
  document.querySelectorAll('[data-open-settings]').forEach(button => button.onclick = event => { event.stopPropagation(); if (button.dataset.openSettings === 'currency') openCurrencySettings(); document.querySelectorAll('.menu-panel').forEach(panel => panel.classList.remove('!visible','!opacity-100')); });
  document.querySelectorAll('[data-open-business-report]').forEach(button => button.onclick = event => { event.stopPropagation(); openBusinessReport(button.dataset.openBusinessReport, button.dataset.label); document.querySelectorAll('.menu-panel').forEach(panel => panel.classList.remove('!visible','!opacity-100')); });
  document.querySelectorAll('[data-open-production-list]').forEach(button => button.onclick = event => { event.stopPropagation(); openProductionList(button.dataset.openProductionList, button.dataset.label); document.querySelectorAll('.menu-panel').forEach(panel => panel.classList.remove('!visible','!opacity-100')); });
  document.querySelectorAll('[data-open-production-new]').forEach(button => button.onclick = event => { event.stopPropagation(); openProductionProject(null); document.querySelectorAll('.menu-panel').forEach(panel => panel.classList.remove('!visible','!opacity-100')); });
  document.querySelectorAll('[data-open-production-balances]').forEach(button => button.onclick = event => { event.stopPropagation(); openProductionList('balances', 'WebCin Bakiyeleri'); document.querySelectorAll('.menu-panel').forEach(panel => panel.classList.remove('!visible','!opacity-100')); });
  document.querySelectorAll('[data-open-production-report]').forEach(button => button.onclick = event => { event.stopPropagation(); openProductionReport(button.dataset.openProductionReport, button.dataset.label); document.querySelectorAll('.menu-panel').forEach(panel => panel.classList.remove('!visible','!opacity-100')); });
  document.querySelectorAll('nav > .group > button').forEach(trigger => {
    trigger.addEventListener('click', event => {
      event.stopPropagation();
      const panel = trigger.parentElement.querySelector('.menu-panel');
      document.querySelectorAll('.menu-panel').forEach(item => { if (item !== panel) item.classList.remove('!visible', '!opacity-100'); });
      panel?.classList.toggle('!visible'); panel?.classList.toggle('!opacity-100');
    });
  });
  document.addEventListener('click', () => document.querySelectorAll('.menu-panel').forEach(panel => panel.classList.remove('!visible', '!opacity-100')));
  window.addEventListener('resize', () => windows.forEach(win => { if (win.maximized) fitWindowInsideDesktop(win); }));
  document.querySelectorAll('[data-window-command]').forEach(button => button.onclick = () => {
    if (button.dataset.windowCommand === 'close-all') {
      if ([...windows.values()].some(win => win.dirty) && !confirm('Kaydedilmemiş değişiklikler kaybolacak. Tüm pencereler kapatılsın mı?')) return;
      [...windows.values()].forEach(win => { win.dirty = false; closeWindow(win); });
      return;
    }
    const visible = [...windows.values()].filter(win => !win.minimized);
    if (button.dataset.windowCommand === 'minimize-all') return visible.forEach(minimizeWindow);
    if (button.dataset.windowCommand === 'cascade') visible.forEach((win, index) => { if (win.maximized) maximizeWindow(win); Object.assign(win.el.style, {left: `${20 + index * 28}px`, top: `${16 + index * 25}px`, width: `${Math.min(900, desktop.clientWidth - 100)}px`, height: `${Math.min(580, desktop.clientHeight - 90)}px`}); fitWindowInsideDesktop(win); activateWindow(win); });
    if (button.dataset.windowCommand === 'tile' && visible.length) { const cols = Math.ceil(Math.sqrt(visible.length)), rows = Math.ceil(visible.length / cols), width = desktop.clientWidth / cols, height = desktop.clientHeight / rows; visible.forEach((win, index) => { win.maximized = false; Object.assign(win.el.style, {left: `${(index % cols) * width}px`, top: `${Math.floor(index / cols) * height}px`, width: `${width}px`, height: `${height}px`}); }); scheduleSaveOpenWindows(); }
  });
  window.addEventListener('beforeunload', saveOpenWindows);
  restoreOpenWindows();
  const updateClock = () => { document.getElementById('desktop-clock').textContent = new Intl.DateTimeFormat('tr-TR', {dateStyle: 'short', timeStyle: 'medium'}).format(new Date()); };
  updateClock(); setInterval(updateClock, 1000);
})();
