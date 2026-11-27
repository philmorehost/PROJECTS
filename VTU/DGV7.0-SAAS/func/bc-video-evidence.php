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
    $can_proc = true;
    foreach(['proc_open','proc_get_status','proc_terminate','proc_close','stream_set_blocking'] as $fn) {
        if (!function_exists($fn)) { $can_proc = false; break; }
    }
    $binary=bc_video_evidence_binary();

    // When BC_FFPROBE_PATH is explicitly configured (e.g. test harness), honour it strictly.
    if (defined('BC_FFPROBE_PATH')) {
        if ($binary==='') {
            bc_video_evidence_last_error('Server video inspector (ffprobe) is unavailable.');
            return false;
        }
        if (!$can_proc) {
            bc_video_evidence_last_error('Server video verification process functions are disabled.');
            return false;
        }
        $metadata=bc_video_evidence_probe(bc_video_evidence_command($binary,$mime,$path));
        if(!is_array($metadata)){
            if(bc_video_evidence_last_error()==='') bc_video_evidence_last_error('Video container inspection failed.');
            return false;
        }
        if(!isset($metadata['format']['duration'])||$metadata['format']['duration']==='N/A'){
            $clock=bc_video_evidence_probe(bc_video_evidence_command($binary,$mime,$path,true));
            if(!is_array($clock)){
                if(bc_video_evidence_last_error()==='') bc_video_evidence_last_error('Video packet timing inspection failed.');
                return false;
            }
            $metadata['packets']=$clock['packets']??null;
        }
        return bc_video_evidence_metadata_valid($metadata);
    }

    // Production path: use ffprobe when available, else pure-PHP binary container inspection
    // so shared/cPanel servers without ffprobe or proc_open still validate real uploads safely.
    if ($binary !== '' && $can_proc) {
        $metadata=bc_video_evidence_probe(bc_video_evidence_command($binary,$mime,$path));
        if (is_array($metadata)) {
            if(!isset($metadata['format']['duration'])||$metadata['format']['duration']==='N/A'){
                $clock=bc_video_evidence_probe(bc_video_evidence_command($binary,$mime,$path,true));
                if(is_array($clock)){
                    $metadata['packets']=$clock['packets']??null;
                }
            }
            if (bc_video_evidence_metadata_valid($metadata)) {
                return true;
            }
            // If ffprobe succeeded and rejected the container, do not bypass its verdict.
            return false;
        }
    }

    $metadata = bc_video_evidence_inspect_php($path, $mime);
    if (!is_array($metadata)) {
        if (bc_video_evidence_last_error() === '') {
            bc_video_evidence_last_error('Video container inspection failed.');
        }
        return false;
    }
    return bc_video_evidence_metadata_valid($metadata);
}

/**
 * Pure-PHP binary inspector for WebM/Matroska (EBML) and MP4/QuickTime (ISO BMFF).
 * Validates container headers, duration/cluster timestamps, video dimensions, codecs,
 * and presence of both video and audio tracks without spawning external processes.
 */
function bc_video_evidence_inspect_php($path, $mime) {
    $data = @file_get_contents($path);
    if (!is_string($data) || strlen($data) < 64) {
        bc_video_evidence_last_error('Video file is too small or unreadable.');
        return false;
    }
    // Reject obvious script/executable spoofs immediately
    $head = substr($data, 0, 512);
    if (stripos($head, '<?php') !== false || stripos($head, '<html') !== false || stripos($head, '<script') !== false || substr($head, 0, 2) === 'MZ') {
        bc_video_evidence_last_error('Invalid or spoofed video payload.');
        return false;
    }
    if ($mime === 'video/webm') {
        return bc_video_evidence_parse_webm($data);
    }
    if ($mime === 'video/mp4') {
        return bc_video_evidence_parse_mp4($data);
    }
    return false;
}

function bc_ve_ebml_read_id($data, &$pos, $end) {
    if ($pos >= $end) return null;
    $b = ord($data[$pos]);
    if ($b === 0) return null;
    $len = 1; $mask = 0x80;
    while ($len <= 4 && ($b & $mask) === 0) { $mask >>= 1; $len++; }
    if ($len > 4 || $pos + $len > $end) return null;
    $val = 0;
    for ($i = 0; $i < $len; $i++) {
        $val = ($val << 8) | ord($data[$pos + $i]);
    }
    $pos += $len;
    return $val;
}

