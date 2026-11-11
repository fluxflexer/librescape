# Copies the viewer and the admin app to the XAMPP web root and applies pending schema migrations.
# Usage: .\deploy.ps1                  (migrations + files)
#        .\deploy.ps1 -ResetDb         (WIPES the database incl. users and reloads the Tolkien demo data;
#                                       writes a backup first and asks for confirmation; -Force skips the question)
#        .\deploy.ps1 -FetchImages     (refresh Wikipedia images for the current data, then deploy)
# The database is the master copy of the data - see tools/migrate.php and tools/backup.php.
param([switch]$ResetDb, [switch]$FetchImages, [switch]$Force)

$src  = $PSScriptRoot
$dest = 'C:\XAMPP\htdocs\librescape'
$php  = 'C:\XAMPP\php\php.exe'

if (-not (Test-Path (Join-Path $src 'config.php'))) {
    Write-Error 'config.php is missing - copy config.example.php to config.php and fill in the database settings'
    exit 1
}

if ($ResetDb) {
    if (-not $Force) {
        Write-Warning "-ResetDb deletes ALL data in the database, including users and the change log."
        if ((Read-Host "Type RESET to continue") -cne "RESET") { Write-Host "Cancelled"; exit 1 }
    }
    & $php (Join-Path $src "tools\backup.php") before-reset
    if ($LASTEXITCODE -ne 0) { Write-Error "Backup failed - database NOT reset"; exit 1 }

    $mysql = 'C:\XAMPP\mysql\bin\mysql.exe'
    # Credentials come from config.php (one value per line: host, name, user, password).
    $cfg = & $php -r '$c = require $argv[1]; echo implode(PHP_EOL, [$c[''db_host''], $c[''db_name''], $c[''db_user''], $c[''db_pass'']]);' (Join-Path $src 'config.php')
    if ($LASTEXITCODE -ne 0 -or $cfg.Count -ne 4) { Write-Error 'Could not read config.php'; exit 1 }
    $env:MYSQL_PWD = $cfg[3]   # keeps the password off the command line
    foreach ($f in '01_schema.sql', '02_seed_tolkien.sql', '03_sources_tolkien.sql') {
        # mysql reads the file itself (SOURCE): piping it through PowerShell would re-encode
        # non-ASCII characters with the session's $OutputEncoding ("née" -> "n?e").
        $file = (Join-Path $src "sql\$f") -replace '\\', '/'
        & $mysql -h $cfg[0] -u $cfg[2] --abort-source-on-error --default-character-set=utf8mb4 $cfg[1] --execute="SOURCE $file"
        if ($LASTEXITCODE -ne 0) { Write-Error "Import of $f failed"; exit 1 }
    }
    Write-Host 'Database reset and seeded'
}

# Schema changes since the baseline (and after a reset: all of them).
& $php (Join-Path $src 'tools\migrate.php')
if ($LASTEXITCODE -ne 0) { Write-Error 'Migration failed'; exit 1 }
if ($ResetDb) {
    Write-Warning 'All users were deleted. Create an administrator with: php tools\create_user.php'
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
    /XD models data_representation sql tools notes .git .claude media `
    /XF deploy.ps1 Description.md README.md config.example.php .gitignore *.bak *.mwb | Out-Null
if ($LASTEXITCODE -ge 8) { Write-Error "robocopy failed ($LASTEXITCODE)"; exit 1 }
# Pictures: copied without /MIR, because the admin app stores the pictures of entries saved there
# directly in the web root (media/wiki); mirroring would delete them.
if (Test-Path (Join-Path $src 'media')) {
    robocopy (Join-Path $src 'media') (Join-Path $dest 'media') /E /NFL /NDL /NJH /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { Write-Error "robocopy of media failed ($LASTEXITCODE)"; exit 1 }
}
Write-Host "Deployed to $dest"

exit 0  # robocopy uses 1-7 for success; don't leak that as a failure
