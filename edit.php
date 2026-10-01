<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
$user = require_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isNew = $id === 0;
$a = $isNew ? null : get_asset($id);
if (!$isNew && !$a) { http_response_code(404); exit('Asset not found.'); }

// Category choices: the standard types plus any already used in the register (e.g. imported ones).
$categories = array_unique(array_merge(['Barcode scanner', 'Desktop', 'Laptop', 'Monitor', 'Printer', 'Scanner', 'Shredder', 'UPS'],
    db()->query('SELECT DISTINCT category FROM assets')->fetchAll(PDO::FETCH_COLUMN), $a && $a['category'] !== '' ? [$a['category']] : []));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
array_unshift($categories, '');

$fields = [
    'serial_number' => ['Serial number', 'text'], 'category' => ['Category', $categories], 'brand' => ['Brand', 'text'], 'model' => ['Model', 'text'],
    'status' => ['Status', STATUSES], 'location' => ['Location', LOCATIONS], 'user_name' => ['User', 'text'], 'os_family' => ['Operating system', OS_FAMILIES],
    'ram_gb' => ['RAM (GB)', 'number'], 'storage_gb' => ['Storage (GB)', 'number'], 'purchase_date' => ['Date of purchase', 'date'],
    'maint_schedule' => ['Maintenance schedule', array_keys(SCHEDULES)], 'last_maintenance' => ['Last maintenance', 'date'], 'last_audit' => ['Last security audit', 'date'],
    'risk_rating' => ['Risk rating (used in score only)', array_keys(LEVELS)], 'business_impact' => ['Business impact', array_keys(LEVELS)], 'criticality' => ['Criticality', array_keys(LEVELS)],
    'security_class' => ['Security classification', ['Public', 'Confidential / Private', 'Top Secret']], 'network_zone' => ['Network zone', ['Corporate LAN', 'Corporate APN']],
    'cia_class' => ['CIA classification', ['Level 0', 'Level 1', 'Level 2', 'Level 3', '']],
    'backup' => ['Backup available', 'bool'], 'security_controls' => ['Security controls in place', 'bool'], 'remote_access' => ['Remote access', 'bool'],
    'completeness' => ['Record completeness (%)', 'number'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['maint_today']) && !$isNew) {
        db()->prepare('UPDATE assets SET last_maintenance = ?, updated_by = ? WHERE id = ?')->execute([date('Y-m-d'), $user['username'], $id]);
        flash('Maintenance recorded for today.'); redirect('edit.php?id=' . $id);
    }
    if (isset($_POST['delete']) && !$isNew) {
        db()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
        flash('Asset deleted.'); redirect('assets.php');
    }
    $vals = [];
    foreach ($fields as $k => [$label, $type]) {
        $v = $_POST[$k] ?? '';
        $v = is_string($v) ? trim($v) : '';
        if ($type === 'bool') { $vals[$k] = isset($_POST[$k]) ? 1 : 0; continue; }
        if (is_array($type)) { if (!in_array($v, $type, true)) $errors[$k] = 'Choose a value from the list.'; $vals[$k] = $v; continue; }
        if ($type === 'number') { if ($v === '') { $vals[$k] = $k === 'completeness' ? 0 : null; continue; } if (!ctype_digit($v)) $errors[$k] = 'Enter a whole number.'; $vals[$k] = (int)$v; continue; }
        if ($type === 'date') { if ($v === '') { $vals[$k] = null; continue; } $d = DateTime::createFromFormat('Y-m-d', $v); if (!$d || $d->format('Y-m-d') !== $v) $errors[$k] = 'Enter a date.'; $vals[$k] = $v; continue; }
        $vals[$k] = mb_substr($v, 0, 120);
    }
    if ($vals['category'] === '') $errors['category'] = 'Choose a category.';
    if ($vals['serial_number'] === '') $vals['serial_number'] = null;
    if (!$errors) {
        $vals['updated_by'] = $user['username'];
        $cols = array_keys($vals);
        if ($isNew) {
            db()->prepare('INSERT INTO assets (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($vals));
            $id = (int)db()->lastInsertId();
            flash('Asset added.');
        } else {
            db()->prepare('UPDATE assets SET ' . implode(',', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...array_values($vals), $id]);
            flash('Changes saved.');
        }
        redirect('edit.php?id=' . $id);
    }
    $a = array_merge($a ?? [], $vals);
}
$a ??= ['status' => 'In Use', 'maint_schedule' => 'Quarterly', 'risk_rating' => 'Low', 'business_impact' => 'Average', 'criticality' => 'Average',
        'security_class' => 'Confidential / Private', 'network_zone' => 'Corporate LAN', 'cia_class' => 'Level 1', 'backup' => true, 'security_controls' => true,
        'remote_access' => false, 'os_family' => 'Windows 11', 'location' => LOCATIONS[0], 'brand' => '', 'completeness' => 0];

