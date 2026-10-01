<?php
declare(strict_types=1);
/*
 * Search suggestions — a compact index of the values already stored in the asset register, used by
 * assets/suggest.js to filter suggestions in the browser. Read-only; nothing is written.
 *
 *   - Signed-in users only (same require_login() as every other page). Anyone not signed in gets 401.
 *   - Data comes from all_assets(), the same function the Assets page and the dashboard search use, so it
 *     already follows the 'exclude_high_risk' setting. Optional ?loc= limits it to one location, exactly
 *     like the page's own location filter (only values from LOCATIONS are accepted).
 *   - Only the six fields the search itself looks at are used: user_name, model, brand, category,
 *     location, serial_number. No application accounts, passwords, sign-in data or other columns.
 *   - No SQL here, so there is no query text to inject into; the response is JSON and never HTML.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (current_user() === null) {
    http_response_code(401);
    echo '{"error":"Sign in required."}';
    exit;
}
require_login();   // same idle-timeout and "account still active" checks as the pages

$loc = in_array(q('loc'), LOCATIONS, true) ? q('loc') : '';   // only a known location is accepted
$A = in_location(all_assets(), $loc);

/** Tidy whitespace; skip empty values and placeholders such as "-", "N/A" or "None". */
$clean = function ($v): string {
    $v = trim((string)preg_replace('/\s+/u', ' ', (string)$v));
    if ($v === '' || !preg_match('/[\p{L}\p{N}]{2,}/u', $v)) return '';
    if (preg_match('~^(n\s*[\\\\/]?\s*a|none|nil|unknown|not applicable)$~iu', $v)) return '';
    return $v;
};

/** value => count of assets, merged case-insensitively; the most common spelling is shown. */
$tally = function (string $field, array $skip = []) use ($A, $clean): array {
    $seen = [];   // lower-case key => [spelling => n]
    foreach ($A as $a) {
        $v = $clean($a[$field] ?? '');
        if ($v === '' || in_array(mb_strtolower($v), $skip, true)) continue;
        $seen[mb_strtolower($v)][$v] = ($seen[mb_strtolower($v)][$v] ?? 0) + 1;
    }
    $out = [];
    foreach ($seen as $variants) {
        arsort($variants);
        $out[] = [(string)array_key_first($variants), array_sum($variants)];
    }
    usort($out, fn($x, $y) => [$y[1], mb_strtolower($x[0])] <=> [$x[1], mb_strtolower($y[0])]);
    return $out;
};

// Serial numbers: unique, case-insensitive, with the asset's make/model as a small hint to tell look-alikes apart.
$serials = [];
foreach ($A as $a) {
    $v = $clean($a['serial_number'] ?? '');
    if ($v === '' || isset($serials[mb_strtolower($v)])) continue;
    $serials[mb_strtolower($v)] = [$v, $clean($a['label'] ?? '')];
}

echo json_encode([
    'u' => $tally('user_name'),
    'm' => $tally('model'),
    'b' => $tally('brand', ['mixed', 'other']),   // "Mixed" / "Other" are catch-all values, not real brands
    'c' => $tally('category'),
    'l' => $tally('location'),
    's' => array_values($serials),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
