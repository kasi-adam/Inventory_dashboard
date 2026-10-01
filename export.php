<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/fleet.php';
require_login();
$loc = in_array(q('loc'), LOCATIONS, true) ? q('loc') : '';
csv_download('asset-register' . ($loc ? '-' . preg_replace('/\W+/', '-', strtolower($loc)) : '') . '-' . date('Y-m-d') . '.csv', by_priority(in_location(all_assets(), $loc)));
