<?php
declare(strict_types=1);

/** Regenerate app/integrity.manifest.json after editing protected credit files. */
$root = dirname(__DIR__);
$files = [
    'app/Services/DevCredit.php',
    'app/Services/Installed.php',
    'app/Helpers/WebLayout.php',
    'app/Views/AdminUi.php',
    'app/Install/InstallerView.php',
    'app/Views/ErrorUi.php',
];

$manifest = [];
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing: {$rel}\n");
        exit(1);
    }
    $manifest[$rel] = hash_file('sha256', $path);
}

$out = $root . '/app/integrity.manifest.json';
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($out, $json . "\n") === false) {
    fwrite(STDERR, "Failed to write manifest\n");
    exit(1);
}

echo "Wrote {$out}\n";
