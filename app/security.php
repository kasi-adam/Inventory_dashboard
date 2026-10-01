<?php
declare(strict_types=1);
/*
 * Security monitoring — built only on the existing `auth_log` table.
 *
 * What the application actually records (see log_auth() / attempt_login() in bootstrap.php):
 *   one auth_log row per sign-in attempt: user_id, username, ip, user_agent, success, created_at.
 *   - success = 1                      → successful sign-in
 *   - success = 0 and user_id NOT NULL → wrong password for an existing, active account
 *   - success = 0 and user_id IS NULL  → username not recognised (does not exist, or the account is disabled)
 *
 * What it does NOT record:
 *   - IP lock-outs. When ip_throttled() is true, attempt_login() returns before log_auth(), so blocked
 *     attempts leave no row. IP lock-outs are therefore DERIVED here by replaying the IP rule over the logged failures.
 *   - ACCOUNT lock-outs ARE recorded: the progressive lock-out writes lock_1h / lock_2h / disabled rows to
 *     account_lock_log (same timestamp as the failed attempt that caused them), and this file marks that failure.
 *   - Location / geolocation, sign-outs, password changes, admin actions. None of these are shown.
 *
 * Nothing in this file writes to the database.
 */

/* ------------------------------------------------------------------ *
 * Security Risk Indicator — scoring model
 *
 * Each observable event in the selected period earns fixed points:
 *
 *   Successful sign-in ................................  0
 *   Failed sign-in, existing account (wrong password) .  2
 *   Failed sign-in, unrecognised/disabled username .....  3   (guessing at account names)
 *   Account lock-out triggered (recorded) .............. 10   (each lock_1h / lock_2h / disabled event)
 *   IP lock-out triggered (derived) .................... 15   (4 × max_failed_logins failures from one IP
 *                                                               inside lockout_minutes)
 *
 * Score = total points, normalised to a 7-day rate when the period is longer than 7 days
 *         (score = points × 7 / days). Shorter periods use the raw total — they are never scaled up.
 *         This stops a 90-day view from reading "High" just because it covers more time.
 *
 * Level (points per 7 days):  0–9 Low · 10–29 Moderate · 30–59 Elevated · 60+ High
 *   Examples: 5 wrong passwords (10) → Moderate.  One account lock-out (5 × 2 + 10 = 20) → Moderate.
 *             Two lock-outs (40) → Elevated.  One IP lock-out (20 failures ≥ 40, + 15) → Elevated or High.
 *
 * This is a transparent activity indicator. It is NOT a probability that the system is vulnerable.
 * Weights and bands can be overridden in app/config.php with 'security_weights' / 'security_bands'.
 * ------------------------------------------------------------------ */
const SEC_WEIGHTS = ['ok' => 0, 'fail_known' => 2, 'fail_unknown' => 3, 'lock_user' => 10, 'lock_ip' => 15];
const SEC_BANDS   = [['Low', 0, 'good'], ['Moderate', 10, 'warn'], ['Elevated', 30, 'serious'], ['High', 60, 'crit']];

const SEC_PERIODS = ['24h' => ['Last 24 hours', 1], '7d' => ['Last 7 days', 7], '30d' => ['Last 30 days', 30], '90d' => ['Last 90 days', 90]];
const SEC_MAX_CUSTOM_DAYS = 366;

function sec_weights(): array { return array_merge(SEC_WEIGHTS, (array)cfg('security_weights', [])); }
function sec_bands(): array { return cfg('security_bands', SEC_BANDS); }

/**
 * Resolve the time period from the query string.
 * range = 24h | 7d | 30d | 90d | custom (with from / to as YYYY-MM-DD).
 * Returns ['key','label','from','to' (DateTimeImmutable),'days' (float),'error' (?string),'fromStr','toStr'].
 * Invalid input never reaches SQL: it falls back to the last 7 days with an error message.
 */
