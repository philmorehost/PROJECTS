<?php
// Compatibility URL: the canonical worker enforces CLI or the platform cron secret,
// tenant context, bounded work and overlap protection. Never use a browser session.
require __DIR__ . '/cron/process_requery_queue.php';
