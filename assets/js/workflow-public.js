/* Workflow-only field prefill. Public Classic scripts remain untouched. */
(function () {
  'use strict';
  document.querySelectorAll('[data-ecm-flow-stored]').forEach(function (grid) {
    var saved;
    try { saved = JSON.parse(grid.getAttribute('data-ecm-flow-stored') || '{}'); }
    catch (error) { return; }
    if (!saved || typeof saved !== 'object') return;
    grid.querySelectorAll('input[name^="ecm_field["],select[name^="ecm_field["],textarea[name^="ecm_field["]').forEach(function (control) {
      var matched = /^ecm_field\[([a-z][a-z0-9_]*)\]/.exec(control.name);
      if (!matched || typeof saved[matched[1]] !== 'string') return;
      var value = saved[matched[1]];
      if (control.type === 'checkbox' || control.type === 'radio') {
        var selected = value.split('،').map(function (item) { return item.trim(); });
        control.checked = selected.indexOf(control.value) !== -1;
      } else if (control.type !== 'file') control.value = value;
    });
    var guests = [];
    try { guests = JSON.parse(saved.__guests || '[]'); } catch (error) { /* Ignore invalid historical data. */ }
    if (!Array.isArray(guests)) return;
    grid.querySelectorAll('[data-guest-field]').forEach(function (field) {
      var key = field.getAttribute('data-guest-field');
      var matching = guests.filter(function (guest) { return guest && guest.key === key; });
      var add = field.querySelector('[data-add-guest]');
      var list = field.querySelector('[data-guest-list]');
      if (!add || !list) return;
      while (list.children.length < matching.length) add.click();
      Array.from(list.children).forEach(function (row, index) {
        if (!matching[index]) return;
        var name = row.querySelector('input[name$="[name]"]');
        var phone = row.querySelector('input[name$="[phone]"]');
        if (name) name.value = String(matching[index].name || '');
        if (phone) phone.value = String(matching[index].phone || '');
      });
    });
  });
})();
