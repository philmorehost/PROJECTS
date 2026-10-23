package com.payhub.guest.data

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Bolt
import androidx.compose.material.icons.filled.Casino
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.School
import androidx.compose.material.icons.filled.Tv
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import com.payhub.guest.ui.theme.CAirtime
import com.payhub.guest.ui.theme.CAirtimeBg
import com.payhub.guest.ui.theme.CBetting
import com.payhub.guest.ui.theme.CBettingBg
import com.payhub.guest.ui.theme.CCable
import com.payhub.guest.ui.theme.CCableBg
import com.payhub.guest.ui.theme.CData
import com.payhub.guest.ui.theme.CDataBg
import com.payhub.guest.ui.theme.CElectric
import com.payhub.guest.ui.theme.CElectricBg
import com.payhub.guest.ui.theme.CExam
import com.payhub.guest.ui.theme.CExamBg

data class GuestServiceEntry(
    /** App-internal navigation key (used by onOpenService/loadCatalog's own switch). */
    val key: String,
    /** Key as stored in sas_service_control / catalog.php's `service=` param — only differs
     *  from [key] for electricity ("electricity" navigation key vs "electric" backend key). */
    val serviceControlKey: String,
    val title: String,
    /** Terser label for HomeScreen's compact quick-action tiles (e.g. "Electric" vs "Electricity"). */
    val shortLabel: String,
    val caption: String,
    val icon: ImageVector,
    val color: Color,
    val bg: Color,
)

/**
 * Single source of truth for the guest app's service tiles — previously ServicesScreen.kt and
 * HomeScreen.kt each hardcoded their own separate list, which would silently drift. Both screens
 * now import ALL and filter it against the site-info.php-driven enabledServices map.
 */
object GuestServiceCatalog {
    val ALL = listOf(
        GuestServiceEntry("airtime", "airtime", "Airtime", "Airtime", "Top up any network", Icons.Filled.Phone, CAirtime, CAirtimeBg),
        GuestServiceEntry("data", "data", "Data Bundle", "Data", "SME, Shared, CG & Direct", Icons.Filled.Wifi, CData, CDataBg),
        GuestServiceEntry("cable", "cable", "Cable TV", "Cable TV", "DStv, GOtv & more", Icons.Filled.Tv, CCable, CCableBg),
        GuestServiceEntry("electricity", "electric", "Electricity", "Electric", "Prepaid & postpaid", Icons.Filled.Bolt, CElectric, CElectricBg),
        GuestServiceEntry("exam", "exam", "Exam Pins", "Exam Pins", "WAEC, NECO & more", Icons.Filled.School, CExam, CExamBg),
        GuestServiceEntry("betting", "betting", "Betting", "Betting", "Fund your wallet", Icons.Filled.Casino, CBetting, CBettingBg),
    )

    /** A service key absent from [enabledServices] is treated as enabled — mirrors
     *  isServiceEnabled()'s own default on the backend (no row = not yet configured, not disabled). */
    fun filterEnabled(enabledServices: Map<String, Int>): List<GuestServiceEntry> =
        ALL.filter { (enabledServices[it.serviceControlKey] ?: 1) != 0 }
}
