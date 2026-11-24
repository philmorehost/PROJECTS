# Server-side KYC video inspection (both editions)

Customer web/API and vendor Account Settings now inspect liveness video on the server before saving ANY of that submission's evidence. Invalid video returns an explicit error rather than silently skipping it while saving another attachment. No previous evidence is deleted. No financial state is touched.

## Requirements and contract

Install and maintain a security-patched FFmpeg/ffprobe package; default absolute executable `/usr/bin/ffprobe`. Operators may define `BC_FFPROBE_PATH` to another trusted absolute executable in server bootstrap/configuration, never through request data. PHP must permit `proc_open`, `proc_get_status`, `proc_terminate`, `proc_close` and nonblocking streams; fileinfo is also required. Missing/unusable dependencies fail CLOSED for new video submissions. Deploy the dependency before deploying this validation change. Ordinary photo/ID/address-only submissions do not require ffprobe. Do not silently weaken checks on shared hosting without this capability.

Limits: positive size ≤3MiB, MP4 or WebM MIME, expected container parser, one supported video stream and one supported audio stream, no additional/cover-art tracks, video dimensions160–1920 per side, duration1–9seconds. The upper tolerance accommodates eight-second camera timers and container overhead. Supported codecs: H264/HEVC/VP8/VP9/AV1 video and AAC/Opus/Vorbis/MP3 audio. Browser MediaRecorder WebM often omits duration; bounded packet timestamps provide the fallback instead of rejecting every such recording. This does not prove recorded speech is intelligible or that the camera subject is genuine.

Inspector execution uses an argv array, no shell interpolation, a fixed operator-owned executable, only local file protocol, forced expected demuxer, bounded probe/allocation settings, five-second deadline per probe (at most two probes for missing-duration WebM), ≤256KiB JSON and ≤8KiB error output. Timeout, parser error, oversized output, malformed metadata, absent tracks and unavailable inspector all reject. Error output/media paths are not returned to users. No network/transcode/provider operation is added. Full decoded-frame validation, OS/container CPU-memory sandboxing and anti-replay/liveness attestation remain separate hardening; patched ffprobe and host resource controls are operational prerequisites. This is metadata/container inspection, not a claim of full media sanitization.

## Evidence

Unit contracts cover numeric/duration/dimension/stream/codec/container rules and WebM timestamp fallback. Native CI generates synthetic color/tone fixtures (not personal media) for MP4, WebM, live WebM without declared duration, long/silent/audio-only/short/junk/oversize files, wrong parser and missing binary. Native client and physical-device capture acceptance remain separate. Existing manual compliance review and financial holds remain. No deployment/signing/provider activation is performed.
