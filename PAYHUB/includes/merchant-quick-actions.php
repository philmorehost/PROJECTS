<?php
// php-version/includes/merchant-quick-actions.php
?>
<!-- Quick Actions Floating Button -->
<div class="fixed bottom-8 right-8 group z-40" x-data="{ open: false }">
    <div class="absolute bottom-full right-0 mb-4 flex flex-col items-end gap-3 opacity-0 group-hover:opacity-100 pointer-events-none group-hover:pointer-events-auto transition-all translate-y-4 group-hover:translate-y-0">
        <a href="<?php echo BASE_URL; ?>merchant/payouts.php" class="flex items-center gap-3 bg-white px-4 py-2.5 rounded-xl shadow-xl border border-slate-100 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all whitespace-nowrap">
            <i data-lucide="arrow-down-left" class="text-amber-500 w-4.5 h-4.5"></i>
            Request Payout
        </a>
        <a href="<?php echo BASE_URL; ?>merchant/invoices.php" class="flex items-center gap-3 bg-white px-4 py-2.5 rounded-xl shadow-xl border border-slate-100 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all whitespace-nowrap">
            <i data-lucide="file-text" class="text-indigo-500 w-4.5 h-4.5"></i>
            Create Invoice
        </a>
        <a href="<?php echo BASE_URL; ?>merchant/tickets.php" class="flex items-center gap-3 bg-white px-4 py-2.5 rounded-xl shadow-xl border border-slate-100 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all whitespace-nowrap">
            <i data-lucide="ticket" class="text-emerald-500 w-4.5 h-4.5"></i>
            Open Ticket
        </a>
    </div>
    <button class="w-14 h-14 bg-indigo-600 rounded-full flex items-center justify-center text-white shadow-2xl shadow-indigo-200 hover:bg-indigo-700 transition-all hover:scale-110 active:scale-95">
        <i data-lucide="plus" class="w-7 h-7 group-hover:rotate-45 transition-transform duration-300"></i>
    </button>
</div>
