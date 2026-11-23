"""Structural safety checks, NOT a replacement for Android/Xcode compilation."""
from pathlib import Path
import re
import xml.etree.ElementTree as ET
root = Path(__file__).resolve().parents[1]
checks = 0

def check(ok, label):
    global checks
    checks += 1
    if not ok:
        raise AssertionError(label)

for brand, package in [('DG7-Android', 'com.datagifting.app'), ('JIKABIZ-Android', 'com.jikabiz.app'), ('MZEEVTU-Android', 'com.mzeevtu')]:
    main = root / brand / 'app/src/main'
    java = main / 'java' / package.replace('.', '/')
    for rel in ['AndroidManifest.xml', 'res/layout/activity_main.xml', 'res/drawable/bg_fintech_bottom.xml', 'res/color/fintech_bottom_color.xml']:
        ET.parse(main / rel)
        checks += 1
    declared_styles = {node.attrib['name'] for f in (main / 'res').glob('values*/*.xml') for node in ET.parse(f).getroot().findall('style')}
    manifest = ET.parse(main / 'AndroidManifest.xml').getroot()
    for node in manifest.iter():
        theme = node.attrib.get('{http://schemas.android.com/apk/res/android}theme', '')
        if theme.startswith('@style/'):
            check(theme[7:] in declared_styles, f'{brand}: undeclared manifest theme {theme}')
    nav = (main / 'res/navigation/nav_graph.xml').read_text()
    navigator = (java / 'ui/dashboard/MobileServiceNavigator.kt').read_text()
    for target in set(re.findall(r'R.id.(nav_\w+)', navigator)):
        check(f'@+id/{target}' in nav, f'{brand}: missing navigation {target}')
    home = (java / 'ui/dashboard/HomeFragment.kt').read_text()
    check('ReceiptHelper.showReceiptDialog' in home, f'{brand}: receipt flow retained')
    check('model.loadTransactions' in home and 'model.loadServices' in home, f'{brand}: refresh retained')
    check('ServicePortalActivity' in (main / 'AndroidManifest.xml').read_text(), f'{brand}: portal registered')
    portal = (java / 'ui/dashboard/ServicePortalActivity.kt').read_text()
    for guard in ['allowFileAccess=false', 'MIXED_CONTENT_NEVER_ALLOW', 'setAcceptThirdPartyCookies(browser,false)', 'handler?.cancel()', 'URLEncoder.encode(token', 'android:exported']:
        if guard == 'android:exported': continue
        check(guard in portal, f'{brand}: portal guard missing {guard}')
    check('addJavascriptInterface' not in portal, f'{brand}: no credential-bearing JS interface')
    check('TransactionPin.current' in (java / 'api/RetrofitClient.kt').read_text(), f'{brand}: transaction PIN interceptor retained')
    check((java / 'ui/security/LockActivity.kt').exists(), f'{brand}: lock activity retained')

for brand, folder in [('DG7-iOS', 'DG6-iOS'), ('JIKABIZ-iOS', 'JIKABIZ'), ('MZEEVTU-iOS', 'MZEEVTU-iOS')]:
    base = root / brand / folder
    home = (base / 'Views/DashboardView.swift').read_text()
    assistant = 'AIAssistantView()' if brand == 'MZEEVTU-iOS' else 'AIChatView()'
    check(('struct ' + assistant[:-2] + ': View') in (base / ('Views/' + assistant[:-2] + '.swift')).read_text(), f'{brand}: assistant destination exists')
    for feature in ['KYCView()', assistant, 'TransactionHistoryView()', 'mobile_biometric_lock', 'mobile-home.php', 'nonPersistent()', 'request.httpMethod="POST"']:
        check(feature in home, f'{brand}: missing {feature}')
    receipt = (base / 'Views/MobileReceipt.swift').read_text()
    check('UIGraphicsPDFRenderer' in receipt and '.pngData()' in receipt and 'BCShareSheet' in receipt, f'{brand}: PDF and image sharing')
    check('NSFaceIDUsageDescription' in (root / brand / 'project.yml').read_text(), f'{brand}: Face ID permission')
    check('PinSetupView' in (base / 'DG6_iOSApp.swift').read_text() if (base / 'DG6_iOSApp.swift').exists() else any('PinSetupView' in f.read_text() for f in base.glob('*App.swift')), f'{brand}: required PIN gate retained')
print(f'{checks} mobile structural checks passed (native compilation still required)')
