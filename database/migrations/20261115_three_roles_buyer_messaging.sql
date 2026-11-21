-- KALAKAL: consolidate accounts and add buyer proposals + messaging.
-- Back up `kalakal` first. Run once on an existing v3.3 database.
-- Resolve active collector assignments before running; the migration stops if
-- any collector-owned pickup is still in progress.
-- Fresh installs should use the updated database/kalakal_mysql.sql.
USE kalakal;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_check_legacy_collector_work$$
CREATE PROCEDURE sp_check_legacy_collector_work()
BEGIN
    IF EXISTS (
        SELECT 1
          FROM collection_requests r
          JOIN users u ON u.user_id = r.assigned_collector_id
         WHERE u.user_role = 'collector'
           AND r.request_status IN ('accepted','awaiting_approval','disputed')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Finish or cancel collector pickups before running this migration';
    END IF;
END$$

CALL sp_check_legacy_collector_work()$$
DROP PROCEDURE sp_check_legacy_collector_work$$

DELIMITER ;

ALTER TABLE users
    MODIFY COLUMN user_role ENUM('resident','establishment','collector','buyer','admin','seller')
        NOT NULL,
    ADD COLUMN seller_type ENUM('resident','establishment') NULL AFTER user_role;

UPDATE users
   SET seller_type = user_role
 WHERE user_role IN ('resident','establishment');

INSERT INTO buyer_profiles (user_id, company_name, verification_status)
SELECT u.user_id, CONCAT('Former collector #', u.user_id), 'unverified'
  FROM users u
 WHERE u.user_role = 'collector'
   AND NOT EXISTS (SELECT 1 FROM buyer_profiles bp WHERE bp.user_id = u.user_id);

UPDATE users
   SET user_role = 'seller'
 WHERE user_role IN ('resident','establishment');

UPDATE users
   SET user_role = 'buyer',
       is_active = 0
 WHERE user_role = 'collector';

ALTER TABLE users
    MODIFY COLUMN user_role ENUM('seller','buyer','admin') NOT NULL;

ALTER TABLE buyer_profiles
    ADD COLUMN payment_method ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash'
        AFTER city;

ALTER TABLE collection_requests
    MODIFY COLUMN request_status ENUM('pending','buyer_proposed','accepted','awaiting_approval',
                                      'disputed','completed','cancelled','rejected')
        NOT NULL DEFAULT 'pending',
    ADD COLUMN buyer_suggested_schedule DATETIME NULL AFTER confirmed_schedule,
    ADD COLUMN buyer_payment_method ENUM('cash','gcash','maya') NULL AFTER buyer_suggested_schedule;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_requests_bi$$
CREATE TRIGGER trg_requests_bi BEFORE INSERT ON collection_requests
FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30);
    SELECT user_role INTO v_role FROM users WHERE user_id = NEW.seller_id;
    IF v_role IS NULL OR v_role <> 'seller' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request owner must be a seller';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_requests_bu$$
CREATE TRIGGER trg_requests_bu BEFORE UPDATE ON collection_requests
FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30);

    IF OLD.request_status IN ('completed','cancelled','rejected')
       AND NEW.request_status <> OLD.request_status THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Finished requests cannot be reopened';
    END IF;

    IF NEW.request_status = 'buyer_proposed'
       AND NEW.accepted_buyer_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A buyer proposal requires a buyer';
    END IF;

    IF NEW.request_status IN ('accepted','awaiting_approval','disputed','completed')
       AND NEW.accepted_buyer_id IS NULL AND NEW.assigned_collector_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This status requires an accepting buyer';
    END IF;

    IF NEW.assigned_collector_id IS NOT NULL
       AND NOT (OLD.assigned_collector_id <=> NEW.assigned_collector_id) THEN
        SELECT user_role INTO v_role FROM users
         WHERE user_id = NEW.assigned_collector_id;
        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Assigned account must be a buyer';
        END IF;
    END IF;

    IF NEW.accepted_buyer_id IS NOT NULL
       AND NOT (OLD.accepted_buyer_id <=> NEW.accepted_buyer_id) THEN
        SELECT user_role INTO v_role FROM users
         WHERE user_id = NEW.accepted_buyer_id;
        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Accepting account must be a buyer';
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_collections_bi$$
CREATE TRIGGER trg_collections_bi BEFORE INSERT ON collections
FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30);
    SELECT user_role INTO v_role FROM users WHERE user_id = NEW.collector_id;
    IF v_role IS NULL OR v_role <> 'buyer' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Collection account must be a buyer';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_trades_bi$$
CREATE TRIGGER trg_trades_bi BEFORE INSERT ON trades
FOR EACH ROW
BEGIN
    DECLARE v_seller_role VARCHAR(30);
    DECLARE v_buyer_role VARCHAR(30);
    SELECT user_role INTO v_seller_role FROM users WHERE user_id = NEW.seller_id;
    SELECT user_role INTO v_buyer_role FROM users WHERE user_id = NEW.buyer_id;
    IF v_buyer_role IS NULL OR v_buyer_role <> 'buyer' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'buyer_id must belong to a buyer';
    END IF;
    IF v_seller_role IS NULL OR v_seller_role <> 'seller' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seller_id must belong to a seller';
    END IF;
