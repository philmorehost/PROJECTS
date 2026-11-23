package com.jikabiz.app.ui.transactions

import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.pdf.PdfDocument
import android.view.LayoutInflater
import android.view.View
import android.widget.Button
import android.widget.TextView
import android.widget.Toast
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.fragment.app.Fragment
import com.jikabiz.app.R
import com.jikabiz.app.data.model.Transaction
import com.jikabiz.app.util.toNaira
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import java.io.File
import java.io.FileOutputStream

object ReceiptHelper {

    private const val DEFAULT_RECEIPT_WIDTH_PX = 900

    private fun receiptDescription(tx: Transaction): String = if (tx.receiptDetails.isNotEmpty())
        tx.receiptDetails.joinToString("\n\n") { "${it.label}\n${it.value}" }
    else tx.description.ifEmpty { "—" }


    fun showReceiptDialog(fragment: Fragment, tx: Transaction) {
        val context = fragment.requireContext()
        val dialogView = fragment.layoutInflater.inflate(R.layout.dialog_transaction_receipt, null)
        bindReceiptView(dialogView, tx)

        val dialog = MaterialAlertDialogBuilder(context)
            .setView(dialogView)
            .setNegativeButton("Close", null)
            .create()

        dialogView.findViewById<Button>(R.id.btn_share_image).setOnClickListener {
            val receiptCard = dialogView.findViewById<View>(R.id.card_receipt)
            shareReceiptAsImage(fragment, receiptCard, tx.reference)
        }

        dialogView.findViewById<Button>(R.id.btn_share_pdf).setOnClickListener {
            shareReceiptAsPdf(fragment, tx)
        }

        dialog.show()
        dialog.window?.decorView?.let { com.jikabiz.app.util.BrandTheme.applyView(it) }
    }

    /** Convenience overload used from non-fragment contexts that already have a LayoutInflater */
    fun showReceiptDialog(context: android.content.Context, inflater: LayoutInflater, tx: Transaction) {
        val dialogView = inflater.inflate(R.layout.dialog_transaction_receipt, null)
        bindReceiptView(dialogView, tx)

        val dialog = MaterialAlertDialogBuilder(context)
            .setView(dialogView)
            .setNegativeButton("Close", null)
            .create()

        dialogView.findViewById<Button>(R.id.btn_share_image).setOnClickListener {
            val receiptCard = dialogView.findViewById<View>(R.id.card_receipt)
            shareReceiptAsImageFromContext(context, receiptCard, tx.reference)
        }

        dialogView.findViewById<Button>(R.id.btn_share_pdf).setOnClickListener {
            shareReceiptAsPdfFromContext(context, tx)
        }

        dialog.show()
        dialog.window?.decorView?.let { com.jikabiz.app.util.BrandTheme.applyView(it) }
    }

    private fun bindReceiptView(view: View, tx: Transaction) {
        val statusText = statusLabel(tx.status)
        val emoji = statusEmoji(tx.status)
        view.findViewById<TextView>(R.id.tv_receipt_status_emoji).text = emoji
        view.findViewById<TextView>(R.id.tv_receipt_status).apply {
            text = statusText
            setTextColor(statusColor(tx.status))
        }
        view.findViewById<TextView>(R.id.tv_receipt_amount).text = tx.amount.toNaira()
        view.findViewById<TextView>(R.id.tv_receipt_reference).text = tx.reference.ifEmpty { "—" }
        view.findViewById<TextView>(R.id.tv_receipt_type).text = tx.type.ifEmpty { "—" }
        view.findViewById<TextView>(R.id.tv_receipt_description).text = receiptDescription(tx)
        view.findViewById<TextView>(R.id.tv_receipt_amount_paid).text = tx.discountedAmount.toNaira()
        view.findViewById<TextView>(R.id.tv_receipt_balance_before).text = tx.balanceBefore.toNaira()
        view.findViewById<TextView>(R.id.tv_receipt_balance_after).text = tx.balanceAfter.toNaira()
        view.findViewById<TextView>(R.id.tv_receipt_mode).text = tx.mode.ifEmpty { "—" }
        view.findViewById<TextView>(R.id.tv_receipt_date).text = tx.date.ifEmpty { "—" }
    }