page_start($isNew ? 'Add asset' : 'Edit asset', 'assets', $isNew ? 'Add asset' : 'Edit ' . ($a['serial_number'] ?? ''));
?>
  <section class="hello">
    <div><h1><?= $isNew ? 'Add an asset' : e(($a['category'] ?? '') . ' ' . ($a['serial_number'] ?? '')) ?></h1>
      <p><?= $isNew ? 'Fill in the register fields. Ages, overdue days and the Attention Score are calculated for you.' : e($a['user_name'] ?? '') . ' · ' . e($a['location'] ?? '') . (isset($a['score']) ? ' · Attention Score ' . (int)$a['score'] : '') ?></p></div>
    <?php if (!$isNew): ?>
    <form method="post" class="ctrls"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn dark" name="maint_today" value="1"><?= icon('wrench') ?>Record maintenance today</button></form>
    <?php endif; ?>
  </section>

  <?php if ($errors): ?><div class="flash err" role="alert">Some fields need fixing. They are marked below.</div><?php endif; ?>
  <form method="post" class="panel form">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="fgrid">
    <?php foreach ($fields as $k => [$label, $type]): $v = $a[$k] ?? ''; ?>
      <?php if ($type === 'bool'): ?>
        <label class="check"><input type="checkbox" name="<?= $k ?>" id="f_<?= $k ?>" value="1"<?= $v ? ' checked' : '' ?>> <?= e($label) ?></label>
      <?php else: ?>
        <label for="f_<?= $k ?>"><span><?= e($label) ?></span>
        <?php if (is_array($type)): ?>
          <select name="<?= $k ?>" id="f_<?= $k ?>"><?php foreach ($type as $o): ?><option value="<?= e($o) ?>"<?= (string)$v === $o ? ' selected' : '' ?>><?= e($o === '' ? ($k === 'category' ? 'Select a category' : '—') : $o) ?></option><?php endforeach; ?></select>
        <?php else: ?>
          <input type="<?= $type === 'number' ? 'number' : $type ?>" name="<?= $k ?>" id="f_<?= $k ?>" value="<?= e($v) ?>"<?= $type === 'number' ? ' min="0"' : '' ?>>
        <?php endif; ?>
        <?php if (isset($errors[$k])): ?><em class="ferr"><?= e($errors[$k]) ?></em><?php endif; ?>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <div class="formbar">
      <button class="btn dark" type="submit"><?= $isNew ? 'Add asset' : 'Save changes' ?></button>
      <a class="btn" href="assets.php">Cancel</a>
      <?php if (!$isNew): ?><span class="grow"></span>
        <label class="check danger"><input type="checkbox" id="confirmDel" data-arm="delBtn"> I want to delete this asset</label>
        <button class="btn danger" type="submit" name="delete" value="1" id="delBtn" disabled>Delete</button>
      <?php endif; ?>
    </div>
  </form>
<?php page_end();
