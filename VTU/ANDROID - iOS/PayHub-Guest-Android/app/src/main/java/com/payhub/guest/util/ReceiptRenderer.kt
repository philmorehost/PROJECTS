package com.payhub.guest.util

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.pdf.PdfDocument
import android.net.Uri
import androidx.core.content.FileProvider
import com.payhub.guest.data.model.GuestReceipt
import java.io.File
import java.io.FileOutputStream
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Renders a receipt as a simple, readable PDF or PNG for the "no stored history" guest flow —
 * this is the guest's only durable copy of a transaction, so both formats share one drawing
 * routine to keep them consistent.
 */
object ReceiptRenderer {

    private const val W = 720
    private const val H = 1000

    private fun draw(canvas: Canvas, receipt: GuestReceipt) {
        canvas.drawColor(Color.WHITE)
        val title = Paint().apply { color = Color.parseColor("#1E293B"); textSize = 34f; isFakeBoldText = true; isAntiAlias = true }
        val label = Paint().apply { color = Color.parseColor("#64748B"); textSize = 22f; isAntiAlias = true }
        val value = Paint().apply { color = Color.parseColor("#1E293B"); textSize = 22f; isFakeBoldText = true; isAntiAlias = true }
        val brand = Paint().apply { color = Color.parseColor("#0D6EFD"); textSize = 26f; isFakeBoldText = true; isAntiAlias = true }
        val success = Paint().apply { color = Color.parseColor("#22C55E"); textSize = 40f; isFakeBoldText = true; isAntiAlias = true }

        var y = 60f
        canvas.drawText("PayHub", 40f, y, brand)
        y += 70f
        canvas.drawText(if (receipt.status == "success") "✓ Payment Successful" else "Payment ${receipt.status}", 40f, y, success)
        y += 60f
        canvas.drawText("₦${"%,.0f".format(receipt.amountPaid)}", 40f, y, title)
        y += 70f

        val rows = listOfNotNull(
            "Reference" to receipt.reference,
            "Service" to receipt.service.replaceFirstChar(Char::uppercase),
            "Recipient" to receipt.recipient,
            "Amount Paid" to "₦${"%,.0f".format(receipt.amountPaid)}",
            "Date & Time" to SimpleDateFormat("dd MMM yyyy, h:mm a", Locale.getDefault()).format(Date(receipt.dateMillis)),
            "Payment Method" to "PayHub Checkout",
            receipt.token?.let { "Token" to it },
            receipt.meterNumber?.let { "Meter Number" to it },
        )
        rows.forEach { (l, v) ->
            canvas.drawText(l, 40f, y, label)
            canvas.drawText(v, 300f, y, value)
            y += 44f
        }

        y += 40f
        canvas.drawText("Thank you for using PayHub", 40f, y, label)
    }

    fun renderBitmap(receipt: GuestReceipt): Bitmap {
        val bmp = Bitmap.createBitmap(W, H, Bitmap.Config.ARGB_8888)
        draw(Canvas(bmp), receipt)
        return bmp
    }

    fun saveBitmapToCache(context: Context, receipt: GuestReceipt): Uri {
        val dir = File(context.cacheDir, "receipts").apply { mkdirs() }
        val file = File(dir, "receipt_${receipt.reference}.png")
        FileOutputStream(file).use { out -> renderBitmap(receipt).compress(Bitmap.CompressFormat.PNG, 100, out) }
        return FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
    }

    fun savePdfToCache(context: Context, receipt: GuestReceipt): Uri {
        val dir = File(context.cacheDir, "receipts").apply { mkdirs() }
        val file = File(dir, "receipt_${receipt.reference}.pdf")
        val doc = PdfDocument()
        val page = doc.startPage(PdfDocument.PageInfo.Builder(W, H, 1).create())
        draw(page.canvas, receipt)
        doc.finishPage(page)
        FileOutputStream(file).use { out -> doc.writeTo(out) }
        doc.close()
        return FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
    }

    fun shareUri(context: Context, uri: Uri, mimeType: String, targetPackage: String? = null) {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = mimeType
            putExtra(Intent.EXTRA_STREAM, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            targetPackage?.let { setPackage(it) }
        }
        context.startActivity(Intent.createChooser(intent, "Share receipt"))
    }

    fun emailReceipt(context: Context, uri: Uri, receipt: GuestReceipt, toEmail: String) {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "application/pdf"
            putExtra(Intent.EXTRA_EMAIL, arrayOf(toEmail))
            putExtra(Intent.EXTRA_SUBJECT, "Your PayHub Receipt — ${receipt.reference}")
            putExtra(Intent.EXTRA_TEXT, "Attached is your receipt for ${receipt.service} — ₦${"%,.0f".format(receipt.amountPaid)}.")
            putExtra(Intent.EXTRA_STREAM, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(Intent.createChooser(intent, "Send receipt"))
    }
}
