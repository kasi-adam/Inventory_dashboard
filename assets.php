<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
require_login();

$loc = valid_loc(q('loc'));
$s   = q('s');
$cat = q('cat');
$st  = in_array(q('st'), STATUSES, true) ? q('st') : '';
$sortCols = ['serial_number' => 'Serial', 'user_name' => 'User', 'category' => 'Category', 'location' => 'Location', 'status' => 'Status',
             'os_family' => 'OS', 'age' => 'Age', 'last_maintenance' => 'Last maint.', 'maint_days_overdue' => 'Overdue', 'score' => 'Score'];
$sort = array_key_exists(q('sort'), $sortCols) ? q('sort') : 'score';
$dir  = q('dir') === 'asc' ? 'asc' : 'desc';

$A = search_assets(in_location(all_assets(), $loc), $s);
$cats = array_values(array_unique(array_column(all_assets(), 'category'))); sort($cats);
if ($cat !== '' && in_array($cat, $cats, true)) $A = array_values(array_filter($A, fn($a) => $a['category'] === $cat)); else $cat = '';
if ($st !== '') $A = array_values(array_filter($A, fn($a) => $a['status'] === $st));
usort($A, function ($x, $y) use ($sort, $dir) {
    $c = is_numeric($x[$sort]) || is_numeric($y[$sort]) ? ($x[$sort] <=> $y[$sort]) : strcasecmp((string)$x[$sort], (string)$y[$sort]);
    return $dir === 'asc' ? $c : -$c;
});

$keep = ['loc' => $loc, 's' => $s, 'cat' => $cat, 'st' => $st, 'sort' => $sort, 'dir' => $dir];
if (q('export') === '1') csv_download('asset-register' . ($loc ? '-' . preg_replace('/\W+/', '-', strtolower($loc)) : '') . '.csv', $A);
$page = max(1, (int)q('p', '1')); $per = 25;
$rows = array_slice($A, ($page - 1) * $per, $per);

page_start('Assets', 'assets');
?>
  <section class="hello">
    <div><h1>Assets</h1><p><?= count($A) ?> assets<?= $loc ? ' in ' . e($loc) : '' ?>. Click a column heading to sort.</p></div>
    <div class="ctrls"><?php if (is_admin()): ?><a class="btn dark" href="edit.php"><?= icon('edit') ?>Add asset</a><?php endif; ?><a class="btn" href="<?= e(url('assets.php', $keep + ['export' => '1'])) ?>"><?= icon('down') ?>Export CSV</a></div>
  </section>

  <section class="panel">
    <form class="ctrls filters" method="get">
      <label class="search tight"><?= icon('search') ?><input name="s" type="search" value="<?= e($s) ?>" placeholder="Serial, user, model" aria-label="Search"<?= suggest_attrs() ?>></label>
      <label class="selwrap"><?= icon('pin') ?><select name="loc" aria-label="Location"><option value="">All locations</option><?php foreach (LOCATIONS as $l): ?><option<?= $loc === $l ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="selwrap"><?= icon('box') ?><select name="cat" aria-label="Category"><option value="">All categories</option><?php foreach ($cats as $c): ?><option<?= $cat === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
      <label class="selwrap"><?= icon('list') ?><select name="st" aria-label="Status"><option value="">All statuses</option><?php foreach (STATUSES as $x): ?><option<?= $st === $x ? ' selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
      <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
      <button class="btn dark" type="submit">Apply</button>
      <?php if ($s || $loc || $cat || $st): ?><a class="btn" href="assets.php">Clear</a><?php endif; ?>
    </form>
    <div class="tbl"><table>
      <thead><tr>
        <?php foreach ($sortCols as $k => $l): $isNum = in_array($k, ['age', 'maint_days_overdue', 'score'], true); ?>
        <th class="<?= $isNum ? 'num' : '' ?>"><a href="<?= e(url('assets.php', array_merge($keep, ['sort' => $k, 'dir' => $sort === $k && $dir === 'desc' ? 'asc' : 'desc']))) ?>"><?= e($l) ?> <span aria-hidden="true"><?= $sort === $k ? ($dir === 'desc' ? '↓' : '↑') : '⇅' ?></span></a></th>
        <?php endforeach; ?>
        <?php if (is_admin()): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td><span class="mono"><?= e($a['serial_number'] ?: '—') ?></span></td>
          <td><span class="who"><span class="av s"><?= e(initials($a['user_name'] ?: '?')) ?></span><?= e($a['user_name'] ?: 'No user') ?></span></td>
          <td><?= e($a['category']) ?><br><small class="muted"><?= e($a['label']) ?></small></td>
          <td><?= e(short_loc($a['location'])) ?></td>
          <td><?= status_pill($a['status']) ?></td>
          <td><?= os_pill($a) ?></td>
          <td class="num"><?= number_format($a['age'], 1) ?></td>
          <td><?= $a['last_maintenance'] ? e(date('j M Y', strtotime($a['last_maintenance']))) : '—' ?><br><small class="muted"><?= e($a['maint_schedule']) ?></small></td>
          <td class="num"><?= $a['maint_overdue'] ? '<b class="t-' . ($a['maint_days_overdue'] > 365 ? 'crit' : 'serious') . '">' . $a['maint_days_overdue'] . 'd</b>' : '<span class="t-good">On time</span>' ?></td>
          <td class="num"><?= score_cell($a['score']) ?></td>
          <?php if (is_admin()): ?><td><a class="iconbtn" href="edit.php?id=<?= $a['id'] ?>" title="Edit asset" aria-label="Edit <?= e($a['serial_number']) ?>"><?= icon('edit') ?></a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="11" class="empty">No assets match. Try a shorter search or clear the filters.</td></tr><?php endif; ?>
      </tbody></table></div>
    <?= pager(count($A), $page, $per, 'assets.php', $keep) ?>
  </section>
<?php page_end();
