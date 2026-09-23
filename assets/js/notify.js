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
  if (name === 'telegram') ntTestStart();
  if (name === 'telegram' || name === 'reports') {
    if (typeof ntBindAutosave === 'function') ntBindAutosave();
    ntQuietUI();
  }
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
      const rel = (ts) => {
        if (!ts) return '—';
        try { return formatRelativeTime(ts); } catch (e) { return String(ts); }
      };
      const pol = c.polling || {};
      const polOk = pol.state === 'LISTENING';
      el.innerHTML = `<div class="sync-label">Telegram</div>`
        + `<div class="meta-row"><span class="meta-label">Trạng thái</span><span class="meta-value">● Đã kết nối</span></div>`
        + `<div class="meta-row"><span class="meta-label">Bot</span><span class="meta-value">@${escapeHtml(c.bot_username || '?')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Người nhận</span><span class="meta-value">${escapeHtml(c.display_name || c.username || '?')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Loại</span><span class="meta-value">${escapeHtml(c.chat_type || 'private')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Chat ID</span><span class="meta-value mono">${escapeHtml(c.chat_masked || '')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Quyền</span><span class="meta-value">${escapeHtml(c.role || '')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Polling</span><span class="meta-value">${polOk ? '● Online' : '● ' + escapeHtml(pol.state || 'Offline')}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Nhận cuối</span><span class="meta-value">${escapeHtml(rel(c.last_in_at))}</span></div>`
        + `<div class="meta-row"><span class="meta-label">Gửi cuối</span><span class="meta-value">${escapeHtml(rel(c.last_out_at))}</span></div>`
        + `<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">`
        + `<button type="button" class="btn btn-sm" onclick="notifySendTest()">Gửi thử</button>`
        + (c.bot_username ? `<button type="button" class="btn btn-sm" onclick="window.open('https://t.me/${escapeHtml(c.bot_username)}','_blank')">Mở Telegram</button>` : '')
        + `<button type="button" class="btn btn-sm" onclick="tgChangeReceiver()">Đổi người nhận</button>`
        + `<button type="button" class="btn btn-sm btn-danger" onclick="tgDisconnect()">Ngắt kết nối</button>`
        + `</div><div class="hint" id="tg-setup-note"></div>`
        + `<div style="margin-top:8px"><button type="button" class="link-btn" onclick="tgDiagToggle()">Telegram Diagnostics ▾</button>`
        + `<div id="tg-diag" class="hidden" style="margin-top:4px"></div></div>`;
      tgDiagLoad();
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
  const hasCand = !!(sess.candidate_display || sess.candidate_username);
  // Candidate view gon (§19): phat hien + san sang + cho xac nhan + dung chat nay
  const cand = hasCand
    ? `<div style="margin-top:8px"><div class="sync-label">Telegram</div>`
      + `<div class="meta-row"><span class="meta-label">Phát hiện</span><span class="meta-value"><strong>${escapeHtml(sess.candidate_display || sess.candidate_username || '?')}</strong></span></div>`
      + `<div class="meta-row"><span class="meta-label">Trạng thái</span><span class="meta-value">● Chat Test sẵn sàng</span></div>`
      + `<div class="meta-row"><span class="meta-label">Ghép quản trị</span><span class="meta-value">◌ Chờ xác nhận</span></div>`
      + `<div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">`
      + `<button type="button" class="btn btn-sm btn-primary" onclick="tgUseChat('${sess.session_id || ''}')">✓ Dùng chat này</button>`
      + `<button type="button" class="btn btn-sm" onclick="window.open('https://t.me/${escapeHtml((info && info.bot_username) || '')}','_blank')">Mở Telegram</button>`
      + `</div></div>`
    : '';
  el.innerHTML = `<div class="sync-label">Kết nối Telegram</div>`
    + tgSetupSteps(step)
    + `<div style="margin-top:8px">✓ Bot Token hợp lệ<br>Bot: <strong>${escapeHtml(bot)}</strong></div>`
    + `<div style="margin-top:6px">Bây giờ hãy mở Telegram và nhắn <strong>/start</strong> cho ${escapeHtml(bot)}</div>`
    + cand
    + `<div class="eval-prev">◌ Đang chờ tin nhắn... ${sess.candidate_count > 0 ? `(phát hiện ${sess.candidate_count} yêu cầu)` : ''}</div>`
    + `<div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">`
    + `<button type="button" class="btn btn-sm" onclick="tgSetupPing()">Tôi đã nhắn /start</button>`
    + `<button type="button" class="btn btn-sm" onclick="tgSetupCancel('${sess.session_id || ''}')">Hủy</button>`
    + `</div>`
    + `<div class="hint" id="tg-setup-note"></div>`
    + `<div style="margin-top:8px"><button type="button" class="link-btn" onclick="tgDiagToggle()">Telegram Diagnostics ▾</button>`
    + `<div id="tg-diag" class="hidden" style="margin-top:4px"></div></div>`;
  tgDiagLoad();
  clearTimeout(window.__tgSetupTimer);
  window.__tgSetupTimer = setTimeout(() => {
    const pane = $('nt-pane-telegram');
    if (pane && !pane.classList.contains('hidden')) tgSetupView();
  }, 4000);
}
async function tgUseChat(sid) {
  const r = await sendJson(api + 'notify.php?action=pair_confirm_tool', { session_id: sid });
  toast(r.ok ? 'Đã ghép nối.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  tgSetupView();
}
function tgDiagToggle() {
  const el = $('tg-diag');
  if (el) el.classList.toggle('hidden');
}
async function tgDiagLoad() {
  const el = $('tg-diag');
  if (!el) return;
  try {
    const [r, t] = await Promise.all([
      getJson(api + 'notify.php?action=setup_diag').catch(() => null),
      getJson(api + 'notify.php?action=tg_status').catch(() => null),
    ]);
    if (!r || !r.ok) return;
    const d = r.data;
    const row = (k, v) => `<div class="meta-row"><span class="meta-label">${k}</span><span class="meta-value">${v}</span></div>`;
    const st = d.polling || {};
    const tdata = (t && t.ok && t.data) || {};
    const mask = (s) => {
      s = String(s || '');
      return s.length <= 4 ? '••••' : '••••••' + s.slice(-4);
    };
    el.innerHTML = row('Bot', escapeHtml((d.bot && d.bot.username ? '@' + d.bot.username : '?')))
      + row('Transport', escapeHtml(tdata.transport || '?'))
      + row('Pairing', escapeHtml(tdata.pairing || '?'))
      + row('Gateway', d.token ? 'RUNNING' : 'STOPPED')
      + row('Polling', escapeHtml(st.state || 'STOPPED'))
      + row('Webhook', d.webhook && d.webhook.active ? 'ACTIVE ⚠' : 'NONE')
      + row('Last poll', st.last_poll_completed_at ? 'vừa xong' : '—')
      + row('Last update', escapeHtml(st.last_update_at || 'chưa có'))
      + row('Primary chat', tdata.effective_chat && tdata.effective_chat.source === 'primary' ? mask((tdata || {}).chat_masked || '') : 'NONE')
      + row('Candidate chat', (tdata.setup_session && (tdata.setup_session.candidate_display || tdata.setup_session.candidate_username))
        ? escapeHtml(tdata.setup_session.candidate_display || tdata.setup_session.candidate_username) : 'NONE')
      + row('Inbound', tdata.last_in_at ? escapeHtml(tdata.last_in_at) : '—')
      + row('Outbound', tdata.last_out_at ? escapeHtml(tdata.last_out_at) : '—')
      + row('Pair session', escapeHtml((d.session && d.session.status) || '—'))
      + (st.last_error ? row('Lỗi', escapeHtml(friendlyPollErr(st.last_error))) : '');
  } catch (e) {}
}
function friendlyPollErr(e) {
  e = String(e || '');
  if (e === 'TELEGRAM_POLLING_CONFLICT') return 'Bot đang được một tiến trình khác sử dụng (409).';
  if (e === 'poll_unauthorized') return 'Bot Token không hợp lệ (401).';
  if (e === 'inbound_disabled') return 'Worker chờ (chưa bật inbound, không có setup session).';
  return e;
}
async function tgSetupPing() {
  const note = $('tg-setup-note');
  if (note) note.textContent = 'Đang kiểm tra tin nhắn mới...';
  try {
    const r = await sendJson(api + 'notify.php?action=setup_ping', {});
    if (!r.ok) { if (note) note.textContent = r.message || 'Lỗi'; return; }
    const p = r.data.probe || {};
    if (p.error === 'worker_listening') {
      if (note) note.textContent = 'Worker đang lắng nghe — chờ vài giây rồi kiểm tra lại.';
    } else if ((p.update_count || 0) > 0) {
      if (note) note.textContent = `Đã nhận ${p.update_count} tin nhắn mới — đang xử lý...`;
    } else if (p.error) {
      if (note) note.textContent = 'Lỗi: ' + friendlyPollErr(p.error);
    } else {
      const d = r.data.diagnostics || {};
      const bot = (d.bot && d.bot.username) ? '@' + d.bot.username : 'bot';
      if (note) note.textContent = `Tool vẫn chưa nhận được tin nhắn. Kiểm tra bạn đang nhắn đúng ${bot}.`;
    }
    tgSetupView();
    tgDiagLoad();
  } catch (e) {
    if (note) note.textContent = 'Lỗi kết nối.';
  }
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
async function tgDisconnect() {
  confirmDelete('Ngắt kết nối Telegram?<br><small>Tool sẽ ngừng gửi báo cáo và nhận lệnh.</small>', async () => {
    const r = await sendJson(api + 'notify.php?action=disconnect', {});
    if (r.ok) { toast('Đã ngắt kết nối', 'success'); tgSetupView(); notifyLoadConfig(); }
    else toast(r.message || 'Lỗi', 'error');
  });
}
// ============ CHAT TEST trong tab Telegram (§2-§20) ============
// Reuse poller + store hien co (khong worker rieng §12). Test mode: hien text.
const ntTest = { msgs: [], newest: 0, unseen: 0, clearedAt: 0, loading: false, timer: null };
function ntTestStart() {
  ntTestBindInput();
  ntTestPoll();
  if (ntTest.timer) clearInterval(ntTest.timer);
  ntTest.timer = setInterval(() => {
    const pane = $('nt-pane-telegram');
    if (!pane || pane.classList.contains('hidden') || document.hidden) return;
    ntTestPoll();
  }, 3000);
}
function ntTestStatus() {
  const st = $('nt-test-status');
  if (!st) return;
  getJson(api + 'notify.php?action=tg_status').then(r => {
    if (!r.ok) return;
    const c = r.data;
    // Badge 4 trang thai (§7): transport OFFLINE / ONLINE-cho / candidate / paired
    if (c.transport === 'OFFLINE' || c.transport === 'ERROR') {
      st.textContent = '● Mất kết nối';
      st.className = 'badge badge-err';
    } else if (c.pairing === 'PAIRED') {
      st.textContent = '● Đã kết nối';
      st.className = 'badge badge-ok';
    } else if (c.effective_chat) {
      st.textContent = '● Chat Test sẵn sàng';
      st.className = 'badge badge-ok';
    } else {
      st.textContent = '◌ Chờ tin nhắn Telegram';
      st.className = 'badge badge-warn';
    }
    ntTestSendState(!!c.effective_chat);
  }).catch(() => {});
}
function ntTestSendState(canSend) {
  window.__ntCanSend = !!canSend;
  const inp = $('nt-test-input');
  const btn = $('nt-test-send');
  if (!btn) return;
  const hasText = inp && (inp.value || '').trim() !== '';
  btn.disabled = !(hasText && canSend);
  if (btn && !canSend) {
    btn.title = 'Hãy nhắn một tin cho Bot trước.';
  } else if (btn) {
    btn.title = '';
  }
}
function ntTestRender() {
  const el = $('nt-test-list');
  if (!el) return;
  const rows = ntTest.msgs.filter(m => !ntTest.clearedAt || m.id > ntTest.clearedAt);
  if (!rows.length) {
    el.innerHTML = `<div class="empty-state">💬<br>Chưa có tin nhắn<br><small>Hãy nhắn cho Bot trên Telegram hoặc gửi một tin từ Tool để kiểm tra.</small></div>`;
    return;
  }
  let html = '';
  let lastDay = '';
  for (const m of rows) {
    const day = ntChatDayLabel(ntParseTs(m.created_at));
    if (day !== lastDay) {
      html += `<div class="chat-date-sep">${day}</div>`;
      lastDay = day;
    }
    html += ntTestMsgHtml(m);
  }
  el.innerHTML = html;
}
function ntTestMsgHtml(m) {
  const inbound = m.direction === 'INBOUND';
  const who = inbound ? 'Telegram' : 'YT Manager';
  const ts = ntParseTs(m.created_at);
  let status = '';
  if (!inbound) {
    if (m.status === 'SENT') status = `<div class="chat-status st-ok">✓</div>`;
    else if (m.status === 'FAILED') status = `<div class="chat-status st-err">! <button type="button" class="btn btn-xs" onclick="ntTestRetry(${m.id})">Thử lại</button></div>`;
    else status = `<div class="chat-status">◌</div>`;
  }
  return `<div class="chat-msg ${inbound ? 'chat-in' : 'chat-out'}" data-mid="${m.id}">`
    + `<div class="chat-meta"><span>${who}</span>`
    + `<span title="${ntChatFull(ts)}">${ntChatTime(ts)}</span>${status}</div>`
    + `<div>${escapeHtml(m.text || '')}</div></div>`;
}
function ntTestNearBottom() {
  const el = $('nt-test-list');
  if (!el) return true;
  return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}
async function ntTestPoll() {
  if (ntTest.loading) return;
  ntTest.loading = true;
  ntTestStatus();
  try {
    const r = await getJson(api + 'telegram.php?action=chat_page&limit=30&type=TEXT');
    const rows = ((r.ok && r.data) || []).slice().reverse();
    const stick = ntTestNearBottom();
    let added = 0;
    for (const m of rows) {
      if (m.id > ntTest.newest && !ntTest.msgs.some(x => x.id === m.id)) {
        ntTest.msgs.push(m);
        ntTest.newest = m.id;
        added++;
      }
      const ex = ntTest.msgs.find(x => x.id === m.id);
      if (ex && ex.status !== m.status) ex.status = m.status;
    }
    if (ntTest.msgs.length > 200) ntTest.msgs = ntTest.msgs.slice(-200);
    if (added > 0) {
      ntTestRender();
      if (stick) {
        const el = $('nt-test-list');
        if (el) el.scrollTop = el.scrollHeight;
      } else {
        ntTest.unseen += added;
        const n = $('nt-test-new-n');
        if (n) n.textContent = ntTest.unseen;
        const b = $('nt-test-new');
        if (b) b.classList.remove('hidden');
      }
    }
  } catch (e) {
  }
  ntTest.loading = false;
}
function ntTestJump() {
  ntTest.unseen = 0;
  const b = $('nt-test-new');
  if (b) b.classList.add('hidden');
  const el = $('nt-test-list');
  if (el) el.scrollTop = el.scrollHeight;
}
function ntTestClear() {
  ntTest.clearedAt = ntTest.newest;
  ntTest.unseen = 0;
  const b = $('nt-test-new');
  if (b) b.classList.add('hidden');
  ntTestRender();
}
function ntTestBindInput() {
  const inp = $('nt-test-input');
  const btn = $('nt-test-send');
  if (!inp || inp.dataset.ntbound) return;
  inp.dataset.ntbound = '1';
  inp.addEventListener('input', () => {
    if (btn) btn.disabled = (inp.value || '').trim() === '' || !window.__ntCanSend;
  });
  inp.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      ntTestSend();
    }
  });
  if (btn) btn.disabled = true;
}
async function sendTestMessage(text) {
  // (§9) hien SENDING ngay -> gui -> SENT/FAILED. Khong reload.
  const tempId = -Date.now();
  ntTest.msgs.push({ id: tempId, direction: 'OUTBOUND', text, status: 'SENDING', created_at: new Date().toISOString() });
  ntTestRender();
  const el = $('nt-test-list');
  if (el) el.scrollTop = el.scrollHeight;
  try {
    const r = await sendJson(api + 'telegram.php?action=send_text', { text });
    ntTest.msgs = ntTest.msgs.filter(x => x.id !== tempId);
    if (r.ok && r.data && r.data.id) {
      ntTest.msgs.push({ id: r.data.id, direction: 'OUTBOUND', text, status: 'SENT', created_at: new Date().toISOString() });
      ntTest.newest = Math.max(ntTest.newest, r.data.id);
    } else {
      ntTest.msgs.push({ id: tempId, direction: 'OUTBOUND', text, status: 'FAILED', created_at: new Date().toISOString(), _retry: true });
      toast(r.message || 'Không gửi được', 'error');
    }
  } catch (e) {
    ntTest.msgs = ntTest.msgs.filter(x => x.id !== tempId);
    ntTest.msgs.push({ id: tempId, direction: 'OUTBOUND', text, status: 'FAILED', created_at: new Date().toISOString(), _retry: true });
    toast('Telegram đang mất kết nối.', 'error');
  }
  ntTestRender();
  if (el) el.scrollTop = el.scrollHeight;
  ntTestPoll();
}
async function ntTestSend() {
  const inp = $('nt-test-input');
  const text = (inp.value || '').trim();
  if (!text) return;
  inp.value = '';
  const btn = $('nt-test-send');
  if (btn) btn.disabled = true;
  await sendTestMessage(text);
  if (btn) btn.disabled = (inp.value || '').trim() === '';
}
async function ntTestRetry(mid) {
  const m = ntTest.msgs.find(x => x.id === mid);
  const text = m ? m.text : '';
  if (!text) return;
  ntTest.msgs = ntTest.msgs.filter(x => x.id !== mid);
  ntTestRender();
  await sendTestMessage(text);
}
async function notifyLoadConfig() {
  // Telegram tab moi: preset + autosave controls (nap tu preset_get)
  try {
    const r = await getJson(api + 'telegram.php?action=notify_preset_get');
    if (r.ok && r.data) {
      const sel = $('nt-preset');
      if (sel) sel.value = r.data.preset || 'custom';
      applyNtValues(r.data.values || {});
      const cm = $('nt-cmd-mode');
      if (cm) cm.checked = !!r.data.command_mode;
    }
  } catch (e) {}
  const st = $('nt-conn-status');
  try {
    const c = await getJson(api + 'notify.php?action=config');
    if (st && c.ok) st.textContent = c.data.enabled ? (c.data.has_token ? '● Đã cấu hình' : '● Thiếu token') : '● Chưa bật';
  } catch (e) {}
}
function applyNtValues(v) {
  const vals = {
    'nt-s-warning': v.send_warning, 'nt-s-error': v.send_error, 'nt-s-critical': v.send_critical,
    'nt-recovery': v.notify_recovery, 'nt-s-batch': v.send_batch_summary, 'nt-daily': v.daily_enabled,
    'nt-s-info': v.send_info, 'nt-s-success': v.send_success,
    'nt-r-daily': v.daily_enabled, 'nt-r-weekly': v.weekly_enabled,
  };
  for (const [id, val] of Object.entries(vals)) {
    const el = $(id);
    if (el) el.checked = !!val;
  }
  const dt = $('nt-daily-time');
  if (dt && v.daily_time) dt.value = v.daily_time;
  const rdt = $('nt-r-daily-time');
  if (rdt && v.daily_time) rdt.value = v.daily_time;
  const en = $('nt-enabled');
  if (en) en.value = '1';
  ntQuietUI();
}
async function notifyPresetApply(preset) {
  if (preset === 'custom') return;
  const r = await sendJson(api + 'telegram.php?action=notify_preset', { preset });
  if (r.ok) {
    toast('Đã áp preset', 'success');
    notifyLoadConfig();
  } else toast(r.message || 'Lỗi', 'error');
}
// Auto-save debounce 400ms (§AB): tick -> save -> "✓ Đã lưu"
let ntSaveTimer = null;
let ntSaveState = null;
function ntQueueSave(key, value) {
  const el = $('nt-preset-saved');
  if (el) el.textContent = 'Đang lưu...';
  clearTimeout(ntSaveTimer);
  ntSaveTimer = setTimeout(async () => {
    try {
      const r = await sendJson(api + 'telegram.php?action=notify_autosave', { key, value });
      if (el) el.textContent = r.ok ? '✓ Đã lưu' : 'Lỗi lưu';
      // User tu sua -> preset custom (§AA)
      const sel = $('nt-preset');
      if (sel && r.ok) {
        try {
          const g = await getJson(api + 'telegram.php?action=notify_preset_get');
          if (g.ok) sel.value = g.data.preset || 'custom';
        } catch (e) {}
      }
    } catch (e) {
      if (el) el.textContent = 'Lỗi lưu';
    }
  }, 400);
}
function ntBindAutosave() {
  document.querySelectorAll('[data-ntkey]').forEach(el => {
    if (el.dataset.ntbound) return;
    el.dataset.ntbound = '1';
    const key = el.dataset.ntkey;
    const kind = el.dataset.ntval || (el.type === 'checkbox' ? 'check' : 'val');
    const get = () => {
      if (kind === 'check') return el.checked ? '1' : '0';
      if (kind === 'bool01') return el.value === '1' ? '1' : '0';
      if (kind === 'int17') return String(Math.max(1, Math.min(7, parseInt(el.value || '1', 10) || 1)));
      return el.value;
    };
    el.addEventListener('change', () => {
      ntQueueSave(key, get());
      if (key === 'notify_quiet_start' || key === 'notify_quiet_end') return;
      ntQuietUI();
    });
  });
  const qo = $('nt-quiet-on');
  if (qo && !qo.dataset.ntbound) {
    qo.dataset.ntbound = '1';
    qo.addEventListener('change', () => {
      if (qo.checked) {
        ntQueueSave('notify_quiet_start', $('nt-quiet-start').value || '23:00');
        setTimeout(() => ntQueueSave('notify_quiet_end', $('nt-quiet-end').value || '07:00'), 450);
      } else {
        ntQueueSave('notify_quiet_start', '');
        setTimeout(() => ntQueueSave('notify_quiet_end', ''), 450);
      }
      ntQuietUI();
    });
  }
  const qs = $('nt-quiet-start'), qe = $('nt-quiet-end');
  [qs, qe].forEach(x => {
    if (x && !x.dataset.ntbound) {
      x.dataset.ntbound = '1';
      x.addEventListener('change', () => {
        ntQueueSave('notify_quiet_start', qs.value);
        setTimeout(() => ntQueueSave('notify_quiet_end', qe.value), 450);
      });
    }
  });
  const cm = $('nt-cmd-mode');
  if (cm && !cm.dataset.ntbound) {
    cm.dataset.ntbound = '1';
    cm.addEventListener('change', () => ntQueueSave('tg_chat_command_mode', cm.checked ? '1' : '0'));
  }
}
function ntQuietUI() {
  const qs = $('nt-quiet-start');
  const on = qs && qs.value !== '';
  const row = $('nt-quiet-row');
  const qo = $('nt-quiet-on');
  if (qo) qo.checked = !!on;
  if (row) row.classList.toggle('hidden', !on);
}
async function notifyTestConn() {
  const st = $('nt-conn-status');
  if (st) st.textContent = 'Đang kiểm tra...';
  const tokEl = $('nt-token');
  const tok = tokEl ? tokEl.value.trim() : '';
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
  ntChatInit();
  ntAuditLoad();
  ntCmdRulesLoad();
  ntInboundLoad();
  const cm = $('nt-cmd-mode');
  if (cm) {
    try {
      const g = await getJson(api + 'telegram.php?action=notify_preset_get');
      if (g.ok) cm.checked = !!g.data.command_mode;
    } catch (e) {}
  }
  ntChatBindInput();
  const cmdMode = $('nt-cmd-mode');
  if (cmdMode && !cmdMode.dataset.ntbound) {
    cmdMode.dataset.ntbound = '1';
    cmdMode.addEventListener('change', () => {
      sendJson(api + 'telegram.php?action=notify_autosave',
        { key: 'tg_chat_command_mode', value: cmdMode.checked ? '1' : '0' })
        .then(r => toast(r.ok ? '✓ Đã lưu' : 'Lỗi lưu', r.ok ? 'success' : 'error'));
    });
  }
  if (ntChatTimer) clearInterval(ntChatTimer);
  ntChatTimer = setInterval(async () => {
    const pane = $('nt-pane-chat');
    if (!pane || pane.classList.contains('hidden') || document.hidden) return;
    ntChatPoll();
    ntChatMetrics();
  }, 3000);
}
// ============ CHAT CONSOLE state + render (E-P, R-BB) ============
const ntChat = { msgs: [], oldest: 0, newest: 0, filter: 'all', clearedAt: 0, unseen: 0, loading: false };
async function ntChatMetrics() {
  try {
    const [m, t] = await Promise.all([
      getJson(api + 'telegram.php?action=metrics').catch(() => null),
      getJson(api + 'notify.php?action=tg_status').catch(() => null),
    ]);
    if (m && m.ok) {
      const d = m.data;
      const kv = (k, v) => `<div class="mon-kv"><span>${k}</span><strong>${v}</strong></div>`;
      const el = $('nt-chat-metrics');
      if (el) el.innerHTML = kv('Bot', escapeHtml(d.bot || '?')) + kv('Inbound', d.inbound || 'Tắt')
        + kv('Authorized', d.authorized ?? 0) + kv('Commands today', d.commands_today ?? 0)
        + kv('Running jobs', d.running_jobs ?? 0);
    }
    const st = $('nt-chat-status');
    if (st && t && t.ok) {
      const ok = !!t.data.connected;
      st.textContent = ok ? '● Telegram OK' : '● Chưa kết nối';
      st.className = 'badge ' + (ok ? 'badge-ok' : 'badge-muted');
      const peer = $('nt-chat-peer');
      if (peer) peer.textContent = ok ? ((t.data.display_name || t.data.username || '') + ' • ' + (t.data.role || '')) : '';
    }
  } catch (e) {}
}
function ntChatDayLabel(ts) {
  const d = new Date(ts);
  const t = new Date();
  const day = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const today = new Date(t.getFullYear(), t.getMonth(), t.getDate()).getTime();
  if (day === today) return 'Hôm nay';
  if (day === today - 86400000) return 'Hôm qua';
  return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
}
function ntChatTime(ts) {
  const d = new Date(ts);
  return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}
