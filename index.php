<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YT Manager - Quản lý kênh đa proxy</title>
<link rel="icon" href="data:,">
  <link rel="stylesheet" href="assets/css/style.css?v=20260917q">
</head>
<body>

<div class="app">
  <!-- ===== SIDEBAR ===== -->
  <aside class="sidebar" id="sidebar">
    <div class="side-brand">
      <div class="logo">YT</div>
      <div class="side-brand-text">
        <span class="side-brand-title">YT Manager</span>
        <span class="side-brand-sub">Multi-Channel</span>
      </div>
    </div>

    <nav class="side-nav">
      <div class="nav-label">Quản lý</div>
      <button class="nav-btn active" data-view="dashboard">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        <span>Tổng quan</span>
      </button>
      <button class="nav-btn" data-view="profiles">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        <span>Kênh</span>
        <span id="nav-profile-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="proxies">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 12c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6-1.8C18 6.57 15.35 4 12 4s-6 2.57-6 6.2c0 2.34 1.95 5.44 6 9.14 4.05-3.7 6-6.8 6-9.14zM12 2c4.2 0 8 3.22 8 8.2 0 3.32-2.67 7.25-8 11.8-5.33-4.55-8-8.48-8-11.8C4 5.22 7.8 2 12 2z"/></svg>
        <span>Proxy</span>
        <span id="nav-proxy-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="synchronize">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M8 11H5v2h3v3h2v-3h3v-2h-3V8H8v3zm8-8H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 14H4V5h12v12zm4-13v9h-2V6l-2 2V5l3.5-3L22 5v3l-2-2v7h-2z"/></svg>
        <span>Synchronize</span>
      </button>
      <button class="nav-btn" data-view="logs">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
        <span>Nhật ký</span>
      </button>
    </nav>

    <div class="side-footer">
      <button class="nav-btn" data-view="settings">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
        <span>Cài đặt</span>
      </button>
      <div class="side-version">v1.0 · Hidemium-style</div>
    </div>
  </aside>

  <!-- ===== MAIN ===== -->
  <main class="main">
    <header class="topbar">
      <h2 id="view-title">Tổng quan</h2>
      <div class="topbar-right">
        <button class="btn btn-sm btn-icon" id="btn-menu" title="Mở menu">
          <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
        </button>
        <button class="btn btn-sm" id="btn-refresh" title="Làm mới dữ liệu">
          <span class="refresh-spin">⟳</span> Làm mới
        </button>
        <button class="btn btn-sm btn-icon" id="btn-theme" title="Đổi nền sáng/tối">🌙</button>
        <span id="server-status" class="badge badge-warn">Đang kiểm tra...</span>
      </div>
    </header>

    <div class="content">

      <!-- ===== VIEW: DASHBOARD ===== -->
      <section id="view-dashboard" class="view active">
        <div class="stat-grid" id="stat-grid">
          <div class="stat-card"><div class="stat-value" id="stat-profiles">-</div><div class="stat-label">Tổng kênh</div></div>
          <div class="stat-card"><div class="stat-value green" id="stat-running">-</div><div class="stat-label">Đang chạy</div></div>
          <div class="stat-card"><div class="stat-value" id="stat-proxies">-</div><div class="stat-label">Proxy</div></div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Hoạt động gần đây</h3><button class="btn btn-sm" onclick="switchView('logs')">Xem tất cả</button></div>
          <table class="data-table">
            <thead><tr><th>Thời gian</th><th>Kênh</th><th>Hành động</th><th>Chi tiết</th></tr></thead>
            <tbody id="dash-logs-tbody"></tbody>
          </table>
        </div>
      </section>

      <!-- ===== VIEW: PROFILES ===== -->
      <section id="view-profiles" class="view">
        <div class="toolbar">
          <button class="btn btn-primary" onclick="openProfileModal()">
            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Tạo kênh
          </button>
          <button class="btn" onclick="openAllProfiles()">Mở tất cả</button>
          <button class="btn" onclick="closeAllProfiles()">Đóng tất cả</button>
          <div class="sel-divider"></div>
          <label class="sel-all-label"><span class="ck"><input type="checkbox" id="sel-all" onchange="toggleSelectAll(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span> Chọn tất cả</label>
          <button class="btn btn-sm" onclick="openSelected()">▶ Mở đã chọn</button>
          <button class="btn btn-sm" onclick="closeSelected()">■ Đóng đã chọn</button>
          <button class="btn btn-sm" onclick="assignProxySelected()">⇄ Gán proxy</button>
          <button class="btn btn-sm" onclick="evaluateSelected()" title="Đánh giá account đã chọn">✓ Đánh giá</button>
          <button class="btn btn-sm" onclick="refreshAll(true)" title="Tải lại dữ liệu mới nhất">↻ Làm mới</button>
          <button class="btn btn-sm btn-danger" onclick="deleteSelected()">🗑 Xóa đã chọn</button>
          <div class="dropdown">
            <button class="btn btn-sm" id="arrange-btn" onclick="toggleArrangeMenu(event)">🧩 Sắp xếp ▾</button>
            <div class="dropdown-menu hidden" id="arrange-menu">
              <button onclick="arrangeSmart()">✨ Sắp xếp thông minh</button>
              <button onclick="arrangeSelected()">☑ Xếp kênh đã chọn</button>
              <button onclick="arrangeRunning()">▶ Xếp kênh đang chạy</button>
              <div class="dropdown-sep"></div>
              <button onclick="arrangeWithMode('grid')">▦ Lưới (Grid)</button>
              <button onclick="arrangeWithMode('horizontal')">↔ Hàng ngang</button>
              <button onclick="arrangeWithMode('vertical')">↕ Hàng dọc</button>
              <button onclick="arrangeWithMode('cascade')">🗂 Xếp tầng (Cascade)</button>
              <button onclick="arrangeWithMode('compact')">🗄 Xếp chồng (Compact)</button>
              <div class="dropdown-sep"></div>
              <button onclick="arrangeToMonitorMenu(event)">🖥 Xếp sang màn hình ▸</button>
              <div class="dropdown-sep"></div>
              <button onclick="arrangePreview()">🔍 Xem trước…</button>
              <button onclick="gotoLayoutSettings()">⚙ Cài đặt sắp xếp…</button>
            </div>
            <div class="dropdown-menu flyout hidden" id="arrange-mon-menu"></div>
          </div>
          <span id="selected-count" class="sel-count"></span>
          <span id="arrange-result" class="sel-count"></span>
          <div class="spacer"></div>
          <select id="profile-filter-platform" class="filter-select" onchange="reloadProfilesView()">
            <option value="">Tất cả nền tảng</option>
            <option value="youtube">YouTube</option>
            <option value="tiktok">TikTok</option>
            <option value="facebook">Facebook</option>
            <option value="other">Khác</option>
          </select>
          <select id="profile-filter-stage" class="filter-select" onchange="reloadProfilesView()" title="Lọc theo giai đoạn account">
            <option value="">Mọi giai đoạn</option>
            <option value="NEW">Mới</option>
            <option value="OBSERVING">Theo dõi</option>
            <option value="STABLE">Ổn định</option>
            <option value="READY_FOR_CHANNEL">Sẵn sàng</option>
            <option value="CHANNEL_EXISTS">Có kênh</option>
            <option value="REVIEW_REQUIRED">Cần xem</option>
            <option value="ACTION_REQUIRED">Cần xử lý</option>
            <option value="UNAVAILABLE">Mất kết nối</option>
          </select>
          <select id="profile-filter-channel" class="filter-select" onchange="reloadProfilesView()" title="Lọc theo kênh YouTube">
            <option value="">Mọi kênh YT</option>
            <option value="exists">Đã có kênh</option>
            <option value="none">Chưa có kênh</option>
            <option value="unknown">Không xác định</option>
          </select>
          <input type="number" id="profile-filter-days" class="filter-select" style="width:110px" min="0" placeholder="Số ngày ≥" title="Quản lý ít nhất N ngày" onchange="reloadProfilesView()">
          <select id="profile-filter-stab" class="filter-select" onchange="reloadProfilesView()" title="Lọc theo điểm ổn định">
            <option value="">Mọi điểm ổn định</option>
            <option value="50">Ổn định ≥ 50</option>
            <option value="80">Ổn định ≥ 80</option>
          </select>
          <select id="profile-filter-conf" class="filter-select" onchange="reloadProfilesView()" title="Lọc theo điểm tin cậy">
            <option value="">Mọi điểm tin cậy</option>
            <option value="50">Tin cậy ≥ 50</option>
            <option value="70">Tin cậy ≥ 70</option>
          </select>
          <div class="search-box"><input type="text" id="profile-search" placeholder="Tìm kênh..." oninput="reloadProfilesView()"></div>
          <span id="profile-summary" class="summary-text"></span>
        </div>
        <div class="profile-grid" id="profiles-grid">
          <div class="empty-state">Đang tải...</div>
        </div>
        <div class="pagination" id="profiles-pagination"></div>
      </section>

      <!-- ===== VIEW: PROXIES ===== -->
      <section id="view-proxies" class="view">
        <div class="toolbar">
          <button class="btn btn-primary" onclick="openProxyModal()">+ Thêm proxy</button>
          <button class="btn" onclick="importProxies()">Nhập hàng loạt</button>
          <button class="btn" onclick="testAllProxies()">Test tất cả</button>
          <div class="sel-divider"></div>
          <label class="sel-all-label"><span class="ck"><input type="checkbox" id="sel-all-proxies" onchange="toggleSelectAllProxies(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span> Chọn tất cả</label>
          <button class="btn btn-sm btn-danger" onclick="deleteSelectedProxies()">🗑 Xóa đã chọn</button>
          <span id="selected-proxies-count" class="sel-count"></span>
          <div class="spacer"></div>
          <span id="proxy-summary" class="summary-text"></span>
        </div>
        <div class="panel">
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:34px"><span class="ck"><input type="checkbox" id="sel-all-proxies-head" onchange="toggleSelectAllProxies(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span></th></th>
                  <th>Tên</th><th>Host:Port</th><th>Protocol</th><th>Quốc gia</th>
                  <th>Trạng thái</th><th>Lần check</th><th>Hành động</th>
                </tr>
              </thead>
              <tbody id="proxies-tbody"></tbody>
            </table>
          </div>
        </div>
        <div class="pagination" id="proxies-pagination"></div>
      </section>

      <!-- ===== VIEW: SYNCHRONIZE (MAIN -> CONTROLLED input mirror) ===== -->
      <section id="view-synchronize" class="view">
        <div class="panel">
          <div class="panel-head">
            <h3>Synchronize — <span id="syn-state" class="summary-text">—</span></h3>
            <div class="spacer"></div>
            <button class="btn btn-primary" id="syn-btn-start" onclick="synStart()">▶ Start Sync</button>
            <button class="btn btn-sm" id="syn-btn-pause" onclick="synPause()">⏸ Pause</button>
            <button class="btn btn-sm" id="syn-btn-resume" onclick="synResume()">⏵ Resume</button>
            <button class="btn btn-sm" onclick="synRestart()">↻ Restart</button>
            <button class="btn btn-sm btn-danger" id="syn-btn-stop" onclick="synStop()">⏹ Stop</button>
          </div>
          <div class="sync-url-row" style="flex-wrap:wrap;gap:8px">
            <button class="btn btn-sm" onclick="synSelectAll()">Chọn hết</button>
            <button class="btn btn-sm" onclick="synSelectNone()">Bỏ chọn</button>
            <button class="btn btn-sm" onclick="synSelectInvert()">Đảo chọn</button>
            <button class="btn btn-sm" onclick="synSetMain()">⭐ Đặt MAIN (1 kênh đã chọn)</button>
            <button class="btn btn-sm" onclick="synMakeControlled()">➕ Thêm CONTROLLED (đã chọn)</button>
            <button class="btn btn-sm" onclick="synNewSession()">📝 Session mới từ đã chọn</button>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr><th><span class="ck"><input type="checkbox" id="syn-check-all" onchange="synToggleAll(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span></th>
                <th>#</th><th>Profile</th><th>Window</th><th>Role</th><th>Trạng thái</th><th>Hành động</th></tr>
              </thead>
              <tbody id="syn-tbody"></tbody>
            </table>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Cài đặt phiên</h3></div>
          <div class="settings-form">
            <label>Chuột</label>
            <div class="sync-url-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-mousemove" checked><span>Mouse Move</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-mouseclick" checked><span>Click (trái/phải/giữa)</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-mousewheel" checked><span>Wheel</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-keyboard" checked><span>Keyboard + Hotkeys</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-text" checked><span>Text Sync</span></label>
            </div>
            <label>Tốc độ chuột (FPS)</label>
            <select id="syn-cfg-fps">
              <option value="30">30 FPS</option>
              <option value="60" selected>60 FPS (mặc định)</option>
              <option value="120">120 FPS</option>
            </select>
            <label>Click Delay (ms)</label>
            <input type="number" id="syn-cfg-clickdelay" min="0" max="2000" value="20">
            <label class="checkbox-row"><input type="checkbox" id="syn-cfg-clickrandom"><span>Random delay ±
              <input type="number" id="syn-cfg-clickvar" min="0" max="1000" value="30" style="width:70px"> ms</span></label>
            <label>Typing Delay (ms, min–max)</label>
            <div class="sync-url-row">
              <input type="number" id="syn-cfg-typemin" min="0" max="5000" value="50" style="width:90px">
              <input type="number" id="syn-cfg-typemax" min="0" max="5000" value="120" style="width:90px">
            </div>
            <label>Input Mode</label>
            <select id="syn-cfg-inputmode">
              <option value="AUTO" selected>AUTO (CDP nếu được, fallback sau)</option>
              <option value="CDP">CDP</option>
              <option value="WINDOWS_API">Windows API (Phase 8)</option>
            </select>
            <label>Dừng queue khi Stop</label>
            <select id="syn-cfg-stoppolicy">
              <option value="drain" selected>Drain (xử lý nốt rồi dừng)</option>
              <option value="cancel">Cancel (hủy queue)</option>
            </select>
            <div class="settings-actions">
              <button class="btn btn-primary" onclick="synSaveConfig()">Lưu cài đặt</button>
              <span id="syn-cfg-result" class="summary-text"></span>
            </div>
            <label class="checkbox-row">
              <input type="checkbox" id="syn-cfg-showcursor">
              <span>Show Sync Cursor (chấm đỏ vị trí target trên CONTROLLED — debug coordinate)</span>
            </label>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Debug Mode</h3>
            <div class="spacer"></div>
            <button class="btn btn-sm" onclick="synLoadDebug()">↻ Refresh</button>
            <button class="btn btn-sm" onclick="synLoadLogs()">Nhật ký sync</button>
          </div>
          <div id="syn-debug-main" class="summary-text"></div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Target</th><th>X / Y</th><th>Viewport</th><th>Queue</th><th>Kết nối</th></tr></thead>
              <tbody id="syn-debug-tbody"></tbody>
            </table>
          </div>
          <div class="sync-label" style="margin-top:8px">20 event gần nhất:</div>
          <div id="syn-debug-events" class="mono" style="max-height:150px;overflow:auto;font-size:12px"></div>
          <div class="sync-label" style="margin-top:8px">Log:</div>
          <div id="syn-debug-logs" class="mono" style="max-height:150px;overflow:auto;font-size:12px"></div>
        </div>
      </section>

      <!-- ===== VIEW: LOGS ===== -->
      <section id="view-logs" class="view">
        <div class="panel">
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr><th>Thời gian</th><th>Kênh</th><th>Hành động</th><th>Chi tiết</th></tr>
              </thead>
              <tbody id="logs-tbody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ===== VIEW: SETTINGS ===== -->
      <section id="view-settings" class="view">
        <div class="panel">
          <div class="panel-head"><h3>Cài đặt ứng dụng</h3></div>
          <div class="settings-form">
            <label>Đường dẫn Chrome *</label>
            <input type="text" id="set-chrome-path" placeholder="C:\Program Files\Google\Chrome\Application\chrome.exe">
            <div class="hint">Nếu Chrome ở nơi khác, chỉnh lại đường dẫn chrome.exe</div>

            <label>Trang chủ mặc định khi mở kênh</label>
            <input type="text" id="set-home-url" placeholder="https://www.google.com/">

            <label>Proxy test timeout (giây)</label>
            <input type="number" id="set-proxy-timeout" min="2" max="30" placeholder="5">

            <label class="checkbox-row">
              <input type="checkbox" id="set-auto-refresh">
              <span>Tự động làm mới trạng thái mỗi 15 giây</span>
            </label>

            <div class="settings-actions">
              <button class="btn btn-primary" onclick="saveSettings()">Lưu cài đặt</button>
              <span id="settings-result" class="summary-text"></span>
            </div>
          </div>
        </div>
        <!-- ===== SETTINGS: Browser / Window (Global defaults cho moi Chrome khi Launch) ===== -->
        <div class="panel win-panel">
          <div class="panel-head">
            <h3>🖥️ Browser / Chrome — Cửa sổ</h3>
            <span id="win-live-badge" class="win-live">—</span>
          </div>
          <div class="settings-form win-form">
            <div class="hint">Cấu hình mặc định trung tâm: mọi profile (cũ + mới) đều lấy kích thước / vị trí từ đây <strong>khi Launch</strong>. Không lưu lặp vào từng profile.</div>

            <label class="switch-row">
              <span class="switch"><input type="checkbox" id="set-window-fixed" checked><span class="slider"></span></span>
              <span><strong>Apply Fixed Window Size</strong><br><small class="muted">Bật: mọi Chrome khi mở sẽ ép đúng kích thước bên dưới. Tắt + Auto: giữ nguyên như cũ.</small></span>
            </label>

            <div class="win-group-title">Kích thước cửa sổ</div>
            <div class="preset-grid" id="win-preset-grid">
              <button type="button" class="preset-card" data-preset="small" onclick="selectWindowPreset('small')"><span class="preset-name">Small</span><span class="preset-size">800 × 600</span></button>
              <button type="button" class="preset-card" data-preset="standard" onclick="selectWindowPreset('standard')"><span class="preset-name">Standard</span><span class="preset-size">1024 × 768</span></button>
              <button type="button" class="preset-card" data-preset="hd" onclick="selectWindowPreset('hd')"><span class="preset-name">HD</span><span class="preset-size">1280 × 720</span></button>
              <button type="button" class="preset-card" data-preset="hd_plus" onclick="selectWindowPreset('hd_plus')"><span class="preset-name">HD+</span><span class="preset-size">1280 × 800</span></button>
              <button type="button" class="preset-card" data-preset="laptop" onclick="selectWindowPreset('laptop')"><span class="preset-name">Laptop</span><span class="preset-size">1366 × 768</span></button>
              <button type="button" class="preset-card" data-preset="fhd" onclick="selectWindowPreset('fhd')"><span class="preset-name">Full HD</span><span class="preset-size">1920 × 1080</span></button>
              <button type="button" class="preset-card" data-preset="qhd" onclick="selectWindowPreset('qhd')"><span class="preset-name">2K</span><span class="preset-size">2560 × 1440</span></button>
              <button type="button" class="preset-card" data-preset="uhd" onclick="selectWindowPreset('uhd')"><span class="preset-name">4K</span><span class="preset-size">3840 × 2160</span></button>
              <button type="button" class="preset-card" data-preset="custom" onclick="selectWindowPreset('custom')"><span class="preset-name">Custom</span><span class="preset-size">tự nhập ↓</span></button>
            </div>
            <div class="win-row" id="win-custom-size">
              <div class="win-field"><label>Rộng (400–7680)</label><input type="number" id="set-window-width" min="400" max="7680" value="1280"></div>
              <div class="win-x">×</div>
              <div class="win-field"><label>Cao (300–4320)</label><input type="number" id="set-window-height" min="300" max="4320" value="720"></div>
            </div>

            <div class="win-group-title">Vị trí mở Chrome</div>
            <div class="seg-grid" id="win-pos-grid">
              <button type="button" class="seg-card" data-pos="auto" onclick="selectWindowPos('auto')"><span class="seg-name">Auto</span><small>Giữ chỗ cũ, kẹp trong màn hình</small></button>
              <button type="button" class="seg-card" data-pos="cascade" onclick="selectWindowPos('cascade')"><span class="seg-name">Cascade</span><small>Mở lệch nhau từng ô</small></button>
              <button type="button" class="seg-card" data-pos="grid" onclick="selectWindowPos('grid')"><span class="seg-name">Grid</span><small>Chia ô đều trên màn hình</small></button>
              <button type="button" class="seg-card" data-pos="custom" onclick="selectWindowPos('custom')"><span class="seg-name">Custom</span><small>Tọa độ X / Y cố định</small></button>
            </div>
            <div class="win-row hidden" id="win-custom-pos">
              <div class="win-field"><label>Start X</label><input type="number" id="set-window-x" value="0"></div>
              <div class="win-field"><label>Start Y</label><input type="number" id="set-window-y" value="0"></div>
            </div>

            <div class="win-row">
              <div class="win-field"><label>Màn hình mặc định</label><select id="set-window-monitor"><option value="primary">Màn hình chính</option></select></div>
              <div class="win-field"><label>Gap khi xếp (0–100 px)</label><input type="number" id="set-window-gap" min="0" max="100" value="5"></div>
            </div>

            <div class="settings-actions">
              <button class="btn" id="win-reset-btn" onclick="resetWindowSettings()">↺ Reset mặc định</button>
              <button class="btn btn-primary" id="win-save-btn" onclick="saveWindowSettings()">💾 Lưu cửa sổ</button>
              <span id="window-settings-result" class="summary-text"></span>
            </div>
          </div>
        </div>
        <!-- ===== SETTINGS: Window Layout (Smart Auto Arrange) ===== -->
        <div class="panel win-panel">
          <div class="panel-head">
            <h3>🪟 Bố cục cửa sổ</h3>
            <span id="layout-live-badge" class="win-live">—</span>
          </div>
          <div class="settings-form win-form">
            <div class="hint">Tự động xếp hàng/cột theo số Chrome đang chạy. Mọi profile dùng chung khi Sắp xếp.</div>
            <div class="win-group-title">Bố cục</div>
            <div class="win-row">
              <div class="win-field"><label>Kiểu xếp mặc định</label>
                <select id="set-layout-mode">
                  <option value="smart_auto">Thông minh (tự chọn)</option>
                  <option value="grid">Lưới đều</option>
                  <option value="horizontal">Hàng ngang</option>
                  <option value="vertical">Hàng dọc</option>
                  <option value="cascade">Xếp tầng</option>
                  <option value="compact">Xếp chồng</option>
                </select>
              </div>
              <div class="win-field"><label>Chế độ kích thước</label>
                <select id="set-layout-sizemode">
                  <option value="auto_fit">Tự co vừa màn hình</option>
                  <option value="keep_size">Giữ kích thước cài đặt</option>
                </select>
              </div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Màn hình mục tiêu</label>
                <select id="set-layout-monitor"><option value="primary">Màn hình chính</option></select>
              </div>
              <div class="win-field"><label>&nbsp;</label>
                <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-multi"><span>Dùng nhiều màn hình</span></label>
              </div>
            </div>
            <div class="win-group-title">Khoảng cách</div>
            <div class="win-row">
              <div class="win-field"><label>Khe ngang (0–100)</label><input type="number" id="set-layout-gapx" min="0" max="100" value="5"></div>
              <div class="win-field"><label>Khe dọc (0–100)</label><input type="number" id="set-layout-gapy" min="0" max="100" value="5"></div>
            </div>
            <div class="win-group-title">Xếp thông minh</div>
            <div class="win-row">
              <div class="win-field"><label>Rộng tối thiểu (200–4000)</label><input type="number" id="set-layout-minw" min="200" max="4000" value="500"></div>
              <div class="win-field"><label>Cao tối thiểu (150–3000)</label><input type="number" id="set-layout-minh" min="150" max="3000" value="400"></div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-taskbar" checked><span>Trừ thanh taskbar</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-visible" checked><span>Giữ trong màn hình</span></label>
            </div>
            <div class="win-group-title">Nhiều màn hình</div>
            <div class="hint">Tick chọn màn hình nào được dùng khi Sắp xếp. Bỏ hết = không giới hạn.</div>
            <div id="set-layout-monlist" class="mon-check-list"><div class="hint">Đang tải danh sách màn hình...</div></div>
            <div class="win-row">
              <div class="win-field"><label>Cách chia (Distribution)</label>
                <select id="set-layout-dist">
                  <option value="smart" selected>Thông minh (theo sức chứa)</option>
                  <option value="equal">Chia đều</option>
                  <option value="sequential">Lấp đầy tuần tự</option>
                  <option value="manual">Thủ công (gán từng kênh)</option>
                </select>
              </div>
              <div class="win-field"><label>Cân kích thước</label>
                <select id="set-layout-balance">
                  <option value="similar" selected>Cỡ gần giống nhau</option>
                  <option value="maximize">Tối đa từng màn hình</option>
                </select>
              </div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-remember" checked><span>Nhớ màn hình đã chọn</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-respectdpi" checked><span>Tôn trọng DPI</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-keepinside" checked><span>Giữ trong màn hình đã chọn</span></label>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Mất kết nối màn hình</label>
                <select id="set-layout-disconnect">
                  <option value="ask" selected>Hỏi trước</option>
                  <option value="auto">Tự xếp lại</option>
                </select>
              </div>
              <div class="win-field"><label>MAIN riêng màn hình (để dành Sync)</label>
                <select id="set-layout-mainmon"><option value="">-- Không --</option></select>
              </div>
            </div>
            <div class="win-group-title">Tự động</div>
            <div class="win-row">
              <div class="win-field"><label>&nbsp;</label>
                <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-autolaunch" checked><span>Tự xếp sau khi mở nhiều kênh</span></label>
              </div>
              <div class="win-field"><label>Khi số lượng đổi</label>
                <select id="set-layout-reflow">
                  <option value="off">Tắt</option>
                  <option value="ask" selected>Hỏi trước</option>
                  <option value="auto">Tự động xếp</option>
                </select>
              </div>
            </div>
            <div class="win-group-title">Dự phòng &amp; Xếp chồng</div>
            <div class="win-row">
              <div class="win-field"><label>Khi không vừa</label>
                <select id="set-layout-fallback">
                  <option value="auto" selected>Tự động</option>
                  <option value="multi">Dùng nhiều màn hình</option>
                  <option value="compact">Xếp chồng</option>
                  <option value="force_fit">Ép vừa màn hình</option>
                </select>
              </div>
              <div class="win-field"><label>Lệch ngang chồng</label><input type="number" id="set-layout-compactx" min="0" max="2000" value="150"></div>
              <div class="win-field"><label>Lệch dọc chồng</label><input type="number" id="set-layout-compacty" min="0" max="2000" value="40"></div>
            </div>
            <div class="settings-actions">
              <button class="btn" id="layout-reset-btn" onclick="resetLayoutSettings()">↺ Mặc định</button>
              <button class="btn btn-primary" id="layout-save-btn" onclick="saveLayoutSettings()">💾 Lưu bố cục</button>
              <span id="layout-settings-result" class="summary-text"></span>
            </div>
          </div>
        </div>
        <!-- ===== SETTINGS: Account Evaluation ===== -->
        <div class="panel win-panel">
          <div class="panel-head"><h3>Đánh giá Account</h3></div>
          <div class="settings-form win-form">
            <div class="hint">Quy tắc quản trị nội bộ của tool (không phải điều kiện của Google/YouTube).<br>Đây là tiêu chí quản lý nội bộ, không phải điểm/xếp hạng chính thức của Google hoặc YouTube.</div>
            <div class="win-group-title">Chính sách sẵn sàng</div>
            <div class="win-row">
              <div class="win-field"><label>Theo dõi tối thiểu (ngày)</label><input type="number" id="set-acc-days" min="0" max="365" value="7"></div>
              <div class="win-field"><label>Check thành công tối thiểu</label><input type="number" id="set-acc-checks" min="1" max="1000" value="10"></div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Ngưỡng ổn định sẵn sàng</label><input type="number" id="set-acc-stab" min="0" max="100" value="80"></div>
              <div class="win-field"><label>Ngưỡng tin cậy sẵn sàng</label><input type="number" id="set-acc-conf" min="0" max="100" value="70"></div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Ngưỡng xem lại</label><input type="number" id="set-acc-review" min="0" max="100" value="50"></div>
              <div class="win-field"><label>Lỗi liên tiếp → mất kết nối</label><input type="number" id="set-acc-unavail" min="1" max="1000" value="20"></div>
            </div>
            <div class="win-group-title">Trọng số điểm ổn định</div>
            <div class="win-row">
              <div class="win-field"><label>Đăng nhập</label><input type="number" id="set-acc-wlogin" min="0" max="100" value="25"></div>
              <div class="win-field"><label>Phiên</label><input type="number" id="set-acc-wsession" min="0" max="100" value="15"></div>
              <div class="win-field"><label>YouTube</label><input type="number" id="set-acc-wyt" min="0" max="100" value="25"></div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Lịch sử check</label><input type="number" id="set-acc-wrate" min="0" max="100" value="25"></div>
              <div class="win-field"><label>Lỗi liên tiếp</label><input type="number" id="set-acc-wconsec" min="0" max="100" value="10"></div>
            </div>
            <div class="win-group-title">Kiểm tra nền</div>
            <div class="win-row">
              <div class="win-field"><label>Chu kỳ check (phút)</label><input type="number" id="set-acc-interval" min="5" max="10080" value="120"></div>
              <div class="win-field"><label>Dữ liệu quá hạn (giờ)</label><input type="number" id="set-acc-maxage" min="1" max="720" value="72"></div>
              <div class="win-field"><label>Số kênh mỗi lượt</label><input type="number" id="set-acc-batch" min="1" max="100" value="10"></div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-onstart"><span>Đánh giá khi mở kênh</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-bg"><span>Chạy nền (monitor)</span></label>
            </div>
            <div class="settings-actions">
              <button class="btn" id="acc-reset-btn" onclick="resetAccountSettings()">↺ Mặc định</button>
              <button class="btn btn-primary" id="acc-save-btn" onclick="saveAccountSettings()">💾 Lưu đánh giá</button>
              <span id="account-settings-result" class="summary-text"></span>
            </div>
            <div class="sync-label" style="margin-top:8px" id="acc-monitor-line">Monitor: chưa kiểm tra</div>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Thông tin</h3></div>
          <div class="settings-about">
            <p><strong>YT Manager</strong> — app quản lý nhiều kênh YouTube/TikTok/Facebook với proxy riêng biệt.</p>
            <p>Tham khảo trải nghiệm từ <strong>Hidemium</strong> (antidetect browser): mỗi kênh là 1 profile Chrome tách biệt,
            có proxy riêng, cookie riêng, và điều khiển tab tập trung qua Chrome DevTools Protocol.</p>
            <p>Lưu ý: tuân thủ điều khoản dịch vụ của từng nền tảng, dùng proxy sạch tránh khóa kênh.</p>
          </div>
        </div>
      </section>

    </div>
  </main>
</div>

<!-- ===== MODAL: Profile ===== -->
<div id="profile-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="profile-modal-title">Tạo kênh mới</h2>
      <button class="modal-close" onclick="closeModal('profile-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pf-id">
      <div class="pf-mode-tabs">
        <button type="button" class="btn btn-sm pf-mode-btn active" id="pf-mode-single" onclick="setProfileMode('single')">Tạo đơn</button>
        <button type="button" class="btn btn-sm pf-mode-btn" id="pf-mode-bulk" onclick="setProfileMode('bulk')">Tạo nhiều</button>
      </div>

      <div id="pf-single-fields">
        <label>Tên kênh *</label>
        <input type="text" id="pf-name" placeholder="Ví dụ: Kênh Ẩm Thực">
        <label>User-Agent (để trống = tự động đề xuất)</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="text" id="pf-ua" style="flex:1" placeholder="VD: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36...">
          <button type="button" class="btn btn-sm" onclick="randomUA()" title="Tạo User-Agent ngẫu nhiên">🎲 Tự động</button>
        </div>
        <label>WebRTC (chống lộ IP)</label>
        <select id="pf-webrtc">
          <option value="default">Mặc định (không can thiệp)</option>
          <option value="disable_nonproxied_udp">Chỉ cho UDP qua proxy (đề xuất)</option>
        </select>
        <label>Channel Handle (tùy chọn)</label>
        <input type="text" id="pf-handle" placeholder="Ví dụ: @kênh-ẩm-thực hoặc channel ID">
      </div>

      <div id="pf-bulk-fields" class="hidden">
        <label>Tiền tố tên kênh</label>
        <input type="text" id="pf-prefix" placeholder="Mặc định: Kênh" value="Kênh">
        <div class="hint">Tên sẽ là <strong>[Tiền tố] STT</strong>, VD: Kênh 1, Kênh 2...</div>
        <label>Số lượng kênh *</label>
        <input type="number" id="pf-count" value="5" min="1" max="200">
      </div>

      <label>Nền tảng</label>
      <select id="pf-platform">
        <option value="youtube">YouTube</option>
        <option value="tiktok">TikTok</option>
        <option value="facebook">Facebook</option>
        <option value="other">Khác</option>
      </select>
      <label>Proxy</label>
      <select id="pf-proxy"><option value="">Không dùng proxy</option></select>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('profile-modal')">Hủy</button>
      <button class="btn btn-primary" id="pf-save-btn" onclick="saveProfile()">Lưu</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Proxy ===== -->
<div id="proxy-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="proxy-modal-title">Thêm proxy</h2>
      <button class="modal-close" onclick="closeModal('proxy-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="px-id">
      <label>Chuỗi proxy (điền nhanh)</label>
      <input type="text" id="px-string" placeholder="host:port:user:pass  vd: 198.12.102.140:44743:DedFAM:rzOofV" oninput="parseProxyStringInput(this.value)">
      <label>Tên (tùy chọn)</label>
      <input type="text" id="px-name" placeholder="Ví dụ: Proxy Mỹ #1">
      <label>Host *</label>
      <input type="text" id="px-host" placeholder="192.168.1.100 hoặc proxy.example.com" required>
      <label>Port *</label>
      <input type="number" id="px-port" placeholder="8080" required>
      <label>Protocol</label>
      <select id="px-protocol">
        <option value="http">HTTP</option>
        <option value="socks4">SOCKS4</option>
        <option value="socks5">SOCKS5</option>
        <option value="ssh">SSH</option>
      </select>
      <label>Username (tùy chọn)</label>
      <input type="text" id="px-user" placeholder="Tên đăng nhập nếu proxy yêu cầu">
      <label>Password (tùy chọn)</label>
      <input type="text" id="px-pass" placeholder="Mật khẩu nếu proxy yêu cầu">
      <label>Quốc gia (mã 2 chữ)</label>
      <input type="text" id="px-country" placeholder="US, VN, JP..." maxlength="2">
      <div class="proxy-result" id="proxy-test-result"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('proxy-modal')">Hủy</button>
      <button class="btn" onclick="testProxyModal()">Test</button>
      <button class="btn btn-primary" onclick="saveProxy()">Lưu</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Import ===== -->
<div id="import-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2>Nhập proxy hàng loạt</h2>
      <button class="modal-close" onclick="closeModal('import-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <label>Nhập danh sách proxy (mỗi dòng 1 proxy)</label>
      <textarea id="import-list" rows="8" placeholder="host:port&#10;host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port"></textarea>
      <div class="hint">Hỗ trợ: host:port · host:port:user:pass · protocol://user:pass@host:port</div>
      <div class="import-result" id="import-result"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('import-modal')">Hủy</button>
      <button class="btn btn-primary" onclick="doImport()">Nhập vào danh sách</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Gán proxy hàng loạt ===== -->
<div id="bulk-proxy-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="bulk-proxy-title">Gán proxy cho kênh đã chọn</h2>
      <button class="modal-close" onclick="closeModal('bulk-proxy-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="hint" id="bulk-proxy-count" style="margin-top:0"></div>
      <div class="pf-mode-tabs">
        <button type="button" class="btn btn-sm pf-mode-btn active" id="bp-mode-single" onclick="setBulkProxyMode('single')">Chọn 1 proxy</button>
        <button type="button" class="btn btn-sm pf-mode-btn" id="bp-mode-list" onclick="setBulkProxyMode('list')">Paste danh sách</button>
      </div>

      <div id="bp-single-fields">
        <label>Chọn proxy để gán cho các kênh</label>
        <select id="bulk-proxy-select"><option value="">Không dùng proxy (gỡ proxy)</option></select>
      </div>

      <div id="bp-list-fields" class="hidden">
        <label>Danh sách proxy (mỗi dòng 1 proxy)</label>
        <textarea id="bulk-proxy-list" rows="6" placeholder="host:port&#10;host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port"></textarea>
        <div class="hint">Gán theo thứ tự: kênh 1 ← dòng 1, kênh 2 ← dòng 2... Proxy chưa có sẽ tự thêm. Nếu ít proxy hơn số kênh thì các kênh cuối không gán.</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('bulk-proxy-modal')">Hủy</button>
      <button class="btn btn-primary" onclick="saveBulkProxy()">Gán</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Arrange preview ===== -->
<div id="preview-modal" class="modal-overlay hidden">
  <div class="modal" style="width:640px">
    <div class="modal-header">
      <h2>Xem trước sắp xếp</h2>
      <button class="modal-close" onclick="closeModal('preview-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="preview-summary" class="summary-text"></div>
      <div id="preview-body"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('preview-modal')">Hủy</button>
      <button class="btn btn-primary" id="preview-apply-btn" onclick="applyPreviewArrange()">Xếp ngay</button>
    </div>
  </div>
</div>

<!-- ===== DRAWER: Account detail (truot tu phai) ===== -->
<div id="acc-drawer-wrap" class="drawer-wrap hidden">
  <div class="drawer-overlay" onclick="closeAccountDrawer()"></div>
  <aside class="drawer">
    <div class="drawer-head">
      <h3 id="acc-drawer-title">Account</h3>
      <button class="modal-close" onclick="closeAccountDrawer()">&times;</button>
    </div>
    <div class="drawer-body" id="acc-drawer-body"></div>
    <div class="drawer-foot">
      <button class="btn" id="acc-drawer-eval">Kiểm tra lại</button>
      <button class="btn" id="acc-drawer-open">Mở Profile</button>
      <button class="btn" id="acc-drawer-hist">Lịch sử</button>
    </div>
  </aside>
</div>

<!-- ===== DRAWER: Account detail (truot tu phai, khong chuyen page) ===== -->
<div id="acc-drawer-wrap" class="drawer-wrap hidden">
  <div class="drawer-overlay" onclick="closeAccountDrawer()"></div>
  <aside class="drawer">
    <div class="drawer-head">
      <h3 id="acc-drawer-title">Account</h3>
      <button class="modal-close" onclick="closeAccountDrawer()">&times;</button>
    </div>
    <div class="drawer-body" id="acc-drawer-body"></div>
    <div class="drawer-foot">
      <button class="btn" id="acc-drawer-eval">Kiểm tra lại</button>
      <button class="btn" id="acc-drawer-open">Mở Profile</button>
      <button class="btn" id="acc-drawer-hist">Lịch sử</button>
    </div>
  </aside>
</div>

<div id="toast" class="toast hidden"></div>

<!-- ===== MODAL: Xác nhận xóa dùng chung ===== -->
<div id="confirm-modal" class="modal-overlay hidden">
  <div class="modal" style="width:400px">
    <div class="modal-header">
      <h2>⚠️ Xác nhận</h2>
      <button class="modal-close" onclick="hideConfirm()">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirm-msg" style="font-size:14px;line-height:1.6;margin:0"></p>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="hideConfirm()">Hủy</button>
      <button class="btn btn-danger" id="confirm-ok-btn" onclick="doConfirmDelete()">Xóa</button>
    </div>
  </div>
</div>

<script src="assets/js/app.js?v=20260917q"></script>
</body>
</html>