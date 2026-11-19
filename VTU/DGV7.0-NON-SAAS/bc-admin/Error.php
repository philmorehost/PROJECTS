<?php
include_once(__DIR__ . "/../func/bc-plate-auth.php");
$bc_db_error = isset($_GET["reason"]) && $_GET["reason"] === "db";
bc_plate_auth_head($bc_db_error ? "Service Temporarily Unavailable" : "Website Not Found");
bc_plate_auth_top(array(
    'icon'       => 'warn',
    'icon_style' => 'danger',
    'title'      => $bc_db_error ? 'Service Temporarily Unavailable' : 'Website Not Found',
    'sub'        => $bc_db_error
        ? "The site could not connect to its database. If this persists, check that func/db-json.php holds this installation's database credentials, or re-run the installer to repair them."
        : "We're sorry, but the website you are looking for does not exist. Please verify the URL or contact the site administrator for assistance.",
));
bc_plate_auth_end();
