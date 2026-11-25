document.querySelectorAll('[data-flow-config-editor]').forEach((editor) => {
  const list = editor.querySelector('[data-flow-config-items]');
  const add = editor.querySelector('[data-flow-row-add]');
  const empty = editor.querySelector('[data-flow-config-empty]');
  const template = editor.querySelector('template');
  const limit = Number(editor.dataset.limit);
  let index = list.children.length;

  function update() {
    const rows = Array.from(list.children);
    empty.hidden = rows.length > 0;
    add.disabled = rows.length >= limit;
    rows.forEach((row, position) => {
      row.querySelector('[data-flow-row-number]').textContent = String(position + 1);
      row.querySelector('[data-flow-row-up]').disabled = position === 0;
      row.querySelector('[data-flow-row-down]').disabled = position === rows.length - 1;
    });
  }

  add.addEventListener('click', () => {
    if (list.children.length >= limit) return;
    const fragment = template.content.cloneNode(true);
    const field = fragment.querySelector('input:not([type="hidden"]), select, textarea');
    fragment.querySelectorAll('[name]').forEach((input) => {
      input.name = input.name.replace('__row__', String(index));
    });
    index += 1;
    list.append(fragment);
    update();
    field?.focus();
  });

  list.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    const row = button?.closest('[data-flow-config-row]');
    if (!row) return;
    if (button.hasAttribute('data-flow-row-remove')) {
      row.remove();
      add.focus();
    } else if (button.hasAttribute('data-flow-row-up') && row.previousElementSibling) {
      row.previousElementSibling.before(row);
    } else if (button.hasAttribute('data-flow-row-down') && row.nextElementSibling) {
      row.nextElementSibling.after(row);
    }
    update();
  });

  update();
});

document.querySelectorAll('[data-flow-reminder-mode]').forEach((select) => {
  const section = select.closest('details');
  select.addEventListener('change', () => {
    const custom = select.value === 'custom';
    section.querySelector('[data-flow-reminder-custom]').hidden = !custom;
    section.querySelector('[data-flow-reminder-legacy]').hidden = custom;
  });
});
