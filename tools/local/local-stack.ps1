# Local WordPress stack for Lifeline Daily (Windows, no Docker)
# Usage: powershell -File local-stack.ps1 [init|start|import|replace|serve|stop|status]
# Data stays under C:\MyProjects\lifeline-daily\local\stack ; the snapshot folder is never modified.
param([string]$Action = "status")
$ErrorActionPreference = "Stop"
$Root     = "C:\MyProjects\lifeline-daily"
$Snapshot = "$Root\snapshot-production-2026-10-23"
$Stack    = "$Root\local\stack"
$DataDir  = "$Stack\mariadb-data"
$Port     = 3399
$Db       = "lifelinedaily_local"
$Url      = "http://localhost:8080"
$Wp       = "php $Root\local\bin\wp-cli.phar --path=$Snapshot\public_html"
$MariaBin = (Get-ChildItem "C:\Program Files\MariaDB*\bin" -Directory | Select-Object -First 1).FullName
if (-not $MariaBin) { throw "MariaDB bin folder not found under C:\Program Files" }
$env:LLD_LOCAL_DB_NAME = $Db; $env:LLD_LOCAL_DB_USER = "root"; $env:LLD_LOCAL_DB_PASSWORD = ""; $env:LLD_LOCAL_DB_HOST = "127.0.0.1:$Port"; $env:LLD_LOCAL_URL = $Url

function Start-Db {
  if (Get-Process mariadbd -ErrorAction SilentlyContinue) { Write-Output "mariadbd already running"; return }
  Start-Process -FilePath "$MariaBin\mariadbd.exe" -ArgumentList "--datadir=`"$DataDir`" --port=$Port --bind-address=127.0.0.1 --skip-name-resolve --console" -WindowStyle Hidden
  $tries = 0
  while ($tries -lt 30) { Start-Sleep 1; $tries++; & "$MariaBin\mariadb.exe" -uroot -P $Port -h 127.0.0.1 -e "select 1" *> $null; if ($LASTEXITCODE -eq 0) { Write-Output "mariadbd up on 127.0.0.1:$Port"; return } }
  throw "mariadbd did not start"
}

switch ($Action) {
  "init" {
    New-Item -ItemType Directory -Force $Stack | Out-Null
    if (-not (Test-Path "$DataDir\mysql")) { & "$MariaBin\mariadb-install-db.exe" --datadir="$DataDir" --password= | Out-Null; Write-Output "data dir initialised" }
    Start-Db
    & "$MariaBin\mariadb.exe" -uroot -P $Port -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS $Db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    Write-Output "database $Db ready"
  }
  "start"  { Start-Db }
  "import" {
    Start-Db
    Write-Output "importing dump (49 MB) ..."
    & cmd /c "`"$MariaBin\mariadb.exe`" -uroot -P $Port -h 127.0.0.1 $Db < `"$Snapshot\database\lifelinedaily-original.sql`""
    & "$MariaBin\mariadb.exe" -uroot -P $Port -h 127.0.0.1 $Db -e "SELECT COUNT(*) AS tables_imported FROM information_schema.tables WHERE table_schema='$Db'; SELECT option_name, option_value FROM wp_options WHERE option_name IN ('home','siteurl','active_plugins','template','default_category');"
  }
  "replace" {
    Start-Db
    Invoke-Expression "$Wp search-replace 'https://www.lifelinedaily.com' '$Url' --all-tables --skip-columns=guid --dry-run"
    Invoke-Expression "$Wp search-replace 'https://lifelinedaily.com' '$Url' --all-tables --skip-columns=guid --dry-run"
    Invoke-Expression "$Wp search-replace 'https://www.lifelinedaily.com' '$Url' --all-tables --skip-columns=guid"
    Invoke-Expression "$Wp search-replace 'https://lifelinedaily.com' '$Url' --all-tables --skip-columns=guid"
    Invoke-Expression "$Wp cache flush"
  }
  "serve" {
    Start-Db
    Write-Output "serving $Snapshot\public_html at $Url (Ctrl+C to stop)"
    php -S 127.0.0.1:8080 -t "$Snapshot\public_html" "$Root\local\bin\router.php"
  }
  "stop"   { Get-Process mariadbd -ErrorAction SilentlyContinue | Stop-Process; Write-Output "stopped" }
  "status" {
    Write-Output "MariaDB bin: $MariaBin"
    if (Get-Process mariadbd -ErrorAction SilentlyContinue) { Write-Output "mariadbd: running" } else { Write-Output "mariadbd: stopped" }
    Write-Output "data dir exists: $(Test-Path "$DataDir\mysql")"
  }
}
