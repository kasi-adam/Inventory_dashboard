<?php
declare(strict_types=1);
/*
 * Role hierarchy and account-management authorization. Everything here runs on the SERVER.
 *
 *     SUPER ADMIN  (users.role = 'super_admin')   manages ADMIN and USER accounts
 *          ↓
 *     ADMIN        (users.role = 'admin')         manages USER accounts only
 *          ↓
 *     USER         (users.role = 'viewer')        manages nothing (stored as 'viewer' since the first release)
 *
 * Rules enforced by account_action_denied() / account_action_apply():
 *   - Nobody can be managed by someone of the same or a lower rank. The only exception is a Super Admin managing
 *     another Super Admin, and only when 'allow_multiple_super_admins' is true in app/config.php (default false).
 *   - Nobody changes their own role or disables themselves. (They may unlock themselves and reset their own password.)
 *   - Only a Super Admin can change an ADMIN's role, or unlock / disable / re-enable / reset the password of an ADMIN.
 *   - An ADMIN can never touch a SUPER ADMIN or another ADMIN, by any action, however the request is crafted.
 *   - The last active Super Admin can never be disabled or demoted.
 *   - Password resets are covered too: resetting someone's password lets you sign in as them, so it is treated as
 *     a privilege-bearing action and follows the same hierarchy.
 *
 * The roles used for these decisions are ALWAYS read from the database inside account_action_apply(), never from the
 * session, the form or the URL. No management form carries a target role: the action name decides the transition.
 * The UI (users.php) calls the same account_action_denied() to decide which buttons to draw, so what you see and what
 * the server accepts cannot drift apart. Hiding a button is a convenience only; the server re-checks every request.
 */

const ROLE_USER  = 'viewer';
const ROLE_ADMIN = 'admin';
const ROLE_SUPER = 'super_admin';

/** Every action users.php may request. */
const ACCOUNT_ACTIONS = ['unlock', 'enable', 'disable', 'promote', 'demote', 'promote_super', 'demote_super', 'reset'];

/** 3 super admin, 2 admin, 1 user, 0 = unrecognised (no privileges: unknown or truncated role values fail closed). */
function role_rank(?string $role): int
{
    return match ($role) { ROLE_SUPER => 3, ROLE_ADMIN => 2, ROLE_USER => 1, default => 0 };
}
function role_label(?string $role): string
{
    return match ($role) { ROLE_SUPER => 'Super Admin', ROLE_ADMIN => 'Administrator', default => 'User' };
}
/** May there be more than one Super Admin, and may they manage each other? Default: no. */
function multiple_super_admins(): bool { return (bool)cfg('allow_multiple_super_admins', false); }
/** May an ordinary ADMIN add admin accounts or promote users to admin? (Existing behaviour, so the default is yes.) */
function admins_can_grant_admin(): bool { return (bool)cfg('admins_can_grant_admin', true); }

/** May $actor create a NEW account with this role (users.php "Add a user")? */
function account_role_creatable(array $actor, string $role): bool
{
    $ar = role_rank($actor['role'] ?? '');
    return match ($role) {
        ROLE_USER  => $ar >= 2,
        ROLE_ADMIN => $ar === 3 || ($ar === 2 && admins_can_grant_admin()),
        ROLE_SUPER => $ar === 3 && multiple_super_admins(),
        default    => false,
    };
}

/**
 * The policy. Pure function: no database access, no session.
 * $actor and $target need: id, role, active (and lock_level / locked_until are not needed here).
 * $activeSupers = number of ACTIVE super admins (for the "last Super Admin" guard).
 * Returns null when the action is allowed, otherwise ['auth' | 'state', message]:
 *   'auth'  = not permitted for this actor (the caller records it as a denied attempt);
 *   'state' = permitted in principle, but makes no sense for the account's current state (e.g. enabling an active account).
 */
function account_action_denied(array $actor, array $target, string $act, int $activeSupers = 2): ?array
{
    if (!in_array($act, ACCOUNT_ACTIONS, true)) return ['auth', 'Unknown action.'];
    $ar = role_rank($actor['role'] ?? '');
    $tr = role_rank($target['role'] ?? '');
    if ($ar < 2 || !(int)($actor['active'] ?? 0)) return ['auth', 'Only administrators can manage accounts.'];
    if ($tr === 0) return ['auth', 'This account has an unrecognised role and cannot be changed here.'];   // fail closed

    $self = (int)$actor['id'] === (int)$target['id'];
    if ($self) {
        if (in_array($act, ['unlock', 'reset'], true)) return null;
        return ['auth', 'You cannot change your own role or disable your own account.'];
    }

    // Who may manage whom.
    $may = $ar === 3 ? ($tr < 3 || multiple_super_admins()) : $tr === 1;
    if (!$may) {
        if ($tr === 3) return ['auth', $ar === 3 ? 'Super Admin accounts cannot be managed by other Super Admins (multiple Super Admins are not enabled).' : 'Super Admin accounts are protected.'];
        return ['auth', 'Only a Super Admin can manage administrator accounts.'];
    }

    $active = (bool)(int)$target['active'];
    switch ($act) {
        case 'reset':   return null;
        case 'unlock':  return $active ? null : ['state', 'This account is disabled. Enable it instead.'];
        case 'enable':  return $active ? ['state', 'This account is already active.'] : null;
        case 'disable':
            if (!$active) return ['state', 'This account is already disabled.'];
            if ($tr === 3 && $activeSupers <= 1) return ['auth', 'The last active Super Admin cannot be disabled.'];
            return null;
        case 'promote':   // user → administrator
            if ($tr !== 1) return ['state', 'Only ordinary users can be made administrators.'];
            if ($ar < 3 && !admins_can_grant_admin()) return ['auth', 'Only a Super Admin can make someone an administrator.'];
            return null;
        case 'demote':    // administrator → user
            if ($ar < 3) return ['auth', 'Only a Super Admin can change an administrator’s role.'];
            return $tr === 2 ? null : ['state', 'Only administrators can be changed to ordinary users.'];
        case 'promote_super':   // administrator → Super Admin (only with multiple Super Admins enabled)
            if ($ar < 3 || !multiple_super_admins()) return ['auth', 'Only a Super Admin can do this, and only when multiple Super Admins are enabled.'];
            return $tr === 2 ? null : ['state', 'Only an administrator can be made a Super Admin.'];
        case 'demote_super':    // Super Admin → administrator
            if ($ar < 3 || !multiple_super_admins()) return ['auth', 'Only a Super Admin can do this, and only when multiple Super Admins are enabled.'];
            if ($tr !== 3) return ['state', 'This account is not a Super Admin.'];
            return $activeSupers <= 1 && $active ? ['auth', 'The last active Super Admin cannot be demoted.'] : null;
    }
    return ['auth', 'Unknown action.'];
}

