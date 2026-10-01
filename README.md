# NEDCo IT Asset Inventory — PHP dashboard

A sign-in-protected web version of the Finance ICT asset register dashboard.
Needs PHP 8.1+ (with PDO MySQL) and MySQL 5.7+/MariaDB 10.3+. No Composer, no frameworks.

## Install (cPanel or any Apache/Nginx host)

1. **Create a database and user** (cPanel → MySQL Databases). Give the user all privileges on the database.
2. **Upload** the contents of this folder to your web folder, e.g. `public_html/fleet/`.
3. **Configure:** copy `app/config.sample.php` to `app/config.php` and enter the database name, user and password.
4. **Run setup:** open `https://your-site/fleet/setup.php`. It creates the tables, loads the 200 assets from the register (tick the box), and creates your first account, which is the **Super Admin**. Setup locks itself once a user exists; you can delete `setup.php` afterwards.
5. **Sign in** at `login.php`, then add colleagues under **Users & sign-ins**.

With SSH instead of setup.php:

```
mysql -u USER -p DBNAME < sql/schema.sql
mysql -u USER -p DBNAME < sql/seed.sql
php tools/create_super_admin.php manal "Manal Napari Adam"
```

Use HTTPS on the live site so passwords and session cookies are encrypted.

## Pages

| Page | What it does |
|---|---|
| Overview (`index.php`) | Greeting, 6 KPI cards with trends, purchase history chart, "Needs attention" feed (Maintenance / Condition / Audit), priority monitoring table with search, problem filter and paging. Location filter in the top right and sidebar. |
| Asset Finder (`finder.php`) | Pick a problem and a location: every matching machine with its user, worst first. Count per location. CSV export. |
| Asset register (`assets.php`) | Full list, search, filters, sortable columns, CSV export.. The asset search boxes (sidebar, Assets page, dashboard) suggest real users, models, brands, categories, locations and serial numbers as you type (`suggest.php` + `assets/suggest.js`); browser history suggestions are turned off. |
| Location scorecard (`locations.php`) | Location comparison, Windows support by location, maintenance compliance, assets by category. |
| Edit asset (`edit.php`, admins) | Change any field, **Record maintenance today** in one click, add or delete assets. |
| Import data (`import.php`, admins) | Upload the Data sheet saved as CSV UTF-8: replace the register or add rows. |
| Users & sign-ins (`users.php`, admins) | Add users, disable, reset passwords, unlock / re-enable (what each person may do depends on their role, see *Roles*), sign-in counts and the last 50 sign-in attempts with IP and browser. |
| Security (`security.php`, admins and Super Admin) | Sign-in activity from `auth_log` for a chosen period (24 h, 7, 30, 90 days or custom): totals, lock-outs, a stacked activity chart, the Security Risk indicator with its contributing events, and a filterable event table. Admins also see a compact Security activity card on the dashboard (last 7 days). |
| Change password (`account.php`) | Every user can change their own password. |

## What is calculated

Stored: the register facts (serial, user, location, OS, dates, backup, etc.).
Calculated on every page load, relative to today (or `as_of` in the config):
age and age band, days since maintenance and days overdue (Quarterly 91, Biannually 182, Annually 365),
audit status (over 12 months), OS support, and the Attention Score (same weights as the Excel workbook).
With `as_of => '2026-09-24'` and `exclude_high_risk => false` the figures match the Excel export exactly
(200 assets, 152 overdue, 82 unsupported, 83 audits overdue, average score 41.04).

## Risk

The risk sections are removed: no High-risk card, no risk × impact matrix, no risk column or filter.
Devices rated High risk are left out of every page (`exclude_high_risk => true`). Set it to `false` to include them.
The risk rating is still stored and still counts in the Attention Score, as in the workbook.

## Roles

```
SUPER ADMIN  (users.role = 'super_admin')   manages ADMIN and USER accounts
     ↓
ADMIN        (users.role = 'admin')         manages USER accounts only
     ↓
USER         (users.role = 'viewer')        manages no accounts
```

