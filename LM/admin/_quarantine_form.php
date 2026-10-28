<?php
/**
 * One "quarantine this file" control.
 *
 * Expects $hit = array('file' => relative path, 'label' => why it was flagged).
 *
 * The file name has to be typed before the button enables, so a misplaced click
 * cannot remove something: this is the only control in the panel that deletes a
 * file the admin did not create. The server re-checks the typed name anyway —
 * the browser check is a guard against accidents, not a security boundary.
 *
 * The file is renamed to .<name>.removed-<date>.bak, in place: it stops being
 * executable (it no longer ends in .php) and is kept as evidence.
 */
if (!isset($hit) || !is_array($hit)) {
    return;
}

$quarantineName = (string) ($hit['file'] ?? '');
$quarantineBase = basename($quarantineName);
$quarantineSafe = htmlspecialchars($quarantineBase, ENT_QUOTES, 'UTF-8');
?>
<form method="POST" class="quarantine">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('lm_csrf_token') ? lm_csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="remove_file" value="<?= htmlspecialchars($quarantineName, ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="confirm_name" placeholder="type <?= $quarantineSafe ?>"
           aria-label="Type <?= $quarantineSafe ?> to confirm"
           autocomplete="off" spellcheck="false"
           data-name="<?= $quarantineSafe ?>"
           oninput="this.parentNode.querySelector('button').disabled = this.value.trim() !== this.dataset.name;">
    <button type="submit" disabled>Quarantine</button>
</form>