function sec_period(string $range, string $fromIn = '', string $toIn = ''): array
{
    $now = new DateTimeImmutable('now');
    $err = null;
    if ($range === 'custom') {
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', $fromIn);
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', $toIn);
        $ok = fn($d, $s) => $d && $d->format('Y-m-d') === $s;   // rejects 2026-02-31, 2026-9-1 etc.
        $today = $now->setTime(0, 0);
        if (!$ok($f, $fromIn) || !$ok($t, $toIn))           $err = 'Enter both dates for a custom range.';
        elseif ($f > $t)                                     $err = 'The start date must be on or before the end date.';
        elseif ($t > $today)                                 $err = 'The end date cannot be in the future.';
        elseif ($f->diff($t)->days + 1 > SEC_MAX_CUSTOM_DAYS) $err = 'A custom range can cover at most ' . SEC_MAX_CUSTOM_DAYS . ' days.';
        if (!$err) {
            $to = $t->setTime(23, 59, 59);
            if ($to > $now) $to = $now;
            $days = max(1, $f->diff($t)->days + 1);
            $label = $f->format('j M Y') . ' – ' . $t->format('j M Y');
            return ['key' => 'custom', 'label' => $label, 'from' => $f, 'to' => $to, 'days' => (float)$days, 'error' => null, 'fromStr' => $fromIn, 'toStr' => $toIn];
        }
        $range = '7d';
    }
    if (!isset(SEC_PERIODS[$range])) $range = '7d';
    [$label, $days] = SEC_PERIODS[$range];
    return ['key' => $range, 'label' => $label, 'from' => $now->modify("-$days days"), 'to' => $now, 'days' => (float)$days,
            'error' => $err, 'fromStr' => $fromIn, 'toStr' => $toIn];
}

/**
 * Fetch auth_log rows for the period (prepared statement, existing db() connection).
 * Failures from one lock-out window BEFORE the period are also read, only so that a lock-out
 * which completes inside the period is detected correctly; they are not counted or shown.
 */
