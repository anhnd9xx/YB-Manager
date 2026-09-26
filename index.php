<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YT Manager - Quản lý kênh đa proxy</title>
<link rel="icon" href="data:,">
  <link rel="stylesheet" href="assets/css/style.css?v=20260924a">
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
      <button class="nav-btn active" data-view="dashboard" data-tip="Tổng quan">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        <span>Tổng quan</span>
      </button>
      <button class="nav-btn" data-view="controlcenter" data-tip="Trung tâm điều hành">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 2 1 21h22L12 2zm0 4.2L19.5 19h-15L12 6.2zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/></svg>
        <span>Trung tâm điều hành</span>
        <span id="nav-cc-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="aidev" data-tip="AI Dev Console">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C7.8 12.16 7 10.63 7 9c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.63-.8 3.16-2.15 4.1z"/></svg>
        <span>AI Dev</span>
        <span id="nav-ai-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="profiles" data-tip="Kênh">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        <span>Kênh</span>
        <span id="nav-profile-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="monitoring" data-tip="Thống kê & Theo dõi">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 3v18h18v-2H5V3H3zm4 12v-6h3v6H7zm5 0V7h3v8h-3zm5 0v-4h3v4h-3z"/></svg>
        <span>Thống kê & Theo dõi</span>
        <span id="nav-alert-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="notify" data-tip="Thông báo & Báo cáo">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5S10.5 3.17 10.5 4v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
        <span>Thông báo</span>
        <span id="nav-notify-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="proxies" data-tip="Proxy">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 12c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm6-1.8C18 6.57 15.35 4 12 4s-6 2.57-6 6.2c0 2.34 1.95 5.44 6 9.14 4.05-3.7 6-6.8 6-9.14zM12 2c4.2 0 8 3.22 8 8.2 0 3.32-2.67 7.25-8 11.8-5.33-4.55-8-8.48-8-11.8C4 5.22 7.8 2 12 2z"/></svg>
        <span>Proxy</span>
        <span id="nav-proxy-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="synchronize" data-tip="Synchronize">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M8 11H5v2h3v3h2v-3h3v-2h-3V8H8v3zm8-8H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 14H4V5h12v12zm4-13v9h-2V6l-2 2V5l3.5-3L22 5v3l-2-2v7h-2z"/></svg>
        <span>Synchronize</span>
      </button>
      <button class="nav-btn" data-view="logs" data-tip="Nhật ký">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
        <span>Nhật ký</span>
      </button>
    </nav>

    <div class="side-footer">
      <button class="nav-btn" data-view="settings" data-tip="Cài đặt">
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
          <span class="refresh-spin">⟳</span><span class="lbl"> Làm mới</span>
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

      <!-- ===== VIEW: CONTROL CENTER (Trung tâm điều hành) ===== -->
      <section id="view-controlcenter" class="view">
        <div class="cc-hero">
          <div class="cc-hero-text">
            <div class="cc-hero-title">Trung tâm điều hành</div>
            <div class="cc-hero-sub">Theo dõi hệ thống, công việc và cảnh báo theo thời gian thực</div>
          </div>
          <div class="cc-hero-right">
            <span id="cc-live" class="badge badge-ok"><span class="nt-dot on"></span> Realtime</span>
            <span id="cc-overall" class="badge badge-muted">…</span>
            <button type="button" class="btn btn-sm" onclick="ccRefresh(true)">↻ Làm mới</button>
          </div>
        </div>
        <div class="cc-kpis" id="cc-kpis">
          <div class="skeleton"></div>
        </div>
        <div class="cc-grid">
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">▶</span>Công việc đang chạy
                <button type="button" class="btn btn-xs" onclick="ccJobsToggle()" id="cc-jobs-toggle">Xem tất cả công việc</button>
              </div>
              <div id="cc-jobs"><div class="skeleton"></div></div>
              <div id="cc-jobs-all" class="hidden">
                <div class="cc-filters">
                  <select id="cc-f-module" onchange="ccJobsLoad()"><option value="all">Mọi module</option><option>EVALUATION</option><option>BROWSER</option><option>AUTO_ACTIVITY</option><option>PROXY</option><option>SYSTEM</option></select>
                  <select id="cc-f-status" onchange="ccJobsLoad()"><option value="ACTIVE">Đang hoạt động</option><option value="all">Tất cả</option><option>QUEUED</option><option>RUNNING</option><option>PAUSED</option><option>SUCCESS</option><option>PARTIAL</option><option>FAILED</option><option>CANCELLED</option><option>INTERRUPTED</option></select>
                  <select id="cc-f-source" onchange="ccJobsLoad()"><option value="all">Mọi nguồn</option><option>UI</option><option>TELEGRAM</option><option>SCHEDULER</option><option>SYSTEM</option><option>API</option></select>
                </div>
                <div id="cc-jobs-list"><div class="skeleton"></div></div>
              </div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">⚠</span>Cần chú ý <span id="cc-alert-n" class="badge badge-muted">0</span></div>
              <div id="cc-alerts"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">♥</span>Sức khỏe hệ thống</div>
              <div id="cc-health"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">◷</span>Lịch sắp tới</div>
              <div id="cc-sched"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">⚡</span>Thao tác nhanh</div>
              <div class="cc-actions">
                <button type="button" class="btn btn-sm" onclick="ccQuick('BROWSER_START')">▶ Mở kênh</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('BROWSER_STOP')">■ Đóng kênh</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('EVALUATION')">✓ Đánh giá</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('PROXY_CHECK')">⌖ Kiểm tra Proxy</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('AUTO_ACTIVITY')">◷ Activity</button>
                <button type="button" class="btn btn-sm" onclick="ccSchedOpen()">＋ Tạo lịch</button>
              </div>
            </div>
          </div>
        </div>
        <div class="cc-grid">
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">◔</span>Tài nguyên</div>
              <div id="cc-res"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">☰</span>Hoạt động gần đây</div>
              <div id="cc-activity"><div class="skeleton"></div></div>
            </div>
          </div>
        </div>
        <div id="cc-drawer" class="cc-drawer hidden">
          <div class="cc-drawer-head">
            <strong id="cc-drawer-title">Job</strong>
            <button type="button" class="modal-close" onclick="ccDrawerClose()">×</button>
          </div>
          <div id="cc-drawer-body" class="cc-drawer-body"><div class="skeleton"></div></div>
        </div>
        <div id="cc-modal" class="modal-overlay hidden">
          <div class="modal">
            <div class="modal-header"><strong id="cc-modal-title">Thao tác</strong>
              <button type="button" class="modal-close" onclick="ccModalClose()">×</button>
            </div>
            <div id="cc-modal-body" class="modal-body"></div>
          </div>
        </div>
      </section>

      <!-- ===== VIEW: AI DEV CONSOLE ===== -->
      <section id="view-aidev" class="view">
        <div class="cc-hero">
          <div class="cc-hero-text">
            <div class="cc-hero-title">AI Dev Console</div>
            <div class="cc-hero-sub">Hỏi project, lập phương án và code cùng OpenCode — duyệt trước khi áp dụng</div>
          </div>
          <div class="cc-hero-right">
            <span id="ai-oc-state" class="badge badge-muted">…</span>
            <button type="button" class="btn btn-sm" onclick="aiRefresh(true)">↻ Làm mới</button>
          </div>
        </div>
        <div class="cc-grid">
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">✦</span>OpenCode</div>
              <div id="ai-oc"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">🛠</span>Dev Jobs đang hoạt động</div>
              <div id="ai-jobs"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">⚙</span>Cấu hình AI</div>
              <div id="ai-config"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">▦</span>Project</div>
              <div id="ai-project"><div class="skeleton"></div></div>
            </div>
          </div>
        </div>
        <div id="ai-drawer" class="cc-drawer hidden">
          <div class="cc-drawer-head">
            <strong id="ai-drawer-title">Dev Job</strong>
            <button type="button" class="modal-close" onclick="aiDrawerClose()">×</button>
          </div>
          <div id="ai-drawer-body" class="cc-drawer-body"><div class="skeleton"></div></div>
        </div>
      </section>

      <!-- ===== VIEW: PROFILES ===== -->
      <section id="view-profiles" class="view">
        <div class="toolbar">
          <button class="btn btn-primary" onclick="openProfileModal()">
            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Tạo kênh
          </button>
          <button class="btn btn-sm tb-sec" id="btn-open-all" onclick="openAllProfiles()">Mở tất cả</button>
          <button class="btn btn-sm tb-sec" id="btn-close-all" onclick="closeAllProfiles()">Đóng tất cả</button>
          <div class="sel-divider"></div>
          <label class="sel-all-label"><span class="ck"><input type="checkbox" id="sel-all" onchange="toggleSelectAll(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span> Chọn tất cả</label>
          <button class="btn btn-sm" onclick="openSelected()">▶ Mở đã chọn</button>
          <button class="btn btn-sm" onclick="closeSelected()">■ Đóng đã chọn</button>
          <button class="btn btn-sm tb-sec" onclick="assignProxySelected()">⇄ Gán proxy</button>
          <div class="dropdown">
            <div class="split-btn">
              <button class="btn btn-sm tb-sec" id="btn-eval-bulk" onclick="evaluateSelected()" title="Đánh giá các kênh đã chọn">✓ Đánh giá</button>
              <button class="btn btn-sm tb-sec split-arrow" onclick="toggleEvalMenu(event)" title="Chế độ đánh giá">▼</button>
            </div>
            <div class="dropdown-menu hidden" id="eval-menu">
              <button onclick="evaluateSelected()">✓ Đánh giá đã chọn <span class="muted" id="eval-menu-count"></span></button>
              <button onclick="evaluateAll()">✓ Đánh giá tất cả</button>
              <button onclick="evaluateDue()">✓ Đánh giá kênh cần cập nhật</button>
            </div>
          </div>
          <button class="btn btn-sm tb-sec hidden" id="btn-eval-cancel" onclick="evalCancelBatch()" title="Hủy batch đang chạy">✕ Hủy</button>
          <button class="btn btn-sm tb-sec" onclick="actBulkOpen()" title="Gán Auto Activity cho các kênh đã chọn">◷ Auto</button>
          <button class="btn btn-sm btn-danger tb-sec" onclick="deleteSelected()">🗑 Xóa đã chọn</button>
          <div class="arr-wrap">
            <div class="dropdown">
              <div class="split-btn">
                <button class="btn btn-sm btn-primary" onclick="arrangeLast()" title="Áp dụng cấu hình sắp xếp gần nhất">⬚ Sắp xếp</button>
                <button class="btn btn-sm btn-primary split-arrow" id="arrange-btn" onclick="openArrangeDrawer()" title="Tùy chọn sắp xếp">▼</button>
              </div>
            </div>
          </div>
              <div class="arr-panel hidden" id="arrange-menu">
                <div class="arr-head">
                  <h3>Sắp xếp cửa sổ</h3>
                  <button class="modal-close" onclick="closeArrangeDrawer()">×</button>
                </div>
                <div class="arr-body">
                <div class="arr-group">
                  <div class="arr-gtitle">Phạm vi</div>
                  <div id="arr-scope">
                    <label class="radio-row"><input type="radio" name="arr-scope" value="visible" checked><span>Tất cả đang hiển thị (<b id="arr-n-visible">0</b>)</span></label>
                    <label class="radio-row"><input type="radio" name="arr-scope" value="selected"><span>Kênh đã chọn (<b id="arr-n-selected">0</b>)</span></label>
                    <label class="radio-row"><input type="radio" name="arr-scope" value="running"><span>Kênh đang chạy (<b id="arr-n-running">0</b>)</span></label>
                  </div>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Bố cục</div>
                  <div class="lay-cards" id="arr-layouts">
                    <button class="lay-card" data-mode="smart_auto" onclick="arrSelectMode('smart_auto')"><span class="lay-ic">✨</span><span>Thông minh</span></button>
                    <button class="lay-card" data-mode="grid" onclick="arrSelectMode('grid')"><span class="lay-ic">▦</span><span>Lưới</span></button>
                    <button class="lay-card" data-mode="horizontal" onclick="arrSelectMode('horizontal')"><span class="lay-ic">☰</span><span>Hàng ngang</span></button>
                    <button class="lay-card" data-mode="vertical" onclick="arrSelectMode('vertical')"><span class="lay-ic">⋮</span><span>Hàng dọc</span></button>
                    <button class="lay-card" data-mode="cascade" onclick="arrSelectMode('cascade')"><span class="lay-ic">🗗</span><span>Bố cục tầng</span></button>
                    <button class="lay-card" data-mode="compact" onclick="arrSelectMode('compact')"><span class="lay-ic">▤</span><span>Bố cục nén</span></button>
                  </div>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Màn hình</div>
                  <select id="arr-monitor" class="filter-select" style="width:100%"></select>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Tùy chọn</div>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-taskbar" checked><span>Chừa taskbar</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-uniform" checked><span>Kích thước đồng đều</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-autofit" checked><span>Tự co giãn theo số lượng</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-skipmin"><span>Bỏ qua cửa sổ chưa sẵn sàng</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-focus" checked><span>Không giành focus</span></label>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Kích thước &amp; Mật độ</div>
                  <div class="win-row">
                    <div class="win-field"><label>Cỡ cửa sổ</label>
                      <select id="arr-size" class="filter-select">
                        <option value="auto" selected>Tự động</option>
                        <option value="small">Nhỏ</option>
                        <option value="medium">Vừa</option>
                        <option value="large">Lớn</option>
                      </select>
                    </div>
                    <div class="win-field"><label>Mật độ</label>
                      <select id="arr-density" class="filter-select">
                        <option value="balanced" selected>Cân bằng</option>
                        <option value="relaxed">Thoáng</option>
                        <option value="dense">Dày</option>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Preset nhanh</div>
                  <div class="preset-row">
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(1)">1 cột</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(2)">2 cột</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(3)">3 cột</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(4)">4 cột</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(5)">5 cột</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(6)">6 cột</button>
                  </div>
                  <div class="preset-row">
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(4)">4 ô/màn</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(6)">6 ô/màn</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(8)">8 ô/màn</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(12)">12 ô/màn</button>
                  </div>
                  <div class="preset-row" id="arr-presets"></div>
                  <button class="btn btn-sm" onclick="arrSavePreset()">💾 Lưu preset hiện tại</button>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Xem trước</div>
                  <div class="mini-preview" id="arr-preview"></div>
                  <div class="preset-row" style="margin-top:7px">
                    <button class="btn btn-sm" onclick="arrangePreviewPanel()">🔍 Xem trước chi tiết</button>
                  </div>
                  <button class="link-btn" onclick="gotoLayoutSettings()">Cài đặt nâng cao…</button>
                </div>
                </div>
                <div class="arr-foot">
                  <button class="btn" onclick="closeArrangeDrawer()">Hủy</button>
                  <button class="btn btn-primary" onclick="arrApplyPanel()">✓ Áp dụng</button>
                </div>
              </div>
              <div class="drawer-overlay hidden" id="arrange-overlay" onclick="closeArrangeDrawer()"></div>
          <span id="selected-count" class="sel-count"></span>
          <span id="arrange-result" class="sel-count"></span>
          <div class="spacer"></div>
          <button class="btn btn-sm" id="filter-toggle" onclick="toggleFilterPanel()" title="Bộ lọc">Lọc ⚙</button>
          <div id="filter-panel">
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
          <select id="profile-filter-eval" class="filter-select" onchange="reloadProfilesView()" title="Lọc theo trạng thái đánh giá">
            <option value="">Mọi trạng thái ĐG</option>
            <option value="ACTIVE">Hoạt động</option>
            <option value="UNCHECKED">Chưa kiểm tra</option>
            <option value="CHECKING">Đang kiểm tra</option>
            <option value="LOGIN_REQUIRED">Cần đăng nhập</option>
            <option value="VERIFICATION_REQUIRED">Cần xác minh</option>
            <option value="CHANNEL_UNAVAILABLE">Không truy cập được</option>
            <option value="ERROR">Lỗi kiểm tra</option>
          </select>
          <select id="profile-filter-channel" class="filter-select" onchange="reloadProfilesView()" title="Lọc theo kênh YouTube">
            <option value="">Mọi kênh YT</option>
            <option value="exists">Đã có kênh</option>
            <option value="none">Chưa có kênh</option>
            <option value="unknown">Không xác định</option>
            <option value="signed_in">Đã đăng nhập</option>
            <option value="signed_out">Chưa đăng nhập</option>
            <option value="ready">Sẵn sàng tạo kênh</option>
            <option value="recheck">Cần đánh giá lại</option>
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
            <div class="filter-actions">
              <button class="btn btn-sm" onclick="resetFilters()">Đặt lại</button>
              <button class="btn btn-sm btn-primary" onclick="toggleFilterPanel(false)">Áp dụng</button>
            </div>
          </div>
          <div class="search-box"><input type="text" id="profile-search" placeholder="Tìm kênh..." oninput="reloadProfilesView()"></div>
          <span id="profile-summary" class="summary-text"></span>
        </div>
        <div class="profile-grid" id="profiles-grid">
          <div class="empty-state">Đang tải...</div>
        </div>
        <div class="pagination" id="profiles-pagination"></div>
      </section>

      <!-- ===== VIEW: MONITORING (Thong ke & Theo doi) ===== -->
      <section id="view-monitoring" class="view">
        <div class="mon-head">
          <p class="mon-sub">Theo dõi trạng thái, sức khỏe và thay đổi của toàn bộ kênh</p>
          <div class="mon-actions">
            <button class="btn btn-sm" onclick="monRefresh(true)">↻ Làm mới</button>
            <span class="muted" id="mon-updated">—</span>
            <label class="muted">Tự động <select id="mon-auto" class="filter-select" onchange="monAutoChange()">
              <option value="0">Tắt</option><option value="1">1 phút</option><option value="5">5 phút</option><option value="15">15 phút</option>
            </select></label>
          </div>
        </div>
        <div class="mon-strip" id="mon-strip"><div class="skeleton skeleton-strip"></div></div>
        <div class="mon-range-row">
          <div class="seg" id="mon-range">
            <button data-range="1" onclick="monSetRange(1)">24 giờ</button>
            <button data-range="7" class="active" onclick="monSetRange(7)">7 ngày</button>
            <button data-range="30" onclick="monSetRange(30)">30 ngày</button>
            <button data-range="custom" onclick="monSetRangeCustom()">Tùy chỉnh</button>
          </div>
          <span id="mon-custom-wrap" class="hidden">
            <input type="date" id="mon-from" class="filter-select">
            <span class="muted">→</span>
            <input type="date" id="mon-to" class="filter-select">
            <button class="btn btn-sm btn-primary" onclick="monRangeCustomGo()">Áp dụng</button>
          </span>
        </div>
        <div class="mon-tabs">
          <button class="mon-tab active" data-mtab="overview" onclick="monTab('overview')">Tổng quan</button>
          <button class="mon-tab" data-mtab="channels" onclick="monTab('channels')">Kênh</button>
          <button class="mon-tab" data-mtab="alerts" onclick="monTab('alerts')">Cảnh báo <span id="mon-tab-alert-n" class="nav-count hidden"></span></button>
          <button class="mon-tab" data-mtab="trends" onclick="monTab('trends')">Xu hướng</button>
          <button class="mon-tab" data-mtab="history" onclick="monTab('history')">Lịch sử</button>
        </div>

        <div class="mon-pane" id="mon-pane-overview">
          <div class="mon-kpi-grid" id="mon-kpi"></div>
          <div class="mon-grid-row">
            <div class="dashboard-card mon-span8">
              <div class="dashboard-card-head"><h3>Tình trạng toàn bộ kênh</h3></div>
              <div id="mon-health"><div class="skeleton"></div></div>
            </div>
            <div class="dashboard-card mon-span4">
              <div class="dashboard-card-head"><h3>Cần chú ý</h3><button class="btn btn-sm" onclick="monTab('alerts')">Xem tất cả</button></div>
              <div id="mon-alerts-mini"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="mon-grid-row">
            <div class="dashboard-card mon-span8">
              <div class="dashboard-card-head"><h3>Xu hướng</h3>
                <select id="mon-mini-metric" class="filter-select" onchange="monLoadMiniTrend()">
                  <option value="active">Hoạt động</option>
                  <option value="issues">Có vấn đề</option>
                  <option value="login">Cần đăng nhập</option>
                  <option value="verify">Cần xác minh</option>
                  <option value="proxy">Proxy lỗi</option>
                  <option value="evalfail">Đánh giá thất bại</option>
                </select>
              </div>
              <canvas id="mon-mini-chart" height="160"></canvas>
              <div id="mon-mini-empty" class="empty-state hidden">Chưa đủ dữ liệu lịch sử để hiển thị xu hướng.</div>
            </div>
            <div class="dashboard-card mon-span4">
              <div class="dashboard-card-head"><h3>Theo dõi hệ thống</h3></div>
              <div id="mon-sysinfo"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="dashboard-card">
            <div class="dashboard-card-head"><h3>Thay đổi gần đây</h3>
              <select id="mon-recent-filter" class="filter-select" onchange="monLoadRecent()">
                <option value="">Tất cả</option>
                <option value="evaluation">Đánh giá</option>
                <option value="runtime">Chrome</option>
                <option value="proxy">Proxy</option>
                <option value="monitoring">Theo dõi</option>
              </select>
              <button class="btn btn-sm" onclick="monTab('history')">Xem tất cả</button>
            </div>
            <div id="mon-recent"><div class="skeleton"></div></div>
          </div>
          <div class="dashboard-card">
            <div class="dashboard-card-head"><h3>Phân bố</h3>
              <select id="mon-dist-by" class="filter-select" onchange="monLoadDist()">
                <option value="stage">Theo giai đoạn</option>
                <option value="platform">Theo nền tảng</option>
                <option value="proxy">Theo Proxy</option>
                <option value="monitoring">Theo monitoring</option>
              </select>
            </div>
            <div id="mon-dist"><div class="skeleton"></div></div>
          </div>
        </div>

        <div class="mon-pane hidden" id="mon-pane-channels">
          <div class="mon-chips" id="mon-chips">
            <button class="chip active" data-chip="" onclick="monChip('')">Tất cả</button>
            <button class="chip" data-chip="ACTIVE" onclick="monChip('ACTIVE')">Hoạt động</button>
            <button class="chip" data-chip="ISSUES" onclick="monChip('ISSUES')">Có vấn đề</button>
            <button class="chip" data-chip="UNCHECKED" onclick="monChip('UNCHECKED')">Chưa check</button>
            <button class="chip" data-chip="LOGIN_REQUIRED" onclick="monChip('LOGIN_REQUIRED')">Cần login</button>
            <button class="chip" data-chip="proxy_dead" onclick="monChip('proxy_dead')">Proxy lỗi</button>
            <button class="chip" data-chip="RUNNING" onclick="monChip('RUNNING')">Đang chạy</button>
            <button class="chip" data-chip="WATCH" onclick="monChip('WATCH')">⭐ Watchlist</button>
          </div>
          <div class="toolbar mon-filters">
            <div class="search-box"><input type="text" id="mon-search" placeholder="Tìm kênh..." oninput="monSearch()"></div>
            <select id="mon-f-eval" class="filter-select" onchange="monLoadChannels(1)"><option value="">Mọi ĐG</option><option value="ACTIVE">Hoạt động</option><option value="UNCHECKED">Chưa kiểm tra</option><option value="CHECKING">Đang kiểm tra</option><option value="LOGIN_REQUIRED">Cần đăng nhập</option><option value="VERIFICATION_REQUIRED">Cần xác minh</option><option value="CHANNEL_UNAVAILABLE">Không truy cập được</option><option value="ERROR">Lỗi kiểm tra</option></select>
            <select id="mon-f-chrome" class="filter-select" onchange="monLoadChannels(1)"><option value="">Mọi Chrome</option><option value="running">Đang chạy</option><option value="stopped">Dừng</option></select>
            <select id="mon-f-stage" class="filter-select" onchange="monLoadChannels(1)"><option value="">Mọi giai đoạn</option><option value="NEW">Mới</option><option value="OBSERVING">Theo dõi</option><option value="STABLE">Ổn định</option><option value="READY_FOR_CHANNEL">Sẵn sàng</option><option value="CHANNEL_EXISTS">Có kênh</option><option value="REVIEW_REQUIRED">Cần xem</option><option value="ACTION_REQUIRED">Cần xử lý</option><option value="UNAVAILABLE">Mất kết nối</option></select>
            <select id="mon-f-platform" class="filter-select" onchange="monLoadChannels(1)"><option value="">Mọi nền tảng</option><option value="youtube">YouTube</option><option value="tiktok">TikTok</option><option value="facebook">Facebook</option><option value="other">Khác</option></select>
            <select id="mon-f-proxy" class="filter-select" onchange="monLoadChannels(1)"><option value="">Mọi proxy</option><option value="ok">Proxy tốt</option><option value="dead">Proxy lỗi</option><option value="none">Không proxy</option></select>
            <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="mon-f-watch" onchange="monLoadChannels(1)"><span>⭐ Watchlist</span></label>
            <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="mon-f-alert" onchange="monLoadChannels(1)"><span>Có cảnh báo</span></label>
          </div>
          <div class="panel">
            <div class="table-wrap mon-table-wrap">
              <table class="data-table">
                <thead><tr><th></th><th>Channel</th><th>Đánh giá</th><th>Chrome</th><th>Proxy</th><th>Tabs</th><th>Giai đoạn</th><th>Ổn định</th><th>Tin cậy</th><th>Kiểm tra</th><th>Cảnh báo</th><th>Theo dõi</th><th>Hành động</th></tr></thead>
                <tbody id="mon-tbody"><tr><td colspan="13"><div class="skeleton"></div></td></tr></tbody>
              </table>
            </div>
          </div>
          <div class="pagination" id="mon-pagination"></div>
        </div>

        <div class="mon-pane hidden" id="mon-pane-alerts">
          <div class="toolbar">
            <select id="mon-a-status" class="filter-select" onchange="monLoadAlerts()"><option value="OPEN">Đang mở</option><option value="RESOLVED">Đã xử lý</option><option value="all">Tất cả</option></select>
            <select id="mon-a-sev" class="filter-select" onchange="monLoadAlerts()"><option value="">Mọi mức</option><option value="CRITICAL">Critical</option><option value="WARNING">Warning</option><option value="INFO">Info</option></select>
            <div class="spacer"></div>
            <button class="btn btn-sm" onclick="monRecheckSelected()">✓ Kiểm tra lại đã chọn</button>
            <button class="btn btn-sm" onclick="monResolveSelected()">Đánh dấu đã xem</button>
          </div>
          <div class="panel"><div class="table-wrap"><table class="data-table">
            <thead><tr><th></th><th>Kênh</th><th>Mức</th><th>Loại</th><th>Nội dung</th><th>Lần đầu</th><th>Lần cuối</th><th>Hành động</th></tr></thead>
            <tbody id="mon-alerts-tbody"><tr><td colspan="8"><div class="skeleton"></div></td></tr></tbody>
          </table></div></div>
        </div>

        <div class="mon-pane hidden" id="mon-pane-trends">
          <div class="toolbar">
            <div class="seg" id="mon-trend-range">
              <button data-days="7" class="active" onclick="monTrendRange(7)">7D</button>
              <button data-days="30" onclick="monTrendRange(30)">30D</button>
              <button data-days="90" onclick="monTrendRange(90)">90D</button>
            </div>
            <select id="mon-trend-metric" class="filter-select" onchange="monLoadTrends()">
              <option value="active">Hoạt động</option>
              <option value="issues">Có vấn đề</option>
              <option value="login">Cần đăng nhập</option>
              <option value="verify">Cần xác minh</option>
              <option value="proxy">Proxy lỗi</option>
              <option value="evalfail">Đánh giá thất bại</option>
            </select>
          </div>
          <div class="panel"><canvas id="mon-chart" height="220"></canvas><div id="mon-trend-empty" class="empty-state hidden">Chưa có đủ snapshot — xu hướng sẽ hiện sau vài giờ theo dõi.</div></div>
        </div>

        <div class="mon-pane hidden" id="mon-pane-history">
          <div class="toolbar">
            <select id="mon-h-cat" class="filter-select" onchange="monLoadHistory(1)"><option value="">Tất cả loại</option><option value="evaluation">Đánh giá</option><option value="runtime">Chrome</option><option value="proxy">Proxy</option><option value="monitoring">Theo dõi</option></select>
            <input type="date" id="mon-h-from" class="filter-select" onchange="monLoadHistory(1)">
            <div class="search-box"><input type="text" id="mon-h-search" placeholder="Lọc theo kênh..." oninput="monLoadHistory(1)"></div>
          </div>
          <div class="panel"><div id="mon-history"><div class="skeleton"></div></div></div>
          <div class="pagination" id="mon-history-pg"></div>
        </div>
      </section>
      <!-- ===== VIEW: NOTIFY (Thông báo & Báo cáo) ===== -->
      <section id="view-notify" class="view">
        <div class="nt-hero">
          <div class="nt-hero-text">
            <div class="nt-hero-title">Thông báo &amp; Báo cáo</div>
            <div class="nt-hero-sub">Trung tâm điều phối Telegram, quy tắc gửi và báo cáo định kỳ.</div>
          </div>
          <span id="nav-notify-count-hero" class="badge badge-danger hidden"></span>
        </div>
        <div class="mon-tabs nt-tabs">
          <button class="mon-tab active" data-ntab="overview" onclick="notifyTab('overview')"><span class="nt-tab-ico">◈</span>Tổng quan</button>
          <button class="mon-tab" data-ntab="telegram" onclick="notifyTab('telegram')"><span class="nt-tab-ico">✈</span>Telegram</button>
          <button class="mon-tab" data-ntab="rules" onclick="notifyTab('rules')"><span class="nt-tab-ico">⚙</span>Quy tắc</button>
          <button class="mon-tab" data-ntab="reports" onclick="notifyTab('reports')"><span class="nt-tab-ico">▤</span>Báo cáo định kỳ</button>
          <button class="mon-tab" data-ntab="history" onclick="notifyTab('history')"><span class="nt-tab-ico">◷</span>Lịch sử gửi</button>
          <button class="mon-tab" data-ntab="chat" onclick="notifyTab('chat')"><span class="nt-tab-ico">✎</span>Chat &amp; Điều khiển</button>
        </div>
        <div class="mon-pane" id="nt-pane-overview">
          <div class="panel nt-panel"><div class="nt-panel-head"><span class="nt-panel-ico">◈</span>Trạng thái hệ thống</div><div id="nt-kpi" class="nt-kpi-grid"><div class="skeleton"></div></div></div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">⬢</span>Worker gửi tin<span id="nt-worker-dot" class="nt-dot"></span></div>
            <div id="nt-worker" class="muted">—</div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button class="btn btn-sm" onclick="notifyWorker(1)">Chạy worker</button>
              <button class="btn btn-sm" onclick="notifyWorker(0)">Dừng worker</button>
            </div>
          </div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-telegram">
          <div class="nt-tg-grid">
            <div>
              <div class="panel nt-panel" id="tg-setup-panel">
                <div class="nt-panel-head"><span class="nt-panel-ico">✈</span>Kết nối Telegram</div>
                <div id="tg-setup-view"><div class="skeleton"></div></div>
              </div>
            </div>
            <div class="panel nt-panel" id="tg-test-panel">
              <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
                <div>
                  <div class="nt-panel-head" style="margin:0"><span class="nt-panel-ico">✎</span>Chat Test</div>
                  <div class="hint" style="margin:2px 0 0">Gửi và nhận tin nhắn trực tiếp để kiểm tra kết nối Telegram.</div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                  <span id="nt-test-status" class="badge badge-muted">…</span>
                  <button type="button" class="btn btn-xs" onclick="ntTestClear()" title="Chỉ dọn màn hình, không xóa dữ liệu">Dọn màn hình</button>
                </div>
              </div>
              <div id="nt-test-new" class="hidden" style="text-align:center;margin:6px 0">
                <button type="button" class="btn btn-xs" onclick="ntTestJump()">↓ <span id="nt-test-new-n">0</span> tin nhắn mới</button>
              </div>
              <div id="nt-test-list" class="chat-list chat-test-list"></div>
              <div style="display:flex;gap:8px;margin-top:8px">
                <textarea id="nt-test-input" rows="1" style="flex:1;resize:vertical;min-height:42px;max-height:120px" placeholder="Nhập tin nhắn để test..."></textarea>
                <button type="button" class="btn btn-sm btn-primary" id="nt-test-send" onclick="ntTestSend()" title="Gửi">➤</button>
              </div>
            </div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">⚙</span>Cấu hình thông báo <span class="summary-text" id="nt-preset-saved"></span></div>
              <label>Preset</label>
              <select id="nt-preset" onchange="notifyPresetApply(this.value)">
                <option value="balanced">Cân bằng</option>
                <option value="minimal">Ít thông báo</option>
                <option value="all">Tất cả</option>
                <option value="custom">Tùy chỉnh</option>
              </select>
              <div class="sync-label" style="margin-top:8px">Thông báo tức thì</div>
              <div class="nt-switch-grid">
                <label><span>Cảnh báo</span><input type="checkbox" id="nt-s-warning" data-ntkey="notify_send_warning"></label>
                <label><span>Lỗi</span><input type="checkbox" id="nt-s-error" data-ntkey="notify_send_error"></label>
                <label><span>Nghiêm trọng</span><input type="checkbox" id="nt-s-critical" data-ntkey="notify_send_critical"></label>
                <label><span>Hồi phục</span><input type="checkbox" id="nt-recovery" data-ntkey="notify_recovery"></label>
              </div>
              <div class="sync-label" style="margin-top:8px">Báo cáo</div>
              <div class="nt-switch-grid">
                <label><span>Batch summary</span><input type="checkbox" id="nt-s-batch" data-ntkey="notify_send_batch"></label>
                <label><span>Cuối ngày</span><input type="checkbox" id="nt-daily" data-ntkey="notify_daily_enabled"></label>
              </div>
              <div class="sync-label" style="margin-top:8px">Khác</div>
              <div class="nt-switch-grid">
                <label><span>INFO</span><input type="checkbox" id="nt-s-info" data-ntkey="notify_send_info"></label>
                <label><span>Success đơn</span><input type="checkbox" id="nt-s-success" data-ntkey="notify_send_success"></label>
              </div>
              <div class="win-row" style="margin-top:8px">
                <div class="win-field"><label>Cuối ngày lúc</label><input type="time" id="nt-daily-time" value="23:00" data-ntkey="notify_daily_time" data-ntval="time"></div>
                <div class="win-field"><label>Bật Telegram</label>
                  <select id="nt-enabled" data-ntkey="notify_telegram_enabled" data-ntval="bool01">
                    <option value="1">ON</option>
                    <option value="0">OFF</option>
                  </select>
                </div>
              </div>
              <div style="margin-top:8px">
                <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="nt-quiet-on" style="width:auto"> Giờ yên tĩnh (CRITICAL vẫn gửi)</label>
                <div class="win-row" id="nt-quiet-row" style="margin-top:6px">
                  <div class="win-field"><label>Từ</label><input type="time" id="nt-quiet-start" value="23:00"></div>
                  <div class="win-field"><label>Đến</label><input type="time" id="nt-quiet-end" value="07:00"></div>
                </div>
              </div>
            </div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-rules">
          <div class="panel nt-panel"><div class="nt-panel-head"><span class="nt-panel-ico">⚙</span>Quy tắc gửi theo sự kiện</div><div id="nt-rules"><div class="skeleton"></div></div></div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-reports">
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">◷</span>Lịch gửi tự động</div>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="nt-r-daily" style="width:auto" data-ntkey="notify_daily_enabled"> Báo cáo ngày lúc <input type="time" id="nt-r-daily-time" value="23:00" style="width:auto" data-ntkey="notify_daily_time" data-ntval="time"></label>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="nt-r-weekly" style="width:auto" data-ntkey="notify_weekly_enabled"> Báo cáo tuần
              <select id="nt-r-weekly-day" style="width:auto" data-ntkey="notify_weekly_day" data-ntval="int17"><option value="1">Thứ 2</option><option value="2">Thứ 3</option><option value="3">Thứ 4</option><option value="4">Thứ 5</option><option value="5">Thứ 6</option><option value="6">Thứ 7</option><option value="7">Chủ nhật</option></select>
              lúc <input type="time" id="nt-r-weekly-time" value="08:00" style="width:auto" data-ntkey="notify_weekly_time" data-ntval="time"></label>
            <div class="hint">Tự lưu khi thay đổi. Không gửi báo cáo trống trừ khi bật "luôn gửi" ở cấu hình nâng cao.</div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">▤</span>Tạo báo cáo tùy chọn</div>
            <div class="win-row">
              <div class="win-field"><label>Từ</label><input type="datetime-local" id="nt-range-start"></div>
              <div class="win-field"><label>Đến</label><input type="datetime-local" id="nt-range-end"></div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button type="button" class="btn btn-sm" onclick="notifyRange()">Tạo báo cáo</button>
              <button type="button" class="btn btn-sm" onclick="notifyRangeSend()">Tạo + Gửi Telegram</button>
            </div>
            <div id="nt-range-out" style="margin-top:8px"></div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">▦</span>Lịch sử báo cáo</div>
            <div id="nt-reports"><div class="skeleton"></div></div>
          </div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-history">
          <div class="panel nt-panel"><div class="nt-panel-head"><span class="nt-panel-ico">◷</span>Lịch sử gửi tin</div><div id="nt-history"><div class="skeleton"></div></div></div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-chat">
          <div class="panel chat-head nt-panel">
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
              <strong>Chat & Điều khiển</strong>
              <span id="nt-chat-status" class="badge badge-muted">…</span>
              <span class="muted" id="nt-chat-peer"></span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
              <button type="button" class="btn btn-xs" id="nt-testchat-btn" onclick="ntChatMode('test')">Test Chat</button>
              <label style="display:flex;gap:6px;align-items:center">Điều khiển Tool qua Telegram
                <input type="checkbox" id="nt-cmd-mode" style="width:auto">
              </label>
              <select id="nt-chat-filter" class="filter-select" style="width:auto" onchange="ntChatFilter(this.value)">
                <option value="all">Tất cả</option>
                <option value="TEXT">Chat</option>
                <option value="COMMAND">Command</option>
                <option value="JOB">Job</option>
                <option value="ALERT">Alert</option>
              </select>
              <button type="button" class="btn btn-xs" onclick="ntChatClearView()" title="Chỉ xóa màn hình, không xóa dữ liệu">Xóa màn hình</button>
            </div>
          </div>
          <div class="panel">
            <div id="nt-chat-new" class="hidden" style="text-align:center;margin-bottom:6px">
              <button type="button" class="btn btn-xs" onclick="ntChatJumpNew()">↓ <span id="nt-chat-new-n">0</span> tin nhắn mới</button>
            </div>
            <div id="nt-chat-list" class="chat-list"></div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <textarea id="nt-chat-input" rows="1" style="flex:1;resize:vertical" placeholder="Nhập tin nhắn... (Enter gửi, Shift+Enter xuống dòng)"></textarea>
              <button type="button" class="btn btn-sm btn-primary" id="nt-chat-send" onclick="ntChatSend()">Gửi</button>
            </div>
          </div>
          <div class="panel"><div id="nt-chat-metrics" class="mon-kpi-grid"><div class="skeleton"></div></div></div>
          <div class="panel">
            <div class="sync-label">Điều khiển từ xa (inbound)</div>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="tg-inbound" style="width:auto"> Cho phép điều khiển Tool từ Telegram</label>
            <div class="hint">Mặc định TẮT. Bật rồi cấu hình chat được phép + ghép nối.</div>
            <label>Danh sách chat được phép (chat_id, user_id tùy chọn, role)</label>
            <div id="tg-allowed-list"></div>
            <div class="win-row">
              <div class="win-field"><label>Chat ID</label><input type="text" id="tg-new-chat" placeholder="123456789"></div>
              <div class="win-field"><label>User ID (tùy chọn)</label><input type="text" id="tg-new-user" placeholder=""></div>
              <div class="win-field"><label>Role</label>
                <select id="tg-new-role"><option value="VIEWER">VIEWER</option><option value="OPERATOR">OPERATOR</option><option value="DEVELOPER">DEVELOPER</option><option value="ADMIN">ADMIN</option></select>
              </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
              <button type="button" class="btn btn-sm" onclick="tgAddAllowed()">+ Thêm chat</button>
              <button type="button" class="btn btn-sm" onclick="tgPairCreate()">Tạo mã ghép nối</button>
              <span class="summary-text" id="tg-pair-out"></span>
            </div>
            <div class="win-row" style="margin-top:6px">
              <div class="win-field"><label>Role mặc định cho chat mới ghép nối</label>
                <select id="tg-default-role"><option value="VIEWER">VIEWER</option><option value="OPERATOR">OPERATOR</option><option value="DEVELOPER">DEVELOPER</option><option value="ADMIN">ADMIN</option></select>
              </div>
              <div class="win-field"><label>Polling</label><div id="tg-poll-status" class="muted">—</div></div>
            </div>
            <div style="display:flex;gap:8px;margin-top:6px">
              <button type="button" class="btn btn-sm" onclick="tgSaveInbound()">Lưu inbound</button>
              <button type="button" class="btn btn-sm" onclick="tgReceiverRestart()">Restart receiver</button>
              <button type="button" class="btn btn-sm" onclick="tgJob(1)">Chạy job worker</button>
              <button type="button" class="btn btn-sm" onclick="tgJob(0)">Dừng job worker</button>
            </div>
          </div>
          <div class="panel">
            <div class="sync-label">Nhật ký lệnh (audit)</div>
            <div id="nt-audit"><div class="skeleton"></div></div>
          </div>
          <div class="panel">
            <div class="sync-label">Quy tắc lệnh (role/xác nhận)</div>
            <div id="nt-cmdrules"><div class="skeleton"></div></div>
          </div>
        </div>
      </section>
      <!-- ===== VIEW: PROXIES ===== -->
      <section id="view-proxies" class="view">
        <div class="toolbar">
          <button class="btn btn-primary" onclick="openProxyModal()">+ Thêm proxy</button>
          <button class="btn" onclick="importProxies()">Nhập hàng loạt</button>
          <button class="btn" onclick="testAllProxies()">Test tất cả</button>
          <div class="sel-divider"></div>
          <label class="sel-all-label"><span class="ck"><input type="checkbox" id="sel-all-proxies" onchange="toggleSelectAllProxies(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span> Chọn tất cả</label>
          <button class="btn btn-sm" onclick="openBulkEditProxies()">✎ Sửa hàng loạt</button>
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

            <div class="win-group-title">Phiên tab (Tab Session)</div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-tab-autosave" checked><span>Tự lưu tabs</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-tab-autorestore" checked><span>Tự khôi phục tabs</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-tab-active" checked><span>Nhớ tab đang active</span></label>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Autosave mỗi (giây, 10–3600)</label><input type="number" id="set-tab-interval" min="10" max="3600" value="30"></div>
            </div>

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
            <div class="win-group-title">Chạy đánh giá</div>
            <div class="win-row">
              <div class="win-field"><label>Đồng thời (2/4/6/8)</label>
                <select id="set-acc-concurrency"><option value="2">2</option><option value="4">4</option><option value="6">6</option><option value="8">8</option></select>
              </div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-autostart"><span>Tự mở Chrome khi đánh giá (mặc định: yêu cầu Chrome chạy)</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-closeafter"><span>Đóng lại sau khi kiểm tra (nếu trước đó đang dừng)</span></label>
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
      <button class="modal-close" onclick="cancelProfileModal()" title="Đóng">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pf-id">
      <div class="pf-mode-tabs" id="pf-mode-tabs">
        <button type="button" class="btn btn-sm pf-mode-btn active" id="pf-mode-single" onclick="setProfileMode('single')">Tạo đơn</button>
        <button type="button" class="btn btn-sm pf-mode-btn" id="pf-mode-bulk" onclick="setProfileMode('bulk')">Tạo nhiều</button>
      </div>

      <div id="pf-single-fields">
        <div class="form-group-title">Thông tin kênh</div>
        <label>Tên kênh *</label>
        <input type="text" id="pf-name" placeholder="Ví dụ: Kênh Ẩm Thực">
        <label>Channel Handle (tùy chọn)</label>
        <input type="text" id="pf-handle" placeholder="Ví dụ: @kênh-ẩm-thực hoặc channel ID">
        <div class="form-group-title">Trình duyệt</div>
        <label>User-Agent (để trống = tự động đề xuất)</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="text" id="pf-ua" style="flex:1" placeholder="VD: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36...">
          <button type="button" class="btn btn-sm" onclick="randomUA()" title="Tạo User-Agent ngẫu nhiên">🎲 Tự động</button>
        </div>
        <div class="hint" id="pf-launch-note"></div>
        <label>WebRTC (chống lộ IP)</label>
        <select id="pf-webrtc">
          <option value="default">Mặc định (không can thiệp)</option>
          <option value="disable_nonproxied_udp">Chỉ cho UDP qua proxy (đề xuất)</option>
        </select>
        <div class="form-group-title">Tự động hoạt động</div>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="act-enabled" style="width:auto"> Bật Auto Activity</label>
        <div id="act-fields">
          <div class="win-row">
            <div class="win-field"><label>Hoạt động trong: từ</label><input type="time" id="act-start" value="08:00"></div>
            <div class="win-field"><label>đến</label><input type="time" id="act-end" value="22:00"></div>
          </div>
          <div class="win-row">
            <div class="win-field"><label>Chu kỳ</label>
              <select id="act-interval">
                <option value="15">15 phút</option>
                <option value="30" selected>30 phút</option>
                <option value="60">60 phút</option>
                <option value="120">2 giờ</option>
              </select>
            </div>
            <div class="win-field"><label>Tối đa tab tự động</label>
              <select id="act-maxtabs">
                <option value="3">3</option>
                <option value="5" selected>5</option>
                <option value="10">10</option>
              </select>
            </div>
          </div>
          <label>Tab cần duy trì</label>
          <div id="act-presets" style="display:flex;gap:12px;flex-wrap:wrap">
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="gmail" style="width:auto"> Gmail</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="google" style="width:auto"> Google</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="youtube" style="width:auto"> YouTube</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="drive" style="width:auto"> Drive</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="calendar" style="width:auto"> Calendar</label>
          </div>
          <div id="act-custom-list" style="margin-top:6px"></div>
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>Tên trang</label><input type="text" id="act-custom-label" placeholder="Google News"></div>
            <div class="win-field" style="flex:2"><label>URL</label><input type="text" id="act-custom-url" placeholder="https://news.google.com/"></div>
          </div>
          <button type="button" class="btn btn-sm" onclick="actAddCustom()" style="margin-top:4px">+ Thêm URL</button>
          <label style="margin-top:8px">Search list (mỗi dòng một query)</label>
          <textarea id="act-queries" rows="3" style="width:100%" placeholder="tin công nghệ&#10;hướng dẫn Excel"></textarea>
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>Chế độ</label>
              <select id="act-mode">
                <option value="maintain">Chỉ duy trì tab</option>
                <option value="search">Tab + tìm kiếm</option>
                <option value="full">Đầy đủ</option>
              </select>
            </div>
            <div class="win-field"><label>Mẫu (template)</label>
              <select id="act-template" onchange="actTemplateApply()">
                <option value="LIGHT">LIGHT — nhẹ</option>
                <option value="NORMAL" selected>NORMAL — cân bằng</option>
                <option value="HIGH">HIGH — dày</option>
                <option value="CUSTOM">CUSTOM — tự chỉnh</option>
              </select>
            </div>
            <div class="win-field"><label>Tạm dừng</label>
              <select id="act-pause">
                <option value="off">Không</option>
                <option value="1h">Pause 1 giờ</option>
                <option value="today">Pause hôm nay</option>
              </select>
            </div>
          </div>
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>Sessions/ngày</label>
              <div style="display:flex;gap:6px;align-items:center">
                <input type="number" id="act-sess-min" value="6" min="1" max="24" style="width:100%">
                <span class="muted">–</span>
                <input type="number" id="act-sess-max" value="10" min="1" max="24" style="width:100%">
              </div>
            </div>
            <div class="win-field"><label>Ngày hoạt động</label>
              <div id="act-days" style="display:flex;gap:6px;flex-wrap:wrap">
                <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" data-day="1" style="width:auto" checked>2</label>
                <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" data-day="2" style="width:auto" checked>3</label>
                <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" data-day="3" style="width:auto" checked>4</label>
                <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" data-day="4" style="width:auto" checked>5</label>
                <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" data-day="5" style="width:auto" checked>6</label>
                <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" data-day="6" style="width:auto" checked>7</label>
                <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" data-day="7" style="width:auto" checked>CN</label>
              </div>
            </div>
          </div>
          <div class="form-group-title" style="margin-top:10px">Lịch hôm nay</div>
          <div id="act-plan"><div class="skeleton"></div></div>
          <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
            <button type="button" class="btn btn-sm" onclick="actRunSession()">▶ Chạy session ngay</button>
            <button type="button" class="btn btn-sm" onclick="actRegen()">↻ Tạo lại lịch còn lại</button>
          </div>
          <div class="form-group-title" style="margin-top:10px">Pool chung (mọi kênh)</div>
          <div class="hint">Website Pool + Search Pool dùng chung cho OPEN_RANDOM_WEBSITE và GOOGLE_SEARCH.</div>
          <div id="act-pool-web"><div class="skeleton"></div></div>
          <div style="display:flex;gap:6px;margin-top:6px">
            <input type="text" id="act-web-url" placeholder="https://..." style="flex:1">
            <button type="button" class="btn btn-sm" onclick="actWebAdd()">+ Thêm</button>
          </div>
          <div style="display:flex;gap:6px;margin-top:6px">
            <textarea id="act-web-import" rows="2" style="flex:1" placeholder="Import nhiều URL (mỗi dòng 1 URL, hoặc Tên | URL)"></textarea>
            <button type="button" class="btn btn-sm" onclick="actWebImport()">Nhập</button>
          </div>
          <div id="act-pool-search" style="margin-top:8px"><div class="skeleton"></div></div>
          <div style="display:flex;gap:6px;margin-top:6px">
            <textarea id="act-search-import" rows="2" style="flex:1" placeholder="Import nhiều query (mỗi dòng 1 query)"></textarea>
            <button type="button" class="btn btn-sm" onclick="actSearchImport()">Nhập</button>
          </div>
          <label style="display:flex;gap:8px;align-items:center;margin-top:6px"><input type="checkbox" id="act-maintain" style="width:auto"> Luôn duy trì tab (tạo lại sau debounce)</label>
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="act-autostart" style="width:auto"> Cho phép tự mở profile tới lịch</label>
          <div class="hint">Tab automation chạy nền, không activate, không giành focus. Không click quảng cáo/CAPTCHA.</div>
          <div style="display:flex;gap:8px;margin-top:6px">
            <button type="button" class="btn btn-sm" onclick="actSave()">Lưu Auto Activity</button>
            <button type="button" class="btn btn-sm" onclick="actRunNow()">Chạy ngay</button>
          </div>
          <div class="hint" id="act-save-note"></div>
        </div>
      </div>

      <div id="pf-bulk-fields" class="hidden">
        <label>Tiền tố tên kênh</label>
        <input type="text" id="pf-prefix" placeholder="Mặc định: Kênh" value="Kênh">
        <div class="hint">Tên sẽ là <strong>[Tiền tố] STT</strong>, VD: Kênh 1, Kênh 2...</div>
        <label>Số lượng kênh *</label>
        <input type="number" id="pf-count" value="5" min="1" max="200">
      </div>

      <div id="pf-common-fields">
        <label>Nền tảng</label>
        <select id="pf-platform">
          <option value="youtube">YouTube</option>
          <option value="tiktok">TikTok</option>
          <option value="facebook">Facebook</option>
          <option value="other">Khác</option>
        </select>
        <div class="form-group-title">Kết nối</div>
        <label>Proxy</label>
        <div class="seg seg-3" id="pf-proxy-modes">
          <button type="button" data-pmode="NONE" onclick="setProxyMode('NONE')">Không dùng</button>
          <button type="button" data-pmode="SAVED" onclick="setProxyMode('SAVED')">Kho proxy</button>
          <button type="button" data-pmode="MANUAL" onclick="setProxyMode('MANUAL')">Nhập thủ công</button>
        </div>
        <div id="pf-proxy-saved-wrap">
          <select id="pf-proxy" style="margin-top:6px"><option value="">Không dùng proxy</option></select>
        </div>
        <div id="pf-proxy-manual-wrap" class="hidden">
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>Protocol</label>
              <select id="pf-proxy-proto">
                <option value="http">HTTP</option>
                <option value="https">HTTPS</option>
                <option value="socks4">SOCKS4</option>
                <option value="socks5" selected>SOCKS5</option>
              </select>
            </div>
            <div class="win-field" style="flex:2"><label>Proxy</label>
              <div style="display:flex;gap:8px;align-items:center">
                <input type="text" id="pf-proxy-text" style="flex:1" placeholder="host:port hoặc user:pass@host:port...">
                <button type="button" class="btn btn-sm" onclick="pasteProxy()" title="Dán từ clipboard">📋</button>
              </div>
            </div>
          </div>
          <div class="form-error hidden" id="pf-proxy-error"></div>
          <div class="win-row" style="margin-top:8px">
            <button type="button" class="btn btn-sm" id="pf-proxy-test-btn" onclick="testManualProxy()">⚡ Kiểm tra proxy</button>
            <span class="summary-text" id="pf-proxy-test-result"></span>
          </div>
          <button type="button" class="link-btn" id="pf-proxy-adv-toggle" onclick="toggleProxyAdvanced()">Cấu hình chi tiết ▾</button>
          <div id="pf-proxy-adv" class="hidden">
            <div class="win-row">
              <div class="win-field"><label>Host</label><input type="text" id="pf-px-host"></div>
              <div class="win-field" style="flex:0 0 110px"><label>Port</label><input type="number" id="pf-px-port" min="1" max="65535"></div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Username</label><input type="text" id="pf-px-user"></div>
              <div class="win-field"><label>Password</label>
                <div style="display:flex;gap:8px;align-items:center">
                  <input type="password" id="pf-px-pass" style="flex:1">
                  <button type="button" class="btn btn-sm" onclick="toggleProxyPass()" title="Hiện/ẩn mật khẩu">👁</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="hint" id="pf-proxy-note"></div>
        <div class="form-group-title">Hiển thị</div>
        <label>Màn hình</label>
        <select id="pf-monitor-mode" onchange="pfRefreshMonitorHint();profileDraftChanged()">
          <option value="LAST">Nhớ màn hình lần cuối</option>
          <option value="AUTO">Tự động</option>
          <option value="FIXED">Màn hình cố định…</option>
        </select>
        <select id="pf-monitor-fixed" class="hidden" style="margin-top:6px"></select>
        <div class="hint" id="pf-monitor-hint"></div>
      </div>
      <div class="form-error hidden" id="pf-error"></div>
    </div>
    <div class="modal-footer">
      <span class="summary-text" id="pf-dirty-hint"></span>
      <button class="btn" onclick="cancelProfileModal()">Hủy</button>
      <button class="btn btn-primary hidden" id="pf-create-btn" onclick="saveProfile()">Tạo kênh</button>
      <button class="btn btn-primary" id="pf-save-btn" onclick="saveProfile()">Lưu thay đổi</button>
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

