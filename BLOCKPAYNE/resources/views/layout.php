<?php
declare(strict_types=1);

use App\Support\DynamicBrand;
use App\Support\Security;
use App\Support\Settings;

/**
 * Shared HTML5 shell.
 * Expects $pageTitle (string) and $bodyContent (string).
 */
$siteTitle = bp_installed() ? Settings::get('site_title', 'BlockPayne') : 'BlockPayne';
$accent = bp_installed() ? DynamicBrand::accentColor() : '#10b981';
$fullTitle = isset($pageTitle) && $pageTitle !== '' ? ($pageTitle . ' · ' . $siteTitle) : $siteTitle;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= bp_e($fullTitle) ?></title>
  <meta name="theme-color" content="#020617">
  <meta name="csrf-token" content="<?= bp_e(Security::csrfToken()) ?>">
  <meta name="bp-base" content="<?= bp_e(bp_base_path()) ?>">
  <link rel="icon" type="image/svg+xml" href="<?= bp_e(bp_url('favicon.svg')) ?>">
  <link rel="manifest" href="<?= bp_e(bp_url('manifest.webmanifest')) ?>">
  <link rel="stylesheet" href="<?= bp_e(bp_asset('app.css')) ?>">
  <style>:root { --bp-accent: <?= bp_e($accent) ?>; }</style>
</head>
<body>
<?= $bodyContent ?? '' ?>
<script src="<?= bp_e(bp_asset('app.js')) ?>" defer></script>
</body>
</html>
