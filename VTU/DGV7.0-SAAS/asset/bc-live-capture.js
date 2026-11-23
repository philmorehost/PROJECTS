'use strict';
// Camera-only UX, not proof of biometric liveness. No file-picker fallback.
document.querySelectorAll('[data-live-capture]').forEach(box => {
    const moving = box.dataset.liveCapture === 'video';
    const input = box.querySelector('input'), preview = box.querySelector('video'), image = box.querySelector('img');
    const start = box.querySelector('[data-camera-start]'), shot = box.querySelector('[data-camera-shot]');
    const reset = box.querySelector('[data-camera-reset]'), status = box.querySelector('[role=status]');
    let stream, recorder, timer, url, generation = 0, busy = false;
    const stop = () => {
        clearTimeout(timer);
        if (recorder && recorder.state !== 'inactive') recorder.stop();
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null; preview.srcObject = null;
    };
    const clear = () => {
        generation++; stop(); input.value = ''; busy = false;
        if (url) { URL.revokeObjectURL(url); url = null; }
        image.hidden = preview.hidden = true; preview.removeAttribute('src'); preview.controls = false;
        start.hidden = false; reset.hidden = true; if (shot) shot.hidden = true;
    };
    const attach = (blob, ext) => {
        const max = moving ? 3 * 1024 * 1024 : 512 * 1024;
        if (!blob || !blob.size || blob.size > max) throw new Error('Capture too large or empty. Please retake.');
        const dt = new DataTransfer(); dt.items.add(new File([blob], 'camera.' + ext, {type:blob.type})); input.files = dt.files;
        url = URL.createObjectURL(blob);
        if (moving) { preview.src = url; preview.controls = true; preview.muted = false; preview.autoplay = false; preview.hidden = false; }
        else { image.src = url; image.hidden = false; preview.hidden = true; }
        busy = false; reset.hidden = false;
        status.textContent = 'Captured (' + Math.ceil(blob.size / 1024) + ' KB). Review, retake if needed, then submit for review.';
    };
    const fail = error => { clear(); status.textContent = (error && error.message ? error.message + ' ' : '') + 'Use a supported camera-enabled browser over HTTPS. Gallery uploads are not accepted here.'; };
    start.addEventListener('click', async () => {
        clear(); const epoch = generation; busy = true; start.hidden = true;
        try {
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('Live camera is unavailable.');
            if (moving && !window.MediaRecorder) throw new Error('Live recording is unsupported.');
            const s = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640,max:960},height:{ideal:480,max:960},frameRate:{ideal:15,max:20}},audio:moving});
            if (epoch !== generation) { s.getTracks().forEach(t => t.stop()); return; }
            stream = s; preview.autoplay = true; preview.muted = true; preview.srcObject = s; preview.hidden = false; await preview.play();
            if (epoch !== generation) return;
            reset.hidden = false;
            if (!moving) { shot.hidden = false; status.textContent = 'Look at the camera and take your photo.'; return; }
            const mime = ['video/webm;codecs=vp8,opus','video/webm','video/mp4'].find(t => MediaRecorder.isTypeSupported(t));
            if (!mime) throw new Error('No supported recording format.');
            const chunks = []; let total = 0, oversized = false;
            recorder = new MediaRecorder(s,{mimeType:mime,videoBitsPerSecond:500000,audioBitsPerSecond:32000});
            recorder.ondataavailable = e => { total += e.data.size; if (total > 3 * 1024 * 1024) { oversized = true; stop(); } else chunks.push(e.data); };
            recorder.onerror = () => { if (epoch === generation) fail(new Error('Recording failed.')); };
            recorder.onstop = () => {
                if (epoch !== generation) return;
                stop();
                try { if (oversized) throw new Error('Video too large.'); attach(new Blob(chunks,{type:mime.split(';')[0]}),mime.startsWith('video/mp4')?'mp4':'webm'); }
                catch(e) { fail(e); }
            };
            recorder.start(250); status.textContent = 'Recording 8 seconds: say your name and today’s date, then turn your head slowly.';
            timer = setTimeout(stop,8000);
        } catch(e) { if (epoch === generation) fail(e); }
    });
    if (shot) shot.addEventListener('click', () => {
        const epoch = generation, w = preview.videoWidth, h = preview.videoHeight;
        if (!w || !h) { status.textContent = 'Camera is still starting. Try again.'; return; }
        const scale = Math.min(1,960 / Math.max(w,h)), canvas = document.createElement('canvas');
        canvas.width = Math.round(w*scale); canvas.height = Math.round(h*scale);
        canvas.getContext('2d').drawImage(preview,0,0,canvas.width,canvas.height); shot.hidden = true;
        canvas.toBlob(blob => { if (epoch !== generation) return; stop(); try { attach(blob,'jpg'); } catch(e) { fail(e); } },'image/jpeg',0.78);
    });
    reset.addEventListener('click',clear);
    box.closest('form')?.addEventListener('submit', e => { if (busy) { e.preventDefault(); status.textContent = 'Finish or cancel the camera capture before submitting.'; } });
    window.addEventListener('pagehide',clear);
    document.addEventListener('visibilitychange', () => { if (document.hidden && busy) { clear(); status.textContent = 'Capture cancelled when the page was hidden. Please retake.'; } });
});
