(function () {
  'use strict';
  document.querySelectorAll('.ecm-flow-admin [data-guest-field]').forEach(function (field) {
    var list = field.querySelector('[data-guest-list]');
    var addButton = field.querySelector('[data-add-guest]');
    var count = field.querySelector('[data-guest-count]');
    var max = Math.max(0, Number(field.dataset.guestMax || 0));
    var required = field.dataset.guestRequired === '1';
    var key = field.dataset.guestField;
    function sync() {
      var n = list.children.length;
      count.textContent = n + ' از ' + max;
      addButton.hidden = n >= max;
      list.querySelectorAll('[data-guest-number]').forEach(function (el, i) { el.textContent = 'مهمان ' + (i + 1); });
    }
    function addGuest() {
      if (list.children.length >= max) return;
      var row = document.createElement('div');
      var keyId = String(Date.now()) + Math.random().toString(36).slice(2, 8);
      row.className = 'ecm-guest-row';
      row.innerHTML = '<div class="ecm-guest-row__title"><strong data-guest-number></strong><button type="button" data-remove-guest>حذف</button></div>' +
        '<div><label>نام مهمان<input type="text" maxlength="190" required autocomplete="name" name="ecm_guest[' + key + '][' + keyId + '][name]"></label>' +
        '<label>موبایل مهمان<input type="tel" required inputmode="numeric" maxlength="11" pattern="[0۰٠][9۹٩][0-9۰-۹٠-٩]{9}" name="ecm_guest[' + key + '][' + keyId + '][phone]"></label></div>';
      row.querySelector('[data-remove-guest]').addEventListener('click', function () { row.remove(); if (required && !list.children.length) addGuest(); sync(); });
      list.appendChild(row);
      sync();
    }
    addButton.addEventListener('click', addGuest);
    if (required && max) addGuest();
    sync();
  });
  document.querySelectorAll('.ecm-flow-admin .ecm-flow-preview [data-ecm-required-group]').forEach(function (group) {
    var boxes = Array.from(group.querySelectorAll('input[type="checkbox"]'));
    if (!boxes.length) return;
    function check() { boxes[0].setCustomValidity(boxes.some(function (b) { return b.checked; }) ? '' : 'حداقل یک گزینه را انتخاب کنید.'); }
    boxes.forEach(function (box) { box.addEventListener('change', check); });
    check();
  });
  document.querySelectorAll('.ecm-flow-admin .ecm-flow-stage form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
    });
  });
})();
