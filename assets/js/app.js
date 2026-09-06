// ============ STATE ============
let profiles = [];
let proxies = [];
let logs = [];
let syncData = [];
let settings = {};
let pendingProxyAssignProfileId = null;
let pendingProxyAssignProfileIds = null;
let selectedProfileIds = new Set();
let profilesPage = 1;
let profilesPerPage = 10;
let profilesFiltered = [];
let proxyPage = 1;
let proxyPerPage = 10;
let selectedProxies = new Set();

const $ = (id) => document.getElementById(id);
const api = 'api/';
const VIEW_TITLES = {
  dashboard: 'Tổng quan', profiles: 'Kênh', proxies: 'Proxy',
  sync: 'Đồng bộ tab', logs: 'Nhật ký', settings: 'Cài đặt'
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
    autoRefreshTimer = setInterval(() => { loadProfiles(); loadSync(); }, 15000);
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
    await Promise.allSettled([loadProfiles(), loadProxies(), loadLogs(), loadSync()]);
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
  if (view === 'logs') loadLogs();
  if (view === 'sync') loadSync();
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
      }
    })
    .catch(() => {});
}

function renderProfiles() {
  const q = ($('profile-search').value || '').toLowerCase();
  const platform = $('profile-filter-platform').value;
  profilesFiltered = profiles.filter(p => {
    if (platform && p.platform !== platform) return false;
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
  const pageIds = new Set(pageItems.map(p => p.id));
  const pageAllSelected = pageItems.length > 0 && pageItems.every(p => selectedProfileIds.has(p.id));
  const selAllEl = $('sel-all');
  if (selAllEl) { selAllEl.checked = pageAllSelected; selAllEl.indeterminate = !pageAllSelected && pageItems.some(p => selectedProfileIds.has(p.id)); }
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
    const checked = selectedProfileIds.has(p.id) ? 'checked' : '';
    return `
      <div class="profile-card ${selectedProfileIds.has(p.id) ? 'card-selected' : ''}">
        <div class="card-check">
          <input type="checkbox" ${checked} onchange="toggleProfileSelect(${p.id}, this.checked)">
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
        </div>
        <div class="card-actions">
          <button class="btn btn-sm btn-primary" onclick="openProfile(${p.id})">▶ Mở</button>
          ${p.status === 'running' ? `<button class="btn btn-sm btn-danger" onclick="closeProfile(${p.id})">■ Đóng</button>` : ''}
          <button class="btn btn-sm" title="Mở kênh để xác nhận không phải bot (sign in)" onclick="openProfile(${p.id})">🛡 Xác nhận</button>
          <button class="btn btn-sm" onclick="openProfileModalEdit(${p.id})">⚙</button>
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

function renderPagination(total) {
  const el = $('profiles-pagination');
  if (!el) return;
  const perPageOpts = [5,10,20,30,50].map(n => `<option value="${n}" ${n === profilesPerPage ? 'selected' : ''}>${n}</option>`).join('');
  const perPageSelect = `<label class="pg-label">Số kênh/trang
    <select class="filter-select" onchange="setPerPage(this.value)">${perPageOpts}</select>
  </label>`;
  if (!total) { el.innerHTML = ''; return; }
  const totalPages = Math.ceil(total / profilesPerPage);
  if (totalPages <= 1) { el.innerHTML = perPageSelect; return; }
  let html = `<span class="pg-info">Trang ${profilesPage}/${totalPages}</span>`;
  html += `<button class="btn btn-sm" ${profilesPage <= 1 ? 'disabled' : ''} onclick="goPage(${profilesPage - 1})">‹ Trước</button>`;
  html += `<button class="btn btn-sm" ${profilesPage >= totalPages ? 'disabled' : ''} onclick="goPage(${profilesPage + 1})">Sau ›</button>`;
  html += perPageSelect;
  el.innerHTML = html;
}

function goPage(n) {
  profilesPage = n;
  renderProfiles();
}
function setPerPage(val) {
  profilesPerPage = parseInt(val, 10) || 10;
  profilesPage = 1;
  renderProfiles();
}
function reloadProfilesView() {
  profilesPage = 1;
  renderProfiles();
}

// ============ SELECT (chọn nhiều kênh) ============
function toggleProfileSelect(id, checked) {
  if (checked) selectedProfileIds.add(id);
  else selectedProfileIds.delete(id);
  renderProfiles();
}
function toggleSelectAll(checked) {
  const pageItems = paginateProfiles();
  pageItems.forEach(p => { checked ? selectedProfileIds.add(p.id) : selectedProfileIds.delete(p.id); });
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
  if (ids) ids.textContent = selectedProfileIds.size ? `Đã chọn ${selectedProfileIds.size}` : '';
}

async function openSelected() {
  const ids = getSelectedIds();
  if (!ids.length) { toast('Chưa chọn kênh nào', 'error'); return; }
  for (const id of ids) {
    try { await getJson(api + `browser.php?action=open&id=${id}`); } catch (e) {}
  }
  toast(`Đã mở ${ids.length} kênh`, 'success');
  refreshAll();
}
async function closeSelected() {
  const ids = getSelectedIds();
  if (!ids.length) { toast('Chưa chọn kênh nào', 'error'); return; }
  for (const id of ids) {
    try { await getJson(api + `browser.php?action=close&id=${id}`); } catch (e) {}
  }
  toast(`Đã đóng ${ids.length} kênh`, 'success');
  refreshAll();
}
async function deleteSelected() {
  if (!selectedProfileIds.size) { toast('Chưa chọn kênh nào', 'error'); return; }
  const n = selectedProfileIds.size;
  confirmDelete(`Bạn chắc chắn muốn xóa ${n} kênh đã chọn?<br><small>Thư mục dữ liệu của các kênh sẽ được giữ nguyên.</small>`, async () => {
    const ids = getSelectedIds();
    for (const id of ids) {
      try { await del(api + `profiles.php?action=delete&id=${id}`); } catch (e) {}
    }
    toast(`Đã xóa ${ids.length} kênh`, 'success');
    refreshAll();
  });
}
function assignProxySelected() {
  const ids = getSelectedIds();
  if (!ids.length) { toast('Chưa chọn kênh nào, bấm lại "Gán proxy" sau khi chọn', 'error'); return; }
  pendingProxyAssignProfileIds = ids;
  populateBulkProxySelect();
  setBulkProxyMode('single');
  $('bulk-proxy-count').textContent = `Sẽ gán proxy cho ${ids.length} kênh đã chọn.`;
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
      `<option value="${p.id}" ${p.id == selectedId ? 'selected' : ''}>${escapeHtml(p.name)} (${escapeHtml(p.host)}:${p.port})</option>`
    ).join('');
}
async function saveBulkProxy() {
  if (!pendingProxyAssignProfileIds || !pendingProxyAssignProfileIds.length) return;
  const ids = pendingProxyAssignProfileIds;
  pendingProxyAssignProfileIds = null;

  // MODE LIST: paste danh sách proxy -> gán theo thứ tự
  if (bulkProxyMode === 'list') {
    const list = $('bulk-proxy-list').value;
    if (!list.trim()) {
      toast('Nhập danh sách proxy trước', 'error');
      pendingProxyAssignProfileIds = ids;
      return;
    }
    const res = await sendJson(api + 'profiles.php?action=assign_proxy_list_bulk', { ids, list });
    if (res.ok) {
      toast(`Đã gán proxy cho ${res.updated}/${ids.length} kênh (${res.proxy_count} proxy)`, 'success');
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

  // MODE SINGLE: chọn 1 proxy
  const proxyId = $('bulk-proxy-select').value || null;
  const res = await sendJson(api + 'profiles.php?action=assign_proxy_bulk', { ids, proxy_id: proxyId });
  toast(res.ok ? `Đã gán proxy cho ${res.updated || 0} kênh` : res.message || 'Lỗi', res.ok ? 'success' : 'error');
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
  const st = p.status || 'stopped';
  if (st === 'running') return { state: 'running', label: 'Đang chạy' };
  if (st === 'error') return { state: 'error', label: 'Lỗi' };
  return { state: 'stopped', label: 'Dừng' };
}

function platLabel(p) {
  return { youtube: 'YouTube', tiktok: 'TikTok', facebook: 'Facebook', other: 'Khác' }[p] || 'YouTube';
}

// ============ PROFILE ACTIONS ============
async function openProfile(id) {
  const res = await getJson(api + `browser.php?action=open&id=${id}`);
  toast(res.message || 'Đã mở', res.ok ? 'success' : 'error');
  if (res.proxy_dead) loadProxies();
  if (res.ok) refreshAll();
}
async function deleteProfile(id) {
  const p = profiles.find(x => x.id === id);
  const name = p ? p.name : 'kênh này';
  confirmDelete(`Bạn chắc chắn muốn xóa kênh <strong>${escapeHtml(name)}</strong>?<br><small>Thư mục dữ liệu của kênh sẽ được giữ nguyên.</small>`, async () => {
    const res = await del(api + `profiles.php?action=delete&id=${id}`);
    toast(res.ok ? 'Đã xóa kênh' : 'Lỗi', res.ok ? 'success' : 'error');
    refreshAll();
  });
}
async function closeProfile(id) {
  const res = await getJson(api + `browser.php?action=close&id=${id}`);
  toast(res.message || 'Đã đóng', res.ok ? 'success' : 'error');
  refreshAll();
}
async function openAllProfiles() {
  for (const p of profiles) {
    try { await getJson(api + `browser.php?action=open&id=${p.id}`); } catch (e) {}
    await sleep(700);
  }
  toast(`Đã mở ${profiles.length} kênh`, 'success');
  refreshAll();
}
async function closeAllProfiles() {
  for (const p of profiles) {
    try { await getJson(api + `browser.php?action=close&id=${p.id}`); } catch (e) {}
  }
  toast('Đã đóng tất cả kênh', 'success');
  refreshAll();
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
function openProfileModal() {
  $('profile-modal-title').textContent = 'Tạo kênh mới';
  $('pf-id').value = '';
  $('pf-name').value = '';
  $('pf-platform').value = 'youtube';
  $('pf-handle').value = '';
  $('pf-prefix').value = 'Kênh';
  $('pf-count').value = '5';
  $('pf-ua').value = '';
  $('pf-webrtc').value = 'default';
  setProfileMode('single');
  populateProxySelect();
  showModal('profile-modal');
}
function openProfileModalEdit(id) {
  const p = profiles.find(x => x.id === id);
  if (!p) return;
  $('profile-modal-title').textContent = 'Sửa kênh: ' + p.name;
  $('pf-id').value = p.id;
  $('pf-name').value = p.name;
  $('pf-platform').value = p.platform;
  $('pf-handle').value = p.channel_handle || '';
  $('pf-ua').value = p.user_agent || '';
  $('pf-webrtc').value = p.webrtc_protection || 'default';
  setProfileMode('single');
  populateProxySelect(p.proxy_id);
  showModal('profile-modal');
}
function populateProxySelect(selectedId) {
  const sel = $('pf-proxy');
  sel.innerHTML = '<option value="">Không dùng proxy</option>' +
    proxies.map(p =>
      `<option value="${p.id}" ${p.id == selectedId ? 'selected' : ''}>${escapeHtml(p.name)} (${escapeHtml(p.host)}:${p.port})</option>`
    ).join('');
}
async function saveProfile() {
  const id = $('pf-id').value;
  const platform = $('pf-platform').value;
  const proxy_id = $('pf-proxy').value || null;

  // MODE BULK: tạo nhiều kênh
  if (!id && profileMode === 'bulk') {
    const count = parseInt($('pf-count').value, 10) || 0;
    const prefix = $('pf-prefix').value.trim() || 'Kênh';
    if (count < 1) { toast('Nhập số lượng kênh hợp lệ', 'error'); return; }
    const res = await sendJson(api + 'profiles.php?action=add_bulk', { count, prefix, platform, proxy_id });
    toast(res.ok ? `Đã tạo ${res.count || 0} kênh (${prefix})` : res.message || 'Lỗi', res.ok ? 'success' : 'error');
    if (res.ok) { closeModal('profile-modal'); refreshAll(); }
    return;
  }

  // MODE SINGLE
  const data = {
    id: id || undefined,
    name: $('pf-name').value.trim(),
    platform,
    channel_handle: $('pf-handle').value.trim() || null,
    user_agent: $('pf-ua').value.trim() || null,
    webrtc_protection: $('pf-webrtc').value,
    proxy_id
  };
  if (!data.name) { toast('Vui lòng nhập tên kênh', 'error'); return; }
  const res = await sendJson(api + 'profiles.php?action=' + (id ? 'update' : 'add'), data);
  toast(res.ok ? 'Đã lưu' : res.message || 'Lỗi', res.ok ? 'success' : 'error');
  if (res.ok) { closeModal('profile-modal'); refreshAll(); }
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
    const checked = selectedProxies.has(p.id) ? 'checked' : '';
    return `
      <tr class="${selectedProxies.has(p.id) ? 'row-selected' : ''}">
        <td><input type="checkbox" class="px-check" data-id="${p.id}" ${checked} onchange="toggleProxySelect(${p.id}, this.checked)"></td>
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
  if (!proxies.length) { el.innerHTML = ''; return; }
  const totalPages = Math.max(1, Math.ceil(proxies.length / proxyPerPage));
  const start = (proxyPage - 1) * proxyPerPage + 1;
  const end = Math.min(proxyPage * proxyPerPage, proxies.length);
  el.innerHTML = `
    <span class="pg-info">Hiển thị ${start}–${end} / ${proxies.length}</span>
    <button class="btn btn-sm" ${proxyPage <= 1 ? 'disabled' : ''} onclick="gotoProxyPage(${proxyPage - 1})">‹ Trước</button>
    <span class="pg-info">Trang ${proxyPage}/${totalPages}</span>
    <button class="btn btn-sm" ${proxyPage >= totalPages ? 'disabled' : ''} onclick="gotoProxyPage(${proxyPage + 1})">Sau ›</button>
    <label class="pg-label">Mỗi trang <select class="filter-select" id="proxy-per-page" onchange="setProxyPerPage(this.value)">
      ${[5, 10, 20, 30, 50].map(n => `<option value="${n}" ${n === proxyPerPage ? 'selected' : ''}>${n}</option>`).join('')}
    </select></label>`;
}

function gotoProxyPage(page) {
  proxyPage = Math.max(1, Math.min(page, Math.max(1, Math.ceil(proxies.length / proxyPerPage))));
  renderProxies();
}
function setProxyPerPage(n) {
  proxyPerPage = Math.max(1, parseInt(n, 10) || 10);
  proxyPage = 1;
  renderProxies();
}

// ---- chọn nhiều proxy ----
function toggleProxySelect(id, checked) {
  if (checked) selectedProxies.add(id); else selectedProxies.delete(id);
  syncProxySelectUI();
}
function toggleSelectAllProxies(checked) {
  selectedProxies = checked ? new Set(proxies.map(p => p.id)) : new Set();
  syncProxySelectUI();
}
function syncProxySelectUI() {
  const count = selectedProxies.size;
  const el = $('selected-proxies-count');
  if (el) el.textContent = count ? `Đã chọn ${count}` : '';

  const all = proxies.length > 0 && selectedProxies.size === proxies.length;
  const headAll = $('sel-all-proxies-head');
  const toolAll = $('sel-all-proxies');
  if (headAll) headAll.checked = all;
  if (toolAll) toolAll.checked = all;

  document.querySelectorAll('.px-check').forEach(cb => {
    cb.checked = selectedProxies.has(parseInt(cb.dataset.id, 10));
  });
  document.querySelectorAll('tr.row-selected').forEach(tr => tr.classList.remove('row-selected'));
  proxies.forEach(p => {
    if (selectedProxies.has(p.id)) {
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
  $('px-user').value = p.username || ''; $('px-pass').value = p.password || '';
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
  const totalTabs = syncData.reduce((s, x) => s + (x.tab_count || 0), 0);
  $('stat-profiles').textContent = profiles.length;
  $('stat-running').textContent = running;
  $('stat-proxies').textContent = proxies.length;
  $('stat-tabs').textContent = totalTabs;
  renderDashboardLogs();
}

// ============ SYNC (Dong bo tab) ============
async function loadSync() {
  try {
    const res = await getJson(api + 'sync.php?action=list');
    if (res.ok) {
      syncData = res.data;
      renderSync();
      renderDashboard();
    }
  } catch (e) {}
}

function renderSync() {
  const el = $('sync-results');
  if (!syncData.length) {
    el.innerHTML = '<div class="sync-empty">Chưa có kênh nào. Tạo kênh trước để đồng bộ.</div>';
    $('sync-summary').textContent = '';
    return;
  }
  const totalRunning = syncData.filter(x => x.status === 'running').length;
  const totalTabs = syncData.reduce((s, x) => s + (x.tab_count || 0), 0);
  $('sync-summary').textContent = `${syncData.length} kênh · ${totalRunning} đang chạy · ${totalTabs} tab đang mở`;

  el.innerHTML = syncData.map(p => {
    const st = p.status === 'running' ? '<span class="badge badge-ok">● Đang chạy</span>' : '<span class="badge badge-warn">○ Đã dừng</span>';
    const portInfo = p.port ? `<span class="status-unknown mono">port ${p.port}</span>` : '';

    let body = '';
    if (p.status === 'running') {
      if (!p.tabs.length) {
        body = '<div class="sync-empty">Chrome đang khởi động... hoặc chưa có tab nào</div>';
      } else {
        body = p.tabs.map((t, idx) => {
          const title = t.title || '(không có tiêu đề)';
          const favicon = t.url ? getFavicon(t.url) : '';
          return `
            <div class="sync-tab-item">
              <img src="${favicon}" onerror="this.style.display='none'" alt="" width="16" height="16">
              <div class="sync-tab-info">
                <div class="sync-tab-title" title="${escapeAttr(t.title || '')}">${escapeHtml(title)}</div>
                <div class="sync-tab-url" title="${escapeAttr(t.url || '')}">${escapeHtml(t.url || '')}</div>
              </div>
              <div class="sync-actions">
                <button class="btn btn-sm" onclick="syncOpenInProfile(${p.id}, '${escapeAttr(t.url)}')">Tab mới</button>
                <button class="btn btn-sm btn-danger" onclick="syncCloseTab(${p.id}, '${escapeAttr(t.id)}')">Đóng tab</button>
              </div>
            </div>`;
        }).join('');
      }
    } else {
      body = '<div class="sync-empty">Chưa mở Chrome. Bấm "Mở kênh" hoặc đồng bộ URL để chạy.</div>';
    }

    return `
      <div class="sync-card">
        <div class="sync-card-head">
          <div class="avatar">${(p.name || '?').trim()[0].toUpperCase()}</div>
          <span class="p-name">${escapeHtml(p.name)}</span>
          ${st} ${portInfo}
          <div class="spacer"></div>
          <button class="btn btn-sm" onclick="openProfile(${p.id})">Mở kênh</button>
          <button class="btn btn-sm btn-danger" onclick="syncCloseAllTabs(${p.id})">Đóng hết tab</button>
        </div>
        <div class="sync-card-body">${body}</div>
      </div>`;
  }).join('');
}

function getFavicon(url) {
  try {
    return 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(new URL(url).hostname) + '&sz=32';
  } catch (e) { return ''; }
}

async function syncOpenUrl() {
  const url = $('sync-url').value.trim();
  if (!url) { toast('Nhập URL trước', 'error'); return; }
  let finalUrl = url;
  if (!/^https?:\/\//i.test(url)) finalUrl = 'https://' + url;
  await doSyncOpen(finalUrl);
}
function syncOpen(url) {
  if (!url) { toast('Thiếu URL', 'error'); return; }
  doSyncOpen(url);
}
function syncOpenHandle() {
  const h = $('sync-handle').value.trim();
  if (!h) { toast('Nhập handle', 'error'); return; }
  const handle = h.startsWith('@') ? h : '@' + h;
  doSyncOpen('https://www.youtube.com/' + encodeURIComponent(handle));
}
async function doSyncOpen(url) {
  toast('Đang đồng bộ mở URL...', '');
  const res = await sendJson(api + 'sync.php?action=open_url', { url });
  toast(res.message || 'Xong', res.ok ? 'success' : 'error');
  refreshAll();
}
async function syncOpenInProfile(profileId, url) {
  if (!url) return;
  toast('Mở tab mới cho kênh...', '');
  const res = await sendJson(api + `sync.php?action=open_tab&profile_id=${profileId}`, { url });
  toast(res.message || 'Xong', res.ok ? 'success' : 'error');
  refreshAll();
}
async function syncCloseTab(profileId, tabId) {
  if (!confirm('Đóng tab này?')) return;
  const res = await getJson(api + `sync.php?action=close_tab&profile_id=${profileId}&tab_id=${encodeURIComponent(tabId)}`);
  toast(res.message || 'Xong', res.ok ? 'success' : 'error');
  loadSync();
}
async function syncCloseAllTabs(profileId) {
  if (!confirm('Đóng tất cả tab của kênh này?')) return;
  const res = await getJson(api + `sync.php?action=close_all_tabs&profile_id=${profileId}`);
  toast(res.message || 'Xong', res.ok ? 'success' : 'error');
  loadSync();
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
    }
  } catch (e) {}
}
async function saveSettings() {
  const data = {
    chrome_path: $('set-chrome-path').value.trim(),
    home_url: $('set-home-url').value.trim(),
    proxy_timeout: parseInt($('set-proxy-timeout').value, 10) || 5,
    auto_refresh: $('set-auto-refresh').checked ? '1' : '0'
  };
  if (!data.chrome_path) { toast('Nhập đường dẫn Chrome', 'error'); return; }
  const res = await sendJson(api + 'settings.php?action=save', data);
  if (res.ok) {
    settings.auto_refresh = !!$('set-auto-refresh').checked;
    settings.home_url = data.home_url;
    settings.chrome_path = data.chrome_path;
    settings.proxy_timeout = data.proxy_timeout;
    applyAutoRefresh();
  }
  toast(res.ok ? 'Đã lưu cài đặt' : res.message || 'Lỗi', res.ok ? 'success' : 'error');
  $('settings-result').textContent = res.ok ? '✓ Đã lưu' : '';
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