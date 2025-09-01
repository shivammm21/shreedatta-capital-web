<?php
header('Content-Type: application/json');
$dir = __DIR__ . '/../pdfFiles';
$results = [
  'dir' => $dir,
  'dir_exists' => is_dir($dir),
  'dir_writable' => is_writable($dir),
  'whoami' => trim(shell_exec('whoami') ?? ''),
  'umask' => decoct(umask()),
  'test_file' => null,
  'write_ok' => false,
  'error' => null,
];

if (!is_dir($dir)) {
  @mkdir($dir, 0775, true);
}
$fname = $dir . '/_write_test_' . date('Ymd_His') . '.txt';
$results['test_file'] = $fname;
$ok = @file_put_contents($fname, "ok\n");
if ($ok !== false) {
  $results['write_ok'] = true;
} else {
  $results['write_ok'] = false;
  $err = error_get_last();
  $results['error'] = $err ? ($err['message'] ?? 'unknown error') : 'unknown error';
}

echo json_encode($results);
