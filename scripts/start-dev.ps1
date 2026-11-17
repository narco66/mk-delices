# Démarre l'environnement de développement MK Délices sous Windows / Laragon :
# API Laravel (8000), worker de file d'attente (paiements, notifications) et SPA Vite (5173).
# Usage : powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1
#
# Le projet exige PHP >= 8.4 et Node >= 22.19 (dépendance undici de Vitest/Vite 8). Plusieurs versions coexistent souvent sur un poste
# Laragon : le script choisit lui-même des versions compatibles au lieu de dépendre de l'ordre du PATH.
$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'

function Test-PhpUsable([string]$exe) {
    # Version >= 8.4 ET extension PostgreSQL chargée (une installation sans php.ini n'en a aucune).
    return (Get-Version $exe '-v') -ge [version]'8.4.0' -and ((& $exe -m 2>$null) -contains 'pdo_pgsql')
}

function Get-Version([string]$exe, [string]$arg) {
    try { return [version](((& $exe $arg 2>$null) | Select-Object -First 1) -replace '^[^\d]*(\d+\.\d+\.\d+).*$', '$1') } catch { return $null }
}

# PHP : celui du PATH s'il convient, sinon le plus récent >= 8.4 installé dans Laragon.
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php -or -not (Test-PhpUsable $php)) {
    $php = Get-ChildItem 'C:\laragon\bin\php\php-8.*\php.exe' -ErrorAction SilentlyContinue |
        Where-Object { Test-PhpUsable $_.FullName } |
        Sort-Object { Get-Version $_.FullName '-v' } -Descending | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { Write-Error 'PHP 8.4+ avec l''extension pdo_pgsql introuvable (Laragon : menu PHP > Version).'; exit 1 }

# Node : celui du PATH s'il convient, sinon celui de Laragon.
$node = (Get-Command node -ErrorAction SilentlyContinue).Source
if (-not $node -or (Get-Version $node '-v') -lt [version]'22.19.0') {
    $node = Get-ChildItem 'C:\laragon\bin\nodejs\*\node.exe' -ErrorAction SilentlyContinue |
        Where-Object { (Get-Version $_.FullName '-v') -ge [version]'22.19.0' } |
        Sort-Object { Get-Version $_.FullName '-v' } -Descending | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $node) { Write-Error 'Node.js 22.19 ou plus récent introuvable.'; exit 1 }
$npx = Join-Path (Split-Path $node) 'npx.cmd'

Write-Host "PHP  : $php ($(Get-Version $php '-v'))"
Write-Host "Node : $node ($(Get-Version $node '-v'))"

# Port occupé = une connexion locale aboutit (Get-NetTCPConnection ne voit pas toujours les ports selon le contexte).
# Vite écoute sur « localhost », souvent en IPv6 (::1) : on teste les deux adresses.
function Test-PortInUse([int]$port) {
    foreach ($address in '127.0.0.1', '::1') {
        $client = New-Object System.Net.Sockets.TcpClient([System.Net.Sockets.AddressFamily]([System.Net.IPAddress]::Parse($address).AddressFamily))
        try { if ($client.ConnectAsync($address, $port).Wait(500) -and $client.Connected) { return $true } } catch { } finally { $client.Dispose() }
    }
    return $false
}
foreach ($port in 8000, 5173) {
    if (Test-PortInUse $port) {
        Write-Warning "Le port $port est déjà utilisé : arrêtez le processus correspondant avant de relancer."
        exit 1
    }
}

Start-Process -FilePath $php -ArgumentList 'artisan', 'serve', '--host=127.0.0.1', '--port=8000' -WorkingDirectory $backend
Start-Process -FilePath $php -ArgumentList 'artisan', 'queue:work', '--sleep=1', '--tries=6' -WorkingDirectory $backend
# Vite lancé avec le Node choisi (placé en tête du PATH du processus enfant).
$env:Path = "$(Split-Path $node);$env:Path"
Start-Process -FilePath $npx -ArgumentList 'vite', '--port', '5173', '--strictPort' -WorkingDirectory $frontend

Write-Host "Boutique   : http://localhost:5173"
Write-Host "Back-office: http://localhost:5173/admin"
Write-Host "API        : http://127.0.0.1:8000/api/v1/config"
Write-Host "Santé      : http://127.0.0.1:8000/health"
