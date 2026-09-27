<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YT Manager - Quáº£n lÃ½ kÃªnh Ä‘a proxy</title>
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
      <div class="nav-label">Quáº£n lÃ½</div>
      <button class="nav-btn active" data-view="dashboard" data-tip="Tá»•ng quan">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        <span>Tá»•ng quan</span>
      </button>
      <button class="nav-btn" data-view="controlcenter" data-tip="Trung tÃ¢m Ä‘iá»u hÃ nh">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 2 1 21h22L12 2zm0 4.2L19.5 19h-15L12 6.2zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/></svg>
        <span>Trung tÃ¢m Ä‘iá»u hÃ nh</span>
        <span id="nav-cc-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="aidev" data-tip="AI Dev Console">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C7.8 12.16 7 10.63 7 9c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.63-.8 3.16-2.15 4.1z"/></svg>
        <span>AI Dev</span>
        <span id="nav-ai-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="profiles" data-tip="KÃªnh">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        <span>KÃªnh</span>
        <span id="nav-profile-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="monitoring" data-tip="Thá»‘ng kÃª & Theo dÃµi">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 3v18h18v-2H5V3H3zm4 12v-6h3v6H7zm5 0V7h3v8h-3zm5 0v-4h3v4h-3z"/></svg>
        <span>Thá»‘ng kÃª & Theo dÃµi</span>
        <span id="nav-alert-count" class="nav-count hidden"></span>
      </button>
      <button class="nav-btn" data-view="notify" data-tip="ThÃ´ng bÃ¡o & BÃ¡o cÃ¡o">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5S10.5 3.17 10.5 4v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
        <span>ThÃ´ng bÃ¡o</span>
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
      <button class="nav-btn" data-view="logs" data-tip="Nháº­t kÃ½">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
        <span>Nháº­t kÃ½</span>
      </button>
    </nav>

    <div class="side-footer">
      <button class="nav-btn" data-view="settings" data-tip="CÃ i Ä‘áº·t">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
        <span>CÃ i Ä‘áº·t</span>
      </button>
      <div class="side-version">v1.0 Â· Hidemium-style</div>
    </div>
  </aside>

  <!-- ===== MAIN ===== -->
  <main class="main">
    <header class="topbar">
      <h2 id="view-title">Tá»•ng quan</h2>
      <div class="topbar-right">
        <button class="btn btn-sm btn-icon" id="btn-menu" title="Má»Ÿ menu">
          <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
        </button>
        <button class="btn btn-sm" id="btn-refresh" title="LÃ m má»›i dá»¯ liá»‡u">
          <span class="refresh-spin">âŸ³</span><span class="lbl"> LÃ m má»›i</span>
        </button>
        <button class="btn btn-sm btn-icon" id="btn-theme" title="Äá»•i ná»n sÃ¡ng/tá»‘i">ðŸŒ™</button>
        <span id="server-status" class="badge badge-warn">Äang kiá»ƒm tra...</span>
      </div>
    </header>

    <div class="content">

      <!-- ===== VIEW: DASHBOARD ===== -->
      <section id="view-dashboard" class="view active">
        <div class="stat-grid" id="stat-grid">
          <div class="stat-card"><div class="stat-value" id="stat-profiles">-</div><div class="stat-label">Tá»•ng kÃªnh</div></div>
          <div class="stat-card"><div class="stat-value green" id="stat-running">-</div><div class="stat-label">Äang cháº¡y</div></div>
          <div class="stat-card"><div class="stat-value" id="stat-proxies">-</div><div class="stat-label">Proxy</div></div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Hoáº¡t Ä‘á»™ng gáº§n Ä‘Ã¢y</h3><button class="btn btn-sm" onclick="switchView('logs')">Xem táº¥t cáº£</button></div>
          <table class="data-table">
            <thead><tr><th>Thá»i gian</th><th>KÃªnh</th><th>HÃ nh Ä‘á»™ng</th><th>Chi tiáº¿t</th></tr></thead>
            <tbody id="dash-logs-tbody"></tbody>
          </table>
        </div>
      </section>

      <!-- ===== VIEW: CONTROL CENTER (Trung tÃ¢m Ä‘iá»u hÃ nh) ===== -->
      <section id="view-controlcenter" class="view">
        <div class="cc-hero">
          <div class="cc-hero-text">
            <div class="cc-hero-title">Trung tÃ¢m Ä‘iá»u hÃ nh</div>
            <div class="cc-hero-sub">Theo dÃµi há»‡ thá»‘ng, cÃ´ng viá»‡c vÃ  cáº£nh bÃ¡o theo thá»i gian thá»±c</div>
          </div>
          <div class="cc-hero-right">
            <span id="cc-live" class="badge badge-ok"><span class="nt-dot on"></span> Realtime</span>
            <span id="cc-overall" class="badge badge-muted">â€¦</span>
            <button type="button" class="btn btn-sm" onclick="ccRefresh(true)">â†» LÃ m má»›i</button>
          </div>
        </div>
        <div class="cc-kpis" id="cc-kpis">
          <div class="skeleton"></div>
        </div>
        <div class="cc-grid">
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">â–¶</span>CÃ´ng viá»‡c Ä‘ang cháº¡y
                <button type="button" class="btn btn-xs" onclick="ccJobsToggle()" id="cc-jobs-toggle">Xem táº¥t cáº£ cÃ´ng viá»‡c</button>
              </div>
              <div id="cc-jobs"><div class="skeleton"></div></div>
              <div id="cc-jobs-all" class="hidden">
                <div class="cc-filters">
                  <select id="cc-f-module" onchange="ccJobsLoad()"><option value="all">Má»i module</option><option>EVALUATION</option><option>BROWSER</option><option>AUTO_ACTIVITY</option><option>PROXY</option><option>SYSTEM</option></select>
                  <select id="cc-f-status" onchange="ccJobsLoad()"><option value="ACTIVE">Äang hoáº¡t Ä‘á»™ng</option><option value="all">Táº¥t cáº£</option><option>QUEUED</option><option>RUNNING</option><option>PAUSED</option><option>SUCCESS</option><option>PARTIAL</option><option>FAILED</option><option>CANCELLED</option><option>INTERRUPTED</option></select>
                  <select id="cc-f-source" onchange="ccJobsLoad()"><option value="all">Má»i nguá»“n</option><option>UI</option><option>TELEGRAM</option><option>SCHEDULER</option><option>SYSTEM</option><option>API</option></select>
                </div>
                <div id="cc-jobs-list"><div class="skeleton"></div></div>
              </div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">âš </span>Cáº§n chÃº Ã½ <span id="cc-alert-n" class="badge badge-muted">0</span></div>
              <div id="cc-alerts"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">â™¥</span>Sá»©c khá»e há»‡ thá»‘ng</div>
              <div id="cc-health"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">â—·</span>Lá»‹ch sáº¯p tá»›i</div>
              <div id="cc-sched"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">âš¡</span>Thao tÃ¡c nhanh</div>
              <div class="cc-actions">
                <button type="button" class="btn btn-sm" onclick="ccQuick('BROWSER_START')">â–¶ Má»Ÿ kÃªnh</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('BROWSER_STOP')">â–  ÄÃ³ng kÃªnh</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('EVALUATION')">âœ“ ÄÃ¡nh giÃ¡</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('PROXY_CHECK')">âŒ– Kiá»ƒm tra Proxy</button>
                <button type="button" class="btn btn-sm" onclick="ccQuick('AUTO_ACTIVITY')">â—· Activity</button>
                <button type="button" class="btn btn-sm" onclick="ccSchedOpen()">ï¼‹ Táº¡o lá»‹ch</button>
              </div>
            </div>
          </div>
        </div>
        <div class="cc-grid">
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">â—”</span>TÃ i nguyÃªn</div>
              <div id="cc-res"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">â˜°</span>Hoáº¡t Ä‘á»™ng gáº§n Ä‘Ã¢y</div>
              <div id="cc-activity"><div class="skeleton"></div></div>
            </div>
          </div>
        </div>
        <div id="cc-drawer" class="cc-drawer hidden">
          <div class="cc-drawer-head">
            <strong id="cc-drawer-title">Job</strong>
            <button type="button" class="modal-close" onclick="ccDrawerClose()">Ã—</button>
          </div>
          <div id="cc-drawer-body" class="cc-drawer-body"><div class="skeleton"></div></div>
        </div>
        <div id="cc-modal" class="modal-overlay hidden">
          <div class="modal">
            <div class="modal-header"><strong id="cc-modal-title">Thao tÃ¡c</strong>
              <button type="button" class="modal-close" onclick="ccModalClose()">Ã—</button>
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
            <div class="cc-hero-sub">Há»i project, láº­p phÆ°Æ¡ng Ã¡n vÃ  code cÃ¹ng OpenCode â€” duyá»‡t trÆ°á»›c khi Ã¡p dá»¥ng</div>
          </div>
          <div class="cc-hero-right">
            <span id="ai-oc-state" class="badge badge-muted">â€¦</span>
            <button type="button" class="btn btn-sm" onclick="aiRefresh(true)">â†» LÃ m má»›i</button>
          </div>
        </div>
        <div class="mon-tabs nt-tabs" style="margin-bottom:14px">
          <button class="mon-tab active" data-aitab="overview" onclick="aiTab('overview')"><span class="nt-tab-ico">â—ˆ</span>Tá»•ng quan</button>
          <button class="mon-tab" data-aitab="brain" onclick="aiTab('brain')"><span class="nt-tab-ico">ðŸ§ </span>Project Brain</button>
          <button class="mon-tab" data-aitab="diag" onclick="aiTab('diag')"><span class="nt-tab-ico">ðŸ”Ž</span>Cháº©n Ä‘oÃ¡n</button>
          <button class="mon-tab" data-aitab="know" onclick="aiTab('know')"><span class="nt-tab-ico">ðŸ“š</span>Kiáº¿n thá»©c</button>
          <button class="mon-tab" data-aitab="settings" onclick="aiTab('settings')"><span class="nt-tab-ico">âš™</span>CÃ i Ä‘áº·t</button>
        </div>
        <div id="ai-pane-overview">
        <div class="cc-grid">
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">âœ¦</span>OpenCode</div>
              <div id="ai-oc"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">ðŸ› </span>Dev Jobs Ä‘ang hoáº¡t Ä‘á»™ng</div>
              <div id="ai-jobs"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="cc-col">
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">ðŸ§ </span>Project Brain</div>
              <div id="ai-brain-mini"><div class="skeleton"></div></div>
            </div>
            <div class="panel nt-panel">
              <div class="nt-panel-head"><span class="nt-panel-ico">â–¦</span>Project</div>
              <div id="ai-project"><div class="skeleton"></div></div>
            </div>
          </div>
        </div>
        </div>
        <div id="ai-pane-brain" class="hidden">
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">ðŸ§ </span>Tra cá»©u symbol</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <input type="text" id="ai-brain-q" placeholder="VD: TelegramSupervisor" style="flex:1" onkeydown="if(event.key==='Enter')aiBrainSearch()">
              <button type="button" class="btn btn-sm btn-primary" onclick="aiBrainSearch()">TÃ¬m</button>
              <button type="button" class="btn btn-sm" onclick="aiBrainIndex(false)">Index thay Ä‘á»•i</button>
              <button type="button" class="btn btn-sm" onclick="aiBrainIndex(true)">Index láº¡i</button>
            </div>
            <div id="ai-brain-out" style="margin-top:10px"><div class="hint">Nháº­p tÃªn class/function Ä‘á»ƒ xem file, callers, dependencies.</div></div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">â–¦</span>Module Map</div>
            <div id="ai-modules"><div class="skeleton"></div></div>
          </div>
        </div>
        <div id="ai-pane-diag" class="hidden">
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">ðŸ”Ž</span>Cháº©n Ä‘oÃ¡n má»›i</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <input type="text" id="ai-diag-q" placeholder="VD: táº¡i sao Telegram polling láº¡i cháº¿t" style="flex:1" onkeydown="if(event.key==='Enter')aiDiagCreate()">
              <button type="button" class="btn btn-sm btn-primary" onclick="aiDiagCreate()">Cháº©n Ä‘oÃ¡n</button>
            </div>
            <div id="ai-diag-out" style="margin-top:10px"></div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">â—·</span>Cháº©n Ä‘oÃ¡n gáº§n Ä‘Ã¢y</div>
            <div id="ai-diags"><div class="skeleton"></div></div>
          </div>
        </div>
        <div id="ai-pane-know" class="hidden">
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">ðŸ“š</span>Kiáº¿n thá»©c &amp; Known issues</div>
            <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
              <select id="ai-know-filter" onchange="aiKnowLoad()">
                <option value="">Táº¥t cáº£ loáº¡i</option>
                <option>INVARIANT</option><option>PROJECT_RULE</option><option>ARCHITECTURE_DECISION</option>
                <option>KNOWN_BUG</option><option>BUG_FIX</option><option>LESSON_LEARNED</option>
                <option>TECH_DEBT</option><option>TEST_REQUIREMENT</option><option>MODULE_DESCRIPTION</option>
              </select>
              <button type="button" class="btn btn-sm" onclick="aiKnowAdd()">+ ThÃªm</button>
            </div>
            <div id="ai-knows"><div class="skeleton"></div></div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">âš </span>Bug signatures</div>
            <div id="ai-issues"><div class="skeleton"></div></div>
          </div>
        </div>
        <div id="ai-pane-settings" class="hidden">
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">âš™</span>Cáº¥u hÃ¬nh AI</div>
            <div id="ai-config"><div class="skeleton"></div></div>
          </div>
        </div>
        <div id="ai-drawer" class="cc-drawer hidden">
          <div class="cc-drawer-head">
            <strong id="ai-drawer-title">Dev Job</strong>
            <button type="button" class="modal-close" onclick="aiDrawerClose()">Ã—</button>
          </div>
          <div id="ai-drawer-body" class="cc-drawer-body"><div class="skeleton"></div></div>
        </div>
      </section>

      <!-- ===== VIEW: PROFILES ===== -->
      <section id="view-profiles" class="view">
        <div class="toolbar">
          <button class="btn btn-primary" onclick="openProfileModal()">
            <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Táº¡o kÃªnh
          </button>
          <button class="btn btn-sm tb-sec" id="btn-open-all" onclick="openAllProfiles()">Má»Ÿ táº¥t cáº£</button>
          <button class="btn btn-sm tb-sec" id="btn-close-all" onclick="closeAllProfiles()">ÄÃ³ng táº¥t cáº£</button>
          <div class="sel-divider"></div>
          <label class="sel-all-label"><span class="ck"><input type="checkbox" id="sel-all" onchange="toggleSelectAll(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span> Chá»n táº¥t cáº£</label>
          <button class="btn btn-sm" onclick="openSelected()">â–¶ Má»Ÿ Ä‘Ã£ chá»n</button>
          <button class="btn btn-sm" onclick="closeSelected()">â–  ÄÃ³ng Ä‘Ã£ chá»n</button>
          <button class="btn btn-sm tb-sec" onclick="assignProxySelected()">â‡„ GÃ¡n proxy</button>
          <div class="dropdown">
            <div class="split-btn">
              <button class="btn btn-sm tb-sec" id="btn-eval-bulk" onclick="evaluateSelected()" title="ÄÃ¡nh giÃ¡ cÃ¡c kÃªnh Ä‘Ã£ chá»n">âœ“ ÄÃ¡nh giÃ¡</button>
              <button class="btn btn-sm tb-sec split-arrow" onclick="toggleEvalMenu(event)" title="Cháº¿ Ä‘á»™ Ä‘Ã¡nh giÃ¡">â–¼</button>
            </div>
            <div class="dropdown-menu hidden" id="eval-menu">
              <button onclick="evaluateSelected()">âœ“ ÄÃ¡nh giÃ¡ Ä‘Ã£ chá»n <span class="muted" id="eval-menu-count"></span></button>
              <button onclick="evaluateAll()">âœ“ ÄÃ¡nh giÃ¡ táº¥t cáº£</button>
              <button onclick="evaluateDue()">âœ“ ÄÃ¡nh giÃ¡ kÃªnh cáº§n cáº­p nháº­t</button>
            </div>
          </div>
          <button class="btn btn-sm tb-sec hidden" id="btn-eval-cancel" onclick="evalCancelBatch()" title="Há»§y batch Ä‘ang cháº¡y">âœ• Há»§y</button>
          <button class="btn btn-sm tb-sec" onclick="actBulkOpen()" title="GÃ¡n Auto Activity cho cÃ¡c kÃªnh Ä‘Ã£ chá»n">â—· Auto</button>
          <button class="btn btn-sm btn-danger tb-sec" onclick="deleteSelected()">ðŸ—‘ XÃ³a Ä‘Ã£ chá»n</button>
          <div class="arr-wrap">
            <div class="dropdown">
              <div class="split-btn">
                <button class="btn btn-sm btn-primary" onclick="arrangeLast()" title="Ãp dá»¥ng cáº¥u hÃ¬nh sáº¯p xáº¿p gáº§n nháº¥t">â¬š Sáº¯p xáº¿p</button>
                <button class="btn btn-sm btn-primary split-arrow" id="arrange-btn" onclick="openArrangeDrawer()" title="TÃ¹y chá»n sáº¯p xáº¿p">â–¼</button>
              </div>
            </div>
          </div>
              <div class="arr-panel hidden" id="arrange-menu">
                <div class="arr-head">
                  <h3>Sáº¯p xáº¿p cá»­a sá»•</h3>
                  <button class="modal-close" onclick="closeArrangeDrawer()">Ã—</button>
                </div>
                <div class="arr-body">
                <div class="arr-group">
                  <div class="arr-gtitle">Pháº¡m vi</div>
                  <div id="arr-scope">
                    <label class="radio-row"><input type="radio" name="arr-scope" value="visible" checked><span>Táº¥t cáº£ Ä‘ang hiá»ƒn thá»‹ (<b id="arr-n-visible">0</b>)</span></label>
                    <label class="radio-row"><input type="radio" name="arr-scope" value="selected"><span>KÃªnh Ä‘Ã£ chá»n (<b id="arr-n-selected">0</b>)</span></label>
                    <label class="radio-row"><input type="radio" name="arr-scope" value="running"><span>KÃªnh Ä‘ang cháº¡y (<b id="arr-n-running">0</b>)</span></label>
                  </div>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Bá»‘ cá»¥c</div>
                  <div class="lay-cards" id="arr-layouts">
                    <button class="lay-card" data-mode="smart_auto" onclick="arrSelectMode('smart_auto')"><span class="lay-ic">âœ¨</span><span>ThÃ´ng minh</span></button>
                    <button class="lay-card" data-mode="grid" onclick="arrSelectMode('grid')"><span class="lay-ic">â–¦</span><span>LÆ°á»›i</span></button>
                    <button class="lay-card" data-mode="horizontal" onclick="arrSelectMode('horizontal')"><span class="lay-ic">â˜°</span><span>HÃ ng ngang</span></button>
                    <button class="lay-card" data-mode="vertical" onclick="arrSelectMode('vertical')"><span class="lay-ic">â‹®</span><span>HÃ ng dá»c</span></button>
                    <button class="lay-card" data-mode="cascade" onclick="arrSelectMode('cascade')"><span class="lay-ic">ðŸ——</span><span>Bá»‘ cá»¥c táº§ng</span></button>
                    <button class="lay-card" data-mode="compact" onclick="arrSelectMode('compact')"><span class="lay-ic">â–¤</span><span>Bá»‘ cá»¥c nÃ©n</span></button>
                  </div>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">MÃ n hÃ¬nh</div>
                  <select id="arr-monitor" class="filter-select" style="width:100%"></select>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">TÃ¹y chá»n</div>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-taskbar" checked><span>Chá»«a taskbar</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-uniform" checked><span>KÃ­ch thÆ°á»›c Ä‘á»“ng Ä‘á»u</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-autofit" checked><span>Tá»± co giÃ£n theo sá»‘ lÆ°á»£ng</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-skipmin"><span>Bá» qua cá»­a sá»• chÆ°a sáºµn sÃ ng</span></label>
                  <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="arr-opt-focus" checked><span>KhÃ´ng giÃ nh focus</span></label>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">KÃ­ch thÆ°á»›c &amp; Máº­t Ä‘á»™</div>
                  <div class="win-row">
                    <div class="win-field"><label>Cá»¡ cá»­a sá»•</label>
                      <select id="arr-size" class="filter-select">
                        <option value="auto" selected>Tá»± Ä‘á»™ng</option>
                        <option value="small">Nhá»</option>
                        <option value="medium">Vá»«a</option>
                        <option value="large">Lá»›n</option>
                      </select>
                    </div>
                    <div class="win-field"><label>Máº­t Ä‘á»™</label>
                      <select id="arr-density" class="filter-select">
                        <option value="balanced" selected>CÃ¢n báº±ng</option>
                        <option value="relaxed">ThoÃ¡ng</option>
                        <option value="dense">DÃ y</option>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Preset nhanh</div>
                  <div class="preset-row">
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(1)">1 cá»™t</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(2)">2 cá»™t</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(3)">3 cá»™t</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(4)">4 cá»™t</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(5)">5 cá»™t</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCols(6)">6 cá»™t</button>
                  </div>
                  <div class="preset-row">
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(4)">4 Ã´/mÃ n</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(6)">6 Ã´/mÃ n</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(8)">8 Ã´/mÃ n</button>
                    <button class="btn btn-sm" onclick="arrApplyPresetCells(12)">12 Ã´/mÃ n</button>
                  </div>
                  <div class="preset-row" id="arr-presets"></div>
                  <button class="btn btn-sm" onclick="arrSavePreset()">ðŸ’¾ LÆ°u preset hiá»‡n táº¡i</button>
                </div>
                <div class="arr-group">
                  <div class="arr-gtitle">Xem trÆ°á»›c</div>
                  <div class="mini-preview" id="arr-preview"></div>
                  <div class="preset-row" style="margin-top:7px">
                    <button class="btn btn-sm" onclick="arrangePreviewPanel()">ðŸ” Xem trÆ°á»›c chi tiáº¿t</button>
                  </div>
                  <button class="link-btn" onclick="gotoLayoutSettings()">CÃ i Ä‘áº·t nÃ¢ng caoâ€¦</button>
                </div>
                </div>
                <div class="arr-foot">
                  <button class="btn" onclick="closeArrangeDrawer()">Há»§y</button>
                  <button class="btn btn-primary" onclick="arrApplyPanel()">âœ“ Ãp dá»¥ng</button>
                </div>
              </div>
              <div class="drawer-overlay hidden" id="arrange-overlay" onclick="closeArrangeDrawer()"></div>
          <span id="selected-count" class="sel-count"></span>
          <span id="arrange-result" class="sel-count"></span>
          <div class="spacer"></div>
          <button class="btn btn-sm" id="filter-toggle" onclick="toggleFilterPanel()" title="Bá»™ lá»c">Lá»c âš™</button>
          <div id="filter-panel">
          <select id="profile-filter-platform" class="filter-select" onchange="reloadProfilesView()">
            <option value="">Táº¥t cáº£ ná»n táº£ng</option>
            <option value="youtube">YouTube</option>
            <option value="tiktok">TikTok</option>
            <option value="facebook">Facebook</option>
            <option value="other">KhÃ¡c</option>
          </select>
          <select id="profile-filter-stage" class="filter-select" onchange="reloadProfilesView()" title="Lá»c theo giai Ä‘oáº¡n account">
            <option value="">Má»i giai Ä‘oáº¡n</option>
            <option value="NEW">Má»›i</option>
            <option value="OBSERVING">Theo dÃµi</option>
            <option value="STABLE">á»”n Ä‘á»‹nh</option>
            <option value="READY_FOR_CHANNEL">Sáºµn sÃ ng</option>
            <option value="CHANNEL_EXISTS">CÃ³ kÃªnh</option>
            <option value="REVIEW_REQUIRED">Cáº§n xem</option>
            <option value="ACTION_REQUIRED">Cáº§n xá»­ lÃ½</option>
            <option value="UNAVAILABLE">Máº¥t káº¿t ná»‘i</option>
          </select>
          <select id="profile-filter-eval" class="filter-select" onchange="reloadProfilesView()" title="Lá»c theo tráº¡ng thÃ¡i Ä‘Ã¡nh giÃ¡">
            <option value="">Má»i tráº¡ng thÃ¡i ÄG</option>
            <option value="ACTIVE">Hoáº¡t Ä‘á»™ng</option>
            <option value="UNCHECKED">ChÆ°a kiá»ƒm tra</option>
            <option value="CHECKING">Äang kiá»ƒm tra</option>
            <option value="LOGIN_REQUIRED">Cáº§n Ä‘Äƒng nháº­p</option>
            <option value="VERIFICATION_REQUIRED">Cáº§n xÃ¡c minh</option>
            <option value="CHANNEL_UNAVAILABLE">KhÃ´ng truy cáº­p Ä‘Æ°á»£c</option>
            <option value="ERROR">Lá»—i kiá»ƒm tra</option>
          </select>
          <select id="profile-filter-channel" class="filter-select" onchange="reloadProfilesView()" title="Lá»c theo kÃªnh YouTube">
            <option value="">Má»i kÃªnh YT</option>
            <option value="exists">ÄÃ£ cÃ³ kÃªnh</option>
            <option value="none">ChÆ°a cÃ³ kÃªnh</option>
            <option value="unknown">KhÃ´ng xÃ¡c Ä‘á»‹nh</option>
            <option value="signed_in">ÄÃ£ Ä‘Äƒng nháº­p</option>
            <option value="signed_out">ChÆ°a Ä‘Äƒng nháº­p</option>
            <option value="ready">Sáºµn sÃ ng táº¡o kÃªnh</option>
            <option value="recheck">Cáº§n Ä‘Ã¡nh giÃ¡ láº¡i</option>
          </select>
          <input type="number" id="profile-filter-days" class="filter-select" style="width:110px" min="0" placeholder="Sá»‘ ngÃ y â‰¥" title="Quáº£n lÃ½ Ã­t nháº¥t N ngÃ y" onchange="reloadProfilesView()">
          <select id="profile-filter-stab" class="filter-select" onchange="reloadProfilesView()" title="Lá»c theo Ä‘iá»ƒm á»•n Ä‘á»‹nh">
            <option value="">Má»i Ä‘iá»ƒm á»•n Ä‘á»‹nh</option>
            <option value="50">á»”n Ä‘á»‹nh â‰¥ 50</option>
            <option value="80">á»”n Ä‘á»‹nh â‰¥ 80</option>
          </select>
          <select id="profile-filter-conf" class="filter-select" onchange="reloadProfilesView()" title="Lá»c theo Ä‘iá»ƒm tin cáº­y">
            <option value="">Má»i Ä‘iá»ƒm tin cáº­y</option>
            <option value="50">Tin cáº­y â‰¥ 50</option>
            <option value="70">Tin cáº­y â‰¥ 70</option>
          </select>
            <div class="filter-actions">
              <button class="btn btn-sm" onclick="resetFilters()">Äáº·t láº¡i</button>
              <button class="btn btn-sm btn-primary" onclick="toggleFilterPanel(false)">Ãp dá»¥ng</button>
            </div>
          </div>
          <div class="search-box"><input type="text" id="profile-search" placeholder="TÃ¬m kÃªnh..." oninput="reloadProfilesView()"></div>
          <span id="profile-summary" class="summary-text"></span>
        </div>
        <div class="profile-grid" id="profiles-grid">
          <div class="empty-state">Äang táº£i...</div>
        </div>
        <div class="pagination" id="profiles-pagination"></div>
      </section>

      <!-- ===== VIEW: MONITORING (Thong ke & Theo doi) ===== -->
      <section id="view-monitoring" class="view">
        <div class="mon-head">
          <p class="mon-sub">Theo dÃµi tráº¡ng thÃ¡i, sá»©c khá»e vÃ  thay Ä‘á»•i cá»§a toÃ n bá»™ kÃªnh</p>
          <div class="mon-actions">
            <button class="btn btn-sm" onclick="monRefresh(true)">â†» LÃ m má»›i</button>
            <span class="muted" id="mon-updated">â€”</span>
            <label class="muted">Tá»± Ä‘á»™ng <select id="mon-auto" class="filter-select" onchange="monAutoChange()">
              <option value="0">Táº¯t</option><option value="1">1 phÃºt</option><option value="5">5 phÃºt</option><option value="15">15 phÃºt</option>
            </select></label>
          </div>
        </div>
        <div class="mon-strip" id="mon-strip"><div class="skeleton skeleton-strip"></div></div>
        <div class="mon-range-row">
          <div class="seg" id="mon-range">
            <button data-range="1" onclick="monSetRange(1)">24 giá»</button>
            <button data-range="7" class="active" onclick="monSetRange(7)">7 ngÃ y</button>
            <button data-range="30" onclick="monSetRange(30)">30 ngÃ y</button>
            <button data-range="custom" onclick="monSetRangeCustom()">TÃ¹y chá»‰nh</button>
          </div>
          <span id="mon-custom-wrap" class="hidden">
            <input type="date" id="mon-from" class="filter-select">
            <span class="muted">â†’</span>
            <input type="date" id="mon-to" class="filter-select">
            <button class="btn btn-sm btn-primary" onclick="monRangeCustomGo()">Ãp dá»¥ng</button>
          </span>
        </div>
        <div class="mon-tabs">
          <button class="mon-tab active" data-mtab="overview" onclick="monTab('overview')">Tá»•ng quan</button>
          <button class="mon-tab" data-mtab="channels" onclick="monTab('channels')">KÃªnh</button>
          <button class="mon-tab" data-mtab="alerts" onclick="monTab('alerts')">Cáº£nh bÃ¡o <span id="mon-tab-alert-n" class="nav-count hidden"></span></button>
          <button class="mon-tab" data-mtab="trends" onclick="monTab('trends')">Xu hÆ°á»›ng</button>
          <button class="mon-tab" data-mtab="history" onclick="monTab('history')">Lá»‹ch sá»­</button>
        </div>

        <div class="mon-pane" id="mon-pane-overview">
          <div class="mon-kpi-grid" id="mon-kpi"></div>
          <div class="mon-grid-row">
            <div class="dashboard-card mon-span8">
              <div class="dashboard-card-head"><h3>TÃ¬nh tráº¡ng toÃ n bá»™ kÃªnh</h3></div>
              <div id="mon-health"><div class="skeleton"></div></div>
            </div>
            <div class="dashboard-card mon-span4">
              <div class="dashboard-card-head"><h3>Cáº§n chÃº Ã½</h3><button class="btn btn-sm" onclick="monTab('alerts')">Xem táº¥t cáº£</button></div>
              <div id="mon-alerts-mini"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="mon-grid-row">
            <div class="dashboard-card mon-span8">
              <div class="dashboard-card-head"><h3>Xu hÆ°á»›ng</h3>
                <select id="mon-mini-metric" class="filter-select" onchange="monLoadMiniTrend()">
                  <option value="active">Hoáº¡t Ä‘á»™ng</option>
                  <option value="issues">CÃ³ váº¥n Ä‘á»</option>
                  <option value="login">Cáº§n Ä‘Äƒng nháº­p</option>
                  <option value="verify">Cáº§n xÃ¡c minh</option>
                  <option value="proxy">Proxy lá»—i</option>
                  <option value="evalfail">ÄÃ¡nh giÃ¡ tháº¥t báº¡i</option>
                </select>
              </div>
              <canvas id="mon-mini-chart" height="160"></canvas>
              <div id="mon-mini-empty" class="empty-state hidden">ChÆ°a Ä‘á»§ dá»¯ liá»‡u lá»‹ch sá»­ Ä‘á»ƒ hiá»ƒn thá»‹ xu hÆ°á»›ng.</div>
            </div>
            <div class="dashboard-card mon-span4">
              <div class="dashboard-card-head"><h3>Theo dÃµi há»‡ thá»‘ng</h3></div>
              <div id="mon-sysinfo"><div class="skeleton"></div></div>
            </div>
          </div>
          <div class="dashboard-card">
            <div class="dashboard-card-head"><h3>Thay Ä‘á»•i gáº§n Ä‘Ã¢y</h3>
              <select id="mon-recent-filter" class="filter-select" onchange="monLoadRecent()">
                <option value="">Táº¥t cáº£</option>
                <option value="evaluation">ÄÃ¡nh giÃ¡</option>
                <option value="runtime">Chrome</option>
                <option value="proxy">Proxy</option>
                <option value="monitoring">Theo dÃµi</option>
              </select>
              <button class="btn btn-sm" onclick="monTab('history')">Xem táº¥t cáº£</button>
            </div>
            <div id="mon-recent"><div class="skeleton"></div></div>
          </div>
          <div class="dashboard-card">
            <div class="dashboard-card-head"><h3>PhÃ¢n bá»‘</h3>
              <select id="mon-dist-by" class="filter-select" onchange="monLoadDist()">
                <option value="stage">Theo giai Ä‘oáº¡n</option>
                <option value="platform">Theo ná»n táº£ng</option>
                <option value="proxy">Theo Proxy</option>
                <option value="monitoring">Theo monitoring</option>
              </select>
            </div>
            <div id="mon-dist"><div class="skeleton"></div></div>
          </div>
        </div>

        <div class="mon-pane hidden" id="mon-pane-channels">
          <div class="mon-chips" id="mon-chips">
            <button class="chip active" data-chip="" onclick="monChip('')">Táº¥t cáº£</button>
            <button class="chip" data-chip="ACTIVE" onclick="monChip('ACTIVE')">Hoáº¡t Ä‘á»™ng</button>
            <button class="chip" data-chip="ISSUES" onclick="monChip('ISSUES')">CÃ³ váº¥n Ä‘á»</button>
            <button class="chip" data-chip="UNCHECKED" onclick="monChip('UNCHECKED')">ChÆ°a check</button>
            <button class="chip" data-chip="LOGIN_REQUIRED" onclick="monChip('LOGIN_REQUIRED')">Cáº§n login</button>
            <button class="chip" data-chip="proxy_dead" onclick="monChip('proxy_dead')">Proxy lá»—i</button>
            <button class="chip" data-chip="RUNNING" onclick="monChip('RUNNING')">Äang cháº¡y</button>
            <button class="chip" data-chip="WATCH" onclick="monChip('WATCH')">â­ Watchlist</button>
          </div>
          <div class="toolbar mon-filters">
            <div class="search-box"><input type="text" id="mon-search" placeholder="TÃ¬m kÃªnh..." oninput="monSearch()"></div>
            <select id="mon-f-eval" class="filter-select" onchange="monLoadChannels(1)"><option value="">Má»i ÄG</option><option value="ACTIVE">Hoáº¡t Ä‘á»™ng</option><option value="UNCHECKED">ChÆ°a kiá»ƒm tra</option><option value="CHECKING">Äang kiá»ƒm tra</option><option value="LOGIN_REQUIRED">Cáº§n Ä‘Äƒng nháº­p</option><option value="VERIFICATION_REQUIRED">Cáº§n xÃ¡c minh</option><option value="CHANNEL_UNAVAILABLE">KhÃ´ng truy cáº­p Ä‘Æ°á»£c</option><option value="ERROR">Lá»—i kiá»ƒm tra</option></select>
            <select id="mon-f-chrome" class="filter-select" onchange="monLoadChannels(1)"><option value="">Má»i Chrome</option><option value="running">Äang cháº¡y</option><option value="stopped">Dá»«ng</option></select>
            <select id="mon-f-stage" class="filter-select" onchange="monLoadChannels(1)"><option value="">Má»i giai Ä‘oáº¡n</option><option value="NEW">Má»›i</option><option value="OBSERVING">Theo dÃµi</option><option value="STABLE">á»”n Ä‘á»‹nh</option><option value="READY_FOR_CHANNEL">Sáºµn sÃ ng</option><option value="CHANNEL_EXISTS">CÃ³ kÃªnh</option><option value="REVIEW_REQUIRED">Cáº§n xem</option><option value="ACTION_REQUIRED">Cáº§n xá»­ lÃ½</option><option value="UNAVAILABLE">Máº¥t káº¿t ná»‘i</option></select>
            <select id="mon-f-platform" class="filter-select" onchange="monLoadChannels(1)"><option value="">Má»i ná»n táº£ng</option><option value="youtube">YouTube</option><option value="tiktok">TikTok</option><option value="facebook">Facebook</option><option value="other">KhÃ¡c</option></select>
            <select id="mon-f-proxy" class="filter-select" onchange="monLoadChannels(1)"><option value="">Má»i proxy</option><option value="ok">Proxy tá»‘t</option><option value="dead">Proxy lá»—i</option><option value="none">KhÃ´ng proxy</option></select>
            <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="mon-f-watch" onchange="monLoadChannels(1)"><span>â­ Watchlist</span></label>
            <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="mon-f-alert" onchange="monLoadChannels(1)"><span>CÃ³ cáº£nh bÃ¡o</span></label>
          </div>
          <div class="panel">
            <div class="table-wrap mon-table-wrap">
              <table class="data-table">
                <thead><tr><th></th><th>Channel</th><th>ÄÃ¡nh giÃ¡</th><th>Chrome</th><th>Proxy</th><th>Tabs</th><th>Giai Ä‘oáº¡n</th><th>á»”n Ä‘á»‹nh</th><th>Tin cáº­y</th><th>Kiá»ƒm tra</th><th>Cáº£nh bÃ¡o</th><th>Theo dÃµi</th><th>HÃ nh Ä‘á»™ng</th></tr></thead>
                <tbody id="mon-tbody"><tr><td colspan="13"><div class="skeleton"></div></td></tr></tbody>
              </table>
            </div>
          </div>
          <div class="pagination" id="mon-pagination"></div>
        </div>

        <div class="mon-pane hidden" id="mon-pane-alerts">
          <div class="toolbar">
            <select id="mon-a-status" class="filter-select" onchange="monLoadAlerts()"><option value="OPEN">Äang má»Ÿ</option><option value="RESOLVED">ÄÃ£ xá»­ lÃ½</option><option value="all">Táº¥t cáº£</option></select>
            <select id="mon-a-sev" class="filter-select" onchange="monLoadAlerts()"><option value="">Má»i má»©c</option><option value="CRITICAL">Critical</option><option value="WARNING">Warning</option><option value="INFO">Info</option></select>
            <div class="spacer"></div>
            <button class="btn btn-sm" onclick="monRecheckSelected()">âœ“ Kiá»ƒm tra láº¡i Ä‘Ã£ chá»n</button>
            <button class="btn btn-sm" onclick="monResolveSelected()">ÄÃ¡nh dáº¥u Ä‘Ã£ xem</button>
          </div>
          <div class="panel"><div class="table-wrap"><table class="data-table">
            <thead><tr><th></th><th>KÃªnh</th><th>Má»©c</th><th>Loáº¡i</th><th>Ná»™i dung</th><th>Láº§n Ä‘áº§u</th><th>Láº§n cuá»‘i</th><th>HÃ nh Ä‘á»™ng</th></tr></thead>
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
              <option value="active">Hoáº¡t Ä‘á»™ng</option>
              <option value="issues">CÃ³ váº¥n Ä‘á»</option>
              <option value="login">Cáº§n Ä‘Äƒng nháº­p</option>
              <option value="verify">Cáº§n xÃ¡c minh</option>
              <option value="proxy">Proxy lá»—i</option>
              <option value="evalfail">ÄÃ¡nh giÃ¡ tháº¥t báº¡i</option>
            </select>
          </div>
          <div class="panel"><canvas id="mon-chart" height="220"></canvas><div id="mon-trend-empty" class="empty-state hidden">ChÆ°a cÃ³ Ä‘á»§ snapshot â€” xu hÆ°á»›ng sáº½ hiá»‡n sau vÃ i giá» theo dÃµi.</div></div>
        </div>

        <div class="mon-pane hidden" id="mon-pane-history">
          <div class="toolbar">
            <select id="mon-h-cat" class="filter-select" onchange="monLoadHistory(1)"><option value="">Táº¥t cáº£ loáº¡i</option><option value="evaluation">ÄÃ¡nh giÃ¡</option><option value="runtime">Chrome</option><option value="proxy">Proxy</option><option value="monitoring">Theo dÃµi</option></select>
            <input type="date" id="mon-h-from" class="filter-select" onchange="monLoadHistory(1)">
            <div class="search-box"><input type="text" id="mon-h-search" placeholder="Lá»c theo kÃªnh..." oninput="monLoadHistory(1)"></div>
          </div>
          <div class="panel"><div id="mon-history"><div class="skeleton"></div></div></div>
          <div class="pagination" id="mon-history-pg"></div>
        </div>
      </section>
      <!-- ===== VIEW: NOTIFY (ThÃ´ng bÃ¡o & BÃ¡o cÃ¡o) ===== -->
      <section id="view-notify" class="view">
        <div class="nt-hero">
          <div class="nt-hero-text">
            <div class="nt-hero-title">ThÃ´ng bÃ¡o &amp; BÃ¡o cÃ¡o</div>
            <div class="nt-hero-sub">Trung tÃ¢m Ä‘iá»u phá»‘i Telegram, quy táº¯c gá»­i vÃ  bÃ¡o cÃ¡o Ä‘á»‹nh ká»³.</div>
          </div>
          <span id="nav-notify-count-hero" class="badge badge-danger hidden"></span>
        </div>
        <div class="mon-tabs nt-tabs">
          <button class="mon-tab active" data-ntab="overview" onclick="notifyTab('overview')"><span class="nt-tab-ico">â—ˆ</span>Tá»•ng quan</button>
          <button class="mon-tab" data-ntab="telegram" onclick="notifyTab('telegram')"><span class="nt-tab-ico">âœˆ</span>Telegram</button>
          <button class="mon-tab" data-ntab="rules" onclick="notifyTab('rules')"><span class="nt-tab-ico">âš™</span>Quy táº¯c</button>
          <button class="mon-tab" data-ntab="reports" onclick="notifyTab('reports')"><span class="nt-tab-ico">â–¤</span>BÃ¡o cÃ¡o Ä‘á»‹nh ká»³</button>
          <button class="mon-tab" data-ntab="history" onclick="notifyTab('history')"><span class="nt-tab-ico">â—·</span>Lá»‹ch sá»­ gá»­i</button>
          <button class="mon-tab" data-ntab="chat" onclick="notifyTab('chat')"><span class="nt-tab-ico">âœŽ</span>Chat &amp; Äiá»u khiá»ƒn</button>
        </div>
        <div class="mon-pane" id="nt-pane-overview">
          <div class="panel nt-panel"><div class="nt-panel-head"><span class="nt-panel-ico">â—ˆ</span>Tráº¡ng thÃ¡i há»‡ thá»‘ng</div><div id="nt-kpi" class="nt-kpi-grid"><div class="skeleton"></div></div></div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">â¬¢</span>Worker gá»­i tin<span id="nt-worker-dot" class="nt-dot"></span></div>
            <div id="nt-worker" class="muted">â€”</div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button class="btn btn-sm" onclick="notifyWorker(1)">Cháº¡y worker</button>
              <button class="btn btn-sm" onclick="notifyWorker(0)">Dá»«ng worker</button>
            </div>
          </div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-telegram">
          <div class="nt-tg-grid">
            <div>
              <div class="panel nt-panel" id="tg-setup-panel">
                <div class="nt-panel-head"><span class="nt-panel-ico">âœˆ</span>Káº¿t ná»‘i Telegram</div>
                <div id="tg-setup-view"><div class="skeleton"></div></div>
              </div>
            </div>
            <div class="panel nt-panel" id="tg-test-panel">
              <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
                <div>
                  <div class="nt-panel-head" style="margin:0"><span class="nt-panel-ico">âœŽ</span>Chat Test</div>
                  <div class="hint" style="margin:2px 0 0">Gá»­i vÃ  nháº­n tin nháº¯n trá»±c tiáº¿p Ä‘á»ƒ kiá»ƒm tra káº¿t ná»‘i Telegram.</div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                  <span id="nt-test-status" class="badge badge-muted">â€¦</span>
                  <button type="button" class="btn btn-xs" onclick="ntTestClear()" title="Chá»‰ dá»n mÃ n hÃ¬nh, khÃ´ng xÃ³a dá»¯ liá»‡u">Dá»n mÃ n hÃ¬nh</button>
                </div>
              </div>
              <div id="nt-test-new" class="hidden" style="text-align:center;margin:6px 0">
                <button type="button" class="btn btn-xs" onclick="ntTestJump()">â†“ <span id="nt-test-new-n">0</span> tin nháº¯n má»›i</button>
              </div>
              <div id="nt-test-list" class="chat-list chat-test-list"></div>
              <div style="display:flex;gap:8px;margin-top:8px">
                <textarea id="nt-test-input" rows="1" style="flex:1;resize:vertical;min-height:42px;max-height:120px" placeholder="Nháº­p tin nháº¯n Ä‘á»ƒ test..."></textarea>
                <button type="button" class="btn btn-sm btn-primary" id="nt-test-send" onclick="ntTestSend()" title="Gá»­i">âž¤</button>
              </div>
            </div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">âš™</span>Cáº¥u hÃ¬nh thÃ´ng bÃ¡o <span class="summary-text" id="nt-preset-saved"></span></div>
              <label>Preset</label>
              <select id="nt-preset" onchange="notifyPresetApply(this.value)">
                <option value="balanced">CÃ¢n báº±ng</option>
                <option value="minimal">Ãt thÃ´ng bÃ¡o</option>
                <option value="all">Táº¥t cáº£</option>
                <option value="custom">TÃ¹y chá»‰nh</option>
              </select>
              <div class="sync-label" style="margin-top:8px">ThÃ´ng bÃ¡o tá»©c thÃ¬</div>
              <div class="nt-switch-grid">
                <label><span>Cáº£nh bÃ¡o</span><input type="checkbox" id="nt-s-warning" data-ntkey="notify_send_warning"></label>
                <label><span>Lá»—i</span><input type="checkbox" id="nt-s-error" data-ntkey="notify_send_error"></label>
                <label><span>NghiÃªm trá»ng</span><input type="checkbox" id="nt-s-critical" data-ntkey="notify_send_critical"></label>
                <label><span>Há»“i phá»¥c</span><input type="checkbox" id="nt-recovery" data-ntkey="notify_recovery"></label>
              </div>
              <div class="sync-label" style="margin-top:8px">BÃ¡o cÃ¡o</div>
              <div class="nt-switch-grid">
                <label><span>Batch summary</span><input type="checkbox" id="nt-s-batch" data-ntkey="notify_send_batch"></label>
                <label><span>Cuá»‘i ngÃ y</span><input type="checkbox" id="nt-daily" data-ntkey="notify_daily_enabled"></label>
              </div>
              <div class="sync-label" style="margin-top:8px">KhÃ¡c</div>
              <div class="nt-switch-grid">
                <label><span>INFO</span><input type="checkbox" id="nt-s-info" data-ntkey="notify_send_info"></label>
                <label><span>Success Ä‘Æ¡n</span><input type="checkbox" id="nt-s-success" data-ntkey="notify_send_success"></label>
              </div>
              <div class="win-row" style="margin-top:8px">
                <div class="win-field"><label>Cuá»‘i ngÃ y lÃºc</label><input type="time" id="nt-daily-time" value="23:00" data-ntkey="notify_daily_time" data-ntval="time"></div>
                <div class="win-field"><label>Báº­t Telegram</label>
                  <select id="nt-enabled" data-ntkey="notify_telegram_enabled" data-ntval="bool01">
                    <option value="1">ON</option>
                    <option value="0">OFF</option>
                  </select>
                </div>
              </div>
              <div style="margin-top:8px">
                <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="nt-quiet-on" style="width:auto"> Giá» yÃªn tÄ©nh (CRITICAL váº«n gá»­i)</label>
                <div class="win-row" id="nt-quiet-row" style="margin-top:6px">
                  <div class="win-field"><label>Tá»«</label><input type="time" id="nt-quiet-start" value="23:00"></div>
                  <div class="win-field"><label>Äáº¿n</label><input type="time" id="nt-quiet-end" value="07:00"></div>
                </div>
              </div>
            </div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-rules">
          <div class="panel nt-panel"><div class="nt-panel-head"><span class="nt-panel-ico">âš™</span>Quy táº¯c gá»­i theo sá»± kiá»‡n</div><div id="nt-rules"><div class="skeleton"></div></div></div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-reports">
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">â—·</span>Lá»‹ch gá»­i tá»± Ä‘á»™ng</div>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="nt-r-daily" style="width:auto" data-ntkey="notify_daily_enabled"> BÃ¡o cÃ¡o ngÃ y lÃºc <input type="time" id="nt-r-daily-time" value="23:00" style="width:auto" data-ntkey="notify_daily_time" data-ntval="time"></label>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="nt-r-weekly" style="width:auto" data-ntkey="notify_weekly_enabled"> BÃ¡o cÃ¡o tuáº§n
              <select id="nt-r-weekly-day" style="width:auto" data-ntkey="notify_weekly_day" data-ntval="int17"><option value="1">Thá»© 2</option><option value="2">Thá»© 3</option><option value="3">Thá»© 4</option><option value="4">Thá»© 5</option><option value="5">Thá»© 6</option><option value="6">Thá»© 7</option><option value="7">Chá»§ nháº­t</option></select>
              lÃºc <input type="time" id="nt-r-weekly-time" value="08:00" style="width:auto" data-ntkey="notify_weekly_time" data-ntval="time"></label>
            <div class="hint">Tá»± lÆ°u khi thay Ä‘á»•i. KhÃ´ng gá»­i bÃ¡o cÃ¡o trá»‘ng trá»« khi báº­t "luÃ´n gá»­i" á»Ÿ cáº¥u hÃ¬nh nÃ¢ng cao.</div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">â–¤</span>Táº¡o bÃ¡o cÃ¡o tÃ¹y chá»n</div>
            <div class="win-row">
              <div class="win-field"><label>Tá»«</label><input type="datetime-local" id="nt-range-start"></div>
              <div class="win-field"><label>Äáº¿n</label><input type="datetime-local" id="nt-range-end"></div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button type="button" class="btn btn-sm" onclick="notifyRange()">Táº¡o bÃ¡o cÃ¡o</button>
              <button type="button" class="btn btn-sm" onclick="notifyRangeSend()">Táº¡o + Gá»­i Telegram</button>
            </div>
            <div id="nt-range-out" style="margin-top:8px"></div>
          </div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">â–¦</span>Lá»‹ch sá»­ bÃ¡o cÃ¡o</div>
            <div id="nt-reports"><div class="skeleton"></div></div>
          </div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-history">
          <div class="panel nt-panel"><div class="nt-panel-head"><span class="nt-panel-ico">â—·</span>Lá»‹ch sá»­ gá»­i tin</div><div id="nt-history"><div class="skeleton"></div></div></div>
        </div>
        <div class="mon-pane hidden" id="nt-pane-chat">
          <div class="panel chat-head nt-panel">
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
              <strong>Chat & Äiá»u khiá»ƒn</strong>
              <span id="nt-chat-status" class="badge badge-muted">â€¦</span>
              <span class="muted" id="nt-chat-peer"></span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
              <button type="button" class="btn btn-xs" id="nt-testchat-btn" onclick="ntChatMode('test')">Test Chat</button>
              <label style="display:flex;gap:6px;align-items:center">Äiá»u khiá»ƒn Tool qua Telegram
                <input type="checkbox" id="nt-cmd-mode" style="width:auto">
              </label>
              <select id="nt-chat-filter" class="filter-select" style="width:auto" onchange="ntChatFilter(this.value)">
                <option value="all">Táº¥t cáº£</option>
                <option value="TEXT">Chat</option>
                <option value="COMMAND">Command</option>
                <option value="JOB">Job</option>
                <option value="ALERT">Alert</option>
              </select>
              <button type="button" class="btn btn-xs" onclick="ntChatClearView()" title="Chá»‰ xÃ³a mÃ n hÃ¬nh, khÃ´ng xÃ³a dá»¯ liá»‡u">XÃ³a mÃ n hÃ¬nh</button>
            </div>
          </div>
          <div class="panel">
            <div id="nt-chat-new" class="hidden" style="text-align:center;margin-bottom:6px">
              <button type="button" class="btn btn-xs" onclick="ntChatJumpNew()">â†“ <span id="nt-chat-new-n">0</span> tin nháº¯n má»›i</button>
            </div>
            <div id="nt-chat-list" class="chat-list"></div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <textarea id="nt-chat-input" rows="1" style="flex:1;resize:vertical" placeholder="Nháº­p tin nháº¯n... (Enter gá»­i, Shift+Enter xuá»‘ng dÃ²ng)"></textarea>
              <button type="button" class="btn btn-sm btn-primary" id="nt-chat-send" onclick="ntChatSend()">Gá»­i</button>
            </div>
          </div>
          <div class="panel"><div id="nt-chat-metrics" class="mon-kpi-grid"><div class="skeleton"></div></div></div>
          <div class="panel nt-panel">
            <div class="nt-panel-head"><span class="nt-panel-ico">?</span>CÃ¢u há»i thÆ°á»ng gáº·p</div>
            <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
              <select id="nt-faq-cat" style="width:auto" onchange="ntFaqLoad()">
                <option value="all">Má»i nhÃ³m</option><option>GENERAL</option><option>SYSTEM</option>
                <option>CHANNEL</option><option>PROXY</option><option>TELEGRAM</option><option>JOB</option>
                <option>AUTO</option><option>AI DEV</option><option>PROJECT</option><option>DEVELOPMENT</option>
              </select>
              <button type="button" class="btn btn-sm btn-primary" onclick="ntFaqOpen()">+ ThÃªm FAQ</button>
            </div>
            <div id="nt-faq-list"><div class="skeleton"></div></div>
            <div class="nt-panel-head" style="margin-top:12px"><span class="nt-panel-ico">â–¶</span>Cháº¡y thá»­ FAQ</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <input type="text" id="nt-faq-test" placeholder='VD: proxy nÃ o lá»—i?' style="flex:1" onkeydown="if(event.key==='Enter')ntFaqPreview()">
              <button type="button" class="btn btn-sm" onclick="ntFaqPreview()">Cháº¡y thá»­</button>
            </div>
            <div id="nt-faq-preview" style="margin-top:8px"></div>
          </div>
          <div class="panel">
            <div class="sync-label">Äiá»u khiá»ƒn tá»« xa (inbound)</div>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="tg-inbound" style="width:auto"> Cho phÃ©p Ä‘iá»u khiá»ƒn Tool tá»« Telegram</label>
            <div class="hint">Máº·c Ä‘á»‹nh Táº®T. Báº­t rá»“i cáº¥u hÃ¬nh chat Ä‘Æ°á»£c phÃ©p + ghÃ©p ná»‘i.</div>
            <label>Danh sÃ¡ch chat Ä‘Æ°á»£c phÃ©p (chat_id, user_id tÃ¹y chá»n, role)</label>
            <div id="tg-allowed-list"></div>
            <div class="win-row">
              <div class="win-field"><label>Chat ID</label><input type="text" id="tg-new-chat" placeholder="123456789"></div>
              <div class="win-field"><label>User ID (tÃ¹y chá»n)</label><input type="text" id="tg-new-user" placeholder=""></div>
              <div class="win-field"><label>Role</label>
                <select id="tg-new-role"><option value="VIEWER">VIEWER</option><option value="OPERATOR">OPERATOR</option><option value="DEVELOPER">DEVELOPER</option><option value="ADMIN">ADMIN</option></select>
              </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
              <button type="button" class="btn btn-sm" onclick="tgAddAllowed()">+ ThÃªm chat</button>
              <button type="button" class="btn btn-sm" onclick="tgPairCreate()">Táº¡o mÃ£ ghÃ©p ná»‘i</button>
              <span class="summary-text" id="tg-pair-out"></span>
            </div>
          <div class="win-row" style="margin-top:6px">
              <div class="win-field"><label>Role máº·c Ä‘á»‹nh cho chat má»›i ghÃ©p ná»‘i</label>
                <select id="tg-default-role"><option value="VIEWER">VIEWER</option><option value="OPERATOR">OPERATOR</option><option value="DEVELOPER">DEVELOPER</option><option value="ADMIN">ADMIN</option></select>
              </div>
              <div class="win-field"><label>Polling</label><div id="tg-poll-status" class="muted">â€”</div></div>
            </div>
            <div style="display:flex;gap:8px;margin-top:6px">
              <button type="button" class="btn btn-sm" onclick="tgSaveInbound()">LÆ°u inbound</button>
              <button type="button" class="btn btn-sm" onclick="tgReceiverRestart()">Restart receiver</button>
              <button type="button" class="btn btn-sm" onclick="tgJob(1)">Cháº¡y job worker</button>
              <button type="button" class="btn btn-sm" onclick="tgJob(0)">Dá»«ng job worker</button>
            </div>
          </div>
          <div class="panel">
            <div class="sync-label">Nháº­t kÃ½ lá»‡nh (audit)</div>
            <div id="nt-audit"><div class="skeleton"></div></div>
          </div>
          <div class="panel">
            <div class="sync-label">Quy táº¯c lá»‡nh (role/xÃ¡c nháº­n)</div>
            <div id="nt-cmdrules"><div class="skeleton"></div></div>
          </div>
        </div>
      </section>
      <!-- ===== VIEW: PROXIES ===== -->
      <section id="view-proxies" class="view">
        <div class="toolbar">
          <button class="btn btn-primary" onclick="openProxyModal()">+ ThÃªm proxy</button>
          <button class="btn" onclick="importProxies()">Nháº­p hÃ ng loáº¡t</button>
          <button class="btn" onclick="testAllProxies()">Test táº¥t cáº£</button>
          <div class="sel-divider"></div>
          <label class="sel-all-label"><span class="ck"><input type="checkbox" id="sel-all-proxies" onchange="toggleSelectAllProxies(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span> Chá»n táº¥t cáº£</label>
          <button class="btn btn-sm" onclick="openBulkEditProxies()">âœŽ Sá»­a hÃ ng loáº¡t</button>
          <button class="btn btn-sm btn-danger" onclick="deleteSelectedProxies()">ðŸ—‘ XÃ³a Ä‘Ã£ chá»n</button>
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
                  <th>TÃªn</th><th>Host:Port</th><th>Protocol</th><th>Quá»‘c gia</th>
                  <th>Tráº¡ng thÃ¡i</th><th>Láº§n check</th><th>HÃ nh Ä‘á»™ng</th>
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
            <h3>Synchronize â€” <span id="syn-state" class="summary-text">â€”</span></h3>
            <div class="spacer"></div>
            <button class="btn btn-primary" id="syn-btn-start" onclick="synStart()">â–¶ Start Sync</button>
            <button class="btn btn-sm" id="syn-btn-pause" onclick="synPause()">â¸ Pause</button>
            <button class="btn btn-sm" id="syn-btn-resume" onclick="synResume()">âµ Resume</button>
            <button class="btn btn-sm" onclick="synRestart()">â†» Restart</button>
            <button class="btn btn-sm btn-danger" id="syn-btn-stop" onclick="synStop()">â¹ Stop</button>
          </div>
          <div class="sync-url-row" style="flex-wrap:wrap;gap:8px">
            <button class="btn btn-sm" onclick="synSelectAll()">Chá»n háº¿t</button>
            <button class="btn btn-sm" onclick="synSelectNone()">Bá» chá»n</button>
            <button class="btn btn-sm" onclick="synSelectInvert()">Äáº£o chá»n</button>
            <button class="btn btn-sm" onclick="synSetMain()">â­ Äáº·t MAIN (1 kÃªnh Ä‘Ã£ chá»n)</button>
            <button class="btn btn-sm" onclick="synMakeControlled()">âž• ThÃªm CONTROLLED (Ä‘Ã£ chá»n)</button>
            <button class="btn btn-sm" onclick="synNewSession()">ðŸ“ Session má»›i tá»« Ä‘Ã£ chá»n</button>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr><th><span class="ck"><input type="checkbox" id="syn-check-all" onchange="synToggleAll(this.checked)"><span class="ck-box"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></span></th>
                <th>#</th><th>Profile</th><th>Window</th><th>Role</th><th>Tráº¡ng thÃ¡i</th><th>HÃ nh Ä‘á»™ng</th></tr>
              </thead>
              <tbody id="syn-tbody"></tbody>
            </table>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>CÃ i Ä‘áº·t phiÃªn</h3></div>
          <div class="settings-form">
            <label>Chuá»™t</label>
            <div class="sync-url-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-mousemove" checked><span>Mouse Move</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-mouseclick" checked><span>Click (trÃ¡i/pháº£i/giá»¯a)</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-mousewheel" checked><span>Wheel</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-keyboard" checked><span>Keyboard + Hotkeys</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="syn-cfg-text" checked><span>Text Sync</span></label>
            </div>
            <label>Tá»‘c Ä‘á»™ chuá»™t (FPS)</label>
            <select id="syn-cfg-fps">
              <option value="30">30 FPS</option>
              <option value="60" selected>60 FPS (máº·c Ä‘á»‹nh)</option>
              <option value="120">120 FPS</option>
            </select>
            <label>Click Delay (ms)</label>
            <input type="number" id="syn-cfg-clickdelay" min="0" max="2000" value="20">
            <label class="checkbox-row"><input type="checkbox" id="syn-cfg-clickrandom"><span>Random delay Â±
              <input type="number" id="syn-cfg-clickvar" min="0" max="1000" value="30" style="width:70px"> ms</span></label>
            <label>Typing Delay (ms, minâ€“max)</label>
            <div class="sync-url-row">
              <input type="number" id="syn-cfg-typemin" min="0" max="5000" value="50" style="width:90px">
              <input type="number" id="syn-cfg-typemax" min="0" max="5000" value="120" style="width:90px">
            </div>
            <label>Input Mode</label>
            <select id="syn-cfg-inputmode">
              <option value="AUTO" selected>AUTO (CDP náº¿u Ä‘Æ°á»£c, fallback sau)</option>
              <option value="CDP">CDP</option>
              <option value="WINDOWS_API">Windows API (Phase 8)</option>
            </select>
            <label>Dá»«ng queue khi Stop</label>
            <select id="syn-cfg-stoppolicy">
              <option value="drain" selected>Drain (xá»­ lÃ½ ná»‘t rá»“i dá»«ng)</option>
              <option value="cancel">Cancel (há»§y queue)</option>
            </select>
            <div class="settings-actions">
              <button class="btn btn-primary" onclick="synSaveConfig()">LÆ°u cÃ i Ä‘áº·t</button>
              <span id="syn-cfg-result" class="summary-text"></span>
            </div>
            <label class="checkbox-row">
              <input type="checkbox" id="syn-cfg-showcursor">
              <span>Show Sync Cursor (cháº¥m Ä‘á» vá»‹ trÃ­ target trÃªn CONTROLLED â€” debug coordinate)</span>
            </label>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Debug Mode</h3>
            <div class="spacer"></div>
            <button class="btn btn-sm" onclick="synLoadDebug()">â†» Refresh</button>
            <button class="btn btn-sm" onclick="synLoadLogs()">Nháº­t kÃ½ sync</button>
          </div>
          <div id="syn-debug-main" class="summary-text"></div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Target</th><th>X / Y</th><th>Viewport</th><th>Queue</th><th>Káº¿t ná»‘i</th></tr></thead>
              <tbody id="syn-debug-tbody"></tbody>
            </table>
          </div>
          <div class="sync-label" style="margin-top:8px">20 event gáº§n nháº¥t:</div>
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
                <tr><th>Thá»i gian</th><th>KÃªnh</th><th>HÃ nh Ä‘á»™ng</th><th>Chi tiáº¿t</th></tr>
              </thead>
              <tbody id="logs-tbody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ===== VIEW: SETTINGS ===== -->
      <section id="view-settings" class="view">
        <div class="panel">
          <div class="panel-head"><h3>CÃ i Ä‘áº·t á»©ng dá»¥ng</h3></div>
          <div class="settings-form">
            <label>ÄÆ°á»ng dáº«n Chrome *</label>
            <input type="text" id="set-chrome-path" placeholder="C:\Program Files\Google\Chrome\Application\chrome.exe">
            <div class="hint">Náº¿u Chrome á»Ÿ nÆ¡i khÃ¡c, chá»‰nh láº¡i Ä‘Æ°á»ng dáº«n chrome.exe</div>

            <label>Trang chá»§ máº·c Ä‘á»‹nh khi má»Ÿ kÃªnh</label>
            <input type="text" id="set-home-url" placeholder="https://www.google.com/">

            <label>Proxy test timeout (giÃ¢y)</label>
            <input type="number" id="set-proxy-timeout" min="2" max="30" placeholder="5">

            <label class="checkbox-row">
              <input type="checkbox" id="set-auto-refresh">
              <span>Tá»± Ä‘á»™ng lÃ m má»›i tráº¡ng thÃ¡i má»—i 15 giÃ¢y</span>
            </label>

            <div class="win-group-title">PhiÃªn tab (Tab Session)</div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-tab-autosave" checked><span>Tá»± lÆ°u tabs</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-tab-autorestore" checked><span>Tá»± khÃ´i phá»¥c tabs</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-tab-active" checked><span>Nhá»› tab Ä‘ang active</span></label>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Autosave má»—i (giÃ¢y, 10â€“3600)</label><input type="number" id="set-tab-interval" min="10" max="3600" value="30"></div>
            </div>

            <div class="settings-actions">
              <button class="btn btn-primary" onclick="saveSettings()">LÆ°u cÃ i Ä‘áº·t</button>
              <span id="settings-result" class="summary-text"></span>
            </div>
          </div>
        </div>
        <!-- ===== SETTINGS: Browser / Window (Global defaults cho moi Chrome khi Launch) ===== -->
        <div class="panel win-panel">
          <div class="panel-head">
            <h3>ðŸ–¥ï¸ Browser / Chrome â€” Cá»­a sá»•</h3>
            <span id="win-live-badge" class="win-live">â€”</span>
          </div>
          <div class="settings-form win-form">
            <div class="hint">Cáº¥u hÃ¬nh máº·c Ä‘á»‹nh trung tÃ¢m: má»i profile (cÅ© + má»›i) Ä‘á»u láº¥y kÃ­ch thÆ°á»›c / vá»‹ trÃ­ tá»« Ä‘Ã¢y <strong>khi Launch</strong>. KhÃ´ng lÆ°u láº·p vÃ o tá»«ng profile.</div>

            <label class="switch-row">
              <span class="switch"><input type="checkbox" id="set-window-fixed" checked><span class="slider"></span></span>
              <span><strong>Apply Fixed Window Size</strong><br><small class="muted">Báº­t: má»i Chrome khi má»Ÿ sáº½ Ã©p Ä‘Ãºng kÃ­ch thÆ°á»›c bÃªn dÆ°á»›i. Táº¯t + Auto: giá»¯ nguyÃªn nhÆ° cÅ©.</small></span>
            </label>

            <div class="win-group-title">KÃ­ch thÆ°á»›c cá»­a sá»•</div>
            <div class="preset-grid" id="win-preset-grid">
              <button type="button" class="preset-card" data-preset="small" onclick="selectWindowPreset('small')"><span class="preset-name">Small</span><span class="preset-size">800 Ã— 600</span></button>
              <button type="button" class="preset-card" data-preset="standard" onclick="selectWindowPreset('standard')"><span class="preset-name">Standard</span><span class="preset-size">1024 Ã— 768</span></button>
              <button type="button" class="preset-card" data-preset="hd" onclick="selectWindowPreset('hd')"><span class="preset-name">HD</span><span class="preset-size">1280 Ã— 720</span></button>
              <button type="button" class="preset-card" data-preset="hd_plus" onclick="selectWindowPreset('hd_plus')"><span class="preset-name">HD+</span><span class="preset-size">1280 Ã— 800</span></button>
              <button type="button" class="preset-card" data-preset="laptop" onclick="selectWindowPreset('laptop')"><span class="preset-name">Laptop</span><span class="preset-size">1366 Ã— 768</span></button>
              <button type="button" class="preset-card" data-preset="fhd" onclick="selectWindowPreset('fhd')"><span class="preset-name">Full HD</span><span class="preset-size">1920 Ã— 1080</span></button>
              <button type="button" class="preset-card" data-preset="qhd" onclick="selectWindowPreset('qhd')"><span class="preset-name">2K</span><span class="preset-size">2560 Ã— 1440</span></button>
              <button type="button" class="preset-card" data-preset="uhd" onclick="selectWindowPreset('uhd')"><span class="preset-name">4K</span><span class="preset-size">3840 Ã— 2160</span></button>
              <button type="button" class="preset-card" data-preset="custom" onclick="selectWindowPreset('custom')"><span class="preset-name">Custom</span><span class="preset-size">tá»± nháº­p â†“</span></button>
            </div>
            <div class="win-row" id="win-custom-size">
              <div class="win-field"><label>Rá»™ng (400â€“7680)</label><input type="number" id="set-window-width" min="400" max="7680" value="1280"></div>
              <div class="win-x">Ã—</div>
              <div class="win-field"><label>Cao (300â€“4320)</label><input type="number" id="set-window-height" min="300" max="4320" value="720"></div>
            </div>

            <div class="win-group-title">Vá»‹ trÃ­ má»Ÿ Chrome</div>
            <div class="seg-grid" id="win-pos-grid">
              <button type="button" class="seg-card" data-pos="auto" onclick="selectWindowPos('auto')"><span class="seg-name">Auto</span><small>Giá»¯ chá»— cÅ©, káº¹p trong mÃ n hÃ¬nh</small></button>
              <button type="button" class="seg-card" data-pos="cascade" onclick="selectWindowPos('cascade')"><span class="seg-name">Cascade</span><small>Má»Ÿ lá»‡ch nhau tá»«ng Ã´</small></button>
              <button type="button" class="seg-card" data-pos="grid" onclick="selectWindowPos('grid')"><span class="seg-name">Grid</span><small>Chia Ã´ Ä‘á»u trÃªn mÃ n hÃ¬nh</small></button>
              <button type="button" class="seg-card" data-pos="custom" onclick="selectWindowPos('custom')"><span class="seg-name">Custom</span><small>Tá»a Ä‘á»™ X / Y cá»‘ Ä‘á»‹nh</small></button>
            </div>
            <div class="win-row hidden" id="win-custom-pos">
              <div class="win-field"><label>Start X</label><input type="number" id="set-window-x" value="0"></div>
              <div class="win-field"><label>Start Y</label><input type="number" id="set-window-y" value="0"></div>
            </div>

            <div class="win-row">
              <div class="win-field"><label>MÃ n hÃ¬nh máº·c Ä‘á»‹nh</label><select id="set-window-monitor"><option value="primary">MÃ n hÃ¬nh chÃ­nh</option></select></div>
              <div class="win-field"><label>Gap khi xáº¿p (0â€“100 px)</label><input type="number" id="set-window-gap" min="0" max="100" value="5"></div>
            </div>

            <div class="settings-actions">
              <button class="btn" id="win-reset-btn" onclick="resetWindowSettings()">â†º Reset máº·c Ä‘á»‹nh</button>
              <button class="btn btn-primary" id="win-save-btn" onclick="saveWindowSettings()">ðŸ’¾ LÆ°u cá»­a sá»•</button>
              <span id="window-settings-result" class="summary-text"></span>
            </div>
          </div>
        </div>
        <!-- ===== SETTINGS: Window Layout (Smart Auto Arrange) ===== -->
        <div class="panel win-panel">
          <div class="panel-head">
            <h3>ðŸªŸ Bá»‘ cá»¥c cá»­a sá»•</h3>
            <span id="layout-live-badge" class="win-live">â€”</span>
          </div>
          <div class="settings-form win-form">
            <div class="hint">Tá»± Ä‘á»™ng xáº¿p hÃ ng/cá»™t theo sá»‘ Chrome Ä‘ang cháº¡y. Má»i profile dÃ¹ng chung khi Sáº¯p xáº¿p.</div>
            <div class="win-group-title">Bá»‘ cá»¥c</div>
            <div class="win-row">
              <div class="win-field"><label>Kiá»ƒu xáº¿p máº·c Ä‘á»‹nh</label>
                <select id="set-layout-mode">
                  <option value="smart_auto">ThÃ´ng minh (tá»± chá»n)</option>
                  <option value="grid">LÆ°á»›i Ä‘á»u</option>
                  <option value="horizontal">HÃ ng ngang</option>
                  <option value="vertical">HÃ ng dá»c</option>
                  <option value="cascade">Xáº¿p táº§ng</option>
                  <option value="compact">Xáº¿p chá»“ng</option>
                </select>
              </div>
              <div class="win-field"><label>Cháº¿ Ä‘á»™ kÃ­ch thÆ°á»›c</label>
                <select id="set-layout-sizemode">
                  <option value="auto_fit">Tá»± co vá»«a mÃ n hÃ¬nh</option>
                  <option value="keep_size">Giá»¯ kÃ­ch thÆ°á»›c cÃ i Ä‘áº·t</option>
                </select>
              </div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>MÃ n hÃ¬nh má»¥c tiÃªu</label>
                <select id="set-layout-monitor"><option value="primary">MÃ n hÃ¬nh chÃ­nh</option></select>
              </div>
              <div class="win-field"><label>&nbsp;</label>
                <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-multi"><span>DÃ¹ng nhiá»u mÃ n hÃ¬nh</span></label>
              </div>
            </div>
            <div class="win-group-title">Khoáº£ng cÃ¡ch</div>
            <div class="win-row">
              <div class="win-field"><label>Khe ngang (0â€“100)</label><input type="number" id="set-layout-gapx" min="0" max="100" value="5"></div>
              <div class="win-field"><label>Khe dá»c (0â€“100)</label><input type="number" id="set-layout-gapy" min="0" max="100" value="5"></div>
            </div>
            <div class="win-group-title">Xáº¿p thÃ´ng minh</div>
            <div class="win-row">
              <div class="win-field"><label>Rá»™ng tá»‘i thiá»ƒu (200â€“4000)</label><input type="number" id="set-layout-minw" min="200" max="4000" value="500"></div>
              <div class="win-field"><label>Cao tá»‘i thiá»ƒu (150â€“3000)</label><input type="number" id="set-layout-minh" min="150" max="3000" value="400"></div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-taskbar" checked><span>Trá»« thanh taskbar</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-visible" checked><span>Giá»¯ trong mÃ n hÃ¬nh</span></label>
            </div>
            <div class="win-group-title">Nhiá»u mÃ n hÃ¬nh</div>
            <div class="hint">Tick chá»n mÃ n hÃ¬nh nÃ o Ä‘Æ°á»£c dÃ¹ng khi Sáº¯p xáº¿p. Bá» háº¿t = khÃ´ng giá»›i háº¡n.</div>
            <div id="set-layout-monlist" class="mon-check-list"><div class="hint">Äang táº£i danh sÃ¡ch mÃ n hÃ¬nh...</div></div>
            <div class="win-row">
              <div class="win-field"><label>CÃ¡ch chia (Distribution)</label>
                <select id="set-layout-dist">
                  <option value="smart" selected>ThÃ´ng minh (theo sá»©c chá»©a)</option>
                  <option value="equal">Chia Ä‘á»u</option>
                  <option value="sequential">Láº¥p Ä‘áº§y tuáº§n tá»±</option>
                  <option value="manual">Thá»§ cÃ´ng (gÃ¡n tá»«ng kÃªnh)</option>
                </select>
              </div>
              <div class="win-field"><label>CÃ¢n kÃ­ch thÆ°á»›c</label>
                <select id="set-layout-balance">
                  <option value="similar" selected>Cá»¡ gáº§n giá»‘ng nhau</option>
                  <option value="maximize">Tá»‘i Ä‘a tá»«ng mÃ n hÃ¬nh</option>
                </select>
              </div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-remember" checked><span>Nhá»› mÃ n hÃ¬nh Ä‘Ã£ chá»n</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-respectdpi" checked><span>TÃ´n trá»ng DPI</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-keepinside" checked><span>Giá»¯ trong mÃ n hÃ¬nh Ä‘Ã£ chá»n</span></label>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Máº¥t káº¿t ná»‘i mÃ n hÃ¬nh</label>
                <select id="set-layout-disconnect">
                  <option value="ask" selected>Há»i trÆ°á»›c</option>
                  <option value="auto">Tá»± xáº¿p láº¡i</option>
                </select>
              </div>
              <div class="win-field"><label>MAIN riÃªng mÃ n hÃ¬nh (Ä‘á»ƒ dÃ nh Sync)</label>
                <select id="set-layout-mainmon"><option value="">-- KhÃ´ng --</option></select>
              </div>
            </div>
            <div class="win-group-title">Tá»± Ä‘á»™ng</div>
            <div class="win-row">
              <div class="win-field"><label>&nbsp;</label>
                <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-layout-autolaunch" checked><span>Tá»± xáº¿p sau khi má»Ÿ nhiá»u kÃªnh</span></label>
              </div>
              <div class="win-field"><label>Khi sá»‘ lÆ°á»£ng Ä‘á»•i</label>
                <select id="set-layout-reflow">
                  <option value="off">Táº¯t</option>
                  <option value="ask" selected>Há»i trÆ°á»›c</option>
                  <option value="auto">Tá»± Ä‘á»™ng xáº¿p</option>
                </select>
              </div>
            </div>
            <div class="win-group-title">Dá»± phÃ²ng &amp; Xáº¿p chá»“ng</div>
            <div class="win-row">
              <div class="win-field"><label>Khi khÃ´ng vá»«a</label>
                <select id="set-layout-fallback">
                  <option value="auto" selected>Tá»± Ä‘á»™ng</option>
                  <option value="multi">DÃ¹ng nhiá»u mÃ n hÃ¬nh</option>
                  <option value="compact">Xáº¿p chá»“ng</option>
                  <option value="force_fit">Ã‰p vá»«a mÃ n hÃ¬nh</option>
                </select>
              </div>
              <div class="win-field"><label>Lá»‡ch ngang chá»“ng</label><input type="number" id="set-layout-compactx" min="0" max="2000" value="150"></div>
              <div class="win-field"><label>Lá»‡ch dá»c chá»“ng</label><input type="number" id="set-layout-compacty" min="0" max="2000" value="40"></div>
            </div>
            <div class="settings-actions">
              <button class="btn" id="layout-reset-btn" onclick="resetLayoutSettings()">â†º Máº·c Ä‘á»‹nh</button>
              <button class="btn btn-primary" id="layout-save-btn" onclick="saveLayoutSettings()">ðŸ’¾ LÆ°u bá»‘ cá»¥c</button>
              <span id="layout-settings-result" class="summary-text"></span>
            </div>
          </div>
        </div>
        <!-- ===== SETTINGS: Account Evaluation ===== -->
        <div class="panel win-panel">
          <div class="panel-head"><h3>ÄÃ¡nh giÃ¡ Account</h3></div>
          <div class="settings-form win-form">
            <div class="hint">Quy táº¯c quáº£n trá»‹ ná»™i bá»™ cá»§a tool (khÃ´ng pháº£i Ä‘iá»u kiá»‡n cá»§a Google/YouTube).<br>ÄÃ¢y lÃ  tiÃªu chÃ­ quáº£n lÃ½ ná»™i bá»™, khÃ´ng pháº£i Ä‘iá»ƒm/xáº¿p háº¡ng chÃ­nh thá»©c cá»§a Google hoáº·c YouTube.</div>
            <div class="win-group-title">ChÃ­nh sÃ¡ch sáºµn sÃ ng</div>
            <div class="win-row">
              <div class="win-field"><label>Theo dÃµi tá»‘i thiá»ƒu (ngÃ y)</label><input type="number" id="set-acc-days" min="0" max="365" value="7"></div>
              <div class="win-field"><label>Check thÃ nh cÃ´ng tá»‘i thiá»ƒu</label><input type="number" id="set-acc-checks" min="1" max="1000" value="10"></div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>NgÆ°á»¡ng á»•n Ä‘á»‹nh sáºµn sÃ ng</label><input type="number" id="set-acc-stab" min="0" max="100" value="80"></div>
              <div class="win-field"><label>NgÆ°á»¡ng tin cáº­y sáºµn sÃ ng</label><input type="number" id="set-acc-conf" min="0" max="100" value="70"></div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>NgÆ°á»¡ng xem láº¡i</label><input type="number" id="set-acc-review" min="0" max="100" value="50"></div>
              <div class="win-field"><label>Lá»—i liÃªn tiáº¿p â†’ máº¥t káº¿t ná»‘i</label><input type="number" id="set-acc-unavail" min="1" max="1000" value="20"></div>
            </div>
            <div class="win-group-title">Trá»ng sá»‘ Ä‘iá»ƒm á»•n Ä‘á»‹nh</div>
            <div class="win-row">
              <div class="win-field"><label>ÄÄƒng nháº­p</label><input type="number" id="set-acc-wlogin" min="0" max="100" value="25"></div>
              <div class="win-field"><label>PhiÃªn</label><input type="number" id="set-acc-wsession" min="0" max="100" value="15"></div>
              <div class="win-field"><label>YouTube</label><input type="number" id="set-acc-wyt" min="0" max="100" value="25"></div>
            </div>
            <div class="win-row">
              <div class="win-field"><label>Lá»‹ch sá»­ check</label><input type="number" id="set-acc-wrate" min="0" max="100" value="25"></div>
              <div class="win-field"><label>Lá»—i liÃªn tiáº¿p</label><input type="number" id="set-acc-wconsec" min="0" max="100" value="10"></div>
            </div>
            <div class="win-group-title">Kiá»ƒm tra ná»n</div>
            <div class="win-row">
              <div class="win-field"><label>Chu ká»³ check (phÃºt)</label><input type="number" id="set-acc-interval" min="5" max="10080" value="120"></div>
              <div class="win-field"><label>Dá»¯ liá»‡u quÃ¡ háº¡n (giá»)</label><input type="number" id="set-acc-maxage" min="1" max="720" value="72"></div>
              <div class="win-field"><label>Sá»‘ kÃªnh má»—i lÆ°á»£t</label><input type="number" id="set-acc-batch" min="1" max="100" value="10"></div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-onstart"><span>ÄÃ¡nh giÃ¡ khi má»Ÿ kÃªnh</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-bg"><span>Cháº¡y ná»n (monitor)</span></label>
            </div>
            <div class="win-group-title">Cháº¡y Ä‘Ã¡nh giÃ¡</div>
            <div class="win-row">
              <div class="win-field"><label>Äá»“ng thá»i (2/4/6/8)</label>
                <select id="set-acc-concurrency"><option value="2">2</option><option value="4">4</option><option value="6">6</option><option value="8">8</option></select>
              </div>
            </div>
            <div class="win-row" style="flex-wrap:wrap;gap:8px">
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-autostart"><span>Tá»± má»Ÿ Chrome khi Ä‘Ã¡nh giÃ¡ (máº·c Ä‘á»‹nh: yÃªu cáº§u Chrome cháº¡y)</span></label>
              <label class="checkbox-row" style="min-width:0"><input type="checkbox" id="set-acc-closeafter"><span>ÄÃ³ng láº¡i sau khi kiá»ƒm tra (náº¿u trÆ°á»›c Ä‘Ã³ Ä‘ang dá»«ng)</span></label>
            </div>
            <div class="settings-actions">
              <button class="btn" id="acc-reset-btn" onclick="resetAccountSettings()">â†º Máº·c Ä‘á»‹nh</button>
              <button class="btn btn-primary" id="acc-save-btn" onclick="saveAccountSettings()">ðŸ’¾ LÆ°u Ä‘Ã¡nh giÃ¡</button>
              <span id="account-settings-result" class="summary-text"></span>
            </div>
            <div class="sync-label" style="margin-top:8px" id="acc-monitor-line">Monitor: chÆ°a kiá»ƒm tra</div>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>ThÃ´ng tin</h3></div>
          <div class="settings-about">
            <p><strong>YT Manager</strong> â€” app quáº£n lÃ½ nhiá»u kÃªnh YouTube/TikTok/Facebook vá»›i proxy riÃªng biá»‡t.</p>
            <p>Tham kháº£o tráº£i nghiá»‡m tá»« <strong>Hidemium</strong> (antidetect browser): má»—i kÃªnh lÃ  1 profile Chrome tÃ¡ch biá»‡t,
            cÃ³ proxy riÃªng, cookie riÃªng, vÃ  Ä‘iá»u khiá»ƒn tab táº­p trung qua Chrome DevTools Protocol.</p>
            <p>LÆ°u Ã½: tuÃ¢n thá»§ Ä‘iá»u khoáº£n dá»‹ch vá»¥ cá»§a tá»«ng ná»n táº£ng, dÃ¹ng proxy sáº¡ch trÃ¡nh khÃ³a kÃªnh.</p>
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
      <h2 id="profile-modal-title">Táº¡o kÃªnh má»›i</h2>
      <button class="modal-close" onclick="cancelProfileModal()" title="ÄÃ³ng">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pf-id">
      <div class="pf-mode-tabs" id="pf-mode-tabs">
        <button type="button" class="btn btn-sm pf-mode-btn active" id="pf-mode-single" onclick="setProfileMode('single')">Táº¡o Ä‘Æ¡n</button>
        <button type="button" class="btn btn-sm pf-mode-btn" id="pf-mode-bulk" onclick="setProfileMode('bulk')">Táº¡o nhiá»u</button>
      </div>

      <div id="pf-single-fields">
        <div class="form-group-title">ThÃ´ng tin kÃªnh</div>
        <label>TÃªn kÃªnh *</label>
        <input type="text" id="pf-name" placeholder="VÃ­ dá»¥: KÃªnh áº¨m Thá»±c">
        <label>Channel Handle (tÃ¹y chá»n)</label>
        <input type="text" id="pf-handle" placeholder="VÃ­ dá»¥: @kÃªnh-áº©m-thá»±c hoáº·c channel ID">
        <div class="form-group-title">TrÃ¬nh duyá»‡t</div>
        <label>User-Agent (Ä‘á»ƒ trá»‘ng = tá»± Ä‘á»™ng Ä‘á» xuáº¥t)</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="text" id="pf-ua" style="flex:1" placeholder="VD: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36...">
          <button type="button" class="btn btn-sm" onclick="randomUA()" title="Táº¡o User-Agent ngáº«u nhiÃªn">ðŸŽ² Tá»± Ä‘á»™ng</button>
        </div>
        <div class="hint" id="pf-launch-note"></div>
        <label>WebRTC (chá»‘ng lá»™ IP)</label>
        <select id="pf-webrtc">
          <option value="default">Máº·c Ä‘á»‹nh (khÃ´ng can thiá»‡p)</option>
          <option value="disable_nonproxied_udp">Chá»‰ cho UDP qua proxy (Ä‘á» xuáº¥t)</option>
        </select>
        <div class="form-group-title">Tá»± Ä‘á»™ng hoáº¡t Ä‘á»™ng</div>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="act-enabled" style="width:auto"> Báº­t Auto Activity</label>
        <div id="act-fields">
          <div class="win-row">
            <div class="win-field"><label>Hoáº¡t Ä‘á»™ng trong: tá»«</label><input type="time" id="act-start" value="08:00"></div>
            <div class="win-field"><label>Ä‘áº¿n</label><input type="time" id="act-end" value="22:00"></div>
          </div>
          <div class="win-row">
            <div class="win-field"><label>Chu ká»³</label>
              <select id="act-interval">
                <option value="15">15 phÃºt</option>
                <option value="30" selected>30 phÃºt</option>
                <option value="60">60 phÃºt</option>
                <option value="120">2 giá»</option>
              </select>
            </div>
            <div class="win-field"><label>Tá»‘i Ä‘a tab tá»± Ä‘á»™ng</label>
              <select id="act-maxtabs">
                <option value="3">3</option>
                <option value="5" selected>5</option>
                <option value="10">10</option>
              </select>
            </div>
          </div>
          <label>Tab cáº§n duy trÃ¬</label>
          <div id="act-presets" style="display:flex;gap:12px;flex-wrap:wrap">
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="gmail" style="width:auto"> Gmail</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="google" style="width:auto"> Google</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="youtube" style="width:auto"> YouTube</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="drive" style="width:auto"> Drive</label>
            <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="calendar" style="width:auto"> Calendar</label>
          </div>
          <div id="act-custom-list" style="margin-top:6px"></div>
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>TÃªn trang</label><input type="text" id="act-custom-label" placeholder="Google News"></div>
            <div class="win-field" style="flex:2"><label>URL</label><input type="text" id="act-custom-url" placeholder="https://news.google.com/"></div>
          </div>
          <button type="button" class="btn btn-sm" onclick="actAddCustom()" style="margin-top:4px">+ ThÃªm URL</button>
          <label style="margin-top:8px">Search list (má»—i dÃ²ng má»™t query)</label>
          <textarea id="act-queries" rows="3" style="width:100%" placeholder="tin cÃ´ng nghá»‡&#10;hÆ°á»›ng dáº«n Excel"></textarea>
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>Cháº¿ Ä‘á»™</label>
              <select id="act-mode">
                <option value="maintain">Chá»‰ duy trÃ¬ tab</option>
                <option value="search">Tab + tÃ¬m kiáº¿m</option>
                <option value="full">Äáº§y Ä‘á»§</option>
              </select>
            </div>
            <div class="win-field"><label>Máº«u (template)</label>
              <select id="act-template" onchange="actTemplateApply()">
                <option value="LIGHT">LIGHT â€” nháº¹</option>
                <option value="NORMAL" selected>NORMAL â€” cÃ¢n báº±ng</option>
                <option value="HIGH">HIGH â€” dÃ y</option>
                <option value="CUSTOM">CUSTOM â€” tá»± chá»‰nh</option>
              </select>
            </div>
            <div class="win-field"><label>Táº¡m dá»«ng</label>
              <select id="act-pause">
                <option value="off">KhÃ´ng</option>
                <option value="1h">Pause 1 giá»</option>
                <option value="today">Pause hÃ´m nay</option>
              </select>
            </div>
          </div>
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>HÃ nh vi Search</label>
              <select id="act-search-behavior">
                <option value="SEARCH_ONLY">Chá»‰ tÃ¬m kiáº¿m</option>
                <option value="SEARCH_VISIT" selected>TÃ¬m kiáº¿m + má»Ÿ website há»£p lá»‡</option>
                <option value="DIRECT">Má»Ÿ website trá»±c tiáº¿p</option>
              </select>
            </div>
            <div class="win-field"><label>Äá»™ sÃ¢u káº¿t quáº£</label>
              <input type="number" id="act-result-depth" value="10" min="1" max="30">
            </div>
          </div>
          <div class="win-row" style="margin-top:6px">
            <div class="win-field"><label>Sessions/ngÃ y</label>
              <div style="display:flex;gap:6px;align-items:center">
                <input type="number" id="act-sess-min" value="6" min="1" max="24" style="width:100%">
                <span class="muted">â€“</span>
                <input type="number" id="act-sess-max" value="10" min="1" max="24" style="width:100%">
              </div>
            </div>
            <div class="win-field"><label>NgÃ y hoáº¡t Ä‘á»™ng</label>
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
          <div class="form-group-title" style="margin-top:10px">Lá»‹ch hÃ´m nay</div>
          <div id="act-plan"><div class="skeleton"></div></div>
          <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
            <button type="button" class="btn btn-sm" onclick="actRunSession()">â–¶ Cháº¡y session ngay</button>
            <button type="button" class="btn btn-sm" onclick="actRegen()">â†» Táº¡o láº¡i lá»‹ch cÃ²n láº¡i</button>
          </div>
          <div class="form-group-title" style="margin-top:10px">Pool chung (má»i kÃªnh)</div>
          <div class="hint">Website Pool + Search Pool dÃ¹ng chung cho OPEN_RANDOM_WEBSITE vÃ  GOOGLE_SEARCH.</div>
          <div class="sync-label" style="margin-top:6px">Website Ä‘Æ°á»£c phÃ©p truy cáº­p</div>
          <div id="act-pool-web"><div class="skeleton"></div></div>
          <div style="display:flex;gap:6px;margin-top:6px">
            <input type="text" id="act-web-url" placeholder="https://... (Enter Ä‘á»ƒ thÃªm)" style="flex:1" onkeydown="if(event.key==='Enter')actWebAdd()">
            <button type="button" class="btn btn-sm" onclick="actWebAdd()">+ ThÃªm</button>
          </div>
          <div class="sync-label" style="margin-top:8px">Danh sÃ¡ch tá»« khÃ³a Google</div>
          <div id="act-pool-search" style="margin-top:4px"><div class="skeleton"></div></div>
          <label style="display:flex;gap:8px;align-items:center;margin-top:6px"><input type="checkbox" id="act-maintain" style="width:auto"> LuÃ´n duy trÃ¬ tab (táº¡o láº¡i sau debounce)</label>
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="act-autostart" style="width:auto"> Cho phÃ©p tá»± má»Ÿ profile tá»›i lá»‹ch</label>
          <div class="hint">Tab automation cháº¡y ná»n, khÃ´ng activate, khÃ´ng giÃ nh focus. KhÃ´ng click quáº£ng cÃ¡o/CAPTCHA.</div>
          <div style="display:flex;gap:8px;margin-top:6px">
            <button type="button" class="btn btn-sm" onclick="actSave()">LÆ°u Auto Activity</button>
            <button type="button" class="btn btn-sm" onclick="actRunNow()">Cháº¡y ngay</button>
          </div>
          <div class="hint" id="act-save-note"></div>
        </div>
      </div>

      <div id="pf-bulk-fields" class="hidden">
        <label>Tiá»n tá»‘ tÃªn kÃªnh</label>
        <input type="text" id="pf-prefix" placeholder="Máº·c Ä‘á»‹nh: KÃªnh" value="KÃªnh">
        <div class="hint">TÃªn sáº½ lÃ  <strong>[Tiá»n tá»‘] STT</strong>, VD: KÃªnh 1, KÃªnh 2...</div>
        <label>Sá»‘ lÆ°á»£ng kÃªnh *</label>
        <input type="number" id="pf-count" value="5" min="1" max="200">
      </div>

      <div id="pf-common-fields">
        <label>Ná»n táº£ng</label>
        <select id="pf-platform">
          <option value="youtube">YouTube</option>
          <option value="tiktok">TikTok</option>
          <option value="facebook">Facebook</option>
          <option value="other">KhÃ¡c</option>
        </select>
        <div class="form-group-title">Káº¿t ná»‘i</div>
        <label>Proxy</label>
        <div class="seg seg-3" id="pf-proxy-modes">
          <button type="button" data-pmode="NONE" onclick="setProxyMode('NONE')">KhÃ´ng dÃ¹ng</button>
          <button type="button" data-pmode="SAVED" onclick="setProxyMode('SAVED')">Kho proxy</button>
          <button type="button" data-pmode="MANUAL" onclick="setProxyMode('MANUAL')">Nháº­p thá»§ cÃ´ng</button>
        </div>
        <div id="pf-proxy-saved-wrap">
          <select id="pf-proxy" style="margin-top:6px"><option value="">KhÃ´ng dÃ¹ng proxy</option></select>
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
                <input type="text" id="pf-proxy-text" style="flex:1" placeholder="host:port hoáº·c user:pass@host:port...">
                <button type="button" class="btn btn-sm" onclick="pasteProxy()" title="DÃ¡n tá»« clipboard">ðŸ“‹</button>
              </div>
            </div>
          </div>
          <div class="form-error hidden" id="pf-proxy-error"></div>
          <div class="win-row" style="margin-top:8px">
            <button type="button" class="btn btn-sm" id="pf-proxy-test-btn" onclick="testManualProxy()">âš¡ Kiá»ƒm tra proxy</button>
            <span class="summary-text" id="pf-proxy-test-result"></span>
          </div>
          <button type="button" class="link-btn" id="pf-proxy-adv-toggle" onclick="toggleProxyAdvanced()">Cáº¥u hÃ¬nh chi tiáº¿t â–¾</button>
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
                  <button type="button" class="btn btn-sm" onclick="toggleProxyPass()" title="Hiá»‡n/áº©n máº­t kháº©u">ðŸ‘</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="hint" id="pf-proxy-note"></div>
        <div class="form-group-title">Hiá»ƒn thá»‹</div>
        <label>MÃ n hÃ¬nh</label>
        <select id="pf-monitor-mode" onchange="pfRefreshMonitorHint();profileDraftChanged()">
          <option value="LAST">Nhá»› mÃ n hÃ¬nh láº§n cuá»‘i</option>
          <option value="AUTO">Tá»± Ä‘á»™ng</option>
          <option value="FIXED">MÃ n hÃ¬nh cá»‘ Ä‘á»‹nhâ€¦</option>
        </select>
        <select id="pf-monitor-fixed" class="hidden" style="margin-top:6px"></select>
        <div class="hint" id="pf-monitor-hint"></div>
      </div>
      <div class="form-error hidden" id="pf-error"></div>
    </div>
    <div class="modal-footer">
      <span class="summary-text" id="pf-dirty-hint"></span>
      <button class="btn" onclick="cancelProfileModal()">Há»§y</button>
      <button class="btn btn-primary hidden" id="pf-create-btn" onclick="saveProfile()">Táº¡o kÃªnh</button>
      <button class="btn btn-primary" id="pf-save-btn" onclick="saveProfile()">LÆ°u thay Ä‘á»•i</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Proxy ===== -->
