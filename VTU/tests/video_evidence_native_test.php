<?php
if(getenv('BC_VIDEO_EVIDENCE_CI')!=='1')throw new RuntimeException('Isolated media fixture tests only');
set_exception_handler(function($e){echo '::error::'.$e->getMessage()."\n";exit(1);});
if(($argv[1]??'')==='missing')define('BC_FFPROBE_PATH','/nonexistent/bc-ffprobe');
if(($argv[1]??'')==='timeout')define('BC_FFPROBE_PATH',($argv[2]??'').'/stall-probe');
require __DIR__.'/../DGV7.0-SAAS/func/bc-live-capture.php';
$dir=$argv[2]??'';if(!is_dir($dir))throw new RuntimeException('fixture directory required');
$n=0;function vn($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
if(($argv[1]??'')==='missing'){
 vn(!bc_live_media_prepare($dir.'/valid.mp4','liveliness_video'),'missing inspector fails closed');
}elseif(($argv[1]??'')==='timeout'){
 $start=microtime(true);vn(!bc_live_media_prepare($dir.'/valid.mp4','liveliness_video'),'stalled inspector refused');
 vn(microtime(true)-$start<8,'stalled inspector terminated within bound');
}else{
 foreach(['valid.mp4','valid.webm','live.webm'] as $file)vn(bc_live_media_prepare($dir.'/'.$file,'liveliness_video'),'valid AV accepted '.$file);
 foreach(['long.mp4','silent.mp4','audio.mp4','tiny.mp4','junk.mp4','oversize.mp4'] as $file)vn(!bc_live_media_prepare($dir.'/'.$file,'liveliness_video'),'invalid evidence refused '.$file);
 vn(!bc_video_evidence_inspect($dir.'/valid.mp4','video/webm'),'MIME/container mismatch refused');
}
echo "$n native ffprobe video checks passed\n";