END$$

DROP PROCEDURE IF EXISTS sp_buyer_accept_request$$
CREATE PROCEDURE sp_buyer_accept_request(
    IN p_request_id INT UNSIGNED,
    IN p_buyer_id INT UNSIGNED,
    IN p_suggested_schedule DATETIME,
    IN p_payment_method VARCHAR(20)
)
BEGIN
    DECLARE v_role VARCHAR(30) DEFAULT NULL;
    DECLARE v_city VARCHAR(100) DEFAULT NULL;
    DECLARE v_shop VARCHAR(150) DEFAULT NULL;
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;
    DECLARE v_req_city VARCHAR(100) DEFAULT NULL;
    DECLARE v_pref INT UNSIGNED DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_payment_method NOT IN ('cash','gcash','maya')
       OR p_payment_method IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a supported payment method';
    END IF;
    IF p_suggested_schedule IS NULL OR p_suggested_schedule <= NOW() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a future pickup schedule';
    END IF;

    SET @app_user_id = p_buyer_id;
    START TRANSACTION;
        SELECT u.user_role, bp.city, bp.company_name
          INTO v_role, v_city, v_shop
          FROM users u
          JOIN buyer_profiles bp ON bp.user_id = u.user_id
         WHERE u.user_id = p_buyer_id
           AND u.is_active = 1 AND u.deleted_at IS NULL
         FOR UPDATE;

        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an active junkshop can propose a collection';
        END IF;

        SELECT seller_id, city, preferred_buyer_id
          INTO v_seller, v_req_city, v_pref
          FROM collection_requests
         WHERE request_id = p_request_id AND request_status = 'pending'
         FOR UPDATE;
        IF v_seller IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;
        IF v_pref IS NOT NULL AND v_pref <> p_buyer_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This request was sent to a different junkshop';
        END IF;
        IF v_pref IS NULL AND (v_city IS NULL OR TRIM(v_city) = ''
           OR LOWER(TRIM(v_city)) <> LOWER(TRIM(v_req_city))) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This request is outside your junkshop city';
        END IF;

        UPDATE collection_requests
           SET accepted_buyer_id = p_buyer_id,
               assigned_at = NOW(),
               buyer_suggested_schedule = p_suggested_schedule,
               buyer_payment_method = p_payment_method,
               request_status = 'buyer_proposed'
         WHERE request_id = p_request_id AND request_status = 'pending';
        IF ROW_COUNT() <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;

        INSERT INTO notifications
            (user_id, notification_type, title, message, related_table, related_id)
        VALUES
            (v_seller, 'buyer_proposed', 'Junkshop proposed a collection',
             CONCAT(IFNULL(v_shop, 'A junkshop'), ' proposed ',
                    DATE_FORMAT(p_suggested_schedule, '%b %e, %Y at %l:%i %p'),
                    ' and offered payment by ', UPPER(p_payment_method),
                    '. Review the proposal in your requests.'),
             'collection_requests', p_request_id);
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
    DECLARE v_shop VARCHAR(150) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_seller_id;
    START TRANSACTION;
        SELECT r.request_status, r.accepted_buyer_id, r.buyer_suggested_schedule, bp.company_name
          INTO v_status, v_buyer, v_schedule, v_shop
          FROM collection_requests r
          LEFT JOIN buyer_profiles bp ON bp.user_id = r.accepted_buyer_id
         WHERE r.request_id = p_request_id AND r.seller_id = p_seller_id
         FOR UPDATE;

        IF v_status <> 'buyer_proposed' OR v_buyer IS NULL OR v_schedule IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No buyer proposal is waiting for this seller';
        END IF;

        IF p_approve = 1 THEN
            UPDATE collection_requests
               SET confirmed_schedule = buyer_suggested_schedule,
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

        IF p_approve = 1 THEN
            UPDATE collections
               SET collection_status = 'completed',
                   collected_at = NOW(),
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
                    'The seller disagreed with the final values. Review and resubmit them.',
                    'collection_requests', p_request_id);
        END IF;
    COMMIT;
END$$

DELIMITER ;

CREATE TABLE message_threads (
    thread_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NULL,
    participant_a_id INT UNSIGNED NOT NULL,
    participant_b_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    request_scope BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(request_id, 0)) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (thread_id),
    UNIQUE KEY uq_message_thread_direct (participant_a_id, participant_b_id, request_scope),
    KEY idx_message_thread_participants (participant_a_id, participant_b_id),
    CONSTRAINT fk_message_thread_request
        FOREIGN KEY (request_id) REFERENCES collection_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_thread_participant_a
        FOREIGN KEY (participant_a_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_thread_participant_b
        FOREIGN KEY (participant_b_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_thread_creator
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    message_body TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (message_id),
    KEY idx_messages_thread_time (thread_id, sent_at),
    KEY idx_messages_sender (sender_id),
    CONSTRAINT fk_messages_thread
        FOREIGN KEY (thread_id) REFERENCES message_threads(thread_id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender
        FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
