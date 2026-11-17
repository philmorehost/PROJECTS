<?php
require_once 'includes/functions.php';

$pageTitle = 'Support Center - Payhub';
$success_msg = '';
$error_msg = '';
$form = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
$currentUser = isLoggedIn() ? getAuthUser() : null;
$isMerchant = $currentUser && ($currentUser['role'] ?? '') === 'merchant';

if (!isset($_SESSION['support_captcha_a'], $_SESSION['support_captcha_b'])) {
    $_SESSION['support_captcha_a'] = random_int(1, 9);
    $_SESSION['support_captcha_b'] = random_int(1, 9);
}
if (!isset($_SESSION['support_form_started'])) {
    $_SESSION['support_form_started'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'public_whatsapp_contact') {
    foreach ($form as $field => $_) {
        $form[$field] = trim((string)($_POST[$field] ?? ''));
    }
    $captchaAnswer = filter_var($_POST['captcha'] ?? null, FILTER_VALIDATE_INT);
    $honeypot = trim((string)($_POST['website'] ?? ''));
    $elapsed = time() - (int)($_SESSION['support_form_started'] ?? time());

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Your security check expired. Refresh the page and try again.';
    } elseif ($honeypot !== '' || $elapsed < 3) {
        // Silently reject automated submissions; never store public messages as support tickets.
        $error_msg = 'Please review the form and try again.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Enter a valid email address.';
    } elseif ($form['name'] === '' || strlen($form['name']) > 100) {
        $error_msg = 'Enter your name (up to 100 characters).';
    } elseif ($form['subject'] === '' || strlen($form['subject']) > 120) {
        $error_msg = 'Enter a subject (up to 120 characters).';
    } elseif (strlen($form['message']) < 15 || strlen($form['message']) > 3000) {
        $error_msg = 'Please provide a message between 15 and 3,000 characters.';
    } elseif ($captchaAnswer === false || $captchaAnswer !== (int)$_SESSION['support_captcha_a'] + (int)$_SESSION['support_captcha_b']) {
        $error_msg = 'The answer to the quick security question is incorrect.';
    } else {
        $whatsappNumber = preg_replace('/\D+/', '', (string)getConfig('support_whatsapp_number', ''));
        if (strlen($whatsappNumber) < 7 || strlen($whatsappNumber) > 15) {
            $error_msg = 'WhatsApp contact is not configured yet. Please try again later.';
        } else {
            $message = "Public support enquiry\n\nName: {$form['name']}\nEmail: {$form['email']}\nSubject: {$form['subject']}\n\nMessage:\n{$form['message']}";
            $_SESSION['support_captcha_a'] = random_int(1, 9);
            $_SESSION['support_captcha_b'] = random_int(1, 9);
            $_SESSION['support_form_started'] = time();
            header('Location: https://wa.me/' . $whatsappNumber . '?text=' . rawurlencode($message), true, 303);
            exit;
        }
    }
    // Give the visitor a fresh challenge after any failed attempt.
    $_SESSION['support_captcha_a'] = random_int(1, 9);
    $_SESSION['support_captcha_b'] = random_int(1, 9);
    $_SESSION['support_form_started'] = time();
}

