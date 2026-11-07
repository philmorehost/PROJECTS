package com.mzeevtu.util

import android.app.Dialog
import android.content.Context
import android.graphics.Color
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.ViewGroup
import android.view.WindowManager
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView

/**
 * Full-screen, non-cancelable processing overlay.
 *
 * Shows a dimmed full-screen overlay with a centered card (spinner + status message) that
 * covers the entire screen the moment a purchase/operation starts. This guarantees the user
 * always sees that something is processing — regardless of scroll position — and prevents
 * accidental duplicate taps / double purchases while the request is in flight.
 *
 * Usage:
 *   LoadingOverlay.show(requireContext(), "Processing your purchase...")
 *   ...
 *   LoadingOverlay.dismiss()
 *
 * Safe to call from any thread (show/dismiss are posted to the main thread) and dismiss()
 * is a no-op when nothing is visible.
 */
object LoadingOverlay {

    @Volatile private var dialog: Dialog? = null
    private val mainHandler = Handler(Looper.getMainLooper())

    fun show(context: Context, message: String = "Processing...") {
        mainHandler.post {
            dismissSafely()
            try {
                val d = Dialog(context)
                d.setCancelable(false)
                d.setCanceledOnTouchOutside(false)
                d.requestWindowFeature(android.view.Window.FEATURE_NO_TITLE)

                // Root: dim the whole screen and swallow taps so nothing behind can be clicked.
                val root = FrameLayout(context)
                root.setBackgroundColor(0x88000000.toInt()) // ~53% dim
                root.setOnClickListener { }

                // Centered white card.
                val card = LinearLayout(context)
                card.orientation = LinearLayout.VERTICAL
                card.gravity = Gravity.CENTER
                card.setPadding(dp(context, 40), dp(context, 32), dp(context, 40), dp(context, 32))
                val cardBg = GradientDrawable()
                cardBg.cornerRadius = dp(context, 20).toFloat()
                cardBg.setColor(Color.WHITE)
                card.background = cardBg

                val spinner = ProgressBar(context)
                val spinnerLp = LinearLayout.LayoutParams(dp(context, 46), dp(context, 46))
                spinnerLp.gravity = Gravity.CENTER
                spinner.layoutParams = spinnerLp

                val msg = TextView(context)
                msg.text = message
                msg.setTextColor(Color.parseColor("#0F172A"))
                msg.textSize = 15f
                msg.gravity = Gravity.CENTER
                msg.setTypeface(msg.typeface, android.graphics.Typeface.BOLD)
                val msgLp = LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.WRAP_CONTENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                )
                msgLp.topMargin = dp(context, 16)
                msg.layoutParams = msgLp

                card.addView(spinner)
                card.addView(msg)

                val cardLp = FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.WRAP_CONTENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT,
                    Gravity.CENTER
                )
                root.addView(card, cardLp)

                d.setContentView(root)
                d.window?.apply {
                    setBackgroundDrawable(ColorDrawable(Color.TRANSPARENT))
                    setLayout(
                        WindowManager.LayoutParams.MATCH_PARENT,
                        WindowManager.LayoutParams.MATCH_PARENT
                    )
                }
                d.show()
                dialog = d
            } catch (_: Exception) {
                // Never crash the purchase flow because the overlay failed to render.
            }
        }
    }

    fun dismiss() {
        mainHandler.post { dismissSafely() }
    }

    private fun dismissSafely() {
        try {
            dialog?.dismiss()
        } catch (_: Exception) {
        } finally {
            dialog = null
        }
    }

    private fun dp(context: Context, value: Int): Int =
        (value * context.resources.displayMetrics.density).toInt()
}
