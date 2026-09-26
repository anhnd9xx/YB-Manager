// ============ CONTROL CENTER (Trung tâm điều hành) ============
// Observe/aggregate qua api/controlcenter.php. Realtime: poll summary 10s khi
// view active (patch render); drawer job poll 3s. Khong refresh full page.
let ccTimer = null;
let ccDrawerTimer = null;
let ccDrawerJob = null;

const CC_JOB_BADGE = { QUEUED: 'muted', RUNNING: 'info', PAUSED: 'warn', SUCCESS: 'ok', PARTIAL: 'warn', FAILED: 'err', CANCELLED: 'muted', INTERRUPTED: 'err' };
const CC_JOB_VN = { QUEUED: 'Đang chờ', RUNNING: 'Đang chạy', PAUSED: 'Tạm dừng', SUCCESS: 'Xong', PARTIAL: 'Một phần', FAILED: 'Thất bại', CANCELLED: 'Đã hủy', INTERRUPTED: 'Gián đoạn' };
const CC_SEV_BADGE = { CRITICAL: 'err', ERROR: 'err', WARNING: 'warn', INFO: 'info' };
const CC_HEALTH_VN = { HEALTHY: 'Tốt', DEGRADED: 'Suy giảm', UNHEALTHY: 'Lỗi', UNKNOWN: 'Chưa rõ' };
const CC_HEALTH_BADGE = { HEALTHY: 'ok', DEGRADED: 'warn', UNHEALTHY: 'err', UNKNOWN: 'muted' };

function ccInit() {
  if (ccTimer) clearInterval(ccTimer);
  ccRefresh();
  ccLoadHealth();
  ccTimer = setInterval(() => {
    const pane = $('view-controlcenter');
    if (!pane || !pane.classList.contains('active') || document.hidden) return;
    ccRefresh();
    ccLoadHealth();
  }, 10000);
}

function ccGo(view, tab) {
  switchView(view);
  if (view === 'notify' && tab && typeof notifyTab === 'function') notifyTab(tab);
}

async function ccRefresh(force) {
  try {
    const r = await getJson(api + 'controlcenter.php?action=summary');
    if (!r.ok) return;
    const d = r.data;
    ccRenderKpis(d.kpi);
    ccRenderJobs(d.jobs_active_list || []);
    ccRenderAlerts(d.alerts || [], d.alert_counts || {});
    ccRenderSched(d.upcoming || []);
    ccRenderRes(d.resources || {});
    ccRenderActWidget(d.auto_activity || null);
    ccRenderActivity(d.activity || []);
    const ov = d.overall || {};
    const el = $('cc-overall');
    if (el) {
      el.textContent = '● ' + (ov.label || '');
      el.className = 'badge ' + (ov.level === 'ok' ? 'badge-ok' : (ov.level === 'warning' ? 'badge-warn' : 'badge-err'));
    }
    const nav = $('nav-cc-count');
    if (nav) {
      const n = (d.alert_counts && d.alert_counts.total) || 0;
      nav.textContent = n;
      nav.classList.toggle('hidden', !n);
    }
    if (force) toast('Đã làm mới.', 'success');
  } catch (e) {}
}

function ccKpi(ico, cls, value, label, sub, onclick) {
  return `<div class="nt-kpi-card cc-kpi"${onclick ? ` onclick="${onclick}" role="button" tabindex="0"` : ''}>`
    + `<div class="nt-kpi-ico ${cls}">${ico}</div><div class="nt-kpi-body">`
    + `<div class="nt-kpi-value">${value}</div><div class="nt-kpi-label">${label}</div>`
    + (sub ? `<div class="nt-kpi-sub">${sub}</div>` : '') + `</div></div>`;
}

