// ============ AI DEV CONSOLE UI ============
let aiTimer = null;
let aiDrawerTimer = null;
let aiDrawerCode = null;

const AI_JOB_BADGE = { QUEUED: 'muted', PREPARING: 'warn', ANALYZING: 'info', CODING: 'info', TESTING: 'warn', REVIEW_READY: 'warn', APPROVED: 'ok', APPLYING: 'warn', APPLIED: 'ok', REJECTED: 'muted', FAILED: 'err', CANCELLED: 'muted', INTERRUPTED: 'err' };
const AI_OC_STATE = { ONLINE: 'ok', BUSY: 'info', STARTING: 'warn', RESTARTING: 'warn', STOPPED: 'muted', ERROR: 'err' };

function aiInit() {
  aiRefresh();
  if (aiTimer) clearInterval(aiTimer);
  aiTimer = setInterval(() => {
    const pane = $('view-aidev');
    if (!pane || !pane.classList.contains('active') || document.hidden) return;
    aiRefresh();
  }, 15000);
}

async function aiRefresh(force) {
  try {
    const r = await getJson(api + 'aidev.php?action=status');
    if (!r.ok) return;
    const d = r.data;
    aiRenderOc(d);
    aiRenderJobs();
    aiRenderConfig(d.config || {});
    aiRenderProject(d);
    const nav = $('nav-ai-count');
    if (nav) {
      const n = d.pending_reviews || 0;
      nav.textContent = n;
      nav.classList.toggle('hidden', !n);
    }
    if (force) toast('Đã làm mới.', 'success');
  } catch (e) {}
}