$articles = [];
try {
    $db = Database::connect();
    $articles = $db->query("SELECT title, slug FROM blog_posts ORDER BY created_at DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[PayHub support] Could not load help articles: ' . $e->getMessage());
}

include 'includes/header.php';
?>
<main class="pt-20">
    <section class="relative overflow-hidden bg-slate-950 px-4 py-20 text-center sm:py-24">
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-950 via-slate-950 to-slate-900"></div>
        <div class="absolute -left-20 top-10 h-72 w-72 rounded-full bg-indigo-600/20 blur-3xl"></div>
        <div class="absolute -right-16 bottom-0 h-64 w-64 rounded-full bg-cyan-500/10 blur-3xl"></div>
        <div class="relative mx-auto max-w-3xl">
            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-bold uppercase tracking-widest text-indigo-200"><i data-lucide="life-buoy" class="h-4 w-4"></i> PayHub Help Center</span>
            <h1 class="mt-6 text-4xl font-extrabold tracking-tight text-white sm:text-6xl">How can we help?</h1>
            <p class="mx-auto mt-5 max-w-xl text-base leading-relaxed text-slate-300 sm:text-lg">Find an answer in our guides or get in touch with the right support channel.</p>
            <div class="mx-auto mt-8 flex max-w-lg flex-col justify-center gap-3 sm:flex-row">
                <?php if ($isMerchant): ?>
                    <a href="merchant/tickets.php" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-500 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-950/30 transition hover:bg-indigo-400"><i data-lucide="messages-square" class="h-4 w-4"></i>Open merchant support</a>
                <?php elseif (!$currentUser): ?>
                    <a href="login.php" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-500 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-950/30 transition hover:bg-indigo-400"><i data-lucide="log-in" class="h-4 w-4"></i>Merchant login</a>
                    <a href="register.php" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/5 px-6 py-3.5 text-sm font-bold text-white transition hover:bg-white/10"><i data-lucide="user-plus" class="h-4 w-4"></i>Create an account</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
        <?php if ($success_msg !== ''): ?><div role="status" class="mb-8 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-semibold text-emerald-800"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <div class="grid gap-10 lg:grid-cols-12">
            <section class="lg:col-span-7">
                <div class="mb-6 flex items-end justify-between gap-4">
                    <div><p class="text-xs font-extrabold uppercase tracking-[0.18em] text-indigo-600">Learn & solve</p><h2 class="mt-2 text-2xl font-extrabold text-slate-950">Popular help articles</h2></div>
                    <a href="blog.php" class="hidden items-center gap-1 text-sm font-bold text-indigo-600 hover:text-indigo-800 sm:flex">All articles <i data-lucide="arrow-up-right" class="h-4 w-4"></i></a>
                </div>
                <?php if ($articles): ?>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <?php foreach ($articles as $article): ?>
                            <a href="blog.php?slug=<?php echo rawurlencode((string)$article['slug']); ?>" class="group flex min-h-24 items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md">
                                <span class="font-bold leading-snug text-slate-800 group-hover:text-indigo-700"><?php echo htmlspecialchars((string)$article['title'], ENT_QUOTES, 'UTF-8'); ?></span><i data-lucide="arrow-up-right" class="h-4 w-4 shrink-0 text-slate-300 group-hover:text-indigo-600"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Help articles will appear here soon.</div>
                <?php endif; ?>
                <div class="mt-8 grid gap-4 sm:grid-cols-2">
                    <a href="docs.php" class="rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-indigo-200"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"><i data-lucide="book-open-check" class="h-5 w-5"></i></span><h3 class="mt-4 font-extrabold text-slate-900">Documentation</h3><p class="mt-1 text-sm leading-relaxed text-slate-500">Guides for payments, integrations, and your merchant dashboard.</p><span class="mt-4 inline-flex items-center gap-1 text-sm font-bold text-indigo-600">Read the docs <i data-lucide="arrow-right" class="h-4 w-4"></i></span></a>
                    <div class="rounded-2xl border border-slate-200 bg-white p-6"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><i data-lucide="shield-check" class="h-5 w-5"></i></span><h3 class="mt-4 font-extrabold text-slate-900">Secure support</h3><p class="mt-1 text-sm leading-relaxed text-slate-500">Merchant ticket conversations are tied to your account and visible only after login.</p></div>
                </div>
            </section>

            <aside class="lg:col-span-5">
                <?php if ($isMerchant): ?>
                    <div class="sticky top-28 rounded-3xl bg-indigo-950 p-7 text-white shadow-xl sm:p-8">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 text-indigo-200"><i data-lucide="messages-square" class="h-6 w-6"></i></span>
                        <p class="mt-6 text-xs font-extrabold uppercase tracking-[0.18em] text-indigo-300">For registered merchants</p>
                        <h2 class="mt-2 text-2xl font-extrabold">Open a support ticket</h2>
                        <p class="mt-3 text-sm leading-relaxed text-indigo-100/75">Sign in to create a private request, share details securely, and follow replies from our team.</p>
                        <a href="merchant/tickets.php" class="mt-7 flex w-full items-center justify-center gap-2 rounded-xl bg-white px-5 py-3.5 text-sm font-extrabold text-indigo-950 transition hover:bg-indigo-50"><i data-lucide="arrow-right" class="h-4 w-4"></i>Go to merchant support</a>
                    </div>
                <?php else: ?>
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex items-start gap-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><i data-lucide="message-circle" class="h-6 w-6"></i></span>
                            <div><p class="text-xs font-extrabold uppercase tracking-[0.18em] text-emerald-700">Not registered yet?</p><h2 class="mt-1 text-xl font-extrabold text-slate-950">Contact us on WhatsApp</h2><p class="mt-2 text-sm leading-relaxed text-slate-500">Public enquiries are sent to our WhatsApp contact. They do not create support tickets. You can create a private ticket after registering and signing in.</p></div>
                        </div>
                        <?php if ($error_msg !== ''): ?><div role="alert" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                        <form method="POST" class="mt-6 space-y-4" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="public_whatsapp_contact">
                            <div class="absolute -left-[10000px] top-auto h-px w-px overflow-hidden" aria-hidden="true"><label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                            <div><label for="contact-name" class="mb-1.5 block text-xs font-bold text-slate-600">Your name</label><input id="contact-name" type="text" name="name" required maxlength="100" autocomplete="name" value="<?php echo htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></div>
                            <div><label for="contact-email" class="mb-1.5 block text-xs font-bold text-slate-600">Email address</label><input id="contact-email" type="email" name="email" required maxlength="254" autocomplete="email" value="<?php echo htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></div>
                            <div><label for="contact-subject" class="mb-1.5 block text-xs font-bold text-slate-600">Subject</label><input id="contact-subject" type="text" name="subject" required maxlength="120" value="<?php echo htmlspecialchars($form['subject'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="What do you need help with?" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></div>
                            <div><label for="contact-message" class="mb-1.5 block text-xs font-bold text-slate-600">Message</label><textarea id="contact-message" name="message" required minlength="15" maxlength="3000" rows="4" placeholder="Please include enough detail for us to help…" class="w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"><?php echo htmlspecialchars($form['message'], ENT_QUOTES, 'UTF-8'); ?></textarea><p class="mt-1 text-right text-[11px] text-slate-400">15–3,000 characters</p></div>
                            <div class="flex items-center gap-3 rounded-xl bg-slate-50 p-3"><span class="text-sm font-bold text-slate-700">Security check: <?php echo (int)$_SESSION['support_captcha_a']; ?> + <?php echo (int)$_SESSION['support_captcha_b']; ?> =</span><input aria-label="Answer to security check" type="number" name="captcha" required inputmode="numeric" class="w-20 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-400"><span class="text-xs text-slate-400">Please solve to continue</span></div>
                            <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 py-3.5 text-sm font-extrabold text-white shadow-lg shadow-emerald-100 transition hover:bg-emerald-700"><i data-lucide="send" class="h-4 w-4"></i>Continue to WhatsApp</button>
                            <p class="text-center text-[11px] leading-relaxed text-slate-400">WhatsApp opens with your message prepared. Review it and tap <strong>Send</strong> in WhatsApp to contact us.</p>
                        </form>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</main>
<script src="https://unpkg.com/lucide@latest"></script>
<script>if (typeof lucide !== 'undefined') lucide.createIcons();</script>
<?php include 'includes/footer.php'; ?>
