// ============ MONITORING: Thong ke & Theo doi ============
// ChannelStateStore: nguon state thong nhat runtime/evaluation/proxy/
// monitoring/health/alerts cho Card + Monitoring + Drawer (khong tu tinh rieng).
const ChannelStateStore = {
  byId: new Map(),
  syncFromProfiles(list) {
    (list || []).forEach(p => {
      const prev = this.byId.get(Number(p.id));
      this.byId.set(Number(p.id), {
        runtime: p.status || 'stopped',
        evaluation: p.eval_status || 'UNCHECKED',
        proxy: p.proxy_id ? (p.proxy_status || 'unknown') : 'none',
        monitoring: p.monitor_enabled === 0 ? 'OFF' : 'ON',
        watchlist: !!p.watchlist,
        lastCheck: p.eval_attempt || p.acc_checked || null,
        prevEval: (prev && prev.evaluation === 'CHECKING') ? prev.prevEval : prev?.evaluation,
      });
    });
  },
  patchEval(r) {
    const s = this.byId.get(Number(r.profileId)) || {};
    s.prevEval = s.evaluation;
    s.evaluation = r.status || 'ERROR';
    s.lastCheck = r.checked_at || s.lastCheck;
    this.byId.set(Number(r.profileId), s);
  },
  get(id) { return this.byId.get(Number(id)); },
};
// Hook vao evalApplyResult co san (realtime: KPI + row monitoring)
const _evalApplyResult = evalApplyResult;
evalApplyResult = function (r) {
  const ok = _evalApplyResult(r);
  try {
    ChannelStateStore.patchEval(r);
    if (document.getElementById('view-monitoring')?.classList.contains('active')) {
      monPatchRow(r);
      monDebouncedKpi();
    }
    monBadgeTick();
  } catch (e) {}
  return ok;
};

let monTabCur = 'overview';
let monRangeDays = 7;
let monCustomFrom = '', monCustomTo = '';
let monPage = 1, monPerPage = 20, monTotal = 0;
let monChipCur = '';
let monSearchTimer = null;
let monKpiTimer = null;
let monTrendDays = 7;
let monHistPage = 1;
document.addEventListener('DOMContentLoaded', () => { setTimeout(monBadgeTick, 3000); });
const MON_SEV_VN = { CRITICAL: 'Nghiêm trọng', WARNING: 'Cảnh báo', INFO: 'Thông tin' };

