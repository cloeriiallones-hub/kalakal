-- KALAKAL: idempotent request creation, collection payment tracking, and guarded transitions.
-- Back up the database before applying. This migration preserves existing records.
USE kalakal;

SET @has_submission_token = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'collection_requests'
       AND column_name = 'submission_token'
);
SET @migration_sql = IF(
    @has_submission_token = 0,
    'ALTER TABLE collection_requests ADD COLUMN submission_token CHAR(64) NULL AFTER seller_id',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @has_submission_index = (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'collection_requests'
       AND index_name = 'uq_collection_requests_submission_token'
);
SET @migration_sql = IF(
    @has_submission_index = 0,
    'ALTER TABLE collection_requests ADD UNIQUE KEY uq_collection_requests_submission_token (submission_token)',
    'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS collection_payments (
    payment_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id    INT UNSIGNED NOT NULL,
    reported_by      INT UNSIGNED NOT NULL,
    confirmed_by     INT UNSIGNED NULL,
    idempotency_key  CHAR(64) NOT NULL,
    amount_paid      DECIMAL(12,2) NOT NULL,
    payment_method   ENUM('cash','gcash','maya') NOT NULL,
    payment_status   ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    reference_number VARCHAR(100) NULL,
    confirmed_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_collection_payment_idempotency (idempotency_key),
    KEY idx_collection_payment_status (collection_id, payment_status),
    CONSTRAINT fk_collection_payment_collection
        FOREIGN KEY (collection_id) REFERENCES collections(collection_id) ON DELETE RESTRICT,
    CONSTRAINT fk_collection_payment_reporter
        FOREIGN KEY (reported_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_collection_payment_confirmer
        FOREIGN KEY (confirmed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_collection_payment_amount CHECK (amount_paid > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_collection_payment_status AS
SELECT c.collection_id,
       c.request_id,
       COALESCE(t.total_due, 0.00) AS total_due,
       COALESCE(p.paid_total, 0.00) AS paid_total,
       COALESCE(p.pending_total, 0.00) AS pending_total,
       CASE
           WHEN COALESCE(t.total_due, 0.00) > 0
                AND COALESCE(p.paid_total, 0.00) >= t.total_due THEN 'paid'
           WHEN COALESCE(p.pending_total, 0.00) > 0 THEN 'pending'
           WHEN COALESCE(p.paid_total, 0.00) > 0 THEN 'partial'
           ELSE 'unpaid'
       END AS payment_status
  FROM collections c
  LEFT JOIN (
      SELECT collection_id, SUM(line_total) AS total_due
        FROM collection_items
       GROUP BY collection_id
  ) t ON t.collection_id = c.collection_id
  LEFT JOIN (
      SELECT collection_id,
             SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END) AS paid_total,
             SUM(CASE WHEN payment_status = 'pending' THEN amount_paid ELSE 0 END) AS pending_total
        FROM collection_payments
       GROUP BY collection_id
  ) p ON p.collection_id = c.collection_id;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_requests_bu$$
CREATE TRIGGER trg_requests_bu BEFORE UPDATE ON collection_requests
FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30);

    IF OLD.request_status IN ('completed','cancelled','rejected')
       AND NEW.request_status <> OLD.request_status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finished requests cannot be reopened';
    END IF;

    IF NOT (OLD.request_status <=> NEW.request_status)
       AND NOT (
           (OLD.request_status = 'pending'
             AND NEW.request_status IN ('buyer_proposed','accepted','cancelled','rejected'))
        OR (OLD.request_status = 'buyer_proposed'
             AND NEW.request_status IN ('pending','accepted','cancelled','rejected'))
        OR (OLD.request_status = 'accepted'
             AND NEW.request_status IN ('pending','awaiting_approval','cancelled','rejected'))
        OR (OLD.request_status = 'awaiting_approval'
             AND NEW.request_status IN ('disputed','completed','cancelled','rejected'))
        OR (OLD.request_status = 'disputed'
             AND NEW.request_status IN ('awaiting_approval','cancelled','rejected'))
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid collection request status transition';
    END IF;

    IF NEW.request_status = 'buyer_proposed' AND NEW.accepted_buyer_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A buyer proposal requires a buyer';
    END IF;
    IF NEW.request_status IN ('accepted','awaiting_approval','disputed','completed')
       AND NEW.assigned_collector_id IS NULL AND NEW.accepted_buyer_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This status requires an accepting buyer';
    END IF;

    IF NEW.assigned_collector_id IS NOT NULL
       AND NOT (OLD.assigned_collector_id <=> NEW.assigned_collector_id) THEN
        SELECT user_role INTO v_role FROM users WHERE user_id = NEW.assigned_collector_id;
        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assigned account must be a buyer';
        END IF;
    END IF;

    IF NEW.accepted_buyer_id IS NOT NULL
       AND NOT (OLD.accepted_buyer_id <=> NEW.accepted_buyer_id) THEN
        SELECT user_role INTO v_role FROM users WHERE user_id = NEW.accepted_buyer_id;
        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accepting user must be a junkshop';
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_requests_au$$
CREATE TRIGGER trg_requests_au AFTER UPDATE ON collection_requests
FOR EACH ROW
BEGIN
    IF NOT (OLD.request_status <=> NEW.request_status) THEN
        INSERT INTO status_history
            (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES ('collection_request', NEW.request_id,
                OLD.request_status, NEW.request_status, @app_user_id);

        IF NEW.request_status = 'cancelled' THEN
            IF OLD.accepted_buyer_id IS NOT NULL THEN
                IF NOT (OLD.accepted_buyer_id <=> @app_user_id) THEN
                    INSERT INTO notifications
                        (user_id, notification_type, title, message, related_table, related_id)
                    VALUES (OLD.accepted_buyer_id, 'request_cancelled', 'Collection request cancelled',
                            CONCAT('The waste owner cancelled pickup request #', NEW.request_id,
                                   ' that was scheduled for ',
                                   DATE_FORMAT(IFNULL(OLD.confirmed_schedule, OLD.preferred_schedule),
                                               '%b %e, %Y at %l:%i %p'), '.'),
                            'collection_requests', NEW.request_id);
                END IF;
            ELSEIF OLD.assigned_collector_id IS NOT NULL THEN
                INSERT INTO notifications
                    (user_id, notification_type, title, message, related_table, related_id)
                VALUES (OLD.assigned_collector_id, 'request_cancelled', 'Pickup cancelled',
                        CONCAT('The seller cancelled the pickup scheduled for ',
                               DATE_FORMAT(IFNULL(OLD.confirmed_schedule, OLD.preferred_schedule),
                                           '%b %e, %Y at %l:%i %p'), '.'),
                        'collection_requests', NEW.request_id);
            ELSE
                INSERT INTO notifications
                    (user_id, notification_type, title, message, related_table, related_id)
                SELECT bp.user_id, 'request_cancelled', 'Collection request cancelled',
                       CONCAT('Pickup request #', NEW.request_id, ' in ', NEW.barangay, ', ', NEW.city,
                              ' for ', DATE_FORMAT(OLD.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                              ' was cancelled by the waste owner.'),
                       'collection_requests', NEW.request_id
                  FROM buyer_profiles bp
                  JOIN users u ON u.user_id = bp.user_id
                 WHERE OLD.preferred_buyer_id = bp.user_id
                   AND u.is_active = 1 AND u.deleted_at IS NULL;

                INSERT INTO notifications
                    (user_id, notification_type, title, message, related_table, related_id)
                SELECT cp.user_id, 'request_cancelled', 'Pickup request cancelled',
                       CONCAT('A pickup request in ', NEW.barangay, ', ', NEW.city,
                              ' for ', DATE_FORMAT(OLD.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                              ' was cancelled by the seller.'),
                       'collection_requests', NEW.request_id
                  FROM collector_profiles cp
                  JOIN users u ON u.user_id = cp.user_id
                 WHERE OLD.preferred_buyer_id IS NULL
                   AND cp.verification_status = 'verified'
                   AND cp.is_available = 1
                   AND u.is_active = 1
                   AND u.deleted_at IS NULL
                   AND LOWER(TRIM(cp.service_area)) =
                       LOWER(CONCAT(TRIM(NEW.barangay), ', ', TRIM(NEW.city)));

                INSERT INTO notifications
                    (user_id, notification_type, title, message, related_table, related_id)
                SELECT bp.user_id, 'request_cancelled', 'Collection request cancelled',
                       CONCAT('A collection request in ', NEW.barangay, ', ', NEW.city,
                              ' for ', DATE_FORMAT(OLD.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                              ' was cancelled by the waste owner.'),
                       'collection_requests', NEW.request_id
                  FROM buyer_profiles bp
                  JOIN users u ON u.user_id = bp.user_id
                 WHERE OLD.preferred_buyer_id IS NULL
                   AND u.is_active = 1
                   AND u.deleted_at IS NULL
                   AND bp.city IS NOT NULL
                   AND LOWER(TRIM(bp.city)) = LOWER(TRIM(NEW.city));
            END IF;
        END IF;

        IF NEW.request_status = 'rejected' THEN
            INSERT INTO notifications
                (user_id, notification_type, title, message, related_table, related_id)
            VALUES (NEW.seller_id, 'request_rejected', 'Pickup request rejected',
                    'Your pickup request was rejected. Please check the details and submit a new request.',
                    'collection_requests', NEW.request_id);
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_collections_bu$$
CREATE TRIGGER trg_collections_bu BEFORE UPDATE ON collections
FOR EACH ROW
BEGIN
    IF OLD.collection_status IN ('completed','cancelled')
       AND NEW.collection_status <> OLD.collection_status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finished collections cannot be reopened';
    END IF;

    IF NOT (OLD.collection_status <=> NEW.collection_status)
       AND NOT (
           (OLD.collection_status = 'pending'
             AND NEW.collection_status IN ('in_progress','awaiting_seller_approval','cancelled'))
        OR (OLD.collection_status = 'in_progress'
             AND NEW.collection_status IN ('awaiting_seller_approval','cancelled'))
        OR (OLD.collection_status = 'awaiting_seller_approval'
             AND NEW.collection_status IN ('completed','disputed','cancelled'))
        OR (OLD.collection_status = 'disputed'
             AND NEW.collection_status IN ('awaiting_seller_approval','cancelled'))
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid collection status transition';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_collection_payments_bi$$
CREATE TRIGGER trg_collection_payments_bi BEFORE INSERT ON collection_payments
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_request_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_buyer INT UNSIGNED DEFAULT NULL;
    DECLARE v_method VARCHAR(20) DEFAULT NULL;
    DECLARE v_role VARCHAR(30) DEFAULT NULL;
    DECLARE v_active TINYINT DEFAULT 0;
    DECLARE v_deleted DATETIME DEFAULT NULL;

    SELECT c.collection_status, r.request_status, r.accepted_buyer_id,
           r.buyer_payment_method, u.user_role, u.is_active, u.deleted_at
      INTO v_status, v_request_status, v_buyer, v_method, v_role, v_active, v_deleted
      FROM collections c
      JOIN collection_requests r ON r.request_id = c.request_id
      JOIN users u ON u.user_id = NEW.reported_by
     WHERE c.collection_id = NEW.collection_id;

    IF v_status IS NULL OR v_status <> 'completed' OR v_request_status <> 'completed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment can only be recorded for a completed collection';
    END IF;
    IF NEW.reported_by <> v_buyer OR v_role <> 'buyer' OR v_active <> 1 OR v_deleted IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the active junkshop for this collection can report payment';
    END IF;
    IF NEW.payment_status <> 'pending' OR NEW.confirmed_by IS NOT NULL OR NEW.confirmed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New payment reports must await seller confirmation';
    END IF;
    IF v_method IS NULL OR NEW.payment_method <> v_method THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method must match the agreed collection method';
    END IF;
    IF NEW.idempotency_key NOT REGEXP '^[a-f0-9]{64}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payment submission key';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_collection_payments_bu$$
CREATE TRIGGER trg_collection_payments_bu BEFORE UPDATE ON collection_payments
FOR EACH ROW
BEGIN
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;

    IF OLD.payment_status <> 'pending'
       OR NEW.payment_status NOT IN ('paid','failed')
       OR NEW.collection_id <> OLD.collection_id
       OR NEW.reported_by <> OLD.reported_by
       OR NEW.idempotency_key <> OLD.idempotency_key
       OR NEW.amount_paid <> OLD.amount_paid
       OR NEW.payment_method <> OLD.payment_method
       OR NOT (NEW.reference_number <=> OLD.reference_number) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid collection payment update';
    END IF;

    SELECT r.seller_id INTO v_seller
      FROM collections c
      JOIN collection_requests r ON r.request_id = c.request_id
     WHERE c.collection_id = OLD.collection_id;
    IF NEW.confirmed_by IS NULL OR NEW.confirmed_by <> v_seller THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the waste owner can confirm payment';
    END IF;
    IF NEW.confirmed_at IS NULL THEN
        SET NEW.confirmed_at = NOW();
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_collection_payments_au$$
CREATE TRIGGER trg_collection_payments_au AFTER UPDATE ON collection_payments
FOR EACH ROW
BEGIN
    IF NOT (OLD.payment_status <=> NEW.payment_status) THEN
        INSERT INTO status_history
            (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES ('payment', NEW.payment_id, OLD.payment_status, NEW.payment_status, @app_user_id);
    END IF;
END$$

DROP PROCEDURE IF EXISTS sp_record_collection_payment$$
CREATE PROCEDURE sp_record_collection_payment(
    IN p_collection_id INT UNSIGNED,
    IN p_buyer_id INT UNSIGNED,
    IN p_amount DECIMAL(12,2),
    IN p_payment_method VARCHAR(20),
    IN p_reference_number VARCHAR(100),
    IN p_idempotency_key CHAR(64)
)
BEGIN
    DECLARE v_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_request_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_buyer_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_agreed_method VARCHAR(20) DEFAULT NULL;
    DECLARE v_total DECIMAL(12,2) DEFAULT 0.00;
    DECLARE v_paid DECIMAL(12,2) DEFAULT 0.00;
    DECLARE v_pending DECIMAL(12,2) DEFAULT 0.00;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_buyer_id;
    START TRANSACTION;
        SELECT c.collection_status, r.request_status, r.accepted_buyer_id, r.buyer_payment_method
          INTO v_status, v_request_status, v_buyer_id, v_agreed_method
          FROM collections c
          JOIN collection_requests r ON r.request_id = c.request_id
         WHERE c.collection_id = p_collection_id
         FOR UPDATE;

        IF v_status <> 'completed' OR v_request_status <> 'completed' OR v_buyer_id <> p_buyer_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This completed collection is not assigned to your junkshop';
        END IF;
        IF p_amount IS NULL OR p_amount <= 0 OR p_payment_method IS NULL
           OR v_agreed_method IS NULL OR p_payment_method <> v_agreed_method THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter a positive amount and the agreed payment method';
        END IF;
        IF p_idempotency_key IS NULL OR p_idempotency_key NOT REGEXP '^[a-f0-9]{64}$' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payment submission key';
        END IF;

        SELECT COALESCE(SUM(line_total), 0.00) INTO v_total
          FROM collection_items WHERE collection_id = p_collection_id;
        SELECT COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END), 0.00),
               COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN amount_paid ELSE 0 END), 0.00)
          INTO v_paid, v_pending
          FROM collection_payments WHERE collection_id = p_collection_id;

        IF v_total <= 0 OR v_pending > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A payment is already pending or the collection has no amount due';
        END IF;
        IF p_amount > v_total - v_paid THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the remaining amount due';
        END IF;

        INSERT INTO collection_payments
            (collection_id, reported_by, idempotency_key, amount_paid, payment_method, reference_number)
        VALUES (p_collection_id, p_buyer_id, p_idempotency_key, p_amount,
                p_payment_method, NULLIF(TRIM(p_reference_number), ''));
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_submit_actuals$$
CREATE PROCEDURE sp_submit_actuals(
    IN p_request_id INT UNSIGNED,
    IN p_collector_id INT UNSIGNED
)
BEGIN
    DECLARE v_collection_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;
    DECLARE v_items INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_collector_id;
    START TRANSACTION;
        SELECT c.collection_id, c.collection_status, r.seller_id
          INTO v_collection_id, v_status, v_seller
          FROM collections c
          JOIN collection_requests r ON r.request_id = c.request_id
         WHERE c.request_id = p_request_id
           AND c.collector_id = p_collector_id
         FOR UPDATE;

        IF v_collection_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No collection assigned to this junkshop';
        END IF;
        IF v_status NOT IN ('pending','in_progress','disputed') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Values were already submitted';
        END IF;

        SELECT COUNT(*) INTO v_items
          FROM collection_items WHERE collection_id = v_collection_id;
        IF v_items = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Record at least one item first';
        END IF;

        UPDATE collections
           SET collection_status = 'awaiting_seller_approval',
               confirmed_at = NOW(),
               collected_at = IFNULL(collected_at, NOW()),
               dispute_reason = NULL
         WHERE collection_id = v_collection_id;
        UPDATE collection_requests
           SET request_status = 'awaiting_approval'
         WHERE request_id = p_request_id;

        INSERT INTO notifications
            (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_seller, 'values_submitted', 'Please review the final amounts',
                'The junkshop entered the actual quantities and prices. Please approve or dispute them.',
                'collection_requests', p_request_id);
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_seller_decision$$
CREATE PROCEDURE sp_seller_decision(
    IN p_request_id INT UNSIGNED,
    IN p_seller_id INT UNSIGNED,
    IN p_approve TINYINT,
    IN p_reason VARCHAR(500)
)
BEGIN
    DECLARE v_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_buyer INT UNSIGNED DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_approve IS NULL OR p_approve NOT IN (0,1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose approve or dispute';
    END IF;
    IF p_approve = 0 AND (p_reason IS NULL OR TRIM(p_reason) = '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A dispute reason is required';
    END IF;

    SET @app_user_id = p_seller_id;
    START TRANSACTION;
        SELECT request_status, COALESCE(accepted_buyer_id, assigned_collector_id)
          INTO v_status, v_buyer
          FROM collection_requests
         WHERE request_id = p_request_id AND seller_id = p_seller_id
         FOR UPDATE;
        IF v_status IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request not found for this seller';
        END IF;
        IF v_status <> 'awaiting_approval' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nothing to approve for this request';
        END IF;
        IF v_buyer IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This request has no accepting junkshop';
        END IF;

        IF p_approve = 1 THEN
            UPDATE collections
               SET collection_status = 'completed',
                   collected_at = IFNULL(collected_at, NOW()),
                   seller_approved_at = NOW()
             WHERE request_id = p_request_id;
            UPDATE collection_requests
               SET request_status = 'completed'
             WHERE request_id = p_request_id;
            INSERT INTO notifications
                (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_buyer, 'values_approved', 'Seller approved the amounts',
                    'The seller approved the final quantities and prices.',
                    'collection_requests', p_request_id);
        ELSE
            UPDATE collections
               SET collection_status = 'disputed',
                   dispute_reason = p_reason
             WHERE request_id = p_request_id;
            UPDATE collection_requests
               SET request_status = 'disputed'
             WHERE request_id = p_request_id;
            INSERT INTO notifications
                (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_buyer, 'values_disputed', 'Seller disputed the amounts',
                    CONCAT('The seller disputed the final values: ', p_reason),
                    'collection_requests', p_request_id);
        END IF;
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_seller_buyer_proposal_decision$$
CREATE PROCEDURE sp_seller_buyer_proposal_decision(
    IN p_request_id INT UNSIGNED,
    IN p_seller_id INT UNSIGNED,
    IN p_approve TINYINT
)
BEGIN
    DECLARE v_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_buyer INT UNSIGNED DEFAULT NULL;
    DECLARE v_schedule DATETIME DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_approve IS NULL OR p_approve NOT IN (0,1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose approve or decline';
    END IF;

    SET @app_user_id = p_seller_id;
    START TRANSACTION;
        SELECT request_status, accepted_buyer_id, buyer_suggested_schedule
          INTO v_status, v_buyer, v_schedule
          FROM collection_requests
         WHERE request_id = p_request_id AND seller_id = p_seller_id
         FOR UPDATE;
        IF v_status <> 'buyer_proposed' OR v_buyer IS NULL OR v_schedule IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No buyer proposal is waiting for this seller';
        END IF;

        IF p_approve = 1 THEN
            UPDATE collection_requests
               SET confirmed_schedule = buyer_suggested_schedule,
                   buyer_suggested_schedule = NULL,
                   request_status = 'accepted'
             WHERE request_id = p_request_id;
            INSERT INTO collections (request_id, collector_id)
            VALUES (p_request_id, v_buyer);
            INSERT INTO notifications
                (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_buyer, 'proposal_approved', 'Collection proposal approved',
                    CONCAT('The seller approved your proposal for request #', p_request_id,
                           '. You can now record the collected materials.'),
                    'collection_requests', p_request_id);
        ELSE
            UPDATE collection_requests
               SET accepted_buyer_id = NULL,
                   assigned_at = NULL,
                   buyer_suggested_schedule = NULL,
                   buyer_payment_method = NULL,
                   request_status = 'pending'
             WHERE request_id = p_request_id;
            INSERT INTO notifications
                (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_buyer, 'proposal_declined', 'Collection proposal declined',
                    CONCAT('The seller declined your proposal for request #', p_request_id,
                           '. The request is open for another proposal.'),
                    'collection_requests', p_request_id);
        END IF;
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_buyer_request_reschedule$$
CREATE PROCEDURE sp_buyer_request_reschedule(
    IN p_request_id INT UNSIGNED,
    IN p_buyer_id INT UNSIGNED,
    IN p_new_schedule DATETIME
)
BEGIN
    DECLARE v_request_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_buyer INT UNSIGNED DEFAULT NULL;
    DECLARE v_confirmed_schedule DATETIME DEFAULT NULL;
    DECLARE v_suggested_schedule DATETIME DEFAULT NULL;
    DECLARE v_collection_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_collection_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_role VARCHAR(30) DEFAULT NULL;
    DECLARE v_active TINYINT DEFAULT 0;
    DECLARE v_items INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_new_schedule IS NULL OR p_new_schedule <= NOW() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a future pickup time';
    END IF;

    SET @app_user_id = p_buyer_id;
    START TRANSACTION;
        SELECT user_role, is_active INTO v_role, v_active
          FROM users WHERE user_id = p_buyer_id FOR UPDATE;
        SELECT r.request_status, r.accepted_buyer_id, r.confirmed_schedule,
               r.buyer_suggested_schedule, c.collection_id, c.collection_status
          INTO v_request_status, v_buyer, v_confirmed_schedule,
               v_suggested_schedule, v_collection_id, v_collection_status
          FROM collection_requests r
          JOIN collections c ON c.request_id = r.request_id
         WHERE r.request_id = p_request_id
         FOR UPDATE;

        IF v_role <> 'buyer' OR v_active <> 1 OR v_buyer <> p_buyer_id
           OR v_request_status <> 'accepted'
           OR v_collection_status NOT IN ('pending','in_progress') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This pickup cannot be rescheduled by your junkshop';
        END IF;
        IF v_suggested_schedule IS NOT NULL
           AND NOT (v_suggested_schedule <=> v_confirmed_schedule) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reschedule proposal is already awaiting the seller';
        END IF;
        SELECT COUNT(*) INTO v_items FROM collection_items WHERE collection_id = v_collection_id;
        IF v_items > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A pickup cannot be rescheduled after materials are recorded';
        END IF;
        IF p_new_schedule = v_confirmed_schedule THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a different pickup time';
        END IF;

        UPDATE collection_requests
           SET buyer_suggested_schedule = p_new_schedule
         WHERE request_id = p_request_id;
        INSERT INTO notifications
            (user_id, notification_type, title, message, related_table, related_id)
        SELECT seller_id, 'reschedule_proposed', 'Junkshop requested a new pickup time',
               CONCAT('The junkshop requested ', DATE_FORMAT(p_new_schedule, '%b %e, %Y at %l:%i %p'),
                      '. Approve or decline this change in your requests.'),
               'collection_requests', p_request_id
          FROM collection_requests WHERE request_id = p_request_id;
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_seller_reschedule_decision$$
CREATE PROCEDURE sp_seller_reschedule_decision(
    IN p_request_id INT UNSIGNED,
    IN p_seller_id INT UNSIGNED,
    IN p_approve TINYINT
)
BEGIN
    DECLARE v_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_buyer INT UNSIGNED DEFAULT NULL;
    DECLARE v_confirmed DATETIME DEFAULT NULL;
    DECLARE v_suggested DATETIME DEFAULT NULL;
    DECLARE v_collection_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_collection_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_items INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_approve IS NULL OR p_approve NOT IN (0,1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose approve or decline';
    END IF;

    SET @app_user_id = p_seller_id;
    START TRANSACTION;
        SELECT r.request_status, r.accepted_buyer_id, r.confirmed_schedule,
               r.buyer_suggested_schedule, c.collection_id, c.collection_status
          INTO v_status, v_buyer, v_confirmed, v_suggested, v_collection_id, v_collection_status
          FROM collection_requests r
          JOIN collections c ON c.request_id = r.request_id
         WHERE r.request_id = p_request_id AND r.seller_id = p_seller_id
         FOR UPDATE;
        IF v_status <> 'accepted' OR v_buyer IS NULL OR v_suggested IS NULL
           OR v_suggested = v_confirmed
           OR v_collection_status NOT IN ('pending','in_progress') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No reschedule proposal is waiting for this seller';
        END IF;
        SELECT COUNT(*) INTO v_items FROM collection_items WHERE collection_id = v_collection_id;
        IF v_items > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A pickup cannot be rescheduled after materials are recorded';
        END IF;

        IF p_approve = 1 THEN
            UPDATE collection_requests
               SET confirmed_schedule = buyer_suggested_schedule,
                   buyer_suggested_schedule = NULL
             WHERE request_id = p_request_id;
        ELSE
            UPDATE collection_requests
               SET buyer_suggested_schedule = NULL
             WHERE request_id = p_request_id;
        END IF;
        INSERT INTO notifications
            (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_buyer, IF(p_approve = 1, 'reschedule_approved', 'reschedule_declined'),
                IF(p_approve = 1, 'New pickup time approved', 'Pickup time change declined'),
                IF(p_approve = 1,
                   CONCAT('The seller approved the new pickup time: ',
                          DATE_FORMAT(v_suggested, '%b %e, %Y at %l:%i %p'), '.'),
                   'The seller declined the new pickup time. The original confirmed schedule remains.'),
                'collection_requests', p_request_id);
    COMMIT;
END$$

DELIMITER ;
