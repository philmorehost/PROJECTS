package com.jikabiz.app.ui.profile

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.jikabiz.app.R
import com.jikabiz.app.api.RetrofitClient
import com.jikabiz.app.databinding.FragmentKycBinding
import com.jikabiz.app.util.PreferenceManager
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
    private var selfieUri: Uri? = null
    private var voveidInitialized = false

    private val pickGovtId = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            govtIdUri = result.data?.data
            binding.tvGovtIdName.text = govtIdUri?.lastPathSegment ?: "File selected"
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
        if (granted) captureSelfie() else if (_binding != null) snack("Camera permission is required. Gallery selfies are not accepted.")
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

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        savedInstanceState?.getString("kyc_camera_file")?.let { name ->
            if (name.matches(Regex("kyc_camera_[A-Za-z0-9_-]+\\.jpg"))) {
                pendingSelfie = File(File(requireContext().cacheDir, "kyc"), name)
            }
        }
    }
    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        pendingSelfie?.let { outState.putString("kyc_camera_file", it.name) }
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentKycBinding.bind(view)

        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }

        loadKYCStatus()

        binding.btnSaveBvnNin.setOnClickListener { saveBvnNin() }

        binding.btnPickGovtId.setOnClickListener {
            val intent = Intent(Intent.ACTION_GET_CONTENT).apply { type = "image/*" }
            pickGovtId.launch(intent)
        }
        binding.btnPickSelfie.text = "Take live selfie"
        binding.btnPickSelfie.setOnClickListener { captureSelfie() }
        binding.btnUploadDocuments.setOnClickListener { uploadDocuments() }
        
        // VoveID Verification button
        binding.btnStartVoveID?.setOnClickListener { startVoveIDVerification() }
        
        // Initialize VoveID SDK
        initVoveID()
    }

    private fun loadKYCStatus() {
        binding.progressBar.visibility = View.VISIBLE
        lifecycleScope.launch {
            try {
                val prefs = PreferenceManager(requireContext())
                val api = RetrofitClient.getService()
                val resp = api.kycAction(mapOf("api_key" to prefs.getApiKey(), "action" to "status"))
                val body = resp.body()
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    if (body?.get("status") == "success") {
                        val statusName = body["kyc_name"] as? String ?: "Unverified"
                        val bvnSet = body["bvn_set"] as? String ?: "No"
                        val ninSet = body["nin_set"] as? String ?: "No"
                        val voveidEnabled = body["voveid_enabled"] as? String ?: "No"
                        binding.tvKycStatus.text = "KYC Status: $statusName"
                        binding.tvBvnStatus.text = "BVN Saved: $bvnSet"
                        binding.tvNinStatus.text = "NIN Saved: $ninSet"
                        
                        // Show/hide VoveID button based on vendor settings
                        if (voveidEnabled == "Yes") {
                            binding.btnStartVoveID?.visibility = View.VISIBLE
                        } else {
                            binding.btnStartVoveID?.visibility = View.GONE
                        }
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
        // The VoveID Android SDK initializes itself when startIDVerification is called.
        voveidInitialized = true
    }

    private fun startVoveIDVerification() {
        val prefs = PreferenceManager(requireContext())
        val api = RetrofitClient.getService()
        
        binding.progressBar.visibility = View.VISIBLE
        binding.btnStartVoveID?.isEnabled = false
        
        lifecycleScope.launch {
            try {
                val resp = api.kycAction(mapOf(
                    "api_key" to prefs.getApiKey(),
                    "action" to "voveid_session"
                ))
                
                val body = resp.body()
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnStartVoveID?.isEnabled = true
                    
                    if (body?.get("status") == "success") {
                        val token = body["token"] as? String ?: ""
                        if (token.isNotBlank()) {
                            startVoveIDWithToken(token)
                        } else {
                            snack("Failed to create VoveID session")
                        }
                    } else {
                        val desc = body?.get("desc") as? String ?: "Failed to create VoveID session"
                        snack(desc)
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
        val environment = if (getString(R.string.voveid_environment).equals("production", true)) {
            VoveEnvironment.PRODUCTION
        } else {
            VoveEnvironment.SANDBOX
        }

        Vove.start(requireContext(), sessionToken, false, { result: VerificationResult ->
            activity?.runOnUiThread {
                when (result) {
                    VerificationResult.SUCCESS -> {
                        snack("Verification successful!")
                        loadKYCStatus()
                    }
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
        if (govtIdUri == null && selfieUri == null) {
            snack("Please select at least one document to upload")
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

                val builder = MultipartBody.Builder().setType(MultipartBody.FORM)
                    .addFormDataPart("api_key", null, apiKeyBody)
                    .addFormDataPart("action", null, actionBody)

                fun uriToFile(uri: Uri, prefix: String): File? {
                    return try {
                        val inputStream = requireContext().contentResolver.openInputStream(uri) ?: return null
                        val tmpFile = File(requireContext().cacheDir, "${prefix}_${System.currentTimeMillis()}.jpg")
                        FileOutputStream(tmpFile).use { out -> inputStream.copyTo(out) }
                        tmpFile
                    } catch (_: Exception) { null }
                }

                govtIdUri?.let { uriToFile(it, "govt_id")?.let { f ->
                    builder.addFormDataPart("govt_id", f.name, f.asRequestBody("image/jpeg".toMediaType()))
                }}
                selfieUri?.let { uriToFile(it, "selfie")?.let { f ->
                    builder.addFormDataPart("selfie", f.name, f.asRequestBody("image/jpeg".toMediaType()))
                }}

                val resp = api.kycUpload(builder.build())
                val desc = resp.body()?.get("desc") as? String ?: ""
                activity?.runOnUiThread {
                    if (_binding == null) return@runOnUiThread
                    binding.progressBar.visibility = View.GONE
                    binding.btnUploadDocuments.isEnabled = true
                    snack(desc)
                    if (resp.body()?.get("status") == "success") loadKYCStatus()
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