<div id="proxy-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="proxy-modal-title">ThÃªm proxy</h2>
      <button class="modal-close" onclick="closeModal('proxy-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="px-id">
      <label>Chuá»—i proxy (Ä‘iá»n nhanh)</label>
      <input type="text" id="px-string" placeholder="host:port:user:pass  vd: 198.12.102.140:44743:DedFAM:rzOofV" oninput="parseProxyStringInput(this.value)">
      <label>TÃªn (tÃ¹y chá»n)</label>
      <input type="text" id="px-name" placeholder="VÃ­ dá»¥: Proxy Má»¹ #1">
      <label>Host *</label>
      <input type="text" id="px-host" placeholder="192.168.1.100 hoáº·c proxy.example.com" required>
      <label>Port *</label>
      <input type="number" id="px-port" placeholder="8080" required>
      <label>Protocol</label>
      <select id="px-protocol">
        <option value="http">HTTP</option>
        <option value="socks4">SOCKS4</option>
        <option value="socks5">SOCKS5</option>
        <option value="ssh">SSH</option>
      </select>
      <label>Username (tÃ¹y chá»n)</label>
      <input type="text" id="px-user" placeholder="TÃªn Ä‘Äƒng nháº­p náº¿u proxy yÃªu cáº§u">
      <label>Password (tÃ¹y chá»n)</label>
      <input type="text" id="px-pass" placeholder="Máº­t kháº©u náº¿u proxy yÃªu cáº§u">
      <label>Quá»‘c gia (mÃ£ 2 chá»¯)</label>
      <input type="text" id="px-country" placeholder="US, VN, JP..." maxlength="2">
      <div class="proxy-result" id="proxy-test-result"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('proxy-modal')">Há»§y</button>
      <button class="btn" onclick="testProxyModal()">Test</button>
      <button class="btn btn-primary" onclick="saveProxy()">LÆ°u</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: FAQ (Telegram) ===== -->
