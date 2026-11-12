'use strict';
(function () {
 if (window.__remitAppInit) return;
 window.__remitAppInit = true;

 function openDialogModal(dialog, openers, bodyClass, onOpen) {
  if (!dialog) return;
  try {
   if (typeof dialog.showModal === 'function') {
    if (!dialog.open) dialog.showModal();
   } else {
    dialog.setAttribute('open', '');
   }
  } catch {
   dialog.setAttribute('open', '');
  }
  if (!dialog.hasAttribute('open')) dialog.setAttribute('open', '');
  openers.forEach(btn => btn.setAttribute('aria-expanded', 'true'));
  if (bodyClass) document.body.classList.add(bodyClass);
  if (typeof onOpen === 'function') onOpen();
 }

 function closeDialogModal(dialog, openers, bodyClass, returnFocusTo) {
  if (!dialog) return;
  try {
   if (typeof dialog.close === 'function' && dialog.open) {
    dialog.close();
   }
  } catch {}
  dialog.removeAttribute('open');
  openers.forEach(btn => btn.setAttribute('aria-expanded', 'false'));
  if (bodyClass) document.body.classList.remove(bodyClass);
  if (returnFocusTo && typeof returnFocusTo.focus === 'function') {
   try { returnFocusTo.focus(); } catch {}
  }
 }

 function init() {
  // 1. Welcome & Quick Guide Modal ([data-guide-open], #quick-guide-modal / [data-guide-modal], [data-guide-close])
  const guideModal = document.getElementById('quick-guide-modal') || document.querySelector('[data-guide-modal]');
  const guideOpeners = Array.from(document.querySelectorAll('[data-guide-open]'));
  let lastGuideOpener = null;

  if (guideModal && guideOpeners.length) {
   const openGuide = trigger => {
    lastGuideOpener = trigger || guideOpeners[0] || null;
    openDialogModal(guideModal, guideOpeners, 'guide-is-open', () => {
     const closeBtn = guideModal.querySelector('.guide-close[data-guide-close]');
     if (closeBtn && document.activeElement !== closeBtn) {
      try { closeBtn.focus(); } catch {}
     }
    });
   };

   const closeGuide = restoreFocus => {
    closeDialogModal(guideModal, guideOpeners, 'guide-is-open', restoreFocus ? lastGuideOpener : null);
   };

   guideOpeners.forEach(btn => {
    btn.addEventListener('click', event => {
     event.preventDefault();
     openGuide(btn);
    });
   });

   guideModal.querySelectorAll('[data-guide-close]').forEach(el => {
    el.addEventListener('click', () => {
     const isLink = el.tagName === 'A';
     closeGuide(!isLink);
    });
   });

   guideModal.addEventListener('click', event => {
    if (event.target === guideModal) {
     closeGuide(true);
     return;
    }
    const card = guideModal.querySelector('.guide-modal-card');
    if (card && event.clientX !== 0 && event.clientY !== 0) {
     const r = card.getBoundingClientRect();
     if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) {
      closeGuide(true);
     }
    }
   });

   guideModal.addEventListener('cancel', event => {
    event.preventDefault();
    closeGuide(true);
   });

   guideModal.addEventListener('close', () => {
    guideOpeners.forEach(btn => btn.setAttribute('aria-expanded', 'false'));
    document.body.classList.remove('guide-is-open');
    if (lastGuideOpener && typeof lastGuideOpener.focus === 'function') {
     try { lastGuideOpener.focus(); } catch {}
    }
   });
  }

  // 2. Mobile Navigation Drawer (#mobile-menu, [data-menu-open], [data-menu-close])
  const mobileMenu = document.getElementById('mobile-menu');
  const menuOpeners = Array.from(document.querySelectorAll('[data-menu-open]'));
  let lastMenuOpener = null;

  if (mobileMenu && menuOpeners.length) {
   document.documentElement.classList.add('has-mobile-menu');
   const closeMenu = restoreFocus => {
    closeDialogModal(mobileMenu, menuOpeners, 'menu-is-open', restoreFocus ? lastMenuOpener : null);
   };
   menuOpeners.forEach(btn => {
    btn.addEventListener('click', () => {
     lastMenuOpener = btn;
     openDialogModal(mobileMenu, menuOpeners, 'menu-is-open');
    });
   });
   mobileMenu.querySelectorAll('[data-menu-close]').forEach(btn => {
    btn.addEventListener('click', () => closeMenu(true));
   });
   mobileMenu.addEventListener('click', event => {
    if (event.target === mobileMenu) closeMenu(true);
   });
   mobileMenu.addEventListener('cancel', event => {
    event.preventDefault();
    closeMenu(true);
   });
   mobileMenu.addEventListener('close', () => {
    menuOpeners.forEach(btn => btn.setAttribute('aria-expanded', 'false'));
    document.body.classList.remove('menu-is-open');
   });
  }

  // 3. Ledger client-side search/status filter
  const search = document.querySelector('#ledger-search');
  const statusFilter = document.querySelector('#ledger-status');
  function filterLedger() {
   const q = (search?.value || '').trim().toLowerCase();
   const status = statusFilter?.value || '';
   const rows = Array.from(document.querySelectorAll('#ledger tbody tr[data-status]'));
   let visible = 0;
   rows.forEach(row => {
    row.hidden = !(row.textContent.toLowerCase().includes(q) && (!status || row.dataset.status === status));
    if (!row.hidden) visible++;
   });
   const result = document.querySelector('#ledger-result');
   if (result && search) result.textContent = `${visible} of ${rows.length} loaded reports shown`;
   const empty = document.querySelector('#ledger-no-results');
   if (empty) empty.hidden = rows.length === 0 || visible > 0;
  }
  search?.addEventListener('input', filterLedger);
  statusFilter?.addEventListener('change', filterLedger);
  filterLedger();

  // 4. Progress bar animations & Offering preview sync
  document.querySelectorAll('[data-percent]').forEach(bar => {
   bar.style.width = `${Math.max(0, Math.min(100, Number(bar.dataset.percent)))}%`;
  });
  const updateOfferingPreview = () => {
   const g = document.getElementById('gross');
   if (!g) return;
   g.dispatchEvent(new Event('input'));
   g.dispatchEvent(new Event('change'));
  };
  setTimeout(updateOfferingPreview, 50);
  setTimeout(updateOfferingPreview, 300);
  setTimeout(updateOfferingPreview, 800);

  // 5. Admin bulk pending delete form
  const deleteForm = document.getElementById('delete-reports-form');
  if (deleteForm) {
   const checks = Array.from(deleteForm.querySelectorAll('.report-delete-check'));
   const all = deleteForm.querySelector('[data-select-all-reports]');
   const btn = deleteForm.querySelector('button[type=submit]');
   const update = () => {
    const selected = checks.filter(c => c.checked).length;
    if (all) {
     all.checked = selected > 0 && selected === checks.length;
     all.indeterminate = selected > 0 && selected < checks.length;
    }
    if (btn) {
     btn.disabled = selected === 0;
     btn.textContent = selected ? `Delete ${selected} pending` + ' →' : 'Delete selected pending →';
    }
   };
   checks.forEach(c => c.addEventListener('change', update));
   all?.addEventListener('change', () => {
    checks.forEach(c => { if (!c.disabled) c.checked = all.checked; });
    update();
   });
   deleteForm.addEventListener('submit', event => {
    if (!deleteForm.dataset.confirmMessage) return;
    if (!confirm(deleteForm.dataset.confirmMessage)) event.preventDefault();
   });
   update();
  }

  // 6. Payment receipt dialog ([data-proof-open], #receipt-dialog)
  const receiptDialog = document.getElementById('receipt-dialog');
  let lastProofTrigger = null;
  if (receiptDialog) {
   document.addEventListener('click', event => {
    const trigger = event.target?.closest?.('[data-proof-open]');
    if (!trigger) return;
    event.preventDefault();
    lastProofTrigger = trigger;
    const img = receiptDialog.querySelector('img');
    if (img) {
     img.src = trigger.getAttribute('href') || trigger.dataset.proofOpen || '';
     img.hidden = false;
    }
    openDialogModal(receiptDialog, [], '');
   });
   receiptDialog.querySelectorAll('[data-proof-close],button').forEach(btn => {
    btn.addEventListener('click', () => closeDialogModal(receiptDialog, [], '', lastProofTrigger));
   });
   receiptDialog.addEventListener('cancel', event => {
    event.preventDefault();
    closeDialogModal(receiptDialog, [], '', lastProofTrigger);
   });
  }

  // 7. Server history filter auto-submit ([data-history-form])
  document.querySelectorAll('[data-history-form]').forEach(form => {
   let timer = null;
   form.querySelectorAll('select').forEach(sel => sel.addEventListener('change', () => form.requestSubmit ? form.requestSubmit() : form.submit()));
   form.querySelectorAll('input[type="search"]').forEach(inp => {
    inp.addEventListener('input', () => {
     clearTimeout(timer);
     timer = setTimeout(() => {
      try { sessionStorage.setItem('remit-focus-id', inp.id || inp.name || ''); } catch {}
      form.requestSubmit ? form.requestSubmit() : form.submit();
     }, 350);
    });
   });
  });
  try {
   const focusId = sessionStorage.getItem('remit-focus-id');
   if (focusId) {
    sessionStorage.removeItem('remit-focus-id');
    const el = document.getElementById(focusId) || document.querySelector(`[name="${focusId}"]`);
    if (el && typeof el.focus === 'function') el.focus();
   }
  } catch {}

  // 8. Uppercase church inputs ([data-church-caps])
  document.querySelectorAll('[data-church-caps]').forEach(el => {
   el.addEventListener('input', () => {
    const start = el.selectionStart, end = el.selectionEnd;
    const upper = el.value.toUpperCase();
    if (el.value !== upper) {
     el.value = upper;
     if (typeof start === 'number' && typeof end === 'number') {
      try { el.setSelectionRange(start, end); } catch {}
     }
    }
   });
  });

  // 9. Impersonation read-only enforcement ([data-impersonation])
  if (document.querySelector('[data-impersonation]')) {
   document.querySelectorAll('main form[method="post"]').forEach(form => {
    const act = form.querySelector('[name="action"]')?.getAttribute('value');
    if (act === 'impersonation_return') return;
    form.querySelectorAll('button').forEach(b => { b.disabled = true; });
   });
  }

  // 10. Open targeted <details> panels when navigated by URL hash
  function openHashDisclosure() {
   const hash = (location.hash || '').replace(/^#/, '');
   if (!hash) return;
   const target = document.getElementById(hash);
   if (!target) return;
   if (target.tagName === 'DETAILS') target.open = true;
   const parentDetails = target.closest('details');
   if (parentDetails) parentDetails.open = true;
  }
  openHashDisclosure();
  window.addEventListener('hashchange', openHashDisclosure);
 }

 if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
 } else {
  init();
 }
})();
