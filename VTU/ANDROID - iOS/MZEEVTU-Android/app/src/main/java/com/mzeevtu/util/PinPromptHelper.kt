package com.mzeevtu.util

import android.content.Context
import android.text.InputFilter
import android.text.InputType
import android.widget.EditText
import android.widget.FrameLayout
import com.google.android.material.dialog.MaterialAlertDialogBuilder

/**
 * Gates a purchase action behind the vendor's transaction-PIN requirement
 * (Constants.KEY_PIN_REQUIRED, synced from web/api/profile.php's pin_required field, itself
 * sourced from sas_vendors.force_security_pin). The real enforcement is server-side —
 * requireTransactionPin() on every purchase endpoint — so this is UX only, to avoid a purchase
 * bouncing off a confusing server rejection when no PIN was supplied.
 */
object PinPromptHelper {

    /**
     * @param onReady called with the entered PIN, or null if no PIN is currently required.
     * @param onNeedsSetup called instead if the vendor requires a PIN but the user hasn't set one yet.
     */
    fun requirePin(
        context: Context,
        prefs: PreferenceManager,
        onReady: (String?) -> Unit,
        onNeedsSetup: () -> Unit
    ) {
        if (!prefs.getBoolean(Constants.KEY_PIN_REQUIRED, false)) {
            onReady(null)
            return
        }
        if (!prefs.getBoolean(Constants.KEY_PIN_SET, false)) {
            onNeedsSetup()
            return
        }

        val input = EditText(context).apply {
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
            filters = arrayOf(InputFilter.LengthFilter(4))
            hint = "4-digit PIN"
        }
        val padding = (20 * context.resources.displayMetrics.density).toInt()
        val container = FrameLayout(context).apply {
            setPadding(padding, padding / 2, padding, 0)
            addView(input)
        }

        MaterialAlertDialogBuilder(context)
            .setTitle("Transaction PIN Required")
            .setMessage("Enter your PIN to authorize this purchase.")
            .setView(container)
            .setPositiveButton("Confirm") { _, _ -> onReady(input.text?.toString()?.trim() ?: "") }
            .setNegativeButton("Cancel", null)
            .show()
    }
}
