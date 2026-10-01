<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
require __DIR__ . '/app/security.php';
$user = require_login();

$loc  = valid_loc(q('loc'));
$tab  = in_array(q('tab'), ['maint', 'cond', 'audit'], true) ? q('tab') : 'maint';
$fq   = q('fq');
$tq   = q('tq');
$iss  = array_key_exists(q('issue'), issues()) ? q('issue') : 'all';
$page = max(1, (int)q('p', '1'));

$A    = in_location(all_assets(), $loc);
$n    = count($A);
$pcs  = array_values(array_filter($A, fn($a) => $a['is_pc']));
$inUse = count_if($A, fn($a) => $a['status'] === 'In Use');
$od    = count_if($A, fn($a) => $a['maint_overdue']);
$eol   = count_if($A, fn($a) => $a['unsupported']);
$old   = count_if($A, fn($a) => $a['age'] >= 8);
$avgAge = avg_of($A, 'age');
$avgScore = (int)round(avg_of($A, 'score'));
$legacyYear = (int)as_of()->format('Y') - 8;

$years = years_range();
$avgPerYear = function (string $k) use ($A, $years) {
    $out = [];
    foreach ($years as $y) { $g = array_filter($A, fn($a) => max(2010, (int)$a['purchase_year']) === $y); $out[] = $g ? avg_of($g, $k) : 0; }
    return $out;
};
$cards = [
    ['Total assets', $n, '<b class="t-good">' . pct($inUse, $n) . '%</b> in use · ' . ($n - $inUse) . ' damaged / in repair', 'accent', per_year($A), 'box'],
    ['Maintenance overdue', $od, '<b class="t-' . sev(pct($od, $n), 25, 50) . '">' . pct($od, $n) . '%</b> of assets past schedule', sev(pct($od, $n), 25, 50), per_year($A, fn($a) => $a['maint_overdue']), 'wrench'],
    ['Unsupported Windows', $eol, '<b class="t-' . sev(pct($eol, count($pcs)), 20, 50) . '">' . pct($eol, count($pcs)) . '%</b> of ' . count($pcs) . ' PCs · ' . count_if($A, fn($a) => $a['os_family'] === 'Windows 7') . ' on Windows 7', sev(pct($eol, count($pcs)), 20, 50), per_year($A, fn($a) => $a['unsupported']), 'win'],
    ['Average age', number_format($avgAge, 1) . ' yrs', '<b class="t-' . sev(pct($old, $n), 20, 40) . '">' . $old . '</b> assets aged 8+ years', sev(pct($old, $n), 20, 40), $avgPerYear('age'), 'cal'],
    ['Avg Attention Score', $avgScore, 'out of 100 · higher means act sooner', sev($avgScore, 35, 55), $avgPerYear('score'), 'gauge'],
];

/* Security Activity card — administrators only, last 7 days of the existing auth_log (see app/security.php) */
$sec = null;
if (is_admin()) {
    $secP = sec_period('7d');
    $secRows = sec_rows($secP['from'], $secP['to']);
    /* trend = all recorded sign-in events per day (real auth_log rows, same 7-day window as the figures) */
    $secTrend = array_fill(0, 7, 0);
    foreach ($secRows as $r) if ($r['in']) { $i = min(6, intdiv($r['ts'] - $secP['from']->getTimestamp(), 86400)); if ($i >= 0) $secTrend[$i]++; }
    $sec = sec_summary($secRows, $secP['days']) + ['spark' => $secTrend];
}

