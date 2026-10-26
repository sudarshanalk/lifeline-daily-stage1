# Stage 2 local WordPress for Cursor and Claude (Build Method P0-6). Windows, no Docker.
# A clean site with no production data: WordPress + Paid Memberships Pro + the lld-members plugin linked live from this
# checkout, MariaDB on 127.0.0.1:3399 (shared with the Stage 1 stack, separate database), Mailpit for every email.
#
# Usage: powershell -File tools\local\stage2-local.ps1 <init|serve|stop|reset|dbupdate|status|wp ...>
#   init      create the database and site, install Paid Memberships Pro, link lld-members, write local\stage2.env
#   serve     start MariaDB and Mailpit, then serve http://localhost:8090 (Ctrl+C to stop)
#   stop      stop Mailpit (MariaDB is stopped with local-stack.ps1 stop)
#   reset     drop and rebuild the database and site settings (plugin code is untouched)
#   dbupdate  activate lld-members and run `wp lld-members db-update`
#   status    show what is installed and running
#   wp ...    run any WP-CLI command against the Stage 2 site, e.g.  stage2-local.ps1 wp plugin list
#
# The Stage 1 site at http://localhost:8080 serves the production snapshot (real member data, live mail plugin):
# never run Stage 2 tests against it.
param([Parameter(Position = 0)][string]$Action = "status", [Parameter(ValueFromRemainingArguments = $true)][string[]]$Rest)
$ErrorActionPreference = "Stop"
$Repo     = (Resolve-Path "$PSScriptRoot\..\..").Path
$Root     = (Resolve-Path "$Repo\..").Path
$Local    = "$Root\local"
$Site     = "$Local\stage2-wp"
$EnvFile  = "$Local\stage2.env"
$Phar     = "$Local\bin\wp-cli.phar"
$Port     = 3399
$Db       = "lld_stage2_local"
$Url      = "http://localhost:8090"
$Plugin   = "$Repo\site\public_html\wp-content\plugins\lld-members"
$MariaBin = (Get-ChildItem "C:\Program Files\MariaDB*\bin" -Directory | Select-Object -First 1).FullName
if (-not $MariaBin) { throw "MariaDB bin folder not found under C:\Program Files" }
# WP-CLI unpacks WordPress under TMP; the default user temp path is long enough to break Windows path limits.
$env:TMP = Join-Path $Local 'tmp'; $env:TEMP = $env:TMP; New-Item -ItemType Directory -Force $env:TMP | Out-Null

