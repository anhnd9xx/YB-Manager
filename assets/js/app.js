// ============ STATE ============
let profiles = [];
let proxies = [];
let logs = [];
let settings = {};
let pendingProxyAssignProfileId = null;
let pendingProxyAssignProfileIds = null;
let selectedProfileIds = new Set();
let profilesPage = 1;
let profilesPerPage = storedPerPage('ytm-perpage-profiles', 10);
let profilesFiltered = [];
let proxyPage = 1;
let proxyPerPage = storedPerPage('ytm-perpage-proxies', 10);
let selectedProxies = new Set();

const $ = (id) => document.getElementById(id);
function storedPerPage(key, fb) {
  try {
    const v = parseInt(localStorage.getItem(key), 10);
    if ([5, 10, 20, 30, 50, 100].includes(v)) return v;
  } catch (e) {}
  return fb;
}
const api = 'api/';
const VIEW_TITLES = {
  dashboard: 'Tổng quan', profiles: 'Kênh', monitoring: 'Thống kê & Theo dõi', proxies: 'Proxy',
  synchronize: 'Synchronize', logs: 'Nhật ký', settings: 'Cài đặt'
};

// ============ INIT ============
let autoRefreshTimer = null;
document.addEventListener('DOMContentLoaded', () => {
  initNav();
  initTopbar();
  initTheme();
  checkServer();
  loadSettings(); // trong loadSettings se goi applyAutoRefresh() theo setting
  refreshAll();
});

// Bat/tat refresh dinh ky theo cau hinh auto_refresh (Settings)
function applyAutoRefresh() {
  if (autoRefreshTimer) { clearInterval(autoRefreshTimer); autoRefreshTimer = null; }
  if (settings && settings.auto_refresh !== false) {
    autoRefreshTimer = setInterval(() => { loadProfiles(); if (document.getElementById('view-synchronize').classList.contains('active')) { loadSyn(false); synLoadDebug(); } }, 15000);
  }
}

function initTopbar() {
  const btnRefresh = $('btn-refresh');
  if (btnRefresh) btnRefresh.addEventListener('click', () => refreshAll(true));
  const btnMenu = $('btn-menu');
  if (btnMenu) btnMenu.addEventListener('click', toggleSidebar);
  // đóng sidebar khi bấm ra ngoài (mobile)
  document.addEventListener('click', (e) => {
    const sb = $('sidebar'), menu = $('btn-menu');
    if (sb && menu && document.body.classList.contains('sidebar-open') &&
        !sb.contains(e.target) && !menu.contains(e.target)) {
      document.body.classList.remove('sidebar-open');
    }
  });
}

async function refreshAll(showToast) {
  const btn = $('btn-refresh');
  if (btn) btn.classList.add('loading');
  try {
    await Promise.allSettled([loadProfiles(), loadProxies(), loadLogs()]);
    if (showToast) toast('Đã làm mới', 'success');
  } catch (e) {
    if (showToast) toast('Lỗi khi làm mới', 'error');
  } finally {
    setTimeout(() => { if (btn) btn.classList.remove('loading'); }, 500);
  }
}

function toggleSidebar() {
  document.body.classList.toggle('sidebar-open');
}

// ============ THEME (sáng / tối) ============
const THEME_KEY = 'ytm-theme';
function initTheme() {
  const saved = localStorage.getItem(THEME_KEY) || 'dark';
  applyTheme(saved);
  const btn = $('btn-theme');
  if (btn) btn.addEventListener('click', toggleTheme);
}
function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const btn = $('btn-theme');
  if (btn) btn.textContent = theme === 'dark' ? '🌙' : '☀️';
}
function toggleTheme() {
  const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  localStorage.setItem(THEME_KEY, next);
  applyTheme(next);
}

// ============ NAVIGATION ============
function initNav() {
  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.addEventListener('click', () => switchView(btn.dataset.view));
  });
}
function switchView(view) {
  document.querySelectorAll('.nav-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));
  document.querySelectorAll('.view').forEach(v => v.classList.toggle('active', v.id === 'view-' + view));
  $('view-title').textContent = VIEW_TITLES[view] || view;
  if (view === 'dashboard') renderDashboard();
  if (view === 'monitoring' && typeof monRefresh === 'function') monRefresh();
  if (view === 'logs') loadLogs();
  if (view === 'synchronize') { loadSyn(true); synLoadDebug(); synLoadLogs(); }
  if (view === 'settings') loadSettings();
  if (view === 'proxies') loadProxies();
  // đóng sidebar trên mobile nếu đang mở
  if (document.body.classList.contains('sidebar-open')) document.body.classList.remove('sidebar-open');
}

// ============ TOAST ============
function toast(msg, type = '') {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'toast ' + type;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.add('hidden'), 3200);
}

// ============ SERVER CHECK ============
async function checkServer() {
  try {
    const res = await fetch(api + 'proxies.php?action=list');
    if (res.ok) {
      $('server-status').textContent = 'Máy chủ hoạt động';
      $('server-status').className = 'badge badge-ok';
    } else {
      throw new Error('fail');
    }
  } catch (e) {
    $('server-status').textContent = 'Lỗi máy chủ';
    $('server-status').className = 'badge badge-err';
  }
}

// ============ HTTP HELPERS ============
async function getJson(url) {
  const res = await fetch(url);
  return res.json();
}
async function sendJson(url, data) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  });
  return res.json();
}
async function del(url) {
  const res = await fetch(url, { method: 'DELETE' });
  return res.json();
}
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ============ PROFILES ============
async function loadProfiles() {
  return fetch(api + 'profiles.php?action=list')
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        profiles = res.data;
        renderProfiles();
        renderDashboard();
        trackReflow(); // Phase 12: theo doi thay doi so luong running
      }
    })
    .catch(() => {});
}

// ============ PHASE 12: Smart Reflow (spec muc 29-30) ============
// Theo doi so Chrome running; doi on dinh qua 2 lan quan sat (~15-30s, debounce)
// roi OFF: thoi | ASK: hoi dialog | AUTO: arrange ngay.
// Khong reflow khi: bulk launch, arrange dang chay, modal mo, sync RUNNING,
// vua arrange <60s (tranh arrange chong).
let reflowBaseline = null;
let reflowPending = null; // {count, ticks}
function markProfileChanged() {
  // Mo/dong/xoa trong app: lan quan sat KE TIEP tu nhan baseline moi, KHONG hoi
  // reflow. Dung co thay vi dem 60s giay (Chrome bop setInterval khi tab nen nen
  // tick den muon, dem gio khong con tac dung). 60s ben duoi chi la backup.
  window.__ytmLastProfileChange = Date.now();
  window.__ytmAdoptNext = true;
  reflowPending = null;
}
function trackReflow() {
  try {
    const n = profiles.filter(p => p.status === 'running').length;
    if (!settings || settings.layout_reflow === 'off') {
      reflowBaseline = n;
      reflowPending = null;
      return;
    }
    if (reflowBaseline === null) { reflowBaseline = n; return; } // baseline lan dau, khong hoi
    // Thao tac trong app: nhan baseline o lan quan sat ke tiep (mien nhiem throttle timer)
    if (window.__ytmAdoptNext) {
      window.__ytmAdoptNext = false;
      reflowBaseline = n;
      reflowPending = null;
      return;
    }
    // Backup: thao tac mo/dong trong app <60s: nhan baseline moi im lang (Phase 9 da lo arrange)
    if (Date.now() - (window.__ytmLastProfileChange || 0) < 60000) {
      reflowBaseline = n;
      reflowPending = null;
      return;
    }
    if (n === reflowBaseline) { reflowPending = null; return; }
    if (!reflowPending || reflowPending.count !== n) { reflowPending = { count: n, ticks: 1 }; return; }
    reflowPending.ticks++;
    if (reflowPending.ticks < 2) return; // debounce: cho on dinh
    const target = reflowPending.count;
    reflowPending = null;
    if (target === 0) { reflowBaseline = target; return; } // dong het -> chi cap nhat baseline
    if (window.__ytmBulkOp || window.__ytmArranging) return; // giu baseline cu, thu lai vong sau
    if (Date.now() - (window.__ytmLastArrange || 0) < 60000) { reflowBaseline = target; return; }
    if (document.querySelector('.modal-overlay:not(.hidden)')) return;
    const syncing = document.getElementById('view-synchronize')
      && document.getElementById('view-synchronize').classList.contains('active')
      && synSession && synSession.state === 'RUNNING';
    if (syncing) return;
    reflowBaseline = target;
    if (settings.layout_reflow === 'auto') {
      arrangeCall({});
    } else { // ask (default)
      const okBtn = $('confirm-ok-btn');
      if (okBtn) okBtn.textContent = 'Xếp ngay';
      confirmDelete(`Đang chạy ${target} cửa sổ Chrome.<br><small>Xếp lại cửa sổ ngay?</small>`, async () => {
        await arrangeCall({});
      });
    }
  } catch (e) {
    console.error(e);
  }
}

function renderProfiles() {
  const q = ($('profile-search').value || '').toLowerCase();
  const platform = $('profile-filter-platform').value;
  const fstage = $('profile-filter-stage') ? $('profile-filter-stage').value : '';
  const fchannel = $('profile-filter-channel') ? $('profile-filter-channel').value : '';
  const fdays = $('profile-filter-days') && $('profile-filter-days').value !== '' ? parseInt($('profile-filter-days').value, 10) : 0;
  const fstab = $('profile-filter-stab') ? parseInt($('profile-filter-stab').value || '0', 10) : 0;
  const fconf = $('profile-filter-conf') ? parseInt($('profile-filter-conf').value || '0', 10) : 0;
  const feval = $('profile-filter-eval') ? $('profile-filter-eval').value : '';
  profilesFiltered = profiles.filter(p => {
    if (platform && p.platform !== platform) return false;
    if (fstage && (p.acc_stage || 'NEW') !== fstage) return false;
    if (feval && (p.eval_status || 'UNCHECKED') !== feval) return false;
    if (fchannel === 'exists' && p.acc_channel !== 'exists') return false;
    if (fchannel === 'none' && p.acc_channel !== 'none') return false;
    if (fchannel === 'unknown' && (p.acc_channel === 'exists' || p.acc_channel === 'none')) return false;
    // Filter theo summary (§55): dung auth/presence/account_channel_state
    if (fchannel === 'signed_in' && p.auth_status !== 'LOGGED_IN') return false;
    if (fchannel === 'signed_out' && p.auth_status !== 'LOGIN_REQUIRED' && p.auth_status !== 'LOGGED_OUT') return false;
    if (fchannel === 'ready' && (p.account_channel_state || evalSummary(p).state) !== 'SIGNED_IN_NO_CHANNEL') return false;
    if (fchannel === 'recheck' && !p.needs_recheck) return false;
    if (fdays > 0 && (p.acc_days == null || p.acc_days < fdays)) return false;
    if (fstab > 0 && (p.acc_stability == null || p.acc_stability < fstab)) return false;
    if (fconf > 0 && (p.acc_confidence == null || p.acc_confidence < fconf)) return false;
    if (!q) return true;
    return (p.name || '').toLowerCase().includes(q) || (p.channel_handle || '').toLowerCase().includes(q);
  });

  // nav count
  $('nav-profile-count').textContent = profiles.length;
  $('nav-profile-count').classList.toggle('hidden', profiles.length === 0);

  // reset page nếu ngoài phạm vi
  const totalPages = Math.max(1, Math.ceil(profilesFiltered.length / profilesPerPage));
  if (profilesPage > totalPages) profilesPage = totalPages;
  if (profilesPage < 1) profilesPage = 1;

  // chọn tất cả theo trang current
  const pageItems = paginateProfiles();
  const pageIds = new Set(pageItems.map(p => Number(p.id)));
  const pageAllSelected = pageItems.length > 0 && pageItems.every(p => selectedProfileIds.has(Number(p.id)));
  const selAllEl = $('sel-all');
  if (selAllEl) { selAllEl.checked = pageAllSelected; selAllEl.indeterminate = !pageAllSelected && pageItems.some(p => selectedProfileIds.has(Number(p.id))); }
  updateSelectedCount();

  // summary: tổng số kênh hiện có
  const sumEl = $('profile-summary');
  if (sumEl) {
    sumEl.textContent = profiles.length > 0
      ? `Tổng ${profiles.length} kênh · đang xem ${pageItems.length}`
      : '';
  }

  const grid = $('profiles-grid');
  if (!profilesFiltered.length) {
    grid.innerHTML = profiles.length
      ? '<div class="empty-state">Không tìm thấy kênh nào khớp bộ lọc.</div>'
      : '<div class="empty-state">Chưa có kênh nào. Bấm <strong>Tạo kênh</strong> để bắt đầu — mỗi kênh là 1 profile Chrome riêng với proxy riêng.</div>';
    renderPagination(0);
    return;
  }

  grid.innerHTML = pageItems.map(p => {
    const avatar = (p.name || '?').trim()[0].toUpperCase();
    const plat = p.platform || 'youtube';
    const st = liveProfileStatus(p);
    const proxyInfo = buildProxyRow(p);
    const tabInfo = p.debug_port
      ? `<div class="meta-row"><span class="meta-label">Debug port</span><span class="meta-value mono">${p.debug_port}</span></div>`
      : '';
    const sessInfo = `<div class="meta-row"><span class="meta-label">Tabs</span><span class="meta-value"><button class="btn btn-xs" onclick="openTabsPanel(${p.id})" title="Xem/lưu/khôi phục tabs">${p.tab_count_saved || 0} tabs · Xem</button></span></div>`;
    const accInfo = buildAccountRow(p);
    const checked = selectedProfileIds.has(Number(p.id));
    return `
      <div class="profile-card ${checked ? 'card-selected' : ''}">
        <div class="card-check">
          ${ckHtml(checked, `onchange="toggleProfileSelect(${p.id}, this.checked)"`, true)}
        </div>
        <div class="card-top">
          <div class="avatar platform-${plat}">${avatar}</div>
          <div style="min-width:0">
            <div class="card-name" title="Nhấp để đổi tên" onclick="renameProfile(${p.id})">${escapeHtml(p.name)}</div>
            <div class="card-handle" title="Nhấp để sửa handle" onclick="editHandle(${p.id})">${escapeHtml(p.channel_handle || 'Chưa có handle')}</div>
          </div>
          <div class="card-status"><span class="status-dot status-${st.state}"><i></i>${st.label}</span></div>
        </div>
        <div style="margin-top:8px"><span class="platform-chip platform-${plat}">${platLabel(plat)}</span></div>
        <div class="card-meta">
          ${proxyInfo}
          ${tabInfo}
          ${sessInfo}
          ${accInfo}
        </div>
        <div class="card-actions">
          ${st.state === 'busy'
            ? `<button class="btn btn-sm" disabled>⏳</button>`
            : `<button class="btn btn-sm btn-primary" onclick="openProfile(${p.id})">▶ Mở</button>`}
          ${st.state === 'running' ? `<button class="btn btn-sm btn-danger" onclick="closeProfile(${p.id})">■ Đóng</button>` : ''}
          <button class="btn btn-sm" title="Chạy đánh giá account cho kênh này" onclick="evaluateOneProfile(${p.id})">✓ Kiểm tra</button>
          <button class="btn btn-sm" title="Chỉnh sửa kênh" onclick="event.stopPropagation();openEditChannel(${p.id})">⚙</button>
          <button class="btn btn-sm btn-danger" onclick="deleteProfile(${p.id})">🗑</button>
        </div>
      </div>`;
  }).join('');

  renderPagination(profilesFiltered.length);
}

function paginateProfiles() {
  const start = (profilesPage - 1) * profilesPerPage;
  return profilesFiltered.slice(start, start + profilesPerPage);
}

// ============ PAGINATION BAR DUNG CHUNG (kenh + proxy) ============
// 3 vung: LEFT info | CENTER nut trang | RIGHT per-page. Khong reload UI,
// chi render lai danh sach + bar (filter/search giu nguyen, paginate sau filter).
function pgRange(page, totalPages) {
  const keep = new Set();
  for (let p = 1; p <= totalPages; p++) {
    if (p === 1 || p === totalPages || Math.abs(p - page) <= 2) keep.add(p);
  }
  const arr = [...keep].sort((a, b) => a - b);
  const out = [];
  arr.forEach((p, i) => {
    if (i > 0 && p - arr[i - 1] > 1) out.push('…');
    out.push(p);
  });
  return out;
}
// Doi per-page giu vi tri du lieu (§7): newPage = floor(firstItem/newPer)+1
function pgKeepPosition(page, oldPer, newPer, total) {
  const firstItem = (Math.max(1, page) - 1) * Math.max(1, oldPer);
  const tp = Math.max(1, Math.ceil(total / Math.max(1, newPer)));
  return Math.max(1, Math.min(tp, Math.floor(firstItem / Math.max(1, newPer)) + 1));
}
function pgBarHTML(total, page, perPage, o) {
  // o: {unit:'kênh', onpage:'goPage', onperpage:'setPerPage'}
  const tp = Math.max(1, Math.ceil(total / Math.max(1, perPage)));
  page = Math.max(1, Math.min(page, tp));
  const start = total === 0 ? 0 : (page - 1) * perPage + 1;
  const end = Math.min(page * perPage, total);
  const info = total === 0 ? `0 ${o.unit}` : `Hiển thị ${start}–${end} / ${total} ${o.unit}`;
  let center = `<button class="pg-btn pg-prev" title="Trang trước" ${page <= 1 ? 'disabled' : ''} onclick="${o.onpage}(${page - 1})">‹</button>`;
  if (tp > 1) {
    center += pgRange(page, tp).map(p =>
      p === '…' ? `<span class="pg-dots">…</span>`
        : `<button class="pg-btn${p === page ? ' active' : ''}" onclick="${o.onpage}(${p})">${p}</button>`
    ).join('');
  }
  center += `<button class="pg-btn pg-next" title="Trang sau" ${page >= tp ? 'disabled' : ''} onclick="${o.onpage}(${page + 1})">›</button>`;
  const opts = [5, 10, 20, 30, 50, 100].map(n =>
    `<option value="${n}" ${n === perPage ? 'selected' : ''}>${n}</option>`).join('');
  return `<div class="pgbar"><div class="pg-left">${info}</div>`
    + `<div class="pg-center">${center}</div>`
    + `<div class="pg-right"><span>Mỗi trang</span><select class="pg-perpage" onchange="${o.onperpage}(this.value)">${opts}</select></div></div>`;
}

function renderPagination(total) {
  const el = $('profiles-pagination');
  if (!el) return;
  el.innerHTML = pgBarHTML(total, profilesPage, profilesPerPage,
    { unit: 'kênh', onpage: 'goPage', onperpage: 'setPerPage' });
}

function goPage(n) {
  const tp = Math.max(1, Math.ceil(profilesFiltered.length / profilesPerPage));
  profilesPage = Math.max(1, Math.min(n, tp));
  renderProfiles();
}
function setPerPage(val) {
  const np = [5, 10, 20, 30, 50, 100].includes(parseInt(val, 10)) ? parseInt(val, 10) : 10;
  profilesPage = pgKeepPosition(profilesPage, profilesPerPage, np, profilesFiltered.length);
  profilesPerPage = np;
  try { localStorage.setItem('ytm-perpage-profiles', String(np)); } catch (e) {}
  renderProfiles();
}
function reloadProfilesView() {
  profilesPage = 1;
  renderProfiles();
}

// ============ SELECT (chọn nhiều kênh) ============
// Chuan hoa Number het (id tu JSON co the la string, inline onclick truyen number)
function toggleProfileSelect(id, checked) {
  id = Number(id);
  if (checked) selectedProfileIds.add(id);
  else selectedProfileIds.delete(id);
  renderProfiles();
}
function toggleSelectAll(checked) {
  const pageItems = paginateProfiles();
  pageItems.forEach(p => { checked ? selectedProfileIds.add(Number(p.id)) : selectedProfileIds.delete(Number(p.id)); });
  renderProfiles();
}
function getSelectedIds() {
  const ids = Array.from(selectedProfileIds);
  selectedProfileIds.clear();
  renderProfiles();
  return ids;
}
function updateSelectedCount() {
  const ids = $('selected-count');
  if (!ids) return;
  const eb0 = $('btn-eval-bulk');
  if (!selectedProfileIds.size) {
    ids.innerHTML = '';
    if (eb0 && !evalRunning) eb0.innerHTML = '✓ Đánh giá';
    return;
  }
  ids.innerHTML = `<span class="sel-pill">Đã chọn ${selectedProfileIds.size}<button class="sel-clear" title="Bỏ chọn hết" onclick="clearProfileSelection()">×</button></span>`;
  const eb = $('btn-eval-bulk');
  if (eb && !evalRunning) eb.innerHTML = `✓ Đánh giá ${selectedProfileIds.size}`;
}
function clearProfileSelection() {
  selectedProfileIds.clear();
  renderProfiles();
}
// Checkbox custom dung chung (dep + dong nhat Kênh/Proxy/Synchronize)
function ckHtml(checked, extra = '', lg = false) {
  return `<label class="ck${lg ? ' lg' : ''}"><input type="checkbox" ${checked ? 'checked' : ''} ${extra}><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></label>`;
}

