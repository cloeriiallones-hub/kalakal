-- Message pagination support and private administrator access audit.
-- Safe to repeat; existing messages and threads are preserved.
USE kalakal;

SET @has_message_page_index = (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'messages'
       AND index_name = 'idx_messages_thread_id'
);
SET @migration_sql = IF(
    @has_message_page_index = 0,
    'CREATE INDEX idx_messages_thread_id ON messages (thread_id, message_id)',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS admin_message_access_logs (
    access_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id      INT UNSIGNED NOT NULL,
    thread_id     BIGINT UNSIGNED NOT NULL,
    action        ENUM('opened','sent') NOT NULL,
    ip_address    VARCHAR(45) NULL,
    accessed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (access_log_id),
    KEY idx_admin_message_access_admin_time (admin_id, accessed_at),
    KEY idx_admin_message_access_thread_time (thread_id, accessed_at),
    CONSTRAINT fk_admin_message_access_admin
        FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_admin_message_access_thread
        FOREIGN KEY (thread_id) REFERENCES message_threads(thread_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