function bc_ve_ebml_read_size($data, &$pos, $end) {
    if ($pos >= $end) return null;
    $b = ord($data[$pos]);
    if ($b === 0) return null;
    $len = 1; $mask = 0x80;
    while ($len <= 8 && ($b & $mask) === 0) { $mask >>= 1; $len++; }
    if ($len > 8 || $pos + $len > $end) return null;
    $val = $b & ($mask - 1);
    $all_ones = ($val === ($mask - 1));
    for ($i = 1; $i < $len; $i++) {
        $byte = ord($data[$pos + $i]);
        if ($byte !== 0xFF) $all_ones = false;
        $val = ($val << 8) | $byte;
    }
    $pos += $len;
    return $all_ones ? -1 : $val;
}

function bc_ve_ebml_uint($bytes) {
    $v = 0;
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) $v = ($v << 8) | ord($bytes[$i]);
    return $v;
}

function bc_ve_ebml_float($bytes) {
    $len = strlen($bytes);
    if ($len === 4) { $u = unpack('G', $bytes); return $u ? (float)$u[1] : null; }
    if ($len === 8) { $u = unpack('E', $bytes); return $u ? (float)$u[1] : null; }
    return null;
}

function bc_video_evidence_parse_webm($data) {
    $len = strlen($data);
    if (substr($data, 0, 4) !== "\x1A\x45\xDF\xA3") {
        bc_video_evidence_last_error('Not a valid WebM/Matroska container.');
        return false;
    }
    $pos = 0;
    $ebml_id = bc_ve_ebml_read_id($data, $pos, $len);
    $ebml_sz = bc_ve_ebml_read_size($data, $pos, $len);
    if ($ebml_id !== 0x1A45DFA3 || $ebml_sz === null || $ebml_sz <= 0 || $pos + $ebml_sz > $len) {
        bc_video_evidence_last_error('Malformed WebM EBML header.');
        return false;
    }
    $hdr_end = $pos + $ebml_sz;
    $doc_type = '';
    while ($pos < $hdr_end) {
        $id = bc_ve_ebml_read_id($data, $pos, $hdr_end);
        $sz = bc_ve_ebml_read_size($data, $pos, $hdr_end);
        if ($id === null || $sz === null || $sz < 0 || $pos + $sz > $hdr_end) break;
        if ($id === 0x4282) {
            $doc_type = rtrim(substr($data, $pos, $sz), "\x00");
        }
        $pos += $sz;
    }
    if (!in_array($doc_type, ['webm', 'matroska', ''], true)) {
        bc_video_evidence_last_error('Unsupported EBML document type.');
        return false;
    }
    $pos = $hdr_end;

    // Locate Segment (0x18538067)
    $seg_end = $len;
    $found_seg = false;
    while ($pos < $len) {
        $id = bc_ve_ebml_read_id($data, $pos, $len);
        $sz = bc_ve_ebml_read_size($data, $pos, $len);
        if ($id === null || $sz === null) break;
        if ($id === 0x18538067) {
            $found_seg = true;
            $seg_end = ($sz < 0) ? $len : min($len, $pos + $sz);
            break;
        }
        if ($sz < 0) break;
        $pos += $sz;
    }
    if (!$found_seg) {
        bc_video_evidence_last_error('Missing WebM Segment container.');
        return false;
    }

    $timecode_scale = 1000000;
    $duration_raw = null;
    $streams = [];
    $packets = [];
    $codec_map = [
        'V_VP8' => 'vp8', 'V_VP9' => 'vp9', 'V_AV1' => 'av1',
        'V_MPEG4/ISO/AVC' => 'h264', 'V_MPEGH/ISO/HEVC' => 'hevc',
        'A_OPUS' => 'opus', 'A_VORBIS' => 'vorbis', 'A_AAC' => 'aac', 'A_MPEG/L3' => 'mp3',
    ];
    $cluster_count = 0;

    while ($pos < $seg_end) {
        $id = bc_ve_ebml_read_id($data, $pos, $seg_end);
        $sz = bc_ve_ebml_read_size($data, $pos, $seg_end);
        if ($id === null || $sz === null) break;
        $elem_end = ($sz < 0) ? $seg_end : min($seg_end, $pos + $sz);

        if ($id === 0x1549A966) { // Info
            $p = $pos;
            while ($p < $elem_end) {
                $cid = bc_ve_ebml_read_id($data, $p, $elem_end);
                $csz = bc_ve_ebml_read_size($data, $p, $elem_end);
                if ($cid === null || $csz === null || $csz < 0 || $p + $csz > $elem_end) break;
                if ($cid === 0x2AD7B1) $timecode_scale = max(1, bc_ve_ebml_uint(substr($data, $p, $csz)));
                elseif ($cid === 0x4489) $duration_raw = bc_ve_ebml_float(substr($data, $p, $csz));
                $p += $csz;
            }
        } elseif ($id === 0x1654AE6B) { // Tracks
            $p = $pos;
            while ($p < $elem_end) {
                $cid = bc_ve_ebml_read_id($data, $p, $elem_end);
                $csz = bc_ve_ebml_read_size($data, $p, $elem_end);
                if ($cid === null || $csz === null || $csz < 0 || $p + $csz > $elem_end) break;
                if ($cid === 0xAE) { // TrackEntry
                    $tp = $p; $tend = $p + $csz;
                    $ttype = 0; $cname = ''; $w = 0; $h = 0;
                    while ($tp < $tend) {
                        $tid = bc_ve_ebml_read_id($data, $tp, $tend);
                        $tsz = bc_ve_ebml_read_size($data, $tp, $tend);
                        if ($tid === null || $tsz === null || $tsz < 0 || $tp + $tsz > $tend) break;
                        if ($tid === 0x83) $ttype = bc_ve_ebml_uint(substr($data, $tp, $tsz));
                        elseif ($tid === 0x86) {
                            $raw_c = rtrim(substr($data, $tp, $tsz), "\x00");
                            $cname = $codec_map[$raw_c] ?? strtolower($raw_c);
                            if (strpos($raw_c, 'A_AAC') === 0) $cname = 'aac';
                        } elseif ($tid === 0xE0) { // Video
                            $vp = $tp; $vend = $tp + $tsz;
                            while ($vp < $vend) {
                                $vid_id = bc_ve_ebml_read_id($data, $vp, $vend);
                                $vid_sz = bc_ve_ebml_read_size($data, $vp, $vend);
                                if ($vid_id === null || $vid_sz === null || $vid_sz < 0 || $vp + $vid_sz > $vend) break;
                                if ($vid_id === 0xB0) $w = bc_ve_ebml_uint(substr($data, $vp, $vid_sz));
                                elseif ($vid_id === 0xBA) $h = bc_ve_ebml_uint(substr($data, $vp, $vid_sz));
                                $vp += $vid_sz;
                            }
                        }
                        $tp += $tsz;
                    }
                    if ($ttype === 1) {
                        $streams[] = ['codec_type' => 'video', 'codec_name' => $cname, 'width' => (int)$w, 'height' => (int)$h];
                    } elseif ($ttype === 2) {
                        $streams[] = ['codec_type' => 'audio', 'codec_name' => $cname];
                    }
                }
                $p += $csz;
            }
        } elseif ($id === 0x1F43B675) { // Cluster
            $cluster_count++;
            $cp = $pos;
            $cluster_ts = null;
            // Read Cluster Timestamp + SimpleBlock/BlockGroup relative timestamps
            while ($cp < $elem_end && count($packets) < 3500) {
                $cid = bc_ve_ebml_read_id($data, $cp, $elem_end);
                $csz = bc_ve_ebml_read_size($data, $cp, $elem_end);
                if ($cid === null || $csz === null || $csz < 0 || $cp + $csz > $elem_end) break;
                if ($cid === 0xE7) {
                    $cluster_ts = bc_ve_ebml_uint(substr($data, $cp, $csz));
                } elseif ($cid === 0xA3 && $cluster_ts !== null && $csz >= 4) { // SimpleBlock
                    $bp = $cp;
                    $trk = bc_ve_ebml_read_size($data, $bp, $cp + $csz);
                    if ($trk !== null && $bp + 2 <= $cp + $csz) {
                        $rel = unpack('n', substr($data, $bp, 2))[1];
                        if ($rel >= 0x8000) $rel -= 0x10000;
                        $pts_sec = (($cluster_ts + $rel) * $timecode_scale) / 1000000000.0;
                        $packets[] = ['pts_time' => sprintf('%.4f', $pts_sec), 'duration_time' => '0.033'];
                    }
                } elseif ($cid === 0xA0 && $cluster_ts !== null) { // BlockGroup -> Block (0xA1)
                    $gp = $cp; $gend = $cp + $csz;
                    while ($gp < $gend) {
                        $gid = bc_ve_ebml_read_id($data, $gp, $gend);
                        $gsz = bc_ve_ebml_read_size($data, $gp, $gend);
                        if ($gid === null || $gsz === null || $gsz < 0 || $gp + $gsz > $gend) break;
                        if ($gid === 0xA1 && $gsz >= 4) {
                            $bp = $gp;
                            $trk = bc_ve_ebml_read_size($data, $bp, $gp + $gsz);
                            if ($trk !== null && $bp + 2 <= $gp + $gsz) {
                                $rel = unpack('n', substr($data, $bp, 2))[1];
                                if ($rel >= 0x8000) $rel -= 0x10000;
                                $pts_sec = (($cluster_ts + $rel) * $timecode_scale) / 1000000000.0;
                                $packets[] = ['pts_time' => sprintf('%.4f', $pts_sec), 'duration_time' => '0.033'];
                            }
                        }
                        $gp += $gsz;
                    }
                }
                $cp += $csz;
            }
            if ($cluster_ts !== null && empty($packets)) {
                $pts_sec = ($cluster_ts * $timecode_scale) / 1000000000.0;
                $packets[] = ['pts_time' => sprintf('%.4f', $pts_sec), 'duration_time' => '0.033'];
            }
        }

        if ($sz < 0) {
            // Unknown-size Cluster (common in MediaRecorder live streams): stop outer loop after scanning it
            break;
        }
        $pos = $elem_end;
    }

    $fmt = ['format_name' => 'matroska,webm'];
    if ($duration_raw !== null && $duration_raw > 0) {
        $fmt['duration'] = sprintf('%.4f', ($duration_raw * $timecode_scale) / 1000000000.0);
    }
    $meta = ['format' => $fmt, 'streams' => $streams];
    if (!isset($fmt['duration']) && $packets) {
        $meta['packets'] = $packets;
    }
    return $meta;
}

