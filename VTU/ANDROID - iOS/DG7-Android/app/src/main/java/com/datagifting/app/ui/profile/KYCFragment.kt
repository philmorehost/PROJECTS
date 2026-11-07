package com.datagifting.app.ui.profile

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import com.datagifting.app.R
import com.datagifting.app.api.RetrofitClient
import com.datagifting.app.databinding.FragmentKycBinding
import com.datagifting.app.util.PreferenceManager
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

    private val pickSelfie = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            selfieUri = result.data?.data
            binding.tvSelfieName.text = selfieUri?.lastPathSegment ?: "File selected"
        }
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
        binding.btnPickSelfie.setOnClickListener {
            val intent = Intent(Intent.ACTION_GET_CONTENT).apply { type = "image/*" }
            pickSelfie.launch(intent)
        }
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