function ccRenderKpis(k) {
  const el = $('cc-kpis');
  if (!el || !k) return;
  const tg = k.telegram || {};
  const tgOn = !!tg.listening;
  el.innerHTML =
    ccKpi('▦', 'is-info', escapeHtml(String(k.channels_total || 0)), 'Tổng kênh', 'Click để xem', "ccGo('profiles')")
    + ccKpi('▶', (k.chrome_running || 0) > 0 ? 'is-ok' : 'is-off', escapeHtml(String(k.chrome_running || 0)), 'Chrome đang chạy', 'Click để xem', "ccGo('profiles')")
    + ccKpi('⌖', (k.proxy_total || 0) && (k.proxy_healthy || 0) < (k.proxy_total || 0) ? 'is-info' : 'is-ok',
      escapeHtml(`${k.proxy_healthy || 0} / ${k.proxy_total || 0}`), 'Proxy khỏe', 'Click để xem', "ccGo('proxies')")
    + ccKpi('⬢', (k.jobs_active || 0) > 0 ? 'is-info' : 'is-off', escapeHtml(String(k.jobs_active || 0)), 'Job đang chạy', 'Click để xem', 'ccJobsScroll()')
    + ccKpi('✈', tgOn ? 'is-ok' : 'is-off', tgOn ? '● Online' : '○ Offline', 'Telegram', escapeHtml(tg.state || ''), "ccGo('notify','telegram')");
}

