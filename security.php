<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require __DIR__ . '/app/ui.php';
require __DIR__ . '/app/security.php';
// Administrators only — the application's existing check (re-reads role from the database, answers 403 otherwise).
$me = require_admin();

$P = sec_period(q('range', '7d'), q('from'), q('to'));
$rows = sec_rows($P['from'], $P['to']);
$S = sec_summary($rows, $P['days']);
$chart = sec_buckets($rows, $P['from'], $P['to']);
$first = sec_first_record();
$limited = $first !== null && strtotime($first) > $P['from']->getTimestamp();

/* activity table: newest first, optional result filter, 25 per page */
$ev = in_array(q('ev'), ['ok', 'fail', 'lock'], true) ? q('ev') : '';
$list = array_reverse(array_values(array_filter($rows, fn($r) => $r['in'] && match ($ev) {
    'ok' => $r['kind'] === 'ok', 'fail' => $r['kind'] !== 'ok', 'lock' => $r['lock_user'] || $r['lock_ip'], default => true })));
$per = 25; $pg = max(1, (int)q('pg', '1')); $pages = max(1, (int)ceil(count($list) / $per)); $pg = min($pg, $pages);
$shown = array_slice($list, ($pg - 1) * $per, $per);
$keep = ['range' => $P['key'], 'from' => $P['key'] === 'custom' ? $P['fromStr'] : '', 'to' => $P['key'] === 'custom' ? $P['toStr'] : '', 'ev' => $ev];

/* where failures come from (top 5 usernames and IPs in the period) */
$topU = $topIp = [];
foreach ($rows as $r) if ($r['in'] && $r['kind'] !== 'ok') { $topU[$r['username']] = ($topU[$r['username']] ?? 0) + 1; $topIp[$r['ip']] = ($topIp[$r['ip']] ?? 0) + 1; }
arsort($topU); arsort($topIp); $topU = array_slice($topU, 0, 5, true); $topIp = array_slice($topIp, 0, 5, true);

$W = sec_weights(); $max = (int)cfg('max_failed_logins', 5); $mins = (int)cfg('lockout_minutes', 15);
$reasons = sec_reasons($S);

