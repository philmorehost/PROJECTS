<?php
// php-version/blog-post.php
require_once 'includes/functions.php';

$db = Database::connect();
$slug = $_GET['slug'] ?? '';

if (!$slug) redirect('blog.php');

$stmt = $db->prepare("SELECT b.*, u.full_name as author FROM blog_posts b LEFT JOIN users u ON b.author_id = u.id WHERE b.slug = ?");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) redirect('blog.php');

// Fetch similar posts (excluding current)
$stmt = $db->prepare("SELECT * FROM blog_posts WHERE slug != ? ORDER BY created_at DESC LIMIT 3");
$stmt->execute([$slug]);
$similar = $stmt->fetchAll();

// Recent posts for widget
$stmt = $db->query("SELECT * FROM blog_posts ORDER BY created_at DESC LIMIT 5");
$recent = $stmt->fetchAll();

$pageTitle = $post['meta_title'] ?: $post['title'] . ' - Payhub Blog';
include 'includes/header.php';
?>
<div class="pt-32 pb-24 bg-white min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-12 gap-16">
            <!-- Main Content -->
            <main class="lg:col-span-8">
                <article>
                    <header class="mb-10">
                        <div class="flex items-center gap-4 mb-6">
                            <a href="blog.php" class="text-xs font-bold text-indigo-600 uppercase tracking-widest bg-indigo-50 px-3 py-1 rounded-full">Blog</a>
                            <span class="text-slate-400 text-sm"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                        </div>
                        <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 mb-6 leading-tight"><?php echo $post['title']; ?></h1>
                        <div class="flex items-center gap-3 text-slate-600">
                            <div class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center font-bold text-slate-400">
                                <?php echo substr($post['author'], 0, 1); ?>
                            </div>
                            <span class="font-bold text-sm">By <?php echo $post['author'] ?: 'Admin'; ?></span>
                        </div>
                    </header>

                    <?php if (!empty($post['featured_image'])): ?>
                        <div class="mb-12 rounded-[2.5rem] overflow-hidden shadow-2xl">
                            <img src="uploads/<?php echo $post['featured_image']; ?>" alt="<?php echo $post['title']; ?>" class="w-full h-auto">
                        </div>
                    <?php endif; ?>

                    <div class="prose prose-lg max-w-none text-slate-600 leading-relaxed space-y-6">
                        <?php echo nl2br($post['content']); ?>
                    </div>

                    <div class="mt-20 pt-12 border-t border-slate-100">
                        <h3 class="text-2xl font-bold text-slate-900 mb-8">Similar Posts</h3>
                        <div class="grid md:grid-cols-3 gap-8">
                            <?php foreach($similar as $s): ?>
                                <a href="blog-post.php?slug=<?php echo $s['slug']; ?>" class="group">
                                    <div class="aspect-video rounded-2xl bg-slate-100 mb-4 overflow-hidden">
                                        <img src="<?php echo !empty($s['featured_image']) ? 'uploads/'.$s['featured_image'] : 'https://picsum.photos/seed/'.$s['id'].'/400/200'; ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                                    </div>
                                    <h4 class="font-bold text-slate-900 group-hover:text-indigo-600 transition-colors line-clamp-2"><?php echo $s['title']; ?></h4>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </article>
            </main>

            <!-- Sidebar -->
            <aside class="lg:col-span-4 space-y-12">
                <div class="bg-slate-50 p-8 rounded-[2rem] border border-slate-100">
                    <h4 class="font-bold text-slate-900 mb-6 flex items-center gap-2">
                        <i data-lucide="zap" class="text-amber-500 w-5 h-5"></i>
                        Recent Posts
                    </h4>
                    <div class="space-y-6">
                        <?php foreach($recent as $r): ?>
                            <a href="blog-post.php?slug=<?php echo $r['slug']; ?>" class="flex items-start gap-4 group">
                                <div class="w-16 h-16 shrink-0 rounded-xl bg-white border border-slate-200 overflow-hidden">
                                    <img src="<?php echo !empty($r['featured_image']) ? 'uploads/'.$r['featured_image'] : 'https://picsum.photos/seed/'.$r['id'].'/100/100'; ?>" class="w-full h-full object-cover group-hover:scale-110 transition-all">
                                </div>
                                <div class="min-w-0">
                                    <h5 class="text-sm font-bold text-slate-900 line-clamp-2 group-hover:text-indigo-600 transition-colors"><?php echo $r['title']; ?></h5>
                                    <p class="text-[10px] text-slate-400 mt-1 uppercase font-bold"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bg-indigo-600 p-8 rounded-[2rem] text-white shadow-xl shadow-indigo-100">
                    <h4 class="text-xl font-bold mb-4">Start processing payments today</h4>
                    <p class="text-indigo-100 mb-8 leading-relaxed">Join thousands of businesses across Africa growing with Payhub.</p>
                    <a href="register.php" class="block w-full text-center py-4 bg-white text-indigo-600 font-bold rounded-xl hover:bg-indigo-50 transition-all">Create free account</a>
                </div>
            </aside>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
