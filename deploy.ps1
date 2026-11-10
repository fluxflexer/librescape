# Copies the viewer to the XAMPP web root.
# Usage: .\deploy.ps1                (files only)
#        .\deploy.ps1 -ResetDb       (re-create schema, load seed data, fetch Wikipedia images, then deploy)
#        .\deploy.ps1 -FetchImages   (refresh Wikipedia images for the current data, then deploy)
param([switch]$ResetDb, [switch]$FetchImages)

$src  = $PSScriptRoot
$dest = 'C:\XAMPP\htdocs\librescape'
$php  = 'C:\XAMPP\php\php.exe'

if (-not (Test-Path (Join-Path $src 'config.php'))) {
    Write-Error 'config.php is missing - copy config.example.php to config.php and fill in the database settings'
    exit 1
}

if ($ResetDb) {
    $mysql = 'C:\XAMPP\mysql\bin\mysql.exe'
    # Credentials come from config.php (one value per line: host, name, user, password).
    $cfg = & $php -r '$c = require $argv[1]; echo implode(PHP_EOL, [$c[''db_host''], $c[''db_name''], $c[''db_user''], $c[''db_pass'']]);' (Join-Path $src 'config.php')
    if ($LASTEXITCODE -ne 0 -or $cfg.Count -ne 4) { Write-Error 'Could not read config.php'; exit 1 }
    $env:MYSQL_PWD = $cfg[3]   # keeps the password off the command line
    foreach ($f in '01_schema.sql', '02_seed_tolkien.sql', '03_sources_tolkien.sql') {
        Get-Content -Raw -Encoding UTF8 (Join-Path $src "sql\$f") |
            & $mysql -h $cfg[0] -u $cfg[2] --default-character-set=utf8mb4 $cfg[1]
        if ($LASTEXITCODE -ne 0) { Write-Error "Import of $f failed"; exit 1 }
    }
    Write-Host 'Database reset and seeded'
}

# Every entry must cite a source; report the ones that don't (does not stop the deploy).
& $php (Join-Path $src 'tools\check_sources.php')
if ($LASTEXITCODE -ne 0) { Write-Warning 'Some entries have no source - see the list above' }

if ($ResetDb -or $FetchImages) {
    # Needs internet access; on failure the viewer simply shows no pictures.
    & $php (Join-Path $src 'tools\fetch_images.php')
    if ($LASTEXITCODE -ne 0) { Write-Warning 'Image fetch failed - continuing without pictures' }
}

robocopy $src $dest /MIR /NFL /NDL /NJH /NP `
    /XD models data_representation sql tools notes .git .claude `
    /XF deploy.ps1 Description.md README.md config.example.php .gitignore *.bak *.mwb | Out-Null
if ($LASTEXITCODE -ge 8) { Write-Error "robocopy failed ($LASTEXITCODE)"; exit 1 }
Write-Host "Deployed to $dest"

exit 0  # robocopy uses 1-7 for success; don't leak that as a failure
