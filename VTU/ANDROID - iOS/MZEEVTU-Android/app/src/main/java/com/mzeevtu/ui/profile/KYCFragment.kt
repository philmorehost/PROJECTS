package com.mzeevtu.ui.profile

import android.app.Activity
import android.app.AlertDialog
import android.app.Dialog
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.ImageFormat
import android.graphics.SurfaceTexture
import android.graphics.drawable.GradientDrawable
import android.hardware.camera2.CameraCaptureSession
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraDevice
import android.hardware.camera2.CameraManager
import android.hardware.camera2.CaptureRequest
import android.media.FaceDetector
import android.media.ImageReader
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.HandlerThread
import android.view.Gravity
import android.view.Surface
import android.view.TextureView
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.R
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.FragmentKycBinding
import com.mzeevtu.ui.MainActivity
import com.mzeevtu.ui.auth.LoginActivity
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.RequiredSetup
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.io.FileOutputStream

// VoveID SDK imports
import com.voveid.sdk.Vove
import com.voveid.sdk.VoveEnvironment
import com.voveid.sdk.model.VerificationResult

class KYCFragment : Fragment(R.layout.fragment_kyc) {

    private var _binding: FragmentKycBinding? = null
    private val binding get() = _binding!!

    private var govtIdUri: Uri? = null
    private var poaUri: Uri? = null
    private var selfieUri: Uri? = null
    private var voveidInitialized = false
    private var formsExpandedWhilePending = false
    private var currentKycStatus = -1
    private var kycPollJob: kotlinx.coroutines.Job? = null
    private val idTypes = listOf("National ID", "NIN", "Passport", "Driver's Licence", "Voter's Card", "BVN")

