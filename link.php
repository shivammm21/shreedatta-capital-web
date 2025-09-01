<?php
// link.php - verifies a signed token and redirects to the underlying form path
@session_start();
require_once __DIR__ . '/config/secret.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
if ($token === '') {
  http_response_code(400);
  echo 'Bad request';
  exit;
}

// Try short-token first (v1:<code>:<expHex>:<sig10>)
$path = '';
if (str_starts_with($token, 'v1:')) {
  $short = verify_short_token($token, $LINK_SECRET);
  if ($short) {
    // Map code to path
    $code = $short['code'];
    if ($code === 'g') $path = '/asset/forms/gold/draw/index.html';
    elseif ($code === 'c') $path = '/asset/forms/cash/draw/index.html';
    elseif ($code === 'b') $path = '/asset/forms/bike/draw/index.html';
  }
}

// Fallback: long JSON token
if ($path === '') {
  $payload = verify_signed_token($token, $LINK_SECRET);
  if ($payload) {
    $path = (string)$payload['p'];
  }
}

if ($path === '') {
  http_response_code(403);
  echo 'Invalid or expired link';
  exit;
}
// Very important: restrict to allowed directory/pattern to prevent open redirect
if (!str_starts_with($path, '/asset/forms/') || !str_ends_with($path, 'index.html')) {
  http_response_code(403);
  echo 'Path not allowed';
  exit;
}

// Build absolute URL relative to this host
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$target = $scheme . '://' . $host . $path;
// Pass through token to the destination so the form can display it
$join = (str_contains($target, '?')) ? '&' : '?';
$target .= $join . 't=' . rawurlencode($token);

header('Location: ' . $target, true, 302);
exit;
