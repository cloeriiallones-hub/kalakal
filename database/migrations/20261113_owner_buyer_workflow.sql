-- ============================================================
-- KALAKAL v3.2  -  Waste owner / junkshop (buyer) workflow
-- Back up the `kalakal` database, then run this file ONCE on an
-- existing database (phpMyAdmin > SQL, or: mysql -u root kalakal < file).
-- Fresh installs already contain this block inside kalakal_mysql.sql.
--
--  1. users: profile_completed_at (onboarding), e-wallet provider + QR file
--  2. file_uploads.purpose: new value 'ewallet_qr'
--  3. collection_request_items: estimate_method 'detailed' = quantity
--     (pieces) + size + weight recorded together for one waste type
--  4. collection_requests.accepted_buyer_id: a junkshop can accept a request
--  5. sp_buyer_accept_request: junkshop accepts a pending request
--  6. triggers: junkshops are notified of new / cancelled requests in their
--     city; the waste owner is notified when a junkshop accepts
-- ============================================================

USE kalakal;

-- ---------- 1. Users: onboarding + e-wallet -------------------------
ALTER TABLE users
    ADD COLUMN profile_completed_at DATETIME NULL AFTER privacy_consent_at,
    ADD COLUMN ewallet_provider     VARCHAR(30) NULL AFTER profile_completed_at,
    ADD COLUMN ewallet_qr_file_id   INT UNSIGNED NULL AFTER ewallet_provider,
    ADD CONSTRAINT fk_users_ewallet_qr_file
        FOREIGN KEY (ewallet_qr_file_id) REFERENCES file_uploads(file_id)
        ON DELETE SET NULL;

-- Accounts that already exist are treated as onboarded (no pop-up for them).
UPDATE users SET profile_completed_at = created_at WHERE profile_completed_at IS NULL;

-- ---------- 2. File purposes ----------------------------------------
ALTER TABLE file_uploads
    MODIFY COLUMN purpose ENUM('waste_photo','payment_proof','id_document',
                               'business_permit','ewallet_qr','other')
                          NOT NULL DEFAULT 'other';

-- ---------- 3. Quantity + size + weight per waste type ---------------
-- 'detailed' rows: estimated_quantity = number of pieces,
--                  estimated_size     = small | medium | large,
--                  estimated_weight_kg = total weight in kg.
ALTER TABLE collection_request_items
    DROP CONSTRAINT chk_request_item_estimate;

ALTER TABLE collection_request_items
    MODIFY COLUMN estimate_method ENUM('weight','pieces','size','detailed')
                                  NOT NULL DEFAULT 'weight';

ALTER TABLE collection_request_items
    ADD CONSTRAINT chk_request_item_estimate CHECK (
        (estimate_method = 'weight' AND estimated_weight_kg > 0
         AND estimated_quantity = estimated_weight_kg AND estimated_size IS NULL)
        OR (estimate_method = 'pieces' AND estimated_weight_kg IS NULL
            AND estimated_quantity > 0 AND estimated_size IS NULL)
        OR (estimate_method = 'size' AND estimated_weight_kg IS NULL
            AND estimated_quantity > 0 AND estimated_size IS NOT NULL)
        OR (estimate_method = 'detailed' AND estimated_weight_kg > 0
            AND estimated_quantity > 0 AND estimated_size IS NOT NULL)
    );

CREATE OR REPLACE VIEW v_request_vs_actual AS
SELECT ri.request_id,
       ri.category_id,
       mc.category_name,
       CASE ri.estimate_method
            WHEN 'weight'   THEN 'kg'
            WHEN 'pieces'   THEN 'piece'
            WHEN 'detailed' THEN 'piece'
            ELSE ri.estimated_size END      AS estimated_unit,
       ri.estimated_quantity,
       ri.estimated_unit_price,
       ri.estimated_value,
       ci.quantity_unit                     AS actual_unit,
       ci.actual_quantity,
       ci.unit_price                        AS actual_unit_price,
       ci.line_total                        AS actual_value
