<?php session_start();
    include("../func/bc-admin-config.php");

$hub_slug = isset($_GET['g']) ? (string)$_GET['g'] : '';
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
$hub_item = $hub_slug !== '' ? bc_adm_hub_find('admin', $hub_slug) : null;
bc_adm_head(($hub_item ? $hub_item['label'] : 'Explore') . " | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => $hub_item ? strtoupper($hub_item['label']) : 'EXPLORE', "crumb" => "Home / " . ($hub_item ? $hub_item['label'] : 'Explore'))); ?>

<div class="pk-hub__hero">
    <h1><?php echo bc_adm_icon($hub_item && !empty($hub_item['icon']) ? $hub_item['icon'] : 'grid-3x3-gap'); ?> <?php echo htmlspecialchars($hub_item ? $hub_item['label'] : 'Explore'); ?></h1>
    <p><?php echo $hub_item ? 'Everything in this section, one click away.' : 'Pick a section to see everything inside it.'; ?></p>
</div>

<?php if ($hub_item): ?>
    <?php bc_adm_hub_render($hub_item, 'admin'); ?>
<?php else: ?>
    <div class="pk-matrix">
        <?php foreach (bc_adm_menu('admin') as $group): ?>
            <?php foreach ((array)$group['items'] as $item): ?>
                <?php if (empty($item['children'])) continue; ?>
                <a class="pk-matrix__card" href="<?php echo htmlspecialchars(bc_adm_url('Hub.php?g=' . urlencode(bc_adm_hub_slug($item['label'])), 'admin')); ?>">
                    <span class="pk-matrix__icon"><?php echo bc_adm_icon(!empty($item['icon']) ? $item['icon'] : 'grid'); ?></span>
                    <span class="pk-matrix__label"><?php echo htmlspecialchars($item['label']); ?></span>
                    <span class="pk-matrix__go"><?php echo bc_adm_icon('arrow-right-circle'); ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php bc_adm_foot(array("area" => "admin")); ?>
