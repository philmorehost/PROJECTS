<?php
require_once '../includes/functions.php';
if (!isLoggedIn() || !isAdmin()) redirect('../login.php');
$user = getAuthUser();
$pageTitle = 'Transactions - PayHub Admin';
include '../includes/dashboard-head.php';
?>
<body class="flex h-screen overflow-hidden bg-slate-50 text-slate-900" x-data="transactionReport('admin')">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex min-w-0 flex-1 flex-col overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-[0.18em] text-indigo-600">Finance operations · <span class="uppercase"><?php echo htmlspecialchars(admin_reporting_mode(), ENT_QUOTES, 'UTF-8'); ?> report</span></p>
                        <h1 class="text-3xl font-extrabold tracking-tight text-slate-950">Transactions</h1>
                        <p class="mt-2 text-sm text-slate-500">A complete, searchable ledger of platform payments and their processing history.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a :href="exportUrl('csv')" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50"><i data-lucide="file-spreadsheet" class="h-4 w-4 text-emerald-600"></i>Download CSV</a>
                        <a :href="exportUrl('pdf')" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-slate-800"><i data-lucide="file-down" class="h-4 w-4"></i>Download PDF</a>
                    </div>
                </header>

                <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <button @click="setStatus('')" class="rounded-2xl border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" :class="status === '' ? 'border-indigo-400 ring-2 ring-indigo-100' : 'border-slate-200'">
                        <span class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-slate-400">All transactions <i data-lucide="layers-3" class="h-4 w-4"></i></span><span class="mt-3 block text-2xl font-extrabold text-slate-900" x-text="number(summary.total)"></span><span class="mt-1 block text-xs text-slate-500">In selected period</span>
                    </button>
                    <button @click="setStatus('success')" class="rounded-2xl border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" :class="status === 'success' ? 'border-emerald-400 ring-2 ring-emerald-100' : 'border-slate-200'">
                        <span class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-slate-400">Successful <i data-lucide="circle-check" class="h-4 w-4 text-emerald-500"></i></span><span class="mt-3 block text-2xl font-extrabold text-emerald-700" x-text="number(summary.successful)"></span><span class="mt-1 block truncate text-xs text-slate-500" x-text="currency(summary.volume) + ' successful volume'"></span>
                    </button>
                    <button @click="setStatus('pending')" class="rounded-2xl border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" :class="status === 'pending' ? 'border-amber-400 ring-2 ring-amber-100' : 'border-slate-200'">
                        <span class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-slate-400">Pending <i data-lucide="clock-3" class="h-4 w-4 text-amber-500"></i></span><span class="mt-3 block text-2xl font-extrabold text-amber-700" x-text="number(summary.pending)"></span><span class="mt-1 block text-xs text-slate-500">Awaiting completion</span>
                    </button>
                    <button @click="setStatus('failed')" class="rounded-2xl border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" :class="status === 'failed' ? 'border-rose-400 ring-2 ring-rose-100' : 'border-slate-200'">
                        <span class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-slate-400">Failed <i data-lucide="circle-x" class="h-4 w-4 text-rose-500"></i></span><span class="mt-3 block text-2xl font-extrabold text-rose-700" x-text="number(summary.failed)"></span><span class="mt-1 block text-xs text-slate-500">Unsuccessful payments</span>
                    </button>
                    <button @click="setStatus('refunded')" class="rounded-2xl border bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" :class="status === 'refunded' ? 'border-sky-400 ring-2 ring-sky-100' : 'border-slate-200'">
                        <span class="flex items-center justify-between text-xs font-bold uppercase tracking-wider text-slate-400">Refunded <i data-lucide="undo-2" class="h-4 w-4 text-sky-500"></i></span><span class="mt-3 block text-2xl font-extrabold text-sky-700" x-text="number(summary.refunded)"></span><span class="mt-1 block text-xs text-slate-500">Reversed transactions</span>
                    </button>
                </div>

                <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(220px,1fr)_180px_150px_150px_auto] lg:items-end">
                        <label class="block"><span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">Search all records</span><span class="relative block"><i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-slate-400"></i><input x-model="search" @input.debounce.350ms="resetAndFetch()" placeholder="Reference, customer, merchant..." class="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"></span></label>
                        <label class="block"><span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">Date range</span><select x-model="period" @change="applyPeriod()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="all">All time</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option><option value="month">This month</option><option value="lastmonth">Last month</option><option value="custom">Custom dates</option></select></label>
                        <label class="block"><span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">From</span><input type="date" x-model="from" @change="period='custom'; resetAndFetch()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label>
                        <label class="block"><span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-400">To</span><input type="date" x-model="to" @change="period='custom'; resetAndFetch()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label>
                        <button @click="clearFilters()" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50">Reset</button>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="font-bold text-slate-900">Transaction ledger</h2><p class="mt-1 text-xs text-slate-500">Click any row to inspect the transaction and its processing events.</p></div>
                        <span class="w-fit rounded-full px-3 py-1 text-[10px] font-extrabold uppercase tracking-widest" :class="mode === 'test' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'" x-text="mode + ' report'">Live report</span>
                    </div>
                    <div x-show="error" x-cloak class="m-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" x-text="error"></div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px] text-left">
                            <thead class="bg-slate-50 text-[10px] font-extrabold uppercase tracking-wider text-slate-400"><tr><th class="px-5 py-3">Date / Reference</th><th class="px-5 py-3">Merchant</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Amount</th><th class="px-5 py-3">Net settled</th><th class="px-5 py-3">Status</th><th class="px-5 py-3"><span class="sr-only">Open</span></th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="tx in transactions" :key="tx.id"><tr @click="openTransaction(tx)" @keydown.enter="openTransaction(tx)" tabindex="0" role="button" class="cursor-pointer transition hover:bg-indigo-50/40 focus:bg-indigo-50/50">
                                    <td class="px-5 py-4"><span class="block whitespace-nowrap text-sm font-semibold text-slate-800" x-text="date(tx.created_at)"></span><span class="mt-1 block font-mono text-[11px] text-slate-400" x-text="tx.reference"></span></td>
                                    <td class="px-5 py-4"><span class="block max-w-[190px] truncate text-sm font-semibold text-slate-800" x-text="tx.business_name"></span><span class="mt-1 block text-xs text-slate-400" x-text="tx.merchant_email || ('Merchant #' + tx.user_id)"></span></td>
                                    <td class="px-5 py-4"><span class="block max-w-[190px] truncate text-sm text-slate-700" x-text="tx.customer_name || 'Customer'"></span><span class="mt-1 block max-w-[190px] truncate text-xs text-slate-400" x-text="tx.customer_email || '—'"></span></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm font-bold text-slate-900" x-text="currency(tx.amount)"></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm font-semibold text-emerald-700" x-text="currency(tx.settled_amount)"></td>
                                    <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase" :class="statusClass(tx.status)" x-text="tx.status"></span></td>
                                    <td class="px-5 py-4 text-right"><i data-lucide="chevron-right" class="ml-auto h-4 w-4 text-slate-300"></i></td>
                                </tr></template>
                                <template x-if="!loading && transactions.length === 0"><tr><td colspan="7" class="px-5 py-16 text-center"><i data-lucide="search-x" class="mx-auto mb-3 h-8 w-8 text-slate-300"></i><p class="font-semibold text-slate-700">No transactions found</p><p class="mt-1 text-sm text-slate-400">Try a different date range or search term.</p></td></tr></template>
                                <template x-if="loading && transactions.length === 0"><tr><td colspan="7" class="px-5 py-14 text-center text-sm text-slate-400">Loading full transaction report…</td></tr></template>
                            </tbody>
                        </table>
                    </div>
                    <footer class="flex flex-col gap-3 border-t border-slate-100 bg-slate-50/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-500">Showing <b class="text-slate-800" x-text="pagination.from"></b>–<b class="text-slate-800" x-text="pagination.to"></b> of <b class="text-slate-800" x-text="number(pagination.total)"></b> matching transactions</p>
                        <div class="flex items-center gap-2"><span class="mr-2 text-xs text-slate-400" x-text="'Page ' + pagination.current_page + ' of ' + pagination.total_pages"></span><button @click="changePage(-1)" :disabled="page <= 1 || loading" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40">Previous</button><button @click="changePage(1)" :disabled="page >= pagination.total_pages || loading" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40">Next</button></div>
                    </footer>
                </section>
            </div>
        </div>
    </main>

    <div x-show="selectedTx" x-cloak @keydown.escape.window="selectedTx = null" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-3 backdrop-blur-sm sm:p-6" @click.self="selectedTx = null">
        <section class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Transaction details">
            <header class="flex items-start justify-between border-b border-slate-100 bg-slate-50 px-5 py-5 sm:px-8"><div><p class="text-[10px] font-extrabold uppercase tracking-[0.18em] text-indigo-600">Transaction detail</p><h2 class="mt-1 text-xl font-extrabold text-slate-950" x-text="detail?.transaction?.reference || selectedTx?.reference"></h2><p class="mt-1 text-xs text-slate-500" x-text="detail?.transaction ? date(detail.transaction.created_at) : ''"></p></div><button @click="selectedTx = null" class="rounded-xl p-2 text-slate-500 hover:bg-slate-200" aria-label="Close details"><i data-lucide="x" class="h-5 w-5"></i></button></header>
            <div class="flex-1 overflow-y-auto p-5 sm:p-8">
                <div x-show="detailLoading" class="py-16 text-center text-sm text-slate-400">Loading transaction process…</div>
                <div x-show="detailError" class="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" x-text="detailError"></div>
                <template x-if="detail?.transaction"><div>
                    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="rounded-2xl bg-slate-950 p-4 text-white"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Amount</p><p class="mt-2 text-xl font-extrabold" x-text="currency(detail.transaction.amount)"></p></div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Fee</p><p class="mt-2 text-lg font-extrabold text-rose-700" x-text="currency(detail.transaction.fee_amount)"></p></div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Net settled</p><p class="mt-2 text-lg font-extrabold text-emerald-700" x-text="currency(detail.transaction.settled_amount)"></p></div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Status</p><p class="mt-2"><span class="rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase" :class="statusClass(detail.transaction.status)" x-text="detail.transaction.status"></span></p></div>
                    </div>
                    <div class="mb-7 grid gap-5 md:grid-cols-2">
                        <article class="rounded-2xl border border-slate-200 p-5"><h3 class="mb-4 text-sm font-extrabold text-slate-900">Payment information</h3><dl class="space-y-3 text-sm"><div class="flex justify-between gap-4"><dt class="text-slate-400">Customer</dt><dd class="text-right font-semibold" x-text="detail.transaction.customer_name || '—'"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Email</dt><dd class="break-all text-right font-semibold" x-text="detail.transaction.customer_email || '—'"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Merchant</dt><dd class="text-right font-semibold" x-text="detail.transaction.business_name"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Method / currency</dt><dd class="text-right font-semibold" x-text="(detail.transaction.payment_method || '—') + ' · ' + (detail.transaction.currency || 'NGN')"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Gateway reference</dt><dd class="break-all text-right font-mono text-xs" x-text="detail.transaction.gateway_reference || '—'"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Failure reason</dt><dd class="text-right font-semibold text-rose-700" x-text="detail.transaction.failure_reason || '—'"></dd></div></dl></article>
                        <article class="rounded-2xl border border-slate-200 p-5"><h3 class="mb-4 text-sm font-extrabold text-slate-900">Processing details</h3><dl class="space-y-3 text-sm"><div class="flex justify-between gap-4"><dt class="text-slate-400">Transaction ID</dt><dd class="font-mono font-semibold" x-text="'#' + detail.transaction.id"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Platform reference</dt><dd class="break-all text-right font-mono text-xs" x-text="detail.transaction.reference"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Gateway amount</dt><dd class="font-semibold" x-text="detail.transaction.gateway_amount ? currency(detail.transaction.gateway_amount) : '—'"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Environment</dt><dd class="font-semibold uppercase" x-text="Number(detail.transaction.is_test) ? 'Test' : 'Live'"></dd></div><div class="flex justify-between gap-4"><dt class="text-slate-400">Card fingerprint</dt><dd class="text-right font-semibold" x-text="detail.transaction.card_last4 ? [detail.transaction.card_type, '•••• ' + detail.transaction.card_last4, detail.transaction.card_country].filter(Boolean).join(' · ') : 'Not available'"></dd></div></dl></article>
                    </div>
                    <section class="mb-7"><h3 class="mb-4 text-sm font-extrabold text-slate-900">Processing timeline</h3><div class="space-y-3"><template x-for="(event, index) in detail.timeline" :key="index"><div class="flex gap-3"><div class="mt-1 flex w-5 shrink-0 justify-center"><span class="h-2.5 w-2.5 rounded-full bg-indigo-500 ring-4 ring-indigo-50"></span></div><div class="min-w-0 flex-1 border-b border-slate-100 pb-3"><div class="flex flex-wrap justify-between gap-2"><b class="text-sm capitalize text-slate-800" x-text="(event.event_type || 'event').replaceAll('_', ' ')"></b><time class="text-[11px] text-slate-400" x-text="date(event.created_at)"></time></div><p class="mt-1 whitespace-pre-wrap text-sm text-slate-500" x-text="event.description || ''"></p></div></div></template><p x-show="!detail.timeline?.length" class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No timeline events were recorded for this transaction.</p></div></section>
                    <section class="mb-7"><h3 class="mb-3 text-sm font-extrabold text-slate-900">Refunds and reversals</h3><div class="overflow-x-auto rounded-xl border border-slate-200"><table class="w-full text-left text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-400"><tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Reference</th><th class="px-3 py-2">Amount</th><th class="px-3 py-2">Date</th></tr></thead><tbody><template x-for="(reversal, index) in detail.reversals" :key="index"><tr class="border-t border-slate-100"><td class="px-3 py-2 font-bold" x-text="reversal.kind"></td><td class="px-3 py-2 font-mono" x-text="reversal.reversal_reference"></td><td class="px-3 py-2" x-text="currency(reversal.amount)"></td><td class="px-3 py-2" x-text="date(reversal.created_at)"></td></tr></template><tr x-show="!detail.reversals?.length"><td colspan="4" class="px-3 py-3 text-slate-400">No reversals recorded.</td></tr></tbody></table></div></section>
                    <section><h3 class="mb-3 text-sm font-extrabold text-slate-900">Webhook delivery attempts</h3><div class="space-y-3"><template x-for="(hook, index) in detail.webhooks" :key="index"><details class="rounded-xl border border-slate-200"><summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm"><span class="font-bold" x-text="'Attempt ' + (hook.attempt_count || index + 1) + ' · ' + hook.status"></span><span class="text-xs text-slate-400" x-text="date(hook.last_attempt_at || hook.created_at)"></span></summary><div class="grid gap-3 border-t border-slate-100 p-4 md:grid-cols-2"><div><p class="mb-1 text-[10px] font-bold uppercase text-slate-400">Request payload</p><pre class="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-950 p-3 text-[10px] text-slate-100" x-text="hook.payload || '—'"></pre></div><div><p class="mb-1 text-[10px] font-bold uppercase text-slate-400">Gateway response</p><pre class="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-950 p-3 text-[10px] text-slate-100" x-text="hook.response || '—'"></pre></div></div></details></template><p x-show="!detail.webhooks?.length" class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No webhook attempts logged.</p></div></section>
                    <details x-show="detail.transaction.metadata" class="mt-7 rounded-xl border border-slate-200"><summary class="cursor-pointer px-4 py-3 text-sm font-bold">Transaction metadata</summary><pre class="max-h-64 overflow-auto whitespace-pre-wrap break-all border-t border-slate-100 bg-slate-50 p-4 text-xs" x-text="pretty(detail.transaction.metadata)"></pre></details>
                </div></template>
            </div>
            <footer class="flex justify-end border-t border-slate-100 bg-slate-50 px-5 py-4"><button @click="selectedTx = null" class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white">Close details</button></footer>
        </section>
    </div>

    <script>
    function transactionReport(kind) { return {
        kind, search: '', status: '', period: 'all', from: '', to: '', page: 1, perPage: 25,
        transactions: [], summary: {total:0, successful:0, pending:0, failed:0, refunded:0, volume:0},
        pagination: {total:0, current_page:1, total_pages:1, from:0, to:0}, loading:false, error:'', mode:'live',
        selectedTx:null, detail:null, detailLoading:false, detailError:'',
        init() { this.fetchTransactions(); },
        params() { const p = new URLSearchParams({search:this.search, status:this.status, from:this.from, to:this.to, limit:this.perPage, offset:(this.page-1)*this.perPage}); return p; },
        async fetchTransactions() {
            this.loading = true; this.error = '';
            try { const r = await fetch('./ajax-transactions.php?' + this.params().toString(), {headers:{'Accept':'application/json'}}); const data = await r.json(); if (!r.ok || !data.status) throw new Error(data.message || 'Unable to load report');
                this.transactions = data.data || []; this.summary = data.summary; this.pagination = data.pagination; this.mode = data.mode || 'live';
                this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
            } catch (e) { this.error = e.message; this.transactions = []; } finally { this.loading = false; }
        },
        resetAndFetch() { this.page = 1; this.fetchTransactions(); },
        setStatus(value) { this.status = value; this.resetAndFetch(); },
        changePage(delta) { const next = this.page + delta; if (next >= 1 && next <= this.pagination.total_pages) { this.page = next; this.fetchTransactions(); } },
        applyPeriod() { const now = new Date(); const d = value => [value.getFullYear(), String(value.getMonth()+1).padStart(2,'0'), String(value.getDate()).padStart(2,'0')].join('-'); this.from = ''; this.to = '';
            if (this.period === 'today') this.from = this.to = d(now);
            else if (['7','30','90'].includes(this.period)) { const start = new Date(now); start.setDate(start.getDate() - (Number(this.period)-1)); this.from = d(start); this.to = d(now); }
            else if (this.period === 'lastmonth') { this.from = d(new Date(now.getFullYear(), now.getMonth()-1, 1)); this.to = d(new Date(now.getFullYear(), now.getMonth(), 0)); }
            else if (this.period === 'month') { this.from = d(new Date(now.getFullYear(), now.getMonth(), 1)); this.to = d(now); }
            this.resetAndFetch();
        },
        clearFilters() { this.search = ''; this.status = ''; this.period = 'all'; this.from = ''; this.to = ''; this.resetAndFetch(); },
        exportUrl(format) { const p = new URLSearchParams({format, search:this.search, status:this.status, from:this.from, to:this.to}); return './export-transactions.php?' + p.toString(); },
        async openTransaction(tx) { this.selectedTx = tx; this.detail = null; this.detailError = ''; this.detailLoading = true;
            try { const r = await fetch('./transaction-details.php?id=' + encodeURIComponent(tx.id), {headers:{'Accept':'application/json'}}); const data = await r.json(); if (!r.ok) throw new Error(data.error || 'Unable to load transaction'); this.detail = data; }
            catch(e) { this.detailError = e.message; } finally { this.detailLoading = false; this.$nextTick(() => { if(window.lucide) lucide.createIcons(); }); }
        },
        date(value) { if (!value) return '—'; const date = new Date(value.replace(' ', 'T') + (value.endsWith('Z') ? '' : 'Z')); return isNaN(date) ? value : date.toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'UTC'}) + ' UTC'; },
        number(value) { return Number(value || 0).toLocaleString('en-GB'); },
        currency(value) { return 'NGN ' + Number(value || 0).toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2}); },
        pretty(value) { try { return JSON.stringify(typeof value === 'string' ? JSON.parse(value) : value, null, 2); } catch(e) { return value || ''; } },
        statusClass(value) { return ({success:'bg-emerald-100 text-emerald-800',pending:'bg-amber-100 text-amber-800',failed:'bg-rose-100 text-rose-800',refunded:'bg-sky-100 text-sky-800'})[value] || 'bg-slate-100 text-slate-700'; }
    }; }
    </script>
</body>
</html>
