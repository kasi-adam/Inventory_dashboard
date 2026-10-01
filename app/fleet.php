<?php
declare(strict_types=1);

const LOCATIONS  = ['Head Office', 'Northern Area', 'Sunyani Area', 'Techiman Area', 'Upper East Area', 'Upper West Area'];
const STATUSES   = ['In Use', 'In Repair', 'Damaged'];
const SCHEDULES  = ['Quarterly' => 91, 'Biannually' => 182, 'Annually' => 365];
const LEVELS     = ['Low' => 0, 'Average' => 1, 'High' => 2];
const OS_FAMILIES = ['Windows 11', 'Windows 10', 'Windows 7', 'Windows (version not recorded)', 'Not recorded', 'Not applicable'];

/** Problems the Asset Finder and the table filter can look for. key => [label, test] */
function issues(): array
{
    return [
        'maint'    => ['Maintenance overdue',            fn($a) => $a['maint_overdue']],
        'cond'     => ['Damaged or in repair',           fn($a) => $a['status'] !== 'In Use'],
        'os'       => ['Unsupported Windows',            fn($a) => $a['unsupported']],
        'win7'     => ['Still on Windows 7',             fn($a) => $a['os_family'] === 'Windows 7'],
        'audit'    => ['Audit overdue (over 12 months)', fn($a) => $a['audit_status'] === 'Over 12 months'],
        'old'      => ['Old equipment (8+ years)',       fn($a) => $a['age'] >= 8],
        'backup'   => ['No backup',                      fn($a) => !$a['backup']],
        'security' => ['No security controls',           fn($a) => !$a['security_controls']],
        'critical' => ['Highly critical assets',         fn($a) => $a['criticality'] === 'High'],
        'all'      => ['All assets',                     fn($a) => true],
    ];
}

function as_of(): DateTimeImmutable
{
    $d = cfg('as_of');
    return $d ? new DateTimeImmutable($d) : new DateTimeImmutable('today');
}

function os_support(string $os): string
{
    return match ($os) {
        'Windows 7'      => 'End of support (Jan 2020)',
        'Windows 10'     => 'End of support (Oct 2025)',
        'Windows 11'     => 'Supported',
        'Not applicable' => 'Not applicable',
        default          => 'Unknown',
    };
}

function days_between(?string $from, DateTimeImmutable $to): ?int
{
    if (!$from) return null;
    $f = new DateTimeImmutable($from);
    return (int)$f->diff($to)->format('%r%a');
}

/** Adds the calculated fields (age, overdue days, audit status, Attention Score…) to a raw DB row. */
function derive(array $a, DateTimeImmutable $asOf): array
{
    foreach (['backup', 'security_controls', 'remote_access'] as $b) $a[$b] = (bool)$a[$b];
    $a['id'] = (int)$a['id'];
    $a['completeness'] = (int)$a['completeness'];
    $a['ram_gb'] = $a['ram_gb'] === null ? null : (int)$a['ram_gb'];
    $a['storage_gb'] = $a['storage_gb'] === null ? null : (int)$a['storage_gb'];

    $ageDays = days_between($a['purchase_date'], $asOf);
    $a['age'] = $ageDays === null ? 0.0 : round($ageDays / 365.25, 1);
    $a['age_band'] = $a['age'] >= 8 ? '8+ yrs' : ($a['age'] >= 5 ? '5-8 yrs' : ($a['age'] >= 3 ? '3-5 yrs' : '0-3 yrs'));
    $a['purchase_year'] = $a['purchase_date'] ? (int)substr($a['purchase_date'], 0, 4) : null;

    $since = days_between($a['last_maintenance'], $asOf);
    $interval = SCHEDULES[$a['maint_schedule']] ?? 91;
    $a['days_since_maint'] = $since;
    $a['maint_days_overdue'] = $since === null ? 0 : max(0, $since - $interval);
    $a['maint_overdue'] = $a['maint_days_overdue'] > 0;

    $audit = days_between($a['last_audit'], $asOf);
    $a['audit_status'] = $audit === null ? 'Never audited' : ($audit < 0 ? 'Future date (data error)' : ($audit > 365 ? 'Over 12 months' : 'Within 12 months'));

    $a['os_support'] = os_support($a['os_family']);
    $a['unsupported'] = str_starts_with($a['os_support'], 'End of support');
    $a['is_pc'] = in_array($a['category'], ['Desktop', 'Laptop'], true);

    // Attention Score 0–100 (same weights as the Excel workbook; halves round to even, as in the workbook).
    $s = 10 * (LEVELS[$a['risk_rating']] ?? 0) + 7.5 * (LEVELS[$a['business_impact']] ?? 0) + 7.5 * (LEVELS[$a['criticality']] ?? 0)
       + ($a['unsupported'] ? 15 : 0) + ($a['maint_overdue'] ? 10 : 0) + ($a['backup'] ? 0 : 10) + ($a['security_controls'] ? 0 : 10)
       + ($a['status'] !== 'In Use' ? 5 : 0) + ($a['age'] >= 8 ? 5 : 0);
    $a['score'] = (int)min(100, round($s, 0, PHP_ROUND_HALF_EVEN));
    $a['band'] = $a['score'] >= 60 ? 'crit' : ($a['score'] >= 40 ? 'serious' : 'good');
    $a['label'] = trim(($a['brand'] !== 'Mixed' && $a['brand'] !== 'Other' ? $a['brand'] . ' ' : '') . $a['model']);

    $flags = [];
    if ($a['unsupported']) $flags[] = [$a['os_family'], 1];
    if ($a['maint_overdue']) $flags[] = ['Maint. overdue ' . $a['maint_days_overdue'] . 'd', $a['maint_days_overdue'] > 365];
    if ($a['audit_status'] === 'Over 12 months') $flags[] = ['Audit overdue', 0];
    if (!$a['backup']) $flags[] = ['No backup', 1];
    if (!$a['security_controls']) $flags[] = ['No security controls', 1];
    if ($a['status'] !== 'In Use') $flags[] = [$a['status'], 1];
    if ($a['age'] >= 8) $flags[] = ['8+ yrs old', 0];
    $a['flags'] = $flags;
    return $a;
}