function monTab(name) {
  monTabCur = name;
  document.querySelectorAll('.mon-tab').forEach(b => b.classList.toggle('active', b.dataset.mtab === name));
  document.querySelectorAll('.mon-pane').forEach(p => p.classList.toggle('hidden', p.id !== 'mon-pane-' + name));
  if (name === 'overview') monLoadOverview();
  if (name === 'channels') monLoadChannels(monPage);
  if (name === 'alerts') monLoadAlerts();
  if (name === 'trends') monLoadTrends();
  if (name === 'history') monLoadHistory(1);
}
function monSetRange(d) {
  monRangeDays = d; monCustomFrom = ''; monCustomTo = '';
  document.querySelectorAll('#mon-range button').forEach(b => b.classList.toggle('active', Number(b.dataset.range) === d));
  $('mon-from').classList.add('hidden'); $('mon-to').classList.add('hidden');
  monRefresh();
}
function monSetRangeCustom() {
  document.querySelectorAll('#mon-range button').forEach(b => b.classList.toggle('active', b.dataset.range === 'custom'));
  $('mon-from').classList.remove('hidden'); $('mon-to').classList.remove('hidden');
}
function monRangeCustomGo() {
  monCustomFrom = $('mon-from').value; monCustomTo = $('mon-to').value;
  if (monCustomFrom) monLoadHistory(1);
}
async function monRefresh(hard) {
  if (!$('view-monitoring').classList.contains('active') && !hard) return;
  ChannelStateStore.syncFromProfiles(profiles);
  monTab(monTabCur);
  monBadgeTick();
}
function monDebouncedKpi() {
  clearTimeout(monKpiTimer);
  monKpiTimer = setTimeout(() => { if (monTabCur === 'overview') monLoadOverview(true); }, 800);
}
async function monBadgeTick() {
  try {
    const r = await getJson(api + 'monitoring.php?action=summary');
    if (!r.ok) return;
    const n = r.data.summary.alerts || 0;
    const el = $('nav-alert-count');
    if (el) { el.textContent = n; el.classList.toggle('hidden', !n); }
    const tn = $('mon-tab-alert-n');
    if (tn) { tn.textContent = n; tn.classList.toggle('hidden', !n); }
  } catch (e) {}
}
// ---- Overview ----
async function monLoadOverview(quiet) {
  try {
    const r = await getJson(api + 'monitoring.php?action=summary');
    if (!r.ok) throw new Error();
    const s = r.data.summary;
    const kpi = [
      ['Tổng kênh', s.total, '', ''],
      ['Hoạt động', s.active, 'green', 'ACTIVE'],
      ['Có vấn đề', s.issues, s.issues ? 'red' : '', 'ISSUES'],
      ['Chưa kiểm tra', s.unchecked, '', 'UNCHECKED'],
    ];
    $('mon-kpi').innerHTML = kpi.map(([l, v, c, f]) =>
      `<div class="stat-card mon-kpi" ${f ? `onclick="monKpiFilter('${f}')"` : ''}><div class="stat-value ${c}">${v}</div><div class="stat-label">${l}</div></div>`).join('');
    const sub = [
      ['Chrome đang chạy', s.running, 'RUNNING'],
      ['Có Proxy', s.withProxy, ''],
      ['Đang đánh giá', s.checking, 'CHECKING'],
      ['Cảnh báo mới', s.alerts, s.alertsCritical ? 'red' : ''],
    ];
    $('mon-kpi-sub').innerHTML = sub.map(([l, v, f]) =>
      `<div class="stat-card mon-kpi" ${f ? `onclick="monKpiFilter('${f}')"` : ''}><div class="stat-value">${v}</div><div class="stat-label">${l}</div></div>`).join('');
    // Health bars
    $('mon-health').innerHTML = r.data.distribution.map(d => {
      const [label] = EVAL_STATUS[d.status] || [d.status];
      const cls = d.status === 'ACTIVE' ? 'ok' : (['LOGIN_REQUIRED', 'VERIFICATION_REQUIRED'].includes(d.status) ? 'mid' : (d.status === 'UNCHECKED' || d.status === 'CHECKING' ? '' : 'low'));
      return `<div class="mon-health-row" onclick="monKpiFilter('${d.status}')" title="Lọc kênh ${label}">`
        + `<span class="mon-health-label">${label}</span>`
        + `<span class="acc-bar" style="flex:1"><i class="${cls}" style="width:${d.pct}%"></i></span>`
        + `<strong>${d.count}</strong><span class="muted">${d.pct}%</span></div>`;
    }).join('');
    monLoadAlertsMini();
    monLoadRecent();
    monLoadDist();
  } catch (e) {
    if (!quiet) $('mon-kpi').innerHTML = '<div class="empty-state">Không tải được dữ liệu <button class="btn btn-sm" onclick="monLoadOverview()">Thử lại</button></div>';
  }
}
function monKpiFilter(f) {
  // KPI click -> filter bang channel (khong reload page)
  monChipCur = '';
  document.querySelectorAll('#mon-chips .chip').forEach(c => c.classList.toggle('active', false));
  $('mon-f-eval').value = '';
  $('mon-f-chrome').value = '';
  $('mon-f-proxy').value = '';
  $('mon-f-watch').checked = false;
  $('mon-f-alert').checked = false;
  if (f === 'ISSUES') { $('mon-f-eval').value = ''; monChipCur = 'ISSUES'; }
  else if (f === 'RUNNING') $('mon-f-chrome').value = 'running';
  else if (f === 'CHECKING') $('mon-f-eval').value = 'CHECKING';
  else if (['ACTIVE', 'UNCHECKED', 'LOGIN_REQUIRED', 'VERIFICATION_REQUIRED', 'UNAVAILABLE', 'ERROR'].includes(f)) $('mon-f-eval').value = f;
  monTab('channels');
  monLoadChannels(1);
}
async function monLoadAlertsMini() {
  try {
    const r = await getJson(api + 'monitoring.php?action=alerts&status=OPEN&limit=5');
    const list = r.ok ? r.data.alerts : [];
    $('mon-alerts-mini').innerHTML = list.length ? list.map(a => monAlertRow(a, true)).join('')
      : '<div class="empty-state">Không có cảnh báo đang mở</div>';
  } catch (e) { $('mon-alerts-mini').innerHTML = '<div class="empty-state">Không tải được dữ liệu</div>'; }
}
async function monLoadRecent() {
  try {
    const r = await getJson(api + 'monitoring.php?action=recent&limit=10');
    const list = r.ok ? r.data : [];
    $('mon-recent').innerHTML = list.length ? `<div class="timeline">` + list.map(h =>
      `<div class="tl-item"><span class="mono muted">${escapeHtml((h.ts || '').slice(5, 16))}</span> `
      + `<strong>${escapeHtml(h.profile_name || ('#' + h.profile_id))}</strong> `
      + `<span class="muted">${escapeHtml(h.category)}</span> `
      + `${escapeHtml(h.old_value || '-')} → <strong>${escapeHtml(h.new_value || '-')}</strong></div>`).join('') + `</div>`
      : '<div class="empty-state">Chưa có thay đổi</div>';
  } catch (e) { $('mon-recent').innerHTML = '<div class="empty-state">Không tải được dữ liệu</div>'; }
}
async function monLoadDist() {
  const by = $('mon-dist-by').value;
  try {
    const r = await getJson(api + `monitoring.php?action=distribution&by=${by}`);
    const rows = r.ok ? r.data : [];
    const tot = Math.max(1, rows.reduce((a, x) => a + Number(x.c), 0));
    $('mon-dist').innerHTML = rows.length ? rows.map(x =>
      `<div class="mon-health-row"><span class="mon-health-label">${escapeHtml(x.k)}</span>`
      + `<span class="acc-bar" style="flex:1"><i style="width:${Math.round(100 * x.c / tot)}%"></i></span>`
      + `<strong>${x.c}</strong></div>`).join('') : '<div class="empty-state">Không có dữ liệu</div>';
  } catch (e) { $('mon-dist').innerHTML = '<div class="empty-state">Không tải được dữ liệu</div>'; }
}
// ---- Channels table ----
function monChip(f) {
  monChipCur = (monChipCur === f) ? '' : f;
  document.querySelectorAll('#mon-chips .chip').forEach(c => c.classList.toggle('active', c.dataset.chip === monChipCur));
  monLoadChannels(1);
}
function monSearch() {
  clearTimeout(monSearchTimer);
  monSearchTimer = setTimeout(() => monLoadChannels(1), 300);
}
function monChipParams() {
  const p = {};
  if (monChipCur === 'ISSUES') p.eval = 'ISSUES';
  else if (monChipCur === 'WATCH') p.watch = 1;
  else if (monChipCur === 'RUNNING') p.chrome = 'running';
  else if (monChipCur === 'proxy_dead') p.proxy = 'dead';
  else if (monChipCur) p.eval = monChipCur;
  return p;
}
async function monLoadChannels(page) {
  monPage = page || 1;
  const cp = monChipParams();
  const q = new URLSearchParams({
    search: $('mon-search').value.trim(),
    eval: cp.eval || $('mon-f-eval').value,
    chrome: cp.chrome || $('mon-f-chrome').value,
    stage: $('mon-f-stage').value,
    platform: $('mon-f-platform').value,
    proxy: cp.proxy || $('mon-f-proxy').value,
    watch: cp.watch ? '1' : ($('mon-f-watch').checked ? '1' : ''),
    alert: $('mon-f-alert').checked ? '1' : '',
    page: monPage, per: monPerPage,
  });
  try {
    const r = await getJson(api + 'monitoring.php?action=channels&' + q.toString());
    if (!r.ok) throw new Error();
    const rows = r.data.rows;
    monTotal = r.data.total;
    $('mon-tbody').innerHTML = rows.length ? rows.map(monRowHtml).join('')
      : '<tr><td colspan="13"><div class="empty-state">Không có kênh nào khớp bộ lọc.</div></td></tr>';
    $('mon-pagination').innerHTML = pgBarHTML(monTotal, monPage, monPerPage, { unit: 'kênh', onpage: 'monGoPage', onperpage: 'monSetPerPage' });
  } catch (e) {
    $('mon-tbody').innerHTML = '<tr><td colspan="13"><div class="empty-state">Không tải được dữ liệu <button class="btn btn-sm" onclick="monLoadChannels(1)">Thử lại</button></div></td></tr>';
  }
}
function monGoPage(n) { monLoadChannels(n); }
function monSetPerPage(v) { monPerPage = [5, 10, 20, 30, 50, 100].includes(+v) ? +v : 20; monLoadChannels(1); }
function monRowHtml(x) {
  const es = x.eval_status || 'UNCHECKED';
  const rt = (x.runtime || 'stopped');
  const px = x.proxy_id ? (x.proxy_status === 'dead' ? '<span class="badge badge-danger">Proxy lỗi</span>' : (x.proxy_status === 'alive' ? '<span class="badge badge-ok">Proxy tốt</span>' : '<span class="badge badge-muted">Proxy?</span>')) : '<span class="muted">No proxy</span>';
  return `<tr id="mon-row-${x.id}">`
    + `<td><span class="ck"><input type="checkbox" class="mon-check" data-id="${x.id}"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span></td>`
    + `<td><strong class="mon-link" onclick="monOpenDrawer(${x.id})">${escapeHtml(x.name)}</strong>${x.watchlist ? ' ⭐' : ''}<div class="muted"><small>${escapeHtml(x.channel_handle || '')}</small></div></td>`
    + `<td>${evalBadge(es)}</td>`
    + `<td>${rt === 'running' ? '<span class="status-dot status-running"><i></i>Đang chạy</span>' : '<span class="status-dot status-stopped"><i></i>Dừng</span>'}</td>`
    + `<td>${px}</td>`
    + `<td>${x.has_tabs ? '•' : '0'}</td>`
    + `<td>${accStageBadge(x.stage || 'NEW')}</td>`
    + `<td>${x.stability ?? '-'}</td><td>${x.confidence ?? '-'}</td>`
    + `<td><small>${x.eval_attempt ? accRelTime(x.eval_attempt) : 'chưa có'}</small></td>`
    + `<td>${x.open_alerts ? `<span class="badge badge-danger">${x.open_alerts}</span>` : '0'}</td>`
    + `<td><button class="btn btn-xs" onclick="monToggleWatch(${x.id}, this)" title="Theo dõi">${x.watchlist ? '⭐' : '☆'}</button> ${x.monitor_enabled ? '<span class="badge badge-info">ON</span>' : '<span class="badge badge-muted">OFF</span>'}</td>`
    + `<td><button class="btn btn-xs" onclick="monRowRecheck(${x.id}, this)">✓</button> <button class="btn btn-xs" onclick="monOpenDrawer(${x.id})">Chi tiết</button></td></tr>`;
}
function monPatchRow(r) {
  // Patch granular 1 row (khong rerender dashboard)
  const tr = $('mon-row-' + r.profileId);
  if (!tr) return;
  const cells = tr.children;
  if (cells[2]) cells[2].innerHTML = evalBadge(r.status || 'ERROR');
  if (cells[9]) cells[9].innerHTML = `<small>${accRelTime(r.checked_at || '')}</small>`;
}
async function monToggleWatch(id, btn) {
  const r = await sendJson(api + 'monitoring.php?action=watch', { id });
  if (r.ok) { toast(r.watchlist ? 'Đã thêm vào Watchlist' : 'Đã gỡ khỏi Watchlist', 'success'); monLoadChannels(monPage); refreshAll(); }
}
async function monRowRecheck(id, btn) {
  if (btn) btn.disabled = true;
  try {
    const r = await sendJson(api + 'monitoring.php?action=recheck', { id });
    if (r.ok && r.data) { evalApplyResult(r.data); monPatchRow(r.data); toast(`#${id}: ${(EVAL_STATUS[r.data.status] || [])[0]}`, 'success'); }
    else toast(r.message || 'Lỗi', 'error');
  } finally { if (btn) btn.disabled = false; }
}
// ---- Alerts tab ----
async function monLoadAlerts() {
  const q = new URLSearchParams({ status: $('mon-a-status').value, severity: $('mon-a-sev').value, limit: 100 });
  try {
    const r = await getJson(api + 'monitoring.php?action=alerts&' + q.toString());
    const list = r.ok ? r.data.alerts : [];
    $('mon-alerts-tbody').innerHTML = list.length ? list.map(a => monAlertRow(a, false)).join('')
      : '<tr><td colspan="8"><div class="empty-state">Không có cảnh báo đang mở</div></td></tr>';
  } catch (e) { $('mon-alerts-tbody').innerHTML = '<tr><td colspan="8"><div class="empty-state">Không tải được dữ liệu</div></td></tr>'; }
}
function monAlertRow(a, mini) {
  const sev = `<span class="badge ${a.severity === 'CRITICAL' ? 'badge-danger' : (a.severity === 'WARNING' ? 'badge-warn' : 'badge-info')}">${a.severity}</span>`;
  return `<div class="mon-alert" style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px dashed var(--border-soft)">`
    + (mini ? '' : `<span class="ck"><input type="checkbox" class="mon-alert-check" data-id="${a.id}" data-pid="${a.profile_id}"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span>`)
    + `<strong>${escapeHtml(a.profile_name || ('#' + a.profile_id))}</strong>${sev}`
    + `<span>${escapeHtml(a.message)}</span><span class="muted"><small>${accRelTime(a.last_seen)}</small></span>`
    + `<span class="spacer"></span>`
    + `<button class="btn btn-xs" onclick="monOpenDrawer(${a.profile_id})">Xem</button>`
    + `<button class="btn btn-xs" onclick="monRowRecheck(${a.profile_id}, this)">Kiểm tra lại</button>`
    + (mini || a.status !== 'OPEN' ? '' : `<button class="btn btn-xs" onclick="monResolveAlert(${a.id})">Đã xem</button>`) + `</div>`;
}
async function monResolveAlert(id) {
  await sendJson(api + 'monitoring.php?action=alert_resolve', { id });
  if (monTabCur === 'alerts') monLoadAlerts();
  monLoadAlertsMini();
  monBadgeTick();
}
function monSelectedAlertIds() {
  return [...document.querySelectorAll('.mon-alert-check:checked')].map(c => Number(c.dataset.id));
}
async function monResolveSelected() {
  const ids = monSelectedAlertIds();
  if (!ids.length) { toast('Chưa chọn cảnh báo nào', 'error'); return; }
  for (const id of ids) await sendJson(api + 'monitoring.php?action=alert_resolve', { id });
  toast(`Đã xử lý ${ids.length} cảnh báo`, 'success');
  monLoadAlerts(); monBadgeTick();
}
async function monRecheckSelected() {
  const pids = [...new Set([...document.querySelectorAll('.mon-alert-check:checked')].map(c => Number(c.dataset.pid)))];
  if (!pids.length) { toast('Chưa chọn kênh nào', 'error'); return; }
  const st = await sendJson(api + 'accounts.php?action=eval_start', { ids: pids, concurrency: 4 });
  if (!st.ok) { toast(st.message || 'Lỗi', 'error'); return; }
  let done = false;
  while (!done) {
    const ch = await sendJson(api + 'accounts.php?action=eval_chunk', { batch_id: st.data.batch_id, limit: 4 });
    if (!ch.ok) break;
    (ch.data.results || []).forEach(r => { evalApplyResult(r); monPatchRow(r); });
    done = !!ch.data.done;
  }
  toast('Đã kiểm tra lại các kênh đã chọn', 'success');
  monLoadAlerts(); monLoadOverview(true);
}
// ---- Trends ----
function monTrendRange(d) {
  monTrendDays = d;
  document.querySelectorAll('#mon-trend-range button').forEach(b => b.classList.toggle('active', Number(b.dataset.days) === d));
  monLoadTrends();
}
async function monLoadTrends() {
  const metric = $('mon-trend-metric').value;
  try {
    const r = await getJson(api + `monitoring.php?action=trends&days=${monTrendDays}&metric=${metric}`);
    const pts = r.ok ? r.data.points : [];
    $('mon-trend-empty').classList.toggle('hidden', pts.length > 1);
    monDrawChart(pts);
  } catch (e) { monDrawChart([]); }
}
function monDrawChart(pts) {
  const cv = $('mon-chart');
  const ctx = cv.getContext('2d');
  const W = cv.parentElement.clientWidth - 32, H = 220;
  cv.width = W; cv.height = H;
  ctx.clearRect(0, 0, W, H);
  if (pts.length < 2) return;
  const vs = pts.map(p => p.v);
  const max = Math.max(1, ...vs);
  const stepX = W / (pts.length - 1);
  ctx.strokeStyle = '#4da3ff'; ctx.lineWidth = 2; ctx.beginPath();
  pts.forEach((p, i) => {
    const x = i * stepX, y = H - 12 - (p.v / max) * (H - 30);
    i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
  });
  ctx.stroke();
  ctx.fillStyle = '#888'; ctx.font = '11px sans-serif';
  ctx.fillText(String(max), 4, 12);
  ctx.fillText(pts[0].t.slice(0, 10), 4, H - 2);
  ctx.fillText(pts[pts.length - 1].t.slice(0, 10), W - 70, H - 2);
}
// ---- History ----
async function monLoadHistory(page) {
  monHistPage = page || 1;
  const q = new URLSearchParams({ category: $('mon-h-cat').value, from: $('mon-h-from').value, limit: 50, offset: (monHistPage - 1) * 50 });
  try {
    const r = await getJson(api + 'monitoring.php?action=history&' + q.toString());
    let rows = r.ok ? r.data : [];
    const qs = $('mon-h-search').value.trim().toLowerCase();
    if (qs) rows = rows.filter(h => (h.profile_name || '').toLowerCase().includes(qs) || String(h.profile_id) === qs);
    $('mon-history').innerHTML = rows.length ? `<div class="timeline">` + rows.map(h =>
      `<div class="tl-item"><span class="mono muted">${escapeHtml(h.ts || '')}</span> `
      + `<strong>${escapeHtml(h.profile_name || ('#' + h.profile_id))}</strong> `
      + `<span class="badge badge-muted">${escapeHtml(h.category)}</span> `
      + `${escapeHtml(h.old_value || '-')} → <strong>${escapeHtml(h.new_value || '-')}</strong> `
      + (h.reason ? `<span class="muted"><small>${escapeHtml(h.reason)}</small></span>` : '') + `</div>`).join('') + `</div>`
      : '<div class="empty-state">Chưa có thay đổi</div>';
    $('mon-history-pg').innerHTML = `<div class="pgbar"><div class="pg-left">Trang ${monHistPage}</div>`
      + `<div class="pg-center"><button class="pg-btn" ${monHistPage <= 1 ? 'disabled' : ''} onclick="monLoadHistory(${monHistPage - 1})">‹</button>`
      + `<button class="pg-btn" onclick="monLoadHistory(${monHistPage + 1})">›</button></div></div>`;
  } catch (e) { $('mon-history').innerHTML = '<div class="empty-state">Không tải được dữ liệu</div>'; }
}
// ---- Channel drawer (8 sections, khong roi dashboard) ----
async function monOpenDrawer(id) {
  try {
    const [acc, mon, hist] = await Promise.all([
      getJson(api + `accounts.php?action=get&id=${id}`),
      getJson(api + `monitoring.php?action=settings&id=${id}`),
      getJson(api + `monitoring.php?action=history&profile_id=${id}&limit=10`),
    ]);
    if (!acc.ok) { toast('Lỗi', 'error'); return; }
    const st = acc.data.state, p = acc.data.profile || {};
    const ms = mon.ok ? mon.data : {};
    const ev = st.eval_status || 'UNCHECKED';
    const row = (k, v) => `<div class="acc-item"><span>${k}</span><strong>${v}</strong></div>`;
    const tgl = (k, label) => `<label class="checkbox-row" style="min-width:0"><input type="checkbox" data-mon-k="${k}" ${ms[k] ? 'checked' : ''} onchange="monSaveSettings(${id})"><span>${label}</span></label>`;
    $('acc-drawer-title').textContent = 'THEO DÕI — ' + (p.name || ('#' + id));
    $('acc-drawer-body').innerHTML =
      `<div class="acc-head"><span class="meta-label">Chrome: ${p.status === 'running' ? '● Đang chạy' : '● Dừng'}</span><span>${evalBadge(ev)}</span></div>`
      + `<div class="sync-label" style="margin-top:8px">TỔNG QUAN</div><div class="acc-grid">`
      + row('Kiểm tra', st.last_attempt_at ? accRelTime(st.last_attempt_at) : 'chưa có')
      + row('Biết gần nhất', st.last_known_status ? (EVAL_STATUS[st.last_known_status] || [])[0] : '-') + `</div>`
      + `<div class="sync-label">ĐÁNH GIÁ (tín hiệu thô)</div><div class="acc-grid">`
      + row('Đăng nhập', st.login_state || '—') + row('Phiên', st.session_state || '—')
      + row('YouTube', st.youtube_state || '—') + row('Kênh', st.channel_state || '—') + `</div>`
      + `<div class="sync-label">ĐIỂM NỘI BỘ (tool tự tính, không phải của YouTube)</div><div class="acc-grid">`
      + row('Ổn định', `${st.stability ?? '-'} / 100`) + row('Tin cậy', `${st.confidence ?? '-'} / 100`) + `</div>`
      + `<div class="sync-label">RUNTIME / PROXY</div><div class="acc-grid">`
      + row('Chrome', p.status || '-') + row('Mở lúc', p.last_opened || '—') + `</div>`
      + `<div class="sync-label">SỨC KHỎE</div><div class="acc-grid">`
      + row('Lỗi liên tiếp', st.consec_fails ?? 0) + row('Thất bại', `${st.fail_count ?? 0}/${(st.success_count ?? 0) + (st.fail_count ?? 0)}`) + `</div>`
      + `<div class="sync-label">THEO DÕI</div>`
      + tgl('monitor_enabled', 'Bật theo dõi kênh này')
      + `<button class="btn btn-sm" style="margin:4px 0" onclick="monToggleWatch(${id});monOpenDrawer(${id})">${ms.watchlist ? '⭐ Gỡ Watchlist' : '☆ Thêm Watchlist'}</button><br>`
      + tgl('alert_on_status_change', 'Báo khi đổi trạng thái')
      + tgl('alert_on_login_required', 'Báo khi cần đăng nhập')
      + tgl('alert_on_proxy_error', 'Báo khi proxy lỗi')
      + `<div class="sync-label" style="margin-top:8px">LỊCH SỬ GẦN ĐÂY</div>`
      + ((hist.ok && hist.data.length) ? hist.data.map(h =>
        `<div class="tl-item"><span class="mono muted">${escapeHtml((h.ts || '').slice(5, 16))}</span> ${escapeHtml(h.old_value || '-')} → <strong>${escapeHtml(h.new_value || '-')}</strong></div>`).join('')
        : '<div class="empty-state">Chưa có thay đổi</div>');
    setDrawerFoot('Kiểm tra lại', 'Mở Profile', 'Đánh giá',
      async () => { await monRowRecheck(id); monOpenDrawer(id); },
      () => { closeAccountDrawer(); openProfile(id); },
      () => { closeAccountDrawer(); openAccountDrawer(id); });
    $('acc-drawer-wrap').classList.remove('hidden');
  } catch (e) { toast('Lỗi tải chi tiết', 'error'); }
}
async function monSaveSettings(id) {
  const o = {};
  document.querySelectorAll('#acc-drawer-body [data-mon-k]').forEach(c => { o[c.dataset.monK] = c.checked ? 1 : 0; });
  o.id = id;
  const r = await sendJson(api + 'monitoring.php?action=settings', o);
  toast(r.ok ? 'Đã lưu theo dõi' : 'Lỗi lưu', r.ok ? 'success' : 'error');
}
