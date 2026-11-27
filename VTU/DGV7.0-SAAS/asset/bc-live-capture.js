'use strict';
// Camera-only UX, not proof of biometric liveness. No file-picker fallback.
document.querySelectorAll('[data-live-capture]').forEach(box => {
    const moving = box.dataset.liveCapture === 'video';
    const input = box.querySelector('input'), preview = box.querySelector('video'), image = box.querySelector('img');
    const stage = box.querySelector('[data-camera-stage]');
    const overlay = box.querySelector('[data-face-overlay]');
    const oval = box.querySelector('[data-face-oval]');
    const pill = box.querySelector('[data-prompt-pill]');
    const micBar = box.querySelector('[data-mic-bar]');
    const start = box.querySelector('[data-camera-start]'), shot = box.querySelector('[data-camera-shot]');
    const reset = box.querySelector('[data-camera-reset]'), status = box.querySelector('[role=status]');
    let stream, recorder, timer, countdownTimer, detectTimer, micRaf, audioCtx, url, generation = 0, busy = false;
    let goodFrames = 0, spokeDetected = false, peakMic = 0;

    const hasPixelInspect = () => {
        try {
            const c = document.createElement('canvas');
            const ctx = c.getContext && c.getContext('2d');
            return !!(ctx && typeof ctx.getImageData === 'function');
        } catch (e) { return false; }
    };

    const stopMonitors = () => {
        clearInterval(detectTimer); detectTimer = null;
        clearInterval(countdownTimer); countdownTimer = null;
        if (micRaf) { cancelAnimationFrame(micRaf); micRaf = null; }
        if (audioCtx) { try { audioCtx.close(); } catch (e) {} audioCtx = null; }
        if (overlay) overlay.hidden = true;
        if (oval) oval.style.borderColor = 'rgba(255,255,255,0.85)';
        if (micBar) micBar.style.width = '8%';
    };

    const stopTracks = () => {
        stopMonitors();
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null; preview.srcObject = null;
    };

    const stop = () => {
        clearTimeout(timer);
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        } else {
            stopTracks();
        }
    };

    const clear = () => {
        generation++; clearTimeout(timer);
        if (recorder && recorder.state !== 'inactive') {
            try { recorder.stop(); } catch (e) {}
        }
        stopTracks(); input.value = ''; busy = false; goodFrames = 0; spokeDetected = false; peakMic = 0;
        if (url) { URL.revokeObjectURL(url); url = null; }
        if (stage) stage.hidden = true;
        image.hidden = preview.hidden = true; preview.removeAttribute('src'); preview.controls = false;
        start.hidden = false; reset.hidden = true; if (shot) shot.hidden = true;
    };

    const attach = (blob, ext) => {
        const max = moving ? 3 * 1024 * 1024 : 512 * 1024;
        if (!blob || !blob.size || blob.size > max) throw new Error(moving ? 'Video too large or empty.' : 'Photo too large or empty.');
        const dt = new DataTransfer(); dt.items.add(new File([blob], 'camera.' + ext, {type:blob.type})); input.files = dt.files;
        url = URL.createObjectURL(blob);
        if (moving) {
            if (stage) stage.hidden = false;
            preview.src = url; preview.controls = true; preview.muted = false; preview.autoplay = false; preview.hidden = false;
        } else {
            if (stage) stage.hidden = true;
            image.src = url; image.hidden = false; preview.hidden = true;
        }
        busy = false; reset.hidden = false;
        status.textContent = 'Captured (' + Math.ceil(blob.size / 1024) + ' KB).' + (moving && spokeDetected ? ' Audio voice detected.' : '') + ' Review, retake if needed, then submit for review.';
        box.dispatchEvent(new CustomEvent('bc:capture-complete', {
            bubbles: true,
            detail: { kind: moving ? 'video' : 'photo', name: input.name || '', file: input.files[0] || null }
        }));
    };

    const fail = error => {
        clear();
        status.textContent = (error && error.message ? error.message + ' ' : '') + 'Use a supported camera-enabled browser over HTTPS. Gallery uploads are not accepted here.';
    };

    // Real-browser frame quality & face-in-oval checker (gracefully no-ops in headless JSDOM mocks)
    const inspectFrameQuality = async (videoEl) => {
        if (!hasPixelInspect() || !videoEl.videoWidth || !videoEl.videoHeight) return { ok: true, reason: '' };
        try {
            if (typeof window.FaceDetector === 'function') {
                const detector = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 2 });
                const faces = await detector.detect(videoEl);
                if (!faces || faces.length === 0) return { ok: false, reason: 'No face detected — position your face inside the oval.' };
                if (faces.length > 1) return { ok: false, reason: 'Multiple faces detected — only one person should be in frame.' };
                const bb = faces[0].boundingBox;
                const cx = (bb.x + bb.width / 2) / videoEl.videoWidth;
                const cy = (bb.y + bb.height / 2) / videoEl.videoHeight;
                const sizeRatio = bb.width / videoEl.videoWidth;
                if (sizeRatio < 0.16) return { ok: false, reason: 'Move closer so your face fills the oval.' };
                if (cx < 0.22 || cx > 0.78 || cy < 0.18 || cy > 0.82) return { ok: false, reason: 'Center your face inside the oval.' };
            }

            const w = 64, h = 48;
            const c = document.createElement('canvas'); c.width = w; c.height = h;
            const ctx = c.getContext('2d');
            ctx.drawImage(videoEl, 0, 0, w, h);
            const d = ctx.getImageData(0, 0, w, h).data;

            let sum = 0, cnt = 0, edgeSum = 0, edgeCnt = 0;
            let bgSum = 0, bgCnt = 0, skinCnt = 0;
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    const idx = (y * w + x) * 4;
                    const r = d[idx], g = d[idx + 1], b = d[idx + 2];
                    const luma = 0.299 * r + 0.587 * g + 0.114 * b;
                    const nx = (x / w - 0.5) / 0.27;
                    const ny = (y / h - 0.48) / 0.36;
                    if (nx * nx + ny * ny <= 1.0) {
                        sum += luma; cnt++;
                        // Broad skin/face-tone + mid-tone foreground indicator
                        if (r > 45 && g > 30 && b > 20 && r >= b && Math.abs(r - g) < 95) skinCnt++;
                        if (x + 1 < w) {
                            const idx2 = (y * w + (x + 1)) * 4;
                            const l2 = 0.299 * d[idx2] + 0.587 * d[idx2 + 1] + 0.114 * d[idx2 + 2];
                            edgeSum += Math.abs(luma - l2); edgeCnt++;
                        }
                    } else {
                        bgSum += luma; bgCnt++;
                    }
                }
            }
            const mean = cnt ? sum / cnt : 0;
            const edge = edgeCnt ? edgeSum / edgeCnt : 0;
            const bgMean = bgCnt ? bgSum / bgCnt : mean;
            const skinRatio = cnt ? skinCnt / cnt : 0;

            if (mean < 42) return { ok: false, reason: 'Too dark — move to a brighter area and try again.' };
            if (mean > 236) return { ok: false, reason: 'Too bright / washed out — avoid direct glare behind or on you.' };
            if (edge < 6.0) return { ok: false, reason: 'Image too blurry or camera covered — hold steady and try again.' };
            if (typeof window.FaceDetector !== 'function' && skinRatio < 0.14 && Math.abs(mean - bgMean) < 6) {
                return { ok: false, reason: 'Face not clearly framed — look directly into the center oval.' };
            }
            return { ok: true, reason: '' };
        } catch (e) {
            return { ok: true, reason: '' };
        }
    };

    const capturePhoto = (autoTriggered) => {
        const epoch = generation, w = preview.videoWidth, h = preview.videoHeight;
        if (!w || !h) { status.textContent = 'Camera is still starting. Try again.'; return; }

        const finishCapture = () => {
            const renderAndEncode = (maxDim, qualities, idx) => {
                const scale = Math.min(1, maxDim / Math.max(w, h)), canvas = document.createElement('canvas');
                canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
                canvas.getContext('2d').drawImage(preview, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(blob => {
                    if (epoch !== generation) return;
                    if (blob && blob.size > 512 * 1024) {
                        if (idx + 1 < qualities.length) { renderAndEncode(maxDim, qualities, idx + 1); return; }
                        if (maxDim > 640) { renderAndEncode(640, [0.68, 0.52, 0.38], 0); return; }
                    }
                    stopTracks();
                    try { attach(blob, 'jpg'); } catch (e) { fail(e); }
                }, 'image/jpeg', qualities[idx]);
            };
            if (shot) shot.hidden = true;
            renderAndEncode(960, [0.78, 0.62, 0.48, 0.35], 0);
        };

        if (!autoTriggered && hasPixelInspect()) {
            inspectFrameQuality(preview).then(res => {
                if (epoch !== generation) return;
                if (!res.ok) {
                    goodFrames = 0;
                    if (oval) oval.style.borderColor = '#f59e0b';
                    if (pill) pill.textContent = res.reason;
                    status.textContent = 'Face was not well captured: ' + res.reason + ' Please try again.';
                    return;
                }
                finishCapture();
            });
            return;
        }
        finishCapture();
    };

    const startMicMeter = (s) => {
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC || !s.getAudioTracks || !s.getAudioTracks().length) return;
            audioCtx = new AC();
            const src = audioCtx.createMediaStreamSource(s);
            const analyser = audioCtx.createAnalyser();
            analyser.fftSize = 256;
            src.connect(analyser);
            const buf = new Uint8Array(analyser.frequencyBinCount);
            const tickMic = () => {
                if (!audioCtx) return;
                analyser.getByteTimeDomainData(buf);
                let maxDev = 0;
                for (let i = 0; i < buf.length; i++) {
                    const dev = Math.abs(buf[i] - 128);
                    if (dev > maxDev) maxDev = dev;
                }
                if (maxDev > peakMic) peakMic = maxDev;
                if (maxDev >= 6) spokeDetected = true;
                if (micBar) {
                    const pct = Math.min(100, Math.max(8, Math.round((maxDev / 48) * 100)));
                    micBar.style.width = pct + '%';
                    micBar.style.background = spokeDetected ? '#10b981' : '#f59e0b';
                }
                micRaf = requestAnimationFrame(tickMic);
            };
            micRaf = requestAnimationFrame(tickMic);
        } catch (e) {}
    };

    const openStream = async () => {
        const attempts = [
            moving
                ? {video:{facingMode:{ideal:'user'},width:{ideal:480,max:960},height:{ideal:360,max:960},frameRate:{ideal:15,max:20}},audio:true}
                : {video:{facingMode:{ideal:'user'},width:{ideal:640,max:960},height:{ideal:480,max:960},frameRate:{ideal:15,max:20}},audio:false},
            {video:{facingMode:{ideal:'user'}},audio:moving},
            {video:true,audio:moving}
        ];
        let lastErr;
        for (let i = 0; i < attempts.length; i++) {
            try {
                return await navigator.mediaDevices.getUserMedia(attempts[i]);
            } catch (err) {
                lastErr = err;
                const msg = (err && err.message) ? String(err.message) : '';
                if ((err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) || /permission|denied/i.test(msg)) {
                    throw err;
                }
            }
        }
        throw lastErr;
    };

    start.addEventListener('click', async () => {
        clear(); const epoch = generation; busy = true; start.hidden = true;
        try {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('Live camera is unavailable.');
            if (moving && !window.MediaRecorder) throw new Error('Live recording is unsupported.');
            const s = await openStream();
            if (epoch !== generation) { s.getTracks().forEach(t => t.stop()); return; }
            stream = s;
            if (stage) stage.hidden = false;
            if (overlay) overlay.hidden = false;
            preview.autoplay = true; preview.muted = true; preview.playsInline = true; preview.setAttribute('playsinline', '');
            preview.srcObject = s; preview.hidden = false; await preview.play();
            if (epoch !== generation) return;
            reset.hidden = false;

            if (!moving) {
                if (shot) shot.hidden = false;
                status.textContent = 'Center your face inside the oval — auto-capturing once clear...';
                if (hasPixelInspect()) {
                    let checking = false;
                    detectTimer = setInterval(async () => {
                        if (epoch !== generation || !busy || checking) return;
                        checking = true;
                        const res = await inspectFrameQuality(preview);
                        checking = false;
                        if (epoch !== generation || !busy) return;
                        if (res.ok) {
                            goodFrames++;
                            if (oval) oval.style.borderColor = '#10b981';
                            if (pill) pill.textContent = goodFrames >= 2 ? 'Great! Hold steady — capturing...' : 'Face aligned — hold still...';
                            if (goodFrames >= 3) {
                                stopMonitors();
                                capturePhoto(true);
                            }
                        } else {
                            goodFrames = 0;
                            if (oval) oval.style.borderColor = '#f59e0b';
                            if (pill) pill.textContent = res.reason;
                            status.textContent = res.reason;
                        }
                    }, 260);
                }
                return;
            }

            // Guided "Say the word HI" live video + audio recorder
            startMicMeter(s);
            const candidates = [
                'video/webm;codecs=vp8,opus',
                'video/webm;codecs=vp9,opus',
                'video/webm',
                'video/mp4;codecs=avc1,mp4a.40.2',
                'video/mp4'
            ];
            const mime = candidates.find(t => MediaRecorder.isTypeSupported(t));
            if (!mime) throw new Error('No supported recording format.');
            const chunks = []; let total = 0, oversized = false;
            try {
                recorder = new MediaRecorder(s, { mimeType: mime, videoBitsPerSecond: 500000, audioBitsPerSecond: 32000 });
            } catch (errOpts) {
                recorder = new MediaRecorder(s);
            }
            recorder.ondataavailable = e => {
                if (!e.data || !e.data.size) return;
                total += e.data.size;
                if (total > 3 * 1024 * 1024) { oversized = true; stop(); }
                else chunks.push(e.data);
            };
            recorder.onerror = () => { if (epoch === generation) fail(new Error('Recording failed.')); };
            recorder.onstop = () => {
                if (epoch !== generation) { stopTracks(); return; }
                clearTimeout(timer);
                stopTracks();
                try {
                    if (oversized) throw new Error('Video too large.');
                    const resolvedType = (recorder && recorder.mimeType ? recorder.mimeType : mime).split(';')[0] || 'video/webm';
                    attach(new Blob(chunks, { type: resolvedType }), resolvedType.includes('mp4') ? 'mp4' : 'webm');
                } catch (e) { fail(e); }
            };
            recorder.start(250);
            let secsLeft = 4;
            const updateRecLabel = () => {
                const msg = 'Recording (' + secsLeft + 's): Look at the camera & say the word "HI"!';
                if (pill) pill.textContent = msg;
                if (oval) oval.style.borderColor = spokeDetected ? '#10b981' : '#38bdf8';
                status.textContent = msg;
            };
            updateRecLabel();
            countdownTimer = setInterval(() => {
                secsLeft = Math.max(0, secsLeft - 1);
                if (secsLeft > 0) updateRecLabel();
            }, 1000);
            timer = setTimeout(stop, 4200);
        } catch (e) { if (epoch === generation) fail(e); }
    });

    if (shot) shot.addEventListener('click', () => capturePhoto(false));
    reset.addEventListener('click', clear);
    box.closest('form')?.addEventListener('submit', e => {
        if (busy) { e.preventDefault(); status.textContent = 'Finish or cancel the camera capture before submitting.'; return; }
        const form = box.closest('form');
        const requiredInputs = form ? Array.from(form.querySelectorAll('input[type="file"][data-kyc-required="1"]')) : [];
        if (requiredInputs.length) {
            const missing = requiredInputs.filter(inp => !inp.files || !inp.files.length);
            if (missing.length) {
                e.preventDefault();
                if (missing.includes(input)) {
                    status.textContent = moving ? 'Record your live video before submitting. All activated checks are compulsory.' : 'Take your live photo before submitting. All activated checks are compulsory.';
                } else {
                    status.textContent = 'Please complete all required checks (documents, live selfie, and live video where activated) before submitting.';
                }
                return;
            }
        }
        const inputs = form ? Array.from(form.querySelectorAll('input[type="file"]')) : [];
        if (inputs.length && inputs.every(inp => !inp.files || !inp.files.length)) {
            e.preventDefault();
            status.textContent = moving ? 'Record your live video (or select a document above) before submitting.' : 'Take a live photo (or select a document) before submitting.';
        }
    });
    window.addEventListener('pagehide', clear);
    document.addEventListener('visibilitychange', () => { if (document.hidden && busy) { clear(); status.textContent = 'Capture cancelled when the page was hidden. Please retake.'; } });
});
