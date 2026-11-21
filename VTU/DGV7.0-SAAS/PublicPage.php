<?php
require_once __DIR__ . '/func/bc-connect.php';
require_once __DIR__ . '/func/bc-landing-pages.php';
require_once __DIR__ . '/func/bc-public-pages.php';
bc_public_render(bc_landing_context(), $bc_public_page ?? '');
