<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-public-pages.php';
$n=0;
function directory_check($ok,$label){global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;}
$canonical=array();foreach(bc_public_directory() as $link)$canonical[$link[1]]=$link[0];
$grouped=array();
$groups=bc_public_directory_groups();
directory_check(count($groups)===6,'six focused categories');
foreach($groups as $title=>$links){
 directory_check(count($links)>0,'nonempty '.$title);
 foreach($links as $link){directory_check(!isset($grouped[$link[1]]),'no duplicate destination '.$link[1]);$grouped[$link[1]]=$link[0];}
}
ksort($canonical);ksort($grouped);directory_check($canonical===$grouped,'all original destinations and labels preserved exactly');
ob_start();bc_public_directory_html();$html=ob_get_clean();
directory_check(strpos($html,'aria-label="Explore more"')!==false,'named navigation landmark');
directory_check(substr_count($html,'<ul ')===6 && substr_count($html,'<h3 ')===6,'semantic group lists/headings');
directory_check(substr_count($html,'<a ')===count($canonical),'one rendered link per destination');
directory_check(strpos($html,'repeat(6,minmax(0,1fr))')!==false && strpos($html,'repeat(3,minmax(0,1fr))')!==false && strpos($html,'repeat(2,minmax(0,1fr))')!==false,'desktop tablet and mobile grids');
directory_check(strpos($html,'.public-directory{display:block;position:static;')!==false,'legacy mobile nav hiding does not hide directory');
directory_check(strpos($html,':focus-visible')!==false && strpos($html,'prefers-reduced-motion')!==false,'keyboard and reduced motion styling');
ob_start();bc_public_directory_html();$again=ob_get_clean();
directory_check(strpos($again,'<style>')===false,'styles emitted once per response');
foreach(array('DGV7.0-SAAS','DGV7.0-NON-SAAS') as $edition){
 $root=dirname(__DIR__).'/'.$edition;
 foreach(array_keys($canonical) as $url)directory_check($url==='/' || is_file($root.$url),'destination exists '.$edition.$url);
 foreach(array('index-bc-style-template-home.php','index-freelancers-template.php','index-bc-style-template-2.php','index-bc-style-template-3.php','index-bc-style-template-6.php','func/bc-landing-pages.php') as $page)directory_check(strpos(file_get_contents($root.'/'.$page),'bc_public_directory_html()')!==false,'shared navigation used by '.$edition.'/'.$page);
}
directory_check(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-public-pages.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-public-pages.php'),'edition parity');
echo "$n public directory checks passed\n";