/**
 * The ONLY code path that changes another account's status, role or password. users.php calls it for every POST.
 * $actorSession supplies the actor's id and username; the actor's ROLE and the target's role are re-read from the
 * database here, inside a transaction with both rows locked, so a forged session or form value cannot matter and a
 * concurrent change cannot slip past the check. Each UPDATE also repeats the role it was authorised against.
 * Returns [bool ok, string message]. Every change, and every refused attempt, is written to account_lock_log.
 */
function account_action_apply(array $actorSession, int $targetId, string $act, ?string $newPassword = null): array
{
    if (!in_array($act, ACCOUNT_ACTIONS, true)) return [false, 'Unknown action.'];
    $pdo = db();
    $actorId = (int)($actorSession['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $supers = count($pdo->query("SELECT id FROM users WHERE role = '" . ROLE_SUPER . "' AND active = 1 FOR UPDATE")->fetchAll());
        $st = $pdo->prepare('SELECT id, username, role, active FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $st->execute([$actorId, $targetId]);
        $rows = [];
        foreach ($st->fetchAll() as $r) $rows[(int)$r['id']] = $r;
        $a = $rows[$actorId] ?? null; $t = $rows[$targetId] ?? null;
        if (!$a || !$t) { $pdo->rollBack(); return [false, 'That account no longer exists.']; }

        $deny = account_action_denied($a, $t, $act, $supers);
        if ($deny !== null) {
            $pdo->rollBack();
            if ($deny[0] === 'auth') log_lock_event((int)$t['id'], $t['username'], 'denied_' . $act, $a['username']);
            return [false, $deny[1]];
        }

        $id = (int)$t['id']; $role = (string)$t['role']; $un = (string)$t['username'];
        $set = fn(string $sql, array $p) => $pdo->prepare($sql)->execute($p);
        switch ($act) {
            case 'unlock':   // Lift a 1-hour or 2-hour lock. Clears the progressive state; the account returns to ACTIVE.
                account_clear_lock_state($id);
                log_lock_event($id, $un, 'unlock', $a['username']);
                $msg = 'Account unlocked.'; break;
            case 'enable':   // Re-enable a disabled account (manual or by the lock-out) with a clean progressive state.
                $set('UPDATE users SET active = 1 WHERE id = ? AND role = ?', [$id, $role]);
                account_clear_lock_state($id);
                log_lock_event($id, $un, 'enable', $a['username']);
                $msg = 'Account enabled.'; break;
            case 'disable':
                $set('UPDATE users SET active = 0 WHERE id = ? AND role = ?', [$id, $role]);
                log_lock_event($id, $un, 'admin_disable', $a['username']);   // not 'disabled': that name means "disabled by the lock-out" and feeds the risk score
                $msg = 'Account disabled.'; break;
            case 'promote':
                $set('UPDATE users SET role = ? WHERE id = ? AND role = ?', [ROLE_ADMIN, $id, ROLE_USER]);
                log_lock_event($id, $un, 'promote', $a['username']);
                $msg = 'User updated.'; break;
            case 'demote':
                $set('UPDATE users SET role = ? WHERE id = ? AND role = ?', [ROLE_USER, $id, ROLE_ADMIN]);
                log_lock_event($id, $un, 'demote', $a['username']);
                $msg = 'User updated.'; break;
            case 'promote_super':
                $set('UPDATE users SET role = ? WHERE id = ? AND role = ?', [ROLE_SUPER, $id, ROLE_ADMIN]);
                log_lock_event($id, $un, 'promote_super', $a['username']);
                $msg = 'User updated.'; break;
            case 'demote_super':
                $set('UPDATE users SET role = ? WHERE id = ? AND role = ?', [ROLE_ADMIN, $id, ROLE_SUPER]);
                log_lock_event($id, $un, 'demote_super', $a['username']);
                $msg = 'User updated.'; break;
            case 'reset':
                if ($newPassword === null || strlen($newPassword) < 10) { $pdo->rollBack(); return [false, 'Passwords need at least 10 characters.']; }
                $set('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), $id]);
                log_lock_event($id, $un, 'pw_reset', $a['username']);
                $msg = 'Password reset.'; break;
            default:
                $pdo->rollBack(); return [false, 'Unknown action.'];
        }
        $pdo->commit();
        return [true, $msg];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}