// ============ ARRANGE (Smart Auto Arrange) ============
function openArrangeDrawer() {
  const m = $('arrange-menu');
  const ov = $('arrange-overlay');
  if (!m) return false;
  if (ov) ov.classList.remove('hidden');
  m.classList.remove('hidden');
  arrEnsureCfg();
  arrFillMonitors();
  arrPaint();
  return false;
}
function closeArrangeDrawer() {
  const m = $('arrange-menu');
  const ov = $('arrange-overlay');
  if (m) m.classList.add('hidden');
  if (ov) ov.classList.add('hidden');
}
// Can dropdown/popover theo viewport: thieu cho duoi -> mo len, sat mep phai -> lech trai
function placeDropdown(menu) {
  if (!menu) return;
  menu.classList.remove('flip-up', 'align-right');
  const r = menu.getBoundingClientRect();
  if (r.bottom > window.innerHeight - 8) menu.classList.add('flip-up');
  if (r.right > window.innerWidth - 8) menu.classList.add('align-right');
}
function toggleFilterPanel(force) {
  const p = $('filter-panel');
  if (!p) return;
  const show = force === true ? true : force === false ? false : p.classList.contains('hidden') || !p.classList.contains('open');
  p.classList.toggle('open', show);
}
function resetFilters() {
  ['profile-filter-platform', 'profile-filter-stage', 'profile-filter-eval', 'profile-filter-channel', 'profile-filter-stab', 'profile-filter-conf'].forEach(id => {
    const el = $(id);
    if (el) el.value = '';
  });
  const d = $('profile-filter-days');
  if (d) d.value = '';
  reloadProfilesView();
}
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeArrangeDrawer();
  }
});
function arrangeResultMsg(msg, ok) {
  const el = $('arrange-result');
  if (el) {
    el.textContent = msg;
    el.classList.toggle('win-result-ok', ok === true);
    el.classList.toggle('win-result-err', ok === false);
  }
}
async function arrangeCall(payload) {
  closeArrangeDrawer();
  arrangeResultMsg('⏳ Đang xếp cửa sổ...', null);
  window.__ytmArranging = true;
  try {
    const res = await sendJson(api + 'syncwin.php?action=arrange', payload);
    if (res.disconnectAsk) {
      // Monitor da chon bi rut: hoi user (Later / Arrange sang monitors con lai)
      const okBtn = $('confirm-ok-btn');
      if (okBtn) okBtn.textContent = 'Xếp ngay';
      arrangeResultMsg('⚠ ' + (res.message || 'Mất màn hình'), false);
      confirmDelete(`Màn hình ${(res.missingMonitors || []).join(', ')} không còn.<br><small>Xếp sang màn hình còn lại?</small>`, async () => {
        await arrangeCall({ ...payload, confirmed: true });
      });
    } else {
      const ok = !!(res.ok || res.partial);
      if (ok) window.__ytmLastArrange = Date.now();
      arrangeResultMsg('✓ ' + (res.message || 'Xong') + (res.partial ? ' (một phần)' : ''), ok);
      toast(res.message || 'Xong', ok ? 'success' : 'error');
    }
  } catch (e) {
    arrangeResultMsg('✗ Lỗi kết nối', false);
    toast('Lỗi kết nối khi arrange', 'error');
  }
  window.__ytmArranging = false;
}
// ============ ARRANGE PANEL (nhom: pham vi / bo cuc / man hinh / tuy chon) ============
function arrDefaultCfg() {
  return { scope: 'visible', mode: 'smart_auto', monitor: 'settings', taskbar: true, uniform: true, autofit: true, skipmin: false, focus: true, size: 'auto', density: 'balanced', cols: 0 };
}
let arrCfg = null;
function arrEnsureCfg() {
  if (!arrCfg) {
    arrCfg = arrDefaultCfg();
    try {
      const s = JSON.parse(localStorage.getItem('ytm-arr-last') || 'null');
      if (s && typeof s.mode === 'string') arrCfg = { ...arrCfg, ...s };
    } catch (e) {}
  }
  return arrCfg;
}
function arrSaveLast() {
  try { localStorage.setItem('ytm-arr-last', JSON.stringify(arrEnsureCfg())); } catch (e) {}
}
function arrScopeCounts() {
  return {
    visible: profilesFiltered.length,
    selected: selectedProfileIds.size,
    running: profiles.filter(p => p.status === 'running').length
  };
}
function arrScopeIds() {
  const cfg = arrEnsureCfg();
  if (cfg.scope === 'selected') return [...selectedProfileIds];
  if (cfg.scope === 'running') return profiles.filter(p => p.status === 'running').map(p => p.id);
  return profilesFiltered.map(p => p.id);
}
function arrPaint() {
  const cfg = arrEnsureCfg();
  const c = arrScopeCounts();
  const nv = $('arr-n-visible'), ns = $('arr-n-selected'), nr = $('arr-n-running');
  if (nv) nv.textContent = c.visible;
  if (ns) ns.textContent = c.selected;
  if (nr) nr.textContent = c.running;
  document.querySelectorAll('input[name="arr-scope"]').forEach(r => { r.checked = r.value === cfg.scope; });
  document.querySelectorAll('#arr-layouts .lay-card').forEach(el => el.classList.toggle('active', el.dataset.mode === cfg.mode));
  const ms = $('arr-monitor');
  if (ms && ![...ms.options].some(o => o.value === cfg.monitor)) cfg.monitor = 'settings';
  if (ms) ms.value = cfg.monitor;
  const set = (id, v) => { const el = $(id); if (el) el.checked = !!v; };
  set('arr-opt-taskbar', cfg.taskbar);
  set('arr-opt-uniform', cfg.uniform);
  set('arr-opt-autofit', cfg.autofit);
  set('arr-opt-skipmin', cfg.skipmin);
  set('arr-opt-focus', cfg.focus);
  if ($('arr-size')) $('arr-size').value = cfg.size || 'auto';
  if ($('arr-density')) $('arr-density').value = cfg.density || 'balanced';
  arrPaintPreview();
  arrPaintPresets();
}
function arrPaintPreview() {
  const box = $('arr-preview');
  if (!box) return;
  const cfg = arrEnsureCfg();
  const n = Math.max(1, Math.min(arrScopeIds().length || 1, 12));
  let html = '';
  const cell = (cls) => `<span class="mp ${cls || ''}"></span>`;
  if (cfg.mode === 'horizontal') {
    for (let i = 0; i < n; i++) html += cell();
  } else if (cfg.mode === 'vertical') {
    html = `<span style="display:flex;flex-direction:column;gap:4px">${Array(n).fill(cell()).join('')}</span>`;
  } else if (cfg.mode === 'cascade' || cfg.mode === 'compact') {
    for (let i = 0; i < Math.min(n, 4); i++) html += `<span class="mp" style="width:${34 - i * 5}px;height:${30 - i * 3}px;margin-left:${i * 9}px;${i ? 'margin-top:-24px' : ''}"></span>`;
  } else {
    const cols = cfg.cols > 0 ? cfg.cols : Math.max(1, Math.ceil(Math.sqrt(n)));
    for (let i = 0; i < n; i++) html += cell();
    box.style.display = 'grid';
    box.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
    box.innerHTML = html;
    return;
  }
  box.style.display = 'flex';
  box.innerHTML = html;
}
function arrSelectMode(m) {
  const cfg = arrEnsureCfg();
  cfg.mode = m;
  cfg.cols = 0;
  arrSaveLast();
  arrPaint();
}
function arrFillMonitors() {
  const sel = $('arr-monitor');
  if (!sel) return;
  const cur = arrEnsureCfg().monitor;
  sel.innerHTML = '<option value="settings">Theo cài đặt</option>'
    + '<option value="profile">Theo cài đặt profile (giữ màn hình riêng)</option>'
    + '<option value="primary">Màn hình chính</option>'
    + '<option value="all">Tất cả màn hình</option>';
  (cachedMonitors || []).forEach(m => {
    const o = document.createElement('option');
    const r = m.resolution ? ` - ${m.resolution.w}x${m.resolution.h}` : '';
    o.value = 'name:' + (m.name || '');
    o.textContent = `Màn hình ${m.id}${m.primary ? ' (chính)' : ''}${r}`;
    sel.appendChild(o);
  });
  sel.value = [...sel.options].some(o => o.value === cur) ? cur : 'settings';
  arrEnsureCfg().monitor = sel.value;
}
function arrReadPanel() {
  const cfg = arrEnsureCfg();
  const sc = document.querySelector('input[name="arr-scope"]:checked');
  if (sc) cfg.scope = sc.value;
  cfg.monitor = $('arr-monitor') ? $('arr-monitor').value : 'settings';
  cfg.taskbar = $('arr-opt-taskbar') ? $('arr-opt-taskbar').checked : true;
  cfg.uniform = $('arr-opt-uniform') ? $('arr-opt-uniform').checked : true;
  cfg.autofit = $('arr-opt-autofit') ? $('arr-opt-autofit').checked : true;
  cfg.skipmin = $('arr-opt-skipmin') ? $('arr-opt-skipmin').checked : false;
  cfg.focus = $('arr-opt-focus') ? $('arr-opt-focus').checked : true;
  cfg.size = $('arr-size') ? $('arr-size').value : 'auto';
  cfg.density = $('arr-density') ? $('arr-density').value : 'balanced';
  arrSaveLast();
  return cfg;
}
function arrBuildPayload(extra) {
  const cfg = arrReadPanel();
  const ids = arrScopeIds();
  if (!ids.length) { toast('Không có kênh nào trong phạm vi đã chọn', 'error'); return null; }
  const p = { profileIds: ids, mode: cfg.mode };
  if (cfg.monitor && cfg.monitor !== 'settings') {
    if (cfg.monitor === 'all' || cfg.monitor === 'primary' || cfg.monitor === 'profile') p.monitor = cfg.monitor;
    else if (cfg.monitor.startsWith('name:')) p.monitors = [cfg.monitor.slice(5)];
  }
  p.respectTaskbar = cfg.taskbar;
  p.sizeBalance = cfg.uniform ? 'similar' : 'maximize';
  p.sizeMode = cfg.autofit ? 'auto_fit' : 'keep_size';
  if (cfg.skipmin) p.skipMinimized = true;
  p.noActivate = cfg.focus !== false;
  // Kich thuoc & mat do -> min/gap override (auto/balanced = theo settings)
  if (cfg.size === 'small') { p.minW = 400; p.minH = 300; }
  else if (cfg.size === 'medium') { p.minW = 800; p.minH = 500; }
  else if (cfg.size === 'large') { p.minW = 1100; p.minH = 650; }
  if (cfg.density === 'relaxed') { p.gapX = 12; p.gapY = 12; }
  else if (cfg.density === 'dense') { p.gapX = 0; p.gapY = 0; }
  if (cfg.mode === 'grid' && cfg.cols > 0) p.cols = cfg.cols;
  return { ...p, ...(extra || {}) };
}
function arrApplyPanel() { const p = arrBuildPayload(); if (p) arrangeCall(p); }
function arrApplyPresetCols(n) {
  const cfg = arrEnsureCfg();
  cfg.mode = 'grid';
  cfg.cols = n;
  const ids = arrScopeIds();
  if (!ids.length) { toast('Không có kênh nào trong phạm vi đã chọn', 'error'); return; }
  const base = arrBuildPayload();
  if (!base) return;
  base.mode = 'grid';
  base.cols = n;
  arrSaveLast();
  arrPaint();
  arrangeCall(base);
}
function arrApplyPresetCells(n) {
  // "N o/man": grid voi so cot gan voi can bac 2 (4->2x2, 6->3x2, 8->4x2, 12->4x3)
  const cols = n <= 4 ? 2 : (n <= 6 ? 3 : 4);
  const cfg = arrEnsureCfg();
  cfg.mode = 'grid';
  cfg.cols = 0;
  const ids = arrScopeIds();
  if (!ids.length) { toast('Không có kênh nào trong phạm vi đã chọn', 'error'); return; }
  const base = arrBuildPayload();
  if (!base) return;
  base.mode = 'grid';
  base.cols = cols;
  arrSaveLast();
  arrPaint();
  closeArrangeDrawer();
  arrangeCall(base);
}
function arrangeLast() {
  const cfg = arrEnsureCfg();
  const base = arrBuildPayload();
  if (!base) return;
  if (cfg.mode === 'grid' && cfg.cols > 0) base.cols = cfg.cols;
  else delete base.cols;
  arrangeCall(base);
}
function arrGetPresets() {
  try { return JSON.parse(localStorage.getItem('ytm-arr-presets') || '[]'); } catch (e) { return []; }
}
function arrPaintPresets() {
  const box = $('arr-presets');
  if (!box) return;
  const list = arrGetPresets();
  box.innerHTML = list.map((p, i) =>
    `<span class="preset-chip">${escapeHtml(p.name)}<button title="Xóa preset" onclick="arrDelPreset(${i})">×</button></span>`
  ).join('');
  box.querySelectorAll('.preset-chip').forEach((el, i) => {
    el.addEventListener('click', (e) => {
      if (e.target.tagName === 'BUTTON') return;
      arrApplyPresetObj(list[i].cfg);
    });
  });
}
function arrApplyPresetObj(cfg) {
  if (!cfg) return;
  arrCfg = { ...arrDefaultCfg(), ...cfg };
  arrSaveLast();
  arrPaint();
  arrangeLast();
}
function arrSavePreset() {
  const name = prompt('Tên preset:', 'Preset ' + (arrGetPresets().length + 1));
  if (!name || !name.trim()) return;
  const list = arrGetPresets();
  list.push({ name: name.trim().slice(0, 30), cfg: arrReadPanel() });
  try { localStorage.setItem('ytm-arr-presets', JSON.stringify(list)); } catch (e) {}
  arrPaintPresets();
  toast('Đã lưu preset', 'success');
}
function arrDelPreset(i) {
  const list = arrGetPresets();
  list.splice(i, 1);
  try { localStorage.setItem('ytm-arr-presets', JSON.stringify(list)); } catch (e) {}
  arrPaintPresets();
}
async function arrangePreviewPanel() {
  const p = arrBuildPayload({ dryRun: true });
  if (!p) return;
  arrangeResultMsg('⏳ Đang tính preview...', null);
  try {
    const res = await sendJson(api + 'syncwin.php?action=arrange', p);
    if (!res.ok || !res.plan) {
      arrangeResultMsg('✗ ' + (res.message || 'Không tính được'), false);
      toast(res.message || 'Không tính được preview', 'error');
      return;
    }
    const ids = arrScopeIds();
    lastPreviewPayload = { ...p };
    delete lastPreviewPayload.dryRun;
    renderPreviewModal(res);
    showModal('preview-modal');
    arrangeResultMsg('Xem trước sẵn sàng', true);
  } catch (e) {
    arrangeResultMsg('✗ Lỗi kết nối', false);
  }
}
function gotoLayoutSettings() {
  closeArrangeDrawer();
  switchView('settings');
}
let lastPreviewPayload = null;
function renderPreviewModal(res) {
  const plan = res.plan;
  const bd = plan.breakdown && plan.breakdown.length ? plan.breakdown
    : [{ monitorId: (plan.monitors || [])[0], count: (plan.slots || []).length, rows: plan.rows, cols: plan.cols, cellW: plan.cellW, cellH: plan.cellH }];
  $('preview-summary').textContent =
    `${(plan.slots || []).length} cửa sổ · ${plan.cols}x${plan.rows}` +
    (plan.fallbackUsed ? ` (dự phòng ${plan.fallbackUsed})` : '') +
    ((res.missingMonitors && res.missingMonitors.length) ? ` · thiếu màn hình: ${res.missingMonitors.join(',')}` : '');
  const liveWins = (res.session && res.session.windows ? res.session.windows : []);
  let html = '';
  let si = 0;
  bd.forEach(b => {
    const names = [];
    for (let k = 0; k < b.count; k++, si++) {
      const w = liveWins[si];
      names.push(w ? '#' + w.profileId : '·');
    }
    html += `<div class="preview-mon"><div class="preview-mon-head">Màn hình ${b.monitorId} - ${b.count} cửa sổ · ${b.cols}x${b.rows} · ${b.cellW}x${b.cellH}</div>`;
    html += `<div class="preview-grid" style="grid-template-columns:repeat(${Math.max(1, b.cols)},1fr)">`;
    names.forEach(nm => { html += `<div class="preview-cell">${escapeHtml(nm)}</div>`; });
    html += '</div></div>';
  });
  $('preview-body').innerHTML = html;
}
async function applyPreviewArrange() {
  closeModal('preview-modal');
  if (lastPreviewPayload) await arrangeCall(lastPreviewPayload);
}

// ============ PHASE 9: Auto Arrange sau Multi Launch (spec muc 27) ============
// Doi HWND tung Chrome (poll 1s/timeout 20s, KHONG sleep mu), roi arrange theo
// dung thu tu ids (slot theo profile order, khong phu thuoc launch nhanh/cham).
async function autoArrangeAfterLaunch(ids) {
  try {
    if (!settings || !settings.layout_auto_launch) return;
    ids = (ids || []).map(Number).filter(x => x > 0);
    if (!ids.length) return;
    arrangeResultMsg('⏳ Đợi Chrome hiện cửa sổ...', null);
    const deadline = Date.now() + 20000;
    let ready = [];
    while (Date.now() < deadline) {
      try {
        const res = await getJson(api + 'syncwin.php?action=discover');
        const have = new Set((res.ok && res.data && res.data.windows ? res.data.windows : []).map(w => Number(w.profileId)));
        ready = ids.filter(id => have.has(id));
        if (ready.length === ids.length) break;
      } catch (e) {}
      await sleep(1000);
    }
    if (!ready.length) { arrangeResultMsg('✗ Không thấy cửa sổ nào sau khi mở', false); return; }
    // Giu monitor affinity sau Start All: khong keo ve primary
    await arrangeCall({ profileIds: ready, monitor: 'profile', allowLocked: true });
    if (ready.length < ids.length) {
      toast(`Tự xếp ${ready.length}/${ids.length} kênh (số còn lại chưa hiện cửa sổ)`, 'error');
    }
  } catch (e) {
    console.error(e);
  }
}

