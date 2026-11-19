<?php
include_once(__DIR__ . "/../func/bc-plate-auth.php");
bc_plate_auth_head("Website Not Found");
bc_plate_auth_top(array(
    'icon'       => 'warn',
    'icon_style' => 'danger',
    'title'      => 'Website Not Found',
    'sub'        => "We're sorry, but the website you are looking for does not exist. Please verify the URL or contact the site administrator for assistance.",
));
bc_plate_auth_end();