function sec_rows(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $win = (int)cfg('lockout_minutes', 15) * 60;
    $st = db()->prepare('SELECT id, user_id, username, ip, user_agent, success, created_at FROM auth_log WHERE created_at >= ? AND created_at <= ? ORDER BY created_at, id');
    $st->execute([$from->modify("-$win seconds")->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
    $rows = $st->fetchAll();
    $fromTs = $from->getTimestamp();
    foreach ($rows as &$r) {
        $r['ts'] = strtotime($r['created_at']);
        $r['in'] = $r['ts'] >= $fromTs;
        $r['kind'] = (int)$r['success'] ? 'ok' : ($r['user_id'] === null ? 'fail_unknown' : 'fail_known');
        $r['lock_user'] = $r['lock_ip'] = false;
        $r['lock_event'] = '';
    }
    unset($r);
    // Account lock-outs are recorded: mark the failed attempt that triggered each one (same username, same second).
    $ev = db()->prepare("SELECT username, event, created_at FROM account_lock_log WHERE event IN ('lock_1h','lock_2h','disabled') AND created_at >= ? AND created_at <= ?");
    $ev->execute([$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
    $locks = [];
    foreach ($ev->fetchAll() as $e) $locks[mb_strtolower($e['username']) . '|' . $e['created_at']] = $e['event'];
    foreach ($rows as $i => $r) {
        if ((int)$r['success']) continue;
        $k = mb_strtolower($r['username']) . '|' . $r['created_at'];
        if (isset($locks[$k])) { $rows[$i]['lock_user'] = true; $rows[$i]['lock_event'] = $locks[$k]; unset($locks[$k]); }
    }
    return sec_mark_lockouts($rows);
}

/**
 * Replay the IP throttle (bootstrap.php → ip_throttled()) over the logged failures.
 * An IP lock-out is "triggered" by the failure that brings the count inside the rolling window up to
 * 4 × max_failed_logins for one IP address. Only the transition is marked, so one lock-out = one event.
 * (Account lock-outs are recorded, not replayed; see sec_rows().)
 */
function sec_mark_lockouts(array $rows): array
{
    $win = (int)cfg('lockout_minutes', 15) * 60;
    $max = max(1, (int)cfg('max_failed_logins', 5));
    foreach ([['ip', $max * 4, 'lock_ip']] as [$key, $limit, $flag]) {
        $recent = [];                                   // key => timestamps of failures still inside the window
        foreach ($rows as $i => $r) {
            if ((int)$r['success']) continue;
            $k = $r['ip'];
            $q = array_values(array_filter($recent[$k] ?? [], fn($t) => $t > $r['ts'] - $win));
            $before = count($q);
            $q[] = $r['ts'];
            $recent[$k] = $q;
            if ($before < $limit && count($q) >= $limit) $rows[$i][$flag] = true;
        }
    }
    return $rows;
}

/** Totals, risk points and level for the rows inside the period. */
function sec_summary(array $rows, float $days): array
{
    $w = sec_weights();
    $c = ['ok' => 0, 'fail_known' => 0, 'fail_unknown' => 0, 'lock_user' => 0, 'lock_ip' => 0];
    $users = $ips = $failIps = [];
    foreach ($rows as $r) {
        if (!$r['in']) continue;
        $c[$r['kind']]++;
        if ($r['lock_user']) $c['lock_user']++;
        if ($r['lock_ip']) $c['lock_ip']++;
        if ($r['kind'] === 'ok') $users[mb_strtolower($r['username'])] = true;
        $ips[$r['ip']] = true;
        if ($r['kind'] !== 'ok') $failIps[$r['ip']] = true;
    }
    $points = 0; $parts = [];
    foreach ($c as $k => $n) {
        $p = $n * ($w[$k] ?? 0);
        $points += $p;
        if ($n && $p) $parts[$k] = [$n, $p];
    }
    // Normalise to a 7-day rate for long periods only (never scale short periods up).
    $score = $days > 7 ? $points * 7 / $days : (float)$points;
    $level = sec_bands()[0];
    foreach (sec_bands() as $b) if ($score >= $b[1]) $level = $b;
    return [
        'counts' => $c, 'failed' => $c['fail_known'] + $c['fail_unknown'], 'total' => $c['ok'] + $c['fail_known'] + $c['fail_unknown'],
        'lockouts' => $c['lock_user'] + $c['lock_ip'], 'people' => count($users), 'ips' => count($ips), 'fail_ips' => count($failIps),
        'points' => $points, 'score' => round($score, 1), 'normalised' => $days > 7,
        'level' => $level[0], 'tone' => $level[2], 'parts' => $parts,
    ];
}

/** Human-readable labels for the contributing activity list. */
function sec_part_labels(): array
{
    return ['fail_known' => ['failed sign-in for an existing account', 'failed sign-ins for existing accounts'],
            'fail_unknown' => ['failed sign-in with an unrecognised username', 'failed sign-ins with unrecognised usernames'],
            'lock_user' => ['account lock-out', 'account lock-outs'], 'lock_ip' => ['IP address lock-out', 'IP address lock-outs']];
}
/** One-line reasons, e.g. ["14 failed sign-ins for existing accounts (28 pts)", …]. */
function sec_reasons(array $s): array
{
    $L = sec_part_labels(); $out = [];
    foreach ($s['parts'] as $k => [$n, $p]) $out[] = $n . ' ' . $L[$k][$n === 1 ? 0 : 1] . ' (' . $p . ' pts)';
    return $out;
}
/** Plain-text explanation for a tooltip / title attribute. */
function sec_explain(array $s, string $periodLabel): string
{
    $r = sec_reasons($s);
    $head = 'Security Risk: ' . $s['level'] . ' — ' . ($s['normalised'] ? $s['score'] . ' points per 7 days' : $s['points'] . ' points') . ', ' . mb_strtolower($periodLabel) . '.';
    return $head . ($r ? ' Contributing activity: ' . implode('; ', $r) . '.' : ' No failed sign-ins or lock-outs.');
}

/**
 * Split the period into chart buckets: hourly up to 48 h, daily up to 62 days, weekly beyond.
 * Each bucket: ['t0','t1','label','ok','fail_known','fail_unknown','locks'].
 */
function sec_buckets(array $rows, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $span = max(1, $to->getTimestamp() - $from->getTimestamp());
    if ($span <= 48 * 3600) { $step = 3600; $start = $from->setTime((int)$from->format('H'), 0); $fmt = 'H:i'; }
    elseif ($span <= 62 * 86400) { $step = 86400; $start = $from->setTime(0, 0); $fmt = 'j M'; }
    else { $step = 7 * 86400; $start = $from->setTime(0, 0); $fmt = 'j M'; }
    $b = [];
    for ($t = $start->getTimestamp(); $t <= $to->getTimestamp(); $t += $step) {
        $b[] = ['t0' => $t, 't1' => $t + $step, 'label' => date($fmt, $t), 'full' => $step === 3600 ? date('j M, H:00', $t) : ($step === 86400 ? date('D j M Y', $t) : 'Week of ' . date('j M Y', $t)),
                'ok' => 0, 'fail_known' => 0, 'fail_unknown' => 0, 'locks' => 0];
    }
    $t0 = $start->getTimestamp();
    foreach ($rows as $r) {
        if (!$r['in']) continue;
        $i = intdiv($r['ts'] - $t0, $step);
        if (!isset($b[$i])) continue;
        $b[$i][$r['kind']]++;
        if ($r['lock_user'] || $r['lock_ip']) $b[$i]['locks']++;
    }
    return ['buckets' => $b, 'step' => $step];
}

/** Round an axis maximum up to 1, 2, 5 × 10ⁿ. */
function sec_nice_max(int $v): int
{
    if ($v <= 4) return max(4, $v);
    $p = 10 ** (int)floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $p >= $v) return (int)ceil($m * $p);
    return $v;
}

/**
 * Stacked column chart built from HTML/CSS (no SVG scaling), so labels stay readable at any width
 * and bars never leave their card. Colours come from theme variables → works in light and dark.
 */
function sec_chart(array $data, string $emptyText): string
{
    $b = $data['buckets']; $n = count($b);
    $max = 0;
    foreach ($b as $x) $max = max($max, $x['ok'] + $x['fail_known'] + $x['fail_unknown']);
    if ($max === 0) return '<div class="secchart empty" role="img" aria-label="' . e($emptyText) . '"><p>' . icon('shield') . e($emptyText) . '</p></div>';
    $top = sec_nice_max($max);
    $every = max(1, (int)ceil($n / 8));                 // about 8 x-axis labels on wide screens
    $h = '<div class="secchart" role="img" aria-label="Sign-in activity: successful and failed attempts per ' . ($data['step'] === 3600 ? 'hour' : ($data['step'] === 86400 ? 'day' : 'week')) . '">';
    $h .= '<div class="yax" aria-hidden="true"><span>' . $top . '</span><span>' . ($top / 2 == (int)($top / 2) ? $top / 2 : '') . '</span><span>0</span></div>';
    $h .= '<div class="plot"><div class="grid" aria-hidden="true"><i></i><i></i><i></i></div><div class="cols" style="--n:' . $n . '">';
    foreach ($b as $i => $x) {
        $tot = $x['ok'] + $x['fail_known'] + $x['fail_unknown'];
        $tip = $x['full'] . ': ' . $x['ok'] . ' successful, ' . ($x['fail_known'] + $x['fail_unknown']) . ' failed'
            . ($x['fail_unknown'] ? ' (' . $x['fail_unknown'] . ' unrecognised username)' : '') . ($x['locks'] ? ', ' . $x['locks'] . ' lock-out' . ($x['locks'] > 1 ? 's' : '') : '');
        $h .= '<div class="col" title="' . e($tip) . '">' . ($x['locks'] ? '<b class="lock" aria-hidden="true"></b>' : '') . '<div class="stack" style="height:' . round($tot / $top * 100, 2) . '%">';
        foreach (['fail_unknown' => 's-unk', 'fail_known' => 's-fail', 'ok' => 's-ok'] as $k => $cls) {
            if ($x[$k]) $h .= '<i class="' . $cls . '" style="flex:' . $x[$k] . '"></i>';
        }
        $h .= '</div></div>';
    }
    $h .= '</div></div><div class="xax" style="--n:' . $n . '" aria-hidden="true">';
    foreach ($b as $i => $x) {
        $show = $i % $every === 0;
        $h .= '<span' . ($show ? (($i / $every) % 2 ? ' class="alt"' : '') : ' class="off"') . '>' . ($show ? e($x['label']) : '') . '</span>';
    }
    return $h . '</div></div>';
}

/** Daily failed-attempt counts for a sparkline (dashboard card). */
function sec_daily_failed(array $rows, DateTimeImmutable $from, int $days): array
{
    $out = array_fill(0, $days, 0);
    $t0 = $from->getTimestamp();
    foreach ($rows as $r) {
        if (!$r['in'] || $r['kind'] === 'ok') continue;
        $i = min($days - 1, intdiv($r['ts'] - $t0, 86400));
        if ($i >= 0) $out[$i]++;
    }
    return $out;
}

/** Earliest record in auth_log (for the limited-history note). */
function sec_first_record(): ?string
{
    $v = db()->query('SELECT MIN(created_at) FROM auth_log')->fetchColumn();
    return $v ?: null;
}

/** Short browser label from a user-agent string (same rules as the User Management page). */
function sec_browser(string $ua): string
{
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $k => $n) if (str_contains($ua, $k)) {
        $os = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'iPhone') ? 'iPhone' : (str_contains($ua, 'Mac') ? 'Mac' : (str_contains($ua, 'Linux') ? 'Linux' : ''))));
        return $n . ($os ? " · $os" : '');
    }
    return $ua === '' ? '—' : 'Other';
}
