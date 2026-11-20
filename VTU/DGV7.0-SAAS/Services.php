<?php
// §41 — Services index landing page (one dedicated page per service lives
// under services/<slug>.php).
include(__DIR__ . "/func/bc-connect.php");
require_once(__DIR__ . "/func/bc-landing-pages.php");
bc_landing_render_services_index(bc_landing_context());
