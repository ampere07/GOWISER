# NetManager Source Audit - 2026-06-16

Scope: backend, frontend entry points, role/policy checks, notifications, MikroTik/RADIUS/Hotspot touchpoints, date/time handling, and environment-gated APIs.

## Executive Summary

- Implemented the requested role matrix for subscriber messaging, date override, account reset, map viewing, and router actions.
- Added Telegram forwarding for `logActivity()` so activity records can be mirrored to the configured Telegram group chat.
- Added explicit environment gates for Mail, Telegram, Google OAuth, and reCAPTCHA; SMS was already partially gated and is now enforced in more UI/API paths.
- Password reset is now available to every active user and remains blocked for `is_active = 0`.
- No PHP syntax errors were found in changed files using XAMPP PHP with `-n`.

## Critical

### C1. Runtime PHP CLI Environment Is Misconfigured

Evidence:
```text
php -n -l ... via Homebrew PHP failed: missing libicuio.74.dylib
XAMPP PHP starts with php_intl.dll/php_pdo_pgsql.dll/php_pdo_sqlite.dll/php_pgsql.dll warnings
```

Impact: CLI cron jobs, maintenance scripts, and lint/test commands can fail or emit noisy output depending on which `php` binary runs.

Recommended fix:
```bash
/Applications/XAMPP/xamppfiles/bin/php -n -l path/to/file.php
```

Also clean `/Applications/XAMPP/xamppfiles/etc/php.ini` to remove Windows-style `.dll` extension entries on macOS, or point cron jobs to the known-good XAMPP PHP binary.

### C2. Dashboard/Cron/Report Reads Still Depend On Database Time

Evidence:
```php
// api/dashboard_stats.php
CURDATE(), NOW(), DATE_SUB(CURDATE(), INTERVAL ...)

// modules/cronjobs/index.php
CURDATE(), DATE_ADD(CURDATE(), INTERVAL ...)
```

Impact: saved writes use PHP time in most new and existing write paths, but read filters can disagree with PHP timezone when MySQL timezone differs.

Recommended fix:
```php
$today = appToday();
$dayStart = appDayStart();
$dayEnd = appDayEnd();
// Use bound parameters instead of CURDATE()/NOW()
```

Priority files: `api/dashboard_stats.php`, `modules/cronjobs/index.php`, `modules/reports/index.php`.

## Major

### M1. Router-Side Mutations Need Ongoing Review In Non-Subscriber Flows

Current status: subscriber edit/add and subscriber view router actions now restrict router sync/suspend/disconnect to Admin/Superadmin. Existing plan profile sync is already Admin+ because plan add/edit require `ROLE_ADMIN`.

Recommended review targets:
```text
api/mikrotik_action.php
modules/plans/add.php
modules/plans/edit.php
modules/cronjobs/force_disconnect.php
```

The cron files intentionally run unattended and should remain token/CLI protected.

### M2. Cashier Notification Access Had Hidden API Mismatches

Fixed:
```php
modules/notifications/index.php
api/send_notification.php
api/notification_counts.php
api/notification_recipients.php
```

Risk reduced: Cashier can now access the notification workflow consistently when SMS is enabled, and receives JSON denial when not allowed.

### M3. User Read-Only Policy Is Mostly UI-Driven

Evidence:
```php
function canModifyRecords(): bool {
    return isLoggedIn() && currentRole() !== ROLE_USER;
}
```

Impact: Users are hidden from most add/edit/delete controls, but every mutating endpoint should still enforce server-side permissions. Several endpoints do enforce roles; keep auditing new endpoints against this pattern.

Recommended fix: for each add/edit/delete API, require one of `requireCanModify()`, `requireRole(...)`, or a specific capability helper before processing POST.

### M4. Account Password View Is Not Possible By Design

Fixed: `view_account_password` now returns JSON instead of an HTML 403 page. The underlying password is hashed, so reset is supported but display is not.

Recommended UI improvement: in `modules/subscribers/view.php`, remove the "Current Password" loader from the reset modal or label it as unavailable.

## Minor

### m1. Environment Flags Are Now Honored, But `.env` Has Only Three Enabled Flags

Found enabled flags:
```text
MAIL_ENABLED
SMS_ENABLED
TELEGRAM_ENABLED
```

Implemented optional flags:
```text
GOOGLE_ENABLED
RECAPTCHA_ENABLED
```

If those are added and set to `false`, the matching feature is disabled.

### m2. Telegram Activity Forwarding Is Best-Effort

Implementation:
```php
logActivity(...) -> sendTelegramActivity(...) -> sendTelegramMessage(...)
```

Failure mode: Telegram send failures are logged with `error_log()` and do not block the business action. This is recommended for billing/router operations.

### m3. Group Chat Uses One Table And Router Scope

Confirmed:
```php
group_chat_messages(router_id, user_id, message, created_at, unsent_at)
```

Recommendation: add periodic frontend polling or WebSocket/SSE later if "live" needs to mean instant push instead of modal reload/poll behavior.

## Verification Performed

```bash
/Applications/XAMPP/xamppfiles/bin/php -n -l config/config.php
/Applications/XAMPP/xamppfiles/bin/php -n -l includes/functions.php
/Applications/XAMPP/xamppfiles/bin/php -n -l api/subscriber_action.php
/Applications/XAMPP/xamppfiles/bin/php -n -l api/send_notification.php
/Applications/XAMPP/xamppfiles/bin/php -n -l modules/subscribers/add.php
/Applications/XAMPP/xamppfiles/bin/php -n -l modules/subscribers/edit.php
/Applications/XAMPP/xamppfiles/bin/php -n -l modules/subscribers/view.php
/Applications/XAMPP/xamppfiles/bin/php -n -l modules/notifications/index.php
/Applications/XAMPP/xamppfiles/bin/php -n -l auth/forgot-password.php
/Applications/XAMPP/xamppfiles/bin/php -n -l auth/reset-password.php
```

Result: no syntax errors detected in changed files.
