<#
.SYNOPSIS
    Checks a built APK/AAB for the two things the Play Console rejects at upload time:
    the signing certificate, and 16 KB native-library page alignment.

.DESCRIPTION
    Play refuses a release for two "hard" reasons this script can detect locally:

      1. Wrong signing key - the bundle must be signed with the same upload key that
         signed the app already published under that package name.
         ("Your Android App Bundle is signed with the wrong key")

      2. 16 KB page size - every native .so must be uncompressed and aligned to 16384
         bytes. AGP below 8.5.1 does not do this.
         ("Your app does not support 16 KB memory page sizes")

    Private signing keys can never be downloaded from Google Play - only the public
    certificate and its fingerprints. Download the certificate from Play Console
    (Setup -> App integrity -> App signing) and pass it with -Certificate.

.EXAMPLE
    .\verify-play-signing.ps1 -Apk ..\release-output\DG7\DG7-1.0.7-vc8-release.apk

.EXAMPLE
    .\verify-play-signing.ps1 -Keystore C:\Users\User\play-signing-keys\dg7-upload.jks -StorePassword *** -ExpectedSha1 "1E:92:..."

.EXAMPLE
    .\verify-play-signing.ps1 -Bundle ..\release-output\MZEEVTU\MZEEVTU-1.0.12-vc13-release.aab -ExpectedSha256 "64:53:..."
#>
[CmdletBinding(DefaultParameterSetName = 'Artifact')]
param(
    [Parameter(ParameterSetName = 'Artifact')]
    [string] $Apk,

    [Parameter(ParameterSetName = 'Artifact')]
    [string] $Bundle,

    [Parameter(ParameterSetName = 'Keystore', Mandatory = $true)]
    [string] $Keystore,

    [Parameter(ParameterSetName = 'Certificate', Mandatory = $true)]
    [string] $Certificate,

    [string] $StorePassword,
    [string] $KeyAlias,
    [string] $CompareWith,
    [string] $ExpectedSha256,
    [string] $ExpectedSha1,

    [switch] $SkipAlignment
)

$ErrorActionPreference = 'Stop'

function Find-AndroidSdk {
    foreach ($candidate in @($env:ANDROID_HOME, $env:ANDROID_SDK_ROOT,
                            (Join-Path $env:LOCALAPPDATA 'Android\Sdk'))) {
        if ($candidate -and (Test-Path $candidate)) { return $candidate }
    }
    return $null
}

function Find-BuildTool([string] $sdk, [string] $name) {
    if (-not $sdk) { return $null }
    $root = Join-Path $sdk 'build-tools'
    if (-not (Test-Path $root)) { return $null }
    foreach ($v in (Get-ChildItem $root -Directory | Sort-Object Name -Descending)) {
        foreach ($ext in @('.bat', '.exe', '')) {
            $p = Join-Path $v.FullName "$name$ext"
            if (Test-Path $p) { return $p }
        }
    }
    return $null
}

function Find-Keytool {
    if ($env:JAVA_HOME) {
        $p = Join-Path $env:JAVA_HOME 'bin\keytool.exe'
        if (Test-Path $p) { return $p }
    }
    $cmd = Get-Command keytool.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($root in @('C:\Program Files\Java', 'C:\Program Files\Eclipse Adoptium',
                        'C:\Program Files\Microsoft', 'C:\Program Files\Android\Android Studio')) {
        if (-not (Test-Path $root)) { continue }
        $found = Get-ChildItem $root -Recurse -Filter 'keytool.exe' -ErrorAction SilentlyContinue |
                 Select-Object -First 1
        if ($found) { return $found.FullName }
    }
    throw 'keytool not found. Install a JDK and set JAVA_HOME.'
}

function Normalize-Hex([string] $hex) {
    $raw = ($hex -replace '[^0-9A-Fa-f]', '').ToUpperInvariant()
    return (($raw -split '(.{2})' | Where-Object { $_ }) -join ':')
}

# Native tools write warnings to stderr; with $ErrorActionPreference='Stop' that becomes
# terminating, so every external call goes through here.
function Invoke-Native {
    param([string] $File, [string[]] $Arguments)
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $File @Arguments 2>&1
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    return [pscustomobject]@{ Output = @($output | ForEach-Object { "$_" }); ExitCode = $code }
}

$SHA256_LABEL = '(?i)sha-?256'
$SHA1_LABEL   = '(?i)sha-?1\b|certificate sha-1 digest'

# Fingerprints appear in two shapes: keytool's "A4:25:E6:..." (colon separated) and
# apksigner's raw 64-hex lowercase digest. Parse per line so a label never eats a hex char.
function Get-DigestFromText {
    param([string] $Text, [string] $LabelPattern, [int] $HexDigits)

    foreach ($line in ($Text -split "`r?`n")) {
        if ($line -notmatch $LabelPattern) { continue }
        $m = [regex]::Match($line, "(?i)(?:[0-9a-f]{2}:){$($HexDigits - 1)}[0-9a-f]{2}\b")
        if (-not $m.Success) { $m = [regex]::Match($line, "(?i)\b[0-9a-f]{$HexDigits}\b") }
        if ($m.Success) { return (Normalize-Hex $m.Value) }
    }
    return $null
}