<div id="nt-faq-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="nt-faq-title">ThÃªm FAQ</h2>
      <button class="modal-close" onclick="closeModal('nt-faq-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="nt-faq-id" value="">
      <label>CÃ¢u há»i</label>
      <input type="text" id="nt-faq-q" placeholder="VD: giá» lÃ m viá»‡c cá»§a tool?">
      <div class="win-row" style="margin-top:6px">
        <div class="win-field"><label>Danh má»¥c</label>
          <select id="nt-faq-category"><option>GENERAL</option><option>SYSTEM</option><option>CHANNEL</option><option>PROXY</option><option>TELEGRAM</option><option>JOB</option><option>AUTO</option><option>AI DEV</option><option>PROJECT</option><option>DEVELOPMENT</option></select>
        </div>
        <div class="win-field"><label>Kiá»ƒu</label>
          <select id="nt-faq-kind" onchange="ntFaqKindUI()"><option value="STATIC">TÄ©nh (text cá»‘ Ä‘á»‹nh)</option><option value="ACTION">Runtime/Action</option></select>
        </div>
      </div>
      <div id="nt-faq-answer-wrap" style="margin-top:6px"><label>CÃ¢u tráº£ lá»i</label>
        <textarea id="nt-faq-answer" rows="3" style="width:100%"></textarea>
      </div>
      <div id="nt-faq-action-wrap" class="hidden" style="margin-top:6px"><label>Action</label>
        <select id="nt-faq-action"><option value="system.status">system.status</option><option value="channel.summary">channel.summary</option><option value="channel.status">channel.status</option><option value="proxy.status_summary">proxy.status_summary</option><option value="telegram.status">telegram.status</option><option value="job.summary">job.summary</option><option value="auto_activity.status">auto_activity.status</option></select>
      </div>
      <label style="margin-top:6px">Keywords (má»—i dÃ²ng 1 cá»¥m Ä‘á»ƒ khá»›p)</label>
      <textarea id="nt-faq-kw" rows="3" style="width:100%" placeholder="giá» lÃ m viá»‡c&#10;tool má»Ÿ cá»­a"></textarea>
      <div class="win-row" style="margin-top:6px">
        <div class="win-field"><label>Æ¯u tiÃªn</label><input type="number" id="nt-faq-pri" value="0"></div>
        <div class="win-field"><label>Báº­t</label><select id="nt-faq-on"><option value="1">Báº­t</option><option value="0">Táº¯t</option></select></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('nt-faq-modal')">Há»§y</button>
      <button class="btn btn-primary" onclick="ntFaqSave()">LÆ°u</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Import Pool (Auto Activity) ===== -->
