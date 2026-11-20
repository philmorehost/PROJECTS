<?php
// §43 — Terms of Service landing page. Content lives in func/bc-landing-pages.php
// (bc_landing_legal_sections) so this page and web/policy/terms.php never drift.
include(__DIR__ . "/func/bc-connect.php");
require_once(__DIR__ . "/func/bc-landing-pages.php");
bc_landing_render_legal(bc_landing_context(), 'terms');
