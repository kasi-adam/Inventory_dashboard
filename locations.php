<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
require_login();

$loc = valid_loc(q('loc'));
$all = all_assets();
$A = in_location($all, $loc);
$score = location_scorecard($all);
$cols = ['overdue' => ['Maint. overdue', '%'], 'unsupported' => ['Unsupp. Windows', '%'], 'audit' => ['Audit overdue', ''], 'backup' => ['No backup', ''], 'age' => ['Avg age (yrs)', ''], 'score' => ['Avg score', '']];
$mx = [];
foreach ($cols as $k => $_) $mx[$k] = max(1e-9, ...array_column($score, $k));

$osRows = [];
foreach (LOCATIONS as $l) {
    if ($loc && $l !== $loc) continue;
    $p = array_filter(in_location($all, $l), fn($a) => $a['is_pc']);
    $v = ['w11' => count_if($p, fn($a) => $a['os_family'] === 'Windows 11'), 'w10' => count_if($p, fn($a) => $a['os_family'] === 'Windows 10'),
          'w7' => count_if($p, fn($a) => $a['os_family'] === 'Windows 7')];
    $v['unk'] = count($p) - array_sum($v);
    $osRows[] = ['name' => short_loc($l), 'v' => $v, 'note' => pct($v['w10'] + $v['w7'], count($p)) . '% unsupp.'];
}
$mRows = [];
foreach (SCHEDULES as $s => $days) {
    $g = array_filter($A, fn($a) => $a['maint_schedule'] === $s);
    $o = count_if($g, fn($a) => $a['maint_overdue']);
    $mRows[] = ['name' => $s, 'v' => ['od' => $o, 'ok' => count($g) - $o], 'note' => pct($o, count($g)) . '% overdue'];
}
$catRows = [];
foreach (array_count_values(array_column($A, 'category')) as $c => $cnt) {
    $g = array_filter($A, fn($a) => $a['category'] === $c);
    $bad = count_if($g, fn($a) => $a['maint_overdue'] || $a['unsupported'] || $a['status'] !== 'In Use');
    $catRows[] = ['name' => $c, 'v' => ['bad' => $bad, 'ok' => $cnt - $bad], 'note' => (string)$cnt];
}
usort($catRows, fn($a, $b) => array_sum($b['v']) <=> array_sum($a['v']));

page_start('Locations', 'locations');
?>
  <section class="hello">
    <div><h1>Locations</h1><p>How each location compares. Shading marks the worst values in each column.</p></div>
    <div class="ctrls"><?= location_select($loc) ?></div>
  </section>

  <section class="panel">
    <div class="ph"><h2><?= icon('chart') ?>All locations</h2><span class="muted">Click a location to open its overview</span></div>
    <div class="tbl"><table>
      <thead><tr><th>Location</th><th class="num">Assets</th><?php foreach ($cols as [$l]): ?><th class="num"><?= e($l) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($score as $r): ?>
        <tr class="<?= $loc === $r['loc'] ? 'sel' : '' ?>">
          <td><a href="<?= e(url('index.php', ['loc' => $r['loc']])) ?>"><?= e($r['loc']) ?></a></td><td class="num"><?= $r['n'] ?></td>
          <?php foreach ($cols as $k => [$l, $suf]): ?>
          <td class="num" style="background:color-mix(in srgb,var(--crit) <?= round($r[$k] / $mx[$k] * 26) ?>%,transparent)"><?= in_array($k, ['age'], true) ? number_format($r[$k], 1) : round($r[$k]) ?><?= $suf ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </section>

  <div class="g2">
    <section class="panel">
      <div class="ph"><h2><?= icon('win') ?>Windows support<?= $loc ? '' : ' by location' ?></h2></div>
      <p class="desc">PCs only. Windows 10 support ended 14 Oct 2025; Windows 7 ended 14 Jan 2020.</p>
      <div class="legend"><span><i style="background:var(--good)"></i>Windows 11</span><span><i style="background:var(--serious)"></i>Windows 10</span><span><i style="background:var(--crit)"></i>Windows 7</span><span><i style="background:var(--axis)"></i>Not recorded</span></div>
      <?= stack_rows($osRows, ['w11' => ['Windows 11', 'good'], 'w10' => ['Windows 10', 'serious'], 'w7' => ['Windows 7', 'crit'], 'unk' => ['Not recorded', 'axis']]) ?>
    </section>
    <section class="panel">
      <div class="ph"><h2><?= icon('wrench') ?>Maintenance compliance</h2></div>
      <p class="desc">Overdue means more days since the last maintenance than the schedule allows (Quarterly 91, Biannually 182, Annually 365).</p>
      <div class="legend"><span><i style="background:var(--crit)"></i>Overdue</span><span><i style="background:var(--good)"></i>On time</span></div>
      <?= stack_rows($mRows, ['od' => ['Overdue', 'crit'], 'ok' => ['On time', 'good']]) ?>
    </section>
  </div>

  <section class="panel" style="margin-top:14px">
    <div class="ph"><h2><?= icon('box') ?>Assets by category<?= $loc ? ' · ' . e($loc) : '' ?></h2></div>
    <p class="desc">Needs attention = maintenance overdue, unsupported Windows, or damaged / in repair.</p>
    <div class="legend"><span><i style="background:var(--serious)"></i>Needs attention</span><span><i style="background:var(--accent)"></i>No issues</span></div>
    <?= stack_rows($catRows, ['bad' => ['Needs attention', 'serious'], 'ok' => ['No issues', 'accent']]) ?>
  </section>
<?php page_end();
