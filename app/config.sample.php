<?php
// Copy this file to app/config.php and fill in your database details.
return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'nedco_fleet',
        'user'     => 'nedco_fleet',
        'password' => 'change-me',
    ],
    'app_name'  => 'NEDCo IT Asset Inventory',
    'org_line'  => 'Finance ICT',

    // Leave out devices rated High risk from every page (matches the web dashboard).
    'exclude_high_risk' => true,

    // Date used for "days overdue", ages and audit status. null = today.
    // Set e.g. '2026-09-24' to reproduce the figures of the Excel export exactly.
    'as_of' => null,

    // Account lock-out (progressive): this many CONSECUTIVE failed sign-ins lock the account for level-1 minutes;
    // the next failure after that lock expires locks it for level-2 minutes; the next failure after that disables
    // the account until an administrator re-enables it. A successful sign-in resets the count.
    'lockout_threshold'      => 3,
    'lockout_level1_minutes' => 60,
    'lockout_level2_minutes' => 120,

    // IP throttle: an IP address is blocked for lockout_minutes after 4× max_failed_logins failed sign-ins
    // (offices share one public IP). This is separate from the account lock-out above.
    'max_failed_logins' => 5,
    'lockout_minutes'   => 15,

    // Roles: SUPER ADMIN > ADMIN > USER. The Super Admin is created by setup.php, or on an existing install with
    // `php tools/create_super_admin.php <username>`. By default there is exactly one Super Admin and nobody can manage
    // another Super Admin. Set true to allow several; they can then manage each other (never themselves), and the last
    // active Super Admin can never be disabled or demoted.
    'allow_multiple_super_admins' => false,
    // May an ordinary ADMIN add admin accounts / promote users to admin? (true = as before this change.) Set false to
    // make creating administrators a Super Admin-only action.
    'admins_can_grant_admin' => true,

    // Log users out after this many minutes without activity.
    'idle_timeout_minutes' => 60,

    'timezone' => 'Africa/Accra',
];