/* feed */
$feed = match ($tab) {
    'maint' => array_map(fn($a) => ['a' => $a, 'ic' => 'clock', 'c' => $a['maint_days_overdue'] > 365 ? 'crit' : ($a['maint_days_overdue'] > 180 ? 'serious' : 'warn'),
        't' => 'Maintenance overdue', 'm' => $a['maint_days_overdue'] . 'd'], (function ($A) { $x = array_filter($A, fn($a) => $a['maint_overdue']); usort($x, fn($p, $q) => $q['maint_days_overdue'] <=> $p['maint_days_overdue']); return $x; })($A)),
    'cond'  => array_map(fn($a) => ['a' => $a, 'ic' => $a['status'] !== 'In Use' ? 'x' : 'alert', 'c' => $a['status'] === 'Damaged' ? 'crit' : ($a['status'] === 'In Repair' ? 'serious' : 'warn'),
        't' => implode(' · ', array_filter([$a['status'] !== 'In Use' ? $a['status'] : '', !$a['backup'] ? 'No backup' : '', !$a['security_controls'] ? 'No security controls' : ''])), 'm' => 'Score ' . $a['score']],
        by_priority(array_filter($A, fn($a) => $a['status'] !== 'In Use' || !$a['backup'] || !$a['security_controls']))),
    'audit' => array_map(fn($a) => ['a' => $a, 'ic' => 'shield', 'c' => 'warn', 't' => 'Security audit overdue', 'm' => $a['last_audit'] ? date('M Y', strtotime($a['last_audit'])) : '—'],
        (function ($A) { $x = array_filter($A, fn($a) => $a['audit_status'] === 'Over 12 months'); usort($x, fn($p, $q) => strcmp((string)$p['last_audit'], (string)$q['last_audit'])); return $x; })($A)),
};
if ($fq !== '') {
    $t = mb_strtolower($fq);
    $feed = array_values(array_filter($feed, fn($f) => str_contains(mb_strtolower(implode(' ', [$f['a']['serial_number'], $f['a']['user_name'], $f['a']['model'], $f['a']['location']])), $t)));
}

/* monitoring table */
$T = by_priority(array_filter(search_assets($A, $tq), issues()[$iss][1]));
$per = 10; $rows = array_slice($T, ($page - 1) * $per, $per);
$keep = ['loc' => $loc, 'tab' => $tab, 'fq' => $fq, 'tq' => $tq, 'issue' => $iss === 'all' ? '' : $iss];

