# Deploys the Classic EDU CBT demo (web-demo\) to Vercel.
#
# Run it with:
#   powershell -ExecutionPolicy Bypass -File "C:\Users\user\Desktop\ceduinfo\deploy-to-vercel.ps1"
#
# It signs you in if needed, refreshes the question bank, and deploys.
# Node 24 and Vercel CLI are already installed on this machine.

$ErrorActionPreference = 'Continue'

# winget installs Node machine-wide, and npm puts global binaries in AppData.
# A terminal opened before the install will not have either on PATH, so rebuild it.
$machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
$userPath    = [Environment]::GetEnvironmentVariable('Path', 'User')
$env:Path    = "$machinePath;$userPath;$env:APPDATA\npm"

$root = $PSScriptRoot
$demo = Join-Path $root 'web-demo'

Write-Host ''
Write-Host '===  Classic EDU CBT  ->  Vercel  ===' -ForegroundColor Cyan
Write-Host ''

if (-not (Test-Path (Join-Path $demo 'index.html'))) {
    Write-Host "Cannot find web-demo\index.html beside this script. Is the script still in" -ForegroundColor Red
    Write-Host "C:\Users\user\Desktop\ceduinfo ?" -ForegroundColor Red
    exit 1
}

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    Write-Host 'node is not on PATH. Close this window, open a new one, and try again.' -ForegroundColor Red
    exit 1
}

Write-Host ("node    " + (node --version)) -ForegroundColor DarkGray
Write-Host ("vercel  " + (vercel --version)) -ForegroundColor DarkGray

# Keep the deployed copy of the bank in step with the built one.
$built = Join-Path $root 'seed-bank\out\all-questions.json'
if (Test-Path $built) {
    Copy-Item $built (Join-Path $demo 'questions.json') -Force
    $count = (Get-Content $built -Raw -Encoding UTF8 | ConvertFrom-Json).questions.Count
    Write-Host ("bank    " + $count + " questions refreshed from seed-bank\out") -ForegroundColor DarkGray
}

Set-Location $demo

Write-Host ''
Write-Host 'Step 1 of 2  -  Vercel sign-in' -ForegroundColor Yellow
vercel whoami

if ($LASTEXITCODE -ne 0) {
    Write-Host ''
    Write-Host 'You are not signed in yet.' -ForegroundColor Yellow
    Write-Host 'A browser window is about to open. Sign in to Vercel there, then come back here.'
    Write-Host 'Use the arrow keys to pick how you sign in (GitHub, Google, Email), then press Enter.'
    Write-Host ''
    vercel login

    if ($LASTEXITCODE -ne 0) {
        Write-Host ''
        Write-Host 'Sign-in did not finish. Run this script again when ready.' -ForegroundColor Red
        exit 1
    }
}

Write-Host ''
Write-Host 'Step 2 of 2  -  Deploying' -ForegroundColor Yellow
Write-Host 'If it asks anything, pressing Enter accepts the right default.'
Write-Host ''

vercel deploy --prod --yes

if ($LASTEXITCODE -ne 0) {
    Write-Host ''
    Write-Host 'The deploy failed. Copy the red text above and show it to Claude.' -ForegroundColor Red
    exit 1
}

Write-Host ''
Write-Host 'Done.' -ForegroundColor Green
Write-Host 'The https://... address printed just above is your live site. Open it and pick a subject.'
Write-Host ''
Write-Host 'Note: that URL is public, and questions.json holds all 650 answers.' -ForegroundColor Yellow
Write-Host 'To lock it down: Vercel dashboard -> the project -> Settings -> Deployment Protection'
Write-Host '-> turn on Vercel Authentication.'
Write-Host ''
