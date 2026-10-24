-- ======================================================================
-- WPMS Kenya v2.15 — The platform's own silence, reported (tbljob_heartbeats)
-- ======================================================================
-- Idempotent — safe to re-run:
--   mysql -u root dbwedding < migrations/v2.15_job_heartbeats.sql
--
-- Why this exists:
--   Five scheduled jobs keep the platform's promises to people who are not
--   watching:
--
--     scripts/mail_retry.php              a queued email actually leaves
--     scripts/notify_prune.php            the alert archive does not grow forever
--     scripts/subscription_renewals.php   a planner is prompted, dunned or
--                                         nurtured before their plan lapses
--     scripts/rsvp_reminders.php          invitations still unanswered are
--                                         chased before the wedding, not after
--     scripts/deadline_sms.php            a balance is collected before the day,
--                                         not discovered on it
--
--   All five are silent when they stop. A dead pinger, a renamed cron entry,
--   a PHP upgrade that breaks the CLI binary or a lock left behind all produce
--   the same observable result: nothing. The failure is only discovered when a
--   planner complains an invoice email never arrived, by which time the whole
--   renewal window is gone. Every one of the jobs already PRINTS what it
--   did for whoever runs it — and nobody is standing there.
--
-- What it holds:
--   ONE ROW PER JOB the platform still runs — not one row per run. A heartbeat,
--   not a history: `last_finished_at` is what the watchdog ages, `last_items`
--   is how many rows the job actually acted on, `last_queue` is how much work
--   that run's own window held (`items = 0` while `queue > 0` is the "nothing
--   to show for its window" signal — reported by the JOB, because it is the
--   only thing that knows its own selection), and `last_status` is whether the
--   job did what it was configured to do ('ok'), deliberately did nothing
--   because its transport/legs are off ('noop'), or died ('failed').
--
--   `alert_reason` + `alerted_at` are the operator-noise damper: the audit
--   tells the operator about a (job, reason) once and then stays quiet until
--   the cooldown has passed, so a job that has been silent for three days
--   produces one bell per half-day, not one per audit. When the job recovers
--   the pair is cleared, so the NEXT silence is news again. Those two columns
--   are the only reason a bounded table can carry an unbounded incident.
--
-- Why a table rather than the cron bridge's own state file
--   (.cron_bridge_state.json): the bridge only knows about the runs it started.
--   A crontab entry, a manual `php scripts/mail_retry.php`, a job that failed
--   before the bridge could write its line — and the bridge has no idea they
--   happened, or that they stopped. The heartbeat is written by the JOB, so it
--   is the same fact whatever started it, and the watchdog reads it from PHP
--   and from the operator's own page without needing shell access to the host.
--
-- Indexes: the unique key is the job's identity (and the upsert's concurrency
-- control), `last_finished_at` is the index the watchdog's staleness read walks.
-- There is nothing to prune — the registry is fixed and the table holds one row
-- per job the code still runs.
--
-- Verified after migration:
--   SHOW COLUMNS FROM tbljob_heartbeats;
--   SELECT job, last_status, last_items, TIMESTAMPDIFF(SECOND, last_finished_at, NOW()) AS age FROM tbljob_heartbeats;
--   php scripts/job_watch.php            -- exit 0 = every job reported recently
--   php scripts/lint.php admin && composer code-audit
--   vendor/bin/phpunit tests/JobWatchTest.php
-- ======================================================================

CREATE DATABASE IF NOT EXISTS `dbwedding` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbwedding`;

-- ----------------------------------------------------------------------
-- 1. The heartbeat table. MariaDB syntax (project convention): on stock
--    MySQL run this CREATE once by hand — re-running it is what IF NOT
--    EXISTS guards.
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbljob_heartbeats` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `job`              varchar(48)   NOT NULL COMMENT 'registry key in include/JobWatch.php, e.g. mail_retry',
  `label`            varchar(96)   NOT NULL DEFAULT '' COMMENT 'the human name the operator reads',
  `last_started_at`  datetime      DEFAULT NULL COMMENT 'when the last run began; NULL = the job did not say',
  `last_finished_at` datetime      DEFAULT NULL COMMENT 'the stamp the watchdog ages — NULL = no run recorded',
  `last_status`      varchar(16)   NOT NULL DEFAULT '' COMMENT 'ok | noop | failed — what the job said it did',
  `last_exit_code`   int(11)       NOT NULL DEFAULT 0 COMMENT 'the process exit code, 0 on a clean run',
  `last_items`       int(11)       NOT NULL DEFAULT 0 COMMENT 'rows/prompts/emails the run actually acted on',
  `last_queue`       int(11)       NOT NULL DEFAULT -1 COMMENT 'how much work that window held; -1 = the job reported no queue',
  `last_summary`     varchar(255)  NOT NULL DEFAULT '' COMMENT 'the job''s own one-line report, trimmed',
  `runs`             int(11)       NOT NULL DEFAULT 0 COMMENT 'heartbeats recorded — a counter, not a log',
  `fail_streak`      int(11)       NOT NULL DEFAULT 0 COMMENT 'consecutive failed runs, reset by any other outcome',
  `alert_reason`     varchar(24)   NOT NULL DEFAULT '' COMMENT 'the finding the operator was last told about',
  `alerted_at`       datetime      DEFAULT NULL COMMENT 'when that alert was sent — the cooldown clock',
  `first_seen_at`    datetime      NOT NULL DEFAULT current_timestamp() COMMENT 'the first heartbeat ever recorded',
  `updated_at`       datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_job_heartbeat` (`job`),
  KEY `idx_job_finished` (`last_finished_at`),
  KEY `idx_job_alerted` (`alerted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='v2.15 per-job heartbeat: what each scheduled job last did, and what the operator was told';

-- ----------------------------------------------------------------------
-- 2. Upgrade path. The queue column arrived with the five-job registry: a dev
--    or beta database that ran an earlier draft of this file already has the
--    table, so CREATE ... IF NOT EXISTS would quietly leave it without the
--    column the report reads. Idempotent either way.
-- ----------------------------------------------------------------------
SET @add_queue := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `tbljob_heartbeats`
       ADD COLUMN `last_queue` int(11) NOT NULL DEFAULT -1
       COMMENT ''how much work that window held; -1 = the job reported no queue''
       AFTER `last_items`',
    'SELECT ''last_queue already present'' AS check_name')
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'tbljob_heartbeats'
   AND COLUMN_NAME = 'last_queue'
);
PREPARE stmt FROM @add_queue;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------
-- Verification — expected: 4 (PRIMARY, uq_job_heartbeat, idx_job_finished,
-- idx_job_alerted), and on a healthy install every running job has a row
-- younger than its own interval.
-- ----------------------------------------------------------------------
SELECT 'indexes on tbljob_heartbeats' AS check_name, COUNT(DISTINCT INDEX_NAME) AS n
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbljob_heartbeats';