function Wp { & php -d memory_limit=1024M $Phar "--path=$Site" @args; if ($LASTEXITCODE -ne 0) { throw "wp $args failed" } }
function Sql([string]$q) { & "$MariaBin\mariadb.exe" -uroot -P $Port -h 127.0.0.1 -e $q; if ($LASTEXITCODE -ne 0) { throw "SQL failed: $q" } }
function Start-Db { & "$Repo\tools\local\local-stack.ps1" start }
function Find-Mailpit {
  $c = Get-Command mailpit -ErrorAction SilentlyContinue
  if ($c) { return $c.Source }
  $p = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter mailpit.exe -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($p) { return $p.FullName }
  throw "Mailpit not found: winget install --id axllent.mailpit --exact"
}
function Start-Mailpit {
  if (Get-Process mailpit -ErrorAction SilentlyContinue) { Write-Output "mailpit already running (http://localhost:8025)"; return }
  Start-Process -FilePath (Find-Mailpit) -ArgumentList "--listen 127.0.0.1:8025 --smtp 127.0.0.1:1025" -WindowStyle Hidden
  Write-Output "mailpit up: SMTP 127.0.0.1:1025, web http://localhost:8025"
}
function New-Secret { [guid]::NewGuid().ToString('N') }
function Read-EnvFile {
  $h = @{}
  if (Test-Path $EnvFile) { foreach ($l in Get-Content $EnvFile) { if ($l -match '^([A-Z0-9_]+)=(.*)$') { $h[$Matches[1]] = $Matches[2] } } }
  return $h
}
function Write-EnvFile {
  $e = Read-EnvFile
  foreach ($k in 'LLD_ADMIN_PASSWORD', 'LLD_TEST_MEMBER_PASSWORD') { if (-not $e[$k]) { $e[$k] = New-Secret } }
  $lines = @(
    "# Stage 2 local test settings (tools/local/stage2-local.ps1). Test values only; never commit this file.",
    "LLD_WP_URL=$Url", "MAILPIT_URL=http://localhost:8025", "LLD_TEST_CLOCK=1",
    "LLD_ADMIN_USER=lld-admin", "LLD_ADMIN_PASSWORD=$($e['LLD_ADMIN_PASSWORD'])",
    "LLD_TEST_MEMBER_PASSWORD=$($e['LLD_TEST_MEMBER_PASSWORD'])")
  Set-Content -Path $EnvFile -Value $lines -Encoding ascii
  return $e
}
function Install-Site {
  Sql "DROP DATABASE IF EXISTS $Db; CREATE DATABASE $Db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  if (-not (Test-Path "$Site\wp-includes")) {
    # `wp core download` cannot unpack current WordPress on Windows (PharData cuts long names; its tar fallback is not
    # found from PHP), so fetch the official zip and unpack it here.
    $zip = Join-Path $env:TMP 'wordpress-latest.zip'; $out = Join-Path $env:TMP 'wp-unpack'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri 'https://wordpress.org/latest.zip' -OutFile $zip -UseBasicParsing
    if (Test-Path $out) { Remove-Item -Recurse -Force $out }
    Expand-Archive -Path $zip -DestinationPath $out
    Move-Item "$out\wordpress" $Site
    Remove-Item -Recurse -Force $out, $zip
  }
  if (-not (Test-Path "$Site\wp-config.php")) {
    Wp config create --dbname=$Db --dbuser=root --dbpass= --dbhost="127.0.0.1:$Port" --skip-check --quiet
    Wp config set LLD_ENV local --quiet
    Wp config set LLD_TEST_CLOCK true --raw --quiet
    Wp config set LLD_SMTP_HOST 127.0.0.1 --quiet
    Wp config set LLD_SMTP_PORT 1025 --raw --quiet
    Wp config set WP_DEBUG true --raw --quiet
    Wp config set WP_DEBUG_LOG true --raw --quiet
    Wp config set WP_DEBUG_DISPLAY false --raw --quiet
    Wp config set DISABLE_WP_CRON true --raw --quiet
  }
  $e = Write-EnvFile
  Wp core install --url=$Url --title="Lifeline Daily Stage 2 (local)" --admin_user=lld-admin "--admin_password=$($e['LLD_ADMIN_PASSWORD'])" --admin_email=lld-admin@test.lifelinedaily.invalid --skip-email --quiet
  Wp rewrite structure '/%postname%/' --quiet
  Wp option update blog_public 0 --quiet
  Install-Pmpro
  Link-Plugin
  Write-Output "Stage 2 site ready at $Url (settings in $EnvFile)"
}
function Install-Pmpro {
  # Paid Memberships Pro left wordpress.org in October 2024, so this takes the vendor's tagged release from GitHub,
  # the same version as CI (tests/ci/stage2-wp.sh) and staging; unpacked here for the same reason as WordPress itself.
  $version = '3.8.9'
  $dir = "$Site\wp-content\plugins\paid-memberships-pro"
  $main = "$dir\paid-memberships-pro.php"
  if (-not ((Test-Path $main) -and (Select-String -Path $main -SimpleMatch "Version: $version" -Quiet))) {
    $zip = Join-Path $env:TMP 'pmpro.zip'
    $tmp = Join-Path $env:TMP 'pmpro-unpack'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri "https://codeload.github.com/strangerstudios/paid-memberships-pro/zip/refs/tags/$version" -OutFile $zip -UseBasicParsing
    if (Test-Path $tmp) { Remove-Item -Recurse -Force $tmp }
    Expand-Archive -Path $zip -DestinationPath $tmp -Force
    if (Test-Path $dir) { Remove-Item -Recurse -Force $dir }
    Move-Item "$tmp\paid-memberships-pro-$version" $dir
    Remove-Item -Recurse -Force $zip, $tmp
  }
  Wp plugin activate paid-memberships-pro --quiet
}
function Link-Plugin {
  if (-not (Test-Path $Plugin)) { New-Item -ItemType Directory -Force $Plugin | Out-Null }
  $link = "$Site\wp-content\plugins\lld-members"
  if (Test-Path $link) { (Get-Item $link).Delete() }
  New-Item -ItemType Junction -Path $link -Target $Plugin | Out-Null
  Write-Output "lld-members linked: $link -> $Plugin"
}

switch ($Action) {
  "init"     { Start-Db; Install-Site }
  "reset"    { Start-Db; Install-Site }
  "serve"    {
    Start-Db; Start-Mailpit
    Write-Output "serving $Site at $Url (Ctrl+C to stop)"
    php -S 127.0.0.1:8090 -t $Site "$Repo\tools\local\router.php"
  }
  "stop"     { Get-Process mailpit -ErrorAction SilentlyContinue | Stop-Process; Write-Output "mailpit stopped" }
  "dbupdate" {
    Start-Db
    if (-not (Test-Path "$Plugin\lld-members.php")) { Write-Output "lld-members.php not found yet (arrives with S2-C1)"; break }
    Wp plugin activate lld-members --quiet
    Wp lld-members db-update
  }
  "wp"       { Wp @Rest }
  "status"   {
    Write-Output "site folder: $Site (installed: $(Test-Path "$Site\wp-config.php"))"
    Write-Output "env file: $EnvFile (present: $(Test-Path $EnvFile))"
    Write-Output "plugin source: $Plugin (main file present: $(Test-Path "$Plugin\lld-members.php"))"
    if (Get-Process mariadbd -ErrorAction SilentlyContinue) { Write-Output "mariadbd: running" } else { Write-Output "mariadbd: stopped" }
    if (Get-Process mailpit -ErrorAction SilentlyContinue) { Write-Output "mailpit: running" } else { Write-Output "mailpit: stopped" }
  }
  default    { throw "Unknown action $Action" }
}
