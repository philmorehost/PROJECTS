<?php
// php-version/footer.php
?>
    <footer class="bg-slate-900 text-white py-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-4 gap-12 mb-16">
                <div class="col-span-2">
                    <div class="flex items-center gap-2 mb-8">
                        <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center">
                            <i data-lucide="credit-card" class="text-white w-6 h-6"></i>
                        </div>
                        <span class="text-2xl font-bold tracking-tight">Payhub</span>
                    </div>
                    <p class="text-slate-400 max-w-md mb-8">
                        The most reliable payment gateway for African businesses. 
                        Built for speed, security, and scalability.
                    </p>
                </div>
                <div>
                    <h4 class="font-bold mb-6 text-white">Product</h4>
                    <ul className="space-y-4 text-slate-400">
                        <li><a href="pricing.php" class="hover:text-white transition-colors">Pricing</a></li>
                        <li><a href="#" class="hover:text-white transition-colors">Payment Pages</a></li>
                        <li><a href="#" class="hover:text-white transition-colors">Invoices</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-6 text-white">Resources</h4>
                    <ul class="space-y-4 text-slate-400">
                        <li><a href="docs.php" class="hover:text-white transition-colors">Documentation</a></li>
                        <li><a href="api-reference.php" class="hover:text-white transition-colors">API Reference</a></li>
                        <li><a href="support.php" class="hover:text-white transition-colors">Support Center</a></li>
                        <li><a href="blog.php" class="hover:text-white transition-colors">Blog</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-6 text-white">Legal</h4>
                    <ul class="space-y-4 text-slate-400">
                        <li><a href="privacy.php" class="hover:text-white transition-colors">Privacy Policy</a></li>
                        <li><a href="terms.php" class="hover:text-white transition-colors">Terms of Service</a></li>
                    </ul>
                </div>
            </div>
            <div class="pt-8 border-t border-slate-800 flex flex-col md:flex-row justify-between items-center gap-4 text-slate-500 text-sm">
                <p>© <?php echo date('Y'); ?> Payhub. All rights reserved.</p>
                <div class="flex gap-8">
                    <a href="privacy.php" class="hover:text-white">Privacy Policy</a>
                    <a href="terms.php" class="hover:text-white">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
