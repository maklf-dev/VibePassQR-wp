<#
Read-only Windows PowerShell ZIP integrity / plugin-slug checker.
Example: .\Phase8-Local-Zip-Check.ps1 -ZipPath 'D:\Downloads\event-card-manager-0.7.0-alpha.7-phase8.zip'
No WordPress, network, hosting credentials or database connection required.
#>
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$ZipPath)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$full = (Resolve-Path -LiteralPath $ZipPath).Path
$zip = [System.IO.Compression.ZipFile]::OpenRead($full)
try {
    $entries = @($zip.Entries | ForEach-Object { $_.FullName })
    $main = $zip.GetEntry('event-card-manager/event-card-manager.php')
    if ($null -eq $main) { throw 'Main plugin file is missing or the folder slug changed.' }
    $reader = [System.IO.StreamReader]::new($main.Open())
    try { $source = $reader.ReadToEnd() } finally { $reader.Dispose() }
    $headerMatch = [regex]::Match($source, '(?m)^\s*\* Version:\s+([^\s]+)')
    $defineMatch = [regex]::Match($source, "define\(\s*'ECM_VERSION',\s*'([^']+)'\s*\)")
    $headerVersion = if ($headerMatch.Success) { $headerMatch.Groups[1].Value } else { '' }
    $constantVersion = if ($defineMatch.Success) { $defineMatch.Groups[1].Value } else { '' }
    $unsafe = @($entries | Where-Object { $_ -match '(^/|(^|/)\.\.(/|$)|(^|/)wp-config\.php$|(^|/)\.env$)' })
    $outside = @($entries | Where-Object { -not $_.StartsWith('event-card-manager/') })
    $dupes = @($entries | Group-Object | Where-Object { $_.Count -gt 1 })
    $sha256 = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash
    $passed = $headerVersion -eq '0.7.0-alpha.7' -and $constantVersion -eq $headerVersion -and $unsafe.Count -eq 0 -and $outside.Count -eq 0 -and $dupes.Count -eq 0
    [pscustomobject]@{
        File = $full
        Version = $constantVersion
        PluginSlug = 'event-card-manager'
        FilesInZip = $entries.Count
        UnsafePaths = $unsafe.Count
        OutsidePluginFolder = $outside.Count
        DuplicatedEntries = $dupes.Count
        SHA256 = $sha256
        Result = if($passed){'PASS'}else{'FAIL'}
    } | Format-List
    if (-not $passed) { exit 1 }
} finally { $zip.Dispose() }
