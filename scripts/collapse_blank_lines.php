<?php
/**
 * Collapse excess blank lines in PHP files left by removed multi-line comments.
 *
 * Rules:
 *  - Runs of 3+ consecutive blank lines → 1 blank line
 *  - Blank lines at start of file (after <?php) → removed
 *  - Blank lines at end of file → removed
 *  - Preserves exactly 1 blank line between code sections
 *
 * Usage: php scripts/collapse_blank_lines.php [--dry-run] [--verbose]
 */

$dryRun = in_array('--dry-run', $argv ?? []);
$verbose = in_array('--verbose', $argv ?? []);

$projectRoot = realpath(__DIR__ . '/..');

$skipPrefixes = [
    'vendor/',
    'storage/framework/',
    'storage/logs/',
    'bootstrap/cache/',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS)
);

$phpFiles = [];
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getRealPath();
    $relativePath = str_replace($projectRoot . '/', '', $path);

    $skip = false;
    foreach ($skipPrefixes as $prefix) {
        if (str_starts_with($relativePath, $prefix)) { $skip = true; break; }
    }
    if ($skip) continue;

    $phpFiles[] = $relativePath;
}

sort($phpFiles);

$totalFilesChanged = 0;
$totalBlankLinesRemoved = 0;

foreach ($phpFiles as $relativePath) {
    $fullPath = $projectRoot . '/' . $relativePath;
    $content = file_get_contents($fullPath);
    if ($content === false) {
        echo "  ERROR: Could not read $relativePath\n";
        continue;
    }

    $original = $content;

    // Split into lines, preserving line endings
    $lines = preg_split('/(\r\n|\n|\r)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $lineEnding = "\n";
    // Detect line ending
    if (str_contains($content, "\r\n")) $lineEnding = "\r\n";
    elseif (str_contains($content, "\r")) $lineEnding = "\r";
    else $lineEnding = "\n";

    // Reconstruct without line ending delimiters
    $lines = explode($lineEnding, $content);

    $result = [];
    $blankCount = 0;
    $inLeadingBlanks = true;

    foreach ($lines as $i => $line) {
        // Check for blank line (empty or whitespace only)
        if (trim($line) === '') {
            $blankCount++;
            continue;
        }

        // Non-blank line
        if ($blankCount > 0) {
            // Skip leading blanks (before any code)
            if ($inLeadingBlanks) {
                $blankCount = 0;
                // Add just the first non-blank line
                $result[] = $line;
                $inLeadingBlanks = false;
                continue;
            }
            // Add exactly 1 blank line before the non-blank content
            $result[] = '';
            $totalBlankLinesRemoved += ($blankCount - 1);
            $blankCount = 0;
        }
        $inLeadingBlanks = false;
        $result[] = $line;
    }

    // Remove trailing blank lines
    while (!empty($result) && trim(end($result)) === '') {
        array_pop($result);
        $totalBlankLinesRemoved++;
    }

    // Rebuild content
    $newContent = implode($lineEnding, $result);

    // Add trailing newline
    if (substr($newContent, -1) !== "\n" && substr($newContent, -1) !== "\r") {
        $newContent .= $lineEnding;
    }

    $originalLineCount = count(explode($lineEnding, $original));
    $newLineCount = count(explode($lineEnding, $newContent));
    $linesRemoved = $originalLineCount - $newLineCount;

    if ($linesRemoved > 0) {
        if ($verbose) {
            echo "  $relativePath: $linesRemoved blank lines removed ($originalLineCount → $newLineCount lines)\n";
        }
        $totalFilesChanged++;
        $totalBlankLinesRemoved += $linesRemoved - ($originalLineCount - $newLineCount); // adjust
    }

    if ($dryRun) continue;

    file_put_contents($fullPath, $newContent);
}

echo "\n";
echo "═══════════════════════════════════════════════\n";
if ($dryRun) echo " DRY RUN — NO FILES WERE MODIFIED\n";
echo " Files processed:  " . count($phpFiles) . "\n";
echo " Files with changes: " . $totalFilesChanged . "\n";
echo " Total blank lines removed: " . $totalBlankLinesRemoved . "\n";
echo "═══════════════════════════════════════════════\n";
