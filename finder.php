<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
require_login();

$loc  = valid_loc(q('loc'));
$iss  = array_key_exists(q('issue'), issues()) ? q('issue') : 'maint';
[$issLabel, $test] = issues()[$iss];
$all  = array_values(array_filter(all_assets(), $test));
$hits = by_priority(in_location($all, $loc));

if (q('export') === '1') csv_download('asset-finder-' . $iss . ($loc ? '-' . preg_replace('/\W+/', '-', strtolower($loc)) : '') . '.csv', $hits);

$page = max(1, (int)q('p', '1')); $per = 25;
$rows = array_slice($hits, ($page - 1) * $per, $per);
$byLoc = [];
foreach (LOCATIONS as $l) $byLoc[$l] = count(in_location($all, $l));
$maxLoc = max(1, ...array_values($byLoc));

page_start('Asset Finder', 'finder');
?>
  <section class="hello">
    <div><h1>Asset Finder</h1><p>Whose machine, and where? Pick a problem and a location. Every matching machine is listed with its user, worst first.</p></div>
  </section>

  <form class="panel finder" method="get">
    <label><span>What problem?</span><span class="selwrap"><?= icon('alert') ?><select name="issue" onchange="this.form.submit()">
      <?php foreach (issues() as $k => [$l]): ?><option value="<?= e($k) ?>"<?= $iss === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></span></label>
    <label><span>Which location?</span><span class="selwrap"><?= icon('pin') ?><select name="loc" onchange="this.form.submit()"><option value="">All locations</option>
      <?php foreach (LOCATIONS as $l): ?><option<?= $loc === $l ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></span></label>
    <noscript><button class="btn">Show</button></noscript>
    <div class="found"><b><?= count($hits) ?></b> <?= count($hits) === 1 ? 'machine' : 'machines' ?> · <?= e($issLabel) ?><?= $loc ? ' · ' . e($loc) : '' ?></div>
  </form>

  <section class="panel" style="margin-top:14px">
    <div class="ph"><h2><?= icon('pin') ?>Where is this problem?</h2><span class="muted">Click a location to list its machines</span></div>
    <div class="locbars">
      <?php foreach ($byLoc as $l => $c): ?>
      <a class="locbar<?= $loc === $l ? ' on' : '' ?>" href="<?= e(url('finder.php', ['issue' => $iss, 'loc' => $loc === $l ? '' : $l])) ?>">
        <span><?= e(short_loc($l)) ?></span><i><s style="width:<?= round($c / $maxLoc * 100, 1) ?>%"></s></i><b><?= $c ?></b></a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel" style="margin-top:14px">
    <div class="ph"><h2><?= icon('users') ?>Machines</h2>
      <a class="btn" href="<?= e(url('finder.php', ['issue' => $iss, 'loc' => $loc, 'export' => '1'])) ?>"><?= icon('down') ?>Export CSV</a></div>
    <div class="tbl"><table>
      <thead><tr><th class="num">#</th><th>User (who)</th><th>Location (where)</th><th>Serial No.</th><th>Asset</th><th>Status</th><th>OS</th><th class="num">Age</th><th class="num">Maint. overdue</th><th>Last audit</th><th class="num">Score</th><th>Why it's listed</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $a): ?>
        <tr>
          <td class="num muted"><?= ($page - 1) * $per + $i + 1 ?></td>
          <td><span class="who"><span class="av s"><?= e(initials($a['user_name'] ?: '?')) ?></span><?= e($a['user_name'] ?: 'No user') ?></span></td>
          <td><?= e($a['location']) ?></td>
          <td><span class="mono"><?= e($a['serial_number'] ?: '—') ?></span></td>
          <td><?= e($a['category']) ?><br><small class="muted"><?= e($a['label']) ?></small></td>
          <td><?= status_pill($a['status']) ?></td>
          <td><?= os_pill($a) ?></td>
          <td class="num"><?= number_format($a['age'], 1) ?></td>
          <td class="num"><?= $a['maint_overdue'] ? $a['maint_days_overdue'] . 'd' : '—' ?></td>
          <td><?= $a['last_audit'] ? e(date('j M Y', strtotime($a['last_audit']))) : '—' ?></td>
          <td class="num"><?= score_cell($a['score']) ?></td>
          <td class="wrap"><div class="tags"><?= flag_tags($a) ?></div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="12" class="empty">No machines have this problem<?= $loc ? ' in ' . e($loc) : '' ?>.</td></tr><?php endif; ?>
      </tbody></table></div>
    <?= pager(count($hits), $page, $per, 'finder.php', ['issue' => $iss, 'loc' => $loc]) ?>
  </section>
<?php page_end();
