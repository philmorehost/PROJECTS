<?php
// php-version/support.php
require_once 'includes/functions.php';

$success_msg = '';
$error_msg = '';

// Simple Math Captcha
if (!isset($_SESSION['captcha_a'])) {
    $_SESSION['captcha_a'] = rand(1, 10);
    $_SESSION['captcha_b'] = rand(1, 10);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'contact') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid security token.";
    } else {
        $email = sanitize($_POST['email']);
        $subject = sanitize($_POST['subject']);
        $message = sanitize($_POST['message']);
        $captcha = (int)$_POST['captcha'];

        if ($captcha !== ($_SESSION['captcha_a'] + $_SESSION['captcha_b'])) {
            $error_msg = "Incorrect captcha answer.";
        } else {
            $db = Database::connect();

            // Check if merchant exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            $is_registered = $user ? 1 : 0;
            $userId = $user ? $user['id'] : null;

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO tickets (user_id, guest_email, subject, is_registered, status) VALUES (?, ?, ?, ?, 'open')");
                $stmt->execute([$userId, $is_registered ? null : $email, $subject, $is_registered]);
                $ticketId = $db->lastInsertId();

                // If guest, $userId is null. ticket_messages.user_id is now NULLable.
                $stmt = $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
                $stmt->execute([$ticketId, $userId, $message]);

                $db->commit();

                // Notify admins about the new support request
                notifyAdmins(
                    "New Support Request: " . $subject,
                    "<p>A new support request was submitted through the support form.</p>
                     <p><strong>Email:</strong> " . htmlspecialchars($email) . "<br>
                     <strong>Subject:</strong> " . htmlspecialchars($subject) . "<br>
                     <strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>"
                );

                $success_msg = "Message sent! Our team will get back to you shortly.";
                // Reset captcha
                $_SESSION['captcha_a'] = rand(1, 10);
                $_SESSION['captcha_b'] = rand(1, 10);
            } catch (Exception $e) {
                $db->rollBack();
                $error_msg = "Failed to send message: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Support Center - Payhub';
include 'includes/header.php';
?>
<div class="pt-20">
    <div class="bg-indigo-600 py-24 px-4 text-center">
        <h1 class="text-4xl font-bold text-white mb-8 tracking-tight">How can we help you?</h1>
        <div class="max-w-2xl mx-auto relative">
            <i class="lucide-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-5 h-5"></i>
            <input type="text" placeholder="Search for articles, guides..." class="w-full pl-12 pr-4 py-4 rounded-2xl border-none focus:ring-4 focus:ring-indigo-300 shadow-2xl">
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 -mt-12 mb-24">
        <?php if ($success_msg): ?>
            <div class="mb-12 p-6 bg-emerald-50 text-emerald-700 rounded-3xl border border-emerald-100 font-bold text-center"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="md:col-span-2">
                <h2 class="text-2xl font-bold text-slate-900 mb-8">Popular Articles</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <?php
                    $db = Database::connect();
                    $articles = $db->query("SELECT * FROM blog_posts ORDER BY created_at DESC LIMIT 4")->fetchAll();
                    foreach($articles as $art):
                    ?>
                        <a href="blog.php?slug=<?php echo $art['slug']; ?>" class="p-6 bg-white rounded-2xl border border-slate-200 hover:border-indigo-300 hover:text-indigo-600 transition-all font-bold text-slate-700 flex items-center justify-between group">
                            <?php echo $art['title']; ?>
                            <i class="lucide-arrow-right w-4 h-4 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="mt-16 bg-white p-10 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <h3 class="text-2xl font-bold text-slate-900 mb-8">Direct Message</h3>
                    <?php if ($error_msg): ?>
                        <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium"><?php echo $error_msg; ?></div>
                    <?php endif; ?>
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="contact">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Email Address</label>
                                <input type="email" name="email" required placeholder="your@email.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Subject</label>
                                <input type="text" name="subject" required placeholder="How can we help?" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Message</label>
                            <textarea name="message" required rows="5" placeholder="Detailed description..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20"></textarea>
                        </div>
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                            <div class="flex items-center gap-4">
                                <div class="bg-slate-100 px-4 py-3 rounded-xl font-bold text-slate-600 whitespace-nowrap">
                                    <?php echo $_SESSION['captcha_a']; ?> + <?php echo $_SESSION['captcha_b']; ?> = ?
                                </div>
                                <input type="number" name="captcha" required placeholder="Answer" class="w-24 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                            </div>
                            <button type="submit" class="flex-1 bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Send Message</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="md:col-span-1 space-y-8">
                <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                    <div class="w-12 h-12 bg-indigo-50 rounded-2xl flex items-center justify-center mb-6">
                        <i class="lucide-book-open text-indigo-600 w-6 h-6"></i>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Documentation</h3>
                    <p class="text-slate-600 text-sm leading-relaxed mb-6">Explore our technical documentation for developers and merchants.</p>
                    <a href="docs.php" class="text-indigo-600 font-bold hover:underline">Read Docs &rarr;</a>
                </div>
                <div class="bg-slate-900 p-8 rounded-[2rem] text-white shadow-xl">
                    <h3 class="text-xl font-bold mb-4">Urgent Issue?</h3>
                    <p class="text-slate-400 text-sm mb-8 leading-relaxed">Merchants with 'Special' accounts have access to 24/7 priority phone support.</p>
                    <button class="w-full bg-white text-slate-900 py-3 rounded-xl font-bold text-sm">Call Support</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
<?php include 'includes/footer.php'; ?>
