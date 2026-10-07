<?php
declare(strict_types=1);

// Produce a native Xboard upload archive without touching an installed application.
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('PHP zip extension is required to build the archive.');
}
$root = dirname(__DIR__);
$config = json_decode(file_get_contents($root . '/FlowScope/config.json'), true, 512, JSON_THROW_ON_ERROR);
$outputDir = $root . '/dist';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}
$output = $outputDir . '/flowscope-' . $config['version'] . '.zip';
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create release archive.');
}
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/FlowScope', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $files[] = $file->getPathname();
    }
}
sort($files, SORT_STRING);
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file, strlen($root . '/FlowScope/')));
    // PluginManager resolves Str::studly('flowscope') to Flowscope, case-sensitive on Linux.
    $zip->addFile($file, 'Flowscope/' . $relative);
}
foreach (['README.md', 'DEVELOPMENT.md', 'IMPLEMENTATION_PLAN.md', 'TEST_REPORT.md', 'LICENSE'] as $document) {
    if (is_file($root . '/' . $document)) {
        $zip->addFile($root . '/' . $document, 'Flowscope/' . $document);
    }
}
// Preserve the historical test record linked by the current report.
if (is_file($root . '/docs/TEST_REPORT_1.0.1.md')) {
    $zip->addFile($root . '/docs/TEST_REPORT_1.0.1.md', 'Flowscope/docs/TEST_REPORT_1.0.1.md');
}
if (is_file($root . '/docs/VM_TEST_REPORT.md')) {
    $zip->addFile($root . '/docs/VM_TEST_REPORT.md', 'Flowscope/docs/VM_TEST_REPORT.md');
}
$collectorDir = $root . '/collector';
if (is_dir($collectorDir)) {
    $collectorFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($collectorDir, FilesystemIterator::SKIP_DOTS));
    foreach ($collectorFiles as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($collectorDir) + 1));
        if (str_contains($relative, '__pycache__/') || str_ends_with($relative, '.pyc') || str_ends_with($relative, '.sqlite3')) {
            continue;
        }
        $zip->addFile($file->getPathname(), 'Flowscope/collector/' . $relative);
    }
}
$zip->close();
echo $output . PHP_EOL . 'SHA256 ' . hash_file('sha256', $output) . PHP_EOL;