// Live tracker: trong batch mo/dong, poll nhe 2s/lan de card cap nhat
// RUNNING/CLOSING truc tiep (thay vi chi busy den cuoi batch). Render 1 lan/luot.
function startBulkTracker() {
  stopBulkTracker();
  window.__ytmBulkTimer = setInterval(() => {
    if (!document.hidden) loadProfiles();
  }, 2000);
}
function stopBulkTracker() {
  if (window.__ytmBulkTimer) { clearInterval(window.__ytmBulkTimer); window.__ytmBulkTimer = null; }
}
async function openSelected() {
  const ids = getSelectedIds();
  if (!ids.length) { toast('Chưa chọn kênh nào', 'error'); return; }
  if (activeBatch) { toast(`Đang ${activeBatch.kind === 'open' ? 'mở' : 'đóng'} hàng loạt — thử lại sau`, 'error'); return; }
  const seq = ++batchSeq;
  activeBatch = { kind: 'open', seq };
  const ui = batchBtn(null, 'Đang mở');
  window.__ytmBulkOp = true; // chan reflow xen vao giua bulk launch (Phase 12)
  startBulkTracker(); // card cap nhat RUNNING truc tiep trong batch
  try {
    // Pool 5 song song + stagger (mo tuan tu 20 kenh mat hang phut)
    const r = await poolEach(ids, id => getJson(api + `browser.php?action=open&id=${id}`), 'Đang mở', { seq, onTick: ui.tick.bind(ui) });
    markProfileChanged();
    if (r.cancelled) { toast('Đã hủy mở hàng loạt', 'error'); refreshAll(); return; }
    toast(`Đã mở ${r.ok}/${ids.length} kênh`, r.fail ? 'error' : 'success');
    refreshAll();
    await autoArrangeAfterLaunch(ids);
  } finally {
    window.__ytmBulkOp = false;
    stopBulkTracker();
    ui.done();
    if (activeBatch && activeBatch.seq === seq) activeBatch = null;
  }
}
async function closeSelected() {
  const ids = getSelectedIds();
  if (!ids.length) { toast('Chưa chọn kênh nào', 'error'); return; }
  if (activeBatch) { toast('Đang chạy batch khác — thử lại sau', 'error'); return; }
  const seq = ++batchSeq; // huy batch Start dang chay (QUEUED dung, STARTING/RUNNING -> CLOSING)
  activeBatch = { kind: 'close', seq };
  const ui = batchBtn(null, 'Đang đóng');
  window.__ytmBulkOp = true;
  startBulkTracker();
  try {
    const r = await poolEach(ids, id => getJson(api + `browser.php?action=close&id=${id}`), 'Đang đóng', { seq, onTick: ui.tick.bind(ui) });
    markProfileChanged();
    if (r.cancelled) { toast('Đã hủy', 'error'); refreshAll(); return; }
    toast(`Đã đóng ${r.ok}/${ids.length} kênh`, r.fail ? 'error' : 'success');
    refreshAll();
  } finally {
    window.__ytmBulkOp = false;
    stopBulkTracker();
    ui.done();
    if (activeBatch && activeBatch.seq === seq) activeBatch = null;
  }
}
// Pool dispatch song song co gioi han (B1-B3): toi da 5 request dong thoi,
// worker thu w nghi w*150ms truoc khi chay (stagger chong spike). Tra ve {ok,fail,cancelled}.
// opts: {seq, verbEl, onTick(done,total)} — seq khac batch hien tai -> dung (bi huy).
const BULK_POOL = 5, BULK_STAGGER_MS = 150;
let batchSeq = 0; // tang moi batch Start/Stop (Stop huy Start dang chay)
let activeBatch = null; // {kind:'open'|'close', seq, btnId, label}
async function poolEach(ids, fn, verb, opts) {
  opts = opts || {};
  let i = 0, ok = 0, fail = 0;
  const el = opts.verbEl ? $(opts.verbEl) : $('selected-count');
  const tick = () => {
    if (el) el.textContent = `${verb || 'Đang xử lý'} ${ok + fail}/${ids.length}…`;
    if (opts.onTick) { try { opts.onTick(ok + fail, ids.length); } catch (e) {} }
  };
  tick();
  const workers = Array.from({ length: Math.min(BULK_POOL, ids.length) }, async (_, w) => {
    if (w > 0) await sleep(w * BULK_STAGGER_MS);
    while (i < ids.length) {
      if (opts.seq !== undefined && opts.seq !== batchSeq) return; // bi huy
      const id = ids[i++];
      try { await fn(id); ok++; } catch (e) { fail++; }
      tick();
    }
  });
  await Promise.all(workers);
  const cancelled = opts.seq !== undefined && opts.seq !== batchSeq;
  return { ok, fail, cancelled };
}
// Helper batch nut Mo/Dong tat ca: progress tren nut, chong double-click,
// Stop huy Start dang chay (cancel QUEUED, STARTING/RUNNING chuyen CLOSING).
function batchBtn(btnId, label) {
  const btn = btnId ? $(btnId) : null;
  return {
    start(text) { if (btn) { btn.dataset.orig = btn.dataset.orig || btn.textContent; btn.textContent = text; } },
    tick(done, total) { if (btn) btn.textContent = `◌ ${label} ${done}/${total}`; },
    done() { if (btn && btn.dataset.orig) { btn.textContent = btn.dataset.orig; delete btn.dataset.orig; } }
  };
}
async function deleteSelected() {
  if (!selectedProfileIds.size) { toast('Chưa chọn kênh nào', 'error'); return; }
  const n = selectedProfileIds.size;
  confirmDelete(`Bạn chắc chắn muốn xóa ${n} kênh đã chọn?<br><small>Thư mục dữ liệu (cache) của các kênh sẽ bị xóa vĩnh viễn.</small>`, async () => {
    const ids = getSelectedIds();
    for (const id of ids) {
      try { await del(api + `profiles.php?action=delete&id=${id}`); } catch (e) {}
    }
    markProfileChanged();
    toast(`Đã xóa ${ids.length} kênh`, 'success');
    refreshAll();
  });
}
// ============ CHANNEL EVALUATION (batch + realtime card) ============
// Flow: SELECT -> eval_start (mark CHECKING) -> chunk(4) -> update tung card ngay.
// Chi danh gia kenh DA CHON; chua chon -> toast, khong am tham danh gia tat ca.
const EVAL_STATUS = {
  UNCHECKED: ['Chưa kiểm tra', 'badge-muted', '●'],
  CHECKING: ['Đang kiểm tra', 'badge-info', '◌'],
  ACTIVE: ['Hoạt động', 'badge-ok', '●'],
  LOGIN_REQUIRED: ['Cần đăng nhập', 'badge-review', '!'],
  VERIFICATION_REQUIRED: ['Cần xác minh', 'badge-warn', '!'],
  CHANNEL_UNAVAILABLE: ['Không truy cập được', 'badge-danger', '●'],
  RESTRICTED: ['Bị hạn chế', 'badge-warn', '!'],
  ERROR: ['Lỗi kiểm tra', 'badge-muted', '!'],
};
// Ma loi -> tieng Viet (khong hien raw code tren card)
const EVAL_ERR_VN = {
  CHROME_NOT_RUNNING: 'Chrome chưa chạy — cần mở Chrome để kiểm tra',
  CHROME_START_TIMEOUT: 'Mở Chrome quá chậm',
  BROWSER_NOT_RUNNING: 'Chrome chưa chạy',
  DEBUG_PORT_UNAVAILABLE: 'Không lấy được cổng kiểm tra',
  DEBUG_PORT_NOT_LISTENING: 'Cổng kiểm tra trình duyệt chưa sẵn sàng',
  DEVTOOLS_HTTP_UNAVAILABLE: 'Không kết nối được trình duyệt',
  CDP_UNAVAILABLE: 'Không kết nối được trình duyệt',
  CDP_CONNECT_FAILED: 'Không kết nối được trình duyệt',
  CDP_CONNECT_TIMEOUT: 'Không kết nối được trình duyệt',
  CDP_WEBSOCKET_FAILED: 'Không kết nối được trình duyệt',
  CDP_COMMAND_TIMEOUT: 'Trình duyệt phản hồi chậm',
  CDP_DISCONNECTED: 'Mất kết nối với trình duyệt',
  CDP_TIMEOUT: 'Trình duyệt phản hồi quá thời gian',
  PROXY_ERROR: 'Proxy không kết nối được',
  NETWORK_TIMEOUT: 'Kết nối mạng quá thời gian',
  PAGE_TIMEOUT: 'Trang phản hồi quá chậm',
  TIMEOUT: 'Kiểm tra quá thời gian', PROFILE_MISMATCH: 'Nhầm phiên Chrome',
  LOGIN_REQUIRED: 'Cần đăng nhập', SECURITY_CHALLENGE: 'Cần xác minh',
  YOUTUBE_UNAVAILABLE: 'Không truy cập được YouTube',
  EVALUATOR_INTERNAL_ERROR: 'Lỗi công cụ kiểm tra', EVALUATOR_ERROR: 'Lỗi công cụ kiểm tra',
};
function evalErrVN(code, fallback) {
  if (!code) return fallback || '';
  return EVAL_ERR_VN[code] || fallback || 'Có vấn đề cần kiểm tra';
}
function evalBadge(st) {
  const [label, cls, dot] = EVAL_STATUS[st] || EVAL_STATUS.UNCHECKED;
  return `<span class="badge ${cls}">${dot} ${label}</span>`;
}
let evalBatch = null; // {batch_id, total, done} de Cancel + progress
let evalRunning = false;
function evalApplyResult(r) {
  // Ghi ket qua vao store local + highlight neu doi trang thai
  const p = profiles.find(x => Number(x.id) === Number(r.profileId));
  if (!p) return false;
  const old = p.eval_status || 'UNCHECKED';
  const nw = r.status || 'ERROR';
  p.eval_status = nw;
  p.eval_prev = (r.prev_status && r.prev_status !== 'CHECKING') ? r.prev_status : p.eval_prev;
  p.eval_known = r.last_known_status ?? p.eval_known;
  p.auth_status = r.auth_status ?? p.auth_status;
  p.channel_presence = r.channel_presence ?? p.channel_presence;
  // V2 single source of truth (§49): browser/google/youtube/access/security/version
  if (r.browser_status) p.browser_status = r.browser_status;
  if (r.google_auth_status) p.google_auth_status = r.google_auth_status;
  if (r.google_auth_confidence) p.google_auth_confidence = r.google_auth_confidence;
  if (r.youtube_auth_status) p.youtube_auth_status = r.youtube_auth_status;
  if (r.youtube_auth_confidence) p.youtube_auth_confidence = r.youtube_auth_confidence;
  if (r.channel_presence_confidence) p.channel_presence_confidence = r.channel_presence_confidence;
  if (r.channel_access_status) p.channel_access_status = r.channel_access_status;
  if (r.security_status) p.security_status = r.security_status;
  if (r.evaluation_id) p.evaluation_id = r.evaluation_id;
  // Summary tong hop (§25): backend tinh san, frontend chi derive khi thieu
  if (r.youtube_status) p.youtube_status = r.youtube_status;
  if (r.account_channel_state) p.account_channel_state = r.account_channel_state;
  if (r.readiness_status) p.readiness_status = r.readiness_status;
  if (r.last_verified_auth_status) p.last_verified_auth_status = r.last_verified_auth_status;
  if (r.last_auth_verified_at) p.last_auth_verified_at = r.last_auth_verified_at;
  if (r.last_verified_channel_presence) p.last_verified_channel_presence = r.last_verified_channel_presence;
  if (r.last_channel_verified_at) p.last_channel_verified_at = r.last_channel_verified_at;
  if (r.attempt_status === 'PARTIAL') p.eval_attempt_status = 'PARTIAL';
  p.channel_verified_at = r.channel_verified_at || p.channel_verified_at;
  p.last_known_presence = r.last_known_presence ?? p.last_known_presence;
  if (r.channel_state) { p.acc_channel = r.channel_state; p.acc_channel_name = r.channel_name || null; }
  p.eval_attempt = r.checked_at || p.eval_attempt;
  // Patch ngay completed/success/changed (ISO kem offset) -> card doi tuc thi
  if (r.last_completed_at) { p.eval_completed = r.last_completed_at; p.eval_attempt = r.last_completed_at; }
  if (r.last_successful_check_at) p.eval_success_at = r.last_successful_check_at;
  p.eval_attempt_status = r.attempt_status || null;
  p.eval_error_code = r.error_code || null;
  p.eval_error = r.tool_error ? (r.reason || 'Lỗi kiểm tra') : (r.error_code ? (r.reason || r.error_code) : null);
  p.eval_stage = r.run_state || null;
  if (r.login_state) p.acc_login = r.login_state;
  if (r.session_state) p.acc_session = r.session_state;
  if (r.youtube_state) p.acc_youtube = r.youtube_state;
  if (r.stability != null) p.acc_stability = r.stability;
  if (r.confidence != null) p.acc_confidence = r.confidence;
  if (r.stage) p.acc_stage = r.stage;
  updateCardEval(p.id, old !== nw);
  return true;
}
function updateCardEval(id, changed) {
  // Update dung badge/checked cua card (khong render lai grid)
  const cards = document.querySelectorAll('#profiles-grid .profile-card');
  const p = profiles.find(x => Number(x.id) === Number(id));
  if (!p) return;
  const idx = paginateProfiles().findIndex(x => Number(x.id) === Number(id));
  if (idx < 0 || !cards[idx]) { renderProfiles(); return; }
  const el = cards[idx].querySelector('.eval-block');
  if (el) {
    el.innerHTML = evalBlockInner(p);
    if (changed) {
      el.classList.remove('eval-flash');
      void el.offsetWidth;
      el.classList.add('eval-flash');
      setTimeout(() => el.classList.remove('eval-flash'), 2500);
    }
  }
}
// Buffer gom update card trong ~75ms (4 finish gan nhau -> 1 render)
let evalFlushTimer = null;
const evalPendingPaint = new Map();
function evalQueuePaint(r) {
  evalPendingPaint.set(Number(r.profileId), r);
  clearTimeout(evalFlushTimer);
  evalFlushTimer = setTimeout(() => {
    for (const q of evalPendingPaint.values()) {
      evalApplyResult(q);
      if (accDrawerId === Number(q.profileId)) openAccountDrawer(Number(q.profileId), true);
    }
    evalPendingPaint.clear();
  }, 75);
}
function toggleEvalMenu(e) {
  e.stopPropagation();
  const m = $('eval-menu');
  m.classList.toggle('hidden');
  const c = $('eval-menu-count');
  if (c) c.textContent = selectedProfileIds.size ? `(${selectedProfileIds.size})` : '';
}
document.addEventListener('click', () => { const m = $('eval-menu'); if (m) m.classList.add('hidden'); });
async function evaluateAll() {
  $('eval-menu').classList.add('hidden');
  if (evalRunning) { monEvalPopover(); return; }
  const st = await sendJson(api + 'accounts.php?action=eval_all', {});
  if (!st.ok) { toast(st.message || 'Lỗi', 'error'); return; }
  toast(`Đánh giá tất cả ${st.data.total} kênh...`, '');
  await runEvalBatchId(st.data.batch_id, st.data.total, st.data.ids || []);
}
async function evaluateDue() {
  $('eval-menu').classList.add('hidden');
  if (evalRunning) { monEvalPopover(); return; }
  const st = await sendJson(api + 'accounts.php?action=eval_due', {});
  if (!st.ok) { toast(st.message || 'Không có kênh nào cần cập nhật', ''); return; }
  toast(`Đánh giá ${st.data.total} kênh cần cập nhật...`, '');
  await runEvalBatchId(st.data.batch_id, st.data.total, st.data.ids || []);
}
async function evaluateSelected() {
  if (evalRunning) { monEvalPopover(); return; } // click khi running -> popover trang thai
  // Snapshot selection luc bat dau (bo chon sau khong anh huong batch)
  const ids = Array.from(selectedProfileIds).map(Number).filter(x => x > 0);
  if (!ids.length) { toast('Chọn ít nhất một kênh để đánh giá', 'error'); return; }
  await runEvalBatchIds(ids);
}
// Chung cho selected/all/due: optimistic QUEUED + chunk + patch tung card
async function runEvalBatchIds(ids) {
  // Bo qua kenh dang CHECKING (profile lock) thay vi duplicate
  const fresh = ids.filter(id => {
    const p = profiles.find(x => Number(x.id) === id);
    return !(p && p.eval_status === 'CHECKING');
  });
  if (!fresh.length) { toast('Các kênh đã chọn đang được kiểm tra', 'error'); return; }
  if (fresh.length < ids.length) toast(`Bỏ qua ${ids.length - fresh.length} kênh đang kiểm tra`, '');
  const st = await sendJson(api + 'accounts.php?action=eval_start', { ids: fresh, concurrency: 4 });
  if (!st.ok) { toast(st.message || 'Lỗi tạo batch', 'error'); return; }
  await runEvalBatchId(st.data.batch_id, fresh.length, fresh);
}
async function runEvalBatchId(batchId, total, knownIds) {
  // Dung batch server co san (khong tao batch moi). Optimistic QUEUED cho ids da biet.
  const fresh = (knownIds || []).filter(id => {
    const p = profiles.find(x => Number(x.id) === id);
    return !(p && p.eval_status === 'CHECKING');
  });
  evalRunning = true;
  const btn = $('btn-eval-bulk');
  const cancelBtn = $('btn-eval-cancel');
  if (cancelBtn) cancelBtn.classList.remove('hidden');
  const oldBtn = btn ? btn.innerHTML : '';
  // 1) QUEUED/CHECKING optimistic ngay (giu previous)
  fresh.forEach(id => {
    const p = profiles.find(x => Number(x.id) === id);
    if (p) { p.eval_prev = p.eval_status || 'UNCHECKED'; p.eval_status = 'CHECKING'; p.eval_stage = 'QUEUED'; }
  });
  renderProfiles();
  const conc = (settings && [2, 4, 6, 8].includes(Number(settings.acc_concurrency))) ? Number(settings.acc_concurrency) : 4;
  const counts = {};
  let done = 0, failed = 0, techErr = 0, partial = 0;
  try {
    // 2) Batch server da tao san
    evalBatch = { batch_id: batchId, total, done: 0, failed: 0, pending: total };
    // Poll stage 2s cho card dang CHECKING (hien thi "Dang kiem tra YouTube...")
    const stageTimer = setInterval(async () => {
      const checking = profiles.filter(p => p.eval_status === 'CHECKING' && (!fresh.length || fresh.includes(Number(p.id)))).slice(0, 8);
      for (const p of checking) {
        try {
          const s = await getJson(api + `accounts.php?action=eval_stage&id=${p.id}`);
          const st2 = s.ok && s.data ? s.data.stage : null;
          if (st2 && st2 !== p.eval_stage && st2 !== 'DONE' && st2 !== 'SUCCESS' && st2 !== 'FAILED') {
            p.eval_stage = st2;
            updateCardEval(p.id, false);
          }
        } catch (e) {}
      }
    }, 2000);
    evalBatch.stageTimer = stageTimer;
    // 3) Chunk theo concurrency, kenh nao xong update card do ngay (buffer 75ms)
    let finished = false;
    while (!finished) {
      if (btn) btn.innerHTML = `◌ ${done}/${total}`;
      evalBatch.done = done; evalBatch.failed = failed; evalBatch.pending = total - done;
      const ch = await sendJson(api + 'accounts.php?action=eval_chunk', { batch_id: evalBatch.batch_id, limit: conc });
      if (!ch.ok) { toast(ch.message || 'Lỗi batch', 'error'); break; }
      for (const r of (ch.data.results || [])) {
        if (r.already_running) { continue; }
        done++;
        if ((r.attempt_status || '') === 'SUCCESS') { if (r.status) counts[r.status] = (counts[r.status] || 0) + 1; }
        else if ((r.attempt_status || '') === 'PARTIAL') { partial++; if (r.status) counts[r.status] = (counts[r.status] || 0) + 1; }
        else { failed++; if (r.tool_error) techErr++; }
        evalQueuePaint(r);
      }
      evalBatch.done = done; evalBatch.failed = failed; evalBatch.partial = partial; evalBatch.pending = total - done;
      if (btn) btn.innerHTML = `◌ ${done}/${total}`;
      finished = !!ch.data.done;
      if (!finished && !(ch.data.results || []).length) break;
    }
  } catch (e) {
    toast('Lỗi kết nối khi đánh giá', 'error');
  } finally {
    clearTimeout(evalFlushTimer);
    if (evalBatch && evalBatch.stageTimer) clearInterval(evalBatch.stageTimer);
    for (const q of evalPendingPaint.values()) evalApplyResult(q);
    evalPendingPaint.clear();
    // Tra CHECKING treo (cancel/mang rot) ve previous
    profiles.forEach(p => {
      if (p.eval_status === 'CHECKING' && (!fresh.length || fresh.includes(Number(p.id)))) {
        p.eval_status = p.eval_prev || 'UNCHECKED';
      }
    });
    renderProfiles();
    if (btn) btn.innerHTML = oldBtn;
    if (cancelBtn) cancelBtn.classList.add('hidden');
    const pop = $('eval-popover');
    if (pop) pop.classList.add('hidden');
    evalRunning = false;
    evalBatch = null;
  }
  const sum = Object.entries(counts).map(([s, c]) => `${(EVAL_STATUS[s] || [])[0] || s}: ${c}`).join(' · ');
  toast(`Đánh giá hoàn tất${sum ? ' — ' + sum : ''}${partial ? ` · một phần: ${partial}` : ''}${techErr ? ` · lỗi kỹ thuật: ${techErr}` : ''}`, done === total ? 'success' : 'error');
}
// Mini popover trang thai batch (click toolbar khi running)
function monEvalPopover() {
  let pop = $('eval-popover');
  if (!pop) {
    pop = document.createElement('div');
    pop.id = 'eval-popover';
    pop.className = 'eval-popover hidden';
    document.body.appendChild(pop);
  }
  if (!evalBatch) { pop.classList.add('hidden'); return; }
  const b = evalBatch;
  const conc = (settings && [2, 4, 6, 8].includes(Number(settings.acc_concurrency))) ? Number(settings.acc_concurrency) : 4;
  pop.innerHTML = `<strong>Đánh giá hàng loạt</strong>`
    + `<div>Tổng: ${b.total || 0}</div>`
    + `<div>Hoàn tất: ${b.done || 0}</div><div>Đang chạy: ${Math.min(conc, (b.total || 0) - (b.done || 0))}</div>`
    + `<div>Đang chờ: ${b.pending || 0}</div><div>Thất bại: ${b.failed || 0}</div>`
    + (b.partial ? `<div>Một phần: ${b.partial}</div>` : '')
    + `<button class="btn btn-sm" onclick="evalCancelBatch()">Hủy các mục đang chờ</button>`;
  const btn = $('btn-eval-bulk');
  const rc = btn ? btn.getBoundingClientRect() : { left: 100, bottom: 100 };
  pop.style.left = Math.min(window.innerWidth - 240, rc.left) + 'px';
  pop.style.top = (rc.bottom + window.scrollY + 6) + 'px';
  pop.classList.toggle('hidden');
}
async function evalCancelBatch() {
  if (!evalBatch) return;
  await sendJson(api + 'accounts.php?action=eval_cancel', { batch_id: evalBatch.batch_id });
  toast('Đã gửi yêu cầu hủy batch', '');
}
// Danh gia 1 profile (nut Kiem tra tren card): lock + disable rieng nut do
async function evaluateOneProfile(id) {
  const p = profiles.find(x => Number(x.id) === Number(id));
  // Profile lock: dang CHECKING -> focus operation hien tai, khong duplicate
  if (p && p.eval_status === 'CHECKING') {
    toast('Kênh đang được kiểm tra...', '');
    if (evalBatch) {
      // Neu QUEUED trong batch -> tang priority, khong tao moi
      sendJson(api + 'accounts.php?action=eval_priority', { batch_id: evalBatch.batch_id, profile_id: id });
      toast('Đã ưu tiên kênh này lên đầu hàng đợi', 'success');
    }
    return;
  }
  const btn = document.querySelector(`button[onclick="evaluateOneProfile(${Number(id)})"]`);
  if (btn) btn.disabled = true;
  if (p) { p.eval_prev = p.eval_status || 'UNCHECKED'; p.eval_status = 'CHECKING'; p.eval_stage = 'QUEUED'; updateCardEval(id, false); }
  if (accDrawerId === Number(id)) openAccountDrawer(Number(id), true);
  try {
    const res = await sendJson(api + 'accounts.php?action=refresh', { id });
    if (res.ok && res.data) {
      if (res.data.already_running) { toast('Kênh đang được kiểm tra ở tiến trình khác', ''); }
      else {
        evalApplyResult(res.data);
        const r = res.data;
        const label = (EVAL_STATUS[r.status] || [])[0] || r.status;
        toast(`#${id}: ${label}${r.error_code ? ' (' + evalErrVN(r.error_code) + ')' : ''}`, r.status === 'ACTIVE' ? 'success' : 'error');
      }
      if (accDrawerId === Number(id)) openAccountDrawer(Number(id), true);
    } else {
      toast(res.message || 'Lỗi', 'error');
      if (p) { p.eval_status = p.eval_prev || 'UNCHECKED'; updateCardEval(id, false); }
    }
  } finally {
    if (btn) btn.disabled = false;
  }
}
const ACC_STAGE_VN = { NEW: 'Mới', OBSERVING: 'Theo dõi', STABLE: 'Ổn định', READY_FOR_CHANNEL: 'Sẵn sàng mở kênh', CHANNEL_EXISTS: 'Đã có kênh', REVIEW_REQUIRED: 'Cần xem lại', ACTION_REQUIRED: 'Cần xử lý', UNAVAILABLE: 'Không khả dụng' };
const ACC_REASON_VN = { channel_exists: 'Đã có YouTube channel', recovery_required: 'Cần khôi phục tài khoản', security_challenge: 'Gặp kiểm tra bảo mật', login_or_session_unusable: 'Đăng nhập/phiên không dùng được', too_many_consecutive_failures: 'Lỗi liên tiếp quá nhiều', insufficient_history: 'Chưa đủ dữ liệu theo dõi', stability_below_review: 'Ổn định dưới ngưỡng xem lại', confidence_below_ready: 'Chưa đủ tin cậy', meets_ready_policy: 'Đạt chính sách nội bộ', stable_but_below_ready: 'Ổn nhưng chưa đạt sẵn sàng' };
let accDrawerId = 0;
function closeAccountDrawer() {
  accDrawerId = 0;
  const w = $('acc-drawer-wrap');
  if (w) w.classList.add('hidden');
}
const EVAL_STAGE_VN = { INITIALIZING: 'Đang khởi tạo...', QUEUED: 'Đang chờ...', PRECHECK: 'Đang kiểm tra điều kiện...', PRECHECK_BROWSER: 'Đang kiểm tra trình duyệt...', CONNECTING: 'Đang kết nối trình duyệt...', CHECK_CDP: 'Đang kết nối CDP...', CHECK_PROXY_NETWORK: 'Đang kiểm tra proxy...', CHECKING_SESSION: 'Đang kiểm tra phiên...', CHECK_SESSION: 'Đang kiểm tra phiên...', CHECKING_LOGIN: 'Đang kiểm tra đăng nhập...', CHECK_LOGIN: 'Đang kiểm tra đăng nhập...', CHECKING_PLATFORM: 'Đang kiểm tra YouTube...', CHECK_YOUTUBE: 'Đang kiểm tra YouTube...', CHECKING_CHANNEL: 'Đang kiểm tra kênh...', CHECK_CHANNEL: 'Đang kiểm tra kênh...', CHECK_SECURITY: 'Đang kiểm tra bảo mật...', FINALIZING: 'Đang tổng hợp...', FINALIZE: 'Đang tổng hợp...', DONE: 'Hoàn tất', SUCCESS: 'Hoàn tất', FAILED: 'Thất bại', CANCELLED: 'Đã hủy' };
async function openAccountDrawer(id, keepOpen) {
  accDrawerId = Number(id);
  try {
    const res = await getJson(api + `accounts.php?action=get&id=${id}`);
    if (!res.ok) { toast(res.message || 'Lỗi', 'error'); return; }
    const st = res.data.state, p = res.data.profile || {}, hist = res.data.history || [];
    const vn = (arr) => (arr || []).map(x => ACC_REASON_VN[x] || x);
    const row = (k, v) => `<div class="acc-item"><span>${k}</span><strong>${v}</strong></div>`;
    const yn = (b) => b ? '<span class="badge badge-danger">Có</span>' : '<span class="badge badge-muted">Không</span>';
    const [sLabel, sCls, sDot] = ACC_STAGES[st.stage] || ACC_STAGES.NEW;
    const ev = st.eval_status || 'UNCHECKED';
    const reasons = vn(hist[0] ? hist[0].reasons : []);
    const warns = vn(hist[0] ? hist[0].warnings : []);
    const sig = (v, map) => {
      const lbl = (EVAL_UI_VN[v] || v || 'Chưa kiểm tra');
      const ok = ['YES', 'VALID', 'AVAILABLE'].includes(v);
      return `<span class="badge ${ok ? 'badge-ok' : (v === 'NOT_CHECKED' || v === 'CHECK_FAILED' ? 'badge-muted' : 'badge-review')}">${ok ? '✓' : '•'} ${escapeHtml(lbl)}</span>`;
    };
    const completedTs = st.last_completed_at || st.last_attempt_at || st.last_checked_at || null;
    const successTs = st.last_successful_check_at || null;
    const attemptRes = st.last_attempt_status || null;
    const STAGE_ORDER = ['PRECHECK_BROWSER', 'CHECK_CDP', 'CHECK_PROXY_NETWORK', 'CHECK_SESSION', 'CHECK_LOGIN', 'CHECK_YOUTUBE', 'CHECK_CHANNEL', 'CHECK_SECURITY'];
    const STAGE_VN_SHORT = { PRECHECK_BROWSER: 'Browser', CHECK_CDP: 'CDP', CHECK_PROXY_NETWORK: 'Proxy', CHECK_SESSION: 'Session', CHECK_LOGIN: 'Login', CHECK_YOUTUBE: 'YouTube', CHECK_CHANNEL: 'Channel', CHECK_SECURITY: 'Security' };
    const stageIcon = (r) => r === 'PASS' ? '<span class="st-healthy">✓ Pass</span>' : (r === 'FAIL' ? '<span class="st-critical">! Fail</span>' : (r === 'TIMEOUT' ? '<span class="st-warning">◌ Timeout</span>' : (r === 'NOT_APPLICABLE' ? '<span class="muted">— N/A</span>' : '<span class="muted">— Chưa chạy</span>')));
    const renderStages = (list, live) => {
      const byName = {};
      (list || []).forEach(s => { byName[s.stage] = s; });
      return `<table class="data-table"><tbody>` + STAGE_ORDER.map(n => {
        const s = byName[n];
        // NO_CHANNEL la PASS value (khong phai FAIL) (§45)
        let cell;
        if (s && n === 'CHECK_CHANNEL' && s.result === 'PASS' && s.detail === 'no_channel') {
          cell = '<span class="st-healthy">✓ No channel</span>';
        } else if (s && n === 'CHECK_CHANNEL' && s.result === 'TIMEOUT' && s.code === 'CHANNEL_TIMEOUT') {
          cell = '<span class="st-warning">◌ Timeout</span> <small class="muted">CHANNEL_TIMEOUT</small>';
        } else {
          cell = s ? stageIcon(s.result) + (s.code ? ` <small class="muted">${escapeHtml(s.code)}</small>` : '')
          : (live ? '<span class="st-info">◌ Checking</span>' : '<span class="muted">— Chưa chạy</span>');
        }
        return `<tr><td>${STAGE_VN_SHORT[n]}</td><td title="${escapeAttr(s && s.detail ? s.detail : '')}">${cell}</td></tr>`;
      }).join('') + `</tbody></table>`;
    };
    $('acc-drawer-title').textContent = 'ACCOUNT EVALUATION — ' + (p.name || ('#' + id));
    // Runtime rieng, Main last-known, Latest attempt + confidence
    const knownLbl = st.last_known_status ? ((EVAL_STATUS[st.last_known_status] || [])[0] || st.last_known_status) : 'Chưa xác định';
    const attLbl = st.last_attempt_status ? ` · Lần thử: ${st.last_attempt_status}` : '';
    const confLbl = st.eval_confidence ? ` · Tin cậy: ${st.eval_confidence}` : '';
    let head = `<div class="acc-head" style="margin-bottom:6px"><span class="meta-label">Runtime: Chrome ${p.status === 'running' ? '● Đang chạy' : '● Dừng'}${p.proxy_host ? ' · Proxy ' + escapeHtml(p.proxy_host) : ''}</span></div>`
      + `<div class="acc-head" style="margin-bottom:6px"><span class="meta-label">Kênh: <strong>${escapeHtml(knownLbl)}</strong></span>`
      + `<span class="acc-stage">${evalBadge(ev)}</span></div>`
      + `<div class="sync-label">Lần thử gần nhất${attLbl}${confLbl}</div>`;
    if (ev === 'CHECKING') {
      const live = st.eval_stage_live && EVAL_STAGE_VN[st.eval_stage_live] ? EVAL_STAGE_VN[st.eval_stage_live] : 'Đang kiểm tra...';
      head += `<div class="eval-live" id="eval-live-stage">◌ ${escapeHtml(live)}</div>`;
      head += `<div class="sync-label" style="margin-top:6px">STAGES</div><div id="eval-live-stages">${renderStages(st.eval_stages, true)}</div>`;
      pollEvalStage(id);
    } else if (st.eval_stages && st.eval_stages.length) {
      head += `<div class="sync-label" style="margin-top:6px">STAGES (lần chạy cuối)</div>` + renderStages(st.eval_stages, false);
    }
    if (ev === 'ERROR' && st.last_known_status) {
      head += `<div class="eval-prev">Trạng thái gần nhất: ${(EVAL_STATUS[st.last_known_status] || [])[0] || st.last_known_status}`
        + (st.last_successful_check_at ? ` (${accRelTime(st.last_successful_check_at)})` : '') + `</div>`;
    }
    if (st.last_attempt_status && st.last_attempt_status !== 'SUCCESS') {
      head += `<div class="eval-prev">Lần thử: ${st.last_attempt_status}`
        + (st.last_error_code ? ` · ${escapeHtml(evalErrVN(st.last_error_code))}` : '') + `</div>`;
    } else if (st.last_error) {
      head += `<div class="eval-prev" title="${escapeAttr(st.last_error)}">⚠ Lần kiểm tra mới nhất thất bại</div>`;
    }
    const attemptLine = attemptRes ? ` · Kết quả: ${attemptRes}` : '';
    const successLine = successTs ? `<div class="sync-label">Lần kiểm tra thành công: ${relSpan(successTs)}</div>` : '';
    const changedLine = st.last_status_changed_at ? `<div class="sync-label">Trạng thái từ: ${relSpan(st.last_status_changed_at)}</div>` : '';
    // Stale badge (§35-§36): verified qua 24h => "Cần kiểm tra lại", khong coi cu nhu current
    let staleLine = '';
    try {
      const vTs = st.auth_verified_at || successTs || null;
      if (vTs) {
        const t = parseServerTime(vTs);
        if (!isNaN(t) && (Date.now() - t) > 24 * 3600 * 1000) {
          staleLine = `<div class="eval-prev">Đã xác minh ${formatRelativeTime(vTs)} · <strong>Cần kiểm tra lại</strong></div>`;
        }
      } else if (st.needs_recheck) {
        staleLine = `<div class="eval-prev"><strong>Cần đánh giá lại</strong> (kết quả cũ chưa có bằng chứng xác minh)</div>`;
      }
    } catch (e) {}
    const gLbl = st.google_auth_status ? `${AUTH_VN[st.google_auth_status] || st.google_auth_status}${st.google_auth_confidence ? ' · ' + st.google_auth_confidence : ''}` : null;
    const yLbl = st.youtube_auth_status ? `${AUTH_VN[st.youtube_auth_status] || st.youtube_auth_status}${st.youtube_auth_confidence ? ' · ' + st.youtube_auth_confidence : ''}` : null;
    // TỔNG QUAN (§43-§44): Google/Mail + YouTube + Kênh + Trạng thái (derive tu summary)
    const ytStatusBadge = (() => {
      const ys = st.youtube_status || null;
      if (ys === 'ACCESSIBLE') return '<span class="badge badge-ok">✓ Truy cập được</span>';
      if (ys === 'LOGIN_REQUIRED') return '<span class="badge badge-review">⚠ Cần đăng nhập</span>';
      if (ys === 'UNAVAILABLE') return '<span class="badge badge-danger">● Không truy cập được</span>';
      return '<span class="badge badge-muted">— Chưa kiểm tra</span>';
    })();
    const READINESS_VN = { NEED_LOGIN: 'Cần đăng nhập', NEED_VERIFICATION: 'Cần xác minh', READY_TO_CREATE_CHANNEL: 'Sẵn sàng tạo kênh', CHANNEL_CREATED: 'Đã có kênh', CHANNEL_NEEDS_ATTENTION: 'Kênh cần chú ý', CHECK_REQUIRED: 'Cần kiểm tra lại' };
    const readinessLbl = st.readiness_status ? (READINESS_VN[st.readiness_status] || st.readiness_status) : null;
    // Invariant: stage Login PASS nhưng summary NEED_LOGIN => log (§22)
    try {
      const loginPass = (st.eval_stages || []).some(s => (s.stage === 'CHECK_LOGIN' || s.stage === 'CHECK_YOUTUBE') && s.result === 'PASS');
      if (loginPass && st.account_channel_state === 'NEED_LOGIN') console.error('[EVAL INVARIANT] drawer: login PASS but NEED_LOGIN, profile=' + id);
    } catch (e) {}
    $('acc-drawer-body').innerHTML = head
      + `<div class="sync-label">Lần kiểm tra gần nhất: ${completedTs ? relSpan(completedTs) : 'chưa có'}${attemptLine}</div>`
      + successLine + changedLine + staleLine
      + `<div class="sync-label" style="margin-top:6px">TỔNG QUAN</div><div class="acc-grid">`
      + row('Google/Mail', authBadge(st.auth_status, st.login_state))
      + row('YouTube', ytStatusBadge)
      + row('Kênh', channelPresenceBadge(st))
      + (readinessLbl ? row('Trạng thái', `<strong>${escapeHtml(readinessLbl)}</strong>`) : '')
      + `</div><div class="sync-label" style="margin-top:6px">CHI TIẾT</div><div class="acc-grid">`
      + row('Phiên', sig(st.session_state === 'ok' ? 'VALID' : (st.session_state === 'failed' ? 'INVALID' : (st.session_state || 'NOT_CHECKED'))))
      + row('Bảo mật', st.security_challenge ? yn(true) : '<span class="badge badge-ok">✓ Bình thường</span>')
      + `</div><div class="sync-label" style="margin-top:6px">NỀN TẢNG</div><div class="acc-grid">`
      + row('Giai đoạn', `${sLabel}`) + row('Quản lý', `${st.managed_days ?? '-'} ngày`)
      + row('Ổn định', `${st.stability} / 100`) + row('Tin cậy', `${st.confidence} / 100`)
      + `</div><div class="sync-label" style="margin-top:6px">THỐNG KÊ</div><div class="acc-grid">`
      + row('Check thành công', st.success_count) + row('Check thất bại', st.fail_count)
      + row('Lỗi liên tiếp', st.consec_fails)
      + (st.last_duration_ms != null ? row('Thời gian check', `${st.last_duration_ms}ms`) : '')
      + `</div>`
      + (reasons.map(r => `<p class="reason-ok">✓ ${escapeHtml(r)}</p>`).join('') || '')
      + (warns.length ? `<div class="sync-label" style="margin-top:6px">Cảnh báo:</div>` + warns.map(w => `<p class="reason-warn">⚠ ${escapeHtml(w)}</p>`).join('') : '')
      + `<div class="sync-label" style="margin-top:8px">Lịch sử (${hist.length}):</div>`
      + `<div class="acc-hist" style="display:none"><table class="data-table"><thead><tr><th>Thời gian</th><th>Trạng thái</th><th>Lý do</th></tr></thead><tbody>`
      + (hist.map(h => `<tr><td class="mono">${escapeHtml(h.checked_at || '')}</td><td>${evalBadge(h.eval_status || 'UNCHECKED')}</td><td><small>${escapeHtml(h.reason || vn(h.reasons).join('; '))}</small></td></tr>`).join('') || '<tr><td colspan="3">Chưa có lịch sử</td></tr>')
      + `</tbody></table></div>`;
    setDrawerFoot('Kiểm tra lại', 'Mở Profile', 'Lịch sử',
      async () => { await evaluateOneProfile(id); },
      () => { closeAccountDrawer(); openProfile(id); },
      () => {
        const h = document.querySelector('#acc-drawer-body .acc-hist');
        if (h) h.style.display = h.style.display === 'none' ? '' : 'none';
      });
    $('acc-drawer-wrap').classList.remove('hidden');
  } catch (e) {
    toast('Lỗi tải chi tiết', 'error');
  }
}
// Poll stage khi panel dang CHECKING (1s/lan, dung khi DONE/dong panel)
async function pollEvalStage(id) {
  for (let i = 0; i < 45; i++) {
    if (accDrawerId !== Number(id)) return;
    const el = $('eval-live-stage');
    if (!el) return;
    await sleep(1000);
    if (accDrawerId !== Number(id)) return;
    try {
      const r = await getJson(api + `accounts.php?action=eval_stage&id=${id}`);
      const s = r.ok && r.data ? r.data.stage : null;
      if (!s || s === 'DONE') {
        if (accDrawerId === Number(id)) openAccountDrawer(Number(id), true);
        return;
      }
      const lbl = EVAL_STAGE_VN[s] || 'Đang kiểm tra...';
      const el2 = $('eval-live-stage');
      if (el2) el2.textContent = '◌ ' + lbl;
    } catch (e) { return; }
  }
}
// ============ TAB SESSION (panel trong drawer chung) ============
let tabsPanelId = 0;
async function openTabsPanel(id) {
  tabsPanelId = id;
  const p = profiles.find(x => x.id === id);
  $('acc-drawer-title').textContent = 'TABS — ' + (p ? p.name : ('#' + id));
  $('acc-drawer-body').innerHTML = '<p class="muted">Đang tải tabs...</p>';
  setDrawerFoot('Lưu phiên', 'Khôi phục', 'Xóa phiên', saveTabsPanel, restoreTabsPanel, clearTabsPanel);
  $('acc-drawer-wrap').classList.remove('hidden');
  await refreshTabsPanel();
}
function setDrawerFoot(t1, t2, t3, f1, f2, f3) {
  const b1 = $('acc-drawer-eval'), b2 = $('acc-drawer-open'), b3 = $('acc-drawer-hist');
  if (b1) { b1.textContent = t1; b1.onclick = f1; b1.disabled = false; }
  if (b2) { b2.textContent = t2; b2.onclick = f2; }
  if (b3) { b3.textContent = t3; b3.onclick = f3; }
}
async function refreshTabsPanel() {
  const id = tabsPanelId;
  if (!id) return;
  try {
    const [live, saved] = await Promise.all([
      getJson(api + `tabsessions.php?action=tabs&id=${id}`),
      getJson(api + `tabsessions.php?action=get&id=${id}`)
    ]);
    const tabs = (live.ok && live.data && live.data.tabs) ? live.data.tabs : [];
    const running = !!(live.ok && live.data && live.data.running);
    const cur = saved.ok && saved.data ? saved.data.current : null;
    const good = saved.ok && saved.data ? saved.data.last_good : null;
    const esc2 = (s) => escapeHtml(s || '');
    let html = `<div class="sync-url-row" style="padding:0 0 8px"><button class="btn btn-sm" onclick="refreshTabsPanel()">↻ Làm mới</button>`
      + `<span class="summary-text">${running ? tabs.length + ' tab đang mở' : 'Chrome chưa chạy'}</span></div>`;
    html += `<div class="sync-label">Mở nhiều tab (mỗi dòng 1 URL):</div>`
      + `<textarea id="tab-batch-urls" rows="3" style="width:100%" placeholder="https://...\nhttps://..."></textarea>`
      + `<div class="sync-url-row" style="padding:6px 0">`
      + `<button class="btn btn-sm btn-primary" id="tab-batch-open-btn" onclick="openTabsBatch()">Mở hàng loạt</button>`
      + `<button class="btn btn-sm btn-danger" onclick="closeAllTabsBatch(true)">Đóng hết (giữ 1)</button>`
      + `<button class="btn btn-sm btn-danger" onclick="closeAllTabsBatch(false)">Đóng hết</button>`
      + `<span class="summary-text" id="tab-batch-progress"></span></div>`;
    html += tabs.length
      ? tabs.map((t, i) => `<div class="sync-tab-item"><span>${i === (live.data.active || 0) ? '▶' : '○'}</span>`
        + `<div class="sync-tab-info"><div class="sync-tab-title">${esc2(t.title)}</div>`
        + `<div class="sync-tab-url">${esc2(t.host || t.url)}</div></div></div>`).join('')
      : '<div class="sync-empty">Không có tab nào (mở Chrome để xem trực tiếp)</div>';
    const fmt = (s) => s ? `<div class="sync-label">Đã lưu: ${esc2(s.saved_at)} (${(s.tabs || []).length} tabs)</div>` : '';
    html += fmt(cur) + fmt(good);
    $('acc-drawer-body').innerHTML = html;
  } catch (e) {
    $('acc-drawer-body').innerHTML = '<p class="muted">Lỗi tải tabs</p>';
  }
}
async function saveTabsPanel() {
  if (!tabsPanelId) return;
  const res = await sendJson(api + 'tabsessions.php?action=save', { id: tabsPanelId });
  toast(res.ok ? `Đã lưu ${res.count} tabs (${res.ms}ms)` : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  refreshTabsPanel();
  refreshAll();
}
async function restoreTabsPanel() {
  if (!tabsPanelId) return;
  const res = await sendJson(api + 'tabsessions.php?action=restore', { id: tabsPanelId });
  toast(res.ok ? `Đã khôi phục ${res.count} tabs (${res.ms}ms)` : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  refreshTabsPanel();
}
// Batch open: 1 request duy nhat, UI phan hoi ngay + poll progress (khong refresh tung tab)
async function openTabsBatch() {
  if (!tabsPanelId) return;
  const ta = $('tab-batch-urls');
  const urls = (ta ? ta.value : '').split('\n').map(s => s.trim()).filter(Boolean);
  if (!urls.length) { toast('Nhập ít nhất 1 URL', 'error'); return; }
  const btn = $('tab-batch-open-btn');
  const prog = $('tab-batch-progress');
  if (btn) btn.disabled = true; // chong double-click
  if (prog) prog.textContent = `Đang mở 0/${urls.length}...`;
  try {
    const res = await sendJson(api + 'tabsessions.php?action=open_batch', { id: tabsPanelId, urls, preserve_order: true, activate: 'LAST' });
    if (prog) prog.textContent = '';
    toast(res.ok ? `Đã mở ${res.count}/${urls.length} tabs (${res.ms}ms)` : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  } catch (e) {
    toast('Lỗi kết nối', 'error');
  } finally {
    if (btn) btn.disabled = false;
    if (ta) ta.value = '';
    refreshTabsPanel(); // 1 render cuoi
    refreshAll(); // cap nhat tab count 1 lan
  }
}
async function closeAllTabsBatch(keepOne) {
  if (!tabsPanelId) return;
  const prog = $('tab-batch-progress');
  if (prog) prog.textContent = 'Đang đóng...';
  try {
    const res = await sendJson(api + 'tabsessions.php?action=close_all', { id: tabsPanelId, keep_one: !!keepOne });
    if (prog) prog.textContent = '';
    toast(res.ok ? `Đã đóng ${res.closed} tabs${res.ms ? ' (' + res.ms + 'ms)' : ''}` : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  } catch (e) {
    toast('Lỗi kết nối', 'error');
  } finally {
    refreshTabsPanel();
    refreshAll();
  }
}
async function clearTabsPanel() {
  if (!tabsPanelId) return;
  confirmDelete('Xóa session tabs đã lưu của kênh này?', async () => {
    const res = await sendJson(api + 'tabsessions.php?action=clear', { id: tabsPanelId });
    toast(res.ok ? 'Đã xóa phiên' : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
    refreshTabsPanel();
    refreshAll();
  });
}
function assignProxySelected() {
  const ids = getSelectedIds();
  if (!ids.length) { toast('Chưa chọn kênh nào, bấm lại "Gán proxy" sau khi chọn', 'error'); return; }
  pendingProxyAssignProfileIds = ids;
  populateBulkProxySelect();
  setBulkProxyMode('single');
  // Che do THAY THE: kenh da co proxy se bi ghi de -> bao ro truoc khi gán
  const byId = {};
  profiles.forEach(p => { byId[p.id] = p; });
  const had = ids.filter(id => byId[id] && byId[id].proxy_host);
  const hint = $('bulk-proxy-count');
  if (had.length) {
    hint.innerHTML = `Sẽ <strong>THAY THẾ</strong> proxy cho ${ids.length} kênh đã chọn `
      + `(<span style="color:var(--red)">${had.length} kênh đang có proxy sẽ bị ghi đè</span>).`;
  } else {
    hint.textContent = `Sẽ gán proxy cho ${ids.length} kênh đã chọn (đang chưa có proxy).`;
  }
  const saveBtn = $('bulk-proxy-save-btn');
  if (saveBtn) saveBtn.textContent = had.length ? '⇄ Thay thế proxy' : 'Gán proxy';
  $('bulk-proxy-list').value = '';
  $('bulk-proxy-preview').textContent = '';
  showModal('bulk-proxy-modal');
}
function setBulkProxyMode(mode) {
  bulkProxyMode = mode;
  const isSingle = mode === 'single';
  $('bp-mode-single').classList.toggle('active', isSingle);
  $('bp-mode-list').classList.toggle('active', !isSingle);
  $('bp-single-fields').classList.toggle('hidden', !isSingle);
  $('bp-list-fields').classList.toggle('hidden', isSingle);
}
function populateBulkProxySelect(selectedId) {
  const sel = $('bulk-proxy-select');
  sel.innerHTML = '<option value="">Không dùng proxy (gỡ proxy)</option>' +
    proxies.map(p =>
      `<option value="${p.id}" ${p.id == selectedId ? 'selected' : ''}>${escapeHtml(p.name)} (${escapeHtml(p.host)}:${p.port}) [${escapeHtml((p.protocol || 'http').toUpperCase())}]</option>`
    ).join('');
}
function previewBulkProxyList() {
  const el = $('bulk-proxy-preview');
  if (!el) return;
  const defProto = ($('bulk-proxy-protocol') || {}).value || 'http';
  const lines = ($('bulk-proxy-list').value || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
  const counts = {};
  let valid = 0;
  lines.forEach(ln => {
    const m = ln.match(/^(https?|socks4|socks5|ssh):\/\//i);
    const parts = ln.replace(/^(https?|socks4|socks5|ssh):\/\//i, '').split(':');
    const host = (parts[0] || '').trim();
    const port = parseInt(parts[1], 10);
    if (!host || !(port > 0)) return;
    const proto = (m ? m[1] : defProto).toLowerCase();
    counts[proto] = (counts[proto] || 0) + 1;
    valid++;
  });
  el.textContent = lines.length
    ? `${valid}/${lines.length} dòng hợp lệ` + (valid ? ` (${Object.entries(counts).map(([k, v]) => k.toUpperCase() + '×' + v).join(', ')})` : '')
    : '';
}
async function saveBulkProxy() {
  if (!pendingProxyAssignProfileIds || !pendingProxyAssignProfileIds.length) return;
  const ids = pendingProxyAssignProfileIds;
  pendingProxyAssignProfileIds = null;

  // MODE LIST: paste danh sách proxy -> gán theo thứ tự (ghi đè proxy cũ)
  if (bulkProxyMode === 'list') {
    const list = $('bulk-proxy-list').value;
    if (!list.trim()) {
      toast('Nhập danh sách proxy trước', 'error');
      pendingProxyAssignProfileIds = ids;
      return;
    }
    const protocol = ($('bulk-proxy-protocol') || {}).value || 'http';
    const res = await sendJson(api + 'profiles.php?action=assign_proxy_list_bulk', { ids, list, protocol });
    if (res.ok) {
      const rep = res.replaced ? ` (thay thế ${res.replaced})` : '';
      toast(`Đã gán proxy cho ${res.updated}/${ids.length} kênh${rep} (${res.proxy_count} proxy)`, 'success');
      closeModal('bulk-proxy-modal');
    } else {
      toast(res.message || 'Lỗi', 'error');
      pendingProxyAssignProfileIds = ids;
      return;
    }
    loadProxies();
    refreshAll();
    return;
  }

  // MODE SINGLE: chọn 1 proxy (ghi đè proxy cũ)
  const proxyId = $('bulk-proxy-select').value || null;
  const res = await sendJson(api + 'profiles.php?action=assign_proxy_bulk', { ids, proxy_id: proxyId });
  if (res.ok) {
    let msg;
    if (!proxyId) msg = `Đã gỡ proxy của ${res.updated || 0} kênh`;
    else if (res.replaced) msg = `Đã thay thế proxy cho ${res.replaced}/${res.updated || 0} kênh`;
    else msg = `Đã gán proxy cho ${res.updated || 0} kênh`;
    toast(msg, 'success');
  } else toast(res.message || 'Lỗi', 'error');
  if (res.ok) closeModal('bulk-proxy-modal');
  refreshAll();
}


function buildProxyRow(p) {
  const has = p.proxy_host;
  if (has) {
    const st = p.proxy_status === 'alive' ? 'status-alive' : (p.proxy_status === 'dead' ? 'status-dead' : 'status-unknown');
    return `
      <div class="meta-row proxy-row">
        <span class="meta-label">Proxy</span>
        <div style="display:flex;align-items:center;gap:6px">
          <span class="meta-value mono ${st}">${escapeHtml(p.proxy_host)}:${p.proxy_port}</span>
          <button class="btn btn-xs" onclick="editProfileProxy(${p.id})" title="Đổi hoặc sửa proxy của kênh">✎</button>
          <button class="btn btn-xs btn-danger" onclick="removeProfileProxy(${p.id})" title="Gỡ proxy khỏi kênh">×</button>
        </div>
      </div>`;
  }
  return `
    <div class="meta-row proxy-row">
      <span class="meta-label">Proxy</span>
      <div style="display:flex;align-items:center;gap:6px">
        <span class="meta-value status-unknown">Không dùng</span>
        <button class="btn btn-xs" onclick="addProfileProxy(${p.id})" title="Thêm proxy cho kênh">+ Thêm</button>
      </div>
    </div>`;
}

function liveProfileStatus(p) {
  const op = pendingOps.get(p.id);
  if (op === 'opening') return { state: 'busy', label: 'Đang mở…' };
  if (op === 'closing') return { state: 'busy', label: 'Đang đóng…' };
  const st = p.status || 'stopped';
  if (st === 'running') return { state: 'running', label: 'Đang chạy' };
  if (st === 'error') return { state: 'error', label: 'Lỗi' };
  return { state: 'stopped', label: 'Dừng' };
}

function platLabel(p) {
  return { youtube: 'YouTube', tiktok: 'TikTok', facebook: 'Facebook', other: 'Khác' }[p] || 'YouTube';
}

// ============ ACCOUNT EVALUATION (hien thi) ============
const ACC_STAGES = {
  NEW: ['Mới', 'badge-warn', '⚪'], OBSERVING: ['Đang theo dõi', 'badge-info', '🔵'],
  STABLE: ['Ổn định', 'badge-ok', '🟢'], READY_FOR_CHANNEL: ['Sẵn sàng tạo kênh', 'badge-ready', '🟢'],
  CHANNEL_EXISTS: ['Đã có kênh', 'badge-channel', '🟣'], REVIEW_REQUIRED: ['Cần kiểm tra', 'badge-review', '🟡'],
  ACTION_REQUIRED: ['Cần xử lý', 'badge-danger', '🔴'], UNAVAILABLE: ['Không khả dụng', 'badge-muted', '⚫']
};
function accStageBadge(stage, dot) {
  const [label, cls, emoji] = ACC_STAGES[stage] || ACC_STAGES.NEW;
  return `<span class="badge ${cls}">${dot ? emoji + ' ' : ''}${label}</span>`;
}
function accBar(v, tip) {
  v = Math.max(0, Math.min(100, parseInt(v, 10) || 0));
  const cls = v >= 80 ? 'ok' : (v >= 50 ? 'mid' : 'low');
  return `<span class="acc-bar" title="${escapeAttr(tip)}"><i class="${cls}" style="width:${v}%"></i></span>`;
}
function accDaysVN(p) {
  return p.acc_days != null ? `${p.acc_days} ngày` : '–';
}
const EVAL_UI_VN = { YES: 'Đã đăng nhập', NO: 'Chưa đăng nhập', VALID: 'Hợp lệ', INVALID: 'Không hợp lệ', AVAILABLE: 'Truy cập được', UNAVAILABLE: 'Không truy cập được', NOT_CHECKED: 'Chưa kiểm tra', CHECK_FAILED: 'Không kiểm tra được' };
function evalBlockInner(p) {
  // Card dung last_completed_at (lan xong gan nhat, ke ca failed/timeout)
  const es = p.eval_status || 'UNCHECKED';
  const completed = p.eval_completed || null;
  let sub = '';
  if (es === 'CHECKING') {
    const st = p.eval_stage ? (EVAL_STAGE_VN[p.eval_stage] || null) : null;
    sub = `<div class="eval-prev">◌ ${st ? escapeHtml(st) : 'Đang chờ'}...</div>`;
    if (completed) sub += `<div class="eval-prev">Lần trước: ${relSpan(completed)}</div>`;
    if (p.eval_prev && p.eval_prev !== 'CHECKING' && p.eval_prev !== 'UNCHECKED') {
      sub += `<div class="eval-prev">Trước đó: ${(EVAL_STATUS[p.eval_prev] || [])[0] || p.eval_prev}</div>`;
    }
  } else if (p.eval_attempt_status === 'FAILED' || p.eval_attempt_status === 'TIMEOUT') {
    // Attempt moi loi nhung giu last_known: hien last_known + warning (khong phong to loi)
    const known = p.eval_known && p.eval_known !== 'UNCHECKED' ? p.eval_known : null;
    if (known) {
      sub = `<div class="eval-prev">⚠ Kiểm tra mới nhất thất bại</div>`;
    } else {
      sub = `<div class="eval-prev">⚠ Không kiểm tra được</div>`;
    }
    if (p.eval_error_code) sub += `<div class="eval-prev" title="${escapeAttr(p.eval_error_code)}">ⓘ ${escapeHtml(evalErrVN(p.eval_error_code))}</div>`;
  } else if (es === 'ERROR' && p.eval_known && p.eval_known !== 'ERROR' && p.eval_known !== 'UNCHECKED') {
    sub = `<div class="eval-prev">⚠ Gần nhất: ${(EVAL_STATUS[p.eval_known] || [])[0] || p.eval_known}</div>`;
  }
  const err = (es === 'ERROR' && !p.eval_known && p.eval_error) ? `<div class="eval-prev" title="${escapeAttr(p.eval_error)}">⚠ Lần này thất bại</div>` : '';
  const stage = p.acc_stage || 'NEW';
  const [, , dot] = ACC_STAGES[stage] || ACC_STAGES.NEW;
  const stabTip = `Ổn định (điểm nội bộ do hệ thống tính toán, không phải chỉ số chính thức của YouTube): ${p.acc_stability ?? '-'}/100\nĐăng nhập: ${p.acc_login ?? '?'}\nPhiên: ${p.acc_session ?? '?'}\nYouTube: ${p.acc_youtube ?? '?'}`;
  // Badge kenh uu tien auth truoc presence (§11): chua login thi khong bao gio "Da co kenh"
  const ch = channelPresenceChip(p);
  // Summary tong hop (§16-§19): readiness + partial warning, derive tu 1 nguon
  const sum = evalSummary(p);
  const READINESS_VN = { NEED_LOGIN: 'Cần đăng nhập', NEED_VERIFICATION: 'Cần xác minh', READY_TO_CREATE_CHANNEL: 'Sẵn sàng tạo kênh', CHANNEL_CREATED: 'Đã có kênh', CHANNEL_NEEDS_ATTENTION: 'Kênh cần chú ý', CHECK_REQUIRED: 'Cần kiểm tra lại' };
  let sumLine = '';
  if (p.eval_attempt_status === 'PARTIAL') {
    // Technical: giu verified + canh bao attempt (§19), KHONG "Can dang nhap"
    sumLine = `<div class="eval-prev">⚠ Kiểm tra mới nhất chưa hoàn tất (kênh quá thời gian)</div>`;
    if (p.last_verified_channel_presence === 'NO_CHANNEL' || p.last_known_presence === 'NO_CHANNEL') {
      sumLine += `<div class="eval-prev">Xác minh lần cuối: ○ Chưa có kênh${p.last_channel_verified_at ? ' · ' + formatRelativeTime(p.last_channel_verified_at) : ''}</div>`;
    } else if (p.last_verified_auth_status === 'SIGNED_IN' || p.auth_status === 'LOGGED_IN') {
      sumLine += `<div class="eval-prev">Xác minh lần cuối: ✓ Đã đăng nhập${p.last_auth_verified_at ? ' · ' + formatRelativeTime(p.last_auth_verified_at) : ''}</div>`;
    }
  } else if (sum.state === 'SIGNED_IN_NO_CHANNEL') {
    sumLine = `<div class="eval-prev">Trạng thái: <strong>${READINESS_VN.READY_TO_CREATE_CHANNEL}</strong></div>`;
  } else if (sum.state === 'TECHNICAL_CHECK_FAILED' && p.auth_status === 'LOGGED_IN') {
    sumLine = `<div class="eval-prev">⚠ Chưa xác định trạng thái kênh</div>`;
  }
  return `<div class="acc-head"><span class="meta-label">Đánh giá · ${relSpan(completed, 'chưa kiểm tra')}</span>`
    + `<span class="acc-stage">${evalBadgeFor(p)}</span></div>`
    + sub + err + sumLine
    + `<div class="acc-meter"><span>Ổn định</span><strong>${p.acc_stability ?? '-'}</strong>${accBar(p.acc_stability, stabTip)}${ch}</div>`
    + `<div class="acc-head" style="margin-top:4px"><span class="meta-label">Account · ${accDaysVN(p)}</span>`
    + `<span class="acc-stage">${dot} ${accStageBadge(stage)}</span></div>`;
}
function buildAccountRow(p) {
  return `<div class="acc-block eval-block" onclick="openAccountDrawer(${p.id})" title="Xem chi tiết đánh giá">`
    + evalBlockInner(p) + `</div>`;
}
const AUTH_VN = { LOGGED_IN: 'Đã đăng nhập', LOGGED_OUT: 'Chưa đăng nhập', LOGIN_REQUIRED: 'Cần đăng nhập', VERIFICATION_REQUIRED: 'Cần xác minh', UNKNOWN: 'Chưa xác định', CHECK_FAILED: 'Không kiểm tra được' };
function authBadge(auth, loginState) {
  const a = auth || (loginState === 'ok' ? 'LOGGED_IN' : (loginState === 'failed' ? 'LOGIN_REQUIRED' : null));
  if (a === 'LOGGED_IN') return '<span class="badge badge-ok">✓ Đã đăng nhập</span>';
  if (a === 'LOGIN_REQUIRED') return '<span class="badge badge-review">⚠ Cần đăng nhập</span>';
  if (a === 'VERIFICATION_REQUIRED') return '<span class="badge badge-warn">⚠ Cần xác minh</span>';
  if (a === 'LOGGED_OUT') return '<span class="badge badge-review">○ Chưa đăng nhập</span>';
  return '<span class="badge badge-muted">— Chưa xác định</span>';
}
function channelPresenceBadge(st) {
  const auth = st.auth_status || null;
  const pres = st.channel_presence || 'NOT_CHECKED';
  if (auth === 'LOGIN_REQUIRED') return '<span class="badge badge-review">⚠ Cần đăng nhập</span>';
  if (auth === 'VERIFICATION_REQUIRED') return '<span class="badge badge-warn">⚠ Cần xác minh</span>';
  // UNKNOWN / CHECK_FAILED => "— Chưa kiểm tra", KHONG suy logout/login (§43-§44)
  if (auth === 'UNKNOWN' || auth === 'CHECK_FAILED' || !auth) return '<span class="badge badge-muted">— Chưa kiểm tra</span>';
  if (auth !== 'LOGGED_IN') return '<span class="badge badge-muted">— Chưa kiểm tra</span>';
  if (pres === 'HAS_CHANNEL') return `<span class="badge badge-ok">✓ Đã có kênh${st.channel_name ? ' (' + escapeHtml(st.channel_name) + ')' : ''}</span>`;
  if (pres === 'NO_CHANNEL') return '<span class="badge badge-review">○ Chưa có kênh</span>';
  return '<span class="badge badge-muted">— Chưa kiểm tra</span>';
}
// SINGLE SUMMARY BUILDER phía client (§25) — mirror EvalStates::buildSummary().
// Card + Drawer + Monitoring đều derive từ đây, không tự suy status riêng.
// Backend đã tính sẵn (account_channel_state); hàm này chỉ dùng khi thiếu.
function evalSummary(p) {
  if (p.account_channel_state) {
    return { state: p.account_channel_state, readiness: p.readiness_status || 'CHECK_REQUIRED' };
  }
  const auth = p.auth_status || 'UNKNOWN';
  const pres = p.channel_presence || 'NOT_CHECKED';
  const acc = p.channel_access_status || 'NOT_CHECKED';
  const sec = p.security_status || 'UNKNOWN';
  if (auth === 'LOGIN_REQUIRED') return { state: 'NEED_LOGIN', readiness: 'NEED_LOGIN' };
  if (auth === 'VERIFICATION_REQUIRED' || sec === 'CHALLENGE' || sec === 'RECOVERY') {
    return { state: 'NEED_VERIFICATION', readiness: 'NEED_VERIFICATION' };
  }
  if (auth === 'LOGGED_IN') {
    if (pres === 'HAS_CHANNEL') {
      if (acc === 'UNAVAILABLE' || acc === 'RESTRICTED' || sec === 'RESTRICTED') {
        return { state: 'CHANNEL_PROBLEM', readiness: 'CHANNEL_NEEDS_ATTENTION' };
      }
      return { state: 'CHANNEL_READY', readiness: 'CHANNEL_CREATED' };
    }
    if (pres === 'NO_CHANNEL') return { state: 'SIGNED_IN_NO_CHANNEL', readiness: 'READY_TO_CREATE_CHANNEL' };
    return { state: 'TECHNICAL_CHECK_FAILED', readiness: 'CHECK_REQUIRED' };
  }
  return { state: 'UNVERIFIED', readiness: 'CHECK_REQUIRED' };
}
// Invariant runtime (§22-§23): Login stage PASS nhưng summary NEED_LOGIN => lỗi logic.
function evalCheckInvariant(p, stages) {
  try {
    const loginPass = (stages || []).some(s => (s.stage === 'CHECK_LOGIN' || s.stage === 'CHECK_YOUTUBE') && s.result === 'PASS');
    const sum = evalSummary(p);
    if (loginPass && sum.state === 'NEED_LOGIN') {
      console.error('[EVAL INVARIANT] login PASS but summary NEED_LOGIN, profile=' + p.id);
    }
  } catch (e) {}
}
function channelPresenceChip(p) {
  const auth = p.auth_status || null;
  const pres = p.channel_presence || 'NOT_CHECKED';
  const verifiedAt = p.channel_verified_at || null;
  const known = p.last_known_presence || null;
  if (auth === 'LOGIN_REQUIRED') return `<span class="acc-channel" title="Cần đăng nhập để xác minh kênh">⚠ Cần đăng nhập</span>`;
  if (auth === 'VERIFICATION_REQUIRED') return `<span class="acc-channel" title="Cần xác minh">⚠ Cần xác minh</span>`;
  // UNKNOWN / technical => "? Chưa xác định" + previous verified (khong masquerade) (§43-§44)
  if (auth === 'UNKNOWN' || auth === 'CHECK_FAILED') {
    let sub = `<span class="acc-channel" title="Chưa hoàn tất kiểm tra">? Chưa xác định</span>`;
    if (known === 'HAS_CHANNEL') {
      sub += `<div class="eval-prev">Lần xác nhận gần nhất: ✓ Đã có kênh${p.channel_verified_at ? ' · ' + formatRelativeTime(p.channel_verified_at) : ''}</div>`;
    }
    return sub;
  }
  if (auth !== 'LOGGED_IN') {
    let sub = `<span class="acc-channel" title="Chưa xác minh kênh">— Chưa xác minh kênh</span>`;
    if (known === 'HAS_CHANNEL') {
      sub += `<div class="eval-prev">Lần xác nhận gần nhất: ✓ Đã có kênh${p.channel_verified_at ? ' · ' + formatRelativeTime(p.channel_verified_at) : ''}</div>`;
    }
    return sub;
  }
  if (pres === 'HAS_CHANNEL') {
    const nm = (p.acc_channel_name || '').slice(0, 18);
    return `<span class="acc-channel" title="Đã xác minh${verifiedAt ? ' · ' + formatAbsoluteTime(verifiedAt) : ''}">📺 ${escapeHtml(nm || 'Đã có kênh')}</span>`;
  }
  if (pres === 'NO_CHANNEL') return `<span class="acc-channel" title="Đã xác minh">○ Chưa có kênh</span>`;
  return `<span class="acc-channel" title="Chưa xác định">? Chưa xác định</span>`;
}
function evalBadgeFor(p) {
  // Chua tung check thanh cong + attempt loi -> "Chua xac dinh" (khong ket luan hong)
  const es = p.eval_status || 'UNCHECKED';
  // PARTIAL (auth verify, channel timeout): badge ky thuat, KHONG "Can dang nhap" (§8)
  if (p.eval_attempt_status === 'PARTIAL') {
    return `<span class="badge badge-warn">◌ Kiểm tra một phần</span>`;
  }
  if (es === 'UNCHECKED' && (p.eval_attempt_status === 'FAILED' || p.eval_attempt_status === 'TIMEOUT')) {
    return `<span class="badge badge-muted">? Chưa xác định</span>`;
  }
  return evalBadge(es);
}
// Utility DUY NHAT cho relative time (§9). DB khong luu text, chi timestamp.
// Naive 'YYYY-MM-DD HH:MM:SS' = wall-time server (+07:00) -> gan offset de khong mo ho.
function parseServerTime(s) {
  if (!s) return NaN;
  s = String(s).trim();
  if (/[T]/.test(s)) return new Date(s).getTime(); // ISO (co Z/offset) -> chinh xac
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (m) return new Date(`${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6] || '00'}+07:00`).getTime();
  return new Date(s).getTime();
}
function formatRelativeTime(ts) {
  try {
    const t = parseServerTime(ts);
    if (isNaN(t)) return String(ts || '');
    const sec = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (sec < 10) return 'vừa xong';
    if (sec < 60) return 'vài giây trước';
    const m = Math.floor(sec / 60);
    if (m < 1) return 'vừa xong';
    if (m === 1) return '1 phút trước';
    if (m < 60) return `${m} phút trước`;
    const h = Math.floor(m / 60);
    if (h === 1) return '1 giờ trước';
    if (h < 24) return `${h} giờ trước`;
    const d = Math.floor(h / 24);
    if (d === 1) return 'hôm qua';
    if (d < 7) return `${d} ngày trước`;
    const dt = new Date(t);
    return `${String(dt.getDate()).padStart(2, '0')}/${String(dt.getMonth() + 1).padStart(2, '0')}/${dt.getFullYear()}`;
  } catch (e) { return String(ts || ''); }
}
function formatAbsoluteTime(ts) {
  try {
    const t = parseServerTime(ts);
    if (isNaN(t)) return String(ts || '');
    return new Date(t).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  } catch (e) { return String(ts || ''); }
}
function accRelTime(s) { return formatRelativeTime(s); } // alias tuong thich
// 1 timer chung 30s: chi update text cac [data-rel], khong fetch, khong rerender
setInterval(() => {
  try {
    document.querySelectorAll('[data-rel]').forEach(el => {
      const ts = el.getAttribute('data-ts');
      if (ts) el.textContent = formatRelativeTime(ts);
    });
  } catch (e) {}
}, 30000);
function relSpan(ts, fallback) {
  // Span tu update theo ticker + tooltip gio local chinh xac
  if (!ts) return escapeHtml(fallback || 'chưa kiểm tra');
  return `<span data-rel data-ts="${escapeAttr(ts)}" title="${escapeAttr(formatAbsoluteTime(ts))}">${escapeHtml(formatRelativeTime(ts))}</span>`;
}

// ============ PROFILE ACTIONS ============
// Trạng thái đang xử lý (mở/đóng) để card đổi trạng thái NGAY khi bấm, không chờ server.
const pendingOps = new Map(); // id -> 'opening' | 'closing'
async function openProfile(id) {
  if (pendingOps.has(id)) return;
  pendingOps.set(id, 'opening');
  renderProfiles();
  let res;
  try {
    res = await getJson(api + `browser.php?action=open&id=${id}`);
    toast(res.message || 'Đã mở', res.ok ? 'success' : 'error');
    if (res.proxy_dead) loadProxies();
    if (res.ok) { markProfileChanged(); refreshAll(); }
  } catch (e) {
    toast('Lỗi khi mở kênh', 'error');
  } finally {
    pendingOps.delete(id);
    renderProfiles();
  }
}
async function deleteProfile(id) {
  const p = profiles.find(x => x.id === id);
  const name = p ? p.name : 'kênh này';
  confirmDelete(`Bạn chắc chắn muốn xóa kênh <strong>${escapeHtml(name)}</strong>?<br><small>Thư mục dữ liệu (cache) của kênh sẽ bị xóa vĩnh viễn.</small>`, async () => {
    const res = await del(api + `profiles.php?action=delete&id=${id}`);
    toast(res.ok ? 'Đã xóa kênh' : 'Lỗi', res.ok ? 'success' : 'error');
    if (res.ok) markProfileChanged();
    refreshAll();
  });
}
async function closeProfile(id) {
  if (pendingOps.has(id)) return;
  pendingOps.set(id, 'closing');
  renderProfiles();
  let res;
  try {
    res = await getJson(api + `browser.php?action=close&id=${id}`);
    toast(res.message || 'Đã đóng', res.ok ? 'success' : 'error');
    if (res.ok) markProfileChanged();
    refreshAll();
  } catch (e) {
    toast('Lỗi khi đóng kênh', 'error');
  } finally {
    pendingOps.delete(id);
    renderProfiles();
  }
}
async function openAllProfiles() {
  const ids = profiles.map(p => p.id);
  if (!ids.length) { toast('Chưa có kênh nào', 'error'); return; }
  if (activeBatch) { toast(`Đang ${activeBatch.kind === 'open' ? 'mở' : 'đóng'} hàng loạt — thử lại sau`, 'error'); return; }
  const seq = ++batchSeq;
  activeBatch = { kind: 'open', seq };
  const ui = batchBtn('btn-open-all', 'Đang mở');
  window.__ytmBulkOp = true;
  startBulkTracker();
  try {
    const r = await poolEach(ids, id => getJson(api + `browser.php?action=open&id=${id}`), 'Đang mở', { seq, onTick: ui.tick.bind(ui) });
    markProfileChanged();
    if (r.cancelled) { toast('Đã hủy mở tất cả', 'error'); refreshAll(); return; }
    toast(`Đã mở ${r.ok}/${ids.length} kênh`, r.fail ? 'error' : 'success');
    refreshAll();
    await autoArrangeAfterLaunch(ids);
  } finally {
    window.__ytmBulkOp = false;
    stopBulkTracker();
    ui.done();
    if (activeBatch && activeBatch.seq === seq) activeBatch = null;
  }
}
async function closeAllProfiles() {
  const ids = profiles.map(p => p.id);
  if (!ids.length) { toast('Chưa có kênh nào', 'error'); return; }
  if (activeBatch && activeBatch.kind === 'close') { toast('Đang đóng — thử lại sau', 'error'); return; }
  const seq = ++batchSeq; // huy Start dang chay: QUEUED dung, STARTING/RUNNING -> CLOSING
  activeBatch = { kind: 'close', seq };
  const ui = batchBtn('btn-close-all', 'Đang đóng');
  window.__ytmBulkOp = true;
  startBulkTracker();
  try {
    const r = await poolEach(ids, id => getJson(api + `browser.php?action=close&id=${id}`), 'Đang đóng', { seq, onTick: ui.tick.bind(ui) });
    markProfileChanged();
    if (r.cancelled) { toast('Đã hủy', 'error'); refreshAll(); return; }
    toast(`Đã đóng ${r.ok}/${ids.length} kênh`, r.fail ? 'error' : 'success');
    refreshAll();
  } finally {
    window.__ytmBulkOp = false;
    stopBulkTracker();
    ui.done();
    if (activeBatch && activeBatch.seq === seq) activeBatch = null;
  }
}

// ---- đổi tên / handle nhanh trên card ----
async function renameProfile(id) {
  const p = profiles.find(x => x.id === id);
  if (!p) return;
  const newName = prompt('Nhập tên mới cho kênh:', p.name);
  if (newName === null || newName.trim() === '') return;
  if (newName.trim() === p.name) return;
  const res = await sendJson(api + 'profiles.php?action=update', {
    id, name: newName.trim(), platform: p.platform, channel_handle: p.channel_handle, proxy_id: p.proxy_id,
    user_agent: p.user_agent || '', webrtc_protection: p.webrtc_protection || 'default'
  });
  toast(res.ok ? 'Đã đổi tên kênh' : res.message || 'Lỗi', res.ok ? 'success' : 'error');
  refreshAll();
}
async function editHandle(id) {
  const p = profiles.find(x => x.id === id);
  if (!p) return;
  const h = prompt('Nhập channel handle (@handle hoặc channel ID):', p.channel_handle || '');
  if (h === null) return;
  const res = await sendJson(api + 'profiles.php?action=update', {
    id, name: p.name, platform: p.platform, channel_handle: h.trim() || null, proxy_id: p.proxy_id,
    user_agent: p.user_agent || '', webrtc_protection: p.webrtc_protection || 'default'
  });
  toast(res.ok ? 'Đã lưu handle' : res.message || 'Lỗi', res.ok ? 'success' : 'error');
  refreshAll();
}

// ---- proxy theo từng kênh ----
function addProfileProxy(profileId) {
  pendingProxyAssignProfileId = profileId;
  openProxyModal();
  toast('Thêm proxy xong sẽ tự gán cho kênh này.');
}
function editProfileProxy(profileId) {
  const p = profiles.find(x => x.id === profileId);
  const px = p && proxies.find(x => Number(x.id) === Number(p.proxy_id));
  if (!px) {
    addProfileProxy(profileId);
    return;
  }
  openEditProxy(px.id);
  toast('Đang sửa proxy của kênh "' + p.name + '"');
}
async function removeProfileProxy(profileId) {
  const p = profiles.find(x => x.id === profileId);
  if (!p) return;
  if (!confirm('Gỡ proxy khỏi kênh "' + p.name + '"?')) return;
  const res = await sendJson(api + 'profiles.php?action=update', {
    id: profileId, name: p.name, platform: p.platform, channel_handle: p.channel_handle, proxy_id: null,
    user_agent: p.user_agent || '', webrtc_protection: p.webrtc_protection || 'default'
  });
  toast(res.ok ? 'Đã gỡ proxy khỏi kênh' : res.message || 'Lỗi', res.ok ? 'success' : 'error');
  refreshAll();
}

// ============ PROFILE MODAL ============
let profileMode = 'single';
let bulkProxyMode = 'single';
function setProfileMode(mode) {
  profileMode = mode;
  const isSingle = mode === 'single';
  $('pf-mode-single').classList.toggle('active', isSingle);
  $('pf-mode-bulk').classList.toggle('active', !isSingle);
  $('pf-single-fields').classList.toggle('hidden', !isSingle);
  $('pf-bulk-fields').classList.toggle('hidden', isSingle);
  $('pf-save-btn').textContent = isSingle ? 'Lưu' : 'Tạo';
}
// Tạo User-Agent Chrome giả lập ngẫu nhiên (phiên bản + mã build ngẫu nhiên)
function randomUA() {
  const ver = (100 + Math.floor(Math.random() * 28));       // 100-127
  const build = (5000 + Math.floor(Math.random() * 5000));  // 5000-9999
  const patch = Math.floor(Math.random() * 100);
  const ua = `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${ver}.0.${build}.${patch} Safari/537.36`;
  $('pf-ua').value = ua;
  toast('Đã tạo User-Agent ngẫu nhiên', 'success');
}
function pfFillMonitorSelect(selEl, monitors, cur) {
  if (!selEl) return;
  selEl.innerHTML = (monitors || []).map(m =>
    `<option value="${escapeHtml(m.name || '')}">Màn hình ${m.id}${m.primary ? ' (chính)' : ''}</option>`
  ).join('') || '<option value="">(chưa có màn hình)</option>';
  if (cur) selEl.value = cur;
}
function pfRefreshMonitorHint() {
  const p = profiles.find(x => String(x.id) === String($('pf-id').value));
  const mode = $('pf-monitor-mode') ? $('pf-monitor-mode').value : 'LAST';
  const fx = $('pf-monitor-fixed');
  if (fx) fx.classList.toggle('hidden', mode !== 'FIXED');
  const hint = $('pf-monitor-hint');
  if (!hint) return;
  if (!p) { hint.textContent = 'Mới: mặc định Nhớ màn hình lần cuối.'; return; }
  const cur = p.last_monitor_device ? ('Hiện tại: ' + p.last_monitor_device) : 'Chưa chạy lần nào';
  const last = p.last_monitor_device ? (' · Lần cuối: ' + p.last_monitor_device) : '';
  hint.textContent = (p.status === 'running' ? cur : ('Không chạy.' + last));
}
// ============ CHANNEL MODAL: create / edit mode ro rang ============
// profileModalMode: 'create' | 'edit'. Create MOI duoc thay tabs Tao don/nhieu.
// Edit load draft tu stable ID, khong bao gio goi API tao moi.
let profileModalMode = 'create';
let profileDraftOriginal = null;
function setProfileModalMode(mode) {
  profileModalMode = mode;
  const isCreate = mode === 'create';
  $('pf-mode-tabs').classList.toggle('hidden', !isCreate);
  $('pf-create-btn').classList.toggle('hidden', !isCreate);
  $('pf-save-btn').classList.toggle('hidden', isCreate);
  $('pf-error').classList.add('hidden');
  $('pf-error').textContent = '';
  $('pf-dirty-hint').textContent = '';
}
function pfShowSingleFields() {
  // Edit luon dung single-fields; bulk chi o create
  $('pf-single-fields').classList.remove('hidden');
  $('pf-bulk-fields').classList.add('hidden');
  $('pf-common-fields').classList.remove('hidden');
}
function openCreateChannel() {
  setProfileModalMode('create');
  $('profile-modal-title').textContent = 'Tạo kênh mới';
  $('pf-id').value = '';
  $('pf-name').value = '';
  $('pf-platform').value = 'youtube';
  $('pf-handle').value = '';
  $('pf-prefix').value = 'Kênh';
  $('pf-count').value = '5';
  $('pf-ua').value = '';
  $('pf-webrtc').value = 'default';
  if ($('pf-monitor-mode')) $('pf-monitor-mode').value = 'LAST';
  pfFillMonitorSelect($('pf-monitor-fixed'), cachedMonitors, '');
  pfRefreshMonitorHint();
  $('pf-launch-note').textContent = '';
  $('pf-proxy-note').textContent = '';
  setProfileMode('single');
  populateProxySelect();
  initProxyModeUI(null);
  profileDraftOriginal = null;
  refreshProfileSaveBtn();
  showModal('profile-modal');
}
function openProfileModal() { openCreateChannel(); }
function collectProfileDraft() {
  return {
    name: $('pf-name').value.trim(),
    platform: $('pf-platform').value,
    channel_handle: $('pf-handle').value.trim() || null,
    user_agent: $('pf-ua').value.trim() || null,
    webrtc_protection: $('pf-webrtc').value,
    ...collectProxyDraft(),
    monitor_mode: $('pf-monitor-mode') ? $('pf-monitor-mode').value : 'LAST',
    fixed_monitor_device: ($('pf-monitor-mode') && $('pf-monitor-mode').value === 'FIXED' && $('pf-monitor-fixed')) ? $('pf-monitor-fixed').value : '',
  };
}
function initProxyModeUI(p) {
  // Default theo du lieu channel hien tai: co proxy -> SAVED, khong -> NONE
  const hasProxy = !!(p && p.proxy_id);
  setProxyModeSilent(hasProxy ? 'SAVED' : 'NONE');
  pfManual = { protocol: 'socks5', host: '', port: '', username: '', password: '' };
  $('pf-proxy-text').value = '';
  $('pf-proxy-proto').value = 'socks5';
  $('pf-px-host').value = ''; $('pf-px-port').value = ''; $('pf-px-user').value = ''; $('pf-px-pass').value = '';
  $('pf-proxy-adv').classList.add('hidden');
  $('pf-proxy-adv-toggle').textContent = 'Cấu hình chi tiết ▾';
  $('pf-proxy-test-result').textContent = '';
  pfProxyError('');
  // Neu kenh co protocol luu -> manual default theo no
  const cur = p && proxies.find(x => Number(x.id) === Number(p.proxy_id));
  if (cur && cur.protocol && PF_PROTOCOLS.includes(cur.protocol)) {
    $('pf-proxy-proto').value = cur.protocol;
    pfManual.protocol = cur.protocol;
  }
}
function setProxyModeSilent(m) {
  pfProxyMode = m;
  document.querySelectorAll('#pf-proxy-modes button').forEach(b => b.classList.toggle('active', b.dataset.pmode === m));
  $('pf-proxy-saved-wrap').classList.toggle('hidden', m !== 'SAVED');
  $('pf-proxy-manual-wrap').classList.toggle('hidden', m !== 'MANUAL');
}
function openEditChannel(id) {
  // Load bang stable ID (khong index/title/vi tri), clone sang draft
  const p = profiles.find(x => Number(x.id) === Number(id));
  if (!p) { toast('Không tìm thấy kênh', 'error'); return; }
  setProfileModalMode('edit');
  $('profile-modal-title').textContent = 'Sửa kênh: ' + p.name;
  $('pf-id').value = p.id;
  $('pf-name').value = p.name || '';
  $('pf-platform').value = p.platform || 'youtube';
  $('pf-handle').value = p.channel_handle || '';
  $('pf-ua').value = p.user_agent || '';
  $('pf-webrtc').value = p.webrtc_protection || 'default';
  populateProxySelect(p.proxy_id);
  initProxyModeUI(p);
  if ($('pf-monitor-mode')) $('pf-monitor-mode').value = p.monitor_mode || 'LAST';
  pfFillMonitorSelect($('pf-monitor-fixed'), cachedMonitors, p.fixed_monitor_device || '');
  pfRefreshMonitorHint();
  pfShowSingleFields();
  // Note khi Chrome dang chay: thay doi ap dung lan mo tiep theo, khong restart am tham
  const running = p.status === 'running';
  $('pf-launch-note').textContent = running ? 'ⓘ Chrome đang chạy — đổi User-Agent/WebRTC sẽ áp dụng ở lần mở tiếp theo.' : '';
  $('pf-proxy-note').textContent = running ? 'ⓘ Chrome đang chạy — đổi proxy sẽ áp dụng ở lần mở tiếp theo.' : '';
  const mh = $('pf-monitor-hint');
  if (mh && running) mh.textContent += ' (Áp dụng khi mở Chrome lần tiếp theo.)';
  // Snapshot draft goc de so dirty; form khong bind truc tiep object goc
  profileDraftOriginal = JSON.stringify(collectProfileDraft());
  refreshProfileSaveBtn();
  showModal('profile-modal');
}
function openProfileModalEdit(id) { openEditChannel(id); }
function profileDraftChanged() {
  if (profileModalMode !== 'edit' || !profileDraftOriginal) return;
  refreshProfileSaveBtn();
}
function refreshProfileSaveBtn() {
  const btn = $('pf-save-btn');
  if (!btn || profileModalMode !== 'edit') return;
  const dirty = JSON.stringify(collectProfileDraft()) !== profileDraftOriginal;
  btn.disabled = !dirty;
  $('pf-dirty-hint').textContent = dirty ? 'Có thay đổi chưa lưu' : '';
}
['pf-name', 'pf-handle', 'pf-platform', 'pf-ua', 'pf-webrtc', 'pf-proxy', 'pf-monitor-fixed', 'pf-proxy-text', 'pf-proxy-proto', 'pf-px-host', 'pf-px-port', 'pf-px-user', 'pf-px-pass'].forEach(fid => {
  document.addEventListener('change', (e) => {
    if (!e.target || e.target.id !== fid) return;
    if (fid === 'pf-proxy-text') onProxyTextInput();
    else if (fid === 'pf-proxy-proto') onProxyProtoChange();
    else if (fid.startsWith('pf-px-')) onProxyAdvInput();
    else profileDraftChanged();
  });
  document.addEventListener('input', (e) => {
    if (!e.target || e.target.id !== fid) return;
    if (fid === 'pf-proxy-text') onProxyTextInput();
    else if (fid.startsWith('pf-px-')) onProxyAdvInput();
    else profileDraftChanged();
  });
});
function cancelProfileModal() {
  // Cancel/X: dirty -> hoi, sach -> dong ngay. Draft bi huy, card giu nguyen.
  if (profileModalMode === 'edit' && profileDraftOriginal
      && JSON.stringify(collectProfileDraft()) !== profileDraftOriginal) {
    const okBtn = $('confirm-ok-btn');
    if (okBtn) okBtn.textContent = 'Bỏ thay đổi';
    confirmDelete('Bạn có thay đổi chưa lưu. Đóng mà không lưu?<br><small>Bấm "Hủy" để tiếp tục chỉnh sửa.</small>', () => {
      profileDraftOriginal = null;
      closeModal('profile-modal');
    });
    return;
  }
  profileDraftOriginal = null;
  closeModal('profile-modal');
}
function populateProxySelect(selectedId) {
  const sel = $('pf-proxy');
  sel.innerHTML = '<option value="">Không dùng proxy</option>' +
    proxies.map(p => {
      const proto = String(p.protocol || 'http').toUpperCase();
      const label = `${proto} • ${p.host}:${p.port}`;
      const title = p.name && !/^\d/.test(p.name) ? `${p.name} — ${label}` : label;
      return `<option value="${p.id}" ${p.id == selectedId ? 'selected' : ''}>${escapeHtml(title)}</option>`;
    }).join('');
}
// ============ PROXY MODE (edit/create modal): NONE | SAVED | MANUAL ============
let pfProxyMode = 'NONE';
let pfManual = { protocol: 'socks5', host: '', port: '', username: '', password: '' };
let pfProxyTestAbort = null;
const PF_PROTOCOLS = ['http', 'https', 'socks4', 'socks5'];
function setProxyMode(m) {
  pfProxyMode = m;
  document.querySelectorAll('#pf-proxy-modes button').forEach(b => b.classList.toggle('active', b.dataset.pmode === m));
  $('pf-proxy-saved-wrap').classList.toggle('hidden', m !== 'SAVED');
  $('pf-proxy-manual-wrap').classList.toggle('hidden', m !== 'MANUAL');
  pfProxyError('');
  profileDraftChanged();
}
function pfProxyError(msg) {
  const el = $('pf-proxy-error');
  el.textContent = msg || '';
  el.classList.toggle('hidden', !msg);
}
// Parser: host:port | host:port:user:pass | user:pass@host:port | proto://... (khong doan port)
function parseProxyText(text, fallbackProto) {
  const t = String(text || '').trim();
  if (!t) return { error: 'Chưa nhập proxy' };
  let rest = t, proto = null;
  const pm = rest.match(/^(https?|socks4|socks5):\/\//i);
  if (pm) { proto = pm[1].toLowerCase(); rest = rest.slice(pm[0].length); }
  let auth = null;
  const at = rest.lastIndexOf('@');
  if (at >= 0) {
    auth = rest.slice(0, at);
    rest = rest.slice(at + 1);
    const ci = auth.indexOf(':');
    if (ci < 0) return { error: 'Định dạng proxy không hợp lệ' };
  }
  const parts = rest.split(':');
  if (parts.length < 2 || parts.length > 4) return { error: 'Định dạng proxy không hợp lệ' };
  const host = parts[0].trim();
  const port = parseInt(parts[1], 10);
  if (!host || !Number.isInteger(port) || port < 1 || port > 65535) {
    return { error: 'Định dạng proxy không hợp lệ' };
  }
  let username = '', password = '';
  if (auth !== null) {
    username = auth.slice(0, auth.indexOf(':'));
    password = auth.slice(auth.indexOf(':') + 1);
  } else if (parts.length >= 4) {
    username = parts[2]; password = parts.slice(3).join(':');
  } else if (parts.length === 3) {
    return { error: 'Định dạng proxy không hợp lệ (thiếu password)' };
  }
  return { protocol: proto || (fallbackProto || 'socks5'), host, port, username, password };
}
function pfFillManual(m, updateText) {
  pfManual = { protocol: m.protocol || 'socks5', host: m.host || '', port: m.port || '', username: m.username || '', password: m.password || '' };
  $('pf-proxy-proto').value = pfManual.protocol;
  $('pf-px-host').value = pfManual.host;
  $('pf-px-port').value = pfManual.port;
  $('pf-px-user').value = pfManual.username;
  $('pf-px-pass').value = pfManual.password;
  if (updateText !== false) {
    let s = `${pfManual.host}:${pfManual.port}`;
    if (pfManual.username) s += `:${pfManual.username}:${pfManual.password}`;
    if ($('pf-proxy-text').value.trim() === '' || updateText === true) $('pf-proxy-text').value = (pfManual.host && pfManual.port) ? s : $('pf-proxy-text').value;
  }
}
function onProxyTextInput() {
  const t = $('pf-proxy-text').value.trim();
  if (!t) { pfProxyError(''); return; }
  const r = parseProxyText(t, $('pf-proxy-proto').value);
  if (r.error) { pfProxyError(r.error); return; }
  pfProxyError('');
  $('pf-proxy-proto').value = r.protocol;
  pfFillManual(r, false);
  $('pf-proxy-test-result').textContent = '';
  profileDraftChanged();
}
function onProxyProtoChange() {
  pfManual.protocol = $('pf-proxy-proto').value;
  profileDraftChanged();
}
function onProxyAdvInput() {
  pfManual.host = $('pf-px-host').value.trim();
  pfManual.port = $('pf-px-port').value !== '' ? parseInt($('pf-px-port').value, 10) : '';
  pfManual.username = $('pf-px-user').value;
  pfManual.password = $('pf-px-pass').value;
  pfManual.protocol = $('pf-proxy-proto').value;
  profileDraftChanged();
}
async function pasteProxy() {
  let text = '';
  try {
    text = await navigator.clipboard.readText();
  } catch (e) {
    // Fallback khi clipboard API bi chan: dung prompt nhap nhanh
    text = prompt('Dán proxy vào đây:', '') || '';
  }
  text = String(text || '').trim();
  if (!text) return;
  $('pf-proxy-text').value = text;
  onProxyTextInput();
  if (!$('pf-proxy-error').classList.contains('hidden')) toast('Định dạng proxy không hợp lệ', 'error');
}
function toggleProxyAdvanced() {
  const el = $('pf-proxy-adv');
  el.classList.toggle('hidden');
  $('pf-proxy-adv-toggle').textContent = el.classList.contains('hidden') ? 'Cấu hình chi tiết ▾' : 'Cấu hình chi tiết ▴';
}
function toggleProxyPass() {
  const el = $('pf-px-pass');
  el.type = el.type === 'password' ? 'text' : 'password';
}
function collectManualProxy() {
  // Uu tien advanced fields neu user da mo/sua (dong bo 2 chieu truoc khi doc)
  if (!$('pf-proxy-adv').classList.contains('hidden')) onProxyAdvInput();
  else {
    const t = $('pf-proxy-text').value.trim();
    if (t) {
      const r = parseProxyText(t, $('pf-proxy-proto').value);
      if (!r.error) pfFillManual(r, false);
    } else {
      pfManual.protocol = $('pf-proxy-proto').value;
    }
  }
  return { ...pfManual };
}
function validateManualProxy(m) {
  if (!m.host) return 'Nhập host proxy';
  if (!Number.isInteger(Number(m.port)) || Number(m.port) < 1 || Number(m.port) > 65535) return 'Port phải 1–65535';
  if (!PF_PROTOCOLS.includes(m.protocol)) return 'Protocol không hợp lệ';
  return '';
}
async function testManualProxy() {
  if (pfProxyTestAbort) { pfProxyTestAbort.abort(); pfProxyTestAbort = null; }
  const m = collectManualProxy();
  const err = validateManualProxy({ ...m, port: Number(m.port) });
  if (err) { pfProxyError(err); return; }
  pfProxyError('');
  const btn = $('pf-proxy-test-btn'), out = $('pf-proxy-test-result');
  btn.disabled = true; btn.textContent = '◌ Checking...';
  out.textContent = '';
  const ctrl = new AbortController();
  pfProxyTestAbort = ctrl;
  const timer = setTimeout(() => ctrl.abort(), 11000);
  try {
    const res = await fetch(api + 'proxies.php?action=check', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: m.host, port: Number(m.port), protocol: m.protocol, username: m.username || null, password: m.password || null }),
      signal: ctrl.signal,
    });
    const j = await res.json();
    if (j.ok && j.data && j.data.ok) {
      out.textContent = `✓ Hoạt động${j.data.ip ? ' · IP: ' + j.data.ip : ''} · ${j.data.ms} ms`;
    } else {
      out.textContent = `✕ Không kết nối được${j.data && j.data.error ? ' — ' + j.data.error : ''}`;
    }
  } catch (e) {
    out.textContent = e.name === 'AbortError' ? 'Đã hủy kiểm tra' : 'Lỗi kết nối khi kiểm tra';
  } finally {
    clearTimeout(timer);
    pfProxyTestAbort = null;
    btn.disabled = false; btn.textContent = '⚡ Kiểm tra proxy';
  }
}
// Draft proxy slice (de dirty-check + payload)
function collectProxyDraft() {
  if (pfProxyMode === 'NONE') return { proxy_mode: 'NONE' };
  if (pfProxyMode === 'SAVED') return { proxy_mode: 'SAVED', proxy_id: $('pf-proxy').value ? Number($('pf-proxy').value) : null };
  const m = collectManualProxy();
  return { proxy_mode: 'MANUAL', proxy_protocol: m.protocol, proxy_host: m.host, proxy_port: m.port === '' ? '' : Number(m.port), proxy_username: m.username, proxy_password: m.password };
}
// Dispatch RO RANG theo modal mode (khong doan mo ho): create -> createChannel, edit -> updateChannel
async function saveProfile() {
  if (profileModalMode === 'edit') { await updateChannel(); return; }
  await createChannel();
}
// Tao kenh: noi DUY NHAT duoc phep goi add/add_bulk
async function createChannel() {
  const platform = $('pf-platform').value;
  const proxy_id = $('pf-proxy').value || null;
  if (profileMode === 'bulk') {
    const count = parseInt($('pf-count').value, 10) || 0;
    const prefix = $('pf-prefix').value.trim() || 'Kênh';
    if (count < 1) { toast('Nhập số lượng kênh hợp lệ', 'error'); return; }
    const btn = $('pf-create-btn');
    if (btn) { btn.disabled = true; btn.textContent = '◌ Đang tạo...'; }
    try {
      const res = await sendJson(api + 'profiles.php?action=add_bulk', { count, prefix, platform, proxy_id });
      toast(res.ok ? `Đã tạo ${res.count || 0} kênh (${prefix})` : res.message || 'Lỗi', res.ok ? 'success' : 'error');
      if (res.ok) { closeModal('profile-modal'); refreshAll(); }
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Tạo kênh'; }
    }
    return;
  }
  const name = $('pf-name').value.trim();
  if (!name) { toast('Vui lòng nhập tên kênh', 'error'); return; }
  const px = collectProxyDraft();
  if (px.proxy_mode === 'MANUAL') {
    const verr = validateManualProxy({ protocol: px.proxy_protocol, host: px.proxy_host, port: px.proxy_port });
    if (verr) { pfShowError(verr); toast(verr, 'error'); return; }
  }
  const btn = $('pf-create-btn');
  if (btn) { btn.disabled = true; btn.textContent = '◌ Đang tạo...'; }
  pfShowError('');
  try {
    const res = await sendJson(api + 'profiles.php?action=add', {
      name, platform,
      channel_handle: $('pf-handle').value.trim() || null,
      user_agent: $('pf-ua').value.trim() || null,
      webrtc_protection: $('pf-webrtc').value,
      ...px,
      monitor_mode: $('pf-monitor-mode') ? $('pf-monitor-mode').value : 'LAST',
      fixed_monitor_device: ($('pf-monitor-mode') && $('pf-monitor-mode').value === 'FIXED' && $('pf-monitor-fixed')) ? $('pf-monitor-fixed').value : '',
    });
    toast(res.ok ? `Đã tạo kênh "${name}"` : res.message || 'Lỗi', res.ok ? 'success' : 'error');
    if (res.ok) { closeModal('profile-modal'); refreshAll(); }
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Tạo kênh'; }
  }
}
function pfShowError(msg) {
  const el = $('pf-error');
  el.textContent = msg;
  el.classList.toggle('hidden', !msg);
}
// Sua kenh: chi gui field duoc phep (dirty), KHONG BAO GIO goi add/add_bulk.
// Backend update chi cham name/platform/handle/ua/webrtc/proxy/monitor;
// debug_port, user_data_dir, tabs, runtime giu nguyen.
async function updateChannel() {
  const id = Number($('pf-id').value);
  if (!(id > 0)) { pfShowError('Thiếu ID kênh — không thể lưu.'); return; }
  const draft = collectProfileDraft();
  if (!draft.name) { pfShowError('Vui lòng nhập tên kênh.'); toast('Vui lòng nhập tên kênh', 'error'); return; }
  if (draft.proxy_mode === 'MANUAL') {
    const verr = validateManualProxy({ protocol: draft.proxy_protocol, host: draft.proxy_host, port: draft.proxy_port });
    if (verr) { pfShowError(verr); toast(verr, 'error'); return; }
  }
  // Chi gui field thay doi (dirty) + id; backend giu nguyen field khong gui
  const orig = JSON.parse(profileDraftOriginal || '{}');
  const payload = { id };
  for (const k of Object.keys(draft)) {
    if (JSON.stringify(draft[k]) !== JSON.stringify(orig[k])) payload[k] = draft[k];
  }
  if (Object.keys(payload).length === 1) { toast('Không có thay đổi nào', ''); return; }
  const btn = $('pf-save-btn');
  if (btn) { btn.disabled = true; btn.textContent = '◌ Đang lưu...'; }
  pfShowError('');
  try {
    const res = await sendJson(api + 'profiles.php?action=update', payload);
    if (!res.ok) {
      // Loi: GIU modal + draft, hien error, khong dong
      pfShowError(res.message || 'Không thể lưu thay đổi. Vui lòng thử lại.');
      toast(res.message || 'Lỗi', 'error');
      return;
    }
    // Patch store tai cho (khong doi runtime/tabs) + render card ngay
    const p = profiles.find(x => Number(x.id) === id);
    const newName = draft.name;
    if (p) Object.assign(p, draft);
    profileDraftOriginal = null;
    closeModal('profile-modal');
    renderProfiles();
    toast(`Đã cập nhật "${newName}"`, 'success');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Lưu thay đổi'; refreshProfileSaveBtn(); }
  }
}
function updateChannelInStore(id, fields) {
  const p = profiles.find(x => Number(x.id) === Number(id));
  if (p) Object.assign(p, fields);
}

// ============ PROXIES ============
async function loadProxies() {
  try {
    const res = await getJson(api + 'proxies.php?action=list');
    if (res.ok) {
      proxies = res.data;
      renderProxies();
      renderDashboard();
    }
  } catch (e) {}
}

function renderProxies() {
  $('nav-proxy-count').textContent = proxies.length;
  $('nav-proxy-count').classList.toggle('hidden', proxies.length === 0);

  const tbody = $('proxies-tbody');
  if (!proxies.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">Chưa có proxy nào. Bấm "+ Thêm proxy" hoặc "Nhập hàng loạt".</td></tr>';
    $('proxy-summary').textContent = '';
    renderProxiesPagination();
    return;
  }
  const alive = proxies.filter(p => p.status === 'alive').length;
  $('proxy-summary').textContent = `Tổng: ${proxies.length} · Sống: ${alive} · Chết: ${proxies.length - alive} · Chưa test: ${proxies.filter(p=>!p.status||p.status==='unknown').length}`;

  const totalPages = Math.max(1, Math.ceil(proxies.length / proxyPerPage));
  if (proxyPage > totalPages) proxyPage = totalPages;
  const start = (proxyPage - 1) * proxyPerPage;
  const pageItems = proxies.slice(start, start + proxyPerPage);

  tbody.innerHTML = pageItems.map(p => {
    const used = profiles.filter(x => Number(x.proxy_id) === Number(p.id)).length;
    return `
      <tr class="${selectedProxies.has(p.id) ? 'row-selected' : ''}">
        <td>${ckHtml(selectedProxies.has(p.id), `class="px-check" data-id="${p.id}" onchange="toggleProxySelect(${p.id}, this.checked)"`)}</td>
        <td>${escapeHtml(p.name)}</td>
        <td class="mono">${escapeHtml(p.host)}:${p.port}${p.username ? ' <span class="status-unknown">(auth)</span>' : ''}</td>
        <td>${p.protocol.toUpperCase()}</td>
        <td>${p.country || '-'}</td>
        <td><span class="status-${p.status || 'unknown'}">${statusLabel(p.status)}</span></td>
        <td class="mono">${p.last_check ? formatTime(p.last_check) : 'Chưa test'}</td>
        <td>
          <div style="display:flex;gap:6px">
            <button class="btn btn-sm" onclick="testProxy(${p.id})">Test</button>
            <button class="btn btn-sm" onclick="openEditProxy(${p.id})">Sửa</button>
            <button class="btn btn-sm btn-danger" onclick="deleteProxy(${p.id})">Xóa</button>
            ${used ? `<span class="status-unknown" title="Số kênh dùng proxy này">${used} kênh</span>` : ''}
          </div>
        </td>
      </tr>`;
  }).join('');

  syncProxySelectUI();
  renderProxiesPagination();
}

function renderProxiesPagination() {
  const el = $('proxies-pagination');
  if (!el) return;
  el.innerHTML = pgBarHTML(proxies.length, proxyPage, proxyPerPage,
    { unit: 'proxy', onpage: 'gotoProxyPage', onperpage: 'setProxyPerPage' });
}

function gotoProxyPage(page) {
  proxyPage = Math.max(1, Math.min(page, Math.max(1, Math.ceil(proxies.length / proxyPerPage))));
  renderProxies();
}
function setProxyPerPage(n) {
  const np = [5, 10, 20, 30, 50, 100].includes(parseInt(n, 10)) ? parseInt(n, 10) : 10;
  proxyPage = pgKeepPosition(proxyPage, proxyPerPage, np, proxies.length);
  proxyPerPage = np;
  try { localStorage.setItem('ytm-perpage-proxies', String(np)); } catch (e) {}
  renderProxies();
}

// ---- chọn nhiều proxy ----
function toggleProxySelect(id, checked) {
  id = Number(id);
  if (checked) selectedProxies.add(id); else selectedProxies.delete(id);
  syncProxySelectUI();
}
function toggleSelectAllProxies(checked) {
  // Chuan hoa Number het (id tu JSON co the la string) de so sanh khong lech kieu
  selectedProxies = checked ? new Set(proxies.map(p => Number(p.id))) : new Set();
  syncProxySelectUI();
}
function syncProxySelectUI() {
  // Tu chua cac id cu dang string (da tick tu truoc) -> chuan hoa 1 lan
  const norm = new Set([...selectedProxies].map(Number));
  selectedProxies = norm;
  const count = selectedProxies.size;
  const el = $('selected-proxies-count');
  if (el) el.textContent = count ? `Đã chọn ${count}` : '';

  const all = proxies.length > 0 && selectedProxies.size === proxies.length;
  const headAll = $('sel-all-proxies-head');
  const toolAll = $('sel-all-proxies');
  if (headAll) headAll.checked = all;
  if (toolAll) toolAll.checked = all;

  document.querySelectorAll('.px-check').forEach(cb => {
    cb.checked = selectedProxies.has(Number(cb.dataset.id));
  });
  document.querySelectorAll('tr.row-selected').forEach(tr => tr.classList.remove('row-selected'));
  proxies.forEach(p => {
    if (selectedProxies.has(Number(p.id))) {
      const row = document.querySelector(`td input.px-check[data-id="${p.id}"]`);
      if (row) row.closest('tr').classList.add('row-selected');
    }
  });
}
async function deleteSelectedProxies() {
  if (!selectedProxies.size) { toast('Chưa chọn proxy nào', 'error'); return; }
  const n = selectedProxies.size;
  confirmDelete(`Bạn chắc chắn muốn xóa <strong>${n} proxy</strong> đã chọn?<br><small>Kênh đang dùng các proxy này sẽ không còn proxy.</small>`, async () => {
    const ids = [...selectedProxies];
    await Promise.all(ids.map(id => del(api + `proxies.php?action=delete&id=${id}`)));
    selectedProxies = new Set();
    toast(`Đã xóa ${ids.length} proxy`, 'success');
    loadProxies();
  });
}

function statusLabel(s) {
  return { alive: '● Sống', dead: '● Chết', unknown: '○ Chưa test' }[s || 'unknown'] || '○ Chưa test';
}

// ============ SUA PROXY HANG LOAT (field rong = giu nguyen) ============
let pendingBulkEditProxyIds = [];
function openBulkEditProxies() {
  if (!selectedProxies.size) { toast('Chưa chọn proxy nào', 'error'); return; }
  pendingBulkEditProxyIds = [...selectedProxies];
  $('be-protocol').value = 'keep';
  $('be-country').value = '';
  $('be-user').value = '';
  $('be-pass').value = '';
  $('be-lines').value = '';
  $('bulk-edit-count').textContent = `Sẽ sửa ${pendingBulkEditProxyIds.length} proxy đã chọn. Để trống = giữ nguyên.`;
  showModal('bulk-edit-modal');
}
async function saveBulkEditProxies() {
  if (!pendingBulkEditProxyIds.length) return;
  const ids = pendingBulkEditProxyIds;
  const data = { ids };
  if ($('be-protocol').value !== 'keep') data.protocol = $('be-protocol').value;
  if ($('be-country').value.trim()) data.country = $('be-country').value.trim();
  if ($('be-user').value.trim()) data.username = $('be-user').value.trim();
  if ($('be-pass').value) data.password = $('be-pass').value;
  const lines = $('be-lines').value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
  if (lines.length) data.lines = lines;
  const btn = $('bulk-edit-save-btn');
  try {
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang lưu...'; }
    const res = await sendJson(api + 'proxies.php?action=update_bulk', data);
    if (res.ok) {
      toast(`Đã sửa ${res.updated}/${ids.length} proxy`, 'success');
      closeModal('bulk-edit-modal');
      loadProxies();
    } else {
      toast(res.message || 'Lỗi', 'error');
    }
  } catch (e) {
    toast('Lỗi kết nối khi lưu', 'error');
  }
  if (btn) { btn.disabled = false; btn.textContent = 'Lưu thay đổi'; }
}

async function testProxy(id) {
  toast('Đang test proxy...', '');
  const res = await getJson(api + `proxies.php?action=test&id=${id}`);
  toast(res.alive ? '✓ Proxy sống' : '✗ Proxy chết', res.alive ? 'success' : 'error');
  loadProxies();
}
async function testAllProxies() {
  toast('Đang test tất cả proxy...', '');
  const res = await getJson(api + 'proxies.php?action=test_all');
  toast(`Test xong: ${res.alive}/${res.total} proxy sống`, 'success');
  loadProxies();
}
async function deleteProxy(id) {
  const p = proxies.find(x => x.id === id);
  const name = p ? p.name : 'proxy này';
  confirmDelete(`Bạn chắc chắn muốn xóa proxy <strong>${escapeHtml(name)}</strong>?`, async () => {
    await del(api + `proxies.php?action=delete&id=${id}`);
    toast('Đã xóa proxy', 'success');
    loadProxies();
  });
}

// ============ PROXY MODAL ============
function openProxyModal() {
  $('proxy-modal-title').textContent = 'Thêm proxy';
  $('px-id').value = ''; $('px-name').value = ''; $('px-host').value = '';
  $('px-port').value = ''; $('px-protocol').value = 'http';
  $('px-user').value = ''; $('px-pass').value = ''; $('px-country').value = '';
  $('px-string').value = '';
  $('proxy-test-result').innerHTML = '';
  showModal('proxy-modal');
}
function openEditProxy(id) {
  const p = proxies.find(x => x.id === id);
  if (!p) return;
  $('proxy-modal-title').textContent = 'Sửa proxy: ' + p.name;
  $('px-id').value = p.id; $('px-name').value = p.name;
  $('px-host').value = p.host; $('px-port').value = p.port;
  $('px-protocol').value = p.protocol;
  $('px-user').value = p.username || ''; $('px-pass').value = '';
  $('px-pass').placeholder = p.username ? '(giữ nguyên nếu để trống)' : 'Mật khẩu nếu proxy yêu cầu';
  $('px-country').value = p.country || '';
  $('px-string').value = '';
  $('proxy-test-result').innerHTML = '';
  showModal('proxy-modal');
}
async function saveProxy() {
  const id = $('px-id').value;
  const parsed = $('px-string').value.trim() ? parseProxyString($('px-string').value) : null;
  if ($('px-string').value.trim() && !parsed) { toast('Chuỗi proxy không hợp lệ', 'error'); return; }

  const host = parsed ? parsed.host : $('px-host').value.trim();
  const port = parsed ? parsed.port : $('px-port').value;
  if (!host || !port) { toast('Nhập host và port (hoặc chuỗi proxy)', 'error'); return; }

  const data = {
    id: id || undefined,
    name: $('px-name').value.trim() || (host + ':' + port),
    host: host,
    port: port,
    protocol: parsed ? parsed.protocol : $('px-protocol').value,
    username: parsed ? parsed.username : ($('px-user').value.trim() || null),
    password: parsed ? parsed.password : ($('px-pass').value || null),
    country: $('px-country').value.trim().toUpperCase() || null
  };
  const res = await sendJson(api + 'proxies.php?action=' + (id ? 'update' : 'add'), data);
  if (!res.ok) { toast(res.message || 'Lỗi', 'error'); return; }

  const assignTo = pendingProxyAssignProfileId;
  pendingProxyAssignProfileId = null;
  closeModal('proxy-modal');
  if (id) {
    toast('Đã lưu proxy', 'success');
  } else if (assignTo != null) {
    const p = profiles.find(x => x.id === assignTo);
    if (p) {
      await sendJson(api + 'profiles.php?action=update', {
        id: assignTo, name: p.name, platform: p.platform, channel_handle: p.channel_handle, proxy_id: res.id
      });
      toast('Đã thêm proxy và gán cho kênh "' + p.name + '"', 'success');
    }
  } else {
    toast('Đã thêm proxy', 'success');
  }
  refreshAll();
}
async function testProxyModal() {
  const host = $('px-host').value.trim();
  const port = $('px-port').value;
  if (!host || !port) { toast('Nhập host và port trước khi test', 'error'); return; }
  const user = $('px-user').value ? $('px-user').value + ':' + $('px-pass').value + '@' : '';
  const line = `${$('px-protocol').value}://${user}${host}:${port}`;
  toast('Đang test proxy mới...', '');
  const r2 = await sendJson(api + 'proxies.php?action=import_test', { list: line });
  if (r2.ok && r2.data && r2.data[0]) {
    const alive = r2.data[0].result;
    const el = $('proxy-test-result');
    el.innerHTML = `<div class="${alive ? 'success' : 'fail'}"><strong>${alive ? '✓ Proxy sống' : '✗ Proxy chết'}</strong></div>`;
  }
}
async function doImport() {
  const list = $('import-list').value;
  if (!list.trim()) { toast('Nhập danh sách proxy trước', 'error'); return; }
  toast('Đang nhập...', '');
  const res = await sendJson(api + 'proxies.php?action=import', { list });
  $('import-result').innerHTML = `<div class="${res.ok ? 'success' : 'fail'}">Đã thêm ${res.added || 0} proxy thành công</div>`;
  closeModal('import-modal');
  loadProxies();
}
function importProxies() {
  $('import-list').value = '';
  $('import-result').innerHTML = '';
  showModal('import-modal');
}

// ---- parse chuỗi proxy: host:port / host:port:user:pass / protocol://user:pass@host:port ----
function parseProxyString(s) {
  s = (s || '').trim();
  if (!s) return null;
  let protocol = 'http', auth = null, rest = s;
  const m = s.match(/^(https?|socks4|socks5|ssh):\/\//i);
  if (m) { protocol = m[1].toLowerCase(); rest = s.slice(m[0].length); }
  const am = rest.match(/^(.*?):(.*?)@(.*)$/);
  if (am) {
    auth = [am[1], am[2]];
    rest = am[3];
  }
  const parts = rest.split(':');
  const host = parts[0].trim();
  const port = (parts[1] || '').trim();
  if (!host || !port || !/^\d+$/.test(port)) return null;
  let username = auth ? auth[0] : null;
  let password = auth ? auth[1] : null;
  if (!username && parts[2] !== undefined && parts[2] !== '') {
    username = parts[2];
    password = (parts[3] !== undefined && parts[3] !== '') ? parts[3] : null;
  }
  return { host, port, protocol, username: username || null, password: password || null };
}
function parseProxyStringInput(val) {
  const p = parseProxyString(val);
  if (!p) return;
  $('px-host').value = p.host;
  $('px-port').value = p.port;
  $('px-protocol').value = p.protocol;
  $('px-user').value = p.username || '';
  $('px-pass').value = p.password || '';
}

// ============ LOGS ============
async function loadLogs() {
  try {
    const res = await getJson(api + 'logs.php?limit=50');
    if (res.ok) {
      logs = res.data;
      renderLogs();
      renderDashboard();
    }
  } catch (e) {}
}
function renderLogs() {
  const tbody = $('logs-tbody');
  if (!logs.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty">Chưa có hoạt động nào</td></tr>';
    return;
  }
  tbody.innerHTML = logs.map(l => `
    <tr>
      <td class="mono">${formatTime(l.created_at)}</td>
      <td>${escapeHtml(l.profile_name || '-')}</td>
      <td>${escapeHtml(l.action)}</td>
      <td>${escapeHtml(l.detail || '')}</td>
    </tr>`).join('');
}
function renderDashboardLogs() {
  const tbody = $('dash-logs-tbody');
  if (!logs.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty">Chưa có hoạt động nào</td></tr>';
    return;
  }
  tbody.innerHTML = logs.slice(0, 8).map(l => `
    <tr>
      <td class="mono">${formatTime(l.created_at)}</td>
      <td>${escapeHtml(l.profile_name || '-')}</td>
      <td>${escapeHtml(l.action)}</td>
      <td>${escapeHtml(l.detail || '')}</td>
    </tr>`).join('');
}

// ============ DASHBOARD ============
function renderDashboard() {
  if (!profiles.length && !proxies.length) return;
  const running = profiles.filter(p => p.status === 'running').length;
  $('stat-profiles').textContent = profiles.length;
  $('stat-running').textContent = running;
  $('stat-proxies').textContent = proxies.length;
  renderDashboardLogs();
}

// ============ SYNCHRONIZE (MAIN -> CONTROLLED) ============
let synSession = null;
let synRoles = [];
let synSelected = new Set();
let synCfgDirty = false;

function synMarkDirty() { synCfgDirty = true; }
function synBindDirty() {
  ['syn-cfg-mousemove', 'syn-cfg-mouseclick', 'syn-cfg-mousewheel', 'syn-cfg-keyboard', 'syn-cfg-text',
   'syn-cfg-fps', 'syn-cfg-clickdelay', 'syn-cfg-clickrandom', 'syn-cfg-clickvar',
   'syn-cfg-typemin', 'syn-cfg-typemax', 'syn-cfg-inputmode', 'syn-cfg-stoppolicy', 'syn-cfg-showcursor'].forEach(id => {
    const el = $(id);
    if (el && !el.dataset.synbound) { el.dataset.synbound = '1'; el.addEventListener('change', synMarkDirty); }
  });
}

async function loadSyn(full = true) {
  synBindDirty();
  try {
    const res = await getJson(api + 'syncsess.php?action=get');
    if (!res.ok) return;
    synSession = res.data.session;
    synRoles = res.data.roles || [];
    renderSyn(full);
  } catch (e) {}
}

function synRoleBadge(role) {
  if (role === 'MAIN') return '<span class="badge badge-ok">★ MAIN</span>';
  if (role === 'CONTROLLED') return '<span class="badge badge-info">CONTROLLED</span>';
  return '<span class="badge">—</span>';
}
function synStatusBadge(st) {
  const map = { SYNCING: 'badge-ok', PAUSED: 'badge-warn', RUNNING: 'badge-ok', IDLE: '',
                STARTING: 'badge-warn', STOPPING: 'badge-warn', CRASHED: 'badge-danger',
                DISCONNECTED: 'badge-danger', CLOSED: '', STOPPED: '' };
  return `<span class="badge ${map[st] || ''}">${st}</span>`;
}

function renderSyn(full) {
  const st = synSession ? synSession.state : '—';
  $('syn-state').textContent = (synSession ? `#${synSession.id} ${synSession.name} · ` : '') + st;
  const active = synSession && ['RUNNING', 'PAUSED'].includes(synSession.state);
  $('syn-btn-start').disabled = !synSession || !['IDLE', 'STOPPED', 'ERROR'].includes(synSession.state);
  $('syn-btn-stop').disabled = !active && !(synSession && synSession.state === 'STARTING');
  $('syn-btn-pause').disabled = !(synSession && synSession.state === 'RUNNING');
  $('syn-btn-resume').disabled = !(synSession && synSession.state === 'PAUSED');
  if (full && synSession && !synCfgDirty) {
    const c = synSession.config || {};
    $('syn-cfg-mousemove').checked = !!c.mouseMove;
    $('syn-cfg-mouseclick').checked = !!c.mouseClick;
    $('syn-cfg-mousewheel').checked = !!c.mouseWheel;
    $('syn-cfg-keyboard').checked = !!c.keyboard;
    $('syn-cfg-text').checked = !!c.text;
    $('syn-cfg-fps').value = c.fps || 60;
    $('syn-cfg-clickdelay').value = c.clickDelay ?? 20;
    $('syn-cfg-clickrandom').checked = !!c.clickRandom;
    $('syn-cfg-clickvar').value = c.clickVariation ?? 30;
    $('syn-cfg-typemin').value = c.typingMin ?? 50;
    $('syn-cfg-typemax').value = c.typingMax ?? 120;
    $('syn-cfg-inputmode').value = c.inputMode || 'AUTO';
    $('syn-cfg-stoppolicy').value = c.stopPolicy || 'drain';
    $('syn-cfg-showcursor').checked = !!c.showCursor;
  }
  const tb = $('syn-tbody');
  tb.innerHTML = synRoles.map((r, i) => {
    const win = r.hwnd ? `HWND ${r.hwnd}` : '—';
    const acts = [];
    if (r.hwnd) acts.push(`<button class="btn btn-sm" onclick="synViewWindow(${r.hwnd})">View</button>`);
    if (['CRASHED', 'DISCONNECTED'].includes(r.status) && r.role === 'CONTROLLED' && synSession) {
      acts.push(`<button class="btn btn-sm" onclick="synReconnect(${r.id})">Reconnect</button>`);
    }
    if (synSession && r.role !== 'MAIN') {
      acts.push(`<button class="btn btn-sm" onclick="synSetMainOne(${r.id})">Set MAIN</button>`);
    }
    return `<tr>
      <td>${ckHtml(synSelected.has(Number(r.id)), `onchange="synToggle(${r.id}, this.checked)"`)}</td>
      <td>${i + 1}</td>
      <td>${escapeHtml(r.name)}</td>
      <td class="mono">${win}</td>
      <td>${synRoleBadge(r.role)}</td>
      <td>${synStatusBadge(r.status)}</td>
      <td><div class="sync-actions">${acts.join(' ')}</div></td>
    </tr>`;
  }).join('');
  const synAll = $('syn-check-all');
  if (synAll) {
    const allIds = synRoles.map(r => Number(r.id));
    const allOn = allIds.length > 0 && allIds.every(id => synSelected.has(id));
    synAll.checked = allOn;
    synAll.indeterminate = !allOn && allIds.some(id => synSelected.has(id));
  }
}

function synToggle(id, on) { id = Number(id); on ? synSelected.add(id) : synSelected.delete(id); }
function synToggleAll(on) {
  synSelected = new Set(on ? synRoles.map(r => Number(r.id)) : []);
  renderSyn(false);
}
function synSelectAll() { synSelected = new Set(synRoles.map(r => Number(r.id))); renderSyn(false); }
function synSelectNone() { synSelected = new Set(); renderSyn(false); }
function synSelectInvert() {
  const all = new Set(synRoles.map(r => r.id));
  synSelected.forEach(id => all.delete(id));
  synSelected = all; renderSyn(false);
}
function synSelList() { return [...synSelected]; }

async function synStart() {
  if (!synSession) { toast('Chưa có session (tạo session mới trước)', 'error'); return; }
  toast('Đang start sync...', '');
  const res = await getJson(api + `syncsess.php?action=start&id=${synSession.id}`);
  toast(res.message || (res.ok ? 'Đã start' : 'Lỗi'), res.ok ? 'success' : 'error');
  loadSyn(true);
}
async function synStop() {
  if (!synSession) return;
  const okBtn = $('confirm-ok-btn');
  if (okBtn) okBtn.textContent = 'Stop';
  confirmDelete('Stop sync? (browser giữ nguyên, không đóng)', async () => {
    const res = await getJson(api + `syncsess.php?action=stop&id=${synSession.id}`);
    toast(res.message || (res.ok ? 'Đã stop' : 'Lỗi'), res.ok ? 'success' : 'error');
    loadSyn(true);
  });
}
async function synPause() {
  if (!synSession) return;
  const res = await getJson(api + `syncsess.php?action=pause&id=${synSession.id}`);
  toast(res.ok ? 'Đã pause' : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  loadSyn(true);
}
async function synResume() {
  if (!synSession) return;
  const res = await getJson(api + `syncsess.php?action=resume&id=${synSession.id}`);
  toast(res.ok ? 'Đã resume' : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  loadSyn(true);
}
async function synRestart() {
  if (!synSession) return;
  toast('Đang restart (không restart browser)...', '');
  const res = await getJson(api + `syncsess.php?action=restart&id=${synSession.id}`);
  toast(res.message || (res.ok ? 'Đã restart' : 'Lỗi'), res.ok ? 'success' : 'error');
  loadSyn(true);
}
async function synSetMain() {
  const ids = synSelList();
  if (!synSession) { toast('Chưa có session', 'error'); return; }
  if (ids.length !== 1) { toast('Chọn đúng 1 kênh làm MAIN', 'error'); return; }
  const res = await sendJson(api + 'syncsess.php?action=set_main', { id: synSession.id, profileId: ids[0] });
  toast(res.ok ? 'Đã đổi MAIN (MAIN cũ → CONTROLLED)' : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  loadSyn(true);
}
async function synSetMainOne(pid) {
  if (!synSession) return;
  const res = await sendJson(api + 'syncsess.php?action=set_main', { id: synSession.id, profileId: pid });
  toast(res.ok ? 'Đã đổi MAIN' : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  loadSyn(true);
}
async function synMakeControlled() {
  const ids = synSelList();
  if (!synSession) { toast('Chưa có session', 'error'); return; }
  if (!ids.length) { toast('Chọn ít nhất 1 kênh', 'error'); return; }
  for (const pid of ids) {
    await sendJson(api + 'syncsess.php?action=add_controlled', { id: synSession.id, profileId: pid });
  }
  toast(`Đã thêm ${ids.length} CONTROLLED`, 'success');
  loadSyn(true);
}
async function synNewSession() {
  const ids = synSelList();
  if (ids.length < 2) { toast('Chọn ít nhất 2 kênh (1 MAIN + CONTROLLED)', 'error'); return; }
  const main = ids[0];
  const controlled = ids.slice(1);
  confirmDelete(`Tạo session mới?<br>MAIN: <strong>${escapeHtml((synRoles.find(r => r.id === main) || {}).name || main)}</strong><br>CONTROLLED: ${controlled.length} kênh`, async () => {
    const res = await sendJson(api + 'syncsess.php?action=create', { main, controlled, config: synCollectConfig() });
    toast(res.ok ? `Đã tạo session #${res.data.id}` : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
    synCfgDirty = false;
    loadSyn(true);
  });
}
async function synReconnect(pid) {
  if (!synSession) return;
  const res = await sendJson(api + 'syncsess.php?action=reconnect', { id: synSession.id, profileId: pid });
  toast(res.ok ? 'Đã yêu cầu reconnect' : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  loadSyn(true);
}
async function synViewWindow(hwnd) {
  const res = await sendJson(api + 'syncwin.php?action=front', { hwnd });
  if (!res.ok) toast(res.message || 'Lỗi', 'error');
}
function synCollectConfig() {
  return {
    mouseMove: $('syn-cfg-mousemove').checked ? 1 : 0,
    mouseClick: $('syn-cfg-mouseclick').checked ? 1 : 0,
    mouseWheel: $('syn-cfg-mousewheel').checked ? 1 : 0,
    keyboard: $('syn-cfg-keyboard').checked ? 1 : 0,
    text: $('syn-cfg-text').checked ? 1 : 0,
    fps: parseInt($('syn-cfg-fps').value || '60', 10),
    clickDelay: parseInt($('syn-cfg-clickdelay').value || '0', 10),
    clickRandom: $('syn-cfg-clickrandom').checked ? 1 : 0,
    clickVariation: parseInt($('syn-cfg-clickvar').value || '0', 10),
    typingMin: parseInt($('syn-cfg-typemin').value || '0', 10),
    typingMax: parseInt($('syn-cfg-typemax').value || '0', 10),
    inputMode: $('syn-cfg-inputmode').value,
    stopPolicy: $('syn-cfg-stoppolicy').value,
    showCursor: $('syn-cfg-showcursor').checked ? 1 : 0,
  };
}
async function synSaveConfig() {
  if (!synSession) { toast('Chưa có session', 'error'); return; }
  const res = await sendJson(api + 'syncsess.php?action=update_config', { id: synSession.id, config: synCollectConfig() });
  toast(res.ok ? 'Đã lưu cài đặt' : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  if (res.ok) { synCfgDirty = false; $('syn-cfg-result').textContent = 'Đã lưu'; }
  loadSyn(true);
}

// ============ SYNCHRONIZE DEBUG MODE ============
async function synLoadDebug() {
  try {
    const sid = synSession ? synSession.id : 0;
    const res = await getJson(api + `syncsess.php?action=debug&id=${sid}`);
    if (!res.ok) return;
    const d = res.data;
    const snap = d.snapshot;
    $('syn-debug-main').textContent = d.session
      ? `Session #${d.session.id} · ${d.session.state} · engine ${d.engine ? 'ON' : 'OFF'}`
        + (snap ? ` · captured ${snap.captured} / dispatched ${snap.dispatched} / dropped ${snap.dropped} / failed ${snap.failed}` : ' · chưa có snapshot')
        + (snap && snap.lastEvent ? ` · last: #${snap.lastEvent.seq} ${snap.lastEvent.type} (${snap.lastEvent.nx},${snap.lastEvent.ny})` : '')
      : 'Chưa có session';
    const tb = $('syn-debug-tbody');
    if (!snap || !snap.targets) { tb.innerHTML = '<tr><td colspan="5">Chưa có dữ liệu (bật session để thu thập).</td></tr>'; }
    else {
      tb.innerHTML = Object.entries(snap.targets).map(([fid, t]) => `
        <tr><td>#${fid} ${escapeHtml(t.name || '')}</td>
        <td class="mono">${t.x ?? '—'} / ${t.y ?? '—'}</td>
        <td class="mono">${t.vw}x${t.vh}</td>
        <td>${t.queue}</td>
        <td>${t.conn ? '<span class="badge badge-ok">ON</span>' : '<span class="badge badge-danger">OFF</span>'}</td></tr>`).join('');
    }
    const ev = $('syn-debug-events');
    ev.innerHTML = (snap && snap.recent && snap.recent.length)
      ? snap.recent.slice().reverse().map(e => `#${e.seq} ${escapeHtml(e.type)} nx=${e.nx} ny=${e.ny}`).join('<br>')
      : '—';
  } catch (e) {}
}
async function synLoadLogs() {
  try {
    const res = await getJson(api + 'syncsess.php?action=logs&limit=100');
    const el = $('syn-debug-logs');
    el.innerHTML = (res.ok && res.data.length)
      ? res.data.map(l => `[${escapeHtml(l.ts || '')}] ${escapeHtml(l.level || '')} ${escapeHtml(l.event || '')} ${escapeHtml(l.profileId ?? '')} ${escapeHtml(l.message || '')}`).join('<br>')
      : '—';
  } catch (e) {}
}

// ============ SETTINGS ============
async function loadSettings() {
  try {
    const res = await getJson(api + 'settings.php?action=get');
    if (res.ok) {
      settings = res.data;
      $('set-chrome-path').value = settings.chrome_path || '';
      $('set-home-url').value = settings.home_url || '';
      $('set-proxy-timeout').value = settings.proxy_timeout || 5;
      $('set-auto-refresh').checked = !!settings.auto_refresh;
      applyAutoRefresh();
      if ($('set-tab-autosave')) {
        $('set-tab-autosave').checked = settings.tab_autosave !== false;
        $('set-tab-autorestore').checked = settings.tab_autorestore !== false;
        $('set-tab-active').checked = settings.tab_remember_active !== false;
        $('set-tab-interval').value = settings.tab_autosave_interval ?? 30;
      }
      loadWindowSettingsForm(settings);
      loadLayoutSettingsForm(settings);
      loadAccountSettingsForm(settings);
      loadMonitorsIntoSettings();
    }
  } catch (e) {}
}
async function saveSettings() {
  // Luu chung ca 2 nhom de tranh truong hop user sua panel cua so roi chi bam nut nay
  const data = {
    chrome_path: $('set-chrome-path').value.trim(),
    home_url: $('set-home-url').value.trim(),
    proxy_timeout: parseInt($('set-proxy-timeout').value, 10) || 5,
    auto_refresh: $('set-auto-refresh').checked ? '1' : '0'
  };
  if ($('win-preset-grid')) Object.assign(data, collectWindowSettings());
  if ($('set-layout-mode')) Object.assign(data, collectLayoutSettings());
  if ($('set-acc-days')) Object.assign(data, collectAccountSettings());
  if ($('set-tab-autosave')) {
    Object.assign(data, {
      tab_autosave: $('set-tab-autosave').checked ? '1' : '0',
      tab_autorestore: $('set-tab-autorestore').checked ? '1' : '0',
      tab_remember_active: $('set-tab-active').checked ? '1' : '0',
      tab_autosave_interval: parseInt($('set-tab-interval').value, 10) || 30
    });
  }
  if (!data.chrome_path) { toast('Nhập đường dẫn Chrome', 'error'); return; }
  if ($('win-preset-grid')) {
    const werr = validateWindowForm(data);
    if (werr) { toast(werr, 'error'); return; }
  }
  if ($('set-layout-mode')) {
    const lerr = validateLayoutForm(data);
    if (lerr) { toast(lerr, 'error'); return; }
  }
  const res = await sendJson(api + 'settings.php?action=save', data);
  if (res.ok) {
    settings.auto_refresh = !!$('set-auto-refresh').checked;
    settings.home_url = data.home_url;
    settings.chrome_path = data.chrome_path;
    settings.proxy_timeout = data.proxy_timeout;
    applyAutoRefresh();
    if ($('win-preset-grid')) { Object.assign(settings, data); paintWindowCards(); updateWindowLiveBadge(false); }
    if ($('set-layout-mode')) { loadLayoutSettingsForm(settings); }
    if ($('set-acc-days')) { loadAccountSettingsForm(settings); }
  }
  toast(res.ok ? 'Đã lưu cài đặt' : res.message || 'Lỗi', res.ok ? 'success' : 'error');
  $('settings-result').textContent = res.ok ? '✓ Đã lưu' : '';
}
function validateWindowForm(data) {
  data = data || collectWindowSettings();
  if (data.window_preset === 'custom') {
    if (!(data.window_width >= 400 && data.window_width <= 7680)) return 'Chiều rộng phải từ 400 đến 7680';
    if (!(data.window_height >= 300 && data.window_height <= 4320)) return 'Chiều cao phải từ 300 đến 4320';
  }
  if (!(data.window_gap >= 0 && data.window_gap <= 100)) return 'Gap phải từ 0 đến 100 px';
  return null;
}

// ============ WINDOW SETTINGS (Global defaults) ============
const WINDOW_PRESETS = {
  small: [800, 600], standard: [1024, 768], hd: [1280, 720], hd_plus: [1280, 800],
  laptop: [1366, 768], fhd: [1920, 1080], qhd: [2560, 1440], uhd: [3840, 2160]
};
let winPreset = 'hd';
let winPos = 'auto';
function loadWindowSettingsForm(s) {
  if (!$('win-preset-grid')) return;
  winPreset = s.window_preset || 'hd';
  winPos = s.window_position || 'auto';
  $('set-window-fixed').checked = !!s.window_fixed;
  $('set-window-width').value = s.window_width ?? 1280;
  $('set-window-height').value = s.window_height ?? 720;
  $('set-window-x').value = s.window_x ?? 0;
  $('set-window-y').value = s.window_y ?? 0;
  $('set-window-gap').value = s.window_gap ?? 5;
  setWindowMonitorValue(s.window_monitor || 'primary');
  paintWindowCards();
  updateWindowLiveBadge();
}
function paintWindowCards() {
  document.querySelectorAll('#win-preset-grid .preset-card').forEach(c =>
    c.classList.toggle('active', c.dataset.preset === winPreset));
  document.querySelectorAll('#win-pos-grid .seg-card').forEach(c =>
    c.classList.toggle('active', c.dataset.pos === winPos));
  const custom = winPreset === 'custom';
  $('set-window-width').disabled = !custom;
  $('set-window-height').disabled = !custom;
  $('win-custom-pos').classList.toggle('hidden', winPos !== 'custom');
}
function selectWindowPreset(p) {
  winPreset = p;
  if (WINDOW_PRESETS[p]) {
    $('set-window-width').value = WINDOW_PRESETS[p][0];
    $('set-window-height').value = WINDOW_PRESETS[p][1];
  }
  paintWindowCards();
  updateWindowLiveBadge(true);
}
function selectWindowPos(p) {
  winPos = p;
  paintWindowCards();
  updateWindowLiveBadge(true);
}
function updateWindowLiveBadge(draft) {
  const el = $('win-live-badge');
  if (!el) return;
  const w = $('set-window-width').value || '?';
  const h = $('set-window-height').value || '?';
  const fixed = $('set-window-fixed').checked;
  const posName = { auto: 'Tự động', cascade: 'Xếp tầng', grid: 'Lưới', custom: 'Tùy chỉnh' }[winPos] || winPos;
  el.textContent = (fixed ? `${w}×${h}` : 'Tự do') + ` · ${posName}` + (draft ? ' (chưa lưu)' : ' · đang áp dụng');
}
function setWindowMonitorValue(v) {
  const sel = $('set-window-monitor');
  if (!sel) return;
  v = String(v || 'primary');
  if (![...sel.options].some(o => o.value === v)) {
    const o = document.createElement('option');
    o.value = v; o.textContent = v === 'primary' ? 'Màn hình chính' : ('Màn hình ' + v);
    sel.appendChild(o);
  }
  sel.value = v;
}
async function loadMonitorsIntoSettings() {
  try {
    const res = await getJson(api + 'syncwin.php?action=monitors');
    if (!res.ok || !Array.isArray(res.data)) return;
    fillMonitorSelect($('set-window-monitor'), res.data, false,
      (settings && settings.window_monitor) || 'primary');
    fillMonitorSelect($('set-layout-monitor'), res.data, true,
      (settings && settings.layout_monitor) || 'primary');
  } catch (e) {}
}
function fillMonitorSelect(sel, monitors, withAll, cur) {
  if (!sel) return;
  cur = String(cur || 'primary');
  cachedMonitors = monitors || [];
  sel.innerHTML = '<option value="primary">Màn hình chính</option>'
    + (withAll ? '<option value="all">Tất cả màn hình</option>' : '');
  monitors.forEach(m => {
    const o = document.createElement('option');
    const r = m.resolution ? ` - ${m.resolution.w}x${m.resolution.h}` : '';
    o.value = String(m.id);
    o.textContent = `Màn hình ${m.id}${m.primary ? ' (chính)' : ''}${r}`;
    sel.appendChild(o);
  });
  if (withAll) setLayoutMonitorValue(cur);
  else setWindowMonitorValue(cur);
  renderLayoutMonList(monitors);
  fillMainMonSelect(monitors);
}
let cachedMonitors = [];
function renderLayoutMonList(monitors) {
  const box = $('set-layout-monlist');
  if (!box) return;
  const saved = String((settings && settings.layout_monitors) || '');
  const checked = new Set(saved.split(',').map(s => s.trim()).filter(Boolean));
  const limitAll = checked.size === 0;
  box.innerHTML = '';
  (monitors || []).forEach(m => {
    const name = m.name || ('Monitor ' + m.id);
    const res = m.resolution ? `${m.resolution.w}x${m.resolution.h}` : '';
    const row = document.createElement('label');
    row.className = 'mon-check';
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.value = name;
    cb.checked = limitAll || checked.has(name);
    cb.onchange = () => updateLayoutLiveBadge(true);
    const nm = document.createElement('span');
    nm.textContent = `Màn hình ${m.id}${m.primary ? ' (chính)' : ''}`;
    const meta = document.createElement('span');
    meta.className = 'mon-meta';
    meta.textContent = `${res}${m.dpi ? ' · ' + m.dpi.x + 'dpi' : ''}`;
    row.appendChild(cb); row.appendChild(nm); row.appendChild(meta);
    box.appendChild(row);
  });
  if (!(monitors || []).length) {
    box.innerHTML = '<div class="hint">Không thấy màn hình nào.</div>';
  }
}
function fillMainMonSelect(monitors) {
  const sel = $('set-layout-mainmon');
  if (!sel) return;
  const cur = sel.value || (settings && settings.layout_main_monitor) || '';
  sel.innerHTML = '<option value="">-- Không --</option>';
  (monitors || []).forEach(m => {
    const o = document.createElement('option');
    o.value = m.name || '';
    o.textContent = `Màn hình ${m.id}${m.primary ? ' (chính)' : ''}`;
    sel.appendChild(o);
  });
  if (cur && [...sel.options].some(o => o.value === cur)) sel.value = cur;
}
function collectLayoutMonitors() {
  const box = $('set-layout-monlist');
  if (!box) return '';
  return [...box.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value).join(',');
}
function onWindowPresetChange() { paintWindowCards(); }
function syncPresetInputs() { paintWindowCards(); }
function collectWindowSettings() {
  return {
    window_fixed: $('set-window-fixed').checked ? '1' : '0',
    window_preset: winPreset,
    window_width: parseInt($('set-window-width').value, 10),
    window_height: parseInt($('set-window-height').value, 10),
    window_position: winPos,
    window_monitor: $('set-window-monitor').value,
    window_gap: parseInt($('set-window-gap').value, 10),
    window_x: parseInt($('set-window-x').value, 10),
    window_y: parseInt($('set-window-y').value, 10)
  };
}
function winResult(msg, ok) {
  const el = $('window-settings-result');
  if (!el) return;
  el.textContent = msg;
  el.classList.toggle('win-result-ok', ok === true);
  el.classList.toggle('win-result-err', ok === false);
}
async function saveWindowSettings() {
  const btn = $('win-save-btn');
  try {
    if (!$('win-preset-grid') || !$('set-window-width')) {
      toast('Giao diện chưa tải xong (JS cũ?) — bấm Ctrl+F5 tải lại trang', 'error');
      winResult('✗ JS cũ đang cache — Ctrl+F5 rồi thử lại', false);
      return;
    }
    const data = collectWindowSettings();
    const werr = validateWindowForm(data);
    if (werr) { toast(werr, 'error'); winResult('✗ ' + werr, false); return; }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang lưu...'; }
    winResult('⏳ Đang lưu...', null);
    try {
      const res = await sendJson(api + 'settings.php?action=save', data);
      if (!res.ok) {
        winResult('✗ ' + (res.message || 'Lưu thất bại'), false);
        toast(res.message || 'Lưu thất bại', 'error');
      } else {
        // Doc lai tu server de xac nhan DB giu dung gia tri (chong save "ao")
        const chk = await getJson(api + 'settings.php?action=get');
        const sv = chk.ok ? chk.data : null;
        const okVerify = sv && String(sv.window_preset) === String(data.window_preset)
          && parseInt(sv.window_width, 10) === data.window_width
          && parseInt(sv.window_height, 10) === data.window_height;
        const t = new Date().toLocaleTimeString('vi-VN');
        if (okVerify) {
          const size = data.window_fixed === '1' ? `${data.window_width}×${data.window_height}` : 'tự do';
          settings = sv;
          loadWindowSettingsForm(sv);
          winResult(`✓ Đã lưu lúc ${t} — ${size}, áp dụng từ lần Launch tiếp theo`, true);
          toast(`Đã lưu cửa sổ ${size} — Chrome mở sau sẽ dùng cấu hình này`, 'success');
        } else {
          winResult('⚠ Server báo lưu xong nhưng đọc lại khác — bấm Lưu lại giúp mình', false);
          toast('Xác minh sau lưu thất bại, thử lưu lại', 'error');
        }
      }
    } catch (e) {
      winResult('✗ Lỗi kết nối, thử lại', false);
      toast('Lỗi kết nối khi lưu', 'error');
    }
    if (btn) { btn.disabled = false; btn.textContent = '💾 Lưu cửa sổ'; }
  } catch (e) {
    console.error(e);
    toast('Lỗi không ngờ: ' + (e.message || e), 'error');
    try { winResult('✗ Lỗi: ' + (e.message || e), false); } catch (_) {}
    if (btn) { btn.disabled = false; btn.textContent = '💾 Lưu cửa sổ'; }
  }
}
function resetWindowSettings() {
  try {
    confirmDelete('Reset cấu hình cửa sổ về mặc định (HD 1280×720, Fixed ON, Auto, Gap 5, Primary)?', async () => {
      const btn = $('win-reset-btn');
      try {
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang reset...'; }
        winResult('⏳ Đang reset...', null);
        const data = { window_fixed: '1', window_preset: 'hd', window_width: 1280, window_height: 720, window_position: 'auto', window_monitor: 'primary', window_gap: 5, window_x: 0, window_y: 0 };
        const res = await sendJson(api + 'settings.php?action=save', data);
        if (res.ok) {
          await loadSettings();
          const t = new Date().toLocaleTimeString('vi-VN');
          winResult(`✓ Đã reset về mặc định lúc ${t} (1280×720)`, true);
          toast('Đã reset cửa sổ về mặc định 1280×720', 'success');
        } else {
          winResult('✗ ' + (res.message || 'Reset thất bại'), false);
          toast(res.message || 'Reset thất bại', 'error');
        }
      } catch (e) {
        winResult('✗ Lỗi kết nối khi reset', false);
        toast('Lỗi kết nối khi reset', 'error');
      }
      if (btn) { btn.disabled = false; btn.textContent = '↺ Reset mặc định'; }
    });
  } catch (e) {
    console.error(e);
    toast('Lỗi không ngờ khi reset: ' + (e.message || e), 'error');
  }
}
// Cap nhat badge live khi user sua tay (danh dau "chua luu")
document.addEventListener('DOMContentLoaded', () => {
  ['set-window-fixed', 'set-window-width', 'set-window-height', 'set-window-x', 'set-window-y', 'set-window-gap', 'set-window-monitor'].forEach(id => {
    document.addEventListener('change', e => {
      if (e.target && e.target.id === id && $('win-live-badge')) updateWindowLiveBadge(true);
    });
  });
  document.addEventListener('change', e => {
    if (e.target && e.target.id && e.target.id.indexOf('set-layout-') === 0 && $('layout-live-badge')) updateLayoutLiveBadge(true);
  });
});

// ============ LAYOUT SETTINGS (Smart Auto Arrange) ============
function loadLayoutSettingsForm(s) {
  if (!$('set-layout-mode')) return;
  $('set-layout-mode').value = s.layout_mode || 'smart_auto';
  $('set-layout-sizemode').value = s.layout_size_mode || 'auto_fit';
  $('set-layout-multi').checked = !!s.layout_multi;
  $('set-layout-gapx').value = s.layout_gap_x ?? 5;
  $('set-layout-gapy').value = s.layout_gap_y ?? 5;
  $('set-layout-minw').value = s.layout_min_w ?? 500;
  $('set-layout-minh').value = s.layout_min_h ?? 400;
  $('set-layout-taskbar').checked = s.layout_respect_taskbar !== false;
  $('set-layout-visible').checked = s.layout_keep_visible !== false;
  $('set-layout-autolaunch').checked = s.layout_auto_launch !== false;
  $('set-layout-reflow').value = s.layout_reflow || 'ask';
  $('set-layout-fallback').value = s.layout_fallback || 'auto';
  $('set-layout-compactx').value = s.layout_compact_x ?? 150;
  $('set-layout-compacty').value = s.layout_compact_y ?? 40;
  $('set-layout-dist').value = s.layout_distribution || 'smart';
  $('set-layout-balance').value = s.layout_size_balance || 'similar';
  $('set-layout-remember').checked = s.layout_remember_monitors !== false;
  $('set-layout-respectdpi').checked = s.layout_respect_dpi !== false;
  $('set-layout-keepinside').checked = s.layout_keep_inside !== false;
  $('set-layout-disconnect').value = s.layout_disconnect || 'ask';
  setLayoutMonitorValue(s.layout_monitor || 'primary');
  updateLayoutLiveBadge();
}
function setLayoutMonitorValue(v) {
  const sel = $('set-layout-monitor');
  if (!sel) return;
  v = String(v || 'primary');
  if (![...sel.options].some(o => o.value === v)) {
    const o = document.createElement('option');
    o.value = v; o.textContent = v === 'primary' ? 'Màn hình chính' : v === 'all' ? 'Tất cả màn hình' : ('Màn hình ' + v);
    sel.appendChild(o);
  }
  sel.value = v;
}
function updateLayoutLiveBadge(draft) {
  const el = $('layout-live-badge');
  if (!el || !$('set-layout-mode')) return;
  const modeName = { smart_auto: 'Thông minh', grid: 'Lưới', horizontal: 'Hàng ngang', vertical: 'Hàng dọc', cascade: 'Xếp tầng', compact: 'Xếp chồng' }[$('set-layout-mode').value] || '';
  el.textContent = modeName + (draft ? ' (chưa lưu)' : ' · đang áp dụng');
}
function collectLayoutSettings() {
  return {
    layout_mode: $('set-layout-mode').value,
    layout_size_mode: $('set-layout-sizemode').value,
    layout_monitor: $('set-layout-monitor').value,
    layout_multi: $('set-layout-multi').checked ? '1' : '0',
    layout_gap_x: parseInt($('set-layout-gapx').value, 10),
    layout_gap_y: parseInt($('set-layout-gapy').value, 10),
    layout_min_w: parseInt($('set-layout-minw').value, 10),
    layout_min_h: parseInt($('set-layout-minh').value, 10),
    layout_respect_taskbar: $('set-layout-taskbar').checked ? '1' : '0',
    layout_keep_visible: $('set-layout-visible').checked ? '1' : '0',
    layout_auto_launch: $('set-layout-autolaunch').checked ? '1' : '0',
    layout_reflow: $('set-layout-reflow').value,
    layout_fallback: $('set-layout-fallback').value,
    layout_compact_x: parseInt($('set-layout-compactx').value, 10),
    layout_compact_y: parseInt($('set-layout-compacty').value, 10),
    layout_monitors: collectLayoutMonitors(),
    layout_distribution: $('set-layout-dist').value,
    layout_size_balance: $('set-layout-balance').value,
    layout_remember_monitors: $('set-layout-remember').checked ? '1' : '0',
    layout_respect_dpi: $('set-layout-respectdpi').checked ? '1' : '0',
    layout_keep_inside: $('set-layout-keepinside').checked ? '1' : '0',
    layout_disconnect: $('set-layout-disconnect').value,
    layout_main_monitor: $('set-layout-mainmon').value,
    layout_controlled_monitors: ''
  };
}
function validateLayoutForm(d) {
  d = d || collectLayoutSettings();
  if (!['smart_auto', 'grid', 'horizontal', 'vertical', 'cascade', 'compact'].includes(d.layout_mode)) return 'Layout mode không hợp lệ';
  if (!(d.layout_gap_x >= 0 && d.layout_gap_x <= 100)) return 'Khe ngang phải 0–100';
  if (!(d.layout_gap_y >= 0 && d.layout_gap_y <= 100)) return 'Khe dọc phải 0–100';
  if (!(d.layout_min_w >= 200 && d.layout_min_w <= 4000)) return 'Rộng tối thiểu phải 200–4000';
  if (!(d.layout_min_h >= 150 && d.layout_min_h <= 3000)) return 'Cao tối thiểu phải 150–3000';
  if (!(d.layout_compact_x >= 0 && d.layout_compact_x <= 2000)) return 'Lệch ngang phải 0–2000';
  if (!(d.layout_compact_y >= 0 && d.layout_compact_y <= 2000)) return 'Lệch dọc phải 0–2000';
  if (!['smart', 'equal', 'sequential', 'manual'].includes(d.layout_distribution)) return 'Cách chia không hợp lệ';
  if (!['similar', 'maximize'].includes(d.layout_size_balance)) return 'Cân kích thước không hợp lệ';
  return null;
}
function layoutResult(msg, ok) {
  const el = $('layout-settings-result');
  if (!el) return;
  el.textContent = msg;
  el.classList.toggle('win-result-ok', ok === true);
  el.classList.toggle('win-result-err', ok === false);
}
async function saveLayoutSettings() {
  const btn = $('layout-save-btn');
  try {
    if (!$('set-layout-mode')) { toast('Giao diện chưa tải xong — Ctrl+F5 rồi thử lại', 'error'); return; }
    const data = collectLayoutSettings();
    const err = validateLayoutForm(data);
    if (err) { toast(err, 'error'); layoutResult('✗ ' + err, false); return; }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang lưu...'; }
    layoutResult('⏳ Đang lưu...', null);
    const res = await sendJson(api + 'settings.php?action=save', data);
    if (!res.ok) {
      layoutResult('✗ ' + (res.message || 'Lưu thất bại'), false);
      toast(res.message || 'Lưu thất bại', 'error');
    } else {
      const chk = await getJson(api + 'settings.php?action=get');
      const sv = chk.ok ? chk.data : null;
      const okVerify = sv && String(sv.layout_mode) === String(data.layout_mode)
        && parseInt(sv.layout_gap_x, 10) === data.layout_gap_x;
      const t = new Date().toLocaleTimeString('vi-VN');
      if (okVerify) {
        settings = sv;
        loadLayoutSettingsForm(sv);
        layoutResult(`✓ Đã lưu lúc ${t} — lần Sắp xếp tiếp theo dùng cấu hình này`, true);
        toast('Đã lưu cấu hình bố cục', 'success');
      } else {
        layoutResult('⚠ Lưu xong nhưng đọc lại khác — bấm Lưu lại', false);
        toast('Xác minh sau lưu thất bại', 'error');
      }
    }
  } catch (e) {
    console.error(e);
    toast('Lỗi: ' + (e.message || e), 'error');
    try { layoutResult('✗ Lỗi: ' + (e.message || e), false); } catch (_) {}
  }
  if (btn) { btn.disabled = false; btn.textContent = '💾 Lưu bố cục'; }
}
function resetLayoutSettings() {
  try {
    confirmDelete('Reset cấu hình bố cục về mặc định (Thông minh, Tự co, Màn hình chính)?', async () => {
      const btn = $('layout-reset-btn');
      try {
        if (btn) { btn.disabled = true; btn.textContent = '⏳...'; }
        const data = { layout_mode: 'smart_auto', layout_size_mode: 'auto_fit', layout_monitor: 'primary', layout_multi: '0', layout_gap_x: 5, layout_gap_y: 5, layout_min_w: 500, layout_min_h: 400, layout_respect_taskbar: '1', layout_keep_visible: '1', layout_auto_launch: '1', layout_reflow: 'ask', layout_fallback: 'auto', layout_compact_x: 150, layout_compact_y: 40, layout_monitors: '', layout_distribution: 'smart', layout_size_balance: 'similar', layout_remember_monitors: '1', layout_respect_dpi: '1', layout_keep_inside: '1', layout_disconnect: 'ask', layout_main_monitor: '', layout_controlled_monitors: '' };
        const res = await sendJson(api + 'settings.php?action=save', data);
        if (res.ok) {
          await loadSettings();
          layoutResult('✓ Đã reset bố cục về mặc định', true);
          toast('Đã reset bố cục về mặc định', 'success');
        } else {
          layoutResult('✗ ' + (res.message || 'Loi'), false);
          toast(res.message || 'Loi', 'error');
        }
      } catch (e) {
        layoutResult('✗ Loi ket noi', false);
      }
      if (btn) { btn.disabled = false; btn.textContent = '↺ Mặc định'; }
    });
  } catch (e) {
    toast('Lỗi: ' + (e.message || e), 'error');
  }
}

// ============ ACCOUNT SETTINGS ============
function loadAccountSettingsForm(s) {
  if (!$('set-acc-days')) return;
  $('set-acc-days').value = s.acc_min_days ?? 7;
  $('set-acc-checks').value = s.acc_min_checks ?? 10;
  $('set-acc-stab').value = s.acc_ready_stability ?? 80;
  $('set-acc-conf').value = s.acc_ready_confidence ?? 70;
  $('set-acc-review').value = s.acc_review_threshold ?? 50;
  $('set-acc-unavail').value = s.acc_unavail_fails ?? 20;
  $('set-acc-wlogin').value = s.acc_w_login ?? 25;
  $('set-acc-wsession').value = s.acc_w_session ?? 15;
  $('set-acc-wyt').value = s.acc_w_youtube ?? 25;
  $('set-acc-wrate').value = s.acc_w_rate ?? 25;
  $('set-acc-wconsec').value = s.acc_w_consec ?? 10;
  $('set-acc-interval').value = s.acc_check_interval_min ?? 120;
  $('set-acc-maxage').value = s.acc_max_data_age_h ?? 72;
  $('set-acc-batch').value = s.acc_batch ?? 10;
  $('set-acc-concurrency').value = String(s.acc_concurrency ?? 4);
  $('set-acc-onstart').checked = !!s.acc_eval_on_start;
  $('set-acc-bg').checked = !!s.acc_background;
  $('set-acc-autostart').checked = !!s.acc_auto_start;
  $('set-acc-closeafter').checked = !!s.acc_close_after;
  refreshAccountMonitorLine();
}
function collectAccountSettings() {
  return {
    acc_min_days: parseInt($('set-acc-days').value, 10),
    acc_min_checks: parseInt($('set-acc-checks').value, 10),
    acc_ready_stability: parseInt($('set-acc-stab').value, 10),
    acc_ready_confidence: parseInt($('set-acc-conf').value, 10),
    acc_review_threshold: parseInt($('set-acc-review').value, 10),
    acc_unavail_fails: parseInt($('set-acc-unavail').value, 10),
    acc_w_login: parseInt($('set-acc-wlogin').value, 10),
    acc_w_session: parseInt($('set-acc-wsession').value, 10),
    acc_w_youtube: parseInt($('set-acc-wyt').value, 10),
    acc_w_rate: parseInt($('set-acc-wrate').value, 10),
    acc_w_consec: parseInt($('set-acc-wconsec').value, 10),
    acc_check_interval_min: parseInt($('set-acc-interval').value, 10),
    acc_max_data_age_h: parseInt($('set-acc-maxage').value, 10),
    acc_batch: parseInt($('set-acc-batch').value, 10),
    acc_concurrency: parseInt($('set-acc-concurrency').value, 10),
    acc_eval_on_start: $('set-acc-onstart').checked ? '1' : '0',
    acc_background: $('set-acc-bg').checked ? '1' : '0',
    acc_auto_start: $('set-acc-autostart').checked ? '1' : '0',
    acc_close_after: $('set-acc-closeafter').checked ? '1' : '0'
  };
}
function accResult(msg, ok) {
  const el = $('account-settings-result');
  if (!el) return;
  el.textContent = msg;
  el.classList.toggle('win-result-ok', ok === true);
  el.classList.toggle('win-result-err', ok === false);
}
async function saveAccountSettings() {
  const btn = $('acc-save-btn');
  try {
    if (!$('set-acc-days')) { toast('Giao diện chưa tải xong — Ctrl+F5 rồi thử lại', 'error'); return; }
    const data = collectAccountSettings();
    for (const [k, v] of Object.entries(data)) {
      if (typeof v === 'number' && !Number.isFinite(v)) { toast('Giá trị số không hợp lệ', 'error'); accResult('✗ Số không hợp lệ', false); return; }
    }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Đang lưu...'; }
    accResult('⏳ Đang lưu...', null);
    const res = await sendJson(api + 'settings.php?action=save', data);
    if (!res.ok) {
      accResult('✗ ' + (res.message || 'Lưu thất bại'), false);
      toast(res.message || 'Lưu thất bại', 'error');
    } else {
      const chk = await getJson(api + 'settings.php?action=get');
      const sv = chk.ok ? chk.data : null;
      const okVerify = sv && parseInt(sv.acc_min_days, 10) === data.acc_min_days;
      const t = new Date().toLocaleTimeString('vi-VN');
      if (okVerify) {
        settings = sv;
        loadAccountSettingsForm(sv);
        accResult(`✓ Đã lưu lúc ${t}`, true);
        toast('Đã lưu chính sách đánh giá', 'success');
      } else {
        accResult('⚠ Lưu xong nhưng đọc lại khác — bấm Lưu lại', false);
        toast('Xác minh sau lưu thất bại', 'error');
      }
    }
  } catch (e) {
    toast('Lỗi: ' + (e.message || e), 'error');
    try { accResult('✗ Lỗi', false); } catch (_) {}
  }
  if (btn) { btn.disabled = false; btn.textContent = '💾 Lưu đánh giá'; }
}
function resetAccountSettings() {
  confirmDelete('Reset chính sách đánh giá về mặc định?', async () => {
    const data = { acc_min_days: 7, acc_min_checks: 10, acc_ready_stability: 80, acc_ready_confidence: 70, acc_review_threshold: 50, acc_unavail_fails: 20, acc_w_login: 25, acc_w_session: 15, acc_w_youtube: 25, acc_w_rate: 25, acc_w_consec: 10, acc_check_interval_min: 120, acc_max_data_age_h: 72, acc_batch: 10, acc_concurrency: 4, acc_eval_on_start: '0', acc_background: '0', acc_auto_start: '0', acc_close_after: '0' };
    const res = await sendJson(api + 'settings.php?action=save', data);
    if (res.ok) { await loadSettings(); accResult('✓ Đã reset về mặc định', true); toast('Đã reset', 'success'); }
    else { accResult('✗ ' + (res.message || 'Lỗi'), false); toast(res.message || 'Lỗi', 'error'); }
  });
}
async function refreshAccountMonitorLine() {
  const el = $('acc-monitor-line');
  if (!el) return;
  try {
    const res = await getJson(api + 'accounts.php?action=monitor_status');
    if (res.ok && res.data) {
      el.innerHTML = res.data.running
        ? `Monitor: <span class="badge badge-ok">đang chạy (pid ${res.data.pid})</span> <button class="btn btn-sm" onclick="accountMonitorCtl(false)">Dừng</button>`
        : `Monitor: <span class="badge badge-muted">đang tắt</span> <button class="btn btn-sm" onclick="accountMonitorCtl(true)">Chạy nền</button>`;
    }
  } catch (e) {}
}
async function accountMonitorCtl(on) {
  const res = await getJson(api + `accounts.php?action=monitor_${on ? 'start' : 'stop'}`);
  toast(res.ok ? (on ? 'Monitor đã chạy' : 'Monitor đã dừng') : (res.message || 'Lỗi'), res.ok ? 'success' : 'error');
  refreshAccountMonitorLine();
}

// ============ UTILS ============
function showModal(id) { $(id).classList.remove('hidden'); }
function closeModal(id) {
  $(id).classList.add('hidden');
  if (id === 'proxy-modal') pendingProxyAssignProfileId = null;
  if (id === 'bulk-proxy-modal') pendingProxyAssignProfileIds = null;
}
// Modal xác nhận xóa dùng chung: hiển thị câu hỏi chắc chắn, chỉ xóa khi bấm "Xóa"
let confirmDeleteCallback = null;
let confirmDeleteBusy = false;
function confirmDelete(message, onConfirm) {
  const msgEl = $('confirm-msg');
  if (!msgEl) {
    if (confirm(message)) onConfirm && onConfirm();
    return;
  }
  msgEl.innerHTML = message; // cho phép <strong>/<small>/<br> hiển thị đúng (không hiện code)
  confirmDeleteCallback = onConfirm;
  confirmDeleteBusy = false;
  showModal('confirm-modal');
}
function doConfirmDelete() {
  if (confirmDeleteBusy) return; // chống bấm 2 lần / double-fire
  confirmDeleteBusy = true;
  const cb = confirmDeleteCallback;
  confirmDeleteCallback = null;
  hideConfirm();
  cb && cb();
}
function hideConfirm() {
  confirmDeleteCallback = null;
  const m = $('confirm-modal');
  if (m) m.classList.add('hidden');
  const okBtn = $('confirm-ok-btn');
  if (okBtn) okBtn.textContent = 'Xóa'; // tra nut ve mac dinh (reflow doi thanh Arrange tam thoi)
}
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str || '';
  return div.innerHTML;
}
function escapeAttr(str) {
  return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function formatTime(s) {
  if (!s) return '';
  let t;
  if (/Z$|[+-]\d{2}:?\d{2}$/.test(s)) t = new Date(s);
  else t = new Date(s.replace(' ', 'T'));
  return t.toLocaleString('vi-VN');
}