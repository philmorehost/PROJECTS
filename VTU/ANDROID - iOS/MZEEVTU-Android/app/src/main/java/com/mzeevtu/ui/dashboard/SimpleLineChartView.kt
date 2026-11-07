package com.mzeevtu.ui.dashboard

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.util.AttributeSet
import android.view.View

class SimpleLineChartView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0
) : View(context, attrs, defStyleAttr) {

    private val linePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#6366F1")
        style = Paint.Style.STROKE
        strokeWidth = 6f
        strokeCap = Paint.Cap.ROUND
    }

    private val gridPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#334155")
        style = Paint.Style.STROKE
        strokeWidth = 2f
    }

    private val pointPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#10B981")
        style = Paint.Style.FILL
    }

    private var dataPoints: List<Float> = listOf(50f, 80f, 40f, 65f) // Default dummy coordinates

    fun setData(points: List<Float>) {
        if (points.size >= 2) {
            dataPoints = points
            invalidate()
        }
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val w = width.toFloat()
        val h = height.toFloat()

        if (w <= 0 || h <= 0) return

        // Draw horizontal grid lines
        for (i in 1..3) {
            val gridY = h * i / 4
            canvas.drawLine(0f, gridY, w, gridY, gridPaint)
        }

        val stepX = w / (dataPoints.size - 1)
        val maxVal = dataPoints.maxOrNull() ?: 100f
        val minVal = dataPoints.minOrNull() ?: 0f
        val delta = if (maxVal - minVal == 0f) 1f else maxVal - minVal

        val path = Path()
        for ((idx, value) in dataPoints.withIndex()) {
            // Map value to layout space (leave some margins top and bottom)
            val pct = (value - minVal) / delta
            val py = h - (pct * (h * 0.7f) + (h * 0.15f))
            val px = idx * stepX

            if (idx == 0) {
                path.moveTo(px, py)
            } else {
                path.lineTo(px, py)
            }
        }

        // Draw line chart
        canvas.drawPath(path, linePaint)

        // Draw point dots
        for ((idx, value) in dataPoints.withIndex()) {
            val pct = (value - minVal) / delta
            val py = h - (pct * (h * 0.7f) + (h * 0.15f))
            val px = idx * stepX
            canvas.drawCircle(px, py, 12f, pointPaint)
        }
    }
}
