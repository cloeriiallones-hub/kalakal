-- Let junkshops review and propose on open seller requests from any city.
-- Requests addressed to a specific junkshop remain private to that buyer.
USE kalakal;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_buyer_accept_request$$
CREATE PROCEDURE sp_buyer_accept_request(
    IN p_request_id INT UNSIGNED,
    IN p_buyer_id INT UNSIGNED,
    IN p_suggested_schedule DATETIME,
    IN p_payment_method VARCHAR(20)
)
BEGIN
    DECLARE v_role VARCHAR(30) DEFAULT NULL;
    DECLARE v_shop VARCHAR(150) DEFAULT NULL;
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;
    DECLARE v_pref INT UNSIGNED DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_payment_method NOT IN ('cash','gcash','maya') OR p_payment_method IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a supported payment method';
    END IF;
    IF p_suggested_schedule IS NULL OR p_suggested_schedule <= NOW() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a future pickup schedule';
    END IF;

    SET @app_user_id = p_buyer_id;
    START TRANSACTION;
        SELECT u.user_role, bp.company_name
          INTO v_role, v_shop
          FROM users u
          JOIN buyer_profiles bp ON bp.user_id = u.user_id
         WHERE u.user_id = p_buyer_id AND u.is_active = 1 AND u.deleted_at IS NULL
         FOR UPDATE;
        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an active junkshop can propose a collection';
        END IF;

        SELECT seller_id, preferred_buyer_id
          INTO v_seller, v_pref
          FROM collection_requests
         WHERE request_id = p_request_id AND request_status = 'pending'
         FOR UPDATE;
        IF v_seller IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;
        IF v_pref IS NOT NULL AND v_pref <> p_buyer_id THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'This request was sent to a different junkshop';
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
        VALUES (v_seller, 'buyer_proposed', 'Junkshop proposed a collection',
                CONCAT(IFNULL(v_shop, 'A junkshop'), ' proposed ',
                       DATE_FORMAT(p_suggested_schedule, '%b %e, %Y at %l:%i %p'),
                       ' and offered payment by ', UPPER(p_payment_method),
                       '. Review the proposal in your requests.'),
                'collection_requests', p_request_id);
    COMMIT;
END$$

DROP TRIGGER IF EXISTS trg_requests_ai_notify$$
CREATE TRIGGER trg_requests_ai_notify AFTER INSERT ON collection_requests
FOR EACH ROW
BEGIN
    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    VALUES (NEW.seller_id, 'request_submitted', 'Pickup request received',
            CONCAT('Your pickup request for ',
                   DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                   ' was submitted. We will tell you when a junkshop proposes a collection.'),
            'collection_requests', NEW.request_id);

    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    SELECT cp.user_id, 'new_pickup_request', 'New pickup request in your area',
           CONCAT('A new pickup request was posted in ', NEW.barangay, ', ', NEW.city,
                  ' for ', DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                  '. Log in to review it.'),
           'collection_requests', NEW.request_id
      FROM collector_profiles cp
      JOIN users u ON u.user_id = cp.user_id
     WHERE NEW.preferred_buyer_id IS NULL
       AND cp.verification_status = 'verified'
       AND cp.is_available = 1
       AND u.is_active = 1
       AND u.deleted_at IS NULL
       AND LOWER(TRIM(cp.service_area)) =
           LOWER(CONCAT(TRIM(NEW.barangay), ', ', TRIM(NEW.city)));

    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    SELECT bp.user_id, 'new_pickup_request', 'A waste owner chose your junkshop',
           CONCAT('A waste owner in ', NEW.barangay, ', ', NEW.city,
                  ' requested a pickup for ',
                  DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                  IF(NEW.pickup_charge > 0,
                     CONCAT('. The owner agreed to a pickup charge of PHP ', FORMAT(NEW.pickup_charge, 2)),
                     ''),
                  '. Log in to review and accept it.'),
           'collection_requests', NEW.request_id
      FROM buyer_profiles bp
      JOIN users u ON u.user_id = bp.user_id
     WHERE NEW.preferred_buyer_id = bp.user_id
       AND u.is_active = 1
       AND u.deleted_at IS NULL;

    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    SELECT bp.user_id, 'new_pickup_request', 'New collection request available',
           CONCAT('A waste owner in ', NEW.barangay, ', ', NEW.city,
                  ' requested a pickup for ',
                  DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                  '. Log in to review and propose a collection.'),
           'collection_requests', NEW.request_id
      FROM buyer_profiles bp
      JOIN users u ON u.user_id = bp.user_id
     WHERE NEW.preferred_buyer_id IS NULL
       AND u.is_active = 1
       AND u.deleted_at IS NULL;
END$$

DROP TRIGGER IF EXISTS trg_requests_au$$
CREATE TRIGGER trg_requests_au AFTER UPDATE ON collection_requests
FOR EACH ROW
BEGIN
    IF NOT (OLD.request_status <=> NEW.request_status) THEN
        INSERT INTO status_history
            (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES
            ('collection_request', NEW.request_id,
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
                   AND u.deleted_at IS NULL;
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

DELIMITER ;
