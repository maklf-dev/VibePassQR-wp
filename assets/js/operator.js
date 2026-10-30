(function () {
  'use strict';

  const root = document.querySelector('[data-ecm-operator]');
  if (!root) return;

  const ajaxUrl = root.dataset.ajaxUrl;
  const nonce = root.dataset.nonce;
  const eventSelect = root.querySelector('[data-operator-event]');
  const codeInput = root.querySelector('[data-operator-code]');
  const lookupButton = root.querySelector('[data-operator-lookup]');
  const result = root.querySelector('[data-operator-result]');
  const status = root.querySelector('[data-operator-status]');
  const cameraShell = root.querySelector('[data-camera-shell]');
  const cameraButton = root.querySelector('[data-camera-toggle]');
  const rescanButton = root.querySelector('[data-camera-rescan]');
  const freezeCanvas = root.querySelector('[data-camera-freeze]');
  const video = root.querySelector('[data-operator-video]');
  const placeholder = root.querySelector('[data-camera-placeholder]');
  const fileInput = root.querySelector('[data-camera-file]');
  const clock = root.querySelector('[data-operator-clock]');
  if (!eventSelect) return;

  let detector = null;
  let stream = null;
  let scanning = false;
  let busy = false;
  let currentToken = '';
  let lastDetected = '';
  let lastDetectedAt = 0;
  let resultOpen = false;
  let cameraWasStarted = false;
  let scanTimer = 0;
  let restartTimer = 0;
  let scannerArmed = true;
  let clearFrames = 0;
  const decodeCanvas = document.createElement('canvas');
  const decodeContext = decodeCanvas.getContext('2d', {willReadFrequently:true});

  const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const requestId = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2));

  function updateClock() {
    if (clock) clock.textContent = new Intl.DateTimeFormat('fa-IR', {dateStyle:'short', timeStyle:'medium'}).format(new Date());
  }
  updateClock();
  window.setInterval(updateClock, 1000);

  function setStatus(message, type) {
    status.textContent = message;
    status.dataset.state = type || 'idle';
  }

  function setCameraState(state) {
    if (cameraShell) cameraShell.dataset.cameraState = state;
  }

  async function post(action, extra) {
    const body = new URLSearchParams({action, nonce, event_id: eventSelect.value, token: currentToken});
    Object.entries(extra || {}).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach(item => body.append(key + '[]', item));
      else body.append(key, value);
    });
    const response = await fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body});
    let payload;
    try { payload = await response.json(); } catch (error) { throw new Error('پاسخ سرور قابل خواندن نیست.'); }
    if (!response.ok || !payload.success) {
      const issue = payload && payload.data ? payload.data : {};
      const error = new Error(issue.message || 'درخواست انجام نشد.');
      error.loginUrl = issue.login_url || '';
      throw error;
    }
    return payload.data;
  }

  async function lookup(value, source) {
    value = String(value || '').trim();
    if (!value || busy) return;
    busy = true;
    currentToken = value;
    codeInput.value = value;
    lookupButton.disabled = true;
    setStatus('در حال بررسی کارت…', 'loading');
    try {
      const view = await post('ecm_operator_lookup');
      render(view);
      resultOpen = true;
      setStatus(view.scanned_type === 'guest' ? 'کارت مهمان شناسایی شد؛ مشخصات را بررسی و تأیید کنید.' : 'کارت معرف شناسایی شد؛ افراد موردنظر را انتخاب و تأیید کنید.', 'success');
    } catch (error) {
      showError(error.message, error.loginUrl);
      resultOpen = true;
      setStatus(error.message, 'error');
    } finally {
      busy = false;
      lookupButton.disabled = false;
      if (source === 'camera' && rescanButton) {
        try { rescanButton.focus({preventScroll:true}); } catch (error) { rescanButton.focus(); }
      }
    }
  }

  function showError(message, loginUrl) {
    result.hidden = false;
    result.innerHTML = '<div class="ecm-operator-error"><strong>' + escapeHtml(message) + '</strong>' + (loginUrl ? '<a class="ecm-button ecm-button--primary" href="' + escapeHtml(loginUrl) + '">ورود دوباره</a>' : '') + '</div>';
  }

  function render(view) {
    const rows = view.participants.map(person => {
      const role = person.type === 'guest' ? 'مهمان ' + (person.guest_order || '') : 'معرف / ثبت‌نام‌کننده';
      const history = person.attended
        ? '<div class="ecm-attendance-history"><strong>قبلاً ثبت شده</strong><span>' + escapeHtml(person.attendance_time) + '</span><span>' + escapeHtml(person.recorder_name || 'ثبت‌کننده نامشخص') + (person.recorder_code ? ' · ' + escapeHtml(person.recorder_code) : '') + '</span></div>'
        : '<span class="ecm-not-attended">آماده ثبت حضور</span>';
      const host = person.host_name ? '<small>معرف: ' + escapeHtml(person.host_name) + ' · ' + (person.host_attended ? 'حضور معرف ثبت شده' : 'حضور معرف ثبت نشده') + '</small>' : '';
      const checked = !person.attended && (view.scanned_type === 'guest' || person.type === 'primary') ? ' checked' : '';
      return '<label class="ecm-attendee-row' + (person.attended ? ' is-attended' : '') + '" data-participant-type="' + escapeHtml(person.type) + '"><input type="checkbox" value="' + Number(person.id) + '" data-attendee' + (person.attended ? ' disabled' : '') + checked + '><span class="ecm-attendee-check"></span><span class="ecm-attendee-main"><span><strong>' + escapeHtml(person.name) + '</strong><b>' + escapeHtml(role) + '</b></span><span dir="ltr">' + escapeHtml(person.phone) + '</span>' + host + history + '</span></label>';
    }).join('');
    const selectable = view.participants.some(person => !person.attended);
    const groupTools = view.group_mode && view.participants.length > 1 ? '<div class="ecm-selection-tools"><button type="button" data-select="primary">فقط معرف</button><button type="button" data-select="guests">فقط همه مهمان‌ها</button><button type="button" data-select="all">همه افراد ثبت‌نشده</button><button type="button" data-select="none">پاک‌کردن انتخاب</button></div>' : '';
    result.hidden = false;
    result.innerHTML = '<div class="ecm-result-head"><div><span>' + escapeHtml(view.event_title) + '</span><h2>' + escapeHtml(view.session_title) + '</h2><p>' + escapeHtml(view.session_time) + '</p></div><button type="button" class="ecm-icon-button" data-next-scan aria-label="اسکن بعدی">×</button></div>' + groupTools + '<div class="ecm-attendee-list">' + rows + '</div><div class="ecm-result-actions"><button type="button" class="ecm-button ecm-button--primary" data-confirm-attendance' + (selectable ? '' : ' disabled') + '>تأیید و ثبت حضور انتخاب‌شده‌ها</button><button type="button" class="ecm-button ecm-button--ghost" data-next-scan>اسکن کارت بعدی</button></div>';
    result.querySelectorAll('[data-select]').forEach(button => button.addEventListener('click', () => {
      result.querySelectorAll('[data-attendee]:not(:disabled)').forEach(box => {
        const row = box.closest('.ecm-attendee-row');
        const type = row ? row.dataset.participantType : '';
        box.checked = button.dataset.select === 'all' || (button.dataset.select === 'primary' && type === 'primary') || (button.dataset.select === 'guests' && type === 'guest');
      });
    }));
    result.querySelectorAll('[data-next-scan]').forEach(button => button.addEventListener('click', beginNextScan));
    const confirm = result.querySelector('[data-confirm-attendance]');
    if (confirm) confirm.addEventListener('click', submitAttendance);
  }

  async function submitAttendance() {
    const selected = Array.from(result.querySelectorAll('[data-attendee]:checked:not(:disabled)'), box => box.value);
    if (!selected.length || busy) {
      setStatus('حداقل یک فرد ثبت‌نشده را انتخاب کنید.', 'error');
      return;
    }
    busy = true;
    const button = result.querySelector('[data-confirm-attendance]');
    button.disabled = true;
    button.textContent = 'در حال ثبت…';
    try {
      const data = await post('ecm_operator_checkin', {participant_ids:selected, request_id:requestId()});
      render(data.view);
      const stored = data.results.filter(item => item.status === 'stored').length;
      const already = data.results.filter(item => item.status === 'already').length;
      setStatus(stored + ' حضور ثبت شد' + (already ? '؛ ' + already + ' مورد قبلاً ثبت شده بود.' : '.'), stored ? 'success' : 'warning');
      if (stored > 0 && cameraWasStarted) {
        window.clearTimeout(restartTimer);
        restartTimer = window.setTimeout(beginNextScan, 900);
      }
    } catch (error) {
      showError(error.message, error.loginUrl);
      setStatus(error.message, 'error');
    } finally {
      busy = false;
    }
  }

  function clearResult() {
    window.clearTimeout(restartTimer);
    currentToken = '';
    resultOpen = false;
    codeInput.value = '';
    result.hidden = true;
    result.innerHTML = '';
  }

  function focusManualInput() {
    try { codeInput.focus({preventScroll:true}); } catch (error) { codeInput.focus(); }
  }

  function beginNextScan(event) {
    const manualRestart = Boolean(event && typeof event.preventDefault === 'function');
    clearResult();
    if (cameraWasStarted) {
      startCamera(!manualRestart);
      return;
    }
    setStatus('آماده اسکن', 'idle');
    focusManualInput();
  }

  async function createDetector() {
    const isAppleWebKit = /(?:iPhone|iPad|iPod)/i.test(navigator.userAgent) || (/Safari/i.test(navigator.userAgent) && !/(?:Chrome|Chromium|Edg|OPR)/i.test(navigator.userAgent));
    if (!isAppleWebKit && 'BarcodeDetector' in window) {
      try {
        const formats = BarcodeDetector.getSupportedFormats ? await BarcodeDetector.getSupportedFormats() : ['qr_code'];
        if (formats.includes('qr_code')) {
          const nativeDetector = new BarcodeDetector({formats:['qr_code']});
          return {kind:'native', detect:source => nativeDetector.detect(source)};
        }
      } catch (error) { /* The local decoder below remains available. */ }
    }
    if (typeof window.jsQR !== 'function' || !decodeContext) return null;
    return {
      kind:'jsqr',
      detect: async source => {
        const sourceWidth = source.videoWidth || source.width || 0;
        const sourceHeight = source.videoHeight || source.height || 0;
        if (!sourceWidth || !sourceHeight) return [];
        const scale = Math.min(1, 800 / sourceWidth);
        const width = Math.max(1, Math.round(sourceWidth * scale));
        const height = Math.max(1, Math.round(sourceHeight * scale));
        if (decodeCanvas.width !== width) decodeCanvas.width = width;
        if (decodeCanvas.height !== height) decodeCanvas.height = height;
        decodeContext.drawImage(source, 0, 0, width, height);
        const pixels = decodeContext.getImageData(0, 0, width, height);
        const code = window.jsQR(pixels.data, width, height, {inversionAttempts:'attemptBoth'});
        return code && code.data ? [{rawValue:code.data}] : [];
      }
    };
  }

  function resetCameraSurface() {
    freezeCanvas.hidden = true;
    freezeCanvas.width = 0;
    freezeCanvas.height = 0;
    rescanButton.hidden = true;
    placeholder.hidden = true;
    video.hidden = false;
    setCameraState('scanning');
  }

  function stopTracks() {
    scanning = false;
    window.clearTimeout(scanTimer);
    scanTimer = 0;
    if (stream) stream.getTracks().forEach(track => track.stop());
    stream = null;
    video.srcObject = null;
  }

  function captureFrame() {
    if (!video.videoWidth || !video.videoHeight) return false;
    freezeCanvas.width = video.videoWidth;
    freezeCanvas.height = video.videoHeight;
    const context = freezeCanvas.getContext('2d', {alpha:false});
    if (!context) return false;
    context.drawImage(video, 0, 0, freezeCanvas.width, freezeCanvas.height);
    return true;
  }

  function freezeCamera() {
    const captured = captureFrame();
    stopTracks();
    video.hidden = true;
    freezeCanvas.hidden = !captured;
    placeholder.hidden = captured;
    rescanButton.hidden = false;
    cameraButton.textContent = 'اسکن مجدد';
    setCameraState('frozen');
  }

  async function startCamera(requireClearFrame) {
    window.clearTimeout(restartTimer);
    if (stream) return;
    detector = detector || await createDetector();
    if (!detector || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('مرورگر شما اسکن زنده QR را پشتیبانی نمی‌کند؛ از تصویر یا کادر لینک/کد استفاده کنید.', 'warning');
      cameraWasStarted = false;
      return;
    }
    try {
      clearResult();
      stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}, width:{ideal:1280}, height:{ideal:720}}, audio:false});
      video.srcObject = stream;
      await video.play();
      resetCameraSurface();
      cameraButton.textContent = 'بستن دوربین';
      scanning = true;
      cameraWasStarted = true;
      scannerArmed = !requireClearFrame;
      clearFrames = 0;
      setStatus(requireClearFrame ? 'کارت قبلی را از کادر خارج کنید، سپس QR بعدی را مقابل دوربین بگیرید.' : 'دوربین فعال است؛ QR را داخل کادر قرار دهید.', 'success');
      scanFrame();
    } catch (error) {
      stopCamera(false);
      setStatus('دسترسی دوربین ممکن نشد. مجوز دوربین و HTTPS را بررسی کنید یا لینک را وارد کنید.', 'error');
    }
  }

  function stopCamera(announce) {
    stopTracks();
    video.hidden = true;
    freezeCanvas.hidden = true;
    rescanButton.hidden = true;
    placeholder.hidden = false;
    cameraButton.textContent = 'بازکردن دوربین';
    setCameraState('closed');
    if (announce !== false) setStatus('دوربین بسته شد.', 'idle');
  }

  async function scanFrame() {
    if (!scanning || !stream || !detector) return;
    try {
      const codes = await detector.detect(video);
      if (codes.length) {
        clearFrames = 0;
        if (scannerArmed) detected(codes[0].rawValue, 'camera');
      } else if (!scannerArmed) {
        clearFrames += 1;
        if (clearFrames >= 3) {
          scannerArmed = true;
          setStatus('آماده اسکن QR بعدی.', 'success');
        }
      }
    } catch (error) { /* Some browsers reject frames while the video is warming up. */ }
    if (scanning) scanTimer = window.setTimeout(scanFrame, detector && detector.kind === 'jsqr' ? 300 : 220);
  }

  function detected(value, source) {
    const now = Date.now();
    if (!value || busy || resultOpen || (source === 'camera' && !scannerArmed) || (value === lastDetected && now - lastDetectedAt < 1400)) return;
    lastDetected = value;
    lastDetectedAt = now;
    scannerArmed = false;
    clearFrames = 0;
    if (navigator.vibrate) navigator.vibrate(60);
    if (source === 'camera') freezeCamera();
    lookup(value, source);
  }

  async function detectFile(file) {
    detector = detector || await createDetector();
    if (!detector) {
      setStatus('خواندن QR از تصویر در این مرورگر پشتیبانی نمی‌شود؛ لینک را در کادر وارد کنید.', 'warning');
      return;
    }
    let release = function () {};
    try {
      let source;
      if ('createImageBitmap' in window) {
        source = await createImageBitmap(file);
        release = function () { source.close(); };
      } else {
        const objectUrl = URL.createObjectURL(file);
        source = await new Promise((resolve, reject) => {
          const image = new Image();
          image.onload = function () { resolve(image); };
          image.onerror = function () { reject(new Error('تصویر انتخاب‌شده قابل خواندن نیست.')); };
          image.src = objectUrl;
        });
        release = function () { URL.revokeObjectURL(objectUrl); };
      }
      const codes = await detector.detect(source);
      if (!codes.length) throw new Error('QR در تصویر پیدا نشد.');
      detected(codes[0].rawValue, 'file');
    } catch (error) {
      setStatus(error.message || 'خواندن تصویر انجام نشد.', 'error');
    } finally {
      release();
    }
  }

  cameraButton.addEventListener('click', () => {
    if (stream) stopCamera(true);
    else if (cameraWasStarted) beginNextScan(new Event('manual-rescan'));
    else {
      clearResult();
      startCamera(false);
    }
  });
  rescanButton.addEventListener('click', beginNextScan);
  lookupButton.addEventListener('click', () => lookup(codeInput.value, 'manual'));
  codeInput.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); lookup(codeInput.value, 'manual'); } });
  eventSelect.addEventListener('change', () => {
    const restart = cameraWasStarted;
    stopCamera(false);
    cameraWasStarted = restart;
    if (restart) startCamera(false);
    else clearResult();
  });
  fileInput.addEventListener('change', () => { if (fileInput.files && fileInput.files[0]) detectFile(fileInput.files[0]); fileInput.value = ''; });
  window.addEventListener('pagehide', () => stopCamera(false), {once:true});
})();
