package com.mzeevtu.ui.security

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.mzeevtu.api.RetrofitClient
import com.mzeevtu.databinding.ActivityLockBinding
import com.mzeevtu.ui.MainActivity
import com.mzeevtu.util.Constants
import com.mzeevtu.util.PreferenceManager
import com.google.android.material.snackbar.Snackbar
import kotlinx.coroutines.launch

/**
 * Security-question gate. Shown as the mandatory first screen right after every login (see
 * LoginActivity.navigateToMain), and again whenever the app returns to the foreground after
 * [Constants.LOCK_TIMEOUT_MINUTES] of being backgrounded (see DGApp) — mirrors web/SecurityQuest.php's
 * protection for a shared/unattended device. The app has no session cookie to gate, so instead
 * it re-verifies against the stored security answer and records a local "last verified" timestamp.
 * Back navigation is disabled so the underlying app can't be revealed without verifying.
 */
class LockActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLockBinding
    private lateinit var prefs: PreferenceManager
    private var questBank: List<Map<String, Any>> = emptyList()
    private var questIds: List<Int> = emptyList()
    private var resetQuestIds: List<Int> = emptyList()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLockBinding.inflate(layoutInflater)
        setContentView(binding.root)
        prefs = PreferenceManager(this)

        // Disable back navigation — this screen must be resolved before the app is usable again.
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                moveTaskToBack(true)
            }
        })

        loadState()
    }

    private fun loadState() {
        setLoading(true)
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().securityQuest(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "get")
                )
                val body = resp.body()
                if (body == null || body["status"] as? String != "success") {
                    // Do NOT fall through to "no question set" on a failed call (e.g. invalid
                    // API key, vendor lookup failure) — that silently shows the wrong screen
                    // and hides the real problem. Surface it and let the user retry.
                    runOnUiThread {
                        setLoading(false)
                        snackRetry(body?.get("desc") as? String ?: "Unable to load security question. Please try again.")
                    }
                    return@launch
                }
                val hasQuest = body["has_quest"] as? Boolean ?: false
                @Suppress("UNCHECKED_CAST")
                questBank = body["quest_bank"] as? List<Map<String, Any>> ?: emptyList()
                runOnUiThread {
                    setLoading(false)
                    if (hasQuest) {
                        prefs.saveBoolean(Constants.KEY_HAS_SECURITY_QUEST, true)
                        showVerify(body["question"] as? String ?: "")
                    } else {
                        prefs.saveBoolean(Constants.KEY_HAS_SECURITY_QUEST, false)
                        showSetup(questBank)
                    }
                }
            } catch (e: Exception) {
                runOnUiThread {
                    setLoading(false)
                    snackRetry(e.message ?: "Unable to load security question")
                }
            }
        }
    }

    private fun showVerify(question: String) {
        binding.groupVerify.visibility = View.VISIBLE
        binding.groupSetup.visibility = View.GONE
        binding.groupReset.visibility = View.GONE
        binding.tvQuestion.text = question
        binding.btnVerify.setOnClickListener {
            val answer = binding.etVerifyAnswer.text?.toString()?.trim() ?: ""
            if (answer.isEmpty()) {
                snack("Please enter your answer")
                return@setOnClickListener
            }
            verify(answer)
        }
        binding.tvForgotAnswer.setOnClickListener { showReset() }
    }

    private fun showReset() {
        binding.groupVerify.visibility = View.GONE
        binding.groupReset.visibility = View.VISIBLE
        resetQuestIds = questBank.map { (it["id"] as? Double)?.toInt() ?: (it["id"] as? Int ?: 0) }
        val labels = questBank.map { it["quest"] as? String ?: "" }
        binding.spinnerResetQuestion.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, labels)
        binding.tvCancelReset.setOnClickListener {
            binding.groupReset.visibility = View.GONE
            binding.groupVerify.visibility = View.VISIBLE
        }
        binding.btnSaveReset.setOnClickListener {
            val password = binding.etResetPassword.text?.toString()?.trim() ?: ""
            val answer = binding.etResetAnswer.text?.toString()?.trim() ?: ""
            val position = binding.spinnerResetQuestion.selectedItemPosition
            if (password.isEmpty()) {
                snack("Please enter your account password")
                return@setOnClickListener
            }
            if (answer.length < 3 || answer.length > 20) {
                snack("Answer must be between 3-20 characters")
                return@setOnClickListener
            }
            if (position < 0 || position >= resetQuestIds.size) {
                snack("Please select a question")
                return@setOnClickListener
            }
            performReset(resetQuestIds[position], answer, password)
        }
    }

    private fun showSetup(questBank: List<Map<String, Any>>) {
        binding.groupVerify.visibility = View.GONE
        binding.groupSetup.visibility = View.VISIBLE
        binding.groupReset.visibility = View.GONE
        questIds = questBank.map { (it["id"] as? Double)?.toInt() ?: (it["id"] as? Int ?: 0) }
        val labels = questBank.map { it["quest"] as? String ?: "" }
        binding.spinnerQuestion.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, labels)
        binding.btnSaveSetup.setOnClickListener {
            val answer = binding.etSetupAnswer.text?.toString()?.trim() ?: ""
            val position = binding.spinnerQuestion.selectedItemPosition
            if (answer.length < 3 || answer.length > 20) {
                snack("Answer must be between 3-20 characters")
                return@setOnClickListener
            }
            if (position < 0 || position >= questIds.size) {
                snack("Please select a question")
                return@setOnClickListener
            }
            saveSetup(questIds[position], answer)
        }
    }

    private fun verify(answer: String) {
        setLoading(true)
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().securityQuest(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "verify", "answer" to answer)
                )
                val status = resp.body()?.get("status") as? String ?: "failed"
                runOnUiThread {
                    setLoading(false)
                    if (status == "success") {
                        markVerifiedAndFinish()
                    } else {
                        snack(resp.body()?.get("desc") as? String ?: "Invalid security answer")
                    }
                }
            } catch (e: Exception) {
                runOnUiThread { setLoading(false); snack(e.message ?: "Verification failed") }
            }
        }
    }

    private fun saveSetup(questId: Int, answer: String) {
        setLoading(true)
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().securityQuest(
                    mapOf("api_key" to prefs.getApiKey(), "action" to "set", "quest_id" to questId, "answer" to answer)
                )
                val status = resp.body()?.get("status") as? String ?: "failed"
                runOnUiThread {
                    setLoading(false)
                    if (status == "success") {
                        prefs.saveBoolean(Constants.KEY_HAS_SECURITY_QUEST, true)
                        markVerifiedAndFinish()
                    } else {
                        snack(resp.body()?.get("desc") as? String ?: "Unable to save security question")
                    }
                }
            } catch (e: Exception) {
                runOnUiThread { setLoading(false); snack(e.message ?: "Unable to save security question") }
            }
        }
    }

    private fun performReset(questId: Int, answer: String, password: String) {
        setLoading(true)
        lifecycleScope.launch {
            try {
                val resp = RetrofitClient.getService().securityQuest(
                    mapOf(
                        "api_key" to prefs.getApiKey(),
                        "action" to "reset",
                        "quest_id" to questId,
                        "answer" to answer,
                        "password" to password
                    )
                )
                val status = resp.body()?.get("status") as? String ?: "failed"
                runOnUiThread {
                    setLoading(false)
                    if (status == "success") {
                        prefs.saveBoolean(Constants.KEY_HAS_SECURITY_QUEST, true)
                        markVerifiedAndFinish()
                    } else {
                        snack(resp.body()?.get("desc") as? String ?: "Unable to reset security question")
                    }
                }
            } catch (e: Exception) {
                runOnUiThread { setLoading(false); snack(e.message ?: "Unable to reset security question") }
            }
        }
    }

    private fun markVerifiedAndFinish() {
        prefs.saveLong(Constants.KEY_SECURITY_VERIFIED_AT, System.currentTimeMillis())
        // LockActivity is launched from the Application context (DGApp), which requires
        // FLAG_ACTIVITY_NEW_TASK and can land it in a separate task from MainActivity. Relying on
        // finish() alone then has nothing to fall back to in THIS task, and Android drops to the
        // home screen — looking like the app crashed/closed. Explicitly bring MainActivity back
        // to the foreground first so there's always something to return to.
        startActivity(Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_SINGLE_TOP
        })
        finish()
    }

    private fun setLoading(loading: Boolean) {
        binding.progressBar.visibility = if (loading) View.VISIBLE else View.GONE
    }

    private fun snack(msg: String) = Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()

    // Used for failures on loadState() itself — this screen has no other content to fall back
    // to (it's the mandatory gate), so an indefinite Snackbar with a Retry action is used
    // instead of a transient one that would otherwise leave the user stuck on a blank screen.
    private fun snackRetry(msg: String) {
        Snackbar.make(binding.root, msg, Snackbar.LENGTH_INDEFINITE)
            .setAction("Retry") { loadState() }
            .show()
    }
}
