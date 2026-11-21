DROP PROCEDURE IF EXISTS sp_accept_request;

DELIMITER $$

CREATE PROCEDURE sp_accept_request(
    IN p_request_id   INT UNSIGNED,
    IN p_collector_id INT UNSIGNED
)
BEGIN
    DECLARE v_role VARCHAR(30);
    DECLARE v_verified VARCHAR(20);
    DECLARE v_service_area VARCHAR(255);
    DECLARE v_request_barangay VARCHAR(100);
    DECLARE v_request_city VARCHAR(100);
    DECLARE v_schedule DATETIME DEFAULT NULL;
    DECLARE v_seller INT UNSIGNED;
    DECLARE v_conflicts INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_collector_id;

    START TRANSACTION;
        SELECT u.user_role, cp.verification_status, cp.service_area
          INTO v_role, v_verified, v_service_area
          FROM users u
          LEFT JOIN collector_profiles cp ON cp.user_id = u.user_id
         WHERE u.user_id = p_collector_id AND u.is_active = 1;

        IF v_role IS NULL OR v_role <> 'collector' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an active collector can accept requests';
        END IF;
        IF v_verified IS NULL OR v_verified <> 'verified' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Collector account is not verified yet';
        END IF;

        SELECT preferred_schedule, seller_id, barangay, city
          INTO v_schedule, v_seller, v_request_barangay, v_request_city
          FROM collection_requests
         WHERE request_id = p_request_id AND request_status = 'pending'
           FOR UPDATE;

        IF v_schedule IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;
        IF v_service_area IS NULL
           OR LOWER(TRIM(v_service_area)) <>
              LOWER(CONCAT(TRIM(v_request_barangay), ', ', TRIM(v_request_city))) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'This request is outside your designated barangay and city';
        END IF;

        SELECT COUNT(*) INTO v_conflicts
          FROM collection_requests
         WHERE assigned_collector_id = p_collector_id
           AND request_status IN ('accepted','awaiting_approval','disputed')
           AND confirmed_schedule = v_schedule;

        IF v_conflicts > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'You already have a pickup at that date and time';
        END IF;

        UPDATE collection_requests
           SET assigned_collector_id = p_collector_id,
               assigned_by = p_collector_id,
               assigned_at = NOW(),
               confirmed_schedule = v_schedule,
               request_status = 'accepted'
         WHERE request_id = p_request_id
           AND request_status = 'pending';

        IF ROW_COUNT() = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;

        INSERT INTO collections (request_id, collector_id)
        VALUES (p_request_id, p_collector_id);

        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_seller, 'request_accepted', 'Pickup confirmed',
                CONCAT('A collector accepted your request. Pickup is confirmed for ',
                       DATE_FORMAT(v_schedule, '%b %e, %Y at %l:%i %p'), '.'),
                'collection_requests', p_request_id);
    COMMIT;
END$$

DELIMITER ;