page_start('Dashboard', 'overview');
?>
  <section class="hello">
    <div><h1>Dashboard</h1><p>Summary<?= $loc ? ' for <b>' . e($loc) . '</b>' : ' for all locations' ?> as of <?= e(as_of()->format('j F Y')) ?> · <?= $n ?> assets</p></div>
    <div class="ctrls"><?= location_select($loc) ?><a class="btn" href="<?= e(url('export.php', ['loc' => $loc])) ?>"><?= icon('down') ?>Export CSV</a></div>
  </section>

  <div class="g-main">
    <div class="g-left">
      <section class="cards kpi<?= $sec ? ' six' : '' ?>" aria-label="Key figures">
        <?php foreach ($cards as [$lab, $val, $sub, $tone, $sp, $ic]): ?>
        <div class="card"><div class="hd"><?= e($lab) ?><?= icon($ic) ?></div>
          <div class="row"><div class="v"><?= e($val) ?></div><?= sparkline(array_values($sp), 'var(--' . ($tone === 'accent' ? 'accent' : $tone) . ')') ?></div>
          <div class="s"><?= $sub ?></div></div>
        <?php endforeach; ?>
        <?php if ($sec): ?>
        <div class="card seccard">
          <div class="hd">Security Activity<?= icon('shield') ?></div>
          <div class="row"><div class="v"><?= $sec['total'] ?></div><?= sparkline($sec['spark'], 'var(--accent)') ?></div>
          <div class="s"><b><?= $sec['total'] === 1 ? '1 event' : $sec['total'] . ' events' ?></b> · <b><?= $sec['counts']['ok'] ?></b> successful · <b><?= $sec['failed'] ?></b> failed · last 7 days</div>
          <div class="s secstatus">Security Risk: <i class="dot d-<?= e($sec['tone']) ?>" aria-hidden="true"></i><b><?= e($sec['level']) ?></b></div></div>
        <?php endif; ?>
      </section>

      <section class="panel">
        <div class="ph"><h2><?= icon('trend') ?>Asset purchase history</h2><span class="muted">Assets by year bought · hover a bar</span></div>
        <div class="big"><b><?= $old ?></b><span>assets are 8+ years old and due for replacement · <b class="t-serious"><?= pct($old, $n) ?>%</b> of all assets</span></div>
        <?= year_chart(per_year($A), $legacyYear) ?>
      </section>
    </div>

    <section class="panel feedp">
      <div class="ph"><h2>Needs attention</h2></div>
      <div class="tabs" role="tablist">
        <?php foreach (['maint' => 'Maintenance', 'cond' => 'Condition', 'audit' => 'Audit'] as $k => $l): ?>
        <a role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" href="<?= e(url('index.php', ['loc' => $loc, 'tab' => $k])) ?>#feed"><?= e($l) ?></a>
        <?php endforeach; ?>
      </div>
      <form class="search" method="get" action="index.php#feed"><?= icon('search') ?>
        <input type="hidden" name="loc" value="<?= e($loc) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input name="fq" type="search" value="<?= e($fq) ?>" placeholder="Search user, serial, model" aria-label="Search the list"<?= suggest_attrs(['user', 'model', 'location', 'serial']) ?>></form>
      <div class="fcount" id="feed"><b><?= count($feed) ?></b> assets flagged<?= $loc ? ' in ' . e(short_loc($loc)) : '' ?></div>
      <ul class="feed">
        <?php foreach (array_slice($feed, 0, 40) as $f): $a = $f['a']; ?>
        <li><span class="ic c-<?= $f['c'] ?>"><?= icon($f['ic']) ?></span>
          <div><b><?= e($f['t']) ?></b><span><?= e($a['user_name'] ?: 'No user') ?> · <?= e($a['category']) ?> <span class="mono"><?= e($a['serial_number'] ?: '—') ?></span> · <?= e(short_loc($a['location'])) ?></span></div>
          <em><?= e($f['m']) ?></em></li>
        <?php endforeach; ?>
        <?php if (!$feed): ?><li class="empty">Nothing flagged here.</li><?php endif; ?>
      </ul>
      <?php if (count($feed) > 40): ?><a class="more" href="<?= e(url('finder.php', ['loc' => $loc, 'issue' => $tab === 'cond' ? 'cond' : $tab])) ?>">See all <?= count($feed) ?> in Asset Finder →</a><?php endif; ?>
    </section>
  </div>

  <section class="panel" id="monitor" style="margin-top:14px">
    <div class="ph">
      <h2><?= icon('alert') ?>Priority monitoring</h2>
      <form class="ctrls" method="get" action="index.php#monitor">
        <input type="hidden" name="loc" value="<?= e($loc) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label class="search tight"><?= icon('search') ?><input name="tq" type="search" value="<?= e($tq) ?>" placeholder="Serial, user, model" aria-label="Search assets"<?= suggest_attrs() ?>></label>
        <label class="selwrap"><?= icon('list') ?><select name="issue" aria-label="Filter by problem" onchange="this.form.submit()">
          <?php foreach (issues() as $k => [$l]): ?><option value="<?= e($k) ?>"<?= $iss === $k ? ' selected' : '' ?>><?= e($k === 'all' ? 'All problems' : $l) ?></option><?php endforeach; ?>
        </select></label>
        <noscript><button class="btn">Filter</button></noscript>
      </form>
    </div>
    <div class="tbl"><table>
      <thead><tr><th class="num">#</th><th>Serial No.</th><th>User</th><th>Asset</th><th>Location</th><th>Status</th><th>OS</th><th class="num">Maint. overdue</th><th class="num">Score</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $a): ?>
        <tr>
          <td class="num muted"><?= ($page - 1) * $per + $i + 1 ?></td>
          <td><span class="mono"><?= e($a['serial_number'] ?: '—') ?></span></td>
          <td><span class="who"><span class="av s"><?= e(initials($a['user_name'] ?: '?')) ?></span><?= e($a['user_name'] ?: 'No user') ?></span></td>
          <td><?= e($a['category']) ?><br><small class="muted"><?= e($a['label']) ?></small></td>
          <td><?= e(short_loc($a['location'])) ?></td>
          <td><?= status_pill($a['status']) ?></td>
          <td><?= os_pill($a) ?></td>
          <td class="num"><?= $a['maint_overdue'] ? '<b class="t-' . ($a['maint_days_overdue'] > 365 ? 'crit' : ($a['maint_days_overdue'] > 180 ? 'serious' : 'warn')) . '">' . $a['maint_days_overdue'] . 'd</b>' : '<span class="t-good">On time</span>' ?></td>
          <td class="num"><?= score_cell($a['score']) ?></td>
          <?php if (is_admin()): ?><td><a class="iconbtn" href="edit.php?id=<?= $a['id'] ?>" title="Edit asset" aria-label="Edit <?= e($a['serial_number']) ?>"><?= icon('edit') ?></a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="empty">No assets match these filters.</td></tr><?php endif; ?>
      </tbody></table></div>
    <?= pager(count($T), $page, $per, 'index.php', $keep, '#monitor') ?>
  </section>
<?php page_end();
