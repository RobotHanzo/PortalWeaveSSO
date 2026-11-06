<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$files = [$root . '/wordpress-sso.php', $root . '/uninstall.php'];
foreach (['includes', 'tests', 'scripts'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') { $files[] = $file->getPathname(); }
    }
}
$failed = false;
foreach ($files as $file) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start PHP lint'); }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { $failed = true; echo $output; }
}
echo count($files) . ' PHP files checked' . ($failed ? ', failures found' : ', no syntax errors') . PHP_EOL;
exit($failed ? 1 : 0);