<div id="act-import-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="act-import-title">Nháº­p danh sÃ¡ch</h2>
      <button class="modal-close" onclick="closeModal('act-import-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <label>DÃ¡n danh sÃ¡ch (má»—i dÃ²ng 1 má»¥c, paste 1000 dÃ²ng váº«n Ä‘Æ°á»£c)</label>
      <textarea id="act-import-text" rows="10" style="width:100%" placeholder="Má»—i dÃ²ng má»™t tá»« khÃ³a..."></textarea>
      <div id="act-import-preview" style="margin-top:8px"><p class="muted">Paste danh sÃ¡ch rá»“i báº¥m Kiá»ƒm tra.</p></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('act-import-modal')">Há»§y</button>
      <button class="btn" onclick="actImportCheck()">Kiá»ƒm tra</button>
      <button class="btn btn-primary" onclick="actImportAdd()">ThÃªm danh sÃ¡ch</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Sá»­a proxy hÃ ng loáº¡t ===== -->
<div id="bulk-edit-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2>Sá»­a proxy hÃ ng loáº¡t</h2>
      <button class="modal-close" onclick="closeModal('bulk-edit-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="hint" id="bulk-edit-count" style="margin-top:0"></div>
      <div class="hint">Äá»ƒ trá»‘ng = giá»¯ nguyÃªn. Chá»‰ field cÃ³ giÃ¡ trá»‹ má»›i bá»‹ ghi Ä‘Ã¨.</div>
      <div class="win-row">
        <div class="win-field"><label>Loáº¡i proxy</label>
          <select id="be-protocol">
            <option value="keep">Giá»¯ nguyÃªn</option>
            <option value="http">HTTP</option>
            <option value="socks4">SOCKS4</option>
            <option value="socks5">SOCKS5</option>
            <option value="ssh">SSH</option>
          </select>
        </div>
        <div class="win-field"><label>Quá»‘c gia (mÃ£ 2 chá»¯)</label><input type="text" id="be-country" maxlength="2" placeholder="US"></div>
      </div>
      <div class="win-row">
        <div class="win-field"><label>Username</label><input type="text" id="be-user" placeholder="Äá»ƒ trá»‘ng = giá»¯ nguyÃªn"></div>
        <div class="win-field"><label>Password</label><input type="text" id="be-pass" placeholder="Äá»ƒ trá»‘ng = giá»¯ nguyÃªn"></div>
      </div>
      <label>Thay host:port theo danh sÃ¡ch (dÃ²ng i â†’ proxy thá»© i, dÃ²ng lá»—i/trá»‘ng thÃ¬ proxy Ä‘Ã³ giá»¯ nguyÃªn)</label>
      <textarea id="be-lines" rows="5" placeholder="1.2.3.4:8080&#10;socks5://5.6.7.8:1080:user:pass"></textarea>
      <div class="hint">DÃ²ng cÃ³ user:pass thÃ¬ Ä‘á»•i luÃ´n auth. DÃ²ng cÃ³ prefix protocol thÃ¬ Ä‘á»•i luÃ´n loáº¡i.</div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('bulk-edit-modal')">Há»§y</button>
      <button class="btn btn-primary" id="bulk-edit-save-btn" onclick="saveBulkEditProxies()">LÆ°u thay Ä‘á»•i</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Bulk Auto Activity ===== -->
