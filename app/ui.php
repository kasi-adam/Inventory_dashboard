<?php
declare(strict_types=1);

const ICONS = [
    'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
    'list'   => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'pin'    => '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
    'chart'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/>',
    'users'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5c2 .6 3.2 2.5 3.5 5.5"/>',
    'key'    => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3"/>',
    'logout' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
    'box'    => '<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7"/>',
    'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-.5-.5-2.5z"/>',
    'win'    => '<path d="M3 5l8-1v8H3zM13 3.8 21 3v9h-8zM3 13h8v7l-8-1zM13 13h8v8l-8-1z"/>',
    'shield' => '<path d="M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
    'cal'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    'gauge'  => '<path d="M4 18a8 8 0 1 1 16 0"/><path d="M12 18l4-6"/>',
    'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'x'      => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
    'alert'  => '<path d="M12 3l10 18H2z"/><path d="M12 10v4M12 17.5v.5"/>',
    'trend'  => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    'down'   => '<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>',
    'edit'   => '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
    'sun'    => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'moon'   => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
    'screen' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
    'chev'   => '<path d="M9 6l6 6-6 6"/>',
    'bell'   => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
];
function icon(string $n): string { return '<svg viewBox="0 0 24 24" aria-hidden="true">' . (ICONS[$n] ?? '') . '</svg>'; }

/** Attributes for an ASSET search box: no browser history suggestions, and our own suggestions (assets/suggest.js)
 *  for the listed kinds of value. $types must match what that page's search really looks at. */
function suggest_attrs(array $types = ['user', 'model', 'brand', 'category', 'location', 'serial']): string
{
    return ' autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" data-suggest="' . e(implode(',', $types)) . '"';
}

/** Small area sparkline. */
function sparkline(array $vals, string $color): string
{
    $vals = array_values($vals);
    $w = 92; $h = 38; $p = 4; $n = max(count($vals) - 1, 1);
    $mx = max($vals ?: [1]); $mn = min(0, min($vals ?: [0])); $rng = ($mx - $mn) ?: 1;
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = [round($p + $i * ($w - 2 * $p) / $n, 1), round($h - $p - ($v - $mn) / $rng * ($h - 2 * $p), 1)];
    $line = implode('', array_map(fn($q, $i) => ($i ? 'L' : 'M') . $q[0] . ' ' . $q[1], $pts, array_keys($pts)));
    $id = 'g' . bin2hex(random_bytes(3)); $end = end($pts);
    return "<svg class=\"spark\" viewBox=\"0 0 $w $h\" preserveAspectRatio=\"none\" aria-hidden=\"true\"><defs><linearGradient id=\"$id\" x1=\"0\" x2=\"0\" y1=\"0\" y2=\"1\"><stop offset=\"0\" stop-color=\"$color\" stop-opacity=\".22\"/><stop offset=\"1\" stop-color=\"$color\" stop-opacity=\"0\"/></linearGradient></defs>"
        . "<path d=\"{$line}L" . ($w - $p) . " $h L$p $h Z\" fill=\"url(#$id)\"/><path d=\"$line\" fill=\"none\" stroke=\"$color\" stroke-width=\"1.8\" stroke-linejoin=\"round\" vector-effect=\"non-scaling-stroke\"/><path d=\"M{$end[0]} {$end[1]}h0\" fill=\"none\" stroke=\"$color\" stroke-width=\"5\" stroke-linecap=\"round\" vector-effect=\"non-scaling-stroke\"/></svg>";
}

