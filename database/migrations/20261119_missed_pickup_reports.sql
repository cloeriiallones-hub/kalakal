-- Participant-only missed-pickup reporting and acknowledgment.
-- Preserves collection status and payment state; safe to repeat.
USE kalakal;

CREATE TABLE IF NOT EXISTS collection_incidents (
    incident_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id    INT UNSIGNED NOT NULL,
    reported_by   INT UNSIGNED NOT NULL,
    details       VARCHAR(500) NOT NULL,
    incident_status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    resolved_by   INT UNSIGNED NULL,
    resolved_at   DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (incident_id),
    UNIQUE KEY uq_collection_incident_reporter (request_id, reported_by),
    KEY idx_collection_incident_status (request_id, incident_status),
    CONSTRAINT fk_collection_incident_request
        FOREIGN KEY (request_id) REFERENCES collection_requests(request_id) ON DELETE RESTRICT,
    CONSTRAINT fk_collection_incident_reporter
        FOREIGN KEY (reported_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_collection_incident_resolver
        FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_report_missed_pickup$$
CREATE PROCEDURE sp_report_missed_pickup(
    IN p_request_id INT UNSIGNED,
    IN p_reporter_id INT UNSIGNED,
    IN p_details VARCHAR(500)
)
BEGIN
    DECLARE v_role VARCHAR(30) DEFAULT NULL;
    DECLARE v_active TINYINT DEFAULT 0;
    DECLARE v_deleted DATETIME DEFAULT NULL;
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;
    DECLARE v_buyer INT UNSIGNED DEFAULT NULL;
    DECLARE v_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_schedule DATETIME DEFAULT NULL;
    DECLARE v_suggested DATETIME DEFAULT NULL;
    DECLARE v_collection_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_collection_status VARCHAR(30) DEFAULT NULL;
    DECLARE v_items INT DEFAULT 0;
    DECLARE v_other_party INT UNSIGNED DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_details IS NULL OR TRIM(p_details) = '' OR CHAR_LENGTH(p_details) > 500 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter a missed-pickup note of up to 500 characters';
    END IF;

    SET @app_user_id = p_reporter_id;
    START TRANSACTION;
        SELECT user_role, is_active, deleted_at
          INTO v_role, v_active, v_deleted
          FROM users WHERE user_id = p_reporter_id FOR UPDATE;
        SELECT r.seller_id, r.accepted_buyer_id, r.request_status, r.confirmed_schedule,
               r.buyer_suggested_schedule, c.collection_id, c.collection_status
          INTO v_seller, v_buyer, v_status, v_schedule, v_suggested,
               v_collection_id, v_collection_status
          FROM collection_requests r
          JOIN collections c ON c.request_id = r.request_id
         WHERE r.request_id = p_request_id
         FOR UPDATE;

        IF v_active <> 1 OR v_deleted IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An active account is required to report a missed pickup';
        END IF;
        IF v_role = 'seller' AND v_seller = p_reporter_id THEN
            SET v_other_party = v_buyer;
        ELSEIF v_role = 'buyer' AND v_buyer = p_reporter_id THEN
            SET v_other_party = v_seller;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a participant can report this pickup';
        END IF;
        IF v_other_party IS NULL OR v_status <> 'accepted'
           OR v_collection_status NOT IN ('pending','in_progress')
           OR v_schedule IS NULL OR v_schedule >= NOW() THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This pickup is not eligible for a missed-pickup report';
        END IF;
        IF v_suggested IS NOT NULL AND NOT (v_suggested <=> v_schedule) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Resolve the pending reschedule proposal before reporting a missed pickup';
        END IF;
        SELECT COUNT(*) INTO v_items
          FROM collection_items WHERE collection_id = v_collection_id;
        IF v_items > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A pickup with recorded materials cannot be reported as missed';
        END IF;
        IF EXISTS (
            SELECT 1 FROM collection_incidents
             WHERE request_id = p_request_id AND reported_by = p_reporter_id
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You have already reported this pickup';
        END IF;

        INSERT INTO collection_incidents (request_id, reported_by, details)
        VALUES (p_request_id, p_reporter_id, TRIM(p_details));
        INSERT INTO notifications
            (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_other_party, 'missed_pickup_reported', 'Pickup marked as missed',
                CONCAT('The other participant reported that pickup #', p_request_id,
                       ' was missed. Review the report and contact them to agree on next steps.'),
                'collection_requests', p_request_id);
    COMMIT;
END$$

DROP PROCEDURE IF EXISTS sp_acknowledge_missed_pickup$$
CREATE PROCEDURE sp_acknowledge_missed_pickup(
    IN p_incident_id BIGINT UNSIGNED,
    IN p_user_id INT UNSIGNED
)
BEGIN
    DECLARE v_request_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_reporter INT UNSIGNED DEFAULT NULL;
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;
    DECLARE v_buyer INT UNSIGNED DEFAULT NULL;
    DECLARE v_role VARCHAR(30) DEFAULT NULL;
    DECLARE v_active TINYINT DEFAULT 0;
    DECLARE v_deleted DATETIME DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_user_id;
    START TRANSACTION;
        SELECT request_id, reported_by, incident_status
          INTO v_request_id, v_reporter, v_status
          FROM collection_incidents
         WHERE incident_id = p_incident_id
         FOR UPDATE;
        SELECT seller_id, accepted_buyer_id
          INTO v_seller, v_buyer
          FROM collection_requests
         WHERE request_id = v_request_id
         FOR UPDATE;
        SELECT user_role, is_active, deleted_at
          INTO v_role, v_active, v_deleted
          FROM users
         WHERE user_id = p_user_id
         FOR UPDATE;

        IF v_status IS NULL OR v_status <> 'open' OR p_user_id = v_reporter
           OR v_seller IS NULL
           OR (p_user_id <> v_seller AND (v_buyer IS NULL OR p_user_id <> v_buyer))
           OR v_active <> 1 OR v_deleted IS NOT NULL
           OR v_role NOT IN ('seller','buyer')
           OR (v_role = 'seller' AND p_user_id <> v_seller)
           OR (v_role = 'buyer' AND p_user_id <> v_buyer) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This missed-pickup report cannot be acknowledged by your account';
        END IF;

        UPDATE collection_incidents
           SET incident_status = 'resolved',
               resolved_by = p_user_id,
               resolved_at = NOW()
         WHERE incident_id = p_incident_id AND incident_status = 'open';
        IF ROW_COUNT() <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This missed-pickup report has already been resolved';
        END IF;
        INSERT INTO notifications
            (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_reporter, 'missed_pickup_acknowledged', 'Missed-pickup report acknowledged',
                CONCAT('The other participant acknowledged the missed-pickup report for request #',
                       v_request_id, '. Contact each other to reschedule or cancel the pickup.'),
                'collection_requests', v_request_id);
    COMMIT;
END$$

DELIMITER ;
