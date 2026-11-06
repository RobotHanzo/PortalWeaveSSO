<?php
/** Machine-readable reports shared by the CLI-only integration runners. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function wpsso_report_xml(string $value): string {
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function wpsso_write_reports(string $suite, array $results): void {
    $directory = getenv('WPSSO_REPORT_DIR');
    if ($directory === false || $directory === '') { return; }
    if (!preg_match('/^[a-z][a-z0-9-]*$/D', $suite)) { throw new RuntimeException('Invalid report suite name.'); }
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the test report directory.');
    }
    $failures = count(array_filter($results, static fn($result) => !$result['pass']));
    $duration = array_sum(array_column($results, 'duration'));
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<testsuites tests="' . count($results) . '" failures="' . $failures . '" errors="0" time="' . number_format($duration, 6, '.', '') . '">';
    $xml .= '<testsuite name="' . wpsso_report_xml($suite) . '" tests="' . count($results) . '" failures="' . $failures . '" errors="0" skipped="0" time="' . number_format($duration, 6, '.', '') . '">';
    foreach ($results as $result) {
        $xml .= '<testcase classname="wpsso.' . $suite . '" name="' . wpsso_report_xml($result['name']) . '" time="' . number_format((float) ($result['duration'] ?? 0), 6, '.', '') . '">';
        if (!$result['pass']) {
            $message = (string) ($result['error'] ?? 'Test failed.');
            $xml .= '<failure message="' . wpsso_report_xml($message) . '" type="AssertionFailure">' . wpsso_report_xml($message) . '</failure>';
        }
        $xml .= '</testcase>';
    }
    $xml .= '</testsuite></testsuites>' . "\n";
    $json = json_encode(['suite' => $suite, 'tests' => array_values($results), 'summary' => [
        'total' => count($results), 'passed' => count($results) - $failures, 'failed' => $failures, 'duration' => $duration,
    ]], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    foreach (['json' => $json, 'xml' => $xml] as $extension => $body) {
        if (file_put_contents($directory . DIRECTORY_SEPARATOR . $suite . '.' . $extension, $body, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the ' . $suite . ' test report.');
        }
    }
}

function wpsso_report_bootstrap(string $suite): void {
    $GLOBALS['wpsso_results'] = [];
    $GLOBALS['wpsso_report_complete'] = false;
    register_shutdown_function(static function () use ($suite) {
        if ($GLOBALS['wpsso_report_complete']) { return; }
        $last = error_get_last();
        $results = $GLOBALS['wpsso_results'];
        $results[] = ['name' => 'Test runner did not complete', 'pass' => false, 'duration' => 0,
            'error' => isset($last['message']) ? substr($last['message'], 0, 4000) : 'The process exited before the suite completed.'];
        try { wpsso_write_reports($suite, $results); }
        catch (Throwable $error) { fwrite(STDERR, 'Test reporting failed: ' . $error->getMessage() . "\n"); }
        // An early exit(0) must not turn a missing or incomplete suite green.
        exit(1);
    });
}

function wpsso_report_finish(string $suite, array $results, string $noise = '', string $label = 'tests'): int {
    if (trim($noise) !== '') {
        $results[] = ['name' => 'Unexpected test output', 'pass' => false, 'duration' => 0, 'error' => substr($noise, 0, 4000)];
    }
    if (!$results) {
        $results[] = ['name' => 'No tests executed', 'pass' => false, 'duration' => 0, 'error' => 'The runner produced no test cases.'];
    }
    $failures = 0;
    foreach ($results as $result) {
        echo ($result['pass'] ? 'PASS ' : 'FAIL ') . $result['name'] . (isset($result['error']) ? ': ' . $result['error'] : '') . "\n";
        $failures += $result['pass'] ? 0 : 1;
    }
    $GLOBALS['wpsso_report_complete'] = true;
    try { wpsso_write_reports($suite, $results); }
    catch (Throwable $error) { fwrite(STDERR, 'Test reporting failed: ' . $error->getMessage() . "\n"); $failures++; }
    echo count($results) . ' ' . $label . ', ' . $failures . " failures\n";
    return $failures ? 1 : 0;
}
