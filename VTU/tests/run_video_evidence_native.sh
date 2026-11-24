#!/usr/bin/env bash
set -euo pipefail
fixtures=$(mktemp -d)
trap 'rm -rf "$fixtures"' EXIT
# Generated color/tone fixtures contain no real biometric data or provider calls.
ffmpeg -v error -f lavfi -i color=c=blue:s=320x240:r=15 -f lavfi -i sine=frequency=440:sample_rate=48000 -t 2 -c:v libx264 -pix_fmt yuv420p -c:a aac "$fixtures/valid.mp4"
ffmpeg -v error -i "$fixtures/valid.mp4" -c:v libvpx -c:a libopus "$fixtures/valid.webm"
ffmpeg -v error -i "$fixtures/valid.mp4" -c:v libvpx -c:a libopus -live 1 "$fixtures/live.webm"
ffmpeg -v error -stream_loop 5 -i "$fixtures/valid.mp4" -t 12 -c copy "$fixtures/long.mp4"
ffmpeg -v error -i "$fixtures/valid.mp4" -an -c:v copy "$fixtures/silent.mp4"
ffmpeg -v error -i "$fixtures/valid.mp4" -vn -c:a copy "$fixtures/audio.mp4"
ffmpeg -v error -i "$fixtures/valid.mp4" -t 0.3 -c copy "$fixtures/tiny.mp4"
printf 'not a video' > "$fixtures/junk.mp4"
head -c 3145729 /dev/zero > "$fixtures/oversize.mp4"
BC_VIDEO_EVIDENCE_CI=1 php VTU/tests/video_evidence_native_test.php normal "$fixtures"
BC_VIDEO_EVIDENCE_CI=1 php VTU/tests/video_evidence_native_test.php missing "$fixtures"
