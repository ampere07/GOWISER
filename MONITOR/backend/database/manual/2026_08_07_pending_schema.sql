-- ============================================================================
--  MONITOR — pending schema changes as of 2026-08-07
-- ============================================================================
--
--  RUN THIS AGAINST *MONITOR'S OWN* DATABASE.
--
--  Not against gowiser_sync_db1. That is a monitored source: MONITOR only ever
--  reads it, the connection is forced read-only in SourceRegistry::connection(),
--  and nothing in this file belongs there. If the database you are connected to
--  has a `billing_accounts` table, you are in the wrong one — stop.
--
--  The equivalent and preferred command is:
--
--      php artisan migrate
--
--  This file exists for deployments where artisan cannot reach the database
--  (shared hosting, a DBA-gated schema). It is exactly what those three
--  migrations emit, plus the `migrations` rows so artisan does not try to run
--  them again afterwards.
--
--  Idempotent by construction: every statement checks for its own object first,
--  so running this twice is a no-op rather than an error. MySQL 5.7+ / 8.0+.
--
--  ---------------------------------------------------------------------------
--  1. radius_config          — RADIUS endpoints, editable in Settings
--  2. mikrotik_kick_queue    — `mode` + `scheduled_timezone` for GMT+8 kicks
--  3. users.preferences      — per-user auto-refresh intervals
--  ---------------------------------------------------------------------------

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. radius_config
--
-- Ported from GOWISER's table of the same name, so an operator configuring both
-- systems fills in one familiar form twice. `password` is a TEXT rather than a
-- VARCHAR because the application encrypts it before writing — the column holds
-- ciphertext, which is several times the length of the secret.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `radius_config` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ssl_type`   VARCHAR(8)      NOT NULL DEFAULT 'https',
    `ip`         VARCHAR(255)    NOT NULL,
    `port`       VARCHAR(8)      NOT NULL DEFAULT '443',
    `username`   VARCHAR(255)    NOT NULL,
    `password`   TEXT            NOT NULL,
    `label`      VARCHAR(255)        NULL,
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
    `updated_by` VARCHAR(255)        NULL,
    `created_at` TIMESTAMP           NULL,
    `updated_at` TIMESTAMP           NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. mikrotik_kick_queue: how a queued kick decides it is due
--
-- `window` waits for the maintenance window; `at` fires at a wall-clock time the
-- operator named in Asia/Manila. Without this column the drain command treats a
-- kick scheduled for 2pm as a window kick and holds it until 1am, which is the
-- opposite of what naming a time means.
--
-- Existing rows default to `window`, which is what every one of them was.
--
-- ADD COLUMN IF NOT EXISTS is MySQL 8.0.29+; the guarded blocks below work on
-- 5.7 too.
-- ---------------------------------------------------------------------------

SET @schema := DATABASE();

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `mikrotik_kick_queue` ADD COLUMN `mode` VARCHAR(16) NOT NULL DEFAULT ''window'' AFTER `status`',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME   = 'mikrotik_kick_queue'
      AND COLUMN_NAME  = 'mode'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The zone the operator typed against. Stored beside the absolute timestamp so
-- a row can be read back years later without assuming the server's timezone
-- never changed.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `mikrotik_kick_queue` ADD COLUMN `scheduled_timezone` VARCHAR(64) NULL AFTER `scheduled_for`',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME   = 'mikrotik_kick_queue'
      AND COLUMN_NAME  = 'scheduled_timezone'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The scheduler's only query is "due, in this mode, pending", every minute,
-- against a table that only grows.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `mikrotik_kick_queue` ADD INDEX `kick_mode_due_index` (`mode`, `status`, `scheduled_for`)',
        'DO 0'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME   = 'mikrotik_kick_queue'
      AND INDEX_NAME   = 'kick_mode_due_index'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. users.preferences
--
-- Per-user auto-refresh intervals for the Group Overview and MikroTik screens.
-- Nullable rather than defaulted: "has never chosen" and "chose the defaults"
-- are different, and only the first should follow a future change to what the
-- defaults are.
--
-- THIS ONE IS LOAD-BEARING. Without it, saving a refresh interval returns a 500.
-- ---------------------------------------------------------------------------

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `users` ADD COLUMN `preferences` JSON NULL AFTER `permission_overrides`',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'preferences'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Tell artisan these have run
--
-- Otherwise the next `php artisan migrate` tries to create radius_config again
-- and fails the whole batch. The batch number continues from whatever is there.
-- ---------------------------------------------------------------------------

INSERT INTO `migrations` (`migration`, `batch`)
SELECT v.migration, COALESCE((SELECT MAX(batch) FROM `migrations` m2), 0) + 1
FROM (
    SELECT '2026_01_01_000014_create_radius_config_table'        AS migration
    UNION ALL SELECT '2026_01_01_000015_add_mode_to_mikrotik_kick_queue'
    UNION ALL SELECT '2026_01_01_000016_add_preferences_to_users_table'
) AS v
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` m WHERE m.migration = v.migration
);

COMMIT;

-- ---------------------------------------------------------------------------
-- Verify
-- ---------------------------------------------------------------------------
--
-- SELECT COUNT(*) AS radius_config_exists
--   FROM information_schema.TABLES
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'radius_config';
--
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
--   FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND ((TABLE_NAME = 'mikrotik_kick_queue' AND COLUMN_NAME IN ('mode','scheduled_timezone'))
--      OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'preferences'));
--
-- Expect: radius_config_exists = 1, and three column rows.
--
-- Nothing here touches a monitored database. If you ran this against
-- gowiser_sync_db1 by mistake, it created a `radius_config` table and inserted
-- into `migrations` there; both are additive and safe to drop, and no existing
-- data was modified.
