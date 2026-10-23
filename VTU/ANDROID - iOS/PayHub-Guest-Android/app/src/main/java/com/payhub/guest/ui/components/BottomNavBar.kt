package com.payhub.guest.ui.components

import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.spring
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.GridView
import androidx.compose.material.icons.filled.Headset
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.Home
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.BlendMode
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.CompositingStrategy
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.payhub.guest.ui.theme.CBg

enum class GuestTab(val route: String, val icon: ImageVector) {
    Home("home", Icons.Filled.Home),
    Services("services", Icons.Filled.GridView),
    History("history", Icons.Filled.History),
    Support("support", Icons.Filled.Headset),
}

/** Dark bar background + gradient bubble colors, matching scratch/payhub-guest-app-mockup.html
 *  exactly (#0b0b0f bar, #8b5cf6 -> #3b82f6 bubble) — intentionally not theme-driven colors. */
private val NavBarBg = Color(0xFF0B0B0F)
private val BubbleStart = Color(0xFF8B5CF6)
private val BubbleEnd = Color(0xFF3B82F6)
private val BubbleGlow = Color(0xFF6366F1)

/**
 * The dark floating pill with an animated circular notch punched into its top edge at the
 * active tab's x-position. Reproduces the mockup's `mask-image: radial-gradient(...)` notch
 * using Compose's BlendMode.Clear inside an offscreen compositing layer (the direct analog —
 * BlendMode.Clear only punches a real hole when the draw happens in its own graphics layer,
 * hence the CompositingStrategy.Offscreen).
 *
 * `notchX` is computed by the caller (GuestNavHost.kt) via BoxWithConstraints so the bar and
 * the separate floating bubble (GuestNavBubble) always agree on the same x-coordinate.
 */
@Composable
fun BottomNavBar(current: GuestTab, notchX: Dp, onSelect: (GuestTab) -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(62.dp)
            .shadow(14.dp, RoundedCornerShape(31.dp))
            .graphicsLayer { compositingStrategy = CompositingStrategy.Offscreen }
            .drawWithContent {
                drawContent()
                drawCircle(
                    color = Color.Black,
                    radius = 30.dp.toPx(),
                    center = Offset(notchX.toPx(), -6.dp.toPx()),
                    blendMode = BlendMode.Clear,
                )
            }
            .background(NavBarBg, RoundedCornerShape(31.dp)),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().height(62.dp),
            horizontalArrangement = Arrangement.SpaceEvenly,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            GuestTab.values().forEach { tab ->
                val selected = tab == current
                Box(
                    modifier = Modifier
                        .size(48.dp)
                        .clickable { onSelect(tab) },
                    contentAlignment = Alignment.Center,
                ) {
                    // The active tab's icon is hidden here (mockup: opacity:0) since the floating
                    // bubble shows it instead — kept in layout (not removed) for stable spacing.
                    Icon(
                        imageVector = tab.icon,
                        contentDescription = tab.name,
                        tint = Color.White,
                        modifier = Modifier.graphicsLayer { alpha = if (selected) 0f else 1f },
                    )
                }
            }
        }
    }
}

/**
 * The floating gradient bubble showing the active tab's icon. Must be rendered as a SIBLING of
 * BottomNavBar (same parent BoxWithConstraints in GuestNavHost.kt), never nested inside it —
 * otherwise the bar's own notch-punch compositing would clip the bubble too, exactly the bug
 * the mockup's own CSS comment warns about for `.nav-bubble`. Decorative only: no click handling,
 * matching the mockup's `pointer-events:none` (taps pass through to the real tab button beneath).
 */
@Composable
fun GuestNavBubble(current: GuestTab, bubbleX: Dp) {
    Box(
        modifier = Modifier
            .offset(x = bubbleX - 25.dp, y = (-10).dp)
            .size(50.dp)
            .shadow(10.dp, CircleShape, ambientColor = BubbleGlow, spotColor = BubbleGlow)
            .background(Brush.linearGradient(listOf(BubbleStart, BubbleEnd)), CircleShape)
            .border(5.dp, CBg, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Icon(imageVector = current.icon, contentDescription = null, tint = Color.White)
    }
}

/** Thin decorative bar under the floating nav, matching the mockup's iOS-style home indicator. */
@Composable
fun GuestHomeIndicator() {
    Box(
        modifier = Modifier
            .size(width = 120.dp, height = 4.dp)
            .background(Color.Black.copy(alpha = 0.25f), RoundedCornerShape(4.dp))
    )
}

/** Spring spec approximating the mockup's cubic-bezier(.34,1.56,.64,1) overshoot easing. */
val GuestNavSpringSpec = spring<Dp>(dampingRatio = 0.55f, stiffness = 380f)
