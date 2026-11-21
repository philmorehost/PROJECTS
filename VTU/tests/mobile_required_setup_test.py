"""Source/XML contracts for all shipped clients. Not a substitute for native builds/device tests."""
from pathlib import Path
import xml.etree.ElementTree as ET
root=Path(__file__).resolve().parents[1]/'ANDROID - iOS'
n=0
def check(ok,label):
    global n
    assert ok,label
    n+=1
android=[]
for name,pkg in [('DG7-Android','com.datagifting.app'),('JIKABIZ-Android','com.jikabiz.app'),('MZEEVTU-Android','com.mzeevtu')]:
    base=root/name/'app/src/main';java=base/'java'/Path(*pkg.split('.'))
    gate=(java/'util/RequiredSetup.kt').read_text();android.append(gate.replace(pkg,'PACKAGE'))
    for code in ['AUTHENTICATOR_SETUP_REQUIRED','PASSWORD_CHANGE_REQUIRED']:
        check(code in gate,name+' recognizes '+code)
    for token in ['response.code != 403','peekBody(8192)','Intent.FLAG_ACTIVITY_CLEAR_TASK','required-setup:', 'saveBoolean(Constants.KEY_IS_LOGGED_IN, false)','saveString(Constants.KEY_API_KEY, "")','onBackPressedDispatcher','OnBackPressedCallback(true)','Intent.ACTION_VIEW','/web/SecuritySetup.php','url.scheme == "https"']:
        check(token in gate,name+' '+token)
    check('setup_url' not in gate and 'response.body?.string()' not in gate,name+' does not trust redirect URLs or consume response body')
    check('KEY_BIOMETRIC_ENABLED' not in gate and 'KEY_PIN_SET' not in gate,name+' preserves PIN and biometric preferences')
    check('RequiredSetup.inspect(it)' in (java/'api/RetrofitClient.kt').read_text(),name+' global API interceptor')
    check('RequiredSetup.install(this)' in (java/'DGApp.kt').read_text(),name+' gate installed')
    theme=(java/'util/BrandTheme.kt').read_text()
    check('RequiredSetup.route(activity)' in theme,name+' gate on resume')
    check('"primary" else "surface"' in theme and 'setBackgroundDrawable' in theme,name+' splash bars/window use brand primary')
    check('fun acceptSiteTheme' in theme and 'if (!valid(data)) return' in theme,name+' palette validated before caching')
    splash=(java/'ui/splash/SplashActivity.kt').read_text()
    check('BrandTheme.applyNow(this)' in splash,name+' synchronous cached theme before startup network')
    check('acceptSiteTheme(org.json.JSONObject(palette))' in splash,name+' existing site-info response refreshes splash palette')
    check('getProfile(mapOf("api_key" to prefs.getApiKey()))' in splash and 'policyCheck.await()' in splash,name+' returning sessions revalidated')
    check('withTimeoutOrNull(5000)' in splash and 'val policyCheck = async' in splash,name+' bounded parallel startup check')
    tree=ET.parse(base/'AndroidManifest.xml');ns='{http://schemas.android.com/apk/res/android}'
    activity=next(a for a in tree.findall('./application/activity') if a.get(ns+'name')=='.util.RequiredSetupActivity')
    check(activity.get(ns+'exported')=='false',name+' setup activity private')
    tree=ET.parse(base/'res/layout/activity_splash.xml')
    check(tree.getroot().get(ns+'tag')=='brand:bg=primary',name+' splash canvas uses primary')
    for node in tree.iter('TextView'):check(node.get(ns+'tag')=='brand:text=on_primary',name+' readable splash text for light/dark brands')
check(len(set(android))==1,'Android enforcement parity')
ios=[]
for name,folder in [('DG7-iOS','DG6-iOS'),('JIKABIZ-iOS','JIKABIZ'),('MZEEVTU-iOS','MZEEVTU-iOS')]:
    base=root/name/folder;network=(base/'Services/AppNetworkService.swift').read_text()
    gate=network[network.index('// MARK: Required security setup'):network.index('// MARK: - Tenant branding')];ios.append(gate)
    check('AUTHENTICATOR_SETUP_REQUIRED' in network and 'PASSWORD_CHANGE_REQUIRED' in network,name+' both codes recognized')
    check(network.count('if self.inspectRequiredSetup(data)')==network.count('URLSession.shared.dataTask(with: request)'),name+' all common response paths intercepted')
    check('AppNetworkService.shared.apiKey = nil' in gate and 'required-setup:' in gate,name+' persistent block and session invalidation')
    check('setup_url' not in gate and 'relativeTo: URL(string: AppNetworkService.shared.mobileOrigin)' in gate,name+' trusted website destination')
    check('.background(theme.primary.ignoresSafeArea())' in gate and '.foregroundStyle(theme.onPrimary)' in gate,name+' cached brand splash with contrasting ink')
    app=next(p.read_text() for p in base.glob('*App.swift') if '@main' in p.read_text())
    check(app.index('if requiredSetup.blocked')<app.index('else if checkingSession')<app.index('else if !isLoggedIn'),name+' gate replaces entire dashboard/auth tree')
    check('requestObject("profile.php", params: [:])' in app and 'guard !validatingSession' in app,name+' revalidates live session without duplicate foreground checks')
    check('isLoggedIn = false\n                    requiredSetup.retry()' in app,name+' retry requires fresh sign-in')
check(len(set(ios))==1,'iOS enforcement/splash parity')
print(f'{n} mobile required-setup and splash source/XML checks passed (native compilation/device checks required).')