FROM collection_request_items ri
JOIN material_categories mc    ON mc.category_id = ri.category_id
LEFT JOIN collection_items ci  ON ci.request_item_id = ri.request_item_id
UNION ALL
SELECT col.request_id,
       ci.category_id,
       mc.category_name,
       NULL, NULL, NULL, NULL,
       ci.quantity_unit,
       ci.actual_quantity,
       ci.unit_price,
       ci.line_total
FROM collection_items ci
JOIN collections col        ON col.collection_id = ci.collection_id
JOIN material_categories mc ON mc.category_id = ci.category_id
WHERE ci.request_item_id IS NULL;

-- ---------- 4. Junkshop acceptance ----------------------------------
ALTER TABLE collection_requests
    ADD COLUMN accepted_buyer_id INT UNSIGNED NULL AFTER assigned_at,
    ADD KEY idx_requests_buyer (accepted_buyer_id),
    ADD CONSTRAINT fk_requests_buyer
        FOREIGN KEY (accepted_buyer_id) REFERENCES users(user_id) ON DELETE RESTRICT;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_requests_bu$$
CREATE TRIGGER trg_requests_bu BEFORE UPDATE ON collection_requests
FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30);

    -- finished requests are final
    IF OLD.request_status IN ('completed','cancelled','rejected')
       AND NEW.request_status <> OLD.request_status THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Finished requests cannot be reopened';
    END IF;

    -- 'accepted' needs a collector OR an accepting junkshop; later stages need a collector
    IF NEW.request_status = 'accepted'
       AND NEW.assigned_collector_id IS NULL AND NEW.accepted_buyer_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This status requires an assigned collector or junkshop';
    END IF;
    IF NEW.request_status IN ('awaiting_approval','disputed','completed')
       AND NEW.assigned_collector_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'This status requires an assigned collector';
    END IF;

    IF NEW.assigned_collector_id IS NOT NULL
       AND NOT (OLD.assigned_collector_id <=> NEW.assigned_collector_id) THEN
        SELECT user_role INTO v_role FROM users
         WHERE user_id = NEW.assigned_collector_id;
        IF v_role IS NULL OR v_role <> 'collector' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Assigned user must be a collector';
        END IF;
    END IF;

    IF NEW.accepted_buyer_id IS NOT NULL
       AND NOT (OLD.accepted_buyer_id <=> NEW.accepted_buyer_id) THEN
        SELECT user_role INTO v_role FROM users
         WHERE user_id = NEW.accepted_buyer_id;
        IF v_role IS NULL OR v_role <> 'buyer' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Accepting user must be a junkshop';
        END IF;
    END IF;
END$$

-- ---------- 5. Junkshop accepts a pending request -------------------
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

        SELECT preferred_schedule, seller_id, city
          INTO v_schedule, v_seller, v_req_city
          FROM collection_requests
         WHERE request_id = p_request_id AND request_status = 'pending'
           FOR UPDATE;

        IF v_schedule IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request is no longer available';
        END IF;
        IF v_city IS NULL OR TRIM(v_city) = ''
           OR LOWER(TRIM(v_city)) <> LOWER(TRIM(v_req_city)) THEN
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
                       DATE_FORMAT(v_schedule, '%b %e, %Y at %l:%i %p'), '.'),
                'collection_requests', p_request_id);
    COMMIT;
END$$

-- ---------- 6. Notifications ------------------------------------------
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
     WHERE cp.verification_status = 'verified'
       AND cp.is_available = 1
       AND u.is_active = 1
       AND u.deleted_at IS NULL
       AND LOWER(TRIM(cp.service_area)) =
           LOWER(CONCAT(TRIM(NEW.barangay), ', ', TRIM(NEW.city)));

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
     WHERE u.is_active = 1
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
                INSERT INTO notifications
                       (user_id, notification_type, title, message, related_table, related_id)
                SELECT cp.user_id, 'request_cancelled', 'Pickup request cancelled',
                       CONCAT('A pickup request in ', NEW.barangay, ', ', NEW.city,
                              ' for ', DATE_FORMAT(OLD.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                              ' was cancelled by the seller.'),
                       'collection_requests', NEW.request_id
                  FROM collector_profiles cp
                  JOIN users u ON u.user_id = cp.user_id
                 WHERE cp.verification_status = 'verified'
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
                 WHERE u.is_active = 1
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
