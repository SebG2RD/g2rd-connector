# Genere g2rd-connector.zip wp.org-compatible (1 seul dossier racine).
# Usage : .\build-zip.ps1

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$root      = Split-Path -Parent $PSScriptRoot
$pluginDir = Join-Path $root 'g2rd-connector'
$staging   = Join-Path $root 'g2rd-connector-staging'
$zipPath   = Join-Path $root 'g2rd-connector.zip'

# Nettoyage staging precedent + zip precedent
Remove-Item -Recurse -Force $staging  -ErrorAction SilentlyContinue
Remove-Item -Force        $zipPath    -ErrorAction SilentlyContinue

# Staging : copie filtree (exclut .git, .github, build-zip.ps1, *.zip, caches)
$excludeDirs  = @('.git', '.github', 'node_modules', 'vendor', '.phpunit.cache', '.phpcs.cache', '.phpstan.cache')
$excludeFiles = @('build-zip.ps1', '*.zip', '*.log', '.DS_Store')

$dest = Join-Path $staging 'g2rd-connector'
New-Item -ItemType Directory -Path $dest -Force | Out-Null

# Robocopy = la commande Windows native pour copie filtree (gere mieux les noms unicode)
$rcArgs = @(
    "$pluginDir",
    "$dest",
    '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP',
    '/XD'
)
$rcArgs += $excludeDirs
$rcArgs += '/XF'
$rcArgs += $excludeFiles

& robocopy @rcArgs | Out-Null
# Robocopy renvoie 1 = succes avec fichiers copies, 0 = succes sans fichiers, >=8 = erreur
if ($LASTEXITCODE -ge 8) { throw "robocopy a echoue (exit $LASTEXITCODE)" }

# Zip avec le dossier g2rd-connector comme top-level
Compress-Archive -Path $dest -DestinationPath $zipPath -CompressionLevel Optimal

# Cleanup staging
Remove-Item -Recurse -Force $staging

# Verif rapide
$size = [math]::Round((Get-Item $zipPath).Length / 1KB, 2)
Write-Host ""
Write-Host "OK : $zipPath ($size KB)" -ForegroundColor Green
Write-Host ""
Write-Host "Contenu (top-level) :" -ForegroundColor DarkGray
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$zip.Entries | Where-Object { $_.FullName -match '^g2rd-connector/[^/]+/?$' } | Select-Object FullName, @{n='KB';e={[math]::Round($_.Length/1024,1)}} | Format-Table -AutoSize
$zip.Dispose()