| | Super Admin | Admin | User |
|---|---|---|---|
| Dashboard, assets, locations, CSV export | yes | yes | yes |
| Edit assets, import data, Security page, User Management page | yes | yes | no (403) |
| Unlock / re-enable / disable / reset password of a **User** | yes | yes | no |
| Unlock / re-enable / disable / reset password / change role of an **Admin** | yes | **no** | no |
| Touch a **Super Admin** in any way | only with multiple Super Admins enabled (never themselves) | **no** | no |

Rules, all enforced on the server in `app/authz.php` (`account_action_denied()` decides, `account_action_apply()` is the only code that changes another account):

- A person can only manage accounts ranked **below** them. Nobody changes their own role or disables themselves (they can unlock themselves and reset their own password).
- The roles used are read from the database, in a transaction with both rows locked, on every request. The session, form fields, URL and browser values are never trusted; no management form carries a target role (the action name decides the change). A forged `role=super_admin` is ignored.
- Password reset is covered: resetting someone's password lets you sign in as them, so an Admin cannot reset another Admin's or the Super Admin's password.
- An unrecognised role value has no privileges and cannot be managed (fails closed).
- The last active Super Admin cannot be disabled or demoted.
- Buttons are drawn from the same policy function, so a user only sees controls the server would accept. Hiding is a convenience; the server re-checks every request.
- Every change **and every refused attempt** is written to `account_lock_log` (`unlock`, `enable`, `admin_disable`, `promote`, `demote`, `pw_reset`, `add_*`, `denied_*`, with the acting username). Only the automatic `lock_1h` / `lock_2h` / `disabled` events count towards the Security Risk indicator.

Settings in `app/config.php`:

- `allow_multiple_super_admins` (default `false`): exactly one Super Admin; Super Admins cannot be created in the interface and nobody can manage another Super Admin. If `true`, a Super Admin can make an Admin a Super Admin (and back), and Super Admins can unlock, disable and re-enable each other, but never themselves.
- `admins_can_grant_admin` (default `true`, the behaviour before the Super Admin role existed): an Admin may add administrator accounts or promote Users. Set `false` to make that Super Admin-only.

**Upgrading an existing install:** the first time the app connects it widens `users.role` to `VARCHAR(20)` (so `super_admin` fits) if the database user may alter tables; otherwise run `sql/migrate_super_admin.sql` once. No account changes role by itself. Then name your Super Admin: `php tools/create_super_admin.php <existing-username>`.

**Super Admin recovery** (an Admin is locked or disabled): sign in as the Super Admin → User Management → Manage → *Unlock account* or *Enable account*. This clears the lock-out counters and the Admin can sign in straight away.

**If the Super Admin itself is locked or disabled** (it is still subject to the progressive lock-out, so someone guessing its password can lock it): on the server run `php tools/create_super_admin.php <super-admin-username>`. It re-enables the account and clears the lock-out; type a new password if you want to reset it (leave empty to keep it). It needs access to the server and the database login, so web users cannot do it. With `allow_multiple_super_admins` on, another Super Admin can also unlock it from User Management.

Tests: `php tools/test_authz.php` (roles and recovery; uses the real code and database and deletes its throw-away accounts) and `php tools/test_lockout.php` (the progressive lock-out).

## Security

