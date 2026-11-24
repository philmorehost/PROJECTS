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
 foreach(['valid.mp4','valid.webm','live.webm'] as $file){
  $ok=bc_live_media_prepare($dir.'/'.$file,'liveliness_video');
  if(!$ok){
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($dir.'/'.$file);
   $p=proc_open(bc_video_evidence_command('/usr/bin/ffprobe',$mime,$dir.'/'.$file),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
   fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
   echo '::error::Synthetic fixture diagnostic '.json_encode([$file,$mime,$exit,strlen($out),bc_video_evidence_metadata_valid(json_decode($out,true)),json_decode($out,true)['format']??null,$err])."\n";
  }
  vn($ok,'valid AV accepted '.$file);
 }
 foreach(['long.mp4','silent.mp4','audio.mp4','tiny.mp4','junk.mp4','oversize.mp4'] as $file)vn(!bc_live_media_prepare($dir.'/'.$file,'liveliness_video'),'invalid evidence refused '.$file);
 vn(!bc_video_evidence_inspect($dir.'/valid.mp4','video/webm'),'MIME/container mismatch refused');
}
echo "$n native ffprobe video checks passed\n";