function ccJobsScroll() {
  const p = $('cc-jobs');
  if (p) p.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function ccJobBar(done, total) {
  const pct = total > 0 ? Math.min(100, Math.round(done * 100 / total)) : 0;
  return `<div class="cc-bar"><i style="width:${pct}%"></i></div>`;
}

function ccRenderJobs(rows) {
  const el = $('cc-jobs');
  if (!el) return;
  if (!rows.length) { el.innerHTML = '<div class="empty-state">Không có công việc đang chạy.</div>'; return; }
  el.innerHTML = rows.slice(0, 5).map(j => {
    const done = (j.progress_done === null || j.progress_done === undefined) ? 0 : j.progress_done;
    const total = j.progress_total || 0;
    const dur = ccDur(j.started_at);
    return `<div class="cc-job" onclick="ccDrawerOpen('${escapeHtml(j.job_id)}')">`
      + `<div class="cc-job-top"><strong>${escapeHtml(j.name || j.job_id)}</strong>`
      + `<span class="badge badge-${CC_JOB_BADGE[j.status] || 'muted'}">${CC_JOB_VN[j.status] || escapeHtml(j.status)}</span></div>`
      + `<div class="cc-job-mid"><span class="mono">${escapeHtml(String(done))} / ${escapeHtml(String(total))}</span>`
      + (dur ? `<span class="muted">${escapeHtml(dur)}</span>` : '') + `</div>`
      + ccJobBar(done, total)
      + `<div class="cc-job-act" onclick="event.stopPropagation()">`
      + `<button type="button" class="btn btn-xs" onclick="ccDrawerOpen('${escapeHtml(j.job_id)}')">Xem</button>`
      + (j.status === 'RUNNING' || j.status === 'QUEUED' ? `<button type="button" class="btn btn-xs" onclick="ccJobPause('${escapeHtml(j.job_id)}')">Tạm dừng</button>` : '')
      + (j.status === 'PAUSED' ? `<button type="button" class="btn btn-xs" onclick="ccJobResume('${escapeHtml(j.job_id)}')">Tiếp tục</button>` : '')
      + `<button type="button" class="btn btn-xs" onclick="ccJobCancel('${escapeHtml(j.job_id)}')">Hủy</button>`
      + `</div></div>`;
  }).join('');
}

function ccDur(startedAt) {
  if (!startedAt) return '';
  const s = Math.max(0, Math.round(Date.now() / 1000 - Date.parse(String(startedAt).replace(' ', 'T')) / 1000));
  if (isNaN(s)) return '';
  const m = Math.floor(s / 60);
  return (m > 0 ? m + ':' + String(s % 60).padStart(2, '0') : '00:' + String(s).padStart(2, '0'));
}

async function ccJobsToggle() {
  const p = $('cc-jobs-all');
  if (!p) return;
  p.classList.toggle('hidden');
  $('cc-jobs-toggle').textContent = p.classList.contains('hidden') ? 'Xem tất cả công việc' : 'Thu gọn';
  if (!p.classList.contains('hidden')) ccJobsLoad();
}

async function ccJobsLoad() {
  const el = $('cc-jobs-list');
  if (!el) return;
  try {
    const q = `module=${encodeURIComponent($('cc-f-module').value)}&status=${encodeURIComponent($('cc-f-status').value)}&source=${encodeURIComponent($('cc-f-source').value)}&limit=30`;
    const r = await getJson(api + 'controlcenter.php?action=jobs&' + q);
    const rows = (r.ok && r.data) ? r.data : [];
    el.innerHTML = rows.length
      ? `<div class="nt-table-wrap"><table class="data-table nt-table"><thead><tr><th>Job</th><th>Module</th><th>Tiến độ</th><th>Trạng thái</th><th>Nguồn</th><th>Tạo lúc</th></tr></thead><tbody>`
        + rows.map(j => `<tr onclick="ccDrawerOpen('${escapeHtml(j.job_id)}')" style="cursor:pointer">`
          + `<td><strong>${escapeHtml(j.job_id)}</strong><br><small class="muted">${escapeHtml(j.name || '')}</small></td>`
          + `<td>${escapeHtml(j.module || '')}</td>`
          + `<td class="mono">${j.progress_done || 0}/${j.progress_total || 0}</td>`
          + `<td><span class="badge badge-${CC_JOB_BADGE[j.status] || 'muted'}">${CC_JOB_VN[j.status] || escapeHtml(j.status)}</span></td>`
          + `<td>${escapeHtml(j.source || '')}</td>`
          + `<td class="mono"><small>${escapeHtml((j.created_at || '').slice(5, 16))}</small></td></tr>`).join('')
        + `</tbody></table></div>`
      : '<div class="empty-state">Không có job nào.</div>';
  } catch (e) {
    el.innerHTML = '<div class="empty-state">Lỗi tải.</div>';
  }
}

// ---- Job drawer ----
function ccDrawerOpen(jobId) {
  ccDrawerJob = jobId;
  $('cc-drawer').classList.remove('hidden');
  ccDrawerLoad();
  if (ccDrawerTimer) clearInterval(ccDrawerTimer);
  ccDrawerTimer = setInterval(() => {
    if ($('cc-drawer').classList.contains('hidden')) { clearInterval(ccDrawerTimer); return; }
    ccDrawerLoad(true);
  }, 3000);
}
function ccDrawerClose() {
  $('cc-drawer').classList.add('hidden');
  ccDrawerJob = null;
  if (ccDrawerTimer) clearInterval(ccDrawerTimer);
}
async function ccDrawerLoad(quiet) {
  if (!ccDrawerJob) return;
  try {
    const r = await getJson(api + 'controlcenter.php?action=job_detail&job_id=' + encodeURIComponent(ccDrawerJob));
    if (!r.ok) { if (!quiet) toast(r.message || 'Lỗi', 'error'); return; }
    const j = r.data.job;
    const items = r.data.items || [];
    const failed = r.data.failed || [];
    $('cc-drawer-title').textContent = 'JOB ' + j.job_id;
    const done = j.progress_done || 0;
    const total = j.progress_total || 0;
    const running = items.filter(x => x.status === 'RUNNING').slice(0, 5);
    const failedRows = items.filter(x => x.status === 'FAILED').slice(0, 10);
    const active = ['QUEUED', 'RUNNING', 'PAUSED'].includes(j.status);
    $('cc-drawer-body').innerHTML =
      `<div class="cc-dsec"><span class="badge badge-${CC_JOB_BADGE[j.status] || 'muted'}">${CC_JOB_VN[j.status] || escapeHtml(j.status)}</span> <strong>${escapeHtml(j.name || '')}</strong></div>`
      + `<div class="meta-row"><span class="meta-label">Tiến độ</span><span class="meta-value mono">${done} / ${total}</span></div>`
      + ccJobBar(done, total)
      + `<div class="meta-row"><span class="meta-label">Bắt đầu</span><span class="meta-value">${escapeHtml(j.started_at || '—')}</span></div>`
      + `<div class="meta-row"><span class="meta-label">Thời gian</span><span class="meta-value">${escapeHtml(ccDur(j.started_at))}</span></div>`
      + `<div class="meta-row"><span class="meta-label">Nguồn</span><span class="meta-value">${escapeHtml(j.source || '')}${j.created_by ? ' · ' + escapeHtml(j.created_by) : ''}</span></div>`
      + `<div class="cc-dsec">Kết quả</div>`
      + `<div class="cc-counts"><span class="cc-count ok">✓ ${j.success_count || 0}</span>`
      + `<span class="cc-count warn">⚠ ${(j.warning_count || 0)}</span>`
      + `<span class="cc-count err">✕ ${j.failed_count || 0}</span>`
      + `<span class="cc-count muted">⊘ ${j.cancelled_count || 0}</span></div>`
      + (running.length ? `<div class="cc-dsec">Đang xử lý</div>` + running.map(x => `<div class="cc-item"><span>${escapeHtml(x.target_name || x.target_id)}</span><span class="badge badge-info">RUNNING</span></div>`).join('') : '')
      + (failedRows.length ? `<div class="cc-dsec">Mục lỗi</div>` + failedRows.map(x => `<div class="cc-item"><span>${escapeHtml(x.target_name || x.target_id)}<br><small class="muted">${escapeHtml(x.error || '')}</small></span><span class="badge badge-err">FAILED</span></div>`).join('') : '')
      + (j.error ? `<div class="eval-prev">⚠ ${escapeHtml(j.error)}</div>` : '')
      + `<div class="cc-drawer-act">`
      + (active ? (j.status === 'PAUSED'
        ? `<button type="button" class="btn btn-sm" onclick="ccJobResume('${escapeHtml(j.job_id)}')">Tiếp tục</button>`
        : `<button type="button" class="btn btn-sm" onclick="ccJobPause('${escapeHtml(j.job_id)}')">Tạm dừng</button>`)
        + `<button type="button" class="btn btn-sm btn-danger" onclick="ccJobCancel('${escapeHtml(j.job_id)}')">Hủy Job</button>` : '')
      + (failed.length && !active ? `<button type="button" class="btn btn-sm btn-primary" onclick="ccJobRetry('${escapeHtml(j.job_id)}')">Thử lại mục lỗi (${failed.length})</button>` : '')
      + `</div>`;
  } catch (e) {}
}
async function ccJobCancel(id) {
  const r = await sendJson(api + 'controlcenter.php?action=job_cancel', { job_id: id });
  toast(r.ok ? 'Đã hủy job.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ccRefresh();
  if (ccDrawerJob === id) ccDrawerLoad();
}
async function ccJobPause(id) {
  const r = await sendJson(api + 'controlcenter.php?action=job_pause', { job_id: id });
  toast(r.ok ? 'Đã tạm dừng.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ccRefresh();
  if (ccDrawerJob === id) ccDrawerLoad();
}
async function ccJobResume(id) {
  const r = await sendJson(api + 'controlcenter.php?action=job_resume', { job_id: id });
  toast(r.ok ? 'Đã tiếp tục.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ccRefresh();
  if (ccDrawerJob === id) ccDrawerLoad();
}
async function ccJobRetry(id) {
  const r = await sendJson(api + 'controlcenter.php?action=job_retry', { job_id: id });
  toast(r.ok ? 'Đã tạo job thử lại: ' + r.data.job_id : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ccRefresh();
  ccJobsLoad();
}

// ---- Alerts ----
function ccRenderAlerts(rows, counts) {
  const el = $('cc-alerts');
  if (!el) return;
  const n = $('cc-alert-n');
  const total = counts.total || 0;
  if (n) {
    n.textContent = total ? total + ' cần chú ý' : 'Ổn định';
    n.className = 'badge ' + (total ? 'badge-warn' : 'badge-ok');
  }
  if (!rows.length) { el.innerHTML = '<div class="empty-state">Không có cảnh báo nào. Hệ thống ổn định.</div>'; return; }
  el.innerHTML = rows.slice(0, 5).map(a => {
    const ch = !!a.channel;
    const id = ch ? 0 : a.id;
    return `<div class="cc-alert sev-${(a.severity || '').toLowerCase()}">`
      + `<div class="cc-alert-top"><span class="badge badge-${CC_SEV_BADGE[a.severity] || 'warn'}">${escapeHtml(a.severity)}</span>`
      + `<strong>${escapeHtml(a.title)}</strong></div>`
      + (a.message ? `<div class="cc-alert-msg">${escapeHtml(a.message)}</div>` : '')
      + `<div class="cc-alert-foot"><small class="muted">${escapeHtml(a.last_seen_at || '')}`
      + ((a.occurrence_count || 0) > 1 ? ` · ×${a.occurrence_count}` : '') + `</small>`
      + (ch ? `<small class="muted">· alert kênh</small>`
        : `<button type="button" class="btn btn-xs" onclick="ccAlertAck(${id})">Đã xem</button>`
        + `<button type="button" class="btn btn-xs" onclick="ccAlertResolve(${id})">Xử lý xong</button>`)
      + `</div></div>`;
  }).join('');
}
async function ccAlertAck(id) {
  await sendJson(api + 'controlcenter.php?action=alert_ack', { id });
  ccRefresh();
}
async function ccAlertResolve(id) {
  await sendJson(api + 'controlcenter.php?action=alert_resolve', { id });
  ccRefresh();
}

// ---- Health ----
async function ccLoadHealth() {
  const el = $('cc-health');
  if (!el) return;
  try {
    const r = await getJson(api + 'controlcenter.php?action=health');
    if (!r.ok) return;
    const rows = r.data.checks || [];
    el.innerHTML = rows.map(c => {
      const st = c.status || 'UNKNOWN';
      return `<div class="cc-health-row" onclick="ccHealthDetail('${escapeHtml(c.name)}')">`
        + `<span class="nt-dot ${st === 'HEALTHY' ? 'on' : (st === 'UNKNOWN' ? '' : 'off')}"></span>`
        + `<span class="cc-health-label">${escapeHtml(c.label)}</span>`
        + `<span class="badge badge-${CC_HEALTH_BADGE[st] || 'muted'}">${CC_HEALTH_VN[st] || st}</span></div>`
        + (c.detail ? `<div class="cc-health-detail-line">${escapeHtml(c.detail)}</div>` : '');
    }).join('');
    window.__ccHealth = rows;
  } catch (e) {}
}
async function ccHealthDetail(name) {
  const rows = window.__ccHealth || [];
  const c = rows.find(x => x.name === name);
  if (!c) return;
  const m = c.metrics || {};
  const mrows = Object.entries(m).map(([k, v]) =>
    `<div class="meta-row"><span class="meta-label">${escapeHtml(k)}</span><span class="meta-value">${escapeHtml(v === null || v === undefined ? '—' : (typeof v === 'boolean' ? (v ? 'Có' : 'Không') : String(v)))}</span></div>`).join('');
  $('cc-modal-title').textContent = c.label;
  $('cc-modal-body').innerHTML =
    `<div class="meta-row"><span class="meta-label">Trạng thái</span><span class="meta-value"><span class="badge badge-${CC_HEALTH_BADGE[c.status] || 'muted'}">${CC_HEALTH_VN[c.status] || c.status}</span></span></div>`
    + (c.detail ? `<div class="meta-row"><span class="meta-label">Chi tiết</span><span class="meta-value">${escapeHtml(c.detail)}</span></div>` : '')
    + mrows
    + (c.recoverable && c.status !== 'HEALTHY'
      ? `<div style="margin-top:10px"><button type="button" class="btn btn-sm btn-primary" onclick="ccHealthRecover('${escapeHtml(c.name)}')">Phục hồi</button></div>` : '');
  $('cc-modal').classList.remove('hidden');
}
async function ccHealthRecover(name) {
  const r = await sendJson(api + 'controlcenter.php?action=health_recover', { name });
  toast(r.ok ? 'Đã gửi lệnh phục hồi.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ccModalClose();
  setTimeout(ccLoadHealth, 3000);
}

// ---- Schedules ----
function ccRenderSched(rows) {
  const el = $('cc-sched');
  if (!el) return;
  if (!rows.length) {
    el.innerHTML = '<div class="empty-state">Chưa có lịch nào. Bấm＋Tạo lịch.</div>';
    return;
  }
  el.innerHTML = rows.map(s => {
    let t = String(s.at || '');
    try { t = t.slice(11, 16) + ' · ' + t.slice(8, 10) + '/' + t.slice(5, 7); } catch (e) {}
    return `<div class="cc-sched-row"><span class="cc-sched-time mono">${escapeHtml(t)}</span>`
      + `<span>${escapeHtml(s.name)}</span></div>`;
  }).join('')
    + `<div style="margin-top:8px"><button type="button" class="btn btn-xs" onclick="ccSchedOpen(true)">Quản lý lịch</button></div>`;
}
function ccSchedOpen(manage) {
  $('cc-modal-title').textContent = 'Tạo lịch mới';
  $('cc-modal-body').innerHTML =
    `<label>Tên lịch</label><input type="text" id="cc-sch-name" placeholder="VD: Kiểm tra proxy đêm">`
    + `<div class="win-row" style="margin-top:8px"><div class="win-field"><label>Loại job</label><select id="cc-sch-type">`
    + `<option value="PROXY_CHECK">Kiểm tra Proxy</option><option value="EVALUATION">Đánh giá kênh</option>`
    + `<option value="AUTO_ACTIVITY">Auto Activity</option><option value="BROWSER_START">Mở kênh</option>`
    + `<option value="BROWSER_STOP">Đóng kênh</option></select></div>`
    + `<div class="win-field"><label>Kiểu lặp</label><select id="cc-sch-repeat" onchange="ccSchedRepeatUI()">`
    + `<option value="DAILY">Hàng ngày</option><option value="INTERVAL">Mỗi N phút</option><option value="WEEKLY">Hàng tuần</option><option value="ONCE">Một lần</option></select></div></div>`
    + `<div class="win-row" style="margin-top:8px"><div class="win-field" id="cc-sch-time-wrap"><label>Giờ</label><input type="time" id="cc-sch-time" value="23:00"></div>`
    + `<div class="win-field hidden" id="cc-sch-min-wrap"><label>Mỗi (phút)</label><input type="number" id="cc-sch-min" value="60" min="5"></div>`
    + `<div class="win-field hidden" id="cc-sch-wd-wrap"><label>Thứ</label><select id="cc-sch-wd"><option value="1">Thứ 2</option><option value="2">Thứ 3</option><option value="3">Thứ 4</option><option value="4">Thứ 5</option><option value="5">Thứ 6</option><option value="6">Thứ 7</option><option value="7">Chủ nhật</option></select></div></div>`
    + `<div class="win-field" style="margin-top:8px"><label>Phạm vi</label><select id="cc-sch-scope"><option value="all">Tất cả</option><option value="running">Đang chạy</option><option value="stopped">Đang dừng</option></select></div>`
    + `<div style="margin-top:10px"><button type="button" class="btn btn-sm btn-primary" onclick="ccSchedSave()">Lưu lịch</button></div>`
    + `<div id="cc-sch-list" style="margin-top:12px"></div>`;
  $('cc-modal').classList.remove('hidden');
  ccSchedList();
}
function ccSchedRepeatUI() {
  const v = $('cc-sch-repeat').value;
  $('cc-sch-time-wrap').classList.toggle('hidden', v === 'INTERVAL');
  $('cc-sch-min-wrap').classList.toggle('hidden', v !== 'INTERVAL');
  $('cc-sch-wd-wrap').classList.toggle('hidden', v !== 'WEEKLY');
}
async function ccSchedSave() {
  const rep = $('cc-sch-repeat').value;
  const cfg = rep === 'INTERVAL' ? { minutes: Math.max(5, Number($('cc-sch-min').value || 60)) }
    : rep === 'WEEKLY' ? { time: $('cc-sch-time').value || '08:00', weekday: Number($('cc-sch-wd').value || 1) }
    : rep === 'ONCE' ? { at: $('cc-sch-time').value }
    : { time: $('cc-sch-time').value || '08:00' };
  const r = await sendJson(api + 'controlcenter.php?action=schedule_save', {
    name: $('cc-sch-name').value || 'Lịch mới',
    job_type: $('cc-sch-type').value, schedule_type: rep,
    schedule_config: cfg, target_config: { profile_ids: $('cc-sch-scope').value },
  });
  toast(r.ok ? 'Đã lưu lịch.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  if (r.ok) { ccSchedList(); ccRefresh(); }
}
async function ccSchedList() {
  const el = $('cc-sch-list');
  if (!el) return;
  const r = await getJson(api + 'controlcenter.php?action=schedules').catch(() => null);
  const rows = (r && r.ok && r.data.list) ? r.data.list : [];
  el.innerHTML = rows.length
    ? rows.map(s => `<div class="cc-item"><span>${escapeHtml(s.name)}<br><small class="muted">${escapeHtml(s.job_type)} · ${escapeHtml(s.schedule_type)} · ${escapeHtml(s.next_run_at || '—')}</small></span>`
      + `<span style="display:flex;gap:6px;align-items:center"><input type="checkbox" class="nt-switch" ${s.enabled ? 'checked' : ''} onchange="ccSchedToggle(${s.id}, this.checked)">`
      + `<button type="button" class="btn btn-xs" onclick="ccSchedDel(${s.id})">Xóa</button></span></div>`).join('')
    : '<p class="muted">Chưa có lịch.</p>';
}
async function ccSchedToggle(id, on) {
  await sendJson(api + 'controlcenter.php?action=schedule_toggle', { id, enabled: on ? 1 : 0 });
  ccSchedList();
  ccRefresh();
}
async function ccSchedDel(id) {
  await sendJson(api + 'controlcenter.php?action=schedule_delete', { id });
  ccSchedList();
  ccRefresh();
}

// ---- Quick actions ----
function ccQuick(kind) {
  const needScope = ['BROWSER_START', 'BROWSER_STOP', 'EVALUATION', 'AUTO_ACTIVITY'].includes(kind);
  const titles = { BROWSER_START: 'Mở kênh', BROWSER_STOP: 'Đóng kênh', EVALUATION: 'Đánh giá kênh', PROXY_CHECK: 'Kiểm tra Proxy', AUTO_ACTIVITY: 'Chạy Auto Activity' };
  $('cc-modal-title').textContent = titles[kind] || kind;
  $('cc-modal-body').innerHTML =
    (needScope ? `<label>Phạm vi</label><select id="cc-q-scope"><option value="all">Tất cả kênh</option><option value="running">Kênh đang chạy</option><option value="stopped">Kênh đang dừng</option></select>` : `<p class="muted">Chạy kiểm tra toàn bộ proxy.</p>`)
    + `<div style="margin-top:10px"><button type="button" class="btn btn-sm btn-primary" onclick="ccQuickRun('${kind}')">Tạo Job & Chạy</button></div>`;
  $('cc-modal').classList.remove('hidden');
}
async function ccQuickRun(kind) {
  const sc = $('cc-q-scope');
  const r = await sendJson(api + 'controlcenter.php?action=job_create', { kind, scope: sc ? sc.value : 'all' });
  toast(r.ok ? 'Đã tạo Job: ' + r.data.job_id : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ccModalClose();
  ccRefresh();
}
function ccModalClose() {
  $('cc-modal').classList.add('hidden');
}

// ---- Resources & activity ----
function ccRenderRes(rs) {
  const el = $('cc-res');
  if (!el) return;
  const v = (x, suffix) => (x === null || x === undefined ? '—' : escapeHtml(String(x)) + (suffix || ''));
  el.innerHTML =
    `<div class="meta-row"><span class="meta-label">CPU</span><span class="meta-value">${v(rs.cpu_percent, '%')}</span></div>`
    + `<div class="meta-row"><span class="meta-label">RAM</span><span class="meta-value">${(rs.mem_used_gb !== null && rs.mem_total_gb !== null) ? v(rs.mem_used_gb) + ' / ' + v(rs.mem_total_gb) + ' GB (' + v(rs.mem_percent, '%') + ')' : '—'}</span></div>`
    + `<div class="meta-row"><span class="meta-label">Chrome processes</span><span class="meta-value">${v(rs.chrome_processes)}</span></div>`
    + `<div class="meta-row"><span class="meta-label">Kênh đang chạy</span><span class="meta-value">${v(rs.managed_running)}</span></div>`;
}
function ccRenderActWidget(a) {
  const el = $('cc-res');
  if (!el || !a) return;
  el.innerHTML += `<div class="cc-dsec">Auto Activity</div>`
    + `<div class="meta-row"><span class="meta-label">Running</span><span class="meta-value">${escapeHtml(String(a.running || 0))}</span></div>`
    + `<div class="meta-row"><span class="meta-label">Waiting</span><span class="meta-value">${escapeHtml(String(a.waiting || 0))}</span></div>`
    + `<div class="meta-row"><span class="meta-label">Today</span><span class="meta-value">${escapeHtml(a.today || '0/0')}</span></div>`
    + `<div class="meta-row"><span class="meta-label">Errors</span><span class="meta-value">${escapeHtml(String(a.errors || 0))}</span></div>`;
}
function ccRenderActivity(rows) {
  const el = $('cc-activity');
  if (!el) return;
  if (!rows.length) { el.innerHTML = '<div class="empty-state">Chưa có hoạt động.</div>'; return; }
  el.innerHTML = rows.map(a => {
    let t = String(a.created_at || '');
    try { t = t.slice(11, 16); } catch (e) {}
    return `<div class="cc-act"><span class="mono muted">${escapeHtml(t)}</span>`
      + `<span><strong>${escapeHtml(a.module || '')}</strong> · ${escapeHtml((a.title || '').slice(0, 90))}</span></div>`;
  }).join('');
}