<div id="bulk-act-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2>GÃ¡n Auto Activity hÃ ng loáº¡t</h2>
      <button class="modal-close" onclick="closeModal('bulk-act-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="hint" id="bulk-act-count" style="margin-top:0"></div>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="bact-enabled" style="width:auto" checked> Báº­t Auto Activity</label>
      <div class="win-field" style="margin-top:6px"><label>Máº«u (template)</label>
        <select id="bact-template">
          <option value="">Giá»¯ nguyÃªn</option>
          <option value="LIGHT">LIGHT â€” nháº¹</option>
          <option value="NORMAL">NORMAL â€” cÃ¢n báº±ng</option>
          <option value="HIGH">HIGH â€” dÃ y</option>
        </select>
      </div>
      <div class="win-row">
        <div class="win-field"><label>Hoáº¡t Ä‘á»™ng trong: tá»«</label><input type="time" id="bact-start" value="08:00" onchange="actBulkPreview()"></div>
        <div class="win-field"><label>Ä‘áº¿n</label><input type="time" id="bact-end" value="22:00" onchange="actBulkPreview()"></div>
      </div>
      <div class="win-row">
        <div class="win-field"><label>Chu ká»³</label>
          <select id="bact-interval" onchange="actBulkPreview()">
            <option value="15">15 phÃºt</option>
            <option value="30" selected>30 phÃºt</option>
            <option value="60">60 phÃºt</option>
            <option value="120">2 giá»</option>
          </select>
        </div>
        <div class="win-field"><label>Cháº¿ Ä‘á»™</label>
          <select id="bact-mode">
            <option value="maintain">Chá»‰ duy trÃ¬ tab</option>
            <option value="search">Tab + tÃ¬m kiáº¿m</option>
            <option value="full">Äáº§y Ä‘á»§</option>
          </select>
        </div>
      </div>
      <label>Tab cáº§n duy trÃ¬</label>
      <div id="bact-presets" style="display:flex;gap:12px;flex-wrap:wrap" onchange="actBulkPreview()">
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="gmail" style="width:auto" checked> Gmail</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="google" style="width:auto" checked> Google</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="youtube" style="width:auto"> YouTube</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="drive" style="width:auto"> Drive</label>
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" data-preset="calendar" style="width:auto"> Calendar</label>
      </div>
      <div class="hint" id="bulk-act-preview" style="margin-top:8px;border-top:1px solid var(--border-soft);padding-top:8px"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('bulk-act-modal')">Há»§y</button>
      <button class="btn btn-primary" id="bulk-act-save-btn" onclick="actBulkSave()">GÃ¡n cho Ä‘Ã£ chá»n</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Telegram Bot ===== -->
