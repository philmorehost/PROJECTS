"""Source/XML contract regressions only; not Android SDK or Xcode builds."""
from pathlib import Path
import re
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1] / 'ANDROID - iOS'
checks = 0

def check(ok, label):
    global checks
    assert ok, label
    checks += 1

managers = []
for name, package in [('DG7-Android', 'com.datagifting.app'), ('JIKABIZ-Android', 'com.jikabiz.app'), ('MZEEVTU-Android', 'com.mzeevtu')]:
    base = root / name / 'app/src/main'
    java = base / 'java' / package.replace('.', '/')
    manager = (java / 'util/BrandTheme.kt').read_text()
    managers.append(manager.replace(package, 'PACKAGE'))
    check('BrandTheme.install(this)' in (java / 'DGApp.kt').read_text(), name + ' installs lifecycle theme')
    for token in ['web/api/theme.php', 'schema_version', 'calculateContrast', '60_000', 'Constants.BASE_URL', 'public_brand_theme', 'followRedirects(false)', 'response.use', 'onActivityDestroyed', 'removeOnGlobalLayoutListener', 'buttonTintList', 'itemIconTintList', 'isAppearanceLightStatusBars']:
        check(token in manager, name + ' ' + token)
    check('api_key' not in manager and 'Authorization' not in manager, name + ' public theme never attaches credentials')
    check('KEY_PRIMARY_COLOR' not in (java / 'ui/splash/SplashActivity.kt').read_text(), name + ' no obsolete splash color cache')
    home = (java / 'ui/dashboard/HomeFragment.kt').read_text()
    check('Color.rgb(255, 120, 57)' not in home and 'intArrayOf(orange, orange)' in home, name + ' dashboard uses API brand, no incompatible gradient')
    check('onPrimary(context)' in home, name + ' graph foreground follows brand')
    check('BrandTheme.applyView' in (java / 'ui/transactions/ReceiptHelper.kt').read_text(), name + ' receipt dialog themed')
    for xml in (base / 'res/layout').glob('*.xml'):
        tree = ET.parse(xml)
        for node in tree.iter():
            tag = node.attrib.get('{http://schemas.android.com/apk/res/android}tag', '')
            if tag.startswith('brand:'):
                roles = dict(part.split('=') for part in tag[6:].split(';'))
                check(set(roles) <= {'bg', 'text', 'icon', 'button'}, str(xml) + ' semantic role names')
        check(True, str(xml) + ' valid XML')
check(managers[0] == managers[1] == managers[2], 'Android theme implementation parity')

managers = []
for name, subdir in [('DG7-iOS', 'DG6-iOS'), ('JIKABIZ-iOS', 'JIKABIZ'), ('MZEEVTU-iOS', 'MZEEVTU-iOS')]:
    base = root / name / subdir
    network = (base / 'Services/AppNetworkService.swift').read_text()
    start = network.index('// MARK: - Tenant branding')
    end = network.index('// No redirects:', start) if '// No redirects:' in network[start:] else len(network)
    manager = network[start:end]
    managers.append(manager)
    for token in ['@MainActor', '@Published', 'schema_version', 'contrast(', 'web/api/theme.php', 'cacheKey', 'httpShouldHandleCookies=false', 'http.url?.host==url.host', 'reloadIgnoringLocalCacheData', 'Last-known-good']:
        check(token in manager, name + ' ' + token)
    check('apiKey' not in manager, name + ' theme request separate from account credentials')
    app = next(p.read_text() for p in base.glob('*.swift') if '@main' in p.read_text())
    check('@StateObject private var brandTheme' in app and 'themeScenePhase == .active' in app, name + ' app observes/refreshes while foregrounded')
    home = (base / 'Views/DashboardView.swift').read_text()
    check('.background(brandTheme.primary)' in home and '.foregroundColor(brandTheme.onPrimary)' in home, name + ' dashboard themed')
    check('Color(red: 1, green: 0.49' not in home, name + ' no hardcoded orange brand')
    for p in base.rglob('*.swift'):
        if p.name == 'AppNetworkService.swift':
            continue
        source = p.read_text()
        check('Color.blue' not in source, str(p) + ' no hardcoded blue brand')
        if 'brandTheme.' in source and '@main' not in source:
            check('@ObservedObject private var brandTheme' in source, str(p) + ' observes live updates')
check(managers[0] == managers[1] == managers[2], 'iOS theme implementation parity')
print(f'{checks} mobile theme source/XML checks passed; native builds/device checks remain required.')
