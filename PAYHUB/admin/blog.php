<?php
// php-version/admin/blog.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Blog Manager - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'publish' || $_POST['action'] === 'edit') {
        $title = sanitize($_POST['title']);
        $slug = sanitize($_POST['slug']);
        $content = $_POST['content'];
        $meta_title = sanitize($_POST['meta_title']);
        $meta_desc = sanitize($_POST['meta_description']);
        $meta_keys = sanitize($_POST['meta_keywords']);
        $excerpt = substr(strip_tags($content), 0, 150) . '...';

        $featured_image = $_POST['current_image'] ?? '';
        if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION);
            $featured_image = 'blog_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['featured_image']['tmp_name'], '../uploads/' . $featured_image);
        }

        if ($_POST['action'] === 'publish') {
            $stmt = $db->prepare("INSERT INTO blog_posts (title, slug, content, excerpt, author_id, featured_image, meta_title, meta_description, meta_keywords) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $slug, $content, $excerpt, $user['id'], $featured_image, $meta_title, $meta_desc, $meta_keys]);
            $success_msg = "Blog post published successfully.";
        } else {
            $id = (int)$_POST['post_id'];
            $stmt = $db->prepare("UPDATE blog_posts SET title = ?, slug = ?, content = ?, excerpt = ?, featured_image = ?, meta_title = ?, meta_description = ?, meta_keywords = ? WHERE id = ?");
            $stmt->execute([$title, $slug, $content, $excerpt, $featured_image, $meta_title, $meta_desc, $meta_keys, $id]);
            $success_msg = "Blog post updated.";
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['post_id'];
        $stmt = $db->prepare("DELETE FROM blog_posts WHERE id = ?");
        $stmt->execute([$id]);
        $success_msg = "Blog post deleted.";
    }
}

$stmt = $db->query("SELECT b.*, u.full_name as author FROM blog_posts b LEFT JOIN users u ON b.author_id = u.id ORDER BY b.created_at DESC");
$posts = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{ showCreate: false, showEdit: false, editingPost: {} }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Blog Manager</h1>
                    <p class="text-slate-500">Publish updates, guides, and news directly to the platform's blog</p>
                </div>
                <button @click="showCreate = true" class="bg-indigo-600 text-white px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
                    <i data-lucide="plus" class="w-4 h-4"></i> Create Post
                </button>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold">Recent Posts</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Title</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Author</th>
                                <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Date</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($posts as $p): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="font-bold text-slate-900 truncate max-w-[150px] sm:max-w-none"><?php echo $p['title']; ?></div>
                                        <div class="hidden sm:block text-[10px] text-slate-400 font-mono"><?php echo $p['slug']; ?></div>
                                    </td>
                                    <td class="hidden md:table-cell px-6 py-4 text-sm text-slate-700"><?php echo $p['author']; ?></td>
                                    <td class="hidden sm:table-cell px-6 py-4 text-sm text-slate-500"><?php echo date('M d, Y', strtotime($p['created_at'])); ?></td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <button @click="editingPost = <?php echo htmlspecialchars(json_encode($p)); ?>; showEdit = true;" class="text-indigo-600 hover:text-indigo-800 transition-colors">
                                                <i data-lucide="edit" class="w-4 h-4"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Delete this post?');" class="inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="post_id" value="<?php echo $p['id']; ?>">
                                                <button type="submit" class="text-rose-500 hover:text-rose-700">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div x-show="showCreate" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 sticky top-0 z-10 backdrop-blur-md">
                    <h3 class="font-bold text-slate-900">Create New Blog Post</h3>
                    <button @click="showCreate = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" enctype="multipart/form-data" class="space-y-6" x-data="{ title: '', slug: '' }">
                        <input type="hidden" name="action" value="publish">
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Post Title</label>
                                <input type="text" name="title" x-model="title" @input="slug = title.toLowerCase().replace(/ /g, '-').replace(/[^\w-]+/g, '')" required placeholder="e.g. New Feature Release" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-medium">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">URL Slug</label>
                                <input type="text" name="slug" x-model="slug" required placeholder="new-feature-release" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-mono text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Featured Image</label>
                            <input type="file" name="featured_image" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Content (HTML allowed)</label>
                            <textarea name="content" required rows="8" placeholder="Write your post here..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none"></textarea>
                        </div>
                        <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-4">
                            <h4 class="text-sm font-bold text-slate-900">SEO Settings</h4>
                            <div class="grid md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Meta Title</label>
                                    <input type="text" name="meta_title" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Meta Keywords</label>
                                    <input type="text" name="meta_keywords" placeholder="keyword1, keyword2" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm outline-none">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Meta Description</label>
                                <textarea name="meta_description" rows="2" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm outline-none"></textarea>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Publish Post</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div x-show="showEdit" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 sticky top-0 z-10 backdrop-blur-md">
                    <h3 class="font-bold text-slate-900">Edit Blog Post</h3>
                    <button @click="showEdit = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="post_id" :value="editingPost.id">
                        <input type="hidden" name="current_image" :value="editingPost.featured_image">
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Post Title</label>
                                <input type="text" name="title" x-model="editingPost.title" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none font-medium">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">URL Slug</label>
                                <input type="text" name="slug" x-model="editingPost.slug" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none font-mono text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Featured Image (leave blank to keep current)</label>
                            <input type="file" name="featured_image" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                            <template x-if="editingPost.featured_image">
                                <p class="text-[10px] text-slate-400 mt-2">Current: <span x-text="editingPost.featured_image"></span></p>
                            </template>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Content (HTML allowed)</label>
                            <textarea name="content" x-model="editingPost.content" required rows="8" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none"></textarea>
                        </div>
                        <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-4">
                            <h4 class="text-sm font-bold text-slate-900">SEO Settings</h4>
                            <div class="grid md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Meta Title</label>
                                    <input type="text" name="meta_title" x-model="editingPost.meta_title" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Meta Keywords</label>
                                    <input type="text" name="meta_keywords" x-model="editingPost.meta_keywords" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm outline-none">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Meta Description</label>
                                <textarea name="meta_description" x-model="editingPost.meta_description" rows="2" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm outline-none"></textarea>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Update Post</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
<script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>