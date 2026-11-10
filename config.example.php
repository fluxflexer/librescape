<?php
// Settings for Librescape (viewer, admin app and tools).
// Copy this file to config.php and fill in your own values; config.php is not committed.
return [
    'db_host' => 'localhost',
    'db_name' => 'librescape',
    'db_user' => 'librescape',
    'db_pass' => 'change-me',

    // Database dumps (admin "Backups" page, deploy.ps1 -ResetDb). Keep the folder OUTSIDE the web root.
    'mysqldump_path' => 'C:\XAMPP\mysql\bin\mysqldump.exe',
    'backup_dir'     => 'C:\XAMPP\librescape-backups',
];
