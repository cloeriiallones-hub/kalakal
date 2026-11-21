-- ============================================================
-- KALAKAL v3.3 - junkshop comparison + pickup charge by distance
-- Run ONCE on a database that already has v3.2 (20261113_owner_buyer_workflow.sql).
-- Fresh installs already contain this inside kalakal_mysql.sql - do NOT run it there.
-- Back up the `kalakal` database first.
-- ============================================================
USE kalakal;

-- ---------- 7. Junkshop comparison + pickup charge (v3.3) ----------
-- A waste owner can pick one junkshop from the dashboard comparison. A junkshop
-- outside the owner's city/municipality charges for pickup by distance:
--   charge = pickup_charge_base + pickup_charge_per_km x distance_km
-- Distance is the straight-line (haversine) distance between the two pinned
-- locations (profile > "Use my current location").
ALTER TABLE buyer_profiles
    ADD COLUMN latitude  DECIMAL(10,8) NULL AFTER city,
    ADD COLUMN longitude DECIMAL(11,8) NULL AFTER latitude;

ALTER TABLE collection_requests
    ADD COLUMN preferred_buyer_id INT UNSIGNED NULL AFTER accepted_buyer_id,
    ADD COLUMN distance_km        DECIMAL(7,2) NULL AFTER preferred_buyer_id,
    ADD COLUMN pickup_charge      DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER distance_km,
    ADD KEY idx_requests_pref_buyer (preferred_buyer_id),
    ADD CONSTRAINT fk_requests_pref_buyer
        FOREIGN KEY (preferred_buyer_id) REFERENCES users(user_id) ON DELETE SET NULL;

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('pickup_charge_base',   '30', 'Pickup charge (PHP) for a junkshop outside the owner''s municipality: fixed part'),
    ('pickup_charge_per_km', '10', 'Pickup charge (PHP) for a junkshop outside the owner''s municipality: per kilometre')
ON DUPLICATE KEY UPDATE description = VALUES(description);

CREATE OR REPLACE VIEW v_collector_open_requests AS
SELECT r.request_id,
       r.barangay,
       r.city,
       r.preferred_schedule,
       r.requested_date
FROM collection_requests r
WHERE r.request_status = 'pending'
  AND r.preferred_buyer_id IS NULL;   -- a request addressed to one junkshop is not open to collectors

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_buyer_accept_request$$
CREATE PROCEDURE sp_buyer_accept_request(
    IN p_request_id INT UNSIGNED,
    IN p_buyer_id   INT UNSIGNED
)
BEGIN
    DECLARE v_role     VARCHAR(30) DEFAULT NULL;
    DECLARE v_city     VARCHAR(100) DEFAULT NULL;
    DECLARE v_shop     VARCHAR(150) DEFAULT NULL;
    DECLARE v_schedule DATETIME DEFAULT NULL;
    DECLARE v_seller   INT UNSIGNED DEFAULT NULL;
    DECLARE v_req_city VARCHAR(100) DEFAULT NULL;
    DECLARE v_pref     INT UNSIGNED DEFAULT NULL;
    DECLARE v_charge   DECIMAL(10,2) DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_buyer_id;

    START TRANSACTION;
        SELECT u.user_role, bp.city, bp.company_name
          INTO v_role, v_city, v_shop
          FROM users u
          LEFT JOIN buyer_profiles bp ON bp.user_id = u.user_id
         WHERE u.user_id = p_buyer_id AND u.is_active = 1 AND u.deleted_at IS NULL;

        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an active junkshop can accept requests';
        END IF;

        SELECT preferred_schedule, seller_id, city, preferred_buyer_id, pickup_charge
          INTO v_schedule, v_seller, v_req_city, v_pref, v_charge
          FROM collection_requests
         WHERE request_id = p_request_id AND request_status = 'pending'
           FOR UPDATE;

        IF v_schedule IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;
        IF v_pref IS NOT NULL AND v_pref <> p_buyer_id THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'This request was sent to a different junkshop';
        END IF;
        -- an open request is limited to the junkshop city; a request the owner
        -- addressed to this junkshop may come from anywhere (pickup charge applies)
        IF v_pref IS NULL AND (v_city IS NULL OR TRIM(v_city) = ''
           OR LOWER(TRIM(v_city)) <> LOWER(TRIM(v_req_city))) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'This request is outside your junkshop city';
        END IF;

        UPDATE collection_requests
           SET accepted_buyer_id  = p_buyer_id,
               assigned_at        = NOW(),
               confirmed_schedule = v_schedule,
               request_status     = 'accepted'
         WHERE request_id = p_request_id AND request_status = 'pending';

        IF ROW_COUNT() = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;

        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_seller, 'request_accepted', 'Request accepted by a junkshop',
                CONCAT(IFNULL(v_shop, 'A junkshop'), ' accepted your pickup request #', p_request_id,
                       '. Pickup is set for ',
                       DATE_FORMAT(v_schedule, '%b %e, %Y at %l:%i %p'), '.',
                       IF(v_charge > 0, CONCAT(' Pickup charge: PHP ', FORMAT(v_charge, 2), '.'), '')),
                'collection_requests', p_request_id);
    COMMIT;
END$$

DROP TRIGGER IF EXISTS trg_requests_ai_notify$$
CREATE TRIGGER trg_requests_ai_notify AFTER INSERT ON collection_requests
FOR EACH ROW
BEGIN
    -- confirmation for the waste owner
    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    VALUES (NEW.seller_id, 'request_submitted', 'Pickup request received',
            CONCAT('Your pickup request for ',
                   DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                   ' was submitted. We will tell you when a junkshop accepts it.'),
            'collection_requests', NEW.request_id);

    -- alert for the collector covering this barangay / city
    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    SELECT cp.user_id, 'new_pickup_request', 'New pickup request in your area',
           CONCAT('A new pickup request was posted in ', NEW.barangay, ', ', NEW.city,
                  ' for ', DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                  '. Log in to accept it.'),
           'collection_requests', NEW.request_id
      FROM collector_profiles cp
      JOIN users u ON u.user_id = cp.user_id
     WHERE NEW.preferred_buyer_id IS NULL      -- requests addressed to one junkshop are not offered to collectors
       AND cp.verification_status = 'verified'
       AND cp.is_available = 1
       AND u.is_active = 1
       AND u.deleted_at IS NULL
       AND LOWER(TRIM(cp.service_area)) =
           LOWER(CONCAT(TRIM(NEW.barangay), ', ', TRIM(NEW.city)));

    -- request addressed to ONE junkshop (chosen from the dashboard comparison)
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

    -- alert for every junkshop in the same city / municipality
    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    SELECT bp.user_id, 'new_pickup_request', 'New collection request in your city',
           CONCAT('A waste owner in ', NEW.barangay, ', ', NEW.city,
                  ' requested a pickup for ',
                  DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                  '. Log in to review and accept it.'),
           'collection_requests', NEW.request_id
      FROM buyer_profiles bp
      JOIN users u ON u.user_id = bp.user_id
     WHERE NEW.preferred_buyer_id IS NULL
       AND u.is_active = 1
       AND u.deleted_at IS NULL
       AND bp.city IS NOT NULL
       AND LOWER(TRIM(bp.city)) = LOWER(TRIM(NEW.city));
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

        -- [EMAIL] cancelled: tell the junkshop that accepted it, the assigned collector,
        -- or - while still unassigned - the collectors and junkshops who could see it.
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
                -- the one junkshop the owner chose (if any)
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

        -- [EMAIL] request rejected by admin/system
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
