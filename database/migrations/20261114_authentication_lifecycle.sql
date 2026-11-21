-- Authentication lifecycle additions for password reset, email verification,
-- administrator email MFA, and revocable browser sessions.
-- Back up `kalakal` before running. This migration is safe to repeat.
USE kalakal;

SET @has_last_used_at = (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'user_sessions'
       AND column_name = 'last_used_at'
);
SET @migration_sql = IF(
    @has_last_used_at = 0,
    'ALTER TABLE user_sessions ADD COLUMN last_used_at DATETIME NULL AFTER created_at',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE user_sessions
   SET last_used_at = created_at
 WHERE last_used_at IS NULL;

-- Accounts created before verification was added were already allowed to use
-- the application. Treat those existing addresses as verified on upgrade.
UPDATE users
   SET email_verified_at = created_at
 WHERE email_verified_at IS NULL
   AND created_at IS NOT NULL;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    verification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    requested_ip    VARCHAR(45) NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (verification_id),
    UNIQUE KEY uq_email_verification_token (token_hash),
    KEY idx_email_verification_user (user_id, created_at),
    KEY idx_email_verification_expires (expires_at),
    CONSTRAINT fk_email_verification_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_verification_ip = (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'email_verification_tokens'
       AND column_name = 'requested_ip'
);
SET @migration_sql = IF(
    @has_verification_ip = 0,
    'ALTER TABLE email_verification_tokens ADD COLUMN requested_ip VARCHAR(45) NULL AFTER token_hash',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS admin_mfa_challenges (
    challenge_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    challenge_hash     CHAR(64) NOT NULL,
    code_hash          VARCHAR(255) NOT NULL,
    requested_ip       VARCHAR(45) NULL,
    failed_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at         DATETIME NOT NULL,
    consumed_at        DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (challenge_id),
    UNIQUE KEY uq_admin_mfa_challenge (challenge_hash),
    KEY idx_admin_mfa_user_expiry (user_id, expires_at),
    CONSTRAINT fk_admin_mfa_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_email_attempts (
    attempt_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type   ENUM('password_reset','verification') NOT NULL,
    email_hash   CHAR(64) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_id),
    KEY idx_auth_email_ip_time (event_type, ip_address, attempted_at),
    KEY idx_auth_email_hash_time (event_type, email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
