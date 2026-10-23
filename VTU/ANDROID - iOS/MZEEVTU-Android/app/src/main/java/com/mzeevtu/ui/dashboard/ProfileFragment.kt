package com.mzeevtu.ui.dashboard

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.biometric.BiometricManager
import androidx.fragment.app.Fragment
import androidx.navigation.fragment.findNavController
import com.mzeevtu.R
import com.mzeevtu.databinding.FragmentProfileBinding
import com.mzeevtu.ui.auth.LoginActivity
import com.mzeevtu.util.Constants
import com.mzeevtu.util.PreferenceManager
import com.mzeevtu.util.safeNavigate

class ProfileFragment : Fragment(R.layout.fragment_profile) {

    private var _binding: FragmentProfileBinding? = null
    private val binding get() = _binding!!
    private lateinit var prefs: PreferenceManager

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        _binding = FragmentProfileBinding.bind(view)
        prefs = PreferenceManager(requireContext())

        populateProfile()
        setupButtons()
    }

    private fun populateProfile() {
        binding.tvUsername.text = "@${prefs.getString(Constants.KEY_USERNAME)}"
        binding.tvFullName.text = "${prefs.getString(Constants.KEY_FIRSTNAME)} ${prefs.getString(Constants.KEY_LASTNAME)}"
        binding.tvEmail.text = prefs.getString(Constants.KEY_EMAIL)
        binding.tvPhone.text = prefs.getString(Constants.KEY_PHONE)
    }

    private fun setupButtons() {
        binding.btnSetPin.setOnClickListener {
            findNavController().safeNavigate(R.id.action_profile_to_set_pin)
        }

        binding.btnKyc.setOnClickListener {
            findNavController().safeNavigate(R.id.action_profile_to_kyc)
        }

        binding.btnNinCard.setOnClickListener {
            findNavController().safeNavigate(R.id.action_profile_to_nin)
        }
        // Rewards: referral, coin conversion and the coin ledger. All three are backed by the
        // same server code the website uses (web/api/referral.php, coin-conversion.php,
        // points-history.php), so the numbers match across surfaces.
        binding.btnReferral.setOnClickListener {
            findNavController().safeNavigate(R.id.action_profile_to_referral)
        }

        binding.btnCoins.setOnClickListener {
            findNavController().safeNavigate(R.id.action_profile_to_coins)
        }

        binding.btnPointsHistory.setOnClickListener {
            findNavController().safeNavigate(R.id.action_profile_to_points_history)
        }

        // Dark mode toggle
        binding.switchDarkMode.isChecked = prefs.getBoolean(Constants.KEY_DARK_MODE, false)
        binding.switchDarkMode.setOnCheckedChangeListener { _, isChecked ->
            prefs.saveBoolean(Constants.KEY_DARK_MODE, isChecked)
            androidx.appcompat.app.AppCompatDelegate.setDefaultNightMode(
                if (isChecked) androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_YES
                else androidx.appcompat.app.AppCompatDelegate.MODE_NIGHT_NO
            )
            requireActivity().recreate()
        }

        // Biometric toggle Ã¢â¬” only show if hardware is available
        val biometricManager = BiometricManager.from(requireContext())
        val biometricAvailable = biometricManager.canAuthenticate(
            BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL
        ) == BiometricManager.BIOMETRIC_SUCCESS

        if (biometricAvailable) {
            binding.switchBiometric.visibility = View.VISIBLE
            binding.switchBiometric.isChecked = prefs.getBoolean(Constants.KEY_BIOMETRIC_ENABLED, false)
            binding.switchBiometric.setOnCheckedChangeListener { _, isChecked ->
                prefs.saveBoolean(Constants.KEY_BIOMETRIC_ENABLED, isChecked)
                val msg = if (isChecked) "Biometric login enabled" else "Biometric login disabled"
                Toast.makeText(requireContext(), msg, Toast.LENGTH_SHORT).show()
            }
        } else {
            binding.switchBiometric.visibility = View.GONE
        }

        binding.btnEmailSupport.setOnClickListener {
            val email = prefs.getString(Constants.KEY_SUPPORT_EMAIL)
            val intent = Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:$email"))
            startActivity(intent)
        }

        binding.btnWhatsappSupport.setOnClickListener {
            val number = prefs.getString(Constants.KEY_SUPPORT_WHATSAPP)
            if (number.isBlank()) {
                Toast.makeText(requireContext(), "WhatsApp support is not configured yet.", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            try {
                val intent = Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$number"))
                startActivity(intent)
            } catch (e: Exception) {
                Toast.makeText(requireContext(), "Unable to open WhatsApp.", Toast.LENGTH_SHORT).show()
            }
        }

        binding.btnLogout.setOnClickListener {
            prefs.clear()
            startActivity(Intent(requireContext(), LoginActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
            })
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
