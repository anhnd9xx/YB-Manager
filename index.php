<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YT Manager - Quản lý kênh đa proxy</title>
<link rel="icon" href="data:,">
  <link rel="stylesheet" href="assets/css/style.css">
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
      <button class="nav-btn" data-view="sync">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
        <span>Đồng bộ tab</span>
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
          <div class="stat-card"><div class="stat-value blue" id="stat-tabs">-</div><div class="stat-label">Tab đang mở</div></div>
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
          <label class="sel-all-label"><input type="checkbox" id="sel-all" onchange="toggleSelectAll(this.checked)"> Chọn tất cả</label>
          <button class="btn btn-sm" onclick="openSelected()">▶ Mở đã chọn</button>
          <button class="btn btn-sm" onclick="closeSelected()">■ Đóng đã chọn</button>
          <button class="btn btn-sm" onclick="assignProxySelected()">⇄ Gán proxy</button>
          <button class="btn btn-sm btn-danger" onclick="deleteSelected()">🗑 Xóa đã chọn</button>
          <span id="selected-count" class="sel-count"></span>
          <div class="spacer"></div>
          <select id="profile-filter-platform" class="filter-select" onchange="reloadProfilesView()">
            <option value="">Tất cả nền tảng</option>
            <option value="youtube">YouTube</option>
            <option value="tiktok">TikTok</option>
            <option value="facebook">Facebook</option>
            <option value="other">Khác</option>
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
          <label class="sel-all-label"><input type="checkbox" id="sel-all-proxies" onchange="toggleSelectAllProxies(this.checked)"> Chọn tất cả</label>
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
                  <th style="width:34px"><input type="checkbox" id="sel-all-proxies-head" onchange="toggleSelectAllProxies(this.checked)"></th>
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

      <!-- ===== VIEW: SYNC ===== -->
      <section id="view-sync" class="view">
        <div class="panel">
          <div class="panel-head"><h3>Đồng bộ mở URL lên mọi kênh</h3></div>
          <div class="sync-url-row">
            <input type="text" id="sync-url" placeholder="Nhập URL để đồng bộ mở trên mọi kênh..." class="sync-url-input">
            <button class="btn btn-primary" onclick="syncOpenUrl()">Đồng bộ mở</button>
          </div>
          <div class="sync-quick">
            <span class="sync-label">Mở nhanh:</span>
            <button class="btn btn-sm" onclick="syncOpen('https://www.youtube.com')">YouTube</button>
            <button class="btn btn-sm" onclick="syncOpen('https://studio.youtube.com')">Studio</button>
            <button class="btn btn-sm" onclick="syncOpen('https://www.youtube.com/feed/subscriptions')">Subscriptions</button>
            <input type="text" id="sync-handle" placeholder="@handle hoặc channel ID" style="width:190px">
            <button class="btn btn-sm" onclick="syncOpenHandle()">Mở theo handle</button>
          </div>
        </div>
        <div class="sync-meta">
          <span id="sync-summary" class="summary-text"></span>
        </div>
        <div id="sync-results"></div>
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
            <input type="text" id="set-home-url" placeholder="https://www.youtube.com">

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
          <option value="disabled">Tắt WebRTC hoàn toàn</option>
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

<script src="assets/js/app.js"></script>
</body>
</html>