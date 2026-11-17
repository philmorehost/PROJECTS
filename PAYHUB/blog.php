<?php
// php-version/blog.php
require_once 'includes/functions.php';

$db = Database::connect();
$search = $_GET['q'] ?? '';

$query = "SELECT * FROM blog_posts";
$params = [];
if ($search) {
    $query .= " WHERE title LIKE ? OR content LIKE ?";
    $params = ["%$search%", "%$search%"];
}
$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Mock posts if table is empty
if (empty($posts)) {
    $posts = [
        [
            'title' => 'The Future of Payments in Africa',
            'slug' => 'future-of-payments',
            'excerpt' => 'Exploring how digital wallets and mobile money are revolutionizing commerce across the continent.',
            'created_at' => date('Y-m-d H:i:s'),
            'meta_title' => 'Future of Payments - Payhub Blog',
            'meta_description' => 'Latest trends in African fintech.'
        ],
        [
            'title' => 'How to Secure Your Online Store',
            'slug' => 'secure-online-store',
            'excerpt' => 'Best practices for protecting your customers data and preventing fraudulent transactions.',
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'meta_title' => 'Security Best Practices - Payhub Blog',
            'meta_description' => 'Learn how to secure your e-commerce business.'
        ]
    ];
}

$pageTitle = 'Payhub Blog - Latest Insights in Fintech';
include 'includes/header.php';
?>
<div class="pt-32 pb-24 px-4 bg-slate-50 min-h-screen">
    <div class="max-w-5xl mx-auto">
        <div class="text-center mb-16">
            <h1 class="text-5xl font-bold text-slate-900 mb-6 tracking-tight">Payhub Blog</h1>
            <p class="text-slate-500 text-lg max-w-2xl mx-auto mb-10 leading-relaxed">Insights, updates, and stories from the team building the future of global payments.</p>
            
            <form action="blog.php" method="GET" class="relative max-w-xl mx-auto">
                <i data-lucide="search" class=" absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-5 h-5"></i>
                <input 
                    type="text"
                    name="q"
                    placeholder="Search articles..."
                    value="<?php echo htmlspecialchars($search); ?>"
                    class="w-full pl-12 pr-4 py-4 bg-white border border-slate-200 rounded-2xl shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                >
            </form>
        </div>
        
        <div class="grid md:grid-cols-2 gap-12">
            <?php foreach ($posts as $post): ?>
                <article class="bg-white rounded-[2rem] overflow-hidden border border-slate-200 shadow-sm hover:shadow-xl transition-all group">
                    <a href="blog-post.php?slug=<?php echo $post['slug']; ?>">
                        <img
                            src="<?php echo ($post['featured_image'] ?? null) ? 'uploads/'.$post['featured_image'] : 'https://picsum.photos/seed/'.$post['slug'].'/800/400'; ?>"
                            alt="<?php echo $post['title']; ?>"
                            class="w-full h-56 object-cover group-hover:scale-105 transition-transform duration-500"
                        >
                    </a>
                    <div class="p-8">
                        <a href="blog-post.php?slug=<?php echo $post['slug']; ?>">
                            <h2 class="text-2xl font-bold text-slate-900 mb-4 hover:text-indigo-600 transition-colors">
                                <?php echo $post['title']; ?>
                            </h2>
                        </a>
                        <p class="text-slate-600 mb-6 line-clamp-3 leading-relaxed"><?php echo $post['excerpt']; ?></p>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-400 font-medium"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                            <a href="blog-post.php?slug=<?php echo $post['slug']; ?>" class="text-indigo-600 font-bold text-sm hover:translate-x-1 transition-transform">Read more →</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            
            <?php if (empty($posts)): ?>
                <div class="col-span-2 text-center py-20 bg-white rounded-3xl border border-dashed border-slate-300">
                    <p class="text-slate-500">No blog posts found matching your search.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include 'includes/footer.php'; ?>
