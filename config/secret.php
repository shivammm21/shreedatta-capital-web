<?php
// Secret used to sign encrypted links. Keep this file private.
// You may rotate this value; doing so will invalidate previously issued links.
$LINK_SECRET = 'b3a1f7c6f4c24d1bb5b0c0c8c7a3e2a1'; // random 32-hex chars

/**
 * Base64 URL-safe encoding without padding
 */
function b64u_enc(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Create a very short token for a known path code (g,c,b) with expiry.
 * Format: v1:<code>:<expHex>:<sig10>
 *   where sig10 = first 10 hex chars of HMAC-SHA256(code|expHex)
 */
function make_short_token(string $code, int $exp, string $secret): string {
    $code = strtolower($code);
    if (!in_array($code, ['g','c','b'], true)) $code = 'g';
    $expHex = dechex($exp);
    $sig = hash_hmac('sha256', $code.'|'.$expHex, $secret);
    $sig10 = substr($sig, 0, 10);
    return 'v1:'.$code.':'.$expHex.':'.$sig10;
}

/**
 * Verify short token. Returns ['code'=>g/c/b, 'exp'=>int] or null.
 */
function verify_short_token(string $token, string $secret): ?array {
    $parts = explode(':', $token);
    if (count($parts) !== 4) return null;
    [$v, $code, $expHex, $sig10] = $parts;
    if ($v !== 'v1') return null;
    $code = strtolower($code);
    if (!in_array($code, ['g','c','b'], true)) return null;
    if (!ctype_xdigit($expHex) || !ctype_xdigit($sig10)) return null;
    $calc = hash_hmac('sha256', $code.'|'.$expHex, $secret);
    if (!hash_equals(substr($calc, 0, 10), $sig10)) return null;
    $exp = hexdec($expHex);
    if (time() > $exp) return null;
    return ['code' => $code, 'exp' => $exp];
}

/**
 * Base64 URL-safe decoding
 */
function b64u_dec(string $data): string|false {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'), true);
}

/**
 * Create a signed token for a path with expiry (unix timestamp)
 */
function make_signed_token(string $path, int $exp, string $secret): string {
    $payload = ['p' => $path, 'exp' => $exp];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $p = b64u_enc($json);
    $sig = hash_hmac('sha256', $p, $secret, true);
    return $p . '.' . b64u_enc($sig);
}

/**
 * Verify a signed token. Returns payload array on success or null on failure.
 */
function verify_signed_token(string $token, string $secret): ?array {
    if (!str_contains($token, '.')) return null;
    [$p, $s] = explode('.', $token, 2);
    $calc = hash_hmac('sha256', $p, $secret, true);
    $sig = b64u_dec($s);
    if ($sig === false || !hash_equals($calc, $sig)) return null;
    $json = b64u_dec($p);
    if ($json === false) return null;
    $payload = json_decode($json, true);
    if (!is_array($payload) || !isset($payload['p'], $payload['exp'])) return null;
    if (time() > (int)$payload['exp']) return null;
    return $payload;
}
