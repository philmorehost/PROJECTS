# Android live KYC video increment

DG7, JIKABIZ and MZEEVTU Android KYC now offer **Record Live Video / Retake** in addition to the live selfie. This launches ACTION_VIDEO_CAPTURE with an app-owned, narrowly shared FileProvider output, requested low quality, eight-second limit and3MiB limit. It never offers a gallery picker for liveness video and never substitutes an arbitrary URI returned by the camera application.

Because camera applications may ignore intent hints, success alone is insufficient. The file is inspected with MediaMetadataRetriever: positive size ≤3MiB, MP4, both video and audio tracks, duration1–9seconds (one-second container/timer tolerance). Validation is repeated before multipart upload. Missing/cancelled/oversize/invalid outputs are refused and removed from the local pending file; no automatic retry or server evidence deletion occurs. The API's existing3MiB MIME gate still applies. This is bounded capture and rejection, **not a general transcoder**; devices that cannot create a small valid recording must retry at lower quality or use the existing secure website camera.

Camera/microphone permission denial is explicit. Output path and accepted state survive Activity recreation; restored files are validated before use. In-progress results are not treated as accepted captures. Existing app-session authentication and server KYC review remain unchanged. Evidence is submitted using `liveliness_video`, the field already consumed by both editions' KYC endpoint. Government-ID gallery selection is deliberately retained; only live face evidence excludes a picker.

## Evidence and remaining work

`native_kyc_video_test.py` checks all three source/resource/permission/upload contracts. Native Android CI compilation is separate evidence. Neither source assertions nor compilation prove camera behavior, capture origin, genuine biometric liveness or resistance to virtual cameras/replay. The existing server multipart API still cannot attest capture origin and does not inspect server-side duration; that work remains pending.

Before release, test on physical Android devices: grant/deny/revoke permissions; record/retake/cancel; camera app unavailable; cameras honoring/ignoring limits; silent/no-audio files; portrait/landscape; rotation/process recreation; low storage; oversized output; backgrounding; expired app session; slow network and server rejection. Verify that the camera app can write the supplied output URI and actually records speech. There is no screenshot-only/device acceptance claim.

Native iOS media capture parity, approved liveness-provider integration, provider-backed financial execution/reconciliation, production signing configuration and coordinated backend/app rollout remain outstanding. No live provider operation, deployment or financial activation is introduced. Existing holds and historical evidence are retained.