function ntChatFull(ts) {
  const d = new Date(ts);
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}
function ntParseTs(s) {
  if (!s) return Date.now();
  const t = new Date(String(s).replace(' ', 'T') + '+07:00').getTime();
  return isNaN(t) ? Date.now() : t;
}
function ntTypeLabel(m) {
  const t = m.msg_type || 'TEXT';
  if (t === 'TEXT') return '';
  const vn = { COMMAND: 'COMMAND', JOB: 'JOB', ALERT: 'ALERT', REPORT: 'REPORT', SYSTEM: 'HỆ THỐNG' };
  return `<span class="chat-type chat-type-${t}">${vn[t] || t}</span>`;
}
function ntMsgStatus(m) {
  if (m.direction !== 'OUTBOUND') return '';
  if (m.status === 'SENT') return `<div class="chat-status st-ok">✓ Đã gửi</div>`;
  if (m.status === 'FAILED') return `<div class="chat-status st-err">! Gửi thất bại <button type="button" class="btn btn-xs" onclick="ntChatRetry(${m.id})">Thử lại</button></div>`;
  return `<div class="chat-status">◌ Đang gửi</div>`;
}
function ntMsgHtml(m) {
  const inbound = m.direction === 'INBOUND';
  const who = inbound ? 'Telegram' : 'YT Manager';
  const ts = ntParseTs(m.created_at);
  let body = `<div>${escapeHtml(m.text || '')}</div>`;
  if (m.job_id) {
    body += `<div class="eval-prev">Job: ${escapeHtml(m.job_id)}</div>`;
  }
  return `<div class="chat-msg ${inbound ? 'chat-in' : 'chat-out'}" data-mid="${m.id}">`
    + `<div class="chat-meta"><span>${who}</span>${ntTypeLabel(m)}`
    + `<span title="${ntChatFull(ts)}">${ntChatTime(ts)}</span></div>`
    + body + ntMsgStatus(m) + `</div>`;
}
function ntChatVisible(m) {
  if (ntChat.filter !== 'all' && (m.msg_type || 'TEXT') !== ntChat.filter) return false;
  if (ntChat.clearedAt && m.id <= ntChat.clearedAt) return false;
  return true;
}
function ntChatRender() {
  const el = $('nt-chat-list');
  if (!el) return;
  const rows = ntChat.msgs.filter(ntChatVisible);
  if (!rows.length) {
    el.innerHTML = `<div class="empty-state">💬<br>Chưa có tin nhắn<br><small>Hãy gửi một tin trên Telegram hoặc nhập tin nhắn bên dưới để kiểm tra.</small></div>`;
    return;
  }
  let html = '';
  let lastDay = '';
  for (const m of rows) {
    const day = ntChatDayLabel(ntParseTs(m.created_at));
    if (day !== lastDay) {
      html += `<div class="chat-date-sep">${day}</div>`;
      lastDay = day;
    }
    html += ntMsgHtml(m);
  }
  el.innerHTML = html;
}
function ntChatNearBottom() {
  const el = $('nt-chat-list');
  if (!el) return true;
  return el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}
