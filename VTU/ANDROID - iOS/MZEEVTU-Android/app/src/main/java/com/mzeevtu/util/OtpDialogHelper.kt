package com.mzeevtu.util

import android.content.Context
import android.text.InputFilter
import android.text.InputType
import android.widget.EditText
import android.widget.FrameLayout
import com.google.android.material.dialog.MaterialAlertDialogBuilder

/**
 * One-time-code prompt shared by sign-in and registration.
 *
 * The dialog is deliberately re-openable: a wrong code closes it with an error message from
 * the caller and the caller may prompt again, so the user never has to re-enter a password.
 */
object OtpDialogHelper {

    fun prompt(
        context: Context,
        title: String,
        message: String,
        onCode: (String) -> Unit,
        onResend: (() -> Unit)? = null
    ) {
        val input = EditText(context).apply {
            inputType = InputType.TYPE_CLASS_NUMBER
            filters = arrayOf(InputFilter.LengthFilter(6))
            hint = "6-digit code"
            textSize = 22f
            setTextAlignment(android.view.View.TEXT_ALIGNMENT_CENTER)
            letterSpacing = 0.4f
        }
        val padding = (20 * context.resources.displayMetrics.density).toInt()
        val container = FrameLayout(context).apply {
            setPadding(padding, padding / 2, padding, 0)
            addView(input)
        }

        val builder = MaterialAlertDialogBuilder(context)
            .setTitle(title)
            .setMessage(message)
            .setView(container)
            .setPositiveButton("Verify") { _, _ ->
                val code = input.text?.toString()?.trim() ?: ""
                if (code.length != 6) onCode("") else onCode(code)
            }
            .setNegativeButton("Cancel", null)

        if (onResend != null) builder.setNeutralButton("Resend", null)

        val dialog = builder.show()

        // Neutral button is wired manually so the dialog stays open after a resend.
        if (onResend != null) {
            dialog.getButton(android.app.AlertDialog.BUTTON_NEUTRAL).setOnClickListener { onResend() }
        }
    }
}