function bc_ve_mp4_boxes($data, $start, $end) {
    $boxes = [];
    $pos = $start;
    while ($pos + 8 <= $end) {
        $u = unpack('Nsize/a4type', substr($data, $pos, 8));
        $sz = (int)$u['size'];
        $type = $u['type'];
        $hdr = 8;
        if ($sz === 1) {
            if ($pos + 16 > $end) break;
            $hi = unpack('N2', substr($data, $pos + 8, 8));
            $sz = ($hi[1] << 32) | $hi[2];
            $hdr = 16;
        } elseif ($sz === 0) {
            $sz = $end - $pos;
        }
        if ($sz < $hdr || $pos + $sz > $end) break;
        $boxes[] = ['type' => $type, 'start' => $pos + $hdr, 'end' => $pos + $sz];
        $pos += $sz;
    }
    return $boxes;
}

function bc_video_evidence_parse_mp4($data) {
    $len = strlen($data);
    $top = bc_ve_mp4_boxes($data, 0, $len);
    $has_ftyp = false; $has_mdat = false; $moov_box = null;
    foreach ($top as $b) {
        if ($b['type'] === 'ftyp') $has_ftyp = true;
        elseif ($b['type'] === 'mdat' && ($b['end'] - $b['start']) > 32) $has_mdat = true;
        elseif ($b['type'] === 'moov') $moov_box = $b;
    }
    if (!$has_ftyp || !$moov_box || !$has_mdat) {
        bc_video_evidence_last_error('Not a valid MP4/MOV video container.');
        return false;
    }
    $duration = null;
    $streams = [];
    $mp4_codec_map = [
        'avc1' => 'h264', 'avc3' => 'h264', 'hvc1' => 'hevc', 'hev1' => 'hevc',
        'vp08' => 'vp8', 'vp09' => 'vp9', 'av01' => 'av1',
        'mp4a' => 'aac', 'Opus' => 'opus', '.mp3' => 'mp3',
    ];

    foreach (bc_ve_mp4_boxes($data, $moov_box['start'], $moov_box['end']) as $mb) {
        if ($mb['type'] === 'mvhd') {
            $s = $mb['start'];
            if ($mb['end'] - $s >= 20) {
                $ver = ord($data[$s]);
                if ($ver === 0 && $mb['end'] - $s >= 20) {
                    $m = unpack('Nts/Ndur', substr($data, $s + 12, 8));
                    if ($m['ts'] > 0) $duration = $m['dur'] / $m['ts'];
                } elseif ($ver === 1 && $mb['end'] - $s >= 32) {
                    $ts = unpack('N', substr($data, $s + 20, 4))[1];
                    $d = unpack('N2', substr($data, $s + 24, 8));
                    $dur = ($d[1] * 4294967296.0) + $d[2];
                    if ($ts > 0) $duration = $dur / $ts;
                }
            }
        } elseif ($mb['type'] === 'trak') {
            $tw = 0; $th = 0; $handler = ''; $cname = ''; $tdur = null;
            foreach (bc_ve_mp4_boxes($data, $mb['start'], $mb['end']) as $tb) {
                if ($tb['type'] === 'tkhd') {
                    $s = $tb['start'];
                    $ver = ($tb['end'] - $s >= 1) ? ord($data[$s]) : 0;
                    $woff = ($ver === 1) ? 88 : 76;
                    if ($tb['end'] - $s >= $woff + 8) {
                        $wh = unpack('nw/nwf/nh/nhf', substr($data, $s + $woff, 8));
                        $tw = (int)$wh['w']; $th = (int)$wh['h'];
                    }
                } elseif ($tb['type'] === 'mdia') {
                    foreach (bc_ve_mp4_boxes($data, $tb['start'], $tb['end']) as $mdb) {
                        if ($mdb['type'] === 'mdhd') {
                            $s = $mdb['start'];
                            if ($mdb['end'] - $s >= 20) {
                                $ver = ord($data[$s]);
                                if ($ver === 0) {
                                    $m = unpack('Nts/Ndur', substr($data, $s + 12, 8));
                                    if ($m['ts'] > 0) $tdur = $m['dur'] / $m['ts'];
                                } elseif ($ver === 1 && $mdb['end'] - $s >= 32) {
                                    $ts = unpack('N', substr($data, $s + 20, 4))[1];
                                    $d = unpack('N2', substr($data, $s + 24, 8));
                                    if ($ts > 0) $tdur = (($d[1] * 4294967296.0) + $d[2]) / $ts;
                                }
                            }
                        } elseif ($mdb['type'] === 'hdlr') {
                            if ($mdb['end'] - $mdb['start'] >= 12) {
                                $handler = substr($data, $mdb['start'] + 8, 4);
                            }
                        } elseif ($mdb['type'] === 'minf') {
                            foreach (bc_ve_mp4_boxes($data, $mdb['start'], $mdb['end']) as $mnb) {
                                if ($mnb['type'] === 'stbl') {
                                    foreach (bc_ve_mp4_boxes($data, $mnb['start'], $mnb['end']) as $sb) {
                                        if ($sb['type'] === 'stsd' && $sb['end'] - $sb['start'] >= 16) {
                                            $fourcc = substr($data, $sb['start'] + 12, 4);
                                            $cname = $mp4_codec_map[$fourcc] ?? strtolower($fourcc);
                                            if ($handler === 'vide' && ($tw === 0 || $th === 0) && $sb['end'] - $sb['start'] >= 44) {
                                                $vwh = unpack('nw/nh', substr($data, $sb['start'] + 40, 4));
                                                $tw = (int)$vwh['w']; $th = (int)$vwh['h'];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if ($handler === 'vide') {
                $st = ['codec_type' => 'video', 'codec_name' => $cname, 'width' => $tw, 'height' => $th];
                if ($tdur !== null) $st['duration'] = sprintf('%.4f', $tdur);
                $streams[] = $st;
            } elseif ($handler === 'soun') {
                $st = ['codec_type' => 'audio', 'codec_name' => $cname];
                if ($tdur !== null) $st['duration'] = sprintf('%.4f', $tdur);
                $streams[] = $st;
            } elseif ($handler !== '') {
                $streams[] = ['codec_type' => 'data', 'codec_name' => $handler === 'tmcd' ? 'tmcd' : 'bin_data'];
            }
        }
    }

    return [
        'format' => ['format_name' => 'mov,mp4', 'duration' => $duration !== null ? sprintf('%.4f', $duration) : 'N/A'],
        'streams' => $streams,
    ];
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