function ntChatToBottom() {
  const el = $('nt-chat-list');
  if (el) el.scrollTop = el.scrollHeight;
}
async function ntChatInit() {
  try {
    const r = await getJson(api + `telegram.php?action=chat_page&limit=50&type=${ntChat.filter}`);
    const rows = ((r.ok && r.data) || []).slice().reverse();
    ntChat.msgs = rows;
    ntChat.oldest = rows.length ? rows[0].id : 0;
    ntChat.newest = rows.length ? rows[rows.length - 1].id : 0;
    ntChat.unseen = 0;
    const b = $('nt-chat-new');
    if (b) b.parentElement.classList.add('hidden');
    ntChatRender();
    ntChatToBottom();
  } catch (e) {}
}
async function ntChatPoll() {
  if (ntChat.loading) return;
  ntChat.loading = true;
  try {
    const r = await getJson(api + `telegram.php?action=chat_page&limit=20&type=${ntChat.filter}`);
    const rows = ((r.ok && r.data) || []).slice().reverse();
    const stick = ntChatNearBottom();
    let added = 0;
    for (const m of rows) {
      if (m.id > ntChat.newest && !ntChat.msgs.some(x => x.id === m.id)) {
        ntChat.msgs.push(m);
        ntChat.newest = m.id;
        added++;
      }
      const ex = ntChat.msgs.find(x => x.id === m.id);
      if (ex && (ex.status !== m.status)) ex.status = m.status;
    }
    if (ntChat.msgs.length > 300) ntChat.msgs = ntChat.msgs.slice(-300);
    if (added > 0) {
      ntChatRender();
      if (stick) {
        ntChatToBottom();
      } else {
        ntChat.unseen += added;
        const n = $('nt-chat-new-n');
        if (n) n.textContent = ntChat.unseen;
        const b = $('nt-chat-new');
        if (b) b.parentElement.classList.remove('hidden');
      }
    } else if (rows.length) {
      ntChatRender();
      if (stick) ntChatToBottom();
    }
  } catch (e) {
  }
  ntChat.loading = false;
}
async function ntChatOlder() {
  if (!ntChat.oldest || ntChat.loading) return;
  ntChat.loading = true;
  try {
    const r = await getJson(api + `telegram.php?action=chat_page&limit=50&before_id=${ntChat.oldest}&type=${ntChat.filter}`);
    const rows = ((r.ok && r.data) || []).slice().reverse();
    if (rows.length) {
      const el = $('nt-chat-list');
      const h0 = el ? el.scrollHeight : 0;
      ntChat.msgs = rows.concat(ntChat.msgs);
      ntChat.oldest = rows[0].id;
      ntChatRender();
      if (el) el.scrollTop = el.scrollHeight - h0;
    }
  } catch (e) {
  }
  ntChat.loading = false;
}
function ntChatJumpNew() {
  ntChat.unseen = 0;
  const b = $('nt-chat-new');
  if (b) b.parentElement.classList.add('hidden');
  ntChatToBottom();
}
function ntChatFilter(t) {
  ntChat.filter = t;
  ntChat.clearedAt = 0;
  ntChat.oldest = 0;
  ntChat.newest = 0;
  ntChat.msgs = [];
  ntChatInit();
}
function ntChatClearView() {
  ntChat.clearedAt = ntChat.newest;
  ntChat.unseen = 0;
  const b = $('nt-chat-new');
  if (b) b.parentElement.classList.add('hidden');
  ntChatRender();
}
function ntChatMode(m) {
  // Test Chat la mode mac dinh hien tai: text gui nhu tin nhan thuong (§P)
}
function ntChatBindInput() {
  const inp = $('nt-chat-input');
  const btn = $('nt-chat-send');
  if (!inp || inp.dataset.ntbound) return;
  inp.dataset.ntbound = '1';
  const syncBtn = () => { if (btn) btn.disabled = (inp.value || '').trim() === ''; };
  inp.addEventListener('input', syncBtn);
  inp.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      ntChatSend();
    }
  });
  syncBtn();
  const list = $('nt-chat-list');
  if (list && !list.dataset.ntbound) {
    list.dataset.ntbound = '1';
    list.addEventListener('scroll', () => {
      if (list.scrollTop <= 40) ntChatOlder();
    });
  }
}
async function ntChatSend() {
  const inp = $('nt-chat-input');
  const btn = $('nt-chat-send');
  const text = (inp.value || '').trim();
  if (!text) return;
  btn.disabled = true;
  try {
    const r = await sendJson(api + 'telegram.php?action=send_text', { text });
    if (r.ok) {
      inp.value = '';
      ntChatPoll();
    } else {
      toast(r.message || 'Không gửi được', 'error');
    }
  } catch (e) {
    toast('Telegram đang mất kết nối.', 'error');
  }
  btn.disabled = false;
  if (btn) btn.disabled = (inp.value || '').trim() === '';
}
async function ntChatRetry(id) {
  const r = await sendJson(api + 'telegram.php?action=resend', { id });
  if (!r.ok) toast(r.message || 'Lỗi', 'error');
  ntChatPoll();
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
