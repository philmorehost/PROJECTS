"""Source/XML contract tests for Android and iOS A2P/P2A CryptoHub."""
from pathlib import Path
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1] / "ANDROID - iOS"
checks = 0

def check(ok, label):
    global checks
    assert ok, label
    checks += 1

android_apps = [
    ("DG7-Android", "com/datagifting/app", "com.datagifting.app"),
    ("JIKABIZ-Android", "com/jikabiz/app", "com.jikabiz.app"),
    ("MZEEVTU-Android", "com/mzeevtu", "com.mzeevtu"),
]

norm_kt = []
xmls = []
for name, pkg_path, pkg in android_apps:
    base = root / name / "app/src/main"
    kt = (base / f"java/{pkg_path}/ui/services/CryptoFragment.kt").read_text()
    xml_path = base / "res/layout/fragment_crypto.xml"
    xml = xml_path.read_text()
    svc_xml = (base / "res/layout/fragment_services.xml").read_text()
    wal_xml = (base / "res/layout/fragment_wallet.xml").read_text()

    ET.parse(xml_path)
    check(True, f"{name} fragment_crypto.xml valid XML")
    ET.parse(base / "res/layout/fragment_services.xml")
    ET.parse(base / "res/layout/fragment_wallet.xml")

    norm_kt.append(kt.replace(pkg, "PKG"))
    xmls.append(xml)

    for tok in ['"a2p_desk"', '"a2p_buy"', '"p2a_sell"', '"a2p_swap"', 'R.id.nav_withdraw', '"international"', 'R.id.nav_kyc', 'R.id.nav_set_pin']:
        check(tok in kt, f"{name} CryptoFragment.kt wires {tok}")
    for retired in ['"create_invoice"', '"get_rate"', '"action" to "withdraw"', '"action" to "swap"', 'buy_markup_pct', 'sell_markdown_pct']:
        check(retired not in kt, f"{name} CryptoFragment.kt removes retired/hidden field {retired}")
    for vid in ['btn_tab_buy', 'btn_tab_sell', 'btn_tab_swap', 'panel_buy', 'panel_sell', 'panel_swap', 'btn_withdraw_local', 'btn_withdraw_intl', 'card_admin_deposit', 'card_kyc_notice']:
        check(vid in xml, f"{name} fragment_crypto.xml includes {vid}")
    check("Buy, sell &amp; swap crypto" in svc_xml, f"{name} fragment_services.xml updated")
    check("Buy, sell &amp; swap crypto" in wal_xml, f"{name} fragment_wallet.xml updated")

check(norm_kt[0] == norm_kt[1] == norm_kt[2], "All 3 Android CryptoFragment.kt implementations in parity")
check(xmls[0] == xmls[1] == xmls[2], "All 3 Android fragment_crypto.xml layouts byte-identical")

ios_apps = [
    ("DG7-iOS", "DG6-iOS"),
    ("JIKABIZ-iOS", "JIKABIZ"),
    ("MZEEVTU-iOS", "MZEEVTU-iOS"),
]
for name, sub in ios_apps:
    dash = (root / name / sub / "Views/DashboardView.swift").read_text()
    check('shortcut("Crypto", "bitcoinsign.circle", "crypto")' in dash, f"{name} routes crypto via MobilePortalView")
    check('.sheet(item: $portal, onDismiss: { reload() })' in dash, f"{name} reloads dashboard on portal dismiss")

print(f"{checks} mobile CryptoHub A2P/P2A source/XML checks passed")
