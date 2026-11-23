<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-public-template.php';
$n=0;function pt_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed;
 foreach([1,2,3,6] as $id){$file=bc_public_template_file('bc-style-template-'.$id.'.css');pt_check($file==='index-bc-style-template-'.$id.'.php','selected template dispatched');pt_check(is_file($root.'/'.$file),'target exists');}
 foreach([null,'','../../etc/passwd','index.php','bc-style-template-99.css',[]] as $bad)pt_check(bc_public_template_file($bad)==='index-bc-style-template-home.php','safe default');
 pt_check(bc_public_template_file('bc-style-template-2.css',true)==='index-freelancers-template.php','freelancer route preserved');
 $s=file_get_contents($root.'/index.php');pt_check(strpos($s,'bc_public_template_file(')!==false&&strpos($s,'WHERE vendor_id=?')!==false,'tenant-scoped live selection');
 $hashes=[];foreach([2,3,6] as $id)$hashes[]=hash_file('sha256',$root.'/index-bc-style-template-'.$id.'.php');pt_check(count(array_unique($hashes))===3,'existing designs distinct');
}
echo "$n public template selection checks passed\n";
