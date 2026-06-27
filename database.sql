-- ============================================================
-- Animal Bite Center (ABC) Queue Notification System v2
-- Simple queue: receptionist assigns, TV+speaker notifies
-- Compatible with: MySQL 5.7+ / MariaDB (XAMPP)
-- ============================================================

CREATE DATABASE IF NOT EXISTS abc_queue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE abc_queue;

-- ============================================================
-- Table: patients
-- Each row = one patient visit today
-- ============================================================
CREATE TABLE IF NOT EXISTS patients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number   VARCHAR(10)     NOT NULL,          -- P001, R001, F001
    patient_type    ENUM('priority','regular','followup') NOT NULL,
    patient_name    VARCHAR(100)    NULL,               -- optional, receptionist may leave blank
    status          ENUM('waiting','in_progress','done','skipped') NOT NULL DEFAULT 'waiting',
    current_step    ENUM(
                      'waiting',
                      'triage',
                      'itr_vitals',
                      'yellow_chair',
                      'blue_chair',
                      'doctor',
                      'encoder',
                      'vaccination',
                      'done'
                    ) NOT NULL DEFAULT 'waiting',
    step_started_at TIMESTAMP       NULL,              -- when current step started (for 20min timer)
    called_at       TIMESTAMP       NULL,              -- when TV/speaker called them
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes           TEXT            NULL,
    date_visit      DATE            NOT NULL DEFAULT (CURDATE())
) ENGINE=InnoDB;

-- ============================================================
-- Table: queue_counters
-- Per-type counter resets each day
-- ============================================================
CREATE TABLE IF NOT EXISTS queue_counters (
    prefix          CHAR(1)         NOT NULL,
    date_active     DATE            NOT NULL DEFAULT (CURDATE()),
    current_count   INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (prefix, date_active)
) ENGINE=InnoDB;

INSERT IGNORE INTO queue_counters (prefix, date_active, current_count)
VALUES ('P', CURDATE(), 0), ('R', CURDATE(), 0), ('F', CURDATE(), 0);

-- ============================================================
-- Table: now_calling
-- Single-row: what the TV is currently showing
-- ============================================================
CREATE TABLE IF NOT EXISTS now_calling (
    id              INT             NOT NULL DEFAULT 1,
    ticket_number   VARCHAR(10)     NULL,
    patient_type    VARCHAR(20)     NULL,
    current_step    VARCHAR(30)     NULL,
    step_label      VARCHAR(80)     NULL,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

INSERT IGNORE INTO now_calling (id) VALUES (1);

-- ============================================================
-- Table: call_log
-- Full audit trail
-- ============================================================
CREATE TABLE IF NOT EXISTS call_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number   VARCHAR(10)     NOT NULL,
    action          VARCHAR(40)     NOT NULL,  -- registered, called, step_advanced, done, skipped, timer_warned, timer_expired
    step            VARCHAR(30)     NULL,
    performed_by    VARCHAR(30)     NOT NULL DEFAULT 'system',
    performed_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Stored procedure: reset_daily
-- ============================================================
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS reset_daily()
BEGIN
    UPDATE patients SET status = 'done' WHERE status IN ('waiting','in_progress') AND date_visit = CURDATE();
    UPDATE now_calling SET ticket_number = NULL, patient_type = NULL, current_step = NULL, step_label = NULL WHERE id = 1;
    INSERT INTO queue_counters (prefix, date_active, current_count)
        VALUES ('P', CURDATE(), 0), ('R', CURDATE(), 0), ('F', CURDATE(), 0)
        ON DUPLICATE KEY UPDATE current_count = 0;
END$$
DELIMITER ;