<div id="tg-bot-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="tg-bot-title">ThÃªm Bot Telegram</h2>
      <button class="modal-close" onclick="closeModal('tg-bot-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="tg-bot-id" value="">
      <label>TÃªn káº¿t ná»‘i</label>
      <input type="text" id="tg-bot-name" placeholder="Telegram Bot">
      <label>Bot Token</label>
      <input type="password" id="tg-bot-token" placeholder="123456:ABC-DEF..." autocomplete="off">
      <div class="hint" id="tg-bot-note"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('tg-bot-modal')">Há»§y</button>
      <button class="btn btn-primary" onclick="tgBotSave()">Kiá»ƒm tra & LÆ°u</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Import ===== -->
<div id="import-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2>Nháº­p proxy hÃ ng loáº¡t</h2>
      <button class="modal-close" onclick="closeModal('import-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <label>Nháº­p danh sÃ¡ch proxy (má»—i dÃ²ng 1 proxy)</label>
      <textarea id="import-list" rows="8" placeholder="host:port&#10;host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port"></textarea>
      <div class="hint">Há»— trá»£: host:port Â· host:port:user:pass Â· protocol://user:pass@host:port</div>
      <div class="import-result" id="import-result"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('import-modal')">Há»§y</button>
      <button class="btn btn-primary" onclick="doImport()">Nháº­p vÃ o danh sÃ¡ch</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: GÃ¡n proxy hÃ ng loáº¡t ===== -->