page_start('Security', 'security');
?>
  <section class="hello">
    <div><h1>Security</h1><p>Sign-in activity recorded by the application</p></div>
  </section>

  <form class="secbar" method="get" action="security.php" id="secfilter" aria-label="Time period">
    <span class="lbl"><?= icon('cal') ?>Time period</span>
    <label class="selwrap"><select name="range" id="secrange" aria-label="Time period">
      <?php foreach (SEC_PERIODS as $k => [$l]): ?><option value="<?= $k ?>"<?= $P['key'] === $k && q('range') !== 'custom' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      <option value="custom"<?= q('range') === 'custom' ? ' selected' : '' ?>>Custom range…</option>
    </select></label>
    <span class="custom" id="seccustom"<?= q('range') === 'custom' ? '' : ' data-hide' ?>>
      <label class="datewrap"><span>From</span><input type="date" name="from" value="<?= e($P['fromStr']) ?>" max="<?= date('Y-m-d') ?>" aria-label="From date"></label>
      <label class="datewrap"><span>To</span><input type="date" name="to" value="<?= e($P['toStr']) ?>" max="<?= date('Y-m-d') ?>" aria-label="To date"></label>
    </span>
    <button class="btn dark" type="submit">Apply</button>
    <noscript><style>#seccustom[data-hide]{display:inline-flex!important}</style></noscript>
    <span class="showing">Showing <b><?= e($P['label']) ?></b></span>
  </form>
  <?php if ($P['error']): ?><div class="flash err" role="alert"><?= e($P['error']) ?> Showing the last 7 days instead.</div><?php endif; ?>

  <section class="panel sec-main" id="activity" aria-label="Security activity">
    <div class="ph">
      <h2><?= icon('chart') ?>Security activity</h2>
      <span class="muted"><?= e($P['label']) ?> · per <?= $chart['step'] === 3600 ? 'hour' : ($chart['step'] === 86400 ? 'day' : 'week') ?></span>
    </div>

    <dl class="secstats">
      <div><dt>Sign-in attempts</dt><dd><?= $S['total'] ?></dd><small><?= $S['total'] ? round($S['failed'] / $S['total'] * 100) . '% failed' : 'None recorded' ?> · <?= $S['ips'] ?> IP address<?= $S['ips'] === 1 ? '' : 'es' ?></small></div>
      <div><dt>Successful</dt><dd><?= $S['counts']['ok'] ?></dd><small><?= $S['people'] ?> different user<?= $S['people'] === 1 ? '' : 's' ?></small></div>
      <div><dt>Failed</dt><dd><?= $S['failed'] ?></dd><small><?= $S['counts']['fail_unknown'] ?> unrecognised username · <?= $S['fail_ips'] ?> IP address<?= $S['fail_ips'] === 1 ? '' : 'es' ?></small></div>
      <div><dt>Lock-outs</dt><dd><?= $S['lockouts'] ?></dd><small><?= $S['counts']['lock_user'] ?> account · <?= $S['counts']['lock_ip'] ?> IP address</small></div>
    </dl>

    <div class="legend" aria-hidden="true"><span><i class="k-ok"></i>Successful</span><span><i class="k-fail"></i>Failed · existing account</span><span><i class="k-unk"></i>Failed · unrecognised username</span><span><i class="k-lock"></i>Lock-out</span></div>
    <?= sec_chart($chart, 'No sign-in attempts were recorded in this period.') ?>
    <?php if ($limited): ?><p class="desc note">Sign-in records start on <?= e(date('j M Y, H:i', strtotime($first))) ?>. Earlier dates have no data, not zero activity.</p><?php elseif ($first === null): ?><p class="desc note">No sign-in attempts have been recorded yet.</p><?php endif; ?>
  </section>

  <details class="panel how assess" id="how">
    <summary>
      <span class="asslevel"><i class="dot d-<?= e($S['tone']) ?>" aria-hidden="true"></i>Security Risk: <b><?= e($S['level']) ?></b></span>
      <span class="asstext"><?php
        if ($S['total'] === 0) echo 'No sign-in activity was recorded during the selected period.';
        elseif ($S['tone'] === 'good') echo 'Security activity looks routine during the selected period.';
        elseif ($S['tone'] === 'warn') echo 'Moderate security activity detected during the selected period.';
        elseif ($S['tone'] === 'serious') echo 'Elevated security activity detected during the selected period.';
        else echo 'High security activity detected during the selected period.';
      ?></span>
      <span class="asstoggle" aria-hidden="true"></span>
    </summary>
    <div class="assbody">
      <p class="desc">Based only on sign-in events recorded in the selected period. It describes observed activity; it is not a prediction or probability that the system is vulnerable.</p>

      <h3 class="sub3">Contributing events</h3>
      <?php if ($S['parts']): $L = sec_part_labels(); ?>
      <ul class="reasons">
        <?php foreach ($S['parts'] as $k => [$n, $pts]): ?>
        <li><b><?= $n ?></b> <?= e($L[$k][$n === 1 ? 0 : 1]) ?><?php
          if ($k === 'lock_user') echo ' <span class="muted">(' . lock_policy()['threshold'] . ' consecutive failures, or the next failure after a lock expired)</span>';
          elseif ($k === 'lock_ip') echo ' <span class="muted">(' . ($max * 4) . ' failed attempts from one IP address within ' . $mins . ' minutes)</span>';
        ?></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><p class="desc">No failed sign-ins or lock-outs in this period.</p><?php endif; ?>

      <?php if ($topU): ?>
      <h3 class="sub3">Most failed attempts</h3>
      <div class="toplists">
        <ol><?php foreach ($topU as $u => $n): ?><li><span><?= e($u) ?></span><b><?= $n ?></b></li><?php endforeach; ?></ol>
        <ol><?php foreach ($topIp as $ip => $n): ?><li><span class="mono"><?= e($ip) ?></span><b><?= $n ?></b></li><?php endforeach; ?></ol>
      </div>
      <?php endif; ?>

      <details class="scoring">
        <summary>Scoring details</summary>
        <p class="desc">Each recorded sign-in event earns fixed points. For periods longer than 7 days, the total is converted to points per 7 days so that longer periods are comparable. The level is set by the bands below.</p>
        <?php if ($reasons): ?><ul class="reasons"><?php foreach ($reasons as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <div class="scoreline"><span>Total</span><b><?= $S['points'] ?> pts</b><?php if ($S['normalised']): ?><span>÷ <?= e(rtrim(rtrim(number_format($P['days'], 1), '0'), '.')) ?> days × 7</span><b><?= e($S['score']) ?> pts / 7 days</b><?php endif; ?></div>
        <div class="tbl"><table class="compact">
          <thead><tr><th>Event</th><th>Where it comes from</th><th class="num">Points</th></tr></thead><tbody>
            <tr><td>Successful sign-in</td><td>Logged by the application</td><td class="num"><?= (int)$W['ok'] ?></td></tr>
            <tr><td>Failed sign-in, existing account</td><td>Logged (wrong password)</td><td class="num"><?= (int)$W['fail_known'] ?></td></tr>
            <tr><td>Failed sign-in, unrecognised username</td><td>Logged (username does not exist or is disabled)</td><td class="num"><?= (int)$W['fail_unknown'] ?></td></tr>
            <tr><td>Account lock-out</td><td>Recorded: <?= lock_policy()['threshold'] ?> consecutive failures lock an account for 1 h, the next failure for 2 h, then it is disabled</td><td class="num"><?= (int)$W['lock_user'] ?></td></tr>
            <tr><td>IP address lock-out</td><td>Derived: <?= $max * 4 ?> failures from one IP within <?= $mins ?> min</td><td class="num"><?= (int)$W['lock_ip'] ?></td></tr>
          </tbody></table></div>
        <div class="bands"><?php $B = sec_bands(); foreach ($B as $i => $b): ?><span class="pill c-<?= $b[2] ?>"><?= e($b[0]) ?> · <?= $b[1] ?><?= isset($B[$i + 1]) ? '–' . ($B[$i + 1][1] - 1) : '+' ?></span><?php endforeach; ?></div>
        <p class="desc">Account lock-outs are recorded when they happen. IP address lock-outs are not (blocked attempts are refused before logging), so they are worked out by applying the same rule to the recorded failures. Location is not shown because the application does not record it.</p>
      </details>
    </div>
  </details>

  <section class="panel" id="events">
    <div class="ph">
      <h2><?= icon('list') ?>Recorded events<span class="muted evsub"><?= e($P['label']) ?></span></h2>
      <form class="ctrls" method="get" action="security.php#events">
        <?php foreach (['range', 'from', 'to'] as $k) if ($keep[$k] !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($keep[$k]) . '">'; ?>
        <label class="selwrap"><?= icon('list') ?><select name="ev" aria-label="Filter events" onchange="this.form.submit()">
          <?php foreach (['' => 'All events', 'ok' => 'Successful sign-ins', 'fail' => 'Failed sign-ins', 'lock' => 'Lock-out triggers'] as $k => $l): ?><option value="<?= $k ?>"<?= $ev === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></label>
        <noscript><button class="btn">Filter</button></noscript>
      </form>
    </div>
    <div class="tbl"><table>
      <thead><tr><th>Date / time</th><th>Username</th><th>Event</th><th>IP address</th><th>Browser</th></tr></thead><tbody>
      <?php foreach ($shown as $r): ?>
        <tr>
          <td><?= e(date('j M Y, H:i:s', $r['ts'])) ?></td>
          <td><?= e($r['username']) ?></td>
          <td><span class="evcell"><?= $r['kind'] === 'ok' ? pill('Signed in', 'good') : ($r['kind'] === 'fail_known' ? pill('Failed · wrong password', 'crit') : pill('Failed · unrecognised username', 'warn')) ?>
            <?php if ($r['lock_user']): ?><span class="pill c-serious" title="<?= e(['lock_1h' => 'This failure locked the account for 1 hour.', 'lock_2h' => 'This failure locked the account for 2 hours.', 'disabled' => 'This failure disabled the account until an administrator re-enables it.'][$r['lock_event']] ?? 'This failure locked the account.') ?>"><?= $r['lock_event'] === 'disabled' ? 'Account disabled' : 'Account locked' ?></span><?php endif; ?>
            <?php if ($r['lock_ip']): ?><span class="pill c-serious" title="This was failure number <?= $max * 4 ?> from this IP address within <?= $mins ?> minutes, so the address was locked.">IP locked</span><?php endif; ?></span></td>
          <td class="mono"><?= e($r['ip']) ?></td>
          <td title="<?= e($r['user_agent']) ?>"><?= e(sec_browser($r['user_agent'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shown): ?><tr><td colspan="5" class="empty"><?= $ev ? 'No events of this type in this period.' : 'No sign-in attempts were recorded in this period.' ?></td></tr><?php endif; ?>
      </tbody></table></div>
    <?php
      $from = $list ? ($pg - 1) * $per + 1 : 0; $to = min(count($list), $pg * $per);
      $prev = $pg > 1 ? '<a class="btn" href="' . e(url('security.php', $keep + ['pg' => $pg - 1]) . '#events') . '">Previous</a>' : '<span class="btn off">Previous</span>';
      $next = $pg < $pages ? '<a class="btn" href="' . e(url('security.php', $keep + ['pg' => $pg + 1]) . '#events') . '">Next</a>' : '<span class="btn off">Next</span>';
    ?>
    <div class="pager"><span>Showing <?= $from ?>–<?= $to ?> of <?= count($list) ?></span><div><?= $prev . $next ?></div></div>
  </section>
  <script>
  (function () {
    var s = document.getElementById('secrange'), c = document.getElementById('seccustom');
    if (!s || !c) return;
    function sync() { var on = s.value === 'custom'; c.toggleAttribute('data-hide', !on); c.querySelectorAll('input').forEach(function (i) { i.disabled = !on; }); }
    s.addEventListener('change', function () { sync(); if (s.value !== 'custom') s.form.submit(); else { var f = c.querySelector('input'); if (f) f.focus(); } });
    sync();
  })();
  </script>
<?php page_end();
