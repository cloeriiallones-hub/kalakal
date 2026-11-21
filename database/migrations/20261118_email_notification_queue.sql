-- Add/reconcile the SMTP notification queue for an existing installation.
-- Safe to repeat and preserves notification history.
USE kalakal;

SET @has_email_status = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND column_name = 'email_status'
);
SET @migration_sql = IF(
    @has_email_status = 0,
    'ALTER TABLE notifications ADD COLUMN email_status ENUM(''pending'',''sending'',''sent'',''failed'',''skipped'') NOT NULL DEFAULT ''pending'' AFTER read_at',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_email_attempts = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND column_name = 'email_attempts'
);
SET @migration_sql = IF(
    @has_email_attempts = 0,
    'ALTER TABLE notifications ADD COLUMN email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_status',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_email_batch = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND column_name = 'email_batch'
);
SET @migration_sql = IF(
    @has_email_batch = 0,
    'ALTER TABLE notifications ADD COLUMN email_batch CHAR(36) NULL AFTER email_attempts',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_email_claimed_at = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND column_name = 'email_claimed_at'
);
SET @migration_sql = IF(
    @has_email_claimed_at = 0,
    'ALTER TABLE notifications ADD COLUMN email_claimed_at DATETIME NULL AFTER email_batch',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_email_sent_at = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND column_name = 'email_sent_at'
);
SET @migration_sql = IF(
    @has_email_sent_at = 0,
    'ALTER TABLE notifications ADD COLUMN email_sent_at DATETIME NULL AFTER email_claimed_at',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_email_error = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND column_name = 'email_error'
);
SET @migration_sql = IF(
    @has_email_error = 0,
    'ALTER TABLE notifications ADD COLUMN email_error VARCHAR(255) NULL AFTER email_sent_at',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_email_queue_index = (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND index_name = 'idx_notifications_email_queue'
);
SET @migration_sql = IF(
    @has_email_queue_index = 0,
    'CREATE INDEX idx_notifications_email_queue ON notifications (email_status, notification_id)',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_email_batch_index = (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'notifications' AND index_name = 'idx_notifications_email_batch'
);
SET @migration_sql = IF(
    @has_email_batch_index = 0,
    'CREATE INDEX idx_notifications_email_batch ON notifications (email_batch)',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
    ('email_max_attempts', '3', 'Maximum SMTP delivery attempts per notification');

CREATE OR REPLACE VIEW v_email_outbox AS
SELECT n.notification_id,
       n.email_batch,
       n.notification_type,
       n.title,
       n.message,
       n.related_table,
       n.related_id,
       n.email_attempts,
       n.created_at,
       u.user_id,
       u.email AS to_email,
       u.first_name AS to_first_name
  FROM notifications n
  JOIN users u ON u.user_id = n.user_id
 WHERE n.email_status = 'sending';

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_claim_pending_emails$$
CREATE PROCEDURE sp_claim_pending_emails(IN p_limit INT, IN p_batch CHAR(36))
BEGIN
    DECLARE v_max INT DEFAULT 3;
    DECLARE v_limit INT DEFAULT 20;

    IF p_limit IS NULL OR p_limit < 1 OR p_batch IS NULL OR TRIM(p_batch) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid email batch request';
    END IF;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_max
      FROM system_settings WHERE setting_key = 'email_max_attempts';
    SET v_limit = LEAST(p_limit, 100);

    UPDATE notifications
       SET email_status = 'pending', email_batch = NULL, email_claimed_at = NULL
     WHERE email_status = 'sending'
       AND email_claimed_at < NOW() - INTERVAL 30 MINUTE;

    UPDATE notifications
       SET email_status = 'skipped', email_batch = NULL, email_claimed_at = NULL
     WHERE email_status = 'pending'
       AND NOT EXISTS (
           SELECT 1 FROM users u
            WHERE u.user_id = notifications.user_id
              AND u.is_active = 1
              AND u.deleted_at IS NULL
              AND u.email_notifications = 1
       );

    UPDATE notifications
       SET email_status = 'sending',
           email_batch = p_batch,
           email_claimed_at = NOW()
     WHERE email_status = 'pending'
       AND email_attempts < v_max
     ORDER BY notification_id
     LIMIT v_limit;

    SELECT ROW_COUNT() AS claimed;
END$$

DROP PROCEDURE IF EXISTS sp_mark_email_sent$$
CREATE PROCEDURE sp_mark_email_sent(IN p_notification_id INT UNSIGNED, IN p_batch CHAR(36))
BEGIN
    UPDATE notifications
       SET email_status = 'sent',
           email_attempts = email_attempts + 1,
           email_sent_at = NOW(),
           email_error = NULL,
           email_batch = NULL,
           email_claimed_at = NULL
     WHERE notification_id = p_notification_id
       AND email_status = 'sending'
       AND email_batch = p_batch;

    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email delivery claim expired or belongs to another batch';
    END IF;
END$$

DROP PROCEDURE IF EXISTS sp_mark_email_failed$$
CREATE PROCEDURE sp_mark_email_failed(
    IN p_notification_id INT UNSIGNED,
    IN p_batch CHAR(36),
    IN p_error VARCHAR(255)
)
BEGIN
    DECLARE v_max INT DEFAULT 3;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_max
      FROM system_settings WHERE setting_key = 'email_max_attempts';

    UPDATE notifications
       SET email_status = IF(email_attempts + 1 >= v_max, 'failed', 'pending'),
           email_attempts = email_attempts + 1,
           email_error = LEFT(p_error, 255),
           email_batch = NULL,
           email_claimed_at = NULL
     WHERE notification_id = p_notification_id
       AND email_status = 'sending'
       AND email_batch = p_batch;

    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email delivery claim expired or belongs to another batch';
    END IF;
END$$

DELIMITER ;
