<?php
// §41 — Refer & Earn landing page. Programme numbers come from the vendor's
// own Loyalty Settings via func/bc-landing-pages.php, so the copy always
// matches the money rules.
include(__DIR__ . "/func/bc-connect.php");
require_once(__DIR__ . "/func/bc-landing-pages.php");
bc_landing_render_referral(bc_landing_context());
