<?php
/** Container inspection is not capture-origin or biometric liveness attestation. */
function bc_video_evidence_last_error($msg = null) {
    if ($msg !== null) {
        $GLOBALS['bc_video_evidence_last_error'] = (string)$msg;
    }
    return (string)($GLOBALS['bc_video_evidence_last_error'] ?? '');
}
function bc_video_evidence_metadata_valid($metadata) {
    bc_video_evidence_last_error('');
    if (!is_array($metadata) || !is_array($metadata['format']??null) || !is_array($metadata['streams']??null)) {
        bc_video_evidence_last_error('Unreadable video container metadata.');
        return false;
    }
    $duration=$metadata['format']['duration']??null;
    if ($duration===null || $duration==='N/A') {
        // Browser MediaRecorder WebM often omits container duration. Derive it from bounded packet timestamps.
        $packets=$metadata['packets']??null;
        if(!is_array($packets)||!$packets||count($packets)>4096){
            bc_video_evidence_last_error('Video timing metadata is missing or too long.');
            return false;
        }
        $first=INF;$last=-INF;
        foreach($packets as $packet){
            $pts=$packet['pts_time']??null;
            $raw_span=$packet['duration_time']??null;
            $span=($raw_span===null||$raw_span===''||$raw_span==='N/A')?0:$raw_span;
            if(!is_numeric($pts)||!is_numeric($span)){
                bc_video_evidence_last_error('Invalid video packet timestamps.');
                return false;
            }
            $pts=(float)$pts;$span=(float)$span;
            if(!is_finite($pts)||!is_finite($span)||$pts< -1||$pts>10||$span<0||$span>1){
                bc_video_evidence_last_error('Video duration must be between 1 and 9 seconds.');
                return false;
            }
            $first=min($first,$pts);$last=max($last,$pts+$span);
        }
        $duration=$last-$first;
    }
    if ((!is_string($duration)&&!is_int($duration)&&!is_float($duration)) || !is_numeric($duration)) {
        bc_video_evidence_last_error('Could not determine video duration.');
        return false;
    }
    $seconds=(float)$duration;
    if (!is_finite($seconds) || $seconds<1 || $seconds>9) {
        bc_video_evidence_last_error('Video duration must be between 1 and 9 seconds.');
        return false;
    }
    $formats=explode(',',(string)($metadata['format']['format_name']??''));
    if (!array_intersect($formats,['mov','mp4','matroska','webm'])) {
        bc_video_evidence_last_error('Unsupported video container format.');
        return false;
    }
    $video=0;$audio=0;
    foreach ($metadata['streams'] as $stream) {
        if (!is_array($stream)) return false;
        $type=$stream['codec_type']??'';
        if ($type==='video') {
            $video++;
            if (!in_array($stream['codec_name']??'', ['h264','hevc','vp8','vp9','av1'],true)) {
                bc_video_evidence_last_error('Unsupported video codec.');
                return false;
            }
            $w=$stream['width']??0;$h=$stream['height']??0;
            if (!is_int($w)||!is_int($h)||min($w,$h)<160||max($w,$h)>1920) {
                bc_video_evidence_last_error('Video resolution must be between 160px and 1920px.');
                return false;
            }
            if (!empty($stream['disposition']['attached_pic'])) {
                bc_video_evidence_last_error('Static cover image is not accepted as live video.');
                return false;
            }
        } elseif ($type==='audio') {
            $audio++;
            if (!in_array($stream['codec_name']??'', ['aac','opus','vorbis','mp3'],true)) {
                bc_video_evidence_last_error('Unsupported audio codec.');
                return false;
            }
        } elseif ($type==='data' && in_array($stream['codec_name']??'', ['tmcd','bin_data',''], true)) {
            // Safari/iOS camera MP4 containers may include a timecode data track.
            continue;
        } else {
            bc_video_evidence_last_error('Unexpected stream type inside video container.');
            return false;
        }
        if (isset($stream['duration']) && is_numeric($stream['duration'])) {
            $length=(float)$stream['duration'];
            if(!is_finite($length)||$length<0||$length>9){
                bc_video_evidence_last_error('Stream duration exceeds 9 seconds.');
                return false;
            }
        }
    }
    if ($video!==1 || $audio!==1) {
        bc_video_evidence_last_error($audio===0?'Video must include microphone audio.':'Video must contain exactly one video track and one audio track.');
        return false;
    }
    return true;
}
function bc_video_evidence_binary() {
    if (defined('BC_FFPROBE_PATH')) {
        $binary = BC_FFPROBE_PATH;
        return (is_string($binary)&&$binary!==''&&$binary[0]==='/'&&is_executable($binary))?$binary:'';
    }
    foreach (['/usr/bin/ffprobe','/usr/local/bin/ffprobe','/bin/ffprobe','/opt/ffmpeg/bin/ffprobe'] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return '';
}
function bc_video_evidence_inspect($tmp,$mime) {
    bc_video_evidence_last_error('');
    if (!in_array($mime,['video/mp4','video/webm'],true)) {
        bc_video_evidence_last_error('Video format must be MP4 or WebM.');
        return false;
    }
    $path=realpath($tmp);
    if (!$path||!is_file($path)||filesize($path)<=0||filesize($path)>3*1024*1024) {
        bc_video_evidence_last_error('Video file is missing, empty, or exceeds 3 MB.');
        return false;
    }
    // Operator-owned absolute binary only. Never accept an executable or arguments from a request.
    $binary=bc_video_evidence_binary();
    if ($binary==='') {
        bc_video_evidence_last_error('Server video inspector (ffprobe) is unavailable.');
        return false;
    }
    foreach(['proc_open','proc_get_status','proc_terminate','proc_close','stream_set_blocking'] as $fn)if(!function_exists($fn)){
        bc_video_evidence_last_error('Server video verification process functions are disabled.');
        return false;
    }
    $metadata=bc_video_evidence_probe(bc_video_evidence_command($binary,$mime,$path));
    if(!is_array($metadata)){
        if(bc_video_evidence_last_error()==='') bc_video_evidence_last_error('Video container inspection failed.');
        return false;
    }
    if(!isset($metadata['format']['duration'])||$metadata['format']['duration']==='N/A'){
        // Inspect video packet timing separately. Do not ask ffprobe to parse Opus packets as frames.
        $clock=bc_video_evidence_probe(bc_video_evidence_command($binary,$mime,$path,true));
        if(!is_array($clock)){
            if(bc_video_evidence_last_error()==='') bc_video_evidence_last_error('Video packet timing inspection failed.');
            return false;
        }
        $metadata['packets']=$clock['packets']??null;
    }
    return bc_video_evidence_metadata_valid($metadata);
}
function bc_video_evidence_probe($args) {
    $process=null;$pipes=[];$output='';$errors='';$finished=false;$exit=-1;
    try {
        // Force only an expected container demuxer; disallow network protocols and playlist formats.
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
        if(!$finished){bc_video_evidence_last_error('Video inspection timed out.');return false;}
        $output.=stream_get_contents($pipes[1],262145-strlen($output));
        $errors.=stream_get_contents($pipes[2],8193-strlen($errors));
        if($exit!==0||$errors!==''||strlen($output)>262144)return false;
        $parsed=json_decode($output,true);return is_array($parsed)?$parsed:false;
    } catch(Throwable $e) { return false; }
    finally {
        if(is_resource($process)) {
            if(!$finished){@proc_terminate($process,9);}
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            @proc_close($process);
        }
    }
}

function bc_video_evidence_command($binary,$mime,$path,$packet_clock=false) {
    $args=[$binary,'-v','error','-protocol_whitelist','file','-max_alloc','16777216','-probesize','3145728','-analyzeduration','3000000','-f',$mime==='video/mp4'?'mov':'matroska'];
    if($packet_clock)$args=array_merge($args,['-fflags','+noparse+nofillin','-select_streams','v:0','-show_packets']);
    $entries=$packet_clock?'packet=pts_time,duration_time':'format=duration,format_name:stream=codec_type,codec_name,width,height,duration:stream_disposition=attached_pic';
    return array_merge($args,['-show_entries',$entries,'-of','json',$path]);
}
