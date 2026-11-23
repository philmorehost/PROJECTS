<?php
/** Presentation only: saved tenant shortcuts intersected with the real navigation gates. */
function bc_float_services_render() {
    global $connection_server, $get_logged_user_details;
    if (!function_exists('mysqli_query') || empty($connection_server)) return;
    if (!is_array($get_logged_user_details ?? null) || empty($get_logged_user_details['vendor_id'])) return;
    $vid=(int)$get_logged_user_details['vendor_id'];
    $q=mysqli_query($connection_server,"SELECT service_name FROM sas_float_services WHERE vendor_id='$vid' AND status=1");
    if (!$q) return;
    $selected=[];
    while($r=mysqli_fetch_assoc($q))$selected[$r['service_name']]=true;
    if(!$selected)return;
    $items=[];
    foreach(bc_app_menu() as $group)foreach($group['items']??[] as $item) {
        if (!isset($selected[$item['label']]) || !bc_app_item_visible($item,$get_logged_user_details))continue;
        if (!is_file(__DIR__.'/../web/'.$item['href']))continue;
        $items[$item['href']]=$item;
    }
    // Older configuration labels differ from the navigation's current names.
    foreach(['Send SMS'=>'BulkSMS.php','Gift Card'=>'GiftCard.php','Virtual Card'=>'VirtualCard.php','Data Bundle Card'=>'DataBundleCard.php','Card Printing'=>'DataBundleCard.php'] as $label=>$href) {
        if(!isset($selected[$label]))continue;
        foreach(bc_app_menu() as $group)foreach($group['items']??[] as $item) {
            if($item['href']===$href && bc_app_item_visible($item,$get_logged_user_details) && is_file(__DIR__.'/../web/'.$href))$items[$href]=$item;
        }
    }
    if(!$items)return;
    echo '<style>.bc-float-services{position:fixed;right:16px;bottom:90px;z-index:900;max-width:min(320px,85vw);font:14px system-ui}.bc-float-services summary{cursor:pointer;background:#0f766e;color:white;padding:12px 18px;border-radius:24px;box-shadow:0 4px 18px #0003}.bc-float-services nav{display:grid;gap:8px;background:white;padding:12px;border:1px solid #ddd;border-radius:14px;max-height:50vh;overflow:auto}.bc-float-services a{display:flex;gap:10px;padding:8px;color:#123;text-decoration:none}.bc-float-services a:focus-visible{outline:3px solid #0f766e}</style><details class="bc-float-services"><summary>Quick services</summary><nav aria-label="Floating service shortcuts">';
    foreach($items as $item) {
        echo '<a href="'.htmlspecialchars(bc_app_url($item['href']),ENT_QUOTES,'UTF-8').'">'.bc_app_icon($item['icon']).'<span>'.htmlspecialchars($item['label'],ENT_QUOTES,'UTF-8').'</span></a>';
    }
    echo '</nav></details>';
}
