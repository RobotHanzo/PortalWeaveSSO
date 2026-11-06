param([string]$Version = '', [string]$Slug = '')
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$releaseScript = Join-Path $PSScriptRoot 'release.mjs'
$metadataJson = & node $releaseScript validate
if ($LASTEXITCODE -ne 0) { throw 'Release metadata validation failed.' }
$metadata = ($metadataJson -join "`n") | ConvertFrom-Json
if ($Version -and $Version -ne $metadata.version) { throw 'Requested package version must match plugin headers, constant, and readme Stable tag.' }
$Version = $metadata.version
if ($Slug -and $Slug -ne $metadata.slug) { throw 'Package slug must match the plugin Text Domain.' }
$Slug = $metadata.slug
$dist = Join-Path $repo 'dist'
$scratch = Join-Path $repo '.test-runtime'
foreach ($directory in @($dist,$scratch)) {
    if ((Test-Path -LiteralPath $directory) -and ((Get-Item -LiteralPath $directory -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Package directories must not be symbolic links or junctions.'
    }
}
New-Item -ItemType Directory -Path $dist,$scratch -Force | Out-Null
$stage = Join-Path $scratch ('package-' + [guid]::NewGuid().ToString('N'))
$plugin = Join-Path $stage $Slug
try {
    $stageJson = & node $releaseScript stage --output $plugin --slug $Slug
    if ($LASTEXITCODE -ne 0) { throw 'Release staging failed.' }
    $verifyJson = & node $releaseScript verify --output $plugin
    if ($LASTEXITCODE -ne 0) { throw 'Release staging verification failed.' }
    $zip = Join-Path $dist ($Slug + '-' + $Version + '.zip')
    if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory($stage, $zip)
    $checksum = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
    Set-Content -LiteralPath ($zip + '.sha256') -Value ($checksum + '  ' + [IO.Path]::GetFileName($zip)) -Encoding ascii
    Write-Output $zip
    Write-Output ('SHA256 ' + $checksum)
} finally {
    if (Test-Path -LiteralPath $stage) {
        $resolved = (Resolve-Path -LiteralPath $stage).Path
        $boundary = (Resolve-Path -LiteralPath $scratch).Path.TrimEnd([char[]]@('/','\')) + [IO.Path]::DirectorySeparatorChar
        if (!$resolved.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe staging cleanup path.' }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
