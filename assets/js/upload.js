// ============ YOUTUBE UPLOAD MANAGER V1 ============
let upCurTab = 'today';
let upCfgId = 0;
function upInit() { upRefresh(); }
async function upRefresh() {
  try {
    const k = await getJson(api + 'upload.php?action=kpis');
    if (k.ok) upPaintKpis(k.data);
  } catch (e) {}
  upTab(upCurTab);
}
function upPaintKpis(k) {
  const card = (l, v, sub) => `<div class="nt-kpi-card"><div class="nt-kpi-label">${l}</div><div class="nt-kpi-value">${v}</div><div class="nt-kpi-sub">${sub || ''}</div></div>`;
  $('up-kpis').innerHTML =
    card('Kênh bật', k.channels, '') + card('Queue ready', k.ready, '') +
    card('Đang upload', k.uploading, '') + card('Đã schedule', k.scheduled, '') +
    card('Đã đăng hôm nay', k.published_today, '') + card('Lỗi / Queue thấp', k.failed + ' / ' + k.low, '');
  try {
    const st = async () => {
      const h = await getJson(api + 'upload.php?action=health');
      if (h.ok && !h.data.provider.youtube_api_ready) {
        $('up-provider-note').textContent = 'Đang dùng SIMULATED provider (chưa cấu hình OAuth YouTube) — upload/schedule được mô phỏng đầy đủ luồng, sẵn sàng chuyển API thật khi có credentials.';
      } else if (h.ok) {
        $('up-provider-note').textContent = 'YouTube API sẵn sàng (đã có credentials VALID).';
      }
    };
    st();
  } catch (e) {}
  getJson(api + 'upload.php?action=uploads&per=100').then(r => {
    if (!r.ok) return;
    const c = { QUEUED: 0, UPLOADING: 0, SCHEDULED: 0, PUBLISHED: 0, FAILED: 0 };
    (r.data.rows || []).forEach(x => { const s = x.status; if (c[s] !== undefined) c[s]++; });
    $('up-pipe').innerHTML = Object.keys(c).map(k2 =>
      `<span class="cc-count ${k2 === 'FAILED' ? 'err' : (k2 === 'PUBLISHED' ? 'ok' : '')}">${k2}: ${c[k2]}</span>`).join('');
  }).catch(() => {});
}
function upTab(t) {
  upCurTab = t;
  document.querySelectorAll('#up-tabs [data-uptab]').forEach(b =>
    b.classList.toggle('btn-primary', b.dataset.uptab === t));
  ({ today: upToday, channels: upChannels, library: upLibrary, schedule: upSchedule,
    uploads: upUploads, templates: upTemplates, health: upHealth })[t]();
}
function upFmtSize(b) {
  b = Number(b) || 0;
  if (b >= 1073741824) return (b / 1073741824).toFixed(1) + 'GB';
  if (b >= 1048576) return (b / 1048576).toFixed(0) + 'MB';
  return Math.max(1, Math.round(b / 1024)) + 'KB';
}
function upStBadge(s) {
  const m = { READY: 'ok', NEW: 'muted', ASSIGNED: 'info', QUEUED: 'info', RESERVED: 'info',
    UPLOADING: 'info', PROCESSING: 'info', SCHEDULING: 'info', UPLOADED: 'info',
    SCHEDULED: 'info', PLANNED: 'muted', PUBLISHED: 'ok', SUCCESS: 'ok', DONE: 'ok',
    FAILED: 'err', SKIPPED: 'muted', RETRYING: 'warn', CANCELLED: 'muted', MISSING_FILE: 'err' };
  return `<span class="badge badge-${m[s] || 'muted'}">${escapeHtml(s || '')}</span>`;
}
// ---- Hôm nay ----
async function upToday() {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  try {
    const r = await getJson(api + 'upload.php?action=today');
    if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi tải.</p>'; return; }
    el.innerHTML = r.data.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Giờ</th><th>Kênh</th><th>Video</th><th>Trạng thái</th></tr></thead><tbody>`
      + r.data.map(s => `<tr><td class="mono">${escapeHtml(String(s.publish_at || '').slice(11, 16))}</td><td>${escapeHtml(s.channel_name || ('#' + s.profile_id))}</td><td>${escapeHtml(s.filename || ('asset#' + (s.video_asset_id || '')) || '—')}</td><td>${upStBadge(s.status)}</td></tr>`).join('')
      + `</tbody></table></div>` : '<p class="muted">Hôm nay chưa có lịch đăng.</p>';
  } catch (e) { el.innerHTML = '<p class="muted">Lỗi tải.</p>'; }
}
// ---- Kênh ----
async function upChannels() {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  const list = (profiles || []).map(p => p.id);
  const settled = await Promise.all(list.map(id =>
    getJson(api + `upload.php?action=queue&id=${id}`)
      .then(r => ({ id, ok: r.ok, data: r.data }))
      .catch(() => ({ id, ok: false }))));
  const rows = settled.filter(x => x.ok).map(x => ({
    id: x.id,
    name: (profiles.find(p => Number(p.id) === Number(x.id)) || {}).name || ('#' + x.id),
    ...x.data }));
  const cfgd = rows.filter(x => x.config && x.config.upload_enabled);
  el.innerHTML = `<div class="hint">Đã cấu hình: ${cfgd.length}/${rows.length} kênh · <button type="button" class="btn btn-xs" onclick="upOpenBulk()">Gán lịch hàng loạt</button></div>`
    + `<div class="table-wrap"><table class="data-table"><thead><tr><th>Kênh</th><th>Bật</th><th>Queue</th><th>Lịch/ngày</th><th>Tiếp theo</th><th></th></tr></thead><tbody>`
    + rows.map(x => {
      const c = x.config || {};
      const q = x.counts || {};
      return `<tr><td><strong>${escapeHtml(x.name)}</strong></td>`
        + `<td>${c.upload_enabled ? '●' : '○'}</td>`
        + `<td>${q.ready || 0} ready${(q.reserved || 0) ? ' · ' + q.reserved + ' reserved' : ''}</td>`
        + `<td>${c.daily_enabled ? (c.videos_per_day + '/ngày ' + (c.publish_slots || []).join(',')) : '—'}</td>`
        + `<td class="mono"><small>${escapeHtml(c.next_run_at || '')}</small></td>`
        + `<td style="white-space:nowrap"><button type="button" class="btn btn-xs" onclick="upQueue(${x.id})">Queue</button> `
        + `<button type="button" class="btn btn-xs" onclick="upCfgOpen(${x.id})">Sửa</button> `
        + `<button type="button" class="btn btn-xs" onclick="upDry(${x.id})">Thử lịch</button></td></tr>`;
    }).join('') + `</tbody></table></div>`;
}
async function upQueue(id) {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  const r = await getJson(api + `upload.php?action=queue&id=${id}&per=50`);
  if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi.</p>'; return; }
  const d = r.data;
  el.innerHTML = `<div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;align-items:center">
    <button type="button" class="btn btn-xs" onclick="upTab('channels')">← Kênh</button>
    <strong>Queue #${id}</strong><span class="muted">ready ${d.counts.ready}</span><span class="spacer"></span>
    <button type="button" class="btn btn-xs" onclick="upRunOnce(${id})">Upload ngay 1 video</button>
    <button type="button" class="btn btn-xs" onclick="upRetry(${id})">Retry lỗi</button>
    <button type="button" class="btn btn-xs" onclick="upPause(${id})">Tạm dừng</button></div>`
    + `<div class="table-wrap"><table class="data-table"><thead><tr><th><input type="checkbox" onchange="upCheckAll(this.checked)"></th><th>#</th><th>File</th><th>Size</th><th>Trạng thái</th><th></th></tr></thead><tbody>`
    + (d.list.rows || []).map((q, i) => `<tr><td><input type="checkbox" data-qid="${q.id}"></td><td>${q.queue_position}</td><td>${escapeHtml(q.filename || '')}</td><td>${upFmtSize(q.size_bytes)}</td><td>${upStBadge(q.status)}</td>`
      + `<td style="white-space:nowrap">${i > 0 ? `<button type="button" class="btn btn-xs" onclick="upMove(${id},${q.id},-1)">↑</button>` : ''}<button type="button" class="btn btn-xs" onclick="upMove(${id},${q.id},1)">↓</button></td></tr>`).join('')
    + `</tbody></table></div>`
    + `<div style="display:flex;gap:6px;margin-top:6px"><button type="button" class="btn btn-xs" onclick="upQRemove(${id})">Xóa đã chọn</button>`
    + `<button type="button" class="btn btn-xs" onclick="upQSkip(${id})">Bỏ qua đã chọn</button>`
    + `<button type="button" class="btn btn-xs" onclick="upAssignOpen(${id})">+ Gán video từ thư viện</button></div>`
    + `<div id="up-assign-box" style="margin-top:8px"></div>`;
}
function upCheckedQids() {
  return [...document.querySelectorAll('#up-body input[type=checkbox][data-qid]:checked')].map(cb => Number(cb.dataset.qid));
}
function upCheckAll(on) {
  document.querySelectorAll('#up-body input[type=checkbox][data-qid]').forEach(cb => { cb.checked = on; });
}
async function upMove(id, qid, dir) {
  const r = await getJson(api + `upload.php?action=queue&id=${id}&per=100`);
  if (!r.ok) return;
  const ids = (r.data.list.rows || []).map(x => x.id);
  const i = ids.indexOf(qid);
  const j = i + dir;
  if (i < 0 || j < 0 || j >= ids.length) return;
  [ids[i], ids[j]] = [ids[j], ids[i]];
  await sendJson(api + 'upload.php?action=queue_reorder', { id, queue_ids: ids });
  upQueue(id);
}
async function upQRemove(id) {
  const q = upCheckedQids();
  if (!q.length) { toast('Chưa chọn', 'error'); return; }
  await sendJson(api + 'upload.php?action=queue_remove', { id, queue_ids: q });
  upQueue(id);
}
async function upQSkip(id) {
  const q = upCheckedQids();
  if (!q.length) { toast('Chưa chọn', 'error'); return; }
  await sendJson(api + 'upload.php?action=queue_skip', { id, queue_ids: q });
  upQueue(id);
}
async function upAssignOpen(id) {
  const box = $('up-assign-box');
  box.innerHTML = '<p class="muted">Đang tải thư viện...</p>';
  const r = await getJson(api + 'upload.php?action=library&per=50');
  if (!r.ok) { box.innerHTML = '<p class="muted">Lỗi.</p>'; return; }
  box.innerHTML = `<div class="table-wrap" style="max-height:240px;overflow:auto"><table class="data-table"><thead><tr><th></th><th>File</th><th>Size</th></tr></thead><tbody>`
    + (r.data.rows || []).map(a => `<tr><td><input type="checkbox" data-aid="${a.id}"></td><td>${escapeHtml(a.filename)}</td><td>${upFmtSize(a.size_bytes)}</td></tr>`).join('')
    + `</tbody></table></div><button type="button" class="btn btn-xs" style="margin-top:6px" onclick="upAssignDo(${id})">Gán đã chọn vào queue</button>`;
}
async function upAssignDo(id) {
  const aids = [...document.querySelectorAll('#up-assign-box input[data-aid]:checked')].map(cb => Number(cb.dataset.aid));
  if (!aids.length) { toast('Chưa chọn video', 'error'); return; }
  const r = await sendJson(api + 'upload.php?action=queue_assign', { id, asset_ids: aids });
  toast(r.ok ? `Đã gán ${r.data.added} video` : 'Lỗi', r.ok ? 'success' : 'error');
  upQueue(id);
}
async function upRunOnce(id) {
  const r = await sendJson(api + 'upload.php?action=run_once', { id });
  toast(r.ok ? `Đã tạo job ${r.data.job_id}` : (r.message || 'Lỗi'), r.ok ? 'success' : 'error');
  if (r.ok) upTab('uploads');
}
async function upRetry(id) {
  const r = await sendJson(api + 'upload.php?action=retry_failed', { id });
  toast(r.ok ? 'Đã đưa lỗi vào retry' : 'Lỗi', r.ok ? 'success' : 'error');
}
async function upPause(id) {
  const r = await sendJson(api + 'upload.php?action=pause', { id, mode: '1d' });
  toast(r.ok ? 'Đã tạm dừng 1 ngày' : 'Lỗi', r.ok ? 'success' : 'error');
}
async function upDry(id) {
  const r = await getJson(api + `upload.php?action=dryrun&id=${id}`);
  if (!r.ok) { toast('Lỗi', 'error'); return; }
  const d = r.data;
  toast(`Tiếp: ${d.next_file || '—'} · Upload dự kiến ${d.upload_planned || '—'} · Đăng ${d.publish || '—'}`, 'success');
}
// ---- Config modal ----
async function upCfgOpen(id) {
  upCfgId = id;
  const [c, t] = await Promise.all([
    getJson(api + `upload.php?action=config&id=${id}`),
    getJson(api + 'upload.php?action=templates'),
  ]);
  const cfg = (c.ok && c.data) || {};
  const tpls = (t.ok && t.data) || [];
  $('up-cfg-title').textContent = 'Cấu hình upload #' + id;
  $('up-cfg-body').innerHTML = `
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="uc-enabled" style="width:auto" ${cfg.upload_enabled ? 'checked' : ''}> Bật upload</label>
    <div class="win-row" style="margin-top:6px">
      <div class="win-field"><label>Nguồn nội dung</label><select id="uc-source">
        ${['SHARED_LIBRARY', 'DEDICATED_FOLDER', 'CUSTOM_QUEUE'].map(v => `<option value="${v}" ${cfg.content_source === v ? 'selected' : ''}>${v === 'SHARED_LIBRARY' ? 'Thư viện chung' : (v === 'DEDICATED_FOLDER' ? 'Folder riêng' : 'Hàng chờ riêng')}</option>`).join('')}
      </select></div>
      <div class="win-field"><label>Provider</label><select id="uc-provider">
        <option value="SIMULATED" ${cfg.upload_provider !== 'YOUTUBE_API' ? 'selected' : ''}>Simulated (mặc định)</option>
        <option value="YOUTUBE_API" ${cfg.upload_provider === 'YOUTUBE_API' ? 'selected' : ''}>YouTube API (cần OAuth)</option>
      </select></div>
    </div>
    <div class="win-field"><label>Folder riêng (nếu dùng)</label><input type="text" id="uc-folder" style="width:100%" value="${escapeHtml(cfg.folder_path || '')}" placeholder="D:\\Videos\\Channel05"></div>
    <div class="win-row" style="margin-top:6px">
      <div class="win-field"><label>Thứ tự queue</label><select id="uc-qmode">
        ${['MANUAL', 'FILENAME_ASC', 'CREATED_TIME', 'RANDOM'].map(v => `<option ${cfg.queue_mode === v ? 'selected' : ''}>${v}</option>`).join('')}
      </select></div>
      <div class="win-field"><label>Đăng mỗi ngày</label><input type="number" id="uc-vpd" min="1" max="10" value="${cfg.videos_per_day || 1}"></div>
    </div>
    <label style="display:flex;gap:8px;align-items:center;margin-top:6px"><input type="checkbox" id="uc-daily" style="width:auto" ${cfg.daily_enabled ? 'checked' : ''}> Tự đăng hàng ngày</label>
    <div class="win-row" style="margin-top:6px">
      <div class="win-field"><label>Giờ đăng (mỗi dòng 1 giờ, vd 11:30,19:30)</label><input type="text" id="uc-slots" style="width:100%" value="${escapeHtml((cfg.publish_slots || []).join(','))}"></div>
      <div class="win-field"><label>Ngày trong tuần (1-7)</label><input type="text" id="uc-weekdays" style="width:100%" value="${escapeHtml(cfg.weekdays || '1,2,3,4,5,6,7')}"></div>
    </div>
    <div class="win-row" style="margin-top:6px">
      <div class="win-field"><label>Upload trước (phút)</label><input type="number" id="uc-lead" min="15" max="2880" value="${cfg.pre_upload_minutes || 180}"></div>
      <div class="win-field"><label>Hiển thị</label><select id="uc-vis">
        ${['SCHEDULED', 'PRIVATE', 'UNLISTED', 'PUBLIC'].map(v => `<option ${cfg.visibility === v ? 'selected' : ''}>${v}</option>`).join('')}
      </select></div>
    </div>
    <div class="win-row" style="margin-top:6px">
      <div class="win-field"><label>Metadata template</label><select id="uc-tpl">
        <option value="">—</option>${tpls.map(x => `<option value="${x.id}" ${Number(cfg.metadata_template_id) === Number(x.id) ? 'selected' : ''}>${escapeHtml(x.name)}</option>`).join('')}
      </select></div>
      <div class="win-field"><label>Ngưỡng queue thấp</label><input type="number" id="uc-low" min="0" max="50" value="${cfg.queue_low_threshold ?? 3}"></div>
    </div>
    <details style="margin-top:8px"><summary style="cursor:pointer;font-weight:600">Nâng cao + OAuth</summary>
      <div class="win-row" style="margin-top:6px">
        <div class="win-field"><label>Thử lại tối đa</label><input type="number" id="uc-maxatt" min="1" max="10" value="${cfg.max_attempts || 4}"></div>
        <div class="win-field"><label>Video lỗi thay thế</label><select id="uc-repl">
          ${['AUTO_REPLACE', 'ASK', 'SKIP_SLOT'].map(v => `<option ${cfg.replacement_policy === v ? 'selected' : ''}>${v}</option>`).join('')}
        </select></div>
      </div>
      <div class="win-row" style="margin-top:6px">
        <div class="win-field"><label>Trễ deadline (phút)</label><input type="number" id="uc-deadline" min="0" max="720" value="${cfg.deadline_minutes ?? 30}"></div>
        <div class="win-field"><label>Trượt slot</label><select id="uc-missed">
          ${['SKIP', 'LATE'].map(v => `<option ${cfg.missed_policy === v ? 'selected' : ''}>${v}</option>`).join('')}
        </select></div>
      </div>
      <div class="win-field" style="margin-top:6px"><label>YouTube channel ID (OAuth)</label><input type="text" id="uc-ytcid" style="width:100%" placeholder="UC..."></div>
      <div class="win-row" style="margin-top:6px">
        <div class="win-field"><label>OAuth Client ID</label><input type="text" id="uc-client-id" style="width:100%" placeholder="...apps.googleusercontent.com"></div>
        <div class="win-field"><label>Client Secret</label><input type="password" id="uc-client-secret" style="width:100%"></div>
      </div>
      <div class="win-field"><label>OAuth refresh token</label><input type="password" id="uc-refresh" style="width:100%" placeholder="chỉ nhập khi cấu hình/làm mới"></div>
      <div class="hint">Client ID/Secret + refresh token chỉ nhập khi cấu hình; token không bao giờ hiển thị lại. Chưa có → dùng SIMULATED.</div>
    </details>`;
  $('up-cfg-save').textContent = 'Lưu';
  showModal('up-cfg-modal');
}
async function upCfgSave() {
  const id = upCfgId;
  const body = {
    id, upload_enabled: $('uc-enabled').checked ? 1 : 0,
    content_source: $('uc-source').value, upload_provider: $('uc-provider').value,
    folder_path: $('uc-folder').value, queue_mode: $('uc-qmode').value,
    videos_per_day: Number($('uc-vpd').value) || 1,
    daily_enabled: $('uc-daily').checked ? 1 : 0,
    publish_slots: $('uc-slots').value.split(',').map(s => s.trim()).filter(Boolean),
    weekdays: $('uc-weekdays').value, pre_upload_minutes: Number($('uc-lead').value) || 180,
    visibility: $('uc-vis').value,
    metadata_template_id: $('uc-tpl').value ? Number($('uc-tpl').value) : null,
    queue_low_threshold: Number($('uc-low').value) || 0,
    max_attempts: Number($('uc-maxatt').value) || 4, replacement_policy: $('uc-repl').value,
    deadline_minutes: Number($('uc-deadline').value) || 0, missed_policy: $('uc-missed').value,
  };
  const r = await sendJson(api + 'upload.php?action=config_save', body);
  if (!r.ok) { toast('Lỗi lưu', 'error'); return; }
  const rt = $('uc-refresh') ? $('uc-refresh').value.trim() : '';
  if (rt) {
    await sendJson(api + 'upload.php?action=credential', { id,
      youtube_channel_id: $('uc-ytcid') ? $('uc-ytcid').value.trim() : '',
      client_id: $('uc-client-id') ? $('uc-client-id').value.trim() : '',
      client_secret: $('uc-client-secret') ? $('uc-client-secret').value : '',
      refresh_token: rt });
    toast('Đã lưu config + credential', 'success');
  } else {
    toast('Đã lưu (v' + (r.data.config_version || '?') + ')', 'success');
  }
  closeModal('up-cfg-modal');
  upTab('channels');
}
async function upOpenBulk() {
  const ids = (typeof getSelectedIds === 'function' ? getSelectedIds() : []).map(Number).filter(x => x > 0);
  if (!ids.length) { toast('Chọn kênh ở trang Kênh trước (hoặc nhập IDs)', 'error'); return; }
  const slots = prompt('Giờ đăng (vd 19:30 hoặc 11:30,19:30):', '19:30');
  if (slots === null) return;
  const lead = prompt('Upload trước (phút):', '180');
  if (lead === null) return;
  const r = await sendJson(api + 'upload.php?action=config_bulk', { ids,
    patch: { upload_enabled: 1, daily_enabled: 1, publish_slots: slots.split(',').map(s => s.trim()).filter(Boolean), pre_upload_minutes: Number(lead) || 180 } });
  if (!r.ok) { toast('Lỗi', 'error'); return; }
  toast(`Áp dụng ${ids.length} kênh — mới ${r.data.created}, cập nhật ${r.data.updated}`, r.data.failed.length ? 'error' : 'success');
  upRefresh();
}
// ---- Thư viện ----
async function upLibrary(page) {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  const r = await getJson(api + `upload.php?action=library&page=${page || 1}&per=20`);
  if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi.</p>'; return; }
  const d = r.data;
  el.innerHTML = `<div class="table-wrap"><table class="data-table"><thead><tr><th>File</th><th>Size</th><th>Checksum</th><th>Trạng thái</th><th>Queue</th></tr></thead><tbody>`
    + (d.rows || []).map(a => `<tr><td>${escapeHtml(a.filename)}</td><td>${upFmtSize(a.size_bytes)}</td><td class="mono"><small>${escapeHtml(String(a.checksum || '').slice(0, 12))}</small></td><td>${upStBadge(a.status)}</td><td><small>${escapeHtml(a.queued_for || '—')}</small></td></tr>`).join('')
    + `</tbody></table></div><div class="hint">Trang ${d.page} · tổng ${d.total}</div>`;
}
async function upOpenImport() {
  const path = prompt('Đường dẫn folder chứa video (để trống để nhập CSV ở bước sau):', '');
  if (path === null) return;
  if (path.trim()) {
    const rec = confirm('Bao gồm thư mục con? (OK=có, Cancel=không)');
    const r = await sendJson(api + 'upload.php?action=import_folder', { path: path.trim(), recursive: rec });
    toast(r.ok ? `Quét ${r.data.scanned}: mới ${r.data.imported}, trùng ${r.data.duplicate}, bỏ qua ${r.data.skipped}` : 'Lỗi', r.ok ? 'success' : 'error');
    upTab('library');
    return;
  }
  const csv = prompt('Paste CSV (file,title,description,tags,channel,publish_date,publish_time):', '');
  if (!csv) return;
  const r = await sendJson(api + 'upload.php?action=import_csv', { text: csv });
  if (!r.ok) { toast('Lỗi', 'error'); return; }
  toast(`CSV: ${r.data.rows} dòng — hợp lệ ${r.data.valid}, thiếu file ${r.data.missing_files}, sai kênh ${r.data.invalid_channels}, trùng ${r.data.duplicate}`, 'success');
  upTab('library');
}
// ---- Lịch ----
async function upSchedule() {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  const r = await getJson(api + 'upload.php?action=upcoming');
  if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi.</p>'; return; }
  el.innerHTML = r.data.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Ngày giờ</th><th>Kênh</th><th>Video</th><th>Upload</th><th>Publish</th></tr></thead><tbody>`
    + r.data.map(s => `<tr><td class="mono">${escapeHtml(String(s.publish_at || '').slice(0, 16))}</td><td>${escapeHtml(s.channel_name || ('#' + s.profile_id))}</td><td>${escapeHtml(s.filename || ('asset#' + (s.video_asset_id || '')) || '—')}</td><td>${upStBadge(s.upload_status || '—')}</td><td>${upStBadge(s.status)}</td></tr>`).join('')
    + `</tbody></table></div>` : '<p class="muted">7 ngày tới chưa có lịch.</p>';
}
// ---- Lịch sử ----
async function upUploads(page) {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  const r = await getJson(api + `upload.php?action=uploads&page=${page || 1}&per=20`);
  if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi.</p>'; return; }
  const d = r.data;
  el.innerHTML = `<div style="margin-bottom:6px"><button type="button" class="btn btn-xs" onclick="upRetry(0)">Retry tất cả lỗi</button></div>`
    + `<div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Kênh</th><th>File</th><th>Tiến trình</th><th>Trạng thái</th><th>Lỗi</th></tr></thead><tbody>`
    + (d.rows || []).map(u => `<tr><td>${u.id}</td><td>#${u.profile_id}</td><td>${escapeHtml(u.filename || '')}<br><small class="mono muted">${escapeHtml(u.provider_video_id || '')}</small></td><td>${u.progress}%</td><td>${upStBadge(u.status)}</td><td><small>${escapeHtml(u.error_message || u.error_code || '')}</small></td></tr>`).join('')
    + `</tbody></table></div><div class="hint">Trang ${d.page} · tổng ${d.total}</div>`;
}
// ---- Mẫu ----
async function upTemplates() {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  const r = await getJson(api + 'upload.php?action=templates');
  if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi.</p>'; return; }
  el.innerHTML = `<div style="margin-bottom:6px"><button type="button" class="btn btn-xs" onclick="upTplEdit(0)">+ Mẫu mới</button></div>`
    + `<div class="table-wrap"><table class="data-table"><thead><tr><th>Tên</th><th>Title</th><th>Tags</th><th></th></tr></thead><tbody>`
    + (r.data || []).map(x => `<tr><td><strong>${escapeHtml(x.name)}</strong></td><td>${escapeHtml(x.title_template)}</td><td><small>${escapeHtml(x.tags || '')}</small></td><td><button type="button" class="btn btn-xs" onclick="upTplEdit(${x.id})">Sửa</button></td></tr>`).join('')
    + `</tbody></table></div><div class="hint">Biến: {filename} {channel_name} {date} {index}</div>`;
}
async function upTplEdit(id) {
  let cur = { name: '', title_template: '{filename}', description_template: '', tags: '', category_id: '22', visibility: 'SCHEDULED', playlist_id: '', language: 'vi' };
  if (id) {
    const r = await getJson(api + 'upload.php?action=templates');
    cur = ((r.ok && r.data) || []).find(x => Number(x.id) === Number(id)) || cur;
  }
  const name = prompt('Tên mẫu:', cur.name || '');
  if (name === null) return;
  const title = prompt('Title ({filename} {channel_name} {date} {index}):', cur.title_template || '{filename}');
  if (title === null) return;
  const r = await sendJson(api + 'upload.php?action=template_save', { id: id || null, name, title_template: title, description_template: cur.description_template, tags: cur.tags, category_id: cur.category_id, visibility: cur.visibility, playlist_id: cur.playlist_id, language: cur.language });
  toast(r.ok ? 'Đã lưu mẫu' : 'Lỗi', r.ok ? 'success' : 'error');
  upTemplates();
}
// ---- Sức khỏe ----
async function upHealth() {
  const el = $('up-body');
  el.innerHTML = '<div class="skeleton"></div>';
  const r = await getJson(api + 'upload.php?action=health');
  if (!r.ok) { el.innerHTML = '<p class="muted">Lỗi.</p>'; return; }
  const h = r.data;
  const row = (l, v, ok) => `<div class="meta-row"><span class="meta-label">${l}</span><span class="meta-value">${ok === true ? '● ' : (ok === false ? '✕ ' : '')}${v}</span></div>`;
  el.innerHTML = `<div class="cc-dsec">Upload Storage</div>`
    + row('Trạng thái', h.storage.state, h.storage.state === 'HEALTHY')
    + row('Folder', `ok ${h.storage.folders_ok} · lỗi ${h.storage.folders_bad}`, h.storage.folders_bad === 0)
    + row('File mất', h.storage.missing_files, h.storage.missing_files === 0)
    + row('Ổ đĩa trống', (h.storage.disk_free_gb ?? '?') + 'GB', true)
    + `<div class="cc-dsec">YouTube Auth</div>`
    + row('Valid', h.auth.valid, true) + row('Cần re-auth', h.auth.reauth, h.auth.reauth === 0)
    + `<div class="cc-dsec">Worker</div>`
    + row('Job worker', h.worker.job_worker ? 'RUNNING' : 'STOPPED', h.worker.job_worker)
    + row('Scheduler daemon', h.worker.scheduler ? 'RUNNING' : 'STOPPED', h.worker.scheduler)
    + `<div class="cc-dsec">Provider</div>`
    + row('YouTube API', h.provider.youtube_api_ready ? 'READY (đã có OAuth VALID)' : 'CHƯA CẤU HÌNH (dùng SIMULATED)', h.provider.youtube_api_ready);
}
