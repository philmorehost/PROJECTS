<?php
/** Explicit public-template map: persisted CSS names are never include paths. */
function bc_public_template_file($saved,$freelancers=false){
    if($freelancers)return 'index-freelancers-template.php';
    $templates=[
        'bc-style-template-meridian-1.css'=>'index-bc-style-template-meridian-1.php',
        'bc-style-template-1.css'=>'index-bc-style-template-1.php',
        'bc-style-template-2.css'=>'index-bc-style-template-2.php',
        'bc-style-template-3.css'=>'index-bc-style-template-3.php',
        'bc-style-template-6.css'=>'index-bc-style-template-6.php',
    ];
    return is_string($saved)&&isset($templates[$saved])?$templates[$saved]:'index-bc-style-template-meridian-1.php';
}
