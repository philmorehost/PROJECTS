<?php
// php-version/admin/transactions.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'All Transactions - Admin Hub';

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="adminTransactionsView">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Transaction History</h1>
                    <p class="text-sm text-slate-500">View and manage all platform transactions</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-6 flex flex-col md:flex-row gap-4">
                <div class="flex-1">
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Search</label>
                    <input type="text" 
                           x-model="search" 
                           @input.debounce.300ms="resetPageAndFetch()" 
                           placeholder="Email, Reference, or Business Name..." 
                           class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                </div>
                <div class="w-full md:w-48">
                    <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Status</label>
                    <select x-model="status" @change="resetPageAndFetch()" 
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                        <option value="">All Statuses</option>
                        <option value="success">Success</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-400 uppercase">
                            <tr>
                                <th class="px-6 py-4">Date</th>
                                <th class="px-6 py-4">Merchant</th>
                                <th class="px-6 py-4">Reference</th>
                                <th class="px-6 py-4">Customer</th>
                                <th class="px-6 py-4">Amount</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="tx in transactions" :key="tx.id">
                                <tr class="hover:bg-slate-50 transition-colors text-sm">
                                    <td class="px-6 py-4 text-slate-500 whitespace-nowrap" x-text="formatDate(tx.created_at)"></td>
                                    <td class="px-6 py-4 font-medium text-slate-900" x-text="tx.business_name"></td>
                                    <td class="px-6 py-4 text-slate-500 font-mono text-xs" x-text="tx.reference"></td>
                                    <td class="px-6 py-4 text-slate-500" x-text="tx.customer_email"></td>
                                    <td class="px-6 py-4 font-bold text-slate-900 whitespace-nowrap" x-text="formatCurrency(tx.amount)"></td>
                                    <td class="px-6 py-4">
                                        <span :class="{
                                            'bg-emerald-100 text-emerald-600': tx.status === 'success',
                                            'bg-amber-100 text-amber-600': tx.status === 'pending',
                                            'bg-rose-100 text-rose-600': tx.status === 'failed'
                                        }" class="px-2 py-1 rounded-full text-[10px] font-bold uppercase">
                                            <span x-text="tx.status"></span>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <a :href="'../merchant/transactions.php?id=' + tx.id" class="text-indigo-600 hover:text-indigo-800 font-bold text-xs">Details</a>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="transactions.length === 0">
                                <tr>
                                    <td colspan="7" class="px-6 py-10 text-center text-slate-400 italic">
                                        <span x-show="!loading">No transactions found matching your criteria.</span>
                                        <span x-show="loading">Loading...</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
                    <p class="text-xs text-slate-500 font-medium">
                        Showing <span class="font-bold text-slate-900" x-text="pagination.from"></span> to 
                        <span class="font-bold text-slate-900" x-text="pagination.to"></span> of 
                        <span class="font-bold text-slate-900" x-text="pagination.total"></span> transactions
                    </p>
                    <div class="flex gap-2">
                        <button @click="prevPage()" :disabled="pagination.current_page === 1" 
                                class="px-3 py-1 rounded-lg border border-slate-200 bg-white text-xs font-bold disabled:opacity-50 hover:bg-slate-50 transition-all">
                            Previous
                        </button>
                        <button @click="nextPage()" :disabled="pagination.current_page === pagination.total_pages" 
                                class="px-3 py-1 rounded-lg border border-slate-200 bg-white text-xs font-bold disabled:opacity-50 hover:bg-slate-50 transition-all">
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('alpinejs.start', () => {
        Alpine.data('adminTransactionsView', () => ({
            search: '',
            status: '',
            page: 1,
            perPage: 50,
            transactions: [],
            pagination: {
                total: 0,
                current_page: 1,
                total_pages: 1,
                from: 0,
                to: 0
            },
            loading: false,
            init() {
                console.log('Initializing Transaction View...');
                this.fetchTransactions();
            },
            async fetchTransactions() {
                this.loading = true;
                const offset = (this.page - 1) * this.perPage;
                const url = `./ajax-transactions.php?search=${encodeURIComponent(this.search)}&status=${encodeURIComponent(this.status)}&limit=${this.perPage}&offset=${offset}`;
                console.log('Fetching from:', url);
                try {
                    const response = await fetch(url);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const result = await response.json();
                    console.log('API Result:', result);
                    if (result.status) {
                        this.transactions = result.data;
                        this.pagination = {
                            ...result.pagination,
                            from: result.pagination.total === 0 ? 0 : (result.pagination.current_page - 1) * result.pagination.per_page + 1,
                            to: Math.min(result.pagination.current_page * result.pagination.per_page, result.pagination.total)
                        };
                    } else {
                        this.transactions = [];
                        console.error('API Error:', result.message);
                        alert('API Error: ' + result.message);
                    }
                } catch (e) {
                    console.error('Fetch error:', e);
                    this.transactions = [];
                    alert('Connection Error: ' + e.message);
                } finally {
                    this.loading = false;
                }
            },
            
            resetPageAndFetch() {
                this.page = 1;
                this.fetchTransactions();
            },

            nextPage() {
                if (this.page < this.pagination.total_pages) {
                    this.page++;
                    this.fetchTransactions();
                }
            },

            prevPage() {
                if (this.page > 1) {
                    this.page--;
                    this.fetchTransactions();
                }
            },
            
            formatDate(dateStr) {
                return new Date(dateStr).toLocaleDateString('en-GB', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: '2-digit',
                    min: '2-digit'
                });
            },
            
            formatCurrency(amount) {
                return '₦' + parseFloat(amount).toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
        }));
    });
    </script>
</body>
</html>