    private val pickGovtId = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            govtIdUri = result.data?.data
            binding.tvGovtIdName.text = govtIdUri?.lastPathSegment ?: "ID file selected"
        }
    }

    private val pickPoa = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            poaUri = result.data?.data
            binding.tvPoaName.text = poaUri?.lastPathSegment ?: "Proof of address selected"
        }
    }

    private var pendingSelfie: File? = null
    private val pickSelfie = registerForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        val file = pendingSelfie
        if (success && file != null && file.exists()) {
            try {
                val bounds = android.graphics.BitmapFactory.Options().apply { inJustDecodeBounds = true }
                android.graphics.BitmapFactory.decodeFile(file.path, bounds)
                val opts = android.graphics.BitmapFactory.Options()
                while (maxOf(bounds.outWidth, bounds.outHeight) / opts.inSampleSize > 1920) opts.inSampleSize *= 2
                var original = android.graphics.BitmapFactory.decodeFile(file.path, opts)
                    ?: throw IllegalStateException("Camera image could not be read")
                val orientation = android.media.ExifInterface(file.path).getAttributeInt(android.media.ExifInterface.TAG_ORIENTATION, android.media.ExifInterface.ORIENTATION_NORMAL)
                val matrix = android.graphics.Matrix()
                when (orientation) {
                    2 -> matrix.setScale(-1f, 1f)
                    3 -> matrix.setRotate(180f)
                    4 -> matrix.setScale(1f, -1f)
                    5 -> { matrix.setRotate(90f); matrix.postScale(-1f, 1f) }
                    6 -> matrix.setRotate(90f)
                    7 -> { matrix.setRotate(270f); matrix.postScale(-1f, 1f) }
                    8 -> matrix.setRotate(270f)
                }
                if (!matrix.isIdentity) {
                    val rotated = android.graphics.Bitmap.createBitmap(original, 0, 0, original.width, original.height, matrix, true)
                    if (rotated !== original) original.recycle()
                    original = rotated
                }
                val scale = minOf(1f, 960f / maxOf(original.width, original.height))
                val bitmap = android.graphics.Bitmap.createScaledBitmap(original, (original.width * scale).toInt(), (original.height * scale).toInt(), true)
                FileOutputStream(file).use { bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, 78, it) }
                if (bitmap !== original) bitmap.recycle()
                original.recycle()
                if (file.length() > 512 * 1024) throw IllegalStateException("Photo too large; please retake")
                selfieUri = Uri.fromFile(file)
                _binding?.tvSelfieName?.text = "Camera photo ready (${file.length() / 1024} KB)"
            } catch (e: Exception) {
                file.delete(); selfieUri = null
                if (_binding != null) snack(e.message ?: "Please retake your photo")
            }
        } else { file?.delete(); selfieUri = null }
    }
    private val selfieCameraPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) startSmartSelfieCapture() else if (_binding != null) snack("Camera permission is required. Gallery selfies are not accepted.")
    }
    private fun captureSelfie() {
        if (androidx.core.content.ContextCompat.checkSelfPermission(requireContext(), android.Manifest.permission.CAMERA) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            selfieCameraPermission.launch(android.Manifest.permission.CAMERA); return
        }
        try {
            pendingSelfie?.delete(); selfieUri = null
            val dir = File(requireContext().cacheDir, "kyc").apply { mkdirs() }
            val file = File.createTempFile("kyc_camera_", ".jpg", dir)
            pendingSelfie = file
            val uri = androidx.core.content.FileProvider.getUriForFile(requireContext(), "${requireContext().packageName}.fileprovider", file)
            pickSelfie.launch(uri)
        } catch (e: Exception) { if (_binding != null) snack("Live camera unavailable. Please use the secure website camera.") }
    }

    private fun startSmartSelfieCapture() {
        if (androidx.core.content.ContextCompat.checkSelfPermission(requireContext(), android.Manifest.permission.CAMERA) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            selfieCameraPermission.launch(android.Manifest.permission.CAMERA); return
        }
        val camMgr = requireContext().getSystemService(android.content.Context.CAMERA_SERVICE) as? CameraManager
        val frontId = camMgr?.cameraIdList?.firstOrNull { id ->
            camMgr.getCameraCharacteristics(id).get(CameraCharacteristics.LENS_FACING) == CameraCharacteristics.LENS_FACING_FRONT
        }
        if (camMgr == null || frontId == null) {
            captureSelfie()
            return
        }
        openAutoFaceSelfieDialog(camMgr, frontId)
    }

    @Suppress("MissingPermission")
    private fun openAutoFaceSelfieDialog(camMgr: CameraManager, cameraId: String) {
        val ctx = context ?: return
        val dialog = Dialog(ctx, android.R.style.Theme_Black_NoTitleBar_Fullscreen)
        val container = FrameLayout(ctx).apply { setBackgroundColor(Color.BLACK) }
        val textureView = TextureView(ctx)
        container.addView(textureView, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))

        val guideBox = View(ctx).apply {
            background = GradientDrawable().apply {
                shape = GradientDrawable.OVAL
                setColor(Color.TRANSPARENT)
                setStroke(6, Color.parseColor("#38BDF8"))
            }
        }
        val guideSize = (resources.displayMetrics.widthPixels * 0.65f).toInt()
        container.addView(guideBox, FrameLayout.LayoutParams(guideSize, (guideSize * 1.25f).toInt(), Gravity.CENTER))

        val statusTv = TextView(ctx).apply {
            text = "Position your face inside the oval — auto-capturing..."
            setTextColor(Color.WHITE)
            textSize = 15f
            gravity = Gravity.CENTER
            setBackgroundColor(Color.parseColor("#88000000"))
            setPadding(24, 24, 24, 24)
        }
        container.addView(statusTv, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.TOP))

        val btnRow = LinearLayout(ctx).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            setPadding(24, 24, 24, 48)
        }
        val manualBtn = Button(ctx).apply { text = "Capture Now" }
        val cancelBtn = Button(ctx).apply { text = "Cancel" }
        btnRow.addView(cancelBtn)
        btnRow.addView(manualBtn)
        container.addView(btnRow, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.BOTTOM))
        dialog.setContentView(container)

        var cameraDevice: CameraDevice? = null
        var captureSession: CameraCaptureSession? = null
        var reader: ImageReader? = null
        val bgThread = HandlerThread("AutoSelfieCam").apply { start() }
        val bgHandler = Handler(bgThread.looper)
        var captured = false
        var stableFaceFrames = 0
        var lastCaptureBitmap: Bitmap? = null

        fun cleanup() {
            runCatching { captureSession?.close() }
            runCatching { cameraDevice?.close() }
            runCatching { reader?.close() }
            runCatching { bgThread.quitSafely() }
        }

        fun saveSelfieBitmap(bmp: Bitmap) {
            if (captured) return
            captured = true
            try {
                pendingSelfie?.delete(); selfieUri = null
                val dir = File(requireContext().cacheDir, "kyc").apply { mkdirs() }
                val file = File.createTempFile("kyc_camera_", ".jpg", dir)
                val scale = minOf(1f, 960f / maxOf(bmp.width, bmp.height))
                val scaled = Bitmap.createScaledBitmap(bmp, maxOf(160, (bmp.width * scale).toInt()), maxOf(160, (bmp.height * scale).toInt()), true)
                FileOutputStream(file).use { scaled.compress(Bitmap.CompressFormat.JPEG, 78, it) }
                if (scaled !== bmp) scaled.recycle()
                pendingSelfie = file
                selfieUri = Uri.fromFile(file)
                activity?.runOnUiThread {
                    _binding?.tvSelfieName?.text = "Auto-captured selfie ready (${file.length() / 1024} KB)"
                    dialog.dismiss()
                }
            } catch (_: Exception) {
                activity?.runOnUiThread {
                    dialog.dismiss()
                    captureSelfie()
                }
            } finally {
                cleanup()
            }
        }

        cancelBtn.setOnClickListener { cleanup(); dialog.dismiss() }
        manualBtn.setOnClickListener {
            val snap = textureView.bitmap ?: lastCaptureBitmap
            if (snap != null) saveSelfieBitmap(snap) else { cleanup(); dialog.dismiss(); captureSelfie() }
        }
        dialog.setOnDismissListener { cleanup() }

        textureView.surfaceTextureListener = object : TextureView.SurfaceTextureListener {
            override fun onSurfaceTextureAvailable(st: SurfaceTexture, w: Int, h: Int) {
                try {
                    st.setDefaultBufferSize(640, 480)
                    val previewSurface = Surface(st)
                    val imgReader = ImageReader.newInstance(320, 240, ImageFormat.YUV_420_888, 2)
                    reader = imgReader
                    imgReader.setOnImageAvailableListener({ ir ->
                        val img = ir.acquireLatestImage() ?: return@setOnImageAvailableListener
                        try {
                            if (captured) return@setOnImageAvailableListener
                            val yPlane = img.planes[0]
                            val yBuf = yPlane.buffer
                            val rw = img.width
                            val rh = img.height
                            val rowStride = yPlane.rowStride
                            val rgb565 = Bitmap.createBitmap(rw, rh, Bitmap.Config.RGB_565)
                            val rowBytes = ByteArray(rowStride)
                            val pixels = IntArray(rw * rh)
                            for (row in 0 until rh) {
                                val len = minOf(rowStride, yBuf.remaining())
                                yBuf.get(rowBytes, 0, len)
                                for (col in 0 until rw) {
                                    val luma = rowBytes[col].toInt() and 0xFF
                                    pixels[row * rw + col] = Color.rgb(luma, luma, luma)
                                }
                            }
                            rgb565.setPixels(pixels, 0, rw, 0, 0, rw, rh)
                            val sensorOrientation = camMgr.getCameraCharacteristics(cameraId).get(CameraCharacteristics.SENSOR_ORIENTATION) ?: 270
                            val rot = android.graphics.Matrix().apply { postRotate(sensorOrientation.toFloat()) }
                            val upright = Bitmap.createBitmap(rgb565, 0, 0, rw, rh, rot, true)
                            rgb565.recycle()
                            val faces = arrayOfNulls<FaceDetector.Face>(1)
                            val found = FaceDetector(upright.width, upright.height, 1).findFaces(upright, faces)
                            val conf = faces[0]?.confidence() ?: 0f
                            upright.recycle()
                            if (found > 0 && conf >= 0.45f) {
                                stableFaceFrames++
                                activity?.runOnUiThread {
                                    statusTv.text = "Face aligned — hold still (${stableFaceFrames}/3)..."
                                }
                                if (stableFaceFrames >= 3) {
                                    activity?.runOnUiThread {
                                        val frame = textureView.bitmap
                                        if (frame != null) saveSelfieBitmap(frame)
                                    }
                                }
                            } else {
                                stableFaceFrames = 0
                                activity?.runOnUiThread {
                                    statusTv.text = "Center your face clearly inside the oval..."
                                }
                            }
                        } catch (_: Exception) {
                        } finally {
                            img.close()
                        }
                    }, bgHandler)

                    camMgr.openCamera(cameraId, object : CameraDevice.StateCallback() {
                        override fun onOpened(cam: CameraDevice) {
                            cameraDevice = cam
                            val surfaces = listOf(previewSurface, imgReader.surface)
                            val req = cam.createCaptureRequest(CameraDevice.TEMPLATE_PREVIEW).apply {
                                addTarget(previewSurface)
                                addTarget(imgReader.surface)
                                set(CaptureRequest.CONTROL_AF_MODE, CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE)
                            }
                            @Suppress("DEPRECATION")
                            cam.createCaptureSession(surfaces, object : CameraCaptureSession.StateCallback() {
                                override fun onConfigured(session: CameraCaptureSession) {
                                    captureSession = session
                                    session.setRepeatingRequest(req.build(), null, bgHandler)
                                }
                                override fun onConfigureFailed(session: CameraCaptureSession) {
                                    activity?.runOnUiThread { dialog.dismiss(); captureSelfie() }
                                }
                            }, bgHandler)
                        }
                        override fun onDisconnected(cam: CameraDevice) { cleanup(); dialog.dismiss() }
                        override fun onError(cam: CameraDevice, error: Int) {
                            cleanup()
                            activity?.runOnUiThread { dialog.dismiss(); captureSelfie() }
                        }
                    }, bgHandler)
                } catch (_: Exception) {
                    cleanup()
                    dialog.dismiss()
                    captureSelfie()
                }
            }
            override fun onSurfaceTextureSizeChanged(st: SurfaceTexture, w: Int, h: Int) {}
            override fun onSurfaceTextureDestroyed(st: SurfaceTexture): Boolean = true
            override fun onSurfaceTextureUpdated(st: SurfaceTexture) {}
        }
        dialog.show()
    }

    private var pendingVideo: File? = null
    private var videoReady = false
    private fun videoIsValid(file: File): Boolean {
        if (!file.isFile || file.length() !in 1..(3L * 1024 * 1024)) return false
        val metadata = android.media.MediaMetadataRetriever()
        return try {
            metadata.setDataSource(file.path)
            val duration = metadata.extractMetadata(android.media.MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull() ?: 0L
            val mime = metadata.extractMetadata(android.media.MediaMetadataRetriever.METADATA_KEY_MIMETYPE)
            val hasVideo = metadata.extractMetadata(android.media.MediaMetadataRetriever.METADATA_KEY_HAS_VIDEO) == "yes"
            val hasAudio = metadata.extractMetadata(android.media.MediaMetadataRetriever.METADATA_KEY_HAS_AUDIO) == "yes"
            hasVideo && hasAudio && mime == "video/mp4" && duration in 1000L..9000L
        } catch (_: Exception) { false } finally { metadata.release() }
    }
    private val recordVideo = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val file = pendingVideo
        videoReady = result.resultCode == Activity.RESULT_OK && file != null && videoIsValid(file)
        if (videoReady) {
            _binding?.tvVideoName?.text = "Live recording ready (${file!!.length() / 1024} KB)"
        } else {
            file?.delete()
            _binding?.tvVideoName?.text = "No recording saved"
            if (_binding != null) snack("Recording cancelled or invalid. Record 1–8 seconds at low quality, maximum 3 MB.")
        }
    }
    private val videoPermissions = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants ->
        if (grants[android.Manifest.permission.CAMERA] == true && grants[android.Manifest.permission.RECORD_AUDIO] == true) captureVideo()
        else if (_binding != null) snack("Camera and microphone permissions are required for live video.")
    }
    private fun captureVideo() {
        val permissions = arrayOf(android.Manifest.permission.CAMERA, android.Manifest.permission.RECORD_AUDIO)
        if (permissions.any { androidx.core.content.ContextCompat.checkSelfPermission(requireContext(), it) != android.content.pm.PackageManager.PERMISSION_GRANTED }) {
            videoPermissions.launch(permissions); return
        }
        try {
            pendingVideo?.delete(); videoReady = false
            _binding?.tvVideoName?.text = "No recording saved"
            val dir = File(requireContext().cacheDir, "kyc").apply { mkdirs() }
            val file = File.createTempFile("kyc_video_", ".mp4", dir)
            pendingVideo = file
            val uri = androidx.core.content.FileProvider.getUriForFile(requireContext(), "${requireContext().packageName}.fileprovider", file)
            val intent = Intent(android.provider.MediaStore.ACTION_VIDEO_CAPTURE).apply {
                putExtra(android.provider.MediaStore.EXTRA_OUTPUT, uri)
                putExtra(android.provider.MediaStore.EXTRA_DURATION_LIMIT, 8)
                putExtra(android.provider.MediaStore.EXTRA_SIZE_LIMIT, 3L * 1024 * 1024)
                putExtra(android.provider.MediaStore.EXTRA_VIDEO_QUALITY, 0)
                clipData = android.content.ClipData.newRawUri("KYC camera output", uri)
                addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }
            recordVideo.launch(intent)
        } catch (_: Exception) {
            pendingVideo?.delete(); videoReady = false
            if (_binding != null) snack("Live recording unavailable. Use the secure website camera instead.")
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        savedInstanceState?.getString("kyc_video_file")?.let { name ->
            if (name.matches(Regex("kyc_video_[A-Za-z0-9_-]+\\.mp4"))) {
                pendingVideo = File(File(requireContext().cacheDir, "kyc"), name)
                videoReady = savedInstanceState.getBoolean("kyc_video_ready") && pendingVideo?.let { videoIsValid(it) } == true
            }
        }
        savedInstanceState?.getString("kyc_camera_file")?.let { name ->
            if (name.matches(Regex("kyc_camera_[A-Za-z0-9_-]+\\.jpg"))) {
                pendingSelfie = File(File(requireContext().cacheDir, "kyc"), name)
            }
        }
    }
    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        pendingVideo?.let { outState.putString("kyc_video_file", it.name) }
        outState.putBoolean("kyc_video_ready", videoReady)
        pendingSelfie?.let { outState.putString("kyc_camera_file", it.name) }
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentKycBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        binding.btnRefreshKycStatus.setOnClickListener { loadKYCStatus() }
        binding.btnPendingRefresh.setOnClickListener { loadKYCStatus() }
        binding.btnKycSignOut.setOnClickListener {
            PreferenceManager(requireContext()).signOutPreservingBiometric()
            startActivity(Intent(requireContext(), LoginActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
            })
            requireActivity().finish()
        }
        binding.btnPendingToggleEdit.setOnClickListener {
            formsExpandedWhilePending = !formsExpandedWhilePending
            binding.llKycFormsContainer.visibility = if (formsExpandedWhilePending) View.VISIBLE else View.GONE
            binding.btnPendingToggleEdit.text = if (formsExpandedWhilePending) "Hide Forms" else "Update Files"
        }
        binding.btnKycNotifications.setOnClickListener { openNotificationsDialog() }

        binding.spinnerIdType.adapter = ArrayAdapter(requireContext(), android.R.layout.simple_spinner_dropdown_item, idTypes)

        loadKYCStatus()

        binding.btnSaveBvnNin.setOnClickListener { saveBvnNin() }

        binding.btnPickGovtId.setOnClickListener {
            val intent = Intent(Intent.ACTION_GET_CONTENT).apply {
                type = "*/*"
                putExtra(Intent.EXTRA_MIME_TYPES, arrayOf("image/*", "application/pdf"))
            }
            pickGovtId.launch(intent)
        }
        binding.btnPickPoa.setOnClickListener {
            val intent = Intent(Intent.ACTION_GET_CONTENT).apply {
                type = "*/*"
                putExtra(Intent.EXTRA_MIME_TYPES, arrayOf("image/*", "application/pdf"))
            }
            pickPoa.launch(intent)
        }
        binding.btnPickSelfie.text = "Auto Face Selfie"
        binding.btnPickSelfie.setOnClickListener { startSmartSelfieCapture() }
        binding.btnRecordVideo.setOnClickListener { captureVideo() }
        if (videoReady) binding.tvVideoName.text = "Live recording ready"
        binding.btnUploadDocuments.setOnClickListener { uploadDocuments() }

        // VoveID Verification button
        binding.btnStartVoveID?.setOnClickListener { startVoveIDVerification() }
        initVoveID()
    }

    private fun openNotificationsDialog() {
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val resp = RetrofitClient.getService().kycAction(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "notifications", "mark_read" to "1")
                )
                val body = resp.body()
                @Suppress("UNCHECKED_CAST")
                val items = (body?.get("items") as? List<Map<String, Any>>) ?: emptyList()
                val lines = if (items.isEmpty()) {
                    arrayOf("No compliance alerts yet.")
                } else {
                    items.map { item ->
                        "${item["title"] ?: ""}\n${item["message"] ?: ""}\n(${item["created_at"] ?: ""})"
                    }.toTypedArray()
                }
                if (context != null) {
                    AlertDialog.Builder(requireContext())
                        .setTitle("Compliance & Account Alerts")
                        .setItems(lines, null)
                        .setPositiveButton("Close", null)
                        .show()
                }
            } catch (_: Exception) {
                snack("Unable to load notifications right now.")
            }
        }
    }

    override fun onResume() {
        super.onResume()
        loadKYCStatus(silent = true)
        startPollingIfPending()
    }

    override fun onPause() {
        super.onPause()
        kycPollJob?.cancel()
        kycPollJob = null
    }

    private fun startPollingIfPending() {
        kycPollJob?.cancel()
        kycPollJob = viewLifecycleOwner.lifecycleScope.launch {
            while (currentKycStatus != 2) {
                kotlinx.coroutines.delay(6000)
                if (_binding != null && isResumed) {
                    loadKYCStatus(silent = true)
                }
            }
        }
    }

    private fun loadKYCStatus(silent: Boolean = false) {
        if (!silent) binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val wasLocked = prefs.getBoolean("kyc_gate_locked", false)
                val api = RetrofitClient.getService()
                val resp = api.kycAction(mapOf("api_key" to prefs.getApiKey(), "action" to "status"))
                val body = resp.body()
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    if (body?.get("status") == "success") {
                        val kycStatus = (body["kyc_status"] as? Number)?.toInt() ?: 0
                        currentKycStatus = kycStatus
                        val statusName = body["kyc_name"] as? String ?: "Unverified"
                        val bvnSet = body["bvn_set"] as? String ?: "No"
                        val ninSet = body["nin_set"] as? String ?: "No"
                        val gateLocked = (body["kyc_gate_locked"] as? Boolean) ?: false
                        val rejectReason = (body["kyc_reject_reason"] as? String).orEmpty()
                        val stillNeeded = (body["still_needed"] as? List<*>)?.mapNotNull { it as? String } ?: emptyList()
                        val aiRating = (body["ai_rating"] as? Number)?.toInt()
                        val aiGrade = (body["ai_grade"] as? String).orEmpty()
                        val aiReason = (body["ai_reason"] as? String).orEmpty()

                        com.mzeevtu.util.OnboardingCoordinator.syncFlags(prefs, body)
                        prefs.saveBoolean("kyc_gate_locked", gateLocked)
                        if (wasLocked && !gateLocked) {
                            prefs.saveBoolean("kyc_just_unlocked", true)
                            kycPollJob?.cancel()
                        }
                        (activity as? MainActivity)?.refreshComplianceGate()

                        binding.tvKycStatus.text = "KYC Status: $statusName"
                        binding.tvBvnStatus.text = "BVN Saved: $bvnSet"
                        binding.tvNinStatus.text = "NIN Saved: $ninSet"

                        binding.llComplianceBanner.visibility = if (gateLocked) View.VISIBLE else View.GONE
                        binding.btnBack.visibility = if (gateLocked) View.GONE else View.VISIBLE
                        if (gateLocked) {
                            binding.tvComplianceBannerTitle.text = when (kycStatus) {
                                1 -> "Verification Pending Approval — App Locked"
                                3 -> "Action Required — KYC Rejected"
                                else -> "Complete Identity Verification to Unlock App"
                            }
                            binding.tvComplianceBannerMsg.text = when (kycStatus) {
                                1 -> "Your submission passed automated security screening and is waiting for admin review. Features unlock automatically once approved."
                                3 -> "Your last submission was rejected. Fix the issue below and resubmit to unlock your account."
                                else -> "Wallet, transfers, and service purchases remain locked until your identity verification is approved."
                            }
                        }

                        if (kycStatus == 3 && rejectReason.isNotBlank()) {
                            binding.cardRejectReason.visibility = View.VISIBLE
                            binding.tvRejectReasonText.text = rejectReason
                        } else {
                            binding.cardRejectReason.visibility = View.GONE
                        }

                        if (stillNeeded.isNotEmpty()) {
                            binding.tvStillNeeded.visibility = View.VISIBLE
                            binding.tvStillNeeded.text = "Still needed: " + stillNeeded.joinToString(", ")
                        } else {
                            binding.tvStillNeeded.visibility = View.GONE
                        }

                        if (aiRating != null && aiRating > 0) {
                            binding.tvAiScreeningBadge.visibility = View.VISIBLE
                            binding.tvAiScreeningBadge.text = "AI Screening: $aiRating/100 (Grade $aiGrade)" + if (aiReason.isNotBlank()) " — $aiReason" else ""
                        } else {
                            binding.tvAiScreeningBadge.visibility = View.GONE
                        }

                        val showPendingLock = (kycStatus == 1 && stillNeeded.isEmpty())
                        binding.cardPendingLock.visibility = if (showPendingLock) View.VISIBLE else View.GONE
                        binding.llKycFormsContainer.visibility = if (showPendingLock && !formsExpandedWhilePending) View.GONE else View.VISIBLE

                        val voveidEnabled = body["voveid_enabled"] as? String ?: "No"
                        binding.btnStartVoveID?.visibility = if (voveidEnabled == "Yes") View.VISIBLE else View.GONE
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                }
            }
        }
    }

    private fun initVoveID() {
        voveidInitialized = true
    }

    private fun startVoveIDVerification() {
        val prefs = PreferenceManager(requireContext())
        val api = RetrofitClient.getService()
        binding.progressBar.visibility = View.VISIBLE
        binding.btnStartVoveID?.isEnabled = false
        lifecycleScope.launch {
            try {
                val resp = api.kycAction(mapOf("api_key" to prefs.getApiKey(), "action" to "voveid_session"))
                val body = resp.body()
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnStartVoveID?.isEnabled = true
                    if (body?.get("status") == "success") {
                        val token = body["token"] as? String ?: ""
                        if (token.isNotBlank()) startVoveIDWithToken(token) else snack("Failed to create VoveID session")
                    } else {
                        snack(body?.get("desc") as? String ?: "Failed to create VoveID session")
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnStartVoveID?.isEnabled = true
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun startVoveIDWithToken(sessionToken: String) {
        Vove.start(requireContext(), sessionToken, false, { result: VerificationResult ->
            activity?.runOnUiThread {
                when (result) {
                    VerificationResult.SUCCESS -> { snack("Verification successful!"); loadKYCStatus() }
                    VerificationResult.FAILURE -> snack("Verification failed")
                    VerificationResult.PENDING -> snack("Verification pending review")
                    VerificationResult.CANCELLED -> snack("Verification canceled")
                    VerificationResult.IN_PROGRESS -> snack("Verification in progress")
                    VerificationResult.MAX_ATTEMPTS_REACHED -> snack("Maximum attempts reached. Please contact support.")
                }
            }
        }, null)
    }

    private fun saveBvnNin() {
        val type = if (binding.rbNin.isChecked) "nin" else "bvn"
        val value = binding.etBvnNinValue.text?.toString()?.trim() ?: ""
        if (value.length < 10) { snack("Please enter a valid $type number"); return }

        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.kycAction(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "action" to "submit_bvn_nin",
                    "type" to type,
                    "value" to value
                ))
                val desc = resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    snack(desc)
                    if (resp.body()?.get("status") == "success") loadKYCStatus()
                }
            } catch (e: Exception) {
                activity?.runOnUiThread { snack("Error: ${e.message}") }
            }
        }
    }

    private fun uploadDocuments() {
        if (govtIdUri == null && poaUri == null && selfieUri == null && !videoReady) {
            snack("Please select or capture at least one document to upload")
            return
        }
        binding.progressBar.visibility = View.VISIBLE
        binding.btnUploadDocuments.isEnabled = false
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val apiKeyBody = prefs.getApiKey().toRequestBody("text/plain".toMediaType())
                val actionBody = "upload_document".toRequestBody("text/plain".toMediaType())
                val docTypeStr = (binding.spinnerIdType.selectedItem as? String) ?: "National ID"

                val builder = MultipartBody.Builder().setType(MultipartBody.FORM)
                    .addFormDataPart("api_key", null, apiKeyBody)
                    .addFormDataPart("action", null, actionBody)
                    .addFormDataPart("doc_type", null, docTypeStr.toRequestBody("text/plain".toMediaType()))

                fun uriToFile(uri: Uri, prefix: String): Pair<File, String>? {
                    return try {
                        val mime = requireContext().contentResolver.getType(uri) ?: "image/jpeg"
                        val ext = if (mime.contains("pdf", true)) "pdf" else "jpg"
                        val inputStream = requireContext().contentResolver.openInputStream(uri) ?: return null
                        val tmpFile = File(requireContext().cacheDir, "${prefix}_${System.currentTimeMillis()}.$ext")
                        FileOutputStream(tmpFile).use { out -> inputStream.copyTo(out) }
                        tmpFile to (if (ext == "pdf") "application/pdf" else "image/jpeg")
                    } catch (_: Exception) { null }
                }

                govtIdUri?.let { uriToFile(it, "govt_id")?.let { (f, mime) ->
                    if (f.length() < 256L) throw IllegalStateException("Government ID file is blank or empty.")
                    builder.addFormDataPart("govt_id", f.name, f.asRequestBody(mime.toMediaType()))
                }}
                poaUri?.let { uriToFile(it, "proof_of_address")?.let { (f, mime) ->
                    if (f.length() < 256L) throw IllegalStateException("Proof of address file is blank or empty.")
                    builder.addFormDataPart("proof_of_address", f.name, f.asRequestBody(mime.toMediaType()))
                }}
                selfieUri?.let { uriToFile(it, "selfie")?.let { (f, _) ->
                    if (f.length() < 256L) throw IllegalStateException("Live photo is blank or empty.")
                    builder.addFormDataPart("selfie", f.name, f.asRequestBody("image/jpeg".toMediaType()))
                }}

                if (videoReady) {
                    val video = pendingVideo
                    if (video == null || !videoIsValid(video)) throw IllegalStateException("Video is no longer valid. Please record again.")
                    builder.addFormDataPart("liveliness_video", video.name, video.asRequestBody("video/mp4".toMediaType()))
                }
                val resp = api.kycUpload(builder.build())
                val bodyMap = resp.body()
                val errDesc = if (!resp.isSuccessful) {
                    try {
                        val rawErr = resp.errorBody()?.string().orEmpty()
                        org.json.JSONObject(rawErr).optString("desc", "")
                    } catch (_: Exception) { "" }
                } else ""
                val warnings = (bodyMap?.get("av_warnings") as? List<*>)?.mapNotNull { it as? String } ?: emptyList()
                val baseDesc = (bodyMap?.get("desc") as? String)?.takeIf { it.isNotBlank() }
                    ?: errDesc.takeIf { it.isNotBlank() }
                    ?: if (resp.isSuccessful) "Submitted." else "Upload rejected by security check."
                val desc = if (warnings.isNotEmpty()) "$baseDesc (${warnings.joinToString("; ")})" else baseDesc
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnUploadDocuments.isEnabled = true
                    snack(desc)
                    if (bodyMap?.get("status") == "success") {
                        formsExpandedWhilePending = false
                        loadKYCStatus()
                    }
                }
            } catch (e: Exception) {
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnUploadDocuments.isEnabled = true
                    snack("Error: ${e.message}")
                }
            }
        }
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