function Get-Fingerprint {
    param([string] $Path, [string] $Password, [string] $Alias, [string] $Label)

    if (-not (Test-Path $Path)) { throw "$Label not found: $Path" }
    $ext = [IO.Path]::GetExtension($Path).ToLowerInvariant()
    $sdk = Find-AndroidSdk

    if ($ext -eq '.apk') {
        $apksigner = Find-BuildTool $sdk 'apksigner'
        if (-not $apksigner) { throw 'apksigner not found in the Android SDK build-tools.' }
        $r = Invoke-Native $apksigner @('verify', '--print-certs', $Path)
        if ($r.ExitCode -ne 0) { throw "apksigner verify failed for ${Path}:`n$($r.Output -join "`n")" }
        $text = $r.Output -join "`n"
        Write-Host "${Label}: $Path"
        foreach ($line in ($r.Output | Where-Object { $_ -match 'certificate (DN|SHA-256)' })) {
            Write-Host "  $($line.Trim())"
        }
    }
    elseif ($ext -eq '.keystore') {
        $tmp = Join-Path ([IO.Path]::GetTempPath()) ('ks-' + [guid]::NewGuid().ToString('N') + '.jks')
        Copy-Item $Path $tmp
        try { return (Get-Fingerprint -Path $tmp -Password $Password -Alias $Alias -Label $Label) }
        finally { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
    }
    else {
        $keytool = Find-Keytool
        $arguments = if ($ext -eq '.jks') {
            $a = @('-list', '-v', '-keystore', $Path)
            if ($Password) { $a += @('-storepass', $Password) }
            if ($Alias) { $a += @('-alias', $Alias) }
            $a
        }
        elseif ($ext -in @('.aab', '.apks')) { @('-printcert', '-jarfile', $Path) }
        else { @('-printcert', '-file', $Path) }

        $r = Invoke-Native $keytool $arguments
        if ($r.ExitCode -ne 0) {
            throw "keytool could not read ${Path} (wrong password, or not a release keystore):`n$($r.Output -join "`n")"
        }
        $text = $r.Output -join "`n"
        Write-Host "${Label}: $Path"
        foreach ($line in ($r.Output | Where-Object { $_ -match '^Alias name:|^Owner:|^SHA256:|^SHA1:' })) {
            Write-Host "  $($line.Trim())"
        }
    }

    $sha256 = Get-DigestFromText -Text $text -LabelPattern $SHA256_LABEL -HexDigits 64
    if (-not $sha256) { throw "No SHA-256 fingerprint found for ${Path}." }
    $script:LastSha1 = Get-DigestFromText -Text $text -LabelPattern $SHA1_LABEL -HexDigits 40
    return $sha256
}

# Play refuses bundles whose native libraries are not aligned for 16 KB memory pages.
function Test-PageAlignment {
    param([string] $ApkPath)

    $sdk = Find-AndroidSdk
    $zipalign = Find-BuildTool $sdk 'zipalign'
    if (-not $zipalign) {
        Write-Host ''
        Write-Host 'Alignment: zipalign not found, skipped.'
        return 0
    }

    $r = Invoke-Native $zipalign @('-c', '-P', '16', '4', $ApkPath)
    Write-Host ''
    if ($r.ExitCode -eq 0) {
        Write-Host 'Alignment: OK - every native library is 16 KB aligned (Play requirement).'
        return 0
    }

    Write-Host 'Alignment: FAILED - Play will reject this bundle with'
    Write-Host '           "Your app does not support 16 KB memory page sizes".'
    foreach ($line in ($r.Output | Where-Object { $_ -match '\(BAD' })) {
        Write-Host "  $($line.Trim())"
    }
    return 1
}

if ($Apk) { $primary = @{ Path = $Apk; Password = ''; Alias = ''; Label = 'APK' } }
elseif ($Bundle) { $primary = @{ Path = $Bundle; Password = ''; Alias = ''; Label = 'AAB' } }
elseif ($Keystore) { $primary = @{ Path = $Keystore; Password = $StorePassword; Alias = $KeyAlias; Label = 'Keystore' } }
elseif ($Certificate) { $primary = @{ Path = $Certificate; Password = ''; Alias = ''; Label = 'Certificate' } }
else { throw 'Pass -Apk, -Bundle, -Keystore or -Certificate.' }

$fingerprint = Get-Fingerprint @primary
$sha1Found = $script:LastSha1

Write-Host ''
if ($sha1Found) { Write-Host "SHA-1    : $sha1Found" }
Write-Host "SHA-256  : $fingerprint"

$exitCode = 0

if ($CompareWith) {
    $other = Get-Fingerprint -Path $CompareWith -Password $StorePassword -Alias $KeyAlias -Label 'Compare'
    Write-Host ''
    Write-Host "Compare  : $other"
    if ($fingerprint -eq $other) { Write-Host 'MATCH    : same signing certificate.' }
    else {
        Write-Host 'MISMATCH : different signing keys - Play will reject the upload.'
        $exitCode = 1
    }
}

if ($ExpectedSha256) {
    $expected = Normalize-Hex $ExpectedSha256
    if ($fingerprint -eq $expected) { Write-Host 'MATCH    : signed with the expected Play certificate (SHA-256).' }
    else {
        Write-Host 'MISMATCH : NOT signed with the expected Play certificate (SHA-256).'
        Write-Host "Expected : $expected"
        $exitCode = 1
    }
}

if ($ExpectedSha1) {
    $expected1 = Normalize-Hex $ExpectedSha1
    if (-not $sha1Found) {
        Write-Host 'WARNING  : could not extract a SHA-1 fingerprint from this input.'
        $exitCode = 1
    }
    elseif ($sha1Found -eq $expected1) { Write-Host 'MATCH    : signed with the expected Play certificate (SHA-1).' }
    else {
        Write-Host 'MISMATCH : NOT signed with the expected Play certificate (SHA-1).'
        Write-Host "Expected : $expected1"
        Write-Host "Actual   : $sha1Found"
        $exitCode = 1
    }
}

if ($Apk -and -not $SkipAlignment) {
    $alignExit = Test-PageAlignment $Apk
    if ($alignExit -ne 0) { $exitCode = 1 }
}

if ($exitCode -ne 0) { exit $exitCode }