/** Purchase-year bar chart in the style of the reference: tall bars, one highlighted with a label and dashed guide. */
function year_chart(array $counts, int $legacyUpTo): string
{
    $years = array_keys($counts); $cnt = array_values($counts); $n = count($cnt);
    $maxI = array_search(max($cnt), $cnt, true);
    $top = max(20, (int)ceil(max($cnt) / 20) * 20);
    $W = 720; $H = 250; $L = 8; $R = 40; $T = 24; $B = 26; $cw = ($W - $L - $R) / $n; $bw = $cw * .62;
    $y = fn($v) => round($T + ($H - $T - $B) * (1 - $v / $top), 1);
    $s = "<svg class=\"yc\" viewBox=\"0 0 $W $H\" width=\"100%\" role=\"img\" aria-label=\"Assets by purchase year\" data-top=\"$top\">";
    foreach ([0, $top / 4, $top / 2, $top * 3 / 4, $top] as $t) $s .= '<text x="' . ($W - 4) . '" y="' . ($y($t) + 4) . '" text-anchor="end">' . round($t) . '</text>';
    foreach ($cnt as $i => $v) {
        $x = round($L + $i * $cw + ($cw - $bw) / 2, 1); $h = $v ? max(2, $y(0) - $y($v)) : 0;
        $cls = $i === $maxI ? 'hi' : ($years[$i] <= $legacyUpTo ? 'old' : '');
        $lab = $years[$i] === 2010 ? '≤2010' : (string)$years[$i];
        $s .= "<rect class=\"bar $cls\" x=\"$x\" y=\"" . ($y(0) - $h) . "\" width=\"" . round($bw, 1) . "\" height=\"$h\" rx=\"4\" data-v=\"$v\" data-l=\"$lab\"/>";
        $s .= '<text x="' . round($L + $i * $cw + $cw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . ($years[$i] === 2010 ? '≤’10' : '’' . substr((string)$years[$i], 2)) . '</text>';
    }
    $lx = round($L + (array_search($legacyUpTo, $years, true) + 1) * $cw, 1);
    $s .= "<line x1=\"$lx\" x2=\"$lx\" y1=\"" . ($T - 12) . "\" y2=\"" . ($H - $B) . "\" class=\"cut\"/><text x=\"" . ($lx - 6) . "\" y=\"" . ($T - 4) . "\" text-anchor=\"end\" class=\"cutl\">← 8+ years old</text>";
    $hy = $y($cnt[$maxI]); $hx = $L + $maxI * $cw + $cw / 2;
    $lab = ($years[$maxI] === 2010 ? '≤2010' : $years[$maxI]) . ' : ' . $cnt[$maxI]; $lw = strlen($lab) * 6.6 + 14; $lxx = round(min(max($hx - $lw / 2, $L), $W - $R - $lw), 1);
    $s .= "<line class=\"guide\" x1=\"$L\" x2=\"" . ($W - $R + 4) . "\" y1=\"$hy\" y2=\"$hy\"/>";
    $s .= "<g class=\"flag\"><rect x=\"$lxx\" y=\"" . ($hy - 26) . "\" width=\"$lw\" height=\"20\" rx=\"5\"/><text x=\"" . round($lxx + $lw / 2, 1) . "\" y=\"" . ($hy - 12) . "\" text-anchor=\"middle\">" . e($lab) . "</text></g></svg>";
    return $s;
}

/** Horizontal stacked bar rows (HTML, no SVG) — label, bar segments, value. */
function stack_rows(array $rows, array $series, string $suffixKey = ''): string
{
    $max = max(1, ...array_map(fn($r) => array_sum(array_intersect_key($r['v'], $series)), $rows));
    $h = '';
    foreach ($rows as $r) {
        $tot = array_sum(array_intersect_key($r['v'], $series));
        $h .= '<div class="srow"><span>' . e($r['name']) . '</span><div class="sbar" style="width:' . round($tot / $max * 100, 1) . '%">';
        foreach ($series as $k => [$label, $color]) {
            $v = $r['v'][$k] ?? 0; if (!$v) continue;
            $h .= '<i style="flex:' . $v . ';background:var(--' . $color . ')" title="' . e($r['name'] . ' · ' . $label . ': ' . $v) . '">' . ($v / max($tot, 1) > .12 ? $v : '') . '</i>';
        }
        $h .= '</div><em>' . e($r['note'] ?? (string)$tot) . '</em></div>';
    }
    return $h;
}

function pill(string $text, string $tone): string { return '<span class="pill c-' . $tone . '">' . e($text) . '</span>'; }
function role_badge(string $role): string
{
    return match ($role) {
        ROLE_SUPER => '<span class="pill c-neutral role-admin role-super">' . icon('shield') . 'Super Admin</span>',
        ROLE_ADMIN => '<span class="pill c-neutral role-admin">' . icon('shield') . 'Administrator</span>',
        default    => '<span class="pill role-user">User</span>',
    };
}
function status_pill(string $s): string { return pill($s, ['In Use' => 'good', 'In Repair' => 'warn', 'Damaged' => 'crit'][$s] ?? 'neutral'); }
function os_pill(array $a): string
{
    $t = ['Supported' => 'good', 'End of support (Oct 2025)' => 'serious', 'End of support (Jan 2020)' => 'crit', 'Unknown' => 'warn'][$a['os_support']] ?? 'neutral';
    return pill($a['os_family'] === 'Not applicable' ? 'n/a' : str_replace(' (version not recorded)', ' (?)', $a['os_family']), $t);
}
function score_cell(int $s): string
{
    $tone = $s >= 60 ? 'crit' : ($s >= 40 ? 'serious' : 'good'); $lvl = $s >= 60 ? 3 : ($s >= 40 ? 2 : 1);
    $bars = '';
    for ($k = 1; $k <= 3; $k++) $bars .= '<s style="height:' . ($k * 4) . 'px;' . ($k <= $lvl ? 'background:var(--' . $tone . ')' : '') . '"></s>';
    return '<span class="lvl">' . $s . ' <i aria-hidden="true">' . $bars . '</i></span>';
}
function flag_tags(array $a): string
{
    if (!$a['flags']) return '<span class="tag">No flags</span>';
    return implode('', array_map(fn($f) => '<span class="tag' . ($f[1] ? ' c' : '') . '">' . e($f[0]) . '</span>', $a['flags']));
}
function initials(string $name): string
{
    $p = preg_split('/\s+/', trim($name)) ?: [''];
    return mb_strtoupper(mb_substr($p[0], 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
}

/** Pagination links that keep the current query string. */
function pager(int $total, int $page, int $per, string $page_url, array $params, string $anchor = ''): string
{
    $pages = max(1, (int)ceil($total / $per));
    $from = $total ? ($page - 1) * $per + 1 : 0; $to = min($total, $page * $per);
    $prev = $page > 1 ? '<a class="btn" href="' . e(url($page_url, $params + ['p' => $page - 1]) . $anchor) . '">Previous</a>' : '<span class="btn off">Previous</span>';
    $next = $page < $pages ? '<a class="btn" href="' . e(url($page_url, $params + ['p' => $page + 1]) . $anchor) . '">Next</a>' : '<span class="btn off">Next</span>';
    return "<div class=\"pager\"><span>Showing {$from}–{$to} of {$total}</span><div>$prev$next</div></div>";
}

/* ---------------- page shell ---------------- */
function page_start(string $title, string $active, string $crumb = ''): void
{
    $u = current_user();
    $loc = q('loc');
    $counts = [];
    foreach (all_assets() as $a) $counts[$a['location']] = ($counts[$a['location']] ?? 0) + 1;
    $sections = ['overview' => ['Main', 'Dashboard', 'index.php'], 'assets' => ['Main', 'Assets', 'assets.php'], 'locations' => ['Main', 'Locations', 'locations.php'],
        'finder' => ['Analysis', 'Asset Finder', 'finder.php'], 'users' => ['Administration', 'User Management', 'users.php'],
        'import' => ['Administration', 'Import Data', 'import.php'], 'security' => ['Administration', 'Security', 'security.php'],
        'account' => ['Account', 'Account', 'account.php']];
    $sec = $sections[$active] ?? ['', $title, ''];
    $here = $crumb !== '' && $crumb !== $sec[1] ? '<a href="' . e($sec[2]) . '">' . e($sec[1]) . '</a><span>/</span><b>' . e($crumb) . '</b>' : '<b>' . e($sec[1]) . '</b>';
    $grpOpen = $active === 'assets' || $loc !== '';
    $nav = fn($href, $key, $ic, $label) => '<a href="' . e($href) . '"' . ($active === $key ? ' class="on" aria-current="page"' : '') . ' data-tip="' . e($label) . '">' . icon($ic) . '<span class="lbl">' . e($label) . '</span></a>';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= e(cfg('app_name')) ?></title>
<script>try{var t=localStorage.getItem("nedco-theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t;if(sessionStorage.getItem("nedco-side")==="1")document.documentElement.classList.add("side-collapsed")}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<?= brand_head() ?>
<link rel="stylesheet" href="assets/style.css?v=11">
</head>
<body>
<div class="app">
<aside id="side" aria-label="Sidebar">
  <div class="brandrow">
    <a class="brand" href="<?= e(url('index.php', ['loc' => $loc])) ?>" aria-label="<?= e(cfg('app_name')) ?>" data-tip="<?= e(cfg('app_name')) ?>"><?= brand_logo() ?><span class="sysline"><?= brand_mark() ?><span class="brandtxt"><b><?= e(brand_product()) ?></b><small><?= e(cfg('org_line')) ?></small></span></span></a>
    <button type="button" class="collapse" id="collapse" aria-controls="side" aria-expanded="true" aria-label="Collapse sidebar" data-tip="Expand sidebar"><?= icon('chev') ?></button>
  </div>
  <form class="side-search" action="assets.php" method="get" role="search"><?= icon('search') ?><input name="s" type="search" placeholder="Find serial, user, model" aria-label="Search assets"<?= suggest_attrs() ?>></form>
  <nav aria-label="Main">
    <div class="navlab">Main</div>
    <?= $nav(url('index.php', ['loc' => $loc]), 'overview', 'grid', 'Dashboard') ?>
    <div class="grp<?= $grpOpen ? ' open' : '' ?>" id="grp-assets">
      <div class="grprow">
        <?= $nav(url('assets.php', ['loc' => $loc]), 'assets', 'list', 'Assets') ?>
        <button type="button" class="tog" aria-expanded="<?= $grpOpen ? 'true' : 'false' ?>" aria-controls="sub-assets" aria-label="Show or hide assets by location"><?= icon('chev') ?></button>
      </div>
      <div class="sub" id="sub-assets">
        <a href="assets.php"<?= $active === 'assets' && $loc === '' ? ' class="on"' : '' ?>>All locations<span><?= array_sum($counts) ?></span></a>
        <?php foreach (LOCATIONS as $l): ?><a href="<?= e(url('assets.php', ['loc' => $l])) ?>"<?= $active === 'assets' && $loc === $l ? ' class="on"' : '' ?>><?= e($l) ?><span><?= (int)($counts[$l] ?? 0) ?></span></a><?php endforeach; ?>
      </div>
    </div>
    <?= $nav(url('locations.php', ['loc' => $loc]), 'locations', 'pin', 'Locations') ?>
  </nav>
  <nav aria-label="Analysis">
    <div class="navlab">Analysis</div>
    <?= $nav(url('finder.php', ['loc' => $loc]), 'finder', 'chart', 'Asset Finder') ?>
  </nav>
  <?php if (is_admin()): ?>
  <nav aria-label="Administration">
    <div class="navlab">Administration</div>
    <?= $nav('users.php', 'users', 'users', 'User Management') ?>
    <?= $nav('import.php', 'import', 'upload', 'Import Data') ?>
    <?= $nav('security.php', 'security', 'shield', 'Security') ?>
  </nav>
  <?php endif; ?>
  <nav class="side-foot" aria-label="Account">
    <div class="navlab">Account</div>
    <a data-tip="Account" class="mecard<?= $active === 'account' ? ' on' : '' ?>" href="account.php"<?= $active === 'account' ? ' aria-current="page"' : '' ?> title="Account settings"><span class="av"><?= e(initials($u['name'])) ?></span><span class="lbl"><b><?= e($u['name']) ?></b><small><?= e(role_label($u['role'])) ?></small></span></a>
    <div class="appear"><span class="lbl">Appearance</span>
      <div class="theme" role="group" aria-label="Appearance">
        <button type="button" data-theme-set="light" data-tip="Light theme" aria-label="Light theme"><?= icon('sun') ?><span class="lbl">Light</span></button>
        <button type="button" data-theme-set="dark" data-tip="Dark theme" aria-label="Dark theme"><?= icon('moon') ?><span class="lbl">Dark</span></button>
<!--        <button type="button" data-theme-set="system" data-tip="System theme" aria-label="System theme"><?= icon('screen') ?><span class="lbl">System</span></button> -->
      </div>
    </div>
    <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit" data-tip="Sign out"><?= icon('logout') ?><span class="lbl">Sign out</span></button></form>
  </nav>
</aside>
<main>
  <div class="topbar"><button class="menu" id="menu" type="button" aria-label="Open navigation" aria-controls="side" aria-expanded="false">☰</button><?php if ($sec[0] !== ''): ?><span><?= e($sec[0]) ?></span><span>/</span><?php endif; ?><?= $here ?><span class="rolebadge"><?= role_badge($u['role']) ?></span></div>
  <?php if ($f = flash()): ?><div class="flash <?= e($f[1]) ?>" role="status"><?= e($f[0]) ?></div><?php endif; ?>
<?php
}

function page_end(): void
{
    $asOf = as_of()->format('j M Y');
    echo '<footer>Figures calculated ' . e($asOf) . (cfg('exclude_high_risk', true) ? ' · devices rated High risk are left out' : '') .
        ' · Attention Score: impact and criticality +7.5 per level, unsupported Windows +15, maintenance overdue +10, no backup +10, no security controls +10, damaged or in repair +5, 8+ years old +5.</footer>';
    echo '</main></div><div class="tip" id="tip" hidden></div><script src="assets/app.js?v=9"></script><script src="assets/suggest.js?v=1"></script></body></html>';
}

/** Location dropdown that reloads the page. */
function location_select(string $loc, array $keep = []): string
{
    $h = '<form method="get" class="inline">';
    foreach ($keep as $k => $v) if ($v !== '') $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $h .= '<label class="selwrap">' . icon('pin') . '<select name="loc" aria-label="Location" onchange="this.form.submit()"><option value="">All locations</option>';
    foreach (LOCATIONS as $l) $h .= '<option' . ($loc === $l ? ' selected' : '') . ' value="' . e($l) . '">' . e($l) . '</option>';
    return $h . '</select></label><noscript><button class="btn">Apply</button></noscript></form>';
}
function valid_loc(string $l): string { return in_array($l, LOCATIONS, true) ? $l : ''; }
