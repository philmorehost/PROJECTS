<?php
/** Container inspection is not capture-origin or biometric liveness attestation. */
function bc_video_evidence_metadata_valid($metadata) {
    if (!is_array($metadata) || !is_array($metadata['format']??null) || !is_array($metadata['streams']??null)) return false;
    $duration=$metadata['format']['duration']??null;
    if ($duration===null || $duration==='N/A') {
        // Browser MediaRecorder WebM often omits container duration. Derive it from bounded packet timestamps.
        $packets=$metadata['packets']??null;if(!is_array($packets)||!$packets||count($packets)>4096)return false;
        $first=INF;$last=-INF;
        foreach($packets as $packet){
            $pts=$packet['pts_time']??null;$span=$packet['duration_time']??0;
            if(!is_numeric($pts)||!is_numeric($span))return false;
            $pts=(float)$pts;$span=(float)$span;
            if(!is_finite($pts)||!is_finite($span)||$pts< -1||$pts>10||$span<0||$span>1)return false;
            $first=min($first,$pts);$last=max($last,$pts+$span);
        }
        $duration=$last-$first;
    }
    if ((!is_string($duration)&&!is_int($duration)&&!is_float($duration)) || !is_numeric($duration)) return false;
    $seconds=(float)$duration;
    if (!is_finite($seconds) || $seconds<1 || $seconds>9) return false;
    $formats=explode(',',(string)($metadata['format']['format_name']??''));
    if (!array_intersect($formats,['mov','mp4','matroska','webm'])) return false;
    $video=0;$audio=0;
    foreach ($metadata['streams'] as $stream) {
        if (!is_array($stream)) return false;
        $type=$stream['codec_type']??'';
        if ($type==='video') {
            $video++;
            if (!in_array($stream['codec_name']??'', ['h264','hevc','vp8','vp9','av1'],true)) return false;
            $w=$stream['width']??0;$h=$stream['height']??0;
            if (!is_int($w)||!is_int($h)||min($w,$h)<160||max($w,$h)>1920) return false;
            if (!empty($stream['disposition']['attached_pic'])) return false;
        } elseif ($type==='audio') {
            $audio++;
            if (!in_array($stream['codec_name']??'', ['aac','opus','vorbis','mp3'],true)) return false;
        } else return false;
        if (isset($stream['duration']) && is_numeric($stream['duration'])) {
            $length=(float)$stream['duration'];if(!is_finite($length)||$length<0||$length>9) return false;
        }
    }
    return $video===1 && $audio===1;
}
function bc_video_evidence_inspect($tmp,$mime) {
    if (!in_array($mime,['video/mp4','video/webm'],true)) return false;
    $path=realpath($tmp);
    if (!$path||!is_file($path)||filesize($path)<=0||filesize($path)>3*1024*1024) return false;
    // Operator-owned absolute binary only. Never accept an executable or arguments from a request.
    $binary=defined('BC_FFPROBE_PATH')?BC_FFPROBE_PATH:'/usr/bin/ffprobe';
    if (!is_string($binary)||$binary===''||$binary[0]!=='/'||!is_executable($binary)) return false;
    foreach(['proc_open','proc_get_status','proc_terminate','proc_close','stream_set_blocking'] as $fn)if(!function_exists($fn))return false;
    $process=null;$pipes=[];$output='';$errors='';$finished=false;$exit=-1;
    try {
        // Force only an expected container demuxer; disallow network protocols and playlist formats.
        $args=bc_video_evidence_command($binary,$mime,$path);
        $process=@proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))return false;
        fclose($pipes[0]);
        if(!stream_set_blocking($pipes[1],false)||!stream_set_blocking($pipes[2],false))return false;
        $deadline=microtime(true)+5;
        do {
            $output.=stream_get_contents($pipes[1],262145-strlen($output));
            $errors.=stream_get_contents($pipes[2],8193-strlen($errors));
            if(strlen($output)>262144||strlen($errors)>8192)break;
            $state=proc_get_status($process);
            if(!is_array($state)||!array_key_exists('running',$state))return false;
            if(!$state['running']){$finished=true;$exit=(int)$state['exitcode'];break;}
            usleep(10000);
        } while(microtime(true)<$deadline);
        if(!$finished)return false;
        $output.=stream_get_contents($pipes[1],262145-strlen($output));
        $errors.=stream_get_contents($pipes[2],8193-strlen($errors));
        return $exit===0 && $errors==='' && strlen($output)<=262144 && bc_video_evidence_metadata_valid(json_decode($output,true));
    } catch(Throwable $e) { return false; }
    finally {
        if(is_resource($process)) {
            if(!$finished){@proc_terminate($process,9);}
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            @proc_close($process);
        }
    }
}

function bc_video_evidence_command($binary,$mime,$path) {
    return [$binary,'-v','error','-protocol_whitelist','file','-max_alloc','16777216','-probesize','3145728','-analyzeduration','3000000','-f',$mime==='video/mp4'?'mov':'matroska','-show_packets','-show_entries','packet=pts_time,duration_time:format=duration,format_name:stream=codec_type,codec_name,width,height,duration:stream_disposition=attached_pic','-of','json',$path];
}
