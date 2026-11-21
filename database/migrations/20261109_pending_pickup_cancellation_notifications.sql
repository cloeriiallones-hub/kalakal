DROP TRIGGER IF EXISTS trg_requests_au;

DELIMITER $$

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
            IF OLD.assigned_collector_id IS NOT NULL THEN
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
