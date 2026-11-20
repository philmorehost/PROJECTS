<?php
// §43 — Privacy Policy landing page. Content lives in func/bc-landing-pages.php
// (bc_landing_legal_sections) so this page and web/policy/privacy.php never drift.
include(__DIR__ . "/func/bc-connect.php");
require_once(__DIR__ . "/func/bc-landing-pages.php");
bc_landing_render_legal(bc_landing_context(), 'privacy');
