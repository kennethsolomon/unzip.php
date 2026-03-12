<?php
// DELETE THIS FILE IMMEDIATELY AFTER USE
set_time_limit(600);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$zip_file = 'roof-guard.zip'; // Change this to your zip filename

// Simple HTML page with progress
?>
<!DOCTYPE html>
<html>
<head>
    <title>Unzip Progress</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #e0e0e0; padding: 20px; }
        .progress-bar { width: 100%; background: #333; border-radius: 4px; margin: 15px 0; }
        .progress-fill { height: 24px; background: #4CAF50; border-radius: 4px; text-align: center; line-height: 24px; color: white; transition: width 0.3s; }
        .success { color: #4CAF50; }
        .error { color: #f44336; }
        .warn { color: #ff9800; }
        .info { color: #2196F3; }
        .log { max-height: 400px; overflow-y: auto; background: #111; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .summary { background: #222; padding: 15px; border-radius: 4px; margin-top: 15px; }
    </style>
</head>
<body>
<h2>Unzip Tool</h2>
<?php
ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

function logMsg($msg, $class = '') {
    echo "<div class='{$class}'>" . htmlspecialchars($msg) . "</div>";
    flush();
}

// Step 1: Check zip file
logMsg("=== Step 1: Checking zip file ===", 'info');

if (!file_exists($zip_file)) {
    logMsg("FAILED: Zip file '{$zip_file}' not found.", 'error');
    logMsg("Files in current directory:", 'warn');
    foreach (scandir('.') as $f) {
        if ($f === '.' || $f === '..') continue;
        $size = is_file($f) ? ' (' . round(filesize($f) / 1024 / 1024, 2) . ' MB)' : ' [DIR]';
        logMsg("  - {$f}{$size}");
    }
    die("</body></html>");
}

$zipSize = round(filesize($zip_file) / 1024 / 1024, 2);
logMsg("Found: {$zip_file} ({$zipSize} MB)", 'success');

// Step 2: Open and analyze zip
logMsg("\n=== Step 2: Opening zip file ===", 'info');

$zip = new ZipArchive;
$result = $zip->open($zip_file);

if ($result !== TRUE) {
    $errors = [
        ZipArchive::ER_EXISTS => 'File already exists',
        ZipArchive::ER_INCONS => 'Zip archive inconsistent (possibly corrupted)',
        ZipArchive::ER_MEMORY => 'Memory allocation failure',
        ZipArchive::ER_NOENT => 'No such file',
        ZipArchive::ER_NOZIP => 'Not a zip archive',
        ZipArchive::ER_OPEN => 'Cannot open file',
        ZipArchive::ER_READ => 'Read error',
        ZipArchive::ER_SEEK => 'Seek error',
    ];
    $errorMsg = isset($errors[$result]) ? $errors[$result] : "Unknown error code: {$result}";
    logMsg("FAILED to open zip: {$errorMsg}", 'error');
    die("</body></html>");
}

$totalFiles = $zip->numFiles;
logMsg("Zip opened successfully. Total entries: {$totalFiles}", 'success');

// Detect subfolder prefix
$firstEntry = $zip->getNameIndex(0);
$prefix = '';
if (strpos($firstEntry, '/') !== false) {
    $prefix = explode('/', $firstEntry)[0] . '/';
    logMsg("Detected subfolder prefix: {$prefix}", 'info');
}

$zip->close();

// Step 3: Extract with progress
logMsg("\n=== Step 3: Extracting files ===", 'info');
echo '<div class="progress-bar"><div class="progress-fill" id="progress" style="width: 0%">0%</div></div>';
echo '<div class="log" id="log">';
flush();

$zip = new ZipArchive;
$zip->open($zip_file);

$extracted = 0;
$failed = 0;
$skipped = 0;
$failedFiles = [];
$dirs_created = 0;

for ($i = 0; $i < $totalFiles; $i++) {
    $entryName = $zip->getNameIndex($i);

    // Strip the subfolder prefix
    $targetPath = $entryName;
    if ($prefix && strpos($entryName, $prefix) === 0) {
        $targetPath = substr($entryName, strlen($prefix));
    }

    // Skip empty path (the root folder itself)
    if ($targetPath === '' || $targetPath === false) {
        $skipped++;
        continue;
    }

    // Skip unzip.php and the zip file itself
    if ($targetPath === 'unzip.php' || $targetPath === $zip_file) {
        $skipped++;
        continue;
    }

    // Directory entry
    if (substr($entryName, -1) === '/') {
        if (!is_dir($targetPath)) {
            if (mkdir($targetPath, 0755, true)) {
                $dirs_created++;
            } else {
                logMsg("FAILED to create dir: {$targetPath}", 'error');
                $failed++;
                $failedFiles[] = $targetPath;
            }
        }
        continue;
    }

    // File entry - ensure parent directory exists
    $parentDir = dirname($targetPath);
    if ($parentDir !== '.' && !is_dir($parentDir)) {
        if (!mkdir($parentDir, 0755, true)) {
            logMsg("FAILED to create parent dir: {$parentDir}", 'error');
            $failed++;
            $failedFiles[] = $targetPath;
            continue;
        }
        $dirs_created++;
    }

    // Extract file content
    $content = $zip->getFromIndex($i);
    if ($content === false) {
        logMsg("FAILED to read from zip: {$entryName}", 'error');
        $failed++;
        $failedFiles[] = $targetPath;
        continue;
    }

    if (file_put_contents($targetPath, $content) === false) {
        logMsg("FAILED to write: {$targetPath}", 'error');
        $failed++;
        $failedFiles[] = $targetPath;
        continue;
    }

    $extracted++;

    // Update progress every 50 files
    if ($i % 50 === 0 || $i === $totalFiles - 1) {
        $pct = round(($i + 1) / $totalFiles * 100);
        echo "<script>document.getElementById('progress').style.width='{$pct}%';document.getElementById('progress').textContent='{$pct}%';</script>";
        logMsg("Extracted {$extracted} / {$totalFiles} files...");
        flush();
    }
}

$zip->close();
echo '</div>';

// Step 4: Summary
echo '<div class="summary">';
logMsg("=== SUMMARY ===", 'info');
logMsg("Total entries in zip: {$totalFiles}");
logMsg("Files extracted: {$extracted}", 'success');
logMsg("Directories created: {$dirs_created}", 'success');
logMsg("Skipped: {$skipped}", 'warn');

if ($failed > 0) {
    logMsg("FAILED: {$failed}", 'error');
    logMsg("Failed files:", 'error');
    foreach ($failedFiles as $f) {
        logMsg("  - {$f}", 'error');
    }
} else {
    logMsg("No failures!", 'success');
}

// Step 5: Verify key WordPress directories
logMsg("\n=== Verification ===", 'info');
$checkDirs = ['wp-content', 'wp-content/themes', 'wp-content/plugins', 'wp-content/uploads', 'wp-admin', 'wp-includes'];
foreach ($checkDirs as $dir) {
    if (is_dir($dir)) {
        $count = count(scandir($dir)) - 2;
        logMsg("{$dir}/ exists ({$count} items)", 'success');
    } else {
        logMsg("{$dir}/ MISSING!", 'error');
    }
}

$checkFiles = ['wp-config.php', 'wp-load.php', 'wp-settings.php', 'index.php'];
foreach ($checkFiles as $file) {
    if (file_exists($file)) {
        logMsg("{$file} exists (" . round(filesize($file) / 1024, 1) . " KB)", 'success');
    } else {
        logMsg("{$file} MISSING!", 'error');
    }
}

echo '</div>';
logMsg("\nDone! DELETE unzip.php and the zip file from the server now!", 'warn');
?>
</body>
</html>
