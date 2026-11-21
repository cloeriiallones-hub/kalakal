ALTER TABLE users
    ADD COLUMN deletion_requested_at DATETIME NULL AFTER deleted_at,
    ADD KEY idx_users_deletion_requested (deletion_requested_at);
