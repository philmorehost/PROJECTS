<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-theme.php';
$n=0;
function sr($ok,$label){global $n;$n++;if(!$ok)throw new RuntimeException('FAIL '.$label);}
$colors=array('#FFFFFF','#000000','#FFFF00','#00FF00','#777777','#FF0000','#287BFF','#10756F','#059669','#DC2626','#D97706','#0284C7');
foreach($colors as $brand)foreach(array(0,.1,.2,.25,.5,.75,1) as $alpha){
 $p=bc_theme_tint($brand,$alpha);
 sr(bc_theme_contrast($p['foreground'],$p['background'])>=4.5,'tinted panel foreground contrast');
 sr(bc_theme_contrast($p['link'],$p['background'])>=4.5,'tinted panel link contrast');
 if($alpha===1)sr($p['background']===$brand,'solid brand retained');
 if($alpha===0)sr($p['background']==='#FFFFFF','transparent appearance is neutral');
}
for($r=0;$r<256;$r+=17)for($g=0;$g<256;$g+=17)for($b=0;$b<256;$b+=17){
 $p=bc_theme_tint(sprintf('#%02X%02X%02X',$r,$g,$b),.1);
 sr(bc_theme_contrast($p['foreground'],$p['background'])>=4.5,'security-panel RGB foreground sweep');
 sr(bc_theme_contrast($p['link'],$p['background'])>=4.5,'security-panel RGB link sweep');
}
sr(bc_theme_tint('#10756F',.1)['background']==='#E7F1F1','reported teal panel pale surface');
foreach(array('func/bc-plate-app.php','func/bc-plate-admin.php','func/bc-theme.php') as $file){
 sr(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/'.$file)===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/'.$file),'edition parity '.$file);
}
echo "$n surface readability checks passed\n";
