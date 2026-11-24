<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-video-evidence.php';
$n=0;function ve($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$valid=['format'=>['duration'=>'8.02','format_name'=>'mov,mp4,m4a,3gp,3g2,mj2'],'streams'=>[['codec_type'=>'video','codec_name'=>'h264','width'=>640,'height'=>480],['codec_type'=>'audio','codec_name'=>'aac']]];
ve(bc_video_evidence_metadata_valid($valid),'bounded AV accepted');
foreach([0,0.9,9.1,'N/A','not numeric',[],INF,NAN] as $duration){$m=$valid;$m['format']['duration']=$duration;ve(!bc_video_evidence_metadata_valid($m),'duration refused');}
foreach(['audio','video'] as $type){$m=$valid;$m['streams']=array_values(array_filter($m['streams'],fn($v)=>$v['codec_type']===$type));ve(!bc_video_evidence_metadata_valid($m),'missing track refused');}
$m=$valid;$m['streams'][]=$m['streams'][0];ve(!bc_video_evidence_metadata_valid($m),'extra video refused');
foreach([0,159,1921,99999,'640'] as $width){$m=$valid;$m['streams'][0]['width']=$width;ve(!bc_video_evidence_metadata_valid($m),'invalid dimensions');}
$m=$valid;$m['streams'][0]['disposition']=['attached_pic'=>1];ve(!bc_video_evidence_metadata_valid($m),'cover art not camera video');
$m=$valid;$m['streams'][0]['codec_name']='mjpeg';ve(!bc_video_evidence_metadata_valid($m),'unsupported video codec');
$m=$valid;$m['streams'][1]['duration']='60';ve(!bc_video_evidence_metadata_valid($m),'long audio refused');
$m=$valid;$m['format']['format_name']='hls';ve(!bc_video_evidence_metadata_valid($m),'playlist refused');
$m=$valid;unset($m['format']['duration']);$m['format']['format_name']='matroska,webm';$m['packets']=[['pts_time'=>'0','duration_time'=>'.02'],['pts_time'=>'7.98','duration_time'=>'.02']];ve(bc_video_evidence_metadata_valid($m),'browser WebM without duration uses packet clock');
$m['packets'][1]['pts_time']='60';ve(!bc_video_evidence_metadata_valid($m),'long packet clock refused');
ve(!bc_video_evidence_inspect(__FILE__,'video/mp4'),'nonmedia unavailable inspector refused');ve(!bc_video_evidence_inspect(__FILE__,'application/pdf'),'bad mime refused');
foreach(['SAAS','NON-SAAS'] as $ed){ve(file_get_contents(__DIR__.'/../DGV7.0-'.$ed.'/func/bc-video-evidence.php')===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-video-evidence.php'),'edition parity');}
echo "$n video evidence checks passed\n";
