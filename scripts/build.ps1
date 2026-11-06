param([string]$Version = '1.0.0')
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if ($Version -notmatch '^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.]+)?$') { throw 'Invalid package version.' }
$dist = Join-Path $repo 'dist'
$scratch = Join-Path $repo '.test-runtime'
New-Item -ItemType Directory -Path $dist,$scratch -Force | Out-Null
$stage = Join-Path $scratch ('package-' + [guid]::NewGuid().ToString('N'))
$plugin = Join-Path $stage 'wordpress-sso'
New-Item -ItemType Directory -Path $plugin -Force | Out-Null
try {
    foreach ($name in @('wordpress-sso.php','uninstall.php','README.md','readme.txt','LICENSE','includes','assets','docs')) {
        Copy-Item -LiteralPath (Join-Path $repo $name) -Destination $plugin -Recurse
    }
    $zip = Join-Path $dist ('wordpress-sso-' + $Version + '.zip')
    if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory($stage, $zip)
    $checksum = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
    Set-Content -LiteralPath ($zip + '.sha256') -Value ($checksum + '  ' + [IO.Path]::GetFileName($zip)) -Encoding ascii
    Write-Output $zip
    Write-Output ('SHA256 ' + $checksum)
} finally {
    $resolved = (Resolve-Path -LiteralPath $stage).Path
    $boundary = (Resolve-Path -LiteralPath $scratch).Path.TrimEnd([char[]]@('/','\')) + [IO.Path]::DirectorySeparatorChar
    if (!$resolved.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe staging cleanup path.' }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