    private fun shareReceiptAsImage(fragment: Fragment, receiptCard: View, reference: String) {
        shareReceiptAsImageFromContext(fragment.requireContext(), receiptCard, reference) { intent ->
            fragment.startActivity(intent)
        }
    }

    private fun shareReceiptAsImageFromContext(context: android.content.Context, receiptCard: View, reference: String) {
        shareReceiptAsImageFromContext(context, receiptCard, reference) { intent ->
            context.startActivity(intent)
        }
    }

    private fun shareReceiptAsImageFromContext(
        context: android.content.Context,
        receiptCard: View,
        reference: String,
        startActivity: (Intent) -> Unit
    ) {
        try {
            val width = receiptCard.width.takeIf { it > 0 } ?: DEFAULT_RECEIPT_WIDTH_PX
            receiptCard.measure(
                View.MeasureSpec.makeMeasureSpec(width, View.MeasureSpec.EXACTLY),
                View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED)
            )
            receiptCard.layout(0, 0, receiptCard.measuredWidth, receiptCard.measuredHeight)

            val bitmap = Bitmap.createBitmap(receiptCard.measuredWidth, receiptCard.measuredHeight, Bitmap.Config.ARGB_8888)
            val canvas = Canvas(bitmap)
            canvas.drawColor(Color.WHITE)
            receiptCard.draw(canvas)

            val dir = File(context.cacheDir, "receipts").also { it.mkdirs() }
            val safeName = reference.replace(Regex("[^A-Za-z0-9_-]"), "_")
            val file = File(dir, "receipt_$safeName.png")
            FileOutputStream(file).use { out -> bitmap.compress(Bitmap.CompressFormat.PNG, 100, out) }

            val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
            val intent = Intent(Intent.ACTION_SEND).apply {
                type = "image/png"
                putExtra(Intent.EXTRA_STREAM, uri)
                putExtra(Intent.EXTRA_TEXT, "Transaction Receipt — Ref: $reference")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }
            startActivity(Intent.createChooser(intent, "Share Receipt"))
        } catch (e: Exception) {
            Toast.makeText(context, "Unable to share image", Toast.LENGTH_SHORT).show()
        }
    }

    private fun shareReceiptAsPdf(fragment: Fragment, tx: Transaction) {
        shareReceiptAsPdfFromContext(fragment.requireContext(), tx) { intent ->
            fragment.startActivity(intent)
        }
    }

    private fun shareReceiptAsPdfFromContext(context: android.content.Context, tx: Transaction) {
        shareReceiptAsPdfFromContext(context, tx) { intent ->
            context.startActivity(intent)
        }
    }

    private fun shareReceiptAsPdfFromContext(
        context: android.content.Context,
        tx: Transaction,
        startActivity: (Intent) -> Unit
    ) {
        try {
            val pageWidth = 595
            val receiptRows = listOf("Reference" to tx.reference, "Type" to tx.type) +
                (if (tx.receiptDetails.isNotEmpty()) tx.receiptDetails.map { it.label to it.value }
                 else listOf("Description" to tx.description)) + listOf(
                    "Amount Paid" to tx.discountedAmount.toNaira(),
                    "Balance Before" to tx.balanceBefore.toNaira(),
                    "Balance After" to tx.balanceAfter.toNaira(),
                    "Mode" to tx.mode, "Date" to tx.date)
            val measure = Paint().apply { textSize = 14f; isAntiAlias = true }
            fun wrapped(value: String): List<String> = value.ifEmpty { "—" }.split("\n").flatMap { paragraph ->
                val parts = mutableListOf<String>()
                var remaining = paragraph
                while (remaining.isNotEmpty()) {
                    val count = measure.breakText(remaining, true, 335f, null).coerceAtLeast(1)
                    parts.add(remaining.take(count)); remaining = remaining.drop(count)
                }
                if (parts.isEmpty()) listOf("") else parts
            }
            val prepared = receiptRows.map { (label, value) -> label to wrapped(value) }
            val pageHeight = maxOf(842, 280 + prepared.sumOf { 22 + it.second.size * 18 })

            val pdfDoc = PdfDocument()
            val pageInfo = PdfDocument.PageInfo.Builder(pageWidth, pageHeight, 1).create()
            val page = pdfDoc.startPage(pageInfo)
            val canvas = page.canvas
            canvas.drawColor(Color.WHITE)

            val primaryColor = com.jikabiz.app.util.BrandTheme.printInk()
            val paintTitle = Paint().apply { color = primaryColor; textSize = 22f; isFakeBoldText = true; isAntiAlias = true }
            val paintLabel = Paint().apply { color = Color.GRAY; textSize = 14f; isAntiAlias = true }
            val paintValue = Paint().apply { color = Color.BLACK; textSize = 14f; isAntiAlias = true }
            val paintDivider = Paint().apply { color = Color.LTGRAY }
            val paintAmount = Paint().apply { color = primaryColor; textSize = 26f; isFakeBoldText = true; isAntiAlias = true }

            var y = 60f
            val lx = 40f
            val rx = (pageWidth - 40).toFloat()

            canvas.drawText("Transaction Receipt", lx, y, paintTitle); y += 24f
            canvas.drawText("JIKABIZ", lx, y, paintLabel); y += 20f
            canvas.drawLine(lx, y, rx, y, paintDivider); y += 24f

            val statusText = "${statusEmoji(tx.status)} ${statusLabel(tx.status)}"
            canvas.drawText(statusText, lx, y, paintValue); y += 20f
            canvas.drawText(tx.amount.toNaira(), lx, y, paintAmount); y += 30f

            canvas.drawLine(lx, y, rx, y, paintDivider); y += 20f

            prepared.forEach { (label, lines) ->
                canvas.drawText(label, lx, y, paintLabel)
                y += 18f
                lines.forEach { line -> canvas.drawText(line, lx, y, paintValue); y += 18f }
                y += 4f
            }

            y += 10f
            canvas.drawLine(lx, y, rx, y, paintDivider); y += 20f
            canvas.drawText("Thank you for using JIKABIZ", lx, y, paintLabel)

            pdfDoc.finishPage(page)

            val dir = File(context.cacheDir, "receipts").also { it.mkdirs() }
            val safeName = tx.reference.replace(Regex("[^A-Za-z0-9_-]"), "_")
            val file = File(dir, "receipt_$safeName.pdf")
            FileOutputStream(file).use { out -> pdfDoc.writeTo(out) }
            pdfDoc.close()

            val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
            val intent = Intent(Intent.ACTION_SEND).apply {
                type = "application/pdf"
                putExtra(Intent.EXTRA_STREAM, uri)
                putExtra(Intent.EXTRA_TEXT, "Transaction Receipt — Ref: ${tx.reference}")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }
            startActivity(Intent.createChooser(intent, "Share Receipt PDF"))
        } catch (e: Exception) {
            Toast.makeText(context, "Unable to generate PDF", Toast.LENGTH_SHORT).show()
        }
    }

    private fun statusLabel(status: Int) = when (status) {
        1 -> "Successful"; 2 -> "Pending"; 3 -> "Failed"; else -> "Unknown"
    }

    private fun statusEmoji(status: Int) = when (status) { 1 -> "✅"; 2 -> "⏳"; else -> "❌" }

    private fun statusColor(status: Int) = when (status) {
        1 -> Color.parseColor("#2E7D32")
        2 -> Color.parseColor("#F9A825")
        else -> Color.parseColor("#C62828")
    }
}
