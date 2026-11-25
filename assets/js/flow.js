document.addEventListener('click', async (event) => {
  const preview = event.target.closest('[data-ecm-receipt-preview]');
  if (preview && typeof HTMLDialogElement !== 'undefined' && HTMLDialogElement.prototype.showModal) {
    event.preventDefault();
    const dialog = document.createElement('dialog');
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = 'بستن';
    close.addEventListener('click', () => dialog.close());
    const image = document.createElement('img');
    image.src = preview.href;
    image.alt = 'تصویر رسید';
    image.style.maxWidth = 'min(85vw, 900px)';
    image.style.maxHeight = '78vh';
    dialog.append(image, close);
    dialog.addEventListener('close', () => dialog.remove(), {once:true});
    document.body.append(dialog);
    dialog.showModal();
    return;
  }
  const button = event.target.closest('[data-ecm-copy]');
  if (!button) return;
  const value = button.dataset.ecmCopy;
  const status = button.closest('form').querySelector('[data-ecm-copy-status]');
  try {
    await navigator.clipboard.writeText(value);
    if (status) status.textContent = 'کپی شد';
  } catch (error) {
    if (status) status.textContent = value + ' را دستی کپی کنید.';
  }
});

document.querySelectorAll('[data-flow-expiry]').forEach((root) => {
  const initialized = Date.now();
  const clock = Number(root.dataset.flowNow);
  const counter = root.querySelector('[data-flow-otp-timer]');
  const resend = root.querySelector('[data-flow-resend-button]');
  const verify = root.querySelector('[data-flow-verify]');
  const update = () => {
    const now = clock + Math.floor((Date.now() - initialized) / 1000);
    const remaining = Math.max(0, Number(root.dataset.flowExpiry) - now);
    const wait = Math.max(0, Number(root.dataset.flowResend) - now);
    const format = (seconds) => String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
    counter.textContent = remaining ? format(remaining) : 'منقضی شد';
    resend.textContent = wait ? 'درخواست مجدد کد (' + format(wait) + ')' : 'درخواست مجدد کد';
    resend.disabled = wait > 0;
    verify.disabled = remaining === 0;
    return remaining || wait;
  };
  if (update()) {
    const timer = setInterval(() => { if (!update()) clearInterval(timer); }, 250);
  }
});
