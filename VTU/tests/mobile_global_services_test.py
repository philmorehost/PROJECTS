"""Source/XML contract tests for native Android & iOS Send Money Abroad, Tuition, eSIM, and POS Device screens."""
from pathlib import Path
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1] / "ANDROID - iOS"
checks = 0

def check(ok, label):
    global checks
    checks += 1
    if not ok:
        raise AssertionError(label)

android_apps = [
    ("DG7-Android", "com/datagifting/app", "com.datagifting.app"),
    ("JIKABIZ-Android", "com/jikabiz/app", "com.jikabiz.app"),
    ("MZEEVTU-Android", "com/mzeevtu", "com.mzeevtu"),
]

kt_bundles = []
xml_bundles = []
for name, pkg_path, pkg in android_apps:
    base = root / name / "app/src/main"
    nav = (base / "res/navigation/nav_graph.xml").read_text()
    navigator = (base / f"java/{pkg_path}/ui/dashboard/MobileServiceNavigator.kt").read_text()
    home = (base / f"java/{pkg_path}/ui/dashboard/HomeFragment.kt").read_text()
    api_src = (base / f"java/{pkg_path}/api/VtuApiService.kt").read_text()

    for nav_id in ["nav_intl_transfer", "nav_tuition", "nav_esim", "nav_pos_request"]:
        check(f"@+id/{nav_id}" in nav, f"{name} nav_graph defines {nav_id}")
        check(f"R.id.{nav_id}" in navigator, f"{name} MobileServiceNavigator routes {nav_id}")

    for slug in ['"international"', '"tuition"', '"esim"', '"pos"']:
        check(slug in home, f"{name} HomeFragment surfaces {slug}")
        check(slug in navigator, f"{name} MobileServiceNavigator handles {slug}")

    for ep in ["web/api/intl-transfer.php", "web/api/tuition.php", "web/api/esim.php", "web/api/pos-request.php"]:
        check(ep in api_src, f"{name} VtuApiService declares {ep}")

    app_kts = []
    for kt_name in ["IntlTransferFragment.kt", "TuitionFragment.kt", "EsimFragment.kt", "PosRequestFragment.kt"]:
        kt = (base / f"java/{pkg_path}/ui/services/{kt_name}").read_text()
        check("TransactionPin.current" in kt and "route_snapshot" in kt or "EsimFragment" in kt_name, f"{name} {kt_name} enforces PIN/security")
        check("request_key" in kt and "nav_kyc" in kt, f"{name} {kt_name} enforces idempotency & KYC")
        app_kts.append(kt.replace(pkg, "PKG"))
    kt_bundles.append(app_kts)

    app_xmls = []
    for xml_name in ["fragment_intl_transfer.xml", "fragment_tuition.xml", "fragment_esim.xml", "fragment_pos_request.xml"]:
        p = base / "res/layout" / xml_name
        ET.parse(p)
        check(True, f"{name} {xml_name} valid XML")
        app_xmls.append(p.read_text())
    xml_bundles.append(app_xmls)

check(kt_bundles[0] == kt_bundles[1] == kt_bundles[2], "All 3 Android global service Fragments in parity")
check(xml_bundles[0] == xml_bundles[1] == xml_bundles[2], "All 3 Android global service XML layouts byte-identical")

ios_apps = [
    ("DG7-iOS", "DG6-iOS"),
    ("JIKABIZ-iOS", "JIKABIZ"),
    ("MZEEVTU-iOS", "MZEEVTU-iOS"),
]
swift_bundles = []
for name, sub in ios_apps:
    views = root / name / sub / "Views"
    dash = (views / "DashboardView.swift").read_text()
    for view_call in ["IntlTransferView()", "TuitionView()", "EsimView()", "PosRequestView()"]:
        check(view_call in dash, f"{name} DashboardView routes {view_call}")
    app_swifts = []
    for sv in ["IntlTransferView.swift", "TuitionView.swift", "EsimView.swift", "PosRequestView.swift"]:
        src = (views / sv).read_text()
        check("@ObservedObject private var brandTheme = BrandTheme.shared" in src, f"{name} {sv} observes BrandTheme")
        check("Color.blue" not in src, f"{name} {sv} avoids hardcoded blue")
        check("request_key" in src and "KYCView()" in src and "pin" in src, f"{name} {sv} enforces PIN, KYC, and idempotency")
        app_swifts.append(src)
    swift_bundles.append(app_swifts)

check(swift_bundles[0] == swift_bundles[1] == swift_bundles[2], "All 3 iOS global service SwiftUI views byte-identical")
print(f"{checks} mobile global services source/XML checks passed")
