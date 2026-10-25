<#
.SYNOPSIS
    Packages Classic EDU Suite into an installable WordPress plugin zip.

.DESCRIPTION
    Copies the plugin folder to a clean staging directory, strips development
    files, runs a few structural sanity checks (no PHP is available on this
    machine, so these are text-level checks only), and writes
    dist\classic-edu-suite-<version>.zip.

    The zip contains a single top-level `classic-edu-suite/` folder, which is
    what WordPress expects from Plugins > Add New > Upload Plugin.

.EXAMPLE
    .\build-plugin.ps1
    .\build-plugin.ps1 -SkipChecks
#>

[CmdletBinding()]
param(
    [switch]$SkipChecks
)

$ErrorActionPreference = 'Stop'

$root       = $PSScriptRoot
$pluginName = 'classic-edu-suite'
$source     = Join-Path $root $pluginName
$dist       = Join-Path $root 'dist'
$staging    = Join-Path $env:TEMP "ces-build-$(Get-Random)"

if (-not (Test-Path $source)) {
    throw "Plugin source not found at $source"
}

# ---------------------------------------------------------------
# Version, read from the plugin header.
# ---------------------------------------------------------------
$mainFile = Join-Path $source "$pluginName.php"
$header   = Get-Content $mainFile -Raw
$version  = ([regex]::Match($header, '(?m)^\s*\*\s*Version:\s*(.+)$')).Groups[1].Value.Trim()

if (-not $version) { $version = '0.0.0' }

Write-Host "Classic EDU Suite v$version" -ForegroundColor Cyan
Write-Host ("-" * 50)

# ---------------------------------------------------------------
# Sanity checks.
# ---------------------------------------------------------------
if (-not $SkipChecks) {
    $problems = @()

    $phpFiles = Get-ChildItem -Path $source -Recurse -Filter *.php -File

    foreach ($f in $phpFiles) {
        $text = Get-Content $f.FullName -Raw

        # Balanced braces / parens, a crude but useful smoke test with no PHP
        # binary available. Counts inside strings and comments can skew this,
        # so a mismatch is reported as a warning to eyeball, not a hard error.
        $open  = ([regex]::Matches($text, '\{')).Count
        $close = ([regex]::Matches($text, '\}')).Count
        if ($open -ne $close) {
            $problems += "BRACES  $($f.Name): $open open vs $close close"
        }

        # Every class file must guard against direct access.
        if ($f.Name -ne 'uninstall.php' -and $text -notmatch "defined\(\s*'ABSPATH'") {
            $problems += "ABSPATH $($f.Name): missing direct-access guard"
        }

        if ($text -match '<\?php\s*$' -and $text.Length -lt 40) {
            $problems += "EMPTY   $($f.Name): looks like a stub"
        }
    }

    # Autoloader naming: class CES_Foo_Bar must live in class-ces-foo-bar.php.
    foreach ($f in $phpFiles) {
        $text = Get-Content $f.FullName -Raw
        $m = [regex]::Match($text, '(?m)^\s*(?:final\s+|abstract\s+)?class\s+(CES_[A-Za-z0-9_]+)')
        if ($m.Success) {
            $expected = 'class-' + ($m.Groups[1].Value.ToLower() -replace '_', '-') + '.php'
            if ($f.Name -ne $expected) {
                $problems += "NAMING  $($f.Name): class $($m.Groups[1].Value) expects $expected"
            }
        }
    }

    Write-Host "Checked $($phpFiles.Count) PHP files." -ForegroundColor Gray

    if ($problems.Count -gt 0) {
        Write-Host "`nIssues to review:" -ForegroundColor Yellow
        $problems | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
        Write-Host ""
    } else {
        Write-Host "No structural issues found." -ForegroundColor Green
    }
}

# ---------------------------------------------------------------
# Stage a clean copy.
# ---------------------------------------------------------------
$stagedPlugin = Join-Path $staging $pluginName
New-Item -ItemType Directory -Path $stagedPlugin -Force | Out-Null

Copy-Item -Path (Join-Path $source '*') -Destination $stagedPlugin -Recurse -Force

$excludePatterns = @(
    '*.md',          # Developer notes; readme.txt ships instead.
    '.DS_Store',
    'Thumbs.db',
    '*.log',
    '*.bak',
    '*.orig',
    'node_modules',
    '.git'
)

foreach ($pattern in $excludePatterns) {
    Get-ChildItem -Path $stagedPlugin -Recurse -Force -Filter $pattern -ErrorAction SilentlyContinue |
        ForEach-Object { Remove-Item $_.FullName -Recurse -Force -ErrorAction SilentlyContinue }
}

# readme.txt is the WordPress-facing one and must survive the *.md sweep.
$readmeSrc = Join-Path $source 'readme.txt'
if (Test-Path $readmeSrc) {
    Copy-Item $readmeSrc (Join-Path $stagedPlugin 'readme.txt') -Force
}

# ---------------------------------------------------------------
# Zip it.
# ---------------------------------------------------------------
if (-not (Test-Path $dist)) {
    New-Item -ItemType Directory -Path $dist -Force | Out-Null
}

$zipPath = Join-Path $dist "$pluginName-$version.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Compress-Archive -Path $stagedPlugin -DestinationPath $zipPath -CompressionLevel Optimal

Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue

$sizeKb    = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
$fileCount = (Get-ChildItem -Path $source -Recurse -File).Count

Write-Host ("-" * 50)
Write-Host "Built: $zipPath" -ForegroundColor Green
Write-Host "Size:  $sizeKb KB  ($fileCount source files)" -ForegroundColor Gray
Write-Host ""
Write-Host "Install: WP Admin > Plugins > Add New > Upload Plugin > choose this zip." -ForegroundColor Cyan
