<?php
return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3302,
        'name'     => 'nedco_fleet',
        'user'     => 'root',
        'password' => '',
    ],
    'app_name'  => 'NEDCo IT Asset Inventory',
    'org_line'  => 'MIS',

    // true = hide the 30 devices rated High risk; false = show all assets.
    'exclude_high_risk' => false,

    // Date used for days overdue, ages and audit status. null = today.
    'as_of' => null,

    // Lock a username after this many failed sign-ins, for this many minutes.
    'max_failed_logins' => 5,
    'lockout_minutes'   => 15,

    // Sign users out after this many minutes without activity.
    'idle_timeout_minutes' => 60,

    'timezone' => 'Africa/Accra',
];