<div id="bulk-proxy-modal" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <h2 id="bulk-proxy-title">GÃ¡n proxy cho kÃªnh Ä‘Ã£ chá»n</h2>
      <button class="modal-close" onclick="closeModal('bulk-proxy-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div class="hint" id="bulk-proxy-count" style="margin-top:0"></div>
      <div class="pf-mode-tabs">
        <button type="button" class="btn btn-sm pf-mode-btn active" id="bp-mode-single" onclick="setBulkProxyMode('single')">Chá»n 1 proxy</button>
        <button type="button" class="btn btn-sm pf-mode-btn" id="bp-mode-list" onclick="setBulkProxyMode('list')">Paste danh sÃ¡ch</button>
      </div>

      <div id="bp-single-fields">
        <label>Chá»n proxy Ä‘á»ƒ gÃ¡n cho cÃ¡c kÃªnh</label>
        <select id="bulk-proxy-select"><option value="">KhÃ´ng dÃ¹ng proxy (gá»¡ proxy)</option></select>
      </div>

      <div id="bp-list-fields" class="hidden">
        <label>Danh sÃ¡ch proxy (má»—i dÃ²ng 1 proxy)</label>
        <textarea id="bulk-proxy-list" rows="6" oninput="previewBulkProxyList()" placeholder="host:port&#10;host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port"></textarea>
        <div class="win-row">
          <div class="win-field"><label>Loáº¡i proxy cho dÃ²ng khÃ´ng ghi rÃµ</label>
            <select id="bulk-proxy-protocol" onchange="previewBulkProxyList()">
              <option value="http">HTTP</option>
              <option value="socks4">SOCKS4</option>
              <option value="socks5">SOCKS5</option>
              <option value="ssh">SSH</option>
            </select>
          </div>
          <div class="win-field"><label>&nbsp;</label><div class="summary-text" id="bulk-proxy-preview"></div></div>
        </div>
        <div class="hint">DÃ²ng cÃ³ prefix (vd socks5://â€¦) giá»¯ theo prefix. GÃ¡n theo thá»© tá»±: kÃªnh 1 â† dÃ²ng 1â€¦ Proxy chÆ°a cÃ³ sáº½ tá»± thÃªm. Ãt proxy hÆ¡n sá»‘ kÃªnh thÃ¬ cÃ¡c kÃªnh cuá»‘i giá»¯ nguyÃªn.</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('bulk-proxy-modal')">Há»§y</button>
      <button class="btn btn-primary" id="bulk-proxy-save-btn" onclick="saveBulkProxy()">GÃ¡n</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: Arrange preview ===== -->
<div id="preview-modal" class="modal-overlay hidden">
  <div class="modal" style="width:640px">
    <div class="modal-header">
      <h2>Xem trÆ°á»›c sáº¯p xáº¿p</h2>
      <button class="modal-close" onclick="closeModal('preview-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="preview-summary" class="summary-text"></div>
      <div id="preview-body"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('preview-modal')">Há»§y</button>
      <button class="btn btn-primary" id="preview-apply-btn" onclick="applyPreviewArrange()">Xáº¿p ngay</button>
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
      <button class="btn" id="acc-drawer-eval">Kiá»ƒm tra láº¡i</button>
      <button class="btn" id="acc-drawer-open">Má»Ÿ Profile</button>
      <button class="btn" id="acc-drawer-hist">Lá»‹ch sá»­</button>
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
      <button class="btn" id="acc-drawer-eval">Kiá»ƒm tra láº¡i</button>
      <button class="btn" id="acc-drawer-open">Má»Ÿ Profile</button>
      <button class="btn" id="acc-drawer-hist">Lá»‹ch sá»­</button>
    </div>
  </aside>
</div>

<div id="toast" class="toast hidden"></div>

<!-- ===== MODAL: XÃ¡c nháº­n xÃ³a dÃ¹ng chung ===== -->
<div id="confirm-modal" class="modal-overlay hidden">
  <div class="modal" style="width:400px">
    <div class="modal-header">
      <h2>âš ï¸ XÃ¡c nháº­n</h2>
      <button class="modal-close" onclick="hideConfirm()">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirm-msg" style="font-size:14px;line-height:1.6;margin:0"></p>
    </div>
    <div class="modal-footer">
      <button class="btn" onclick="hideConfirm()">Há»§y</button>
      <button class="btn btn-danger" id="confirm-ok-btn" onclick="doConfirmDelete()">XÃ³a</button>
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