<!-- ===== MODAL: Sửa proxy hàng loạt ===== -->
<div id="bulk-edit-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2>Sửa proxy hàng loạt</h2>
      <button class="modal-close" onclick="closeModal('bulk-edit-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="hint" id="bulk-edit-count" style="margin-top:0"></div>
      <div class="hint">Để trống = giữ nguyên. Chỉ field có giá trị mới bị ghi đè.</div>
      <div class="win-row">
        <div class="win-field"><label>Loại proxy</label>
          <select id="be-protocol">
            <option value="keep">Giữ nguyên</option>
            <option value="http">HTTP</option>
            <option value="socks4">SOCKS4</option>
            <option value="socks5">SOCKS5</option>
            <option value="ssh">SSH</option>
          </select>
        </div>
        <div class="win-field"><label>Quốc gia (mã 2 chữ)</label><input type="text" id="be-country" maxlength="2" placeholder="US"></div>
      </div>
      <div class="win-row">
        <div class="win-field"><label>Username</label><input type="text" id="be-user" placeholder="Để trống = giữ nguyên"></div>
        <div class="win-field"><label>Password</label><input type="text" id="be-pass" placeholder="Để trống = giữ nguyên"></div>
      </div>
      <label>Thay host:port theo danh sách (dòng i → proxy thứ i, dòng lỗi/trống thì proxy đó giữ nguyên)</label>
      <textarea id="be-lines" rows="5" placeholder="1.2.3.4:8080&#10;socks5://5.6.7.8:1080:user:pass"></textarea>
      <div class="hint">Dòng có user:pass thì đổi luôn auth. Dòng có prefix protocol thì đổi luôn loại.</div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('bulk-edit-modal')">Hủy</button>
      <button class="btn btn-primary" id="bulk-edit-save-btn" onclick="saveBulkEditProxies()">Lưu thay đổi</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Bulk Auto Activity ===== -->
