<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
$user = require_admin();

/* CSV header (as in the Excel "Data" sheet or this app's export) => DB column */
const IMPORT_MAP = [
    'serial number' => 'serial_number', 'category' => 'category', 'brand' => 'brand', 'model' => 'model', 'status' => 'status',
    'location' => 'location', 'user' => 'user_name', 'os family' => 'os_family', 'ram (gb)' => 'ram_gb', 'storage (gb)' => 'storage_gb',
    'date of purchase' => 'purchase_date', 'maintenance schedule' => 'maint_schedule', 'last maintenance' => 'last_maintenance',
    'last security audit' => 'last_audit', 'risk rating' => 'risk_rating', 'business impact' => 'business_impact', 'criticality level' => 'criticality',
    'security classification' => 'security_class', 'backup availability' => 'backup', 'security controls' => 'security_controls',
    'remote access' => 'remote_access', 'network zone' => 'network_zone', 'cia classification' => 'cia_class', 'completeness %' => 'completeness',
];
const REQUIRED = ['category', 'location'];

function to_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 20000) return date('Y-m-d', (int)(((float)$v - 25569) * 86400)); // Excel serial
    foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'd M Y', 'j M Y'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $v);
        if ($d && $d->format($f) === $v) return $d->format('Y-m-d');
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}
function to_bool(string $v): int { return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'y'], true) ? 1 : 0; }

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = ($_POST['mode'] ?? '') === 'replace' ? 'replace' : 'add';
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        flash('Choose a CSV file to upload.', 'err'); redirect('import.php');
    }
    if ($f['size'] > 5 * 1024 * 1024) { flash('That file is larger than 5 MB. Split it or remove unused columns.', 'err'); redirect('import.php'); }
    $h = fopen($f['tmp_name'], 'r');
    $first = fgets($h); rewind($h);
    $delim = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',';
    $head = fgetcsv($h, 0, $delim, '"', '\\') ?: [];
    $head = array_map(fn($x) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$x))), $head);
    $map = [];
    foreach ($head as $i => $name) if (isset(IMPORT_MAP[$name])) $map[$i] = IMPORT_MAP[$name];
    $missing = array_diff(REQUIRED, $map);
    if ($missing) {
        fclose($h);
        flash('The file has no ' . implode(' or ', array_map('ucfirst', $missing)) . ' column. Export the "Data" sheet of the asset register as CSV and try again.', 'err');
        redirect('import.php');
    }
    $rows = []; $skipped = []; $line = 1;
    while (($r = fgetcsv($h, 0, $delim, '"', '\\')) !== false) {
        $line++;
        if (count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 0) continue;
        $row = [];
        foreach ($map as $i => $col) {
            $v = trim((string)($r[$i] ?? ''));
            $row[$col] = match ($col) {
                'purchase_date', 'last_maintenance', 'last_audit' => to_date($v),
                'backup', 'security_controls', 'remote_access' => to_bool($v),
                'ram_gb', 'storage_gb' => is_numeric($v) ? (int)$v : null,
                'completeness' => is_numeric($x = rtrim($v, '%')) ? (int)round((float)$x > 0 && (float)$x < 1 ? (float)$x * 100 : (float)$x) : 0,
                'serial_number' => $v === '' ? null : mb_substr($v, 0, 64),
                default => mb_substr($v, 0, 120),
            };
        }
        if (($row['category'] ?? '') === '' || !in_array($row['location'] ?? '', LOCATIONS, true)) { $skipped[] = $line; continue; }
        foreach (['status' => 'In Use', 'maint_schedule' => 'Quarterly', 'risk_rating' => 'Low', 'business_impact' => 'Low', 'criticality' => 'Low', 'os_family' => 'Not recorded', 'brand' => 'Other'] as $k => $d) {
            if (($row[$k] ?? '') === '') $row[$k] = $d;
        }
        $row['updated_by'] = $user['username'];
        $rows[] = $row;
    }
    fclose($h);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($mode === 'replace') $pdo->exec('DELETE FROM assets');
        $cols = array_values(array_unique([...array_values($map), 'status', 'maint_schedule', 'risk_rating', 'business_impact', 'criticality', 'os_family', 'brand', 'updated_by']));
        $st = $pdo->prepare('INSERT INTO assets (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        foreach ($rows as $row) $st->execute(array_map(fn($c) => $row[$c] ?? null, $cols));
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('Nothing was imported: ' . $ex->getMessage(), 'err'); redirect('import.php');
    }
    $msg = count($rows) . ' assets ' . ($mode === 'replace' ? 'imported. The old register was replaced.' : 'added to the register.');
    if ($skipped) $msg .= ' Skipped ' . count($skipped) . ' rows with no category or an unknown location (lines ' . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? '…' : '') . ').';
    flash($msg, $skipped ? 'warn' : 'ok');
    redirect('import.php');
}

$total = (int)db()->query('SELECT COUNT(*) FROM assets')->fetchColumn();
$last = db()->query('SELECT MAX(updated_at) FROM assets')->fetchColumn();
page_start('Import Data', 'import');
?>
  <section class="hello">
    <div><h1>Import Data</h1><p>The system currently holds <b><?= $total ?></b> assets<?= $last ? ', last changed ' . e(date('j M Y, H:i', strtotime((string)$last))) : '' ?>.</p></div>
  </section>

  <div class="g2" style="margin-top:0">
    <form class="panel form" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="ph"><h2><?= icon('upload') ?>Upload a CSV</h2></div>
      <p class="desc">In Excel, open the asset register, go to the <b>Data</b> sheet and choose File → Save As → <b>CSV UTF-8</b>. Upload that file here.</p>
      <label for="csv"><span>CSV file</span><input type="file" name="csv" id="csv" accept=".csv,text/csv" required></label>
      <fieldset class="radios"><legend>What should happen to the current register?</legend>
        <label class="check"><input type="radio" name="mode" value="replace" checked> Replace it with this file</label>
        <label class="check"><input type="radio" name="mode" value="add"> Keep it and add these rows</label>
      </fieldset>
      <div class="formbar"><button class="btn dark" type="submit">Import</button></div>
    </form>
    <section class="panel">
      <div class="ph"><h2><?= icon('list') ?>Columns the import reads</h2></div>
      <p class="desc">Headings are matched by name, in any order. Other columns (like Attention Score or Age) are ignored because the app calculates them. Category and Location are required; Location must be one of the six areas.</p>
      <div class="tags" style="margin-top:10px"><?php foreach (array_keys(IMPORT_MAP) as $k): ?><span class="tag"><?= e(ucwords($k)) ?></span><?php endforeach; ?></div>
      <p class="desc" style="margin-top:12px">Dates can be 2025-06-10, 10/06/2025 or Excel date numbers. Yes / No columns accept Yes, No, TRUE, FALSE, 1 or 0.</p>
    </section>
  </div>
<?php page_end();
