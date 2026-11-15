# Démarre l'environnement de développement MK Délices sous Windows / Laragon :
# API Laravel (8000), worker de file d'attente (paiements, notifications) et SPA Vite (5173).
# Usage : powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'

foreach ($port in 8000, 5173) {
    if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
        Write-Warning "Le port $port est déjà utilisé : arrêtez le processus correspondant avant de relancer."
        exit 1
    }
}

Start-Process -FilePath 'php' -ArgumentList 'artisan', 'serve', '--host=127.0.0.1', '--port=8000' -WorkingDirectory $backend
Start-Process -FilePath 'php' -ArgumentList 'artisan', 'queue:work', '--sleep=1', '--tries=6' -WorkingDirectory $backend
Start-Process -FilePath 'npx.cmd' -ArgumentList 'vite', '--port', '5173', '--strictPort' -WorkingDirectory $frontend

Write-Host "Boutique   : http://localhost:5173"
Write-Host "Back-office: http://localhost:5173/admin"
Write-Host "API        : http://127.0.0.1:8000/api/v1/config"