- Passwords hashed with PHP `password_hash` (bcrypt/argon); sessions regenerated on sign-in; HttpOnly, SameSite cookies.
- CSRF tokens on every form; all queries use prepared statements; all output escaped.
- Progressive account lock-out (enforced on the server): 3 consecutive failed sign-ins lock the account for 1 hour; the next failure after that lock expires locks it for 2 hours; the next failure after that disables it until an administrator re-enables it. A successful sign-in resets the count. Administrators can unlock or re-enable **Users**, and only the Super Admin can unlock or re-enable **Administrators** (see *Roles*), at any time (User Management → Manage). Lock changes are recorded in `account_lock_log`. An IP address is also throttled after 20 failed attempts in 15 minutes. Every attempt is logged.
- **Upgrading an existing install:** the new lock-out columns and `account_lock_log` table are added automatically the first time the app connects (if the database user may alter tables). If that is not allowed, run `sql/migrate_progressive_lockout.sql` once, or `php tools/migrate_lockout.php`, which is safe to repeat. Settings: `lockout_threshold`, `lockout_level1_minutes`, `lockout_level2_minutes` in `app/config.php` (defaults 3 / 60 / 120).
- **If every administrator ends up disabled,** sign in as the Super Admin and re-enable them; if the Super Admin is the locked one, run `php tools/create_super_admin.php <username>` on the server (see *Roles*). `tools/create_admin.php` still works for ordinary admins and never demotes a Super Admin.
- Idle sessions end after 60 minutes (configurable).
- `.htaccess` files block direct access to `app/`, `sql/` and `tools/`. On Nginx, deny those folders in the server block.

## Security Risk indicator

Built only from `auth_log`. Points per event in the selected period: successful sign-in 0, failed sign-in for an existing account 2,
failed sign-in with an unrecognised or disabled username 3, account lock-out 10, IP lock-out 15. Lock-outs are not logged by the
application, so they are derived by replaying the same rule used at sign-in (`max_failed_logins` per username, 4× that per IP,
within `lockout_minutes`). Periods longer than 7 days are converted to points per 7 days. Bands: 0–9 Low, 10–29 Moderate,
30–59 Elevated, 60+ High. It describes observed sign-in activity; it is not a probability that the system is vulnerable.
Weights and bands can be overridden with `security_weights` / `security_bands` in `app/config.php`. Full notes are in `app/security.php`.

## Branding

The interface uses the NEDCo IT Asset Inventory visual identity.

- **Official logo:** `assets/nedco-logo.png` is the supplied high-resolution NEDCo logo (426x90) with only its blank white margin trimmed; the artwork itself is untouched. The untouched supplied file is in `assets/brand/nedco-logo-original.png`. If NEDCo provides an SVG, save it as `assets/nedco-logo.svg` and change the file name in `brand_logo()` in `app/brand.php`. Do not recolour or stretch it.
- **System mark:** the "asset tag + lightning bolt + data node" mark is drawn by `brand_mark()` in `app/brand.php`; the favicon files are in `assets/brand/`.
- **Colours:** all colours are CSS variables at the top of `assets/style.css` (light, dark and system-dark blocks). Change them there, not in individual rules. Brand yellow appears only in the system mark; status colours (green, amber, orange, red) are kept for status only.
- **Fonts:** Archivo, IBM Plex Sans and IBM Plex Mono are loaded from Google Fonts. For an offline intranet, self-host them and replace the `<link>` tags.

## Branding

The interface uses the NEDCo IT Asset Inventory visual identity.

- **Official logo:** `assets/nedco-logo.png` is the supplied high-resolution NEDCo logo (426x90) with only its blank white margin trimmed; the artwork itself is untouched. The untouched supplied file is in `assets/brand/nedco-logo-original.png`. If NEDCo provides an SVG, save it as `assets/nedco-logo.svg` and change the file name in `brand_logo()` in `app/brand.php`. Do not recolour or stretch it.
- **System mark:** the "asset tag + lightning bolt + data node" mark is drawn by `brand_mark()` in `app/brand.php`; the favicon files are in `assets/brand/`.
- **Colours:** all colours are CSS variables at the top of `assets/style.css` (light, dark and system-dark blocks). Change them there, not in individual rules. Brand yellow appears only in the system mark; status colours (green, amber, orange, red) are kept for status only.
- **Fonts:** Archivo, IBM Plex Sans and IBM Plex Mono are loaded from Google Fonts. For an offline intranet, self-host them and replace the `<link>` tags.
