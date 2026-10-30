(function ($) {
  'use strict';

  $('.ecm-tabs [data-tab]').on('click', function () {
    var tab = $(this).data('tab');
    $('.ecm-tabs [data-tab]').removeClass('is-active');
    $(this).addClass('is-active');
    $('.ecm-panel').removeClass('is-active');
    $('.ecm-panel[data-panel="' + tab + '"]').addClass('is-active');
    history.replaceState(null, '', '#' + tab);
  });

  var initialTab = location.hash.replace('#', '');
  if (initialTab && $('.ecm-tabs [data-tab="' + initialTab + '"]').length) {
    $('.ecm-tabs [data-tab="' + initialTab + '"]').trigger('click');
  }

  function syncPaymentMode() {
    var required = $('[data-payment-toggle]').is(':checked');
    $('[data-payment-settings]').prop('hidden', !required);
    $('[data-free-registration]').prop('hidden', required);
  }
  $(document).on('change', '[data-payment-toggle]', syncPaymentMode);
  syncPaymentMode();

  $('[data-field-list]').sortable({handle: '.ecm-drag', axis: 'y'});
  const settingsForm=document.querySelector('.ecm-settings-form');
  if(settingsForm)settingsForm.addEventListener('invalid',function(event){const panel=event.target.closest('[data-panel]');if(panel&&!panel.classList.contains('is-active'))$('.ecm-tabs [data-tab="'+panel.dataset.panel+'"]').trigger('click');},true);
	const eventForm=document.querySelector('.ecm-event-form');
	if(eventForm)eventForm.addEventListener('invalid',function(event){const section=event.target.closest('details');if(section)section.open=true;},true);

  function syncRecoveryFields() {
    var list = $('[data-recovery-list]');
    if (!list.length) return;
    var checked = {};
    list.find('input:checked').each(function () { checked[this.value] = true; });
    var items = [];
    $('[data-field-row]').each(function () {
      var keyInput = $(this).find('input[name$="[key]"]');
      var labelInput = $(this).find('input[name$="[label]"]');
      var type = $(this).find('select[name$="[type]"]').val();
      var key = String(keyInput.val() || '').replace(/[^a-zA-Z0-9_]/g, '').toLowerCase();
      if (!key || type === 'html' || type === 'checkbox' || type === 'guest') return;
      items.push({key: key, label: labelInput.val() || key});
    });
    list.empty();
    items.forEach(function (item) {
      var label = $('<label>').attr('data-recovery-item', item.key);
      $('<input>', {type: 'checkbox', name: 'settings[recovery_fields][]', value: item.key, checked: !!checked[item.key]}).appendTo(label);
      label.append(document.createTextNode(' '));
      $('<span>').text(item.label).appendTo(label);
      label.append(document.createTextNode(' '));
      $('<code>').text(item.key).appendTo(label);
      list.append(label);
    });
  }

  function escapeHtml(value) {
    return $('<div>').text(value == null ? '' : value).html();
  }

  function typeOptions(selected) {
    return Object.keys(ECM_ADMIN.fieldTypes).map(function (key) {
      return '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>' + escapeHtml(ECM_ADMIN.fieldTypes[key]) + '</option>';
    }).join('');
  }

  $('[data-add-field]').on('click', function () {
    var index = Date.now();
    var html = '<div class="ecm-builder-row" data-field-row>' +
      '<div class="ecm-drag" title="جابه‌جایی">⋮⋮</div><div class="ecm-builder-row__main">' +
      '<div class="ecm-admin-grid ecm-admin-grid--4">' +
      '<label>عنوان<input type="text" name="fields[' + index + '][label]" required></label>' +
      '<label>کلید انگلیسی<input type="text" name="fields[' + index + '][key]" pattern="[a-zA-Z][a-zA-Z0-9_]*" dir="ltr" required></label>' +
      '<label>نوع<select name="fields[' + index + '][type]">' + typeOptions('text') + '</select></label>' +
      '<label class="ecm-inline-check"><input type="checkbox" name="fields[' + index + '][required]" value="1"> ضروری</label></div>' +
      '<div class="ecm-admin-grid ecm-admin-grid--2"><label>Placeholder / محتوای HTML<input type="text" name="fields[' + index + '][placeholder]"></label>' +
      '<label>گزینه‌ها (هر خط یک مورد)<textarea name="fields[' + index + '][options]" rows="3"></textarea></label></div></div>' +
      '<button type="button" class="ecm-icon-button ecm-remove" data-remove-row aria-label="حذف">×</button></div>';
    $('[data-field-list]').append(html);
    syncRecoveryFields();
  });

  $('[data-add-session]').on('click', function () {
    var index = Date.now();
	var eventId = Number($(this).data('event-id') || 0);
    var html = '<div class="ecm-session-row" data-session-row><input type="hidden" name="sessions[' + index + '][id]" value="0"><input type="hidden" name="sessions[' + index + '][event_id]" value="' + eventId + '">' +
	  '<div class="ecm-admin-grid ecm-admin-grid--2"><label>عنوان نوبت<input type="text" name="sessions[' + index + '][title]" required></label>' +
	  '<label>روش ثبت حضور<select name="sessions[' + index + '][attendance_mode]"><option value="inherit">طبق تنظیم رویداد</option><option value="self_service">توسط خود کاربر</option><option value="operator_only">فقط اپراتور</option><option value="hybrid">ترکیبی</option></select></label></div>' +
	  '<div class="ecm-admin-grid ecm-admin-grid--3">' +
      '<label>شروع<input type="datetime-local" name="sessions[' + index + '][start]" required></label>' +
      '<label>پایان<input type="datetime-local" name="sessions[' + index + '][end]" required></label>' +
      '<label>حداکثر ثبت هر کارت<input type="number" min="1" max="99" name="sessions[' + index + '][max_entries]" value="1"></label></div>' +
      '<div class="ecm-admin-grid ecm-admin-grid--2"><label>پیغام موفقیت<textarea name="sessions[' + index + '][success_message]" rows="3">حضور شما با موفقیت ثبت شد.</textarea></label>' +
      '<label>پیغام ثبت تکراری/بیش از حد<textarea name="sessions[' + index + '][repeat_message]" rows="3">حضور این کارت قبلاً ثبت شده است.</textarea></label></div>' +
	  '<details class="ecm-session-sms"><summary>پیامک اختصاصی این نوبت</summary><div class="ecm-session-sms__body">' +
	  '<label class="ecm-switch"><input type="checkbox" name="sessions[' + index + '][sms][override]" value="1"><span>تنظیم پیامک حضور و تکرار در این نوبت جایگزین رویداد شود</span></label>' +
	  '<label>روش ارسال<select name="sessions[' + index + '][sms][mode]"><option value="inherit">طبق رویداد</option><option value="webservice">متن آزاد</option><option value="pattern">پترن</option></select></label>' +
	  sessionSmsRule(index, 'attendance', 'ثبت حضور موفق') + sessionSmsRule(index, 'repeat', 'ثبت حضور تکراری') + '</div></details>' +
      '<div class="ecm-session-row__footer"><label><input type="checkbox" name="sessions[' + index + '][enabled]" value="1" checked> فعال</label>' +
      '<button type="button" class="button-link-delete" data-remove-row>حذف نوبت</button></div></div>';
    $('[data-session-list]').append(html);
  });

  function sessionSmsRule(index, state, label) {
    var base = 'sessions[' + index + '][sms][rules][' + state + ']';
	var patternOptions = '<option value="">کد اختصاصی</option>';
	Object.keys(ECM_ADMIN.patternProfiles || {}).forEach(function (key) {
	  patternOptions += '<option value="' + escapeHtml(key) + '">' + escapeHtml(ECM_ADMIN.patternProfiles[key] || key) + '</option>';
	});
	    return '<div class="ecm-session-sms__rule"><label><input type="checkbox" name="' + base + '[enabled]" value="1"> ' + label + '</label>' +
		  '<div class="ecm-admin-grid ecm-admin-grid--2"><select name="' + base + '[mode]"><option value="inherit">طبق روش نوبت</option><option value="webservice">متن آزاد</option><option value="pattern">پترن</option></select><select name="' + base + '[audience_mode]"><option value="shared">قالب مشترک</option><option value="separate">مهمان مجزا</option></select></div>' +
		  '<textarea rows="2" name="' + base + '[text]" placeholder="متن معرف / مشترک"></textarea><select name="' + base + '[pattern_profile]">' + patternOptions + '</select><div class="ecm-admin-grid ecm-admin-grid--2">' +
	      '<input type="text" dir="ltr" name="' + base + '[pattern]" placeholder="کد پترن"><textarea rows="2" dir="ltr" name="' + base + '[params]" placeholder="name=value"></textarea></div>' +
		  '<details><summary>قالب مهمان</summary><div class="ecm-session-sms__body"><select name="' + base + '[guest_mode]"><option value="inherit">مانند معرف</option><option value="webservice">متن آزاد</option><option value="pattern">پترن</option></select><textarea rows="2" name="' + base + '[guest_text]" placeholder="متن مهمان"></textarea><select name="' + base + '[guest_pattern_profile]">' + patternOptions + '</select><input type="text" dir="ltr" name="' + base + '[guest_pattern]" placeholder="کد پترن مهمان"><textarea rows="2" dir="ltr" name="' + base + '[guest_params]" placeholder="name=value"></textarea></div></details></div>';
  }

  $(document).on('click', '[data-remove-row]', function () {
    if (window.confirm(ECM_ADMIN.confirm)) {
      $(this).closest('[data-field-row], [data-session-row]').remove();
      syncRecoveryFields();
    }
  });

  $(document).on('input change', '[data-field-row] input[name$="[key]"], [data-field-row] input[name$="[label]"], [data-field-row] select[name$="[type]"]', syncRecoveryFields);

	$(document).on('click', '[data-add-sms-pattern]', function () {
	  var id = 'pattern_' + Date.now();
	  var base = 'sms[profiles][' + id + ']';
	  var html = '<details class="ecm-pattern-card" data-sms-pattern open><summary><span><strong>پترن جدید</strong><code>بدون کد</code></span></summary><div class="ecm-pattern-card__body"><div class="ecm-pattern-card__head"><code>' + id + '</code><button type="button" class="button-link-delete" data-remove-sms-pattern>حذف</button></div>' +
		'<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>عنوان پترن</span><input type="text" name="' + base + '[label]" required></label>' +
		'<label class="ecm-setting"><span>کد پترن</span><input type="text" dir="ltr" name="' + base + '[code]" required></label></div>' +
		'<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>کاربرد</span><select name="' + base + '[purpose]"><option value="general">عمومی</option><option value="registration">ثبت‌نام</option><option value="attendance">حضور</option><option value="recovery">بازیابی</option><option value="otp">کد تأیید OTP</option></select></label><label class="ecm-setting"><span>خط ارسال اختصاصی</span><input type="text" dir="ltr" name="' + base + '[sender]" placeholder="+983000505"></label></div>' +
		'<div class="ecm-admin-grid ecm-admin-grid--2"><label class="ecm-setting"><span>نگاشت پارامترها؛ هر خط name=value</span><textarea rows="5" dir="ltr" name="' + base + '[params]" placeholder="name={name}"></textarea></label><label class="ecm-setting"><span>نوع پارامترها</span><textarea rows="5" dir="ltr" name="' + base + '[param_types]" placeholder="name=string"></textarea></label></div>' +
		'<div class="ecm-check-grid"><label><input type="checkbox" name="' + base + '[enabled]" value="1" checked> فعال</label><label><input type="checkbox" name="' + base + '[requires_payment]" value="1"> نیازمند پرداخت موفق</label></div></div></details>';
	  var target = document.querySelector('[data-sms-pattern-list="workspace"]') || document.querySelector('[data-sms-pattern-list]');
	  if (target) target.insertAdjacentHTML('beforeend', html);
	});
	$(document).on('click', '[data-remove-sms-pattern]', function () {
	  if (window.confirm('این پترن از کتابخانه حذف شود؟')) $(this).closest('[data-sms-pattern]').remove();
	});

	(function initSmsWorkspace() {
	  var workspace = document.querySelector('[data-sms-workspace]');
	  if (!workspace) return;
	  workspace.addEventListener('invalid', function (event) {
		var panel = event.target.closest('[data-sms-settings-panel]');
		if (panel) { var tab = workspace.querySelector('[data-sms-settings-tab="' + panel.getAttribute('data-sms-settings-panel') + '"]'); if (tab) tab.click(); }
		var details = event.target.closest('details'); if (details) details.open = true;
	  }, true);
	  workspace.querySelectorAll('[data-sms-settings-tab]').forEach(function (button) {
		button.addEventListener('click', function () {
		  var key = button.getAttribute('data-sms-settings-tab');
		  workspace.querySelectorAll('[data-sms-settings-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
		  workspace.querySelectorAll('[data-sms-settings-panel]').forEach(function (panel) { var show = panel.getAttribute('data-sms-settings-panel') === key; panel.hidden = !show; panel.classList.toggle('is-active', show); });
		});
	  });
	  workspace.querySelectorAll('[data-sms-state-tab]').forEach(function (button) {
		button.addEventListener('click', function () {
		  var key = button.getAttribute('data-sms-state-tab');
		  workspace.querySelectorAll('[data-sms-state-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
		  workspace.querySelectorAll('[data-sms-state-panel]').forEach(function (panel) { var show = panel.getAttribute('data-sms-state-panel') === key; panel.hidden = !show; panel.classList.toggle('is-active', show); });
		});
	  });

	  function resolvedMode(scope) {
		var own = scope.querySelector('[data-sms-mode-select]');
		if (!own) {
		  var panel = scope.closest('[data-sms-state-panel]');
		  own = panel ? panel.querySelector('.ecm-admin-grid [data-sms-mode-select]') : null;
		}
		var mode = own ? own.value : 'inherit';
		if (mode === 'inherit' && scope.closest('[data-sms-guest-fields]')) {
		  var hostSelect = scope.closest('[data-sms-state-panel]').querySelector('.ecm-admin-grid [data-sms-mode-select]');
		  mode = hostSelect ? hostSelect.value : 'inherit';
		}
		if (mode === 'inherit') {
		  var fallback = workspace.querySelector('select[name="sms[mode]"]');
		  mode = fallback ? fallback.value : 'webservice';
		}
		return mode;
	  }
	  function syncModes() {
		workspace.querySelectorAll('[data-sms-mode-scope]').forEach(function (scope) {
		  var pattern = resolvedMode(scope) === 'pattern';
		  Array.prototype.forEach.call(scope.children, function (el) { if (el.hasAttribute('data-sms-free-fields')) el.hidden = pattern; if (el.hasAttribute('data-sms-pattern-fields')) el.hidden = !pattern; });
		});
	  }
	  function syncAudiences() {
		workspace.querySelectorAll('[data-sms-state-panel]').forEach(function (panel) {
		  var select = panel.querySelector('[data-sms-audience-select]');
		  var guest = panel.querySelector('[data-sms-guest-fields]');
		  if (select && guest) guest.hidden = select.value !== 'separate';
		});
		syncModes();
	  }
	  workspace.addEventListener('change', function (event) {
		if (event.target.matches('[data-sms-mode-select], select[name="sms[mode]"]')) syncModes();
		if (event.target.matches('[data-sms-audience-select]')) syncAudiences();
		if (event.target.matches('select[name$="[pattern_profile]"]') && event.target.value) {
		  var scope = event.target.closest('[data-sms-mode-scope]');
		  var select = scope ? scope.querySelector('[data-sms-mode-select]') : null;
		  if (!select) select = event.target.closest('[data-sms-state-panel]').querySelector('.ecm-admin-grid [data-sms-mode-select]');
		  if (select) { select.value = 'pattern'; syncModes(); }
		}
		if (event.target.matches('[data-sms-state-panel] input[type="checkbox"][name$="[enabled]"]')) {
		  var panel = event.target.closest('[data-sms-state-panel]');
		  var tab = workspace.querySelector('[data-sms-state-tab="' + panel.getAttribute('data-sms-state-panel') + '"] b');
		  if (tab) { tab.textContent = event.target.checked ? 'فعال' : 'خاموش'; tab.className = event.target.checked ? 'is-on' : 'is-off'; }
		}
	  });
	  workspace.querySelectorAll('[data-copy-variable]').forEach(function (button) {
		button.addEventListener('click', function () {
		  var value = button.getAttribute('data-copy-variable') || '';
		  var done = function () { button.classList.add('is-copied'); window.setTimeout(function () { button.classList.remove('is-copied'); }, 1200); };
		  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(value).then(done);
		  else { var temp = document.createElement('textarea'); temp.value = value; document.body.appendChild(temp); temp.select(); try { document.execCommand('copy'); done(); } catch (error) {} temp.remove(); }
		});
	  });
	  syncAudiences();
	}());

  $(document).on('click', '[data-ecm-report-print]', function () { window.print(); });
  $(document).on('click','[data-columns]',function(){ $(this).closest('form').find('input[name="cols[]"]').prop('checked',$(this).data('columns')==='all'); });

  $(document).on('click', '[data-copy-support]', function () {
    var button = this;
    var textarea = document.getElementById('ecm-support-report');
    if (!textarea) return;
    var done = function () {
      var original = button.textContent;
      button.textContent = button.getAttribute('data-copy-done') || 'کپی شد';
      window.setTimeout(function () { button.textContent = original; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(textarea.value).then(done);
      return;
    }
    textarea.focus();
    textarea.select();
    try { document.execCommand('copy'); done(); } catch (error) { /* Selection remains for manual copy. */ }
  });

  (function initSmsAutoTest() {
    var root = document.querySelector('[data-ecm-sms-auto-test]');
    if (!root || typeof window.ajaxurl !== 'string') return;
    var title = root.querySelector('[data-auto-test-title]');
    var message = root.querySelector('[data-auto-test-message]');
    var retry = root.querySelector('[data-auto-test-retry]');
    var balance = root.querySelector('[data-auto-test-balance]');
    var pulse = root.querySelector('.ecm-sms-auto-health__pulse');

    function render(data) {
      var ok = !!data.success;
      root.classList.toggle('is-success', ok);
      root.classList.toggle('is-error', !ok);
      if (pulse) pulse.classList.remove('is-loading');
      if (title) title.textContent = ok ? 'اتصال درگاه تأیید شد' : 'اتصال درگاه تأیید نشد';
      if (message) message.textContent = data.message || (ok ? 'درگاه آماده است.' : 'پاسخ معتبر دریافت نشد.');
      if (retry) retry.hidden = ok;
      if (balance) {
        balance.hidden = !data.balance_display;
        balance.textContent = data.balance_display ? 'موجودی: ' + data.balance_display : '';
      }
    }

    function run(force) {
      root.classList.remove('is-success', 'is-error');
      if (pulse) pulse.classList.add('is-loading');
      if (title) title.textContent = force ? 'تلاش مجدد برای اتصال' : 'بررسی خودکار اتصال درگاه';
      if (message) message.textContent = 'در حال دریافت پاسخ امن از درگاه فعال…';
      if (retry) retry.hidden = true;
      var body = new URLSearchParams();
      body.set('action', 'ecm_sms_auto_test');
      body.set('nonce', root.getAttribute('data-nonce') || '');
      if (force) body.set('force', '1');
      window.fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
        .then(function (response) { return response.json(); })
        .then(function (payload) { if (!payload || !payload.success) throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'پاسخ نامعتبر'); render(payload.data || {}); })
        .catch(function (error) { render({ success: false, message: error.message || 'ارتباط با وردپرس برقرار نشد.' }); });
    }

    if (retry) retry.addEventListener('click', function () { run(true); });
    run(false);
  }());

	(function initSmsTestMode() {
	  var select = document.querySelector('select[name="test_send_mode"]');
	  if (!select) return;
	  var form = select.closest('form');
	  function field(name) { var input = form.querySelector('[name="' + name + '"]'); return input ? input.closest('label') : null; }
	  function sync() {
		var pattern = select.value === 'pattern';
		var text = field('test_message'); if (text) text.hidden = pattern;
		['test_pattern_profile','test_pattern_code','test_pattern_params'].forEach(function (name) { var label = field(name); if (label) label.hidden = !pattern; });
	  }
	  select.addEventListener('change', sync); sync();
	}());

  var appearancePresets = {
    light: {
      brand_navy:'#0B1F46', brand_blue:'#3A70A7', brand_gold:'#E3B336', surface_ice:'#EAF3F8', surface_white:'#FFFFFF',
      text_color:'#0B1F46', muted_color:'#405775', line_color:'#CEDBE5', success_color:'#166534', danger_color:'#B91C1C', warning_color:'#92400E'
    },
    dark: {
      brand_navy:'#172033', brand_blue:'#57A9FF', brand_gold:'#E3B336', surface_ice:'#080B12', surface_white:'#111722',
      text_color:'#F5F7FA', muted_color:'#AAB4C5', line_color:'#2A3548', success_color:'#4ADE80', danger_color:'#FB7185', warning_color:'#FBBF24'
    }
  };

  function appearanceField(key) {
    return document.querySelector('[name="settings[' + key + ']"]');
  }

  function updateAppearancePreview() {
    var preview = document.querySelector('[data-appearance-preview]');
    if (!preview) return;
    var colorVars = {
      brand_navy:'--ecm-navy', brand_blue:'--ecm-blue', brand_gold:'--ecm-gold', surface_ice:'--ecm-ice', surface_white:'--ecm-white',
      text_color:'--ecm-text', muted_color:'--ecm-muted', line_color:'--ecm-line', success_color:'--ecm-success', danger_color:'--ecm-danger', warning_color:'--ecm-warning'
    };
    Object.keys(colorVars).forEach(function (key) {
      var field = appearanceField(key);
      if (field) preview.style.setProperty(colorVars[key], field.value);
    });
    var sizeVars = {body_size:'--ecm-body', radius:'--ecm-radius', control_radius:'--ecm-control-radius', button_radius:'--ecm-button-radius', button_height:'--ecm-button-height'};
    Object.keys(sizeVars).forEach(function (key) {
      var field = appearanceField(key);
      if (field) preview.style.setProperty(sizeVars[key], Math.max(0, Number(field.value) || 0) + 'px');
    });
    var lineHeight = appearanceField('line_height');
    var headingScale = appearanceField('heading_scale');
    if (lineHeight) preview.style.setProperty('--ecm-line-height', (Number(lineHeight.value) || 190) / 100);
    if (headingScale) preview.style.setProperty('--ecm-heading-scale', (Number(headingScale.value) || 100) / 100);
    var font = appearanceField('font_family');
    if (font) preview.style.setProperty('--ecm-font', font.value === 'system' ? '-apple-system,BlinkMacSystemFont,"Segoe UI",Tahoma,Arial,sans-serif' : (font.value === 'tahoma' ? 'Tahoma,Arial,sans-serif' : 'inherit'));
    var shadow = appearanceField('shadow_mode');
    if (shadow) preview.style.setProperty('--ecm-shadow', shadow.value === 'strong' ? '0 24px 70px rgba(11,31,70,.18)' : (shadow.value === 'soft' ? '0 12px 36px rgba(11,31,70,.09)' : 'none'));
  }

  $(document).on('click', '[data-appearance-preset]', function () {
    var preset = appearancePresets[this.dataset.appearancePreset];
    if (!preset) return;
    Object.keys(preset).forEach(function (key) {
      var field = appearanceField(key);
      if (field) field.value = preset[key];
    });
    updateAppearancePreview();
  });
  $(document).on('input change', '[data-panel="appearance"] input,[data-panel="appearance"] select', updateAppearancePreview);
  updateAppearancePreview();

  $(document).on('change', '[data-ecm-select-all]', function () {
    var form = this.closest('form');
    if (!form) return;
    form.querySelectorAll('input[name="registration_ids[]"]').forEach(function (checkbox) {
      checkbox.checked = !!this.checked;
    }, this);
  });
})(jQuery);