function aiRenderOc(d) {
  const el = $('ai-oc');
  const badge = $('ai-oc-state');
  if (!el) return;
  const oc = d.opencode || {};
  const st = oc.state || 'STOPPED';
  if (badge) {
    badge.textContent = '● ' + st;
    badge.className = 'badge badge-' + (AI_OC_STATE[st] || 'muted');
  }
  const kv = (k, v) => `<div class="meta-row"><span class="meta-label">${k}</span><span class="meta-value">${v}</span></div>`;
  el.innerHTML =
    kv('Trạng thái', `<span class="badge badge-${AI_OC_STATE[st] || 'muted'}">${st}</span>`)
    + kv('Server', `<span class="mono">${escapeHtml(oc.url || '')}</span>`)
    + (oc.pid ? kv('PID', escapeHtml(String(oc.pid))) : '')
    + kv('Dev Jobs', escapeHtml(String(d.active_jobs || 0)) + ' active · ' + escapeHtml(String(d.pending_reviews || 0)) + ' chờ duyệt')
    + kv('Sessions', escapeHtml(String(d.active_sessions || 0)) + ' active')
    + kv('Git', escapeHtml((d.git && d.git.branch) || '?') + ' · ' + ((d.git && d.git.clean) ? 'sạch' : 'đang có thay đổi'))
    + `<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">`
    + `<button type="button" class="btn btn-sm" onclick="aiOc('oc_restart')">Restart OpenCode</button>`
    + (st === 'STOPPED' || st === 'ERROR' ? `<button type="button" class="btn btn-sm btn-primary" onclick="aiOc('oc_start')">Khởi động</button>` : '')
    + `</div>`;
}
async function aiOc(action) {
  toast('Đang xử lý...', '');
  const r = await sendJson(api + 'aidev.php?action=' + action, {});
  toast(r.ok ? 'Xong.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  aiRefresh();
}

async function aiRenderJobs() {
  const el = $('ai-jobs');
  if (!el) return;
  try {
    const r = await getJson(api + 'aidev.php?action=jobs&limit=10');
    const rows = (r.ok && r.data) ? r.data : [];
    const active = rows.filter(j => !['APPLIED', 'REJECTED', 'FAILED', 'CANCELLED'].includes(j.status));
    el.innerHTML = active.length
      ? active.map(j => `<div class="cc-job" onclick="aiDrawerOpen('${escapeHtml(j.job_code)}')">`
        + `<div class="cc-job-top"><strong>${escapeHtml(j.job_code)}</strong>`
        + `<span class="badge badge-${AI_JOB_BADGE[j.status] || 'muted'}">${escapeHtml(j.status)}</span></div>`
        + `<div class="cc-job-mid"><span>${escapeHtml((j.request || '').slice(0, 90))}</span></div></div>`).join('')
      : '<div class="empty-state">Chưa có Dev Job. Từ Telegram: /plan hoặc /dev</div>';
  } catch (e) {
    el.innerHTML = '<div class="empty-state">Lỗi tải.</div>';
  }
}

function aiRenderConfig(cfg) {
  const el = $('ai-config');
  if (!el) return;
  const sw = (key, label, val) =>
    `<label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px dashed var(--border-soft);font-size:13px;cursor:pointer">${label}`
    + `<input type="checkbox" class="nt-switch" ${val ? 'checked' : ''} onchange="aiConfigSave('${key}', this.checked ? '1' : '0')"></label>`;
  el.innerHTML =
    sw('ai_oc_autostart', 'OpenCode Auto Start', cfg.autostart)
    + sw('ai_qa_enabled', 'Hỏi đáp project (read-only)', cfg.qa)
    + sw('ai_plan_enabled', 'Lập phương án', cfg.plan)
    + sw('ai_dev_enabled', 'Dev Jobs', cfg.dev)
    + sw('ai_auto_tests', 'Tự chạy tests', cfg.auto_tests)
    + sw('ai_auto_apply', 'Tự áp dụng (nguy hiểm)', cfg.auto_apply)
    + sw('ai_require_admin_apply', 'Bắt duyệt bởi ADMIN', cfg.require_admin_apply)
    + `<p class="hint" style="margin-top:8px">Server luôn local-only 127.0.0.1 + Basic auth. Auto Apply mặc định TẮT.</p>`;
}
async function aiConfigSave(key, val) {
  const r = await sendJson(api + 'aidev.php?action=config_save', { [key]: val });
  toast(r.ok ? '✓ Đã lưu' : 'Lỗi lưu', r.ok ? 'success' : 'error');
}

function aiRenderProject(d) {
  const el = $('ai-project');
  if (!el) return;
  const p = d.project;
  el.innerHTML = p
    ? `<div class="meta-row"><span class="meta-label">Project</span><span class="meta-value">${escapeHtml(p.name)}</span></div>`
      + `<div class="meta-row"><span class="meta-label">Root</span><span class="meta-value mono"><small>${escapeHtml(p.root_path)}</small></span></div>`
      + `<div class="meta-row"><span class="meta-label">Rules</span><span class="meta-value">DEV_RULES.md</span></div>`
    : '<div class="empty-state">Chưa có project.</div>';
}

// ---- Drawer ----
function aiDrawerOpen(code) {
  aiDrawerCode = code;
  $('ai-drawer').classList.remove('hidden');
  aiDrawerLoad();
  if (aiDrawerTimer) clearInterval(aiDrawerTimer);
  aiDrawerTimer = setInterval(() => {
    if ($('ai-drawer').classList.contains('hidden')) { clearInterval(aiDrawerTimer); return; }
    aiDrawerLoad(true);
  }, 5000);
}
function aiDrawerClose() {
  $('ai-drawer').classList.add('hidden');
  aiDrawerCode = null;
  if (aiDrawerTimer) clearInterval(aiDrawerTimer);
}
async function aiDrawerLoad(quiet) {
  if (!aiDrawerCode) return;
  try {
    const r = await getJson(api + 'aidev.php?action=job_detail&code=' + encodeURIComponent(aiDrawerCode));
    if (!r.ok) { if (!quiet) toast(r.message || 'Lỗi', 'error'); return; }
    const j = r.data;
    $('ai-drawer-title').textContent = j.job_code;
    const kv = (k, v) => `<div class="meta-row"><span class="meta-label">${k}</span><span class="meta-value">${v}</span></div>`;
    const files = (j.diff_files || []).slice(0, 20).map(f => `<div class="mono"><small>${escapeHtml(f)}</small></div>`).join('');
    const risky = (j.has_db_migration || j.has_dependency_change || (j.high_risk_flags || '').trim() !== '');
    $('ai-drawer-body').innerHTML =
      kv('Trạng thái', `<span class="badge badge-${AI_JOB_BADGE[j.status] || 'muted'}">${escapeHtml(j.status)}</span>`)
      + kv('Yêu cầu', escapeHtml((j.request || '').slice(0, 300)))
      + (j.plan_text ? kv('Phương án', `<small>${escapeHtml(j.plan_text.slice(0, 300))}…</small>`) : '')
      + kv('Branch', `<span class="mono"><small>${escapeHtml(j.work_branch || '—')}</small></span>`)
      + kv('Session', `<span class="mono"><small>${escapeHtml(j.opencode_session_id || '—')}</small></span>`)
      + kv('Thay đổi', escapeHtml(`${j.files_changed || 0} files · +${j.lines_added || 0} −${j.lines_removed || 0}`))
      + kv('Tests', escapeHtml(j.test_status || '—'))
      + (j.ai_session ? kv('Model/Cost', escapeHtml((j.ai_session.model || '?')
        + ' · tok ' + (j.ai_session.tokens_input || 0) + '/' + (j.ai_session.tokens_output || 0)
        + ' · $' + (j.ai_session.cost || 0))) : '')
      + (j.test_report ? `<div class="mono"><small>${escapeHtml(j.test_report.slice(0, 400))}</small></div>` : '')
      + (risky ? `<div class="eval-prev">⚠ Rủi ro cao${j.has_db_migration ? ' · DB migration' : ''}${j.has_dependency_change ? ' · deps' : ''}${j.high_risk_flags ? ' · ' + escapeHtml(j.high_risk_flags) : ''}</div>` : '')
      + (files ? `<div class="cc-dsec">Files</div>` + files : '')
      + (j.summary ? `<div class="cc-dsec">Tóm tắt AI</div><div><small>${escapeHtml(j.summary.slice(0, 800))}</small></div>` : '')
      + (j.error ? `<div class="eval-prev">⚠ ${escapeHtml(j.error)}</div>` : '')
      + `<div class="cc-drawer-act">`
      + (['CODING', 'TESTING', 'ANALYZING'].includes(j.status) ? `<button type="button" class="btn btn-sm" onclick="aiDrawerLoad()">Cập nhật tiến độ</button>` : '')
      + (j.status === 'REVIEW_READY' ? `<button type="button" class="btn btn-sm btn-primary" onclick="aiApprove('${escapeHtml(j.job_code)}',false)">Duyệt</button>` : '')
      + (j.status === 'APPROVED' ? `<button type="button" class="btn btn-sm btn-primary" onclick="aiApply('${escapeHtml(j.job_code)}')">Áp dụng</button>` : '')
      + (j.status === 'APPLIED' ? `<button type="button" class="btn btn-sm" onclick="aiRollback('${escapeHtml(j.job_code)}',false)">Rollback</button>` : '')
      + (['QUEUED', 'REVIEW_READY'].includes(j.status) ? `<button type="button" class="btn btn-sm" onclick="aiTests('${escapeHtml(j.job_code)}')">Chạy tests</button>` : '')
      + (!['APPLIED', 'REJECTED', 'CANCELLED'].includes(j.status) ? `<button type="button" class="btn btn-sm btn-danger" onclick="aiReject('${escapeHtml(j.job_code)}')">Từ chối</button>` : '')
      + `</div>`;
  } catch (e) {}
}
async function aiApprove(code, confirmed2) {
  const r = await sendJson(api + 'aidev.php?action=job_approve', { code, confirmed2: confirmed2 ? 1 : 0 });
  if (!r.ok && r.data && r.data.need === 'CONFIRM2') {
    if (confirm('Thay đổi RỦI RO CAO (DB/auth/xóa/deps). Tiếp tục duyệt?')) aiApprove(code, true);
    return;
  }
  toast(r.ok ? 'Đã duyệt.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  aiDrawerLoad();
  aiRefresh();
}
async function aiReject(code) {
  if (!confirm('Từ chối ' + code + '? Main không đổi, worktree bị dọn.')) return;
  const r = await sendJson(api + 'aidev.php?action=job_reject', { code });
  toast(r.ok ? 'Đã từ chối.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  aiDrawerLoad();
  aiRefresh();
}
async function aiApply(code) {
  if (!confirm('Áp dụng ' + code + ' vào main?')) return;
  const r = await sendJson(api + 'aidev.php?action=job_apply', { code });
  toast(r.ok ? 'Applied: ' + (r.data.commit || '') : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  aiDrawerLoad();
  aiRefresh();
}
async function aiRollback(code, confirmed) {
  if (!confirmed && !confirm('Rollback ' + code + '?')) return;
  const r = await sendJson(api + 'aidev.php?action=job_rollback', { code, confirmed: confirmed ? 1 : 0 });
  if (!r.ok && r.data && r.data.need === 'CONFIRM') {
    if (confirm('Job có DB migration — rollback code KHÔNG rollback DB. Tiếp tục?')) aiRollback(code, true);
    return;
  }
  toast(r.ok ? 'Đã rollback.' : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  aiDrawerLoad();
  aiRefresh();
}
async function aiTests(code) {
  toast('Đang chạy tests...', '');
  const r = await sendJson(api + 'aidev.php?action=job_tests', { code });
  const rep = r.data && r.data.report;
  toast(r.ok ? 'Tests PASS.' : ('Tests FAIL' + (rep && rep.details ? ': ' + rep.details.slice(0, 3).join(', ') : '')), r.ok ? 'success' : 'error');
  aiDrawerLoad();
  aiRefresh();
}
