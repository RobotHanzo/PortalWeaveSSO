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
    $manifest = ($stageJson -join "`n") | ConvertFrom-Json
    $expectedEntries = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($file in $manifest.files) {
        $entryName = $Slug + '/' + $file.path
        if (!$entryName.StartsWith($Slug + '/', [StringComparison]::Ordinal) -or $entryName -match '[\\:\x00-\x1f]' -or @($entryName.Split('/') | Where-Object { $_ -eq '' -or $_ -eq '.' -or $_ -eq '..' }).Count) {
            throw ('Unsafe ZIP entry name: ' + $entryName)
        }
        if ($expectedEntries.ContainsKey($entryName)) { throw ('Duplicate ZIP entry name: ' + $entryName) }
        $expectedEntries.Add($entryName, $file.sha256)
    }
    $zip = Join-Path $dist ($Slug + '-' + $Version + '.zip')
    if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    Add-Type -AssemblyName System.IO.Compression
    $zipStream = [IO.File]::Open($zip, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $archive = $null
    try {
        $archive = [IO.Compression.ZipArchive]::new($zipStream, [IO.Compression.ZipArchiveMode]::Create, $false)
        foreach ($file in $manifest.files) {
            $entryName = $Slug + '/' + $file.path
            $sourceFile = Join-Path $plugin $file.path
            # Explicit POSIX names avoid .NET Framework's backslash paths on Windows.
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $sourceFile, $entryName, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally {
        if ($null -ne $archive) { $archive.Dispose() }
        $zipStream.Dispose()
    }
    $archive = [IO.Compression.ZipFile]::OpenRead($zip)
    try {
        $seenEntries = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
        foreach ($entry in $archive.Entries) {
            if ($entry.FullName -match '\\' -or !$expectedEntries.ContainsKey($entry.FullName) -or !$seenEntries.Add($entry.FullName)) {
                throw ('Unexpected, noncanonical or duplicate ZIP entry: ' + $entry.FullName)
            }
            $entryStream = $entry.Open()
            $entryHasher = [Security.Cryptography.SHA256]::Create()
            try {
                $entryHash = [BitConverter]::ToString($entryHasher.ComputeHash($entryStream)).Replace('-','').ToLowerInvariant()
                if ($entryHash -ne $expectedEntries[$entry.FullName]) { throw ('ZIP content differs from staged source: ' + $entry.FullName) }
            } finally {
                $entryHasher.Dispose()
                $entryStream.Dispose()
            }
        }
        if ($seenEntries.Count -ne $expectedEntries.Count) { throw 'ZIP is missing staged production files.' }
    } finally { $archive.Dispose() }
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
