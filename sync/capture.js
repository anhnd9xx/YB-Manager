/* capture.js - EventCapture page-level cho module Synchronize (PHASE 3-5).
 * Chay trong moi page qua Page.addScriptToEvaluateOnNewDocument hoac Runtime.evaluate.
 * SINGLETON: go lai bao nhieu lan cung chi 1 bo listener (remove bo cu truoc khi gan
 * bo moi) -> khong bao gio double-send khi re-arm.
 * Nghe pointer/mouse/wheel/key that (capture phase), throttle move theo FPS,
 * gui JSON qua Runtime binding __ytmSyncEvt (daemon nhan Runtime.bindingCalled).
 * KHONG gui truc tiep sang target (pipeline bat buoc qua daemon).
 * Placeholder __FPS__ duoc PHP thay bang 30/60/120 truoc khi inject.
 */
(function () {
  var W = window;
  // Go bo listener cu (neu danh gia lai) de dam bao duy nhat 1 bo
  if (W.__ytmSyncH) {
    try {
      document.removeEventListener('mousemove', W.__ytmSyncH.move, true);
      document.removeEventListener('mousedown', W.__ytmSyncH.down, true);
      document.removeEventListener('mouseup', W.__ytmSyncH.up, true);
      document.removeEventListener('wheel', W.__ytmSyncH.wheel, true);
      document.removeEventListener('keydown', W.__ytmSyncH.keydn, true);
      document.removeEventListener('keyup', W.__ytmSyncH.keyup, true);
    } catch (_) {}
    W.__ytmSyncH = null;
  }
  W.__ytmSyncArmed = true;
  // Thong ke gui event (HealthMonitor doc de biet tab co phat event khong)
  W.__ytmSyncStat = W.__ytmSyncStat || { sent: 0, err: 0 };

  var FPS = __FPS__ > 0 ? __FPS__ : 60;
  var MIN_DT = 1000 / FPS;
  var lastMove = 0;

  function mods(e) {
    return (e.altKey ? 1 : 0) | (e.ctrlKey ? 2 : 0) | (e.metaKey ? 4 : 0) | (e.shiftKey ? 8 : 0);
  }
  function base(e) {
    return {
      x: Math.round(e.clientX * 100) / 100,
      y: Math.round(e.clientY * 100) / 100,
      vw: window.innerWidth || 1,
      vh: window.innerHeight || 1,
      mod: mods(e)
    };
  }
  function send(o) {
    try {
      if (typeof W.__ytmSyncEvt === 'function') {
        W.__ytmSyncEvt(JSON.stringify(o));
        if (W.__ytmSyncStat) W.__ytmSyncStat.sent++;
      }
    } catch (err) {
      try { if (W.__ytmSyncStat) W.__ytmSyncStat.err++; } catch (_) {}
    }
  }
  function btnName(b) {
    return b === 0 ? 'LEFT' : (b === 1 ? 'MIDDLE' : (b === 2 ? 'RIGHT' : 'X'));
  }

  function onMove(e) {
    var n = performance.now();
    if (n - lastMove < MIN_DT) return;
    lastMove = n;
    var o = base(e);
    o.t = 'MOUSE_MOVE';
    o.bts = e.buttons || 0;
    send(o);
  }
  function onDown(e) {
    var o = base(e);
    o.t = 'MOUSE_' + btnName(e.button) + '_DOWN';
    o.btn = e.button;
    o.bts = e.buttons || 0;
    o.n = e.detail || 1;
    // XBUTTON (back/forward, button 3/4): gui rieng de engine map sang back/forward
    if (o.t === 'MOUSE_X_DOWN') { o.t = 'MOUSE_XBUTTON_DOWN'; send(o); return; }
    send(o);
  }
  function onUp(e) {
    var o = base(e);
    o.t = 'MOUSE_' + btnName(e.button) + '_UP';
    o.btn = e.button;
    o.bts = e.buttons || 0;
    o.n = e.detail || 1;
    if (o.t === 'MOUSE_X_UP') { o.t = 'MOUSE_XBUTTON_UP'; send(o); return; }
    send(o);
  }
  function onWheel(e) {
    var o = base(e);
    o.t = 'MOUSE_WHEEL';
    o.dx = e.deltaX || 0;
    o.dy = e.deltaY || 0;
    o.dm = e.deltaMode || 0;
    send(o);
  }
  function onKeyDn(e) {
    send(keyPayload(e, 'KEY_DOWN'));
  }
  function onKeyUp(e) {
    send(keyPayload(e, 'KEY_UP'));
  }
  function keyPayload(e, type) {
    return {
      t: type,
      key: e.key !== undefined ? e.key : '',
      code: e.code || '',
      keyCode: e.keyCode || e.which || 0,
      loc: e.location || 0,
      repeat: e.repeat ? 1 : 0,
      mod: mods(e)
    };
  }

  // Keyboard (Phase 4): key that (khong preventDefault, khong lam anh huong MAIN).
  // Gui key/code that de target tai hien hotkey chinh xac (Ctrl+A, Shift+Tab...).
  // Wheel: passive de khong chan scroll that cua MAIN
  document.addEventListener('mousemove', onMove, true);
  document.addEventListener('mousedown', onDown, true);
  document.addEventListener('mouseup', onUp, true);
  document.addEventListener('wheel', onWheel, { capture: true, passive: true });
  document.addEventListener('keydown', onKeyDn, true);
  document.addEventListener('keyup', onKeyUp, true);
  W.__ytmSyncH = { move: onMove, down: onDown, up: onUp, wheel: onWheel, keydn: onKeyDn, keyup: onKeyUp };
})();