<div id="bulk-act-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2>Gán Auto Activity hàng loạt</h2>
      <button class="modal-close" onclick="closeModal('bulk-act-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="hint" id="bulk-act-count" style="margin-top:0"></div>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="bact-enabled" style="width:auto" checked> Bật Auto Activity</label>
      <div class="win-field" style="margin-top:6px"><label>Mẫu (template)</label>
        <select id="bact-template">
          <option value="">Giữ nguyên</option>
          <option value="LIGHT">LIGHT — nhẹ</option>
          <option value="NORMAL">NORMAL — cân bằng</option>
          <option value="HIGH">HIGH — dày</option>
        </select>
      </div>
      <div class="win-row">
        <div class="win-field"><label>Hoạt động trong: từ</label><input type="time" id="bact-start" value="08:00"></div>
        <div class="win-field"><label>đến</label><input type="time" id="bact-end" value="22:00"></div>
      </div>
      <div class="win-row">
        <div class="win-field"><label>Chu kỳ</label>
          <select id="bact-interval">
            <option value="15">15 phút</option>
            <option value="30" selected>30 phút</option>
            <option value="60">60 phút</option>
            <option value="120">2 giờ</option>
          </select>
        </div>
        <div class="win-field"><label>Chế độ</label>
          <select id="bact-mode">
            <option value="maintain">Chỉ duy trì tab</option>
            <option value="search">Tab + tìm kiếm</option>
            <option value="full">Đầy đủ</option>
          </select>
        </div>
      </div>
      <label>Tab cần duy trì</label>
      <div id="bact-presets" style="display:flex;gap:12px;flex-wrap:wrap">
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="gmail" style="width:auto" checked> Gmail</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="google" style="width:auto" checked> Google</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="youtube" style="width:auto"> YouTube</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="drive" style="width:auto"> Drive</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="calendar" style="width:auto"> Calendar</label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('bulk-act-modal')">Hủy</button>
      <button class="btn btn-primary" onclick="actBulkSave()">Gán cho đã chọn</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Telegram Bot ===== -->
<div id="tg-bot-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="tg-bot-title">Thêm Bot Telegram</h2>
      <button class="modal-close" onclick="closeModal('tg-bot-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="tg-bot-id" value="">
      <label>Tên kết nối</label>
      <input type="text" id="tg-bot-name" placeholder="Telegram Bot">
      <label>Bot Token</label>
      <input type="password" id="tg-bot-token" placeholder="123456:ABC-DEF..." autocomplete="off">
      <div class="hint" id="tg-bot-note"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('tg-bot-modal')">Hủy</button>
      <button class="btn btn-primary" onclick="tgBotSave()">Kiểm tra & Lưu</button>
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
        <textarea id="bulk-proxy-list" rows="6" oninput="previewBulkProxyList()" placeholder="host:port&#10;host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port"></textarea>
        <div class="win-row">
          <div class="win-field"><label>Loại proxy cho dòng không ghi rõ</label>
            <select id="bulk-proxy-protocol" onchange="previewBulkProxyList()">
              <option value="http">HTTP</option>
              <option value="socks4">SOCKS4</option>
              <option value="socks5">SOCKS5</option>
              <option value="ssh">SSH</option>
            </select>
          </div>
          <div class="win-field"><label>&nbsp;</label><div class="summary-text" id="bulk-proxy-preview"></div></div>
        </div>
        <div class="hint">Dòng có prefix (vd socks5://…) giữ theo prefix. Gán theo thứ tự: kênh 1 ← dòng 1… Proxy chưa có sẽ tự thêm. Ít proxy hơn số kênh thì các kênh cuối giữ nguyên.</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('bulk-proxy-modal')">Hủy</button>
      <button class="btn btn-primary" id="bulk-proxy-save-btn" onclick="saveBulkProxy()">Gán</button>
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

<script src="assets/js/app.js?v=20260921i"></script>
<script src="assets/js/monitoring.js?v=20260921a"></script>
<script src="assets/js/notify.js?v=20260924a"></script>
<script src="assets/js/controlcenter.js?v=20260924a"></script>
<script src="assets/js/aidev.js?v=20260924a"></script>
</body>
</html>