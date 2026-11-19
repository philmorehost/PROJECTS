<?php
session_start();
include("../func/bc-config.php");

$hub_slug = isset($_GET['g']) ? (string)$_GET['g'] : '';
?>
<?php
// Plate app shell: section landing pages. The sidebar lists main items only;
// each one lands here and lists its sub-pages as an embossed dark matrix.
include_once(__DIR__ . "/../func/bc-plate-app.php");
$hub_group = $hub_slug !== '' ? bc_app_hub_group($hub_slug) : null;
bc_app_head(($hub_group ? $hub_group['label'] : 'Explore') . " | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => $hub_group ? strtoupper($hub_group['label']) : 'EXPLORE', "crumb" => "Home / " . ($hub_group ? $hub_group['label'] : 'Explore'))); ?>

<div class="pk-hub__hero">
    <h1><?php echo bc_app_icon($hub_group && !empty($hub_group['icon']) ? $hub_group['icon'] : 'grid-3x3-gap'); ?> <?php echo htmlspecialchars($hub_group ? $hub_group['label'] : 'Explore'); ?></h1>
    <p><?php echo $hub_group ? 'Everything in this section, one tap away.' : 'Pick a section to see everything inside it.'; ?></p>
</div>

<?php if ($hub_group): ?>
    <?php bc_app_hub_render($hub_group); ?>
<?php else: ?>
    <div class="pk-matrix">
        <?php foreach (bc_app_menu() as $group): ?>
            <?php if (empty($group['label']) || empty($group['id'])) continue; ?>
            <a class="pk-matrix__card" href="<?php echo htmlspecialchars(bc_app_url('Hub.php?g=' . urlencode($group['id']))); ?>">
                <span class="pk-matrix__icon"><?php echo bc_app_icon(!empty($group['icon']) ? $group['icon'] : 'grid'); ?></span>
                <span class="pk-matrix__label"><?php echo htmlspecialchars($group['label']); ?></span>
                <span class="pk-matrix__go"><?php echo bc_app_icon('arrow-right-circle'); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php bc_app_foot(); ?>