/** All assets in scope (high-risk devices left out when configured), with calculated fields. */
function all_assets(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $sql = 'SELECT * FROM assets' . (cfg('exclude_high_risk', true) ? " WHERE risk_rating <> 'High'" : '') . ' ORDER BY id';
    $asOf = as_of();
    return $cache = array_map(fn($r) => derive($r, $asOf), db()->query($sql)->fetchAll());
}

function get_asset(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM assets WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? derive($r, as_of()) : null;
}

function in_location(array $assets, string $loc): array
{
    return $loc === '' ? $assets : array_values(array_filter($assets, fn($a) => $a['location'] === $loc));
}
function count_if(array $assets, callable $f): int { return count(array_filter($assets, $f)); }
function avg_of(array $assets, string $k): float { return $assets ? array_sum(array_column($assets, $k)) / count($assets) : 0.0; }
function pct(int|float $a, int|float $b): int { return $b ? (int)round($a / $b * 100) : 0; }
function sev(int $p, int $warn, int $crit): string { return $p >= $crit ? 'crit' : ($p >= $warn ? 'serious' : ($p > 0 ? 'warn' : 'good')); }
function short_loc(string $l): string { return str_replace(' Area', '', $l); }

/** Sort by Attention Score, then days overdue. */
function by_priority(array $assets): array
{
    usort($assets, fn($x, $y) => [$y['score'], $y['maint_days_overdue']] <=> [$x['score'], $x['maint_days_overdue']]);
    return $assets;
}

function search_assets(array $assets, string $term): array
{
    if ($term === '') return $assets;
    $t = mb_strtolower($term);
    return array_values(array_filter($assets, fn($a) => str_contains(mb_strtolower(implode(' ', [
        $a['serial_number'], $a['user_name'], $a['model'], $a['brand'], $a['category'], $a['location']])), $t)));
}

function years_range(): array { return range(2010, (int)as_of()->format('Y')); }
/** Counts per purchase year; 2010 bucket includes everything older. */
function per_year(array $assets, ?callable $f = null): array
{
    $out = array_fill_keys(years_range(), 0);
    foreach ($assets as $a) {
        if ($f && !$f($a)) continue;
        $y = max(2010, (int)$a['purchase_year']);
        if (isset($out[$y])) $out[$y]++;
    }
    return $out;
}

function location_scorecard(array $assets): array
{
    $rows = [];
    foreach (LOCATIONS as $loc) {
        $g = in_location($assets, $loc);
        $pcs = array_filter($g, fn($a) => $a['is_pc']);
        $rows[] = [
            'loc' => $loc, 'n' => count($g),
            'overdue' => pct(count_if($g, fn($a) => $a['maint_overdue']), count($g)),
            'unsupported' => pct(count_if($pcs, fn($a) => $a['unsupported']), count($pcs)),
            'audit' => count_if($g, fn($a) => $a['audit_status'] === 'Over 12 months'),
            'backup' => count_if($g, fn($a) => !$a['backup']),
            'age' => avg_of($g, 'age'), 'score' => avg_of($g, 'score'),
        ];
    }
    return $rows;
}

function csv_download(string $filename, array $assets): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['serial_number' => 'Serial Number', 'category' => 'Category', 'brand' => 'Brand', 'model' => 'Model', 'status' => 'Status',
        'location' => 'Location', 'user_name' => 'User', 'os_family' => 'OS Family', 'os_support' => 'OS Support', 'ram_gb' => 'RAM (GB)',
        'storage_gb' => 'Storage (GB)', 'purchase_date' => 'Date of Purchase', 'age' => 'Age (yrs)', 'maint_schedule' => 'Maintenance Schedule',
        'last_maintenance' => 'Last Maintenance', 'maint_days_overdue' => 'Maint Days Overdue', 'last_audit' => 'Last Security Audit',
        'audit_status' => 'Audit Status', 'business_impact' => 'Business Impact', 'criticality' => 'Criticality Level',
        'security_class' => 'Security Classification', 'backup' => 'Backup Availability', 'security_controls' => 'Security Controls',
        'remote_access' => 'Remote Access', 'network_zone' => 'Network Zone', 'cia_class' => 'CIA Classification',
        'score' => 'Attention Score', 'completeness' => 'Completeness %'];
    fputcsv($out, array_values($cols), ',', '"', '\\');
    foreach ($assets as $a) {
        fputcsv($out, array_map(fn($k) => is_bool($a[$k]) ? ($a[$k] ? 'Yes' : 'No') : $a[$k], array_keys($cols)), ',', '"', '\\');
    }
    exit;
}
