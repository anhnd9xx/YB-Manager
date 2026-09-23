// ============ NOTIFY VIEW (Thông báo & Báo cáo) ============
function notifyTab(name) {
  document.querySelectorAll('[data-ntab]').forEach(b => b.classList.toggle('active', b.dataset.ntab === name));
  ['overview', 'telegram', 'rules', 'reports', 'history', 'chat'].forEach(t => {
    const el = $('nt-pane-' + t);
    if (el) el.classList.toggle('hidden', t !== name);
  });
  if (name === 'rules') notifyLoadRules();
  if (name === 'history') notifyLoadHistory();
  if (name === 'reports') notifyLoadReports();
  if (name === 'overview') notifyLoadOverview();
  if (name === 'chat') ntChatRefresh();
}
async function notifyRefresh() {
  notifyTab('overview');
  notifyBadge();
}
async function notifyBadge() {
  try {
    const r = await getJson(api + 'notify.php?action=badge');
    if (!r.ok) return;
    const el = $('nav-notify-count');
    if (el) {
      const n = r.data.badge || 0;
      el.textContent = n;
      el.classList.toggle('hidden', !n);
    }
  } catch (e) {}
}
async function notifyLoadOverview() {
  try {
    const [s, b] = await Promise.all([
      getJson(api + 'notify.php?action=config').catch(() => null),
      getJson(api + 'activity.php?action=status').catch(() => null),
    ]);
    const cfg = (s && s.ok && s.data) || {};
    const kv = (k, v) => `<div class="mon-kv"><span>${k}</span><strong>${v}</strong></div>`;
    $('nt-kpi').innerHTML =
      kv('Telegram', cfg.enabled ? (cfg.has_token ? '● Đã kết nối' : '● Thiếu token') : '● Tắt')
      + kv('Worker', cfg.worker_running ? '● Đang chạy' : '● Dừng')
      + kv('Báo cáo ngày', cfg.daily_enabled ? ('Bật (' + escapeHtml(cfg.daily_time || '') + ')') : 'Tắt')
      + kv('Báo cáo tuần', cfg.weekly_enabled ? 'Bật' : 'Tắt');
    const w = $('nt-worker');
    if (w) w.textContent = cfg.worker_running ? 'Worker đang chạy — queue tự gửi.' : 'Worker dừng — notification chờ trong outbox.';
  } catch (e) {
    $('nt-kpi').innerHTML = '<div class="empty-state">Không tải được.</div>';
  }
  notifyLoadConfig();
  tgSetupView();
}
// ============ ONE-FIELD TELEGRAM SETUP (§1-§2, §12, §23) ============
async function tgSetupView() {
  const el = $('tg-setup-view');
  if (!el) return;
  try {
    const r = await getJson(api + 'notify.php?action=tg_status');
    if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi tải.</p>'; return; }
    const c = r.data;
    if (c.connected) {
      el.innerHTML = `<div class="sync-label">Telegram</div>`
        + `<div class="meta-row"><span class="meta-label">Trạng thái</span><span class="meta-value">● Đã kết nối</span></div>`
        + `<div class="meta-row"><span class="meta-label">Bot</span><span class="meta-value">@${escapeHtml(c.bot_username || '?')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Người nhận</span><span class="meta-value">${escapeHtml(c.display_name || c.username || '?')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Loại</span><span class="meta-value">${escapeHtml(c.chat_type || 'private')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Chat ID</span><span class="meta-value mono">${escapeHtml(c.chat_masked || '')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Quyền</span><span class="meta-value">${escapeHtml(c.role || '')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Điều khiển Tool</span><span class="meta-value">${c.inbound_enabled ? '[ ON ]' : '[ OFF ]'}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Thông báo</span><span class="meta-value">${c.enabled ? '[ ON ]' : '[ OFF ]'}</span></div>`
        + `<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">`
        + `<button type="button" class="btn btn-sm" onclick="notifySendTest()">Gửi thử</button>`
        + (c.bot_username ? `<button type="button" class="btn btn-sm" onclick="window.open('https://t.me/${escapeHtml(c.bot_username)}','_blank')">Mở chat</button>` : '')
        + `<button type="button" class="btn btn-sm" onclick="tgChangeReceiver()">Đổi người nhận</button>`
        + `<button type="button" class="btn btn-sm btn-danger" onclick="tgDisconnect()">Ngắt kết nối</button>`
        + `</div><div class="hint" id="tg-setup-note"></div>`;
      return;
    }
    // Chua ket noi: kiem tra session dang mo
    const s = await getJson(api + 'notify.php?action=setup_status').catch(() => null);
    const sess = (s && s.ok && s.data && s.data.session) ? s.data.session : null;
    if (sess && (sess.status === 'WAITING_MESSAGE' || sess.status === 'WAITING_CONFIRM')) {
      tgSetupWaiting(sess, c);
      return;
    }
    el.innerHTML = `<label>Bot Token</label>`
      + `<input type="password" id="tg-token" placeholder="123456:ABC-DEF..." autocomplete="off">`
      + `<div style="margin-top:8px"><button type="button" class="btn btn-sm btn-primary" onclick="tgConnect()">Kết nối Telegram</button></div>`
      + `<div class="hint" id="tg-setup-note"></div>`;
  } catch (e) {
    el.innerHTML = '<p class="muted">Lỗi tải.</p>';
  }
}
async function tgConnect(force) {
  const tok = ($('tg-token').value || '').trim();
  if (!tok) { toast('Nhập Bot Token', 'error'); return; }
  const note = $('tg-setup-note');
  if (note) note.textContent = 'Đang kiểm tra token...';
  const r = await sendJson(api + 'notify.php?action=setup_start', { token: tok, force: force ? 1 : 0 });
  if (!r.ok) {
    if (r.data && r.data.webhook_active) {
      if (note) note.innerHTML = `Bot này đang được sử dụng bởi một webhook khác (${escapeHtml(r.data.webhook_url || '')}).`
        + `<div style="margin-top:6px"><button type="button" class="btn btn-sm" onclick="tgSetupView()">Quay lại</button> `
        + `<button type="button" class="btn btn-sm btn-danger" onclick="tgConnect(true)">Chuyển bot sang YT Manager</button></div>`;
    } else if (note) {
      note.textContent = r.message || 'Lỗi';
    }
    toast(r.message || 'Lỗi', 'error');
    return;
  }
  tgSetupWaiting({ status: 'WAITING_MESSAGE', expires_at: r.data.expires_at }, r.data);
}
function tgSetupSteps(step) {
  const steps = [['token', 'Token hợp lệ'], ['wait', 'Chờ bạn nhắn /start'], ['confirm', 'Chờ xác nhận'], ['done', 'Kết nối hoàn tất']];
  return steps.map(([k, label]) => {
    const done = (k === 'token') || (step === 'confirm' && k === 'wait') || step === 'done';
    const cur = (step === 'wait' && k === 'wait') || (step === 'confirm' && k === 'confirm');
    return `<div>${done ? '✓' : (cur ? '◌' : '○')} ${label}</div>`;
  }).join('');
}
function tgSetupWaiting(sess, info) {
  const el = $('tg-setup-view');
  const bot = (info && info.bot_username) ? '@' + info.bot_username : 'bot';
  const step = sess.status === 'WAITING_CONFIRM' ? 'confirm' : 'wait';
  el.innerHTML = `<div class="sync-label">Kết nối Telegram</div>`
    + tgSetupSteps(step)
    + `<div style="margin-top:8px">✓ Bot Token hợp lệ<br>Bot: <strong>${escapeHtml(bot)}</strong></div>`
    + `<div style="margin-top:6px">Bây giờ hãy mở Telegram và nhắn <strong>/start</strong> cho ${escapeHtml(bot)}</div>`
    + `<div class="eval-prev">◌ Đang chờ tin nhắn... ${sess.candidate_count > 0 ? `(phát hiện ${sess.candidate_count} yêu cầu)` : ''}</div>`
    + `<div style="margin-top:6px"><button type="button" class="btn btn-sm" onclick="tgSetupCancel('${sess.session_id || ''}')">Hủy</button></div>`
    + `<div class="hint" id="tg-setup-note"></div>`;
  clearTimeout(window.__tgSetupTimer);
  window.__tgSetupTimer = setTimeout(() => {
    const pane = $('nt-pane-telegram');
    if (pane && !pane.classList.contains('hidden')) tgSetupView();
  }, 4000);
}
async function tgSetupCancel(sid) {
  clearTimeout(window.__tgSetupTimer);
  await sendJson(api + 'notify.php?action=setup_cancel', { session_id: sid });
  tgSetupView();
}
async function tgChangeReceiver() {
  const r = await sendJson(api + 'notify.php?action=setup_start_existing', {});
  if (!r.ok) { toast(r.message || 'Lỗi', 'error'); return; }
  tgSetupWaiting({ status: 'WAITING_MESSAGE', expires_at: r.data.expires_at }, r.data);
}
async function tgDisconnect() {
  confirmDelete('Ngắt kết nối Telegram?<br><small>Tool sẽ ngừng gửi báo cáo và nhận lệnh.</small>', async () => {
    const r = await sendJson(api + 'notify.php?action=disconnect', {});
    if (r.ok) { toast('Đã ngắt kết nối', 'success'); tgSetupView(); notifyLoadConfig(); }
    else toast(r.message || 'Lỗi', 'error');
  });
}
async function notifyLoadConfig() {
  try {
    const r = await getJson(api + 'notify.php?action=config');
    if (!r.ok) return;
    const c = r.data;
    $('nt-enabled').checked = !!c.enabled;
    $('nt-chat').value = c.chat_id || '';
    $('nt-token-masked').textContent = c.has_token ? ('Đang dùng: ' + c.bot_token_masked) : 'Chưa có token.';
    $('nt-s-success').checked = !!c.send_success;
    $('nt-s-warning').checked = !!c.send_warning;
    $('nt-s-error').checked = !!c.send_error;
    $('nt-s-critical').checked = !!c.send_critical;
    $('nt-s-batch').checked = !!c.send_batch_summary;
    $('nt-recovery').checked = !!c.notify_recovery;
    $('nt-quiet-start').value = c.quiet_start || '';
    $('nt-quiet-end').value = c.quiet_end || '';
    $('nt-daily').checked = !!c.daily_enabled;
    $('nt-daily-time').value = c.daily_time || '22:00';
    $('nt-weekly').checked = !!c.weekly_enabled;
    $('nt-weekly-day').value = String(c.weekly_day || '1');
    $('nt-weekly-time').value = c.weekly_time || '08:00';
    const st = $('nt-conn-status');
    if (st) st.textContent = c.enabled ? (c.has_token ? '● Đã cấu hình' : '● Thiếu token') : '● Chưa bật';
  } catch (e) {}
}
async function notifySaveConfig() {
  const body = {
    enabled: $('nt-enabled').checked ? 1 : 0,
    chat_id: $('nt-chat').value.trim(),
    send_success: $('nt-s-success').checked ? 1 : 0,
    send_warning: $('nt-s-warning').checked ? 1 : 0,
    send_error: $('nt-s-error').checked ? 1 : 0,
    send_critical: $('nt-s-critical').checked ? 1 : 0,
    send_batch_summary: $('nt-s-batch').checked ? 1 : 0,
    notify_recovery: $('nt-recovery').checked ? 1 : 0,
    quiet_start: $('nt-quiet-start').value,
    quiet_end: $('nt-quiet-end').value,
    daily_enabled: $('nt-daily').checked ? 1 : 0,
    daily_time: $('nt-daily-time').value,
    weekly_enabled: $('nt-weekly').checked ? 1 : 0,
    weekly_day: $('nt-weekly-day').value,
    weekly_time: $('nt-weekly-time').value,
  };
  const tok = $('nt-token').value.trim();
  if (tok) body.bot_token = tok;
  const r = await sendJson(api + 'notify.php?action=config_save', body);
  if (r.ok) {
    $('nt-token').value = '';
    toast('Đã lưu cấu hình Telegram', 'success');
    notifyLoadConfig();
  } else toast(r.message || 'Lỗi lưu', 'error');
}
async function notifyTestConn() {
  const st = $('nt-conn-status');
  if (st) st.textContent = 'Đang kiểm tra...';
  const tok = $('nt-token').value.trim();
  const r = await sendJson(api + 'notify.php?action=test', tok ? { bot_token: tok } : {});
  if (st) st.textContent = r.ok ? ('● Đã kết nối (' + (r.data.bot || '') + ')') : ('● ' + (r.message || 'Lỗi'));
  toast(r.ok ? 'Kết nối OK' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
}
async function notifySendTest() {
  const r = await sendJson(api + 'notify.php?action=send_test', {});
  toast(r.ok ? 'Đã gửi thành công' : ('Không gửi được: ' + (r.message || '')), r.ok ? 'success' : 'error');
}
const NT_MODE_VN = { OFF: 'Tắt', IMMEDIATE: 'Ngay', DIGEST: 'Gom' };
async function notifyLoadRules() {
  const el = $('nt-rules');
  try {
    const r = await getJson(api + 'notify.php?action=rules');
    const rows = (r.ok && r.data) ? r.data : [];
    el.innerHTML = `<table class="data-table"><thead><tr><th>Module</th><th>Event</th><th>Severity</th><th>Telegram</th><th>Mode</th></tr></thead><tbody>`
      + rows.map(x => `<tr><td>${escapeHtml(x.module)}</td><td>${escapeHtml(x.event_type)}</td>`
        + `<td>${escapeHtml(x.severity)}</td>`
        + `<td><input type="checkbox" ${x.enabled ? 'checked' : ''} onchange="notifyRuleSave(${x.id}, this)"></td>`
        + `<td><select onchange="notifyRuleSave(${x.id}, null, this)">`
        + ['OFF', 'IMMEDIATE', 'DIGEST'].map(m => `<option value="${m}" ${x.mode === m ? 'selected' : ''}>${NT_MODE_VN[m]}</option>`).join('')
        + `</select></td></tr>`).join('') + `</tbody></table>`;
  } catch (e) {
    el.innerHTML = '<div class="empty-state">Lỗi tải rules.</div>';
  }
}
async function notifyRuleSave(id, cb, sel) {
  const row = cb ? cb.closest('tr') : sel.closest('tr');
  const enabled = row.querySelector('input[type=checkbox]').checked ? 1 : 0;
  const mode = row.querySelector('select').value;
  const r = await sendJson(api + 'notify.php?action=rule_save', { id, mode, enabled });
  if (!r.ok) toast('Lỗi lưu rule', 'error');
}
async function notifyLoadHistory() {
  const el = $('nt-history');
  try {
    const r = await getJson(api + 'notify.php?action=history&limit=100');
    const rows = (r.ok && r.data) ? r.data : [];
    el.innerHTML = rows.length
      ? `<table class="data-table"><thead><tr><th>Thời gian</th><th>Module</th><th>Tiêu đề</th><th>Trạng thái</th><th></th></tr></thead><tbody>`
        + rows.map(h => `<tr><td class="mono">${escapeHtml((h.created_at || '').slice(5, 16))}</td>`
          + `<td>${escapeHtml(h.module || '')}</td>`
          + `<td><small>${escapeHtml((h.ev_title || h.message || '').slice(0, 80))}</small></td>`
          + `<td>${escapeHtml(h.status)}${h.attempt_count > 1 ? ` <small class="muted">Retry ${h.attempt_count}/3</small>` : ''}`
          + (h.last_error ? `<br><small class="muted">${escapeHtml(h.last_error)}</small>` : '') + `</td>`
          + `<td>${h.status === 'PENDING' ? `<button class="btn btn-xs" onclick="notifyCancel('${h.notification_id}')">Hủy</button>` : ''}</td></tr>`).join('')
        + `</tbody></table>`
      : '<div class="empty-state">Chưa có notification nào.</div>';
  } catch (e) {
    el.innerHTML = '<div class="empty-state">Lỗi tải.</div>';
  }
}
async function notifyCancel(id) {
  await sendJson(api + 'notify.php?action=cancel', { id });
  notifyLoadHistory();
}
async function notifyWorker(on) {
  const r = await sendJson(api + `notify.php?action=monitor_${on ? 'start' : 'stop'}`, {});
  toast(r.ok ? (on ? 'Worker đang chạy' : 'Đã dừng worker') : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  notifyLoadOverview();
}
function notifyDocHtml(doc) {
  if (!doc) return '';
  let h = `<div><strong>${escapeHtml(doc.title || '')}</strong></div>`;
  if (doc.summary) h += `<div>${escapeHtml(doc.summary)}</div>`;
  for (const s of (doc.sections || [])) {
    h += `<div class="sync-label" style="margin-top:6px">${escapeHtml(s.heading || '')}</div>`;
    h += (s.lines || []).map(l => `<div>• ${escapeHtml(l)}</div>`).join('');
  }
  return h;
}
async function notifyRange() {
  const s = ($('nt-range-start').value || '').replace('T', ' ') + ':00';
  const e = ($('nt-range-end').value || '').replace('T', ' ') + ':00';
  if (!$('nt-range-start').value || !$('nt-range-end').value) { toast('Chọn từ/đến', 'error'); return; }
  const r = await sendJson(api + 'notify.php?action=report_custom', { start: s.slice(0, 19), end: e.slice(0, 19) });
  if (r.ok) {
    $('nt-range-out').innerHTML = notifyDocHtml(r.data)
      + `<div style="margin-top:8px"><button class="btn btn-sm" onclick="notifyPreviewSend()">Gửi Telegram</button></div>`;
    window.__ntLastDoc = r.data;
    notifyLoadReports();
  } else toast(r.message || 'Lỗi', 'error');
}
async function notifyRangeSend() {
  await notifyRange();
  await notifyPreviewSend();
}
async function notifyPreviewSend() {
  // Gui bao cao vua tao: tim report moi nhat trong lich su
  try {
    const r = await getJson(api + 'notify.php?action=reports&limit=1');
    const rep = (r.ok && r.data && r.data[0]) ? r.data[0] : null;
    if (!rep) { toast('Chưa có báo cáo', 'error'); return; }
    const s = await sendJson(api + 'notify.php?action=report_send', { report_id: rep.report_id });
    toast(s.ok ? 'Đã xếp hàng gửi' : (s.message || 'Lỗi'), s.ok ? 'success' : 'error');
  } catch (e) {
    toast('Lỗi', 'error');
  }
}
async function notifyLoadReports() {
  const el = $('nt-reports');
  if (!el) return;
  try {
    const r = await getJson(api + 'notify.php?action=reports&limit=20');
    const rows = (r.ok && r.data) ? r.data : [];
    el.innerHTML = rows.length
      ? `<table class="data-table"><thead><tr><th>Loại</th><th>Phạm vi</th><th>Tạo lúc</th><th>Gửi</th><th></th></tr></thead><tbody>`
        + rows.map(x => `<tr><td>${escapeHtml(x.type)}</td>`
          + `<td class="mono"><small>${escapeHtml((x.range_start || '').slice(0, 16))} → ${(x.range_end || '').slice(0, 16)}</small></td>`
          + `<td class="mono">${escapeHtml((x.generated_at || '').slice(5, 16))}</td>`
          + `<td>${escapeHtml(x.delivery_status)}</td>`
          + `<td><button class="btn btn-xs" onclick="notifyResend('${x.report_id}')">Gửi Telegram</button></td></tr>`).join('')
        + `</tbody></table>`
      : '<div class="empty-state">Chưa có báo cáo.</div>';
  } catch (e) {
    el.innerHTML = '<div class="empty-state">Lỗi tải.</div>';
  }
}
async function notifyResend(reportId) {
  const r = await sendJson(api + 'notify.php?action=report_send', { report_id: reportId });
  toast(r.ok ? 'Đã xếp hàng gửi' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  notifyLoadReports();
}
// ============ CHAT & CONTROL (§25-§27, §48) ============
let ntChatTimer = null;
async function ntChatRefresh() {
  ntChatMetrics();
  ntChatLoad();
  ntAuditLoad();
  ntCmdRulesLoad();
  ntInboundLoad();
  if (ntChatTimer) clearInterval(ntChatTimer);
  ntChatTimer = setInterval(async () => {
    const pane = $('nt-pane-chat');
    if (!pane || pane.classList.contains('hidden') || document.hidden) return;
    ntChatLoad(true);
    ntChatMetrics();
  }, 5000);
}
async function ntChatMetrics() {
  try {
    const r = await getJson(api + 'telegram.php?action=metrics');
    if (!r.ok) return;
    const m = r.data;
    const kv = (k, v) => `<div class="mon-kv"><span>${k}</span><strong>${v}</strong></div>`;
    const el = $('nt-chat-metrics');
    if (el) el.innerHTML = kv('Bot', escapeHtml(m.bot || '?')) + kv('Inbound', m.inbound || 'Tắt')
      + kv('Authorized', m.authorized ?? 0) + kv('Commands today', m.commands_today ?? 0)
      + kv('Running jobs', m.running_jobs ?? 0);
  } catch (e) {}
}
function ntMsgHtml(m) {
  const inbound = m.direction === 'INBOUND';
  const who = inbound ? ('Telegram' + (m.user_id ? ' · ' + escapeHtml(m.user_id) : '')) : (m.source === 'UI' ? 'UI' : 'YT Manager');
  const ts = (m.created_at || '').slice(5, 16);
  let body = escapeHtml(m.text || '');
  if (m.job_id) body += `<div class="eval-prev">Job: ${escapeHtml(m.job_id)}</div>`;
  return `<div class="chat-msg ${inbound ? 'chat-in' : 'chat-out'}"><div class="chat-meta">${who} · ${ts}</div><div>${body}</div></div>`;
}
async function ntChatLoad(quiet) {
  const el = $('nt-chat-list');
  if (!el) return;
  try {
    const r = await getJson(api + 'telegram.php?action=conversation&limit=60');
    const rows = ((r.ok && r.data) || []).slice().reverse();
    const html = rows.map(ntMsgHtml).join('') || '<p class="muted">Chưa có hội thoại.</p>';
    if (el.dataset.hash !== String(rows.length) + ':' + String((rows[rows.length - 1] || {}).id || 0)) {
      el.innerHTML = html;
      el.dataset.hash = String(rows.length) + ':' + String((rows[rows.length - 1] || {}).id || 0);
      el.scrollTop = el.scrollHeight;
    }
  } catch (e) {
    if (!quiet) el.innerHTML = '<p class="muted">Lỗi tải.</p>';
  }
}
async function ntChatSend() {
  const inp = $('nt-chat-input');
  const text = (inp.value || '').trim();
  if (!text) return;
  inp.value = '';
  const r = await sendJson(api + 'telegram.php?action=send', { text });
  if (!r.ok) toast(r.message || 'Lỗi', 'error');
  ntChatLoad();
}
async function ntInboundLoad() {
  try {
    const r = await getJson(api + 'telegram.php?action=config');
    if (!r.ok) return;
    const c = r.data;
    $('tg-inbound').checked = !!c.inbound_enabled;
    $('tg-default-role').value = c.default_role || 'VIEWER';
    const box = $('tg-allowed-list');
    box.innerHTML = (c.allowed || []).map((a, i) =>
      `<div class="meta-row"><span class="meta-label">${escapeHtml(a.chat_id)}${a.user_id ? ' / ' + escapeHtml(a.user_id) : ''}</span>`
      + `<span class="meta-value">${escapeHtml(a.role)} <button type="button" class="btn btn-xs" onclick="tgDelAllowed(${i})">✕</button></span></div>`).join('')
      || '<p class="muted">Chưa có chat nào. Dùng ghép nối hoặc thêm tay.</p>';
    window.__tgAllowed = c.allowed || [];
    const ps = $('tg-poll-status');
    if (ps) ps.textContent = 'Polling: ' + (c.poll_running ? '● Listening' : '● Dừng')
      + ' · Job worker: ' + (c.job_running ? '● Chạy' : '● Dừng')
      + (c.polling && c.polling.last_update_at ? ' · Update cuối: ' + c.polling.last_update_at : '');
  } catch (e) {}
}
async function tgSaveInbound() {
  const r = await sendJson(api + 'telegram.php?action=config_save', {
    inbound_enabled: $('tg-inbound').checked ? 1 : 0,
    allowed: window.__tgAllowed || [],
    default_role: $('tg-default-role').value,
  });
  toast(r.ok ? 'Đã lưu inbound' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ntInboundLoad();
}
function tgAddAllowed() {
  const chat = ($('tg-new-chat').value || '').trim();
  if (!chat) { toast('Nhập Chat ID', 'error'); return; }
  window.__tgAllowed = window.__tgAllowed || [];
  window.__tgAllowed.push({ chat_id: chat, user_id: ($('tg-new-user').value || '').trim(), role: $('tg-new-role').value });
  $('tg-new-chat').value = '';
  $('tg-new-user').value = '';
  tgSaveInbound();
}
function tgDelAllowed(i) {
  (window.__tgAllowed || []).splice(i, 1);
  tgSaveInbound();
}
async function tgPairCreate() {
  const r = await sendJson(api + 'telegram.php?action=pair_create', {});
  if (r.ok) {
    $('tg-pair-out').textContent = 'Mã: ' + r.data.code + ' (hết hạn ' + r.data.expires_at + ') — gửi bot: /pair ' + r.data.code;
  } else toast(r.message || 'Lỗi', 'error');
}
async function tgPoll(on) {
  const r = await sendJson(api + `telegram.php?action=poll_${on ? 'start' : 'stop'}`, {});
  toast(r.ok ? (on ? 'Polling đang chạy' : 'Đã dừng polling') : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ntInboundLoad();
}
async function tgJob(on) {
  const r = await sendJson(api + `telegram.php?action=job_${on ? 'start' : 'stop'}`, {});
  toast(r.ok ? (on ? 'Job worker đang chạy' : 'Đã dừng job worker') : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  ntInboundLoad();
}
async function ntAuditLoad() {
  const el = $('nt-audit');
  if (!el) return;
  try {
    const r = await getJson(api + 'telegram.php?action=commands&limit=30');
    const rows = (r.ok && r.data) || [];
    el.innerHTML = rows.length
      ? `<table class="data-table"><thead><tr><th>Giờ</th><th>Nguồn</th><th>Lệnh</th><th>Kết quả</th></tr></thead><tbody>`
        + rows.map(c => `<tr><td class="mono">${escapeHtml((c.requested_at || '').slice(5, 16))}</td>`
          + `<td>${escapeHtml(c.source)}${c.chat_id && c.chat_id !== 'local-ui' ? '<br><small>' + escapeHtml(c.chat_id) + '</small>' : ''}</td>`
          + `<td><small>${escapeHtml(c.command_name)} ${escapeHtml(c.arguments || '')}</small></td>`
          + `<td>${escapeHtml(c.status)}${c.job_id ? '<br><small>' + escapeHtml(c.job_id) + '</small>' : ''}</td></tr>`).join('')
        + `</tbody></table>`
      : '<p class="muted">Chưa có lệnh nào.</p>';
  } catch (e) {}
}
async function ntCmdRulesLoad() {
  const el = $('nt-cmdrules');
  if (!el) return;
  try {
    const r = await getJson(api + 'telegram.php?action=cmdrules');
    const rows = (r.ok && r.data) || [];
    el.innerHTML = `<table class="data-table"><thead><tr><th>Module</th><th>Command</th><th>Role</th><th>Xác nhận</th><th>Bật</th></tr></thead><tbody>`
      + rows.map(c => `<tr><td>${escapeHtml(c.module)}</td><td>${escapeHtml(c.command)}<br><small class="muted">${escapeHtml(c.description || '')}</small></td>`
        + `<td>${escapeHtml(c.role)}</td><td>${c.confirmation ? 'Có' : 'Không'}${c.destructive ? ' (nguy hiểm)' : ''}</td>`
        + `<td>${c.enabled ? 'ON' : 'OFF'}</td></tr>`).join('') + `</tbody></table>`;
  } catch (e) {}
}
