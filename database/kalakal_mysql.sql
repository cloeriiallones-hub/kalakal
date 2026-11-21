-- ============================================================
-- PROJECT   : KALAKAL
--             A Waste Material Collection, Valuation, and Trading System
-- DATABASE  : MySQL / MariaDB (XAMPP)  -- written for MariaDB 10.4+
-- VERSION   : 3.5 (guarded collection workflow, paged messages, and SMTP queue claims)
-- IMPORT    : phpMyAdmin > Import (it understands the DELIMITER lines)
--             or: mysql -u root < kalakal_mysql.sql
-- ============================================================
-- WHAT CHANGED IN v3.1+  (search for [EMAIL] in this file)
--  The database cannot send e-mail by itself. It now (a) creates a
--  notification row for every event and (b) acts as the e-mail QUEUE.
--  send_notification_emails.php sends queued rows through PHP's configured
--  mail transport. Configure that transport and schedule the CLI script.
--
--  1. users: email_notifications, login_alert_emails (opt-out switches).
--  2. notifications: email_status / attempts / batch / sent_at / error.
--     trg_notifications_bi marks rows 'skipped' for opted-out users.
--  3. NEW notification events:
--       - pickup request submitted (to seller)
--       - NEW pickup request in a buyer's city or directed to a buyer
--       - request cancelled by seller (to the buyer who proposed)
--       - request rejected (to seller)
--       - trade offer received / trade status changed (to the other party)
--       - payment received / failed / refunded
--       - account verified / rejected (buyer or seller)
--       - login alert and account-locked alert
--     Buyer proposal and seller decision procedures notify both parties.
--  4. NEW procedures: sp_record_login, sp_record_failed_login,
--     sp_claim_pending_emails, sp_mark_email_sent, sp_mark_email_failed.
--  5. NEW view: v_email_outbox (what the PHP mailer reads).
--  6. NEW settings: email_max_attempts, login_email_mode, email_from_name.
-- ============================================================
-- IMPORTANT NOTES FOR THE PHP BACKEND
--  1. Passwords: store password_hash($pw, PASSWORD_DEFAULT). Never plain text.
--  2. Tokens (reset / session): store hash('sha256', $token) only (CHAR(64)).
--  3. Before writing data, tell the DB who is acting so history/audit
--     triggers can record it:   SET @app_user_id = <logged-in user_id>;
--  4. Philippine time: run  SET time_zone = '+08:00';  on each connection
--     (or set default-time-zone in my.ini).
--  5. Access control (who may see/edit what) is NOT done by the database
--     (no Row Level Security in MySQL). Enforce it in PHP, always using
--     prepared statements (PDO / mysqli). App roles are seller, buyer,
--     and admin. Collector-specific tables, views, and routines are retained
--     for legacy compatibility and are not part of the current app workflow.
--  6. Use transactions for trades/payments; the stock CHECK constraint
--     is the final safety net against overselling.
--  7. Current pickup workflow = call the stored procedures:
--       sp_buyer_accept_request(request, buyer, schedule, payment) buyer proposes
--       sp_seller_buyer_proposal_decision(request, seller, approve) seller decides
--       sp_submit_actuals(request, buyer)                         buyer submits actuals
--       sp_seller_decision(request, seller, approve, reason)      seller reviews actuals
--     sp_accept_request and sp_decline_request are legacy collector routines.
--  8. [EMAIL] LOGIN: after the password is verified call
--       CALL sp_record_login(:user_id, :ip, :user_agent);
--     and after a WRONG password call
--       CALL sp_record_failed_login(:email, :ip);
--     (check locked_until in PHP before verifying the password).
--  9. [EMAIL] MAILER (run every minute with cron / Windows Task Scheduler):
--       $batch = uuid v4;
--       CALL sp_claim_pending_emails(20, :batch);
--       SELECT * FROM v_email_outbox WHERE email_batch = :batch;
--       for each row: send with PHP's configured mail transport, then
--          CALL sp_mark_email_sent(:notification_id, :batch);
--       or on error
--          CALL sp_mark_email_failed(:notification_id, :batch, :error_text);
--     Configure PHP's mail transport (e.g. XAMPP sendmail.ini) and set
--     KALAKAL_MAIL_FROM_ADDRESS. See docs/email-notifications.md.
--  10. [EMAIL] Password-reset and e-mail-verification links contain a
--     secret token, so send those directly from PHP (do not put the
--     token in the notifications table).
-- ============================================================



-- ############################################################
-- PART A: SECURITY & ACCOUNTS
-- ############################################################

-- ------------------------------------------------------------
-- 1. USERS
--    Show a full name with CONCAT_WS(' ', first_name, last_name).
--    Login uses email, never the name.
--    [EMAIL] email_notifications = 0 stops ALL notification e-mails.
--            login_alert_emails  = 0 stops only the login alerts.
-- ------------------------------------------------------------
CREATE TABLE users (
    user_id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name            VARCHAR(50)  NOT NULL,
    middle_name           VARCHAR(50)  NULL,
    last_name             VARCHAR(50)  NOT NULL,
    suffix                VARCHAR(10)  NULL,            -- Jr., Sr., III
    email                 VARCHAR(150) NOT NULL,
    phone_number          VARCHAR(20)  NULL,            -- 09XXXXXXXXX or +639XXXXXXXXX
    password_hash         VARCHAR(255) NOT NULL,
    user_role             ENUM('seller','buyer','admin')
                          NOT NULL,
    seller_type           ENUM('resident','establishment') NULL,
    is_active             TINYINT(1)   NOT NULL DEFAULT 1,
    email_verified_at     DATETIME     NULL,
    privacy_consent_at    DATETIME     NULL,            -- Data Privacy Act consent
    email_notifications   TINYINT(1)   NOT NULL DEFAULT 1,   -- [EMAIL]
    login_alert_emails    TINYINT(1)   NOT NULL DEFAULT 1,   -- [EMAIL]
    last_login_at         DATETIME     NULL,
    failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until          DATETIME     NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    deleted_at            DATETIME     NULL,           -- soft delete
    deletion_requested_at DATETIME     NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (user_role),
    KEY idx_users_active (is_active),
    KEY idx_users_deletion_requested (deletion_requested_at),
    KEY idx_users_name (last_name, first_name),
    CONSTRAINT chk_users_phone CHECK (
        phone_number IS NULL
        OR phone_number REGEXP '^(09[0-9]{9}|[+]639[0-9]{9})$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 2. PASSWORD RESETS
-- ------------------------------------------------------------
CREATE TABLE password_resets (
    reset_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    requested_ip VARCHAR(45)  NULL,
    expires_at   DATETIME     NOT NULL,
    used_at      DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reset_id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 3. LOGIN ATTEMPTS  (brute-force protection / rate limiting)
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
    attempt_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(150) NOT NULL,
    user_id       INT UNSIGNED NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    was_successful TINYINT(1)  NOT NULL DEFAULT 0,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_id),
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at),
    KEY idx_login_attempts_user_ip (user_id, ip_address, was_successful),
    CONSTRAINT fk_login_attempts_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 4. USER SESSIONS  (server-side sessions / API tokens)
-- ------------------------------------------------------------
CREATE TABLE user_sessions (
    session_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    expires_at  DATETIME     NOT NULL,
    revoked_at  DATETIME     NULL,
    PRIMARY KEY (session_id),
    UNIQUE KEY uq_user_sessions_token (token_hash),
    KEY idx_user_sessions_user (user_id),
    KEY idx_user_sessions_expires (expires_at),
    CONSTRAINT fk_user_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_verification_tokens (
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

CREATE TABLE admin_mfa_challenges (
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

CREATE TABLE auth_email_attempts (
    attempt_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type   ENUM('password_reset','verification') NOT NULL,
    email_hash   CHAR(64) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_id),
    KEY idx_auth_email_ip_time (event_type, ip_address, attempted_at),
    KEY idx_auth_email_hash_time (event_type, email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################
-- PART B: PROFILES, ADDRESSES, FILES
-- ############################################################

-- ------------------------------------------------------------
-- 5. ADDRESSES  (a user can have many saved addresses)
-- ------------------------------------------------------------
CREATE TABLE addresses (
    address_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    label        VARCHAR(50)  NOT NULL DEFAULT 'Home',
    address_line VARCHAR(255) NOT NULL,                -- house no., street, purok/subdivision
    barangay     VARCHAR(100) NOT NULL,
    city         VARCHAR(100) NOT NULL,
    province     VARCHAR(100) NULL,
    postal_code  VARCHAR(10)  NULL,
    landmark     VARCHAR(150) NULL,                    -- "beside the blue sari-sari store"
    latitude     DECIMAL(10,8) NULL,
    longitude    DECIMAL(11,8) NULL,
    is_default   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (address_id),
    KEY idx_addresses_user (user_id),
    KEY idx_addresses_barangay_city (barangay, city),
    CONSTRAINT fk_addresses_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 6. FILE UPLOADS  (waste photos, payment proofs, IDs, permits)
--    Store the file on disk (e.g. /uploads/...) and the path here.
-- ------------------------------------------------------------
CREATE TABLE file_uploads (
    file_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uploaded_by   INT UNSIGNED NULL,
    purpose       ENUM('waste_photo','payment_proof','id_document',
                       'business_permit','other') NOT NULL DEFAULT 'other',
    original_name VARCHAR(255) NOT NULL,
    stored_path   VARCHAR(500) NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    file_size_bytes INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id),
    KEY idx_file_uploads_user (uploaded_by),
    CONSTRAINT fk_file_uploads_user
        FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_file_size CHECK (file_size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 7. ESTABLISHMENT PROFILES
-- ------------------------------------------------------------
CREATE TABLE establishment_profiles (
    user_id             INT UNSIGNED NOT NULL,
    business_name       VARCHAR(150) NOT NULL,
    business_type       VARCHAR(100) NULL,
    permit_number       VARCHAR(100) NULL,
    tin                 VARCHAR(30)  NULL,
    contact_person      VARCHAR(150) NULL,
    verification_status ENUM('unverified','pending','verified','rejected')
                        NOT NULL DEFAULT 'unverified',
    verified_by         INT UNSIGNED NULL,
    verified_at         DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_establishment_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_establishment_verified_by
        FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 8. BUYER (JUNKSHOP) PROFILES
--    barangay and city so shops can be filtered by area.
--    company_name is the junkshop name.
-- ------------------------------------------------------------
CREATE TABLE buyer_profiles (
    user_id             INT UNSIGNED NOT NULL,
    company_name        VARCHAR(150) NOT NULL,
    business_permit_no  VARCHAR(100) NULL,
    tin                 VARCHAR(30)  NULL,
    business_address    VARCHAR(255) NULL,
    barangay            VARCHAR(100) NULL,
    city                VARCHAR(100) NULL,
    payment_method      ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash',
    verification_status ENUM('unverified','pending','verified','rejected')
                        NOT NULL DEFAULT 'unverified',
    verified_by         INT UNSIGNED NULL,
    verified_at         DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_buyer_area (barangay, city),
    CONSTRAINT fk_buyer_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_buyer_verified_by
        FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 9. COLLECTOR PROFILES
--    Collectors must be approved by an admin before they may accept
--    requests. service_area format: 'Barangay, City' (unique per collector).
-- ------------------------------------------------------------
CREATE TABLE collector_profiles (
    user_id        INT UNSIGNED NOT NULL,
    vehicle_type   VARCHAR(50)  NULL,
    plate_number   VARCHAR(20)  NULL,
    service_area   VARCHAR(255) NULL,   -- e.g. 'Lahug, Cebu City'
    is_available   TINYINT(1)   NOT NULL DEFAULT 1,
    id_file_id     INT UNSIGNED NULL,   -- uploaded ID document
    verification_status ENUM('unverified','pending','verified','rejected')
                   NOT NULL DEFAULT 'unverified',
    verified_by    INT UNSIGNED NULL,
    verified_at    DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_collector_service_area (service_area),
    CONSTRAINT fk_collector_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_collector_id_file
        FOREIGN KEY (id_file_id) REFERENCES file_uploads(file_id) ON DELETE SET NULL,
    CONSTRAINT fk_collector_verified_by
        FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################
-- PART C: MATERIALS & PRICING
-- ############################################################

-- ------------------------------------------------------------
-- 10. MATERIAL CATEGORIES
-- ------------------------------------------------------------
CREATE TABLE material_categories (
    category_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL,
    description   TEXT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_material_categories_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 11. MATERIAL PRICES  (platform reference price; history kept;
--     only ONE open price per category)
--     open_flag = 1 while effective_until IS NULL, otherwise NULL.
--     UNIQUE(category_id, open_flag) therefore blocks overlapping
--     "current" prices. Use sp_set_material_price() to change prices.
-- ------------------------------------------------------------
CREATE TABLE material_prices (
    price_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id     INT UNSIGNED NOT NULL,
    buying_price    DECIMAL(10,2) NOT NULL,
    unit            VARCHAR(20)  NOT NULL DEFAULT 'kg',
    effective_from  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_until DATETIME     NULL,
    open_flag       TINYINT(1)
        GENERATED ALWAYS AS (IF(effective_until IS NULL, 1, NULL)) STORED,
    PRIMARY KEY (price_id),
    UNIQUE KEY uq_price_category (price_id, category_id),   -- target of composite FK
    UNIQUE KEY uq_one_open_price (category_id, open_flag),
    KEY idx_material_prices_from (effective_from),
    CONSTRAINT fk_material_prices_category
        FOREIGN KEY (category_id) REFERENCES material_categories(category_id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_material_price_value CHECK (buying_price >= 0),
    CONSTRAINT chk_material_price_dates
        CHECK (effective_until IS NULL OR effective_until >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 11b. JUNKSHOP MATERIAL PRICES  (each junkshop sets its own prices)
--      One row = one price for one shop, one category and one unit
--      (kg / piece / small / medium / large). Closed rows keep the
--      shop's price history. Junkshops update prices ONLY through
--      sp_set_junkshop_price() so the old row is closed properly.
-- ------------------------------------------------------------
CREATE TABLE junkshop_material_prices (
    offer_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    buyer_id        INT UNSIGNED NOT NULL,
    category_id     INT UNSIGNED NOT NULL,
    price_unit      ENUM('kg','piece','small','medium','large') NOT NULL,
    buying_price    DECIMAL(10,2) NOT NULL,
    effective_from  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- "last updated" shown to users
    effective_until DATETIME NULL,
    open_flag       TINYINT(1)
        GENERATED ALWAYS AS (IF(effective_until IS NULL, 1, NULL)) STORED,
    PRIMARY KEY (offer_id),
    UNIQUE KEY uq_junkshop_price_offer (buyer_id, category_id, price_unit, open_flag),
    KEY idx_junkshop_prices_category (category_id, effective_until),
    CONSTRAINT fk_junkshop_prices_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_junkshop_prices_category
        FOREIGN KEY (category_id) REFERENCES material_categories(category_id) ON DELETE RESTRICT,
    CONSTRAINT chk_junkshop_price_value CHECK (buying_price >= 0),
    CONSTRAINT chk_junkshop_price_dates
        CHECK (effective_until IS NULL OR effective_until >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################
-- PART D: COLLECTION WORKFLOW
-- ############################################################

-- ------------------------------------------------------------
-- 12. COLLECTION REQUESTS  (a seller's pickup request)
--     The typed location is stored on the request itself
--     (a snapshot), so later profile edits never change old requests.
--     Status flow:
--       pending            seller submitted, waiting for a collector
--       accepted           a collector accepted; confirmed_schedule is set
--       awaiting_approval  collector entered the real quantities/prices
--       disputed           seller disagreed with the real values
--       completed          seller approved
--       cancelled          seller cancelled
--       rejected           admin/system rejected the request
--     assigned_at = the moment the collector accepted.
-- ------------------------------------------------------------
CREATE TABLE collection_requests (
    request_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id             INT UNSIGNED NOT NULL,
    submission_token      CHAR(64) NULL,
    address_id            INT UNSIGNED NULL,           -- saved address used (optional)
    street_address        VARCHAR(255) NOT NULL,       -- house no., street, purok/subdivision
    barangay              VARCHAR(100) NOT NULL,
    city                  VARCHAR(100) NOT NULL,
    landmark              VARCHAR(150) NOT NULL,       -- e.g. "across the chapel"
    pickup_notes          VARCHAR(255) NULL,           -- e.g. "green gate, call on arrival"
    contact_number        VARCHAR(20)  NOT NULL,       -- number the collector may call
    latitude              DECIMAL(10,8) NULL,          -- optional map pin (can be added later)
    longitude             DECIMAL(11,8) NULL,
    consent_to_share      TINYINT(1)   NOT NULL DEFAULT 0,  -- seller agrees to share details
    requested_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    preferred_schedule    DATETIME NOT NULL,           -- date + time the seller wants
    confirmed_schedule    DATETIME NULL,               -- set when a collector accepts
    buyer_suggested_schedule DATETIME NULL,
    buyer_payment_method ENUM('cash','gcash','maya') NULL,
    request_status        ENUM('pending','buyer_proposed','accepted','awaiting_approval','disputed',
                               'completed','cancelled','rejected')
                          NOT NULL DEFAULT 'pending',
    assigned_collector_id INT UNSIGNED NULL,
    assigned_by           INT UNSIGNED NULL,
    assigned_at           DATETIME NULL,
    remarks               TEXT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    UNIQUE KEY uq_collection_requests_submission_token (submission_token),
    KEY idx_requests_seller (seller_id),
    KEY idx_requests_status (request_status),
    KEY idx_requests_collector (assigned_collector_id),
    KEY idx_requests_schedule (preferred_schedule),
    KEY idx_requests_area (barangay, city, request_status),
    CONSTRAINT fk_requests_seller
        FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_requests_address
        FOREIGN KEY (address_id) REFERENCES addresses(address_id) ON DELETE SET NULL,
    CONSTRAINT fk_requests_collector
        FOREIGN KEY (assigned_collector_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_requests_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_request_consent CHECK (consent_to_share = 1),
    CONSTRAINT chk_request_phone CHECK (
        contact_number REGEXP '^(09[0-9]{9}|[+]639[0-9]{9})$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 12b. COLLECTION REQUEST DECLINES
--      Remembers which collector declined which request, so the
--      request is hidden from that collector's open list.
-- ------------------------------------------------------------
CREATE TABLE collection_request_declines (
    decline_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id   INT UNSIGNED NOT NULL,
    collector_id INT UNSIGNED NOT NULL,
    reason       VARCHAR(255) NULL,
    declined_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (decline_id),
    UNIQUE KEY uq_decline (request_id, collector_id),
    CONSTRAINT fk_declines_request
        FOREIGN KEY (request_id) REFERENCES collection_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_declines_collector
        FOREIGN KEY (collector_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 12c. CONTACT ACCESS LOGS
--      PHP inserts a row every time a collector opens the seller's
--      name / address / phone (v_collector_request_details).
-- ------------------------------------------------------------
CREATE TABLE contact_access_logs (
    log_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id   INT UNSIGNED NOT NULL,
    collector_id INT UNSIGNED NOT NULL,
    ip_address   VARCHAR(45) NULL,
    viewed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_contact_access_request (request_id),
    KEY idx_contact_access_collector (collector_id, viewed_at),
    CONSTRAINT fk_contact_access_request
        FOREIGN KEY (request_id) REFERENCES collection_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_access_collector
        FOREIGN KEY (collector_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 13. COLLECTION REQUEST ITEMS  (what the seller ESTIMATES up front)
--     Supports weight, pieces or size, an optional estimated price,
--     and several sizes of the same category in one request.
-- ------------------------------------------------------------
CREATE TABLE collection_request_items (
    request_item_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id          INT UNSIGNED NOT NULL,
    category_id         INT UNSIGNED NOT NULL,
    estimated_weight_kg DECIMAL(12,3) NULL,
    estimate_method     ENUM('weight','pieces','size') NOT NULL DEFAULT 'weight',
    estimated_quantity  DECIMAL(12,3) NOT NULL,        -- kg, or number of pieces
    estimated_size      ENUM('small','medium','large') NULL,
    estimated_unit_price DECIMAL(10,2) NULL,           -- optional, for the seller's reference
    estimated_value     DECIMAL(12,2)
        GENERATED ALWAYS AS (ROUND(estimated_quantity * estimated_unit_price, 2)) STORED,
    size_key            VARCHAR(10)
        GENERATED ALWAYS AS (IFNULL(CAST(estimated_size AS CHAR), '')) STORED,
    photo_file_id       INT UNSIGNED NULL,
    notes               VARCHAR(255) NULL,
    PRIMARY KEY (request_item_id),
    UNIQUE KEY uq_request_category (request_id, category_id, estimate_method, size_key),
    KEY idx_request_items_category (category_id),
    CONSTRAINT fk_request_items_request
        FOREIGN KEY (request_id) REFERENCES collection_requests(request_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_request_items_category
        FOREIGN KEY (category_id) REFERENCES material_categories(category_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_request_items_photo
        FOREIGN KEY (photo_file_id) REFERENCES file_uploads(file_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_request_item_estimate CHECK (
        (estimate_method = 'weight' AND estimated_weight_kg > 0
         AND estimated_quantity = estimated_weight_kg AND estimated_size IS NULL)
        OR (estimate_method = 'pieces' AND estimated_weight_kg IS NULL
            AND estimated_quantity > 0 AND estimated_size IS NULL)
        OR (estimate_method = 'size' AND estimated_weight_kg IS NULL
            AND estimated_quantity > 0 AND estimated_size IS NOT NULL)
    ),
    CONSTRAINT chk_request_item_price
        CHECK (estimated_unit_price IS NULL OR estimated_unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 14. COLLECTIONS  (one actual collection per request)
--       pending -> in_progress -> awaiting_seller_approval -> completed
--                                              \-> disputed -> (collector edits, re-submits)
-- ------------------------------------------------------------
CREATE TABLE collections (
    collection_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id        INT UNSIGNED NOT NULL,
    collector_id      INT UNSIGNED NOT NULL,
    collected_at      DATETIME NULL,                  -- set when the junkshop submits collected values
    collection_status ENUM('pending','in_progress','awaiting_seller_approval',
                           'completed','disputed','cancelled')
                      NOT NULL DEFAULT 'pending',
    confirmed_at      DATETIME NULL,                  -- collector submitted real values
    seller_approved_at DATETIME NULL,
    dispute_reason    VARCHAR(500) NULL,
    notes             TEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (collection_id),
    UNIQUE KEY uq_collections_request (request_id),
    KEY idx_collections_collector (collector_id),
    KEY idx_collections_status (collection_status),
    CONSTRAINT fk_collections_request
        FOREIGN KEY (request_id) REFERENCES collection_requests(request_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_collections_collector
        FOREIGN KEY (collector_id) REFERENCES users(user_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_incidents (
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


-- ------------------------------------------------------------
-- 15. COLLECTION ITEMS  (ACTUAL values confirmed by the collector;
--     also acts as the INVENTORY / STOCK table)
--     quantity_unit      : kg / piece / small / medium / large
--     actual_quantity    : real weight, piece count, or count of that size
--     unit_price         : price per unit the COLLECTOR applies (a snapshot,
--                          never looked up later). If left at 0 and a
--                          reference price_id (kg only) is given, the
--                          trigger fills it from material_prices.
--     line_total         : computed, can never drift from qty x price
--     remaining_quantity : stock still available to sell (auto-managed)
--     request_item_id    : the seller's estimate this row confirms;
--                          NULL = item added by the collector at pickup.
-- ------------------------------------------------------------
CREATE TABLE collection_items (
    collection_item_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id       INT UNSIGNED NOT NULL,
    request_item_id     INT UNSIGNED NULL,
    category_id         INT UNSIGNED NOT NULL,
    price_id            INT UNSIGNED NULL,               -- optional reference price
    quantity_unit       ENUM('kg','piece','small','medium','large') NOT NULL DEFAULT 'kg',
    actual_quantity     DECIMAL(12,3) NOT NULL,          -- 0 allowed: "seller had none of this"
    unit_price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total          DECIMAL(12,2)
        GENERATED ALWAYS AS (ROUND(actual_quantity * unit_price, 2)) STORED,
    remaining_quantity  DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    photo_file_id       INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (collection_item_id),
    UNIQUE KEY uq_collection_items_estimate (request_item_id),
    KEY idx_collection_items_collection (collection_id),
    KEY idx_collection_items_category (category_id),
    KEY idx_collection_items_price (price_id, category_id),
    CONSTRAINT fk_collection_items_collection
        FOREIGN KEY (collection_id) REFERENCES collections(collection_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_collection_items_request_item
        FOREIGN KEY (request_item_id) REFERENCES collection_request_items(request_item_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_collection_items_category
        FOREIGN KEY (category_id) REFERENCES material_categories(category_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_collection_items_price
        FOREIGN KEY (price_id, category_id)
        REFERENCES material_prices(price_id, category_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_collection_items_photo
        FOREIGN KEY (photo_file_id) REFERENCES file_uploads(file_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_collection_item_qty CHECK (actual_quantity >= 0),
    CONSTRAINT chk_collection_item_unit_price CHECK (unit_price >= 0),
    CONSTRAINT chk_collection_item_stock
        CHECK (remaining_quantity >= 0 AND remaining_quantity <= actual_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################
-- PART E: TRADING & PAYMENTS
-- ############################################################

-- ------------------------------------------------------------
-- 16. TRADES
--     (Total amount comes from the v_trade_totals view, so it can
--      never be out of sync with trade_items.)
-- ------------------------------------------------------------
CREATE TABLE trades (
    trade_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id    INT UNSIGNED NOT NULL,
    buyer_id     INT UNSIGNED NOT NULL,
    trade_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    trade_status ENUM('pending','accepted','completed','cancelled','rejected')
                 NOT NULL DEFAULT 'pending',
    remarks      TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (trade_id),
    KEY idx_trades_seller (seller_id),
    KEY idx_trades_buyer (buyer_id),
    KEY idx_trades_status (trade_status),
    CONSTRAINT fk_trades_seller
        FOREIGN KEY (seller_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_trades_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_trade_participants CHECK (seller_id <> buyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 17. TRADE ITEMS
--     quantity unit = the collection item's quantity_unit.
--     Stock is deducted automatically (see triggers).
-- ------------------------------------------------------------
CREATE TABLE trade_items (
    trade_item_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trade_id           INT UNSIGNED NOT NULL,
    collection_item_id INT UNSIGNED NOT NULL,
    quantity           DECIMAL(12,3) NOT NULL,
    agreed_unit_price  DECIMAL(10,2) NOT NULL,
    line_total         DECIMAL(12,2)
        GENERATED ALWAYS AS (ROUND(quantity * agreed_unit_price, 2)) STORED,
    PRIMARY KEY (trade_item_id),
    UNIQUE KEY uq_trade_item (trade_id, collection_item_id),
    KEY idx_trade_items_collection_item (collection_item_id),
    CONSTRAINT fk_trade_items_trade
        FOREIGN KEY (trade_id) REFERENCES trades(trade_id) ON DELETE RESTRICT,
    CONSTRAINT fk_trade_items_collection_item
        FOREIGN KEY (collection_item_id) REFERENCES collection_items(collection_item_id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_trade_item_qty   CHECK (quantity > 0),
    CONSTRAINT chk_trade_item_price CHECK (agreed_unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 18. TRANSACTION PAYMENTS  (supports partial payments)
-- ------------------------------------------------------------
CREATE TABLE transaction_payments (
    payment_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    trade_id         INT UNSIGNED NOT NULL,
    payment_date     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    amount_paid      DECIMAL(12,2) NOT NULL,
    payment_method   ENUM('cash','gcash','maya','bank_transfer','other')
                     NOT NULL DEFAULT 'cash',
    payment_status   ENUM('pending','paid','failed','refunded')
                     NOT NULL DEFAULT 'pending',
    reference_number VARCHAR(100) NULL,
    received_by      INT UNSIGNED NULL,
    proof_file_id    INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payments_reference (reference_number),
    KEY idx_payments_trade (trade_id),
    KEY idx_payments_status (payment_status),
    CONSTRAINT fk_payments_trade
        FOREIGN KEY (trade_id) REFERENCES trades(trade_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_received_by
        FOREIGN KEY (received_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_proof
        FOREIGN KEY (proof_file_id) REFERENCES file_uploads(file_id) ON DELETE SET NULL,
    CONSTRAINT chk_payment_amount CHECK (amount_paid > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 18b. COLLECTION PAYMENTS (kept separate from collection completion)
-- ------------------------------------------------------------
CREATE TABLE collection_payments (
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


-- ############################################################
-- PART F: MANAGEMENT, AUDIT & COMMUNICATION
-- ############################################################

-- ------------------------------------------------------------
-- 19. REVIEWS  (ratings between users)
-- ------------------------------------------------------------
CREATE TABLE reviews (
    review_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reviewer_id      INT UNSIGNED NOT NULL,
    reviewed_user_id INT UNSIGNED NOT NULL,
    trade_id         INT UNSIGNED NULL,
    collection_id    INT UNSIGNED NULL,
    rating           TINYINT UNSIGNED NOT NULL,
    review_comment   VARCHAR(500) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (review_id),
    KEY idx_reviews_reviewed (reviewed_user_id),
    KEY idx_reviews_reviewer (reviewer_id),
    KEY idx_reviews_trade (trade_id),
    KEY idx_reviews_collection (collection_id),
    CONSTRAINT fk_reviews_reviewer
        FOREIGN KEY (reviewer_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_reviews_reviewed
        FOREIGN KEY (reviewed_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_reviews_trade
        FOREIGN KEY (trade_id) REFERENCES trades(trade_id) ON DELETE RESTRICT,
    CONSTRAINT fk_reviews_collection
        FOREIGN KEY (collection_id) REFERENCES collections(collection_id) ON DELETE RESTRICT,
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT chk_review_not_self CHECK (reviewer_id <> reviewed_user_id),
    CONSTRAINT chk_review_target CHECK (trade_id IS NOT NULL OR collection_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 20. STATUS HISTORY  (filled automatically by triggers)
-- ------------------------------------------------------------
CREATE TABLE status_history (
    history_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type ENUM('collection_request','collection','trade','payment') NOT NULL,
    entity_id   INT UNSIGNED NOT NULL,
    old_status  VARCHAR(30) NULL,
    new_status  VARCHAR(30) NOT NULL,
    changed_by  INT UNSIGNED NULL,
    changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    KEY idx_status_history_entity (entity_type, entity_id),
    CONSTRAINT fk_status_history_user
        FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 21. AUDIT LOGS  (who did what, to which record, old/new values)
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
    audit_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(50)  NOT NULL,         -- INSERT / UPDATE / DELETE / LOGIN ...
    table_name  VARCHAR(64)  NULL,
    record_id   INT UNSIGNED NULL,
    old_values  LONGTEXT NULL,                 -- JSON text
    new_values  LONGTEXT NULL,                 -- JSON text
    ip_address  VARCHAR(45)  NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_table_record (table_name, record_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 22. NOTIFICATIONS  [EMAIL] = in-app notification AND e-mail queue
--     Every row is shown inside the website. The same row is also
--     e-mailed by the PHP mailer (see notes 8 and 9 at the top).
--
--     email_status:
--       pending  waiting to be e-mailed
--       sending  claimed by a mailer run (email_batch identifies it)
--       sent     e-mail delivered to the SMTP server
--       failed   gave up after email_max_attempts tries (see email_error)
--       skipped  user turned e-mails off / account inactive
-- ------------------------------------------------------------
CREATE TABLE notifications (
    notification_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    notification_type  VARCHAR(50)  NOT NULL,  -- e.g. request_accepted, payment_received
    title              VARCHAR(150) NOT NULL,
    message            VARCHAR(500) NOT NULL,
    related_table      VARCHAR(64)  NULL,
    related_id         INT UNSIGNED NULL,
    is_read            TINYINT(1)   NOT NULL DEFAULT 0,
    read_at            DATETIME     NULL,
    email_status       ENUM('pending','sending','sent','failed','skipped')
                       NOT NULL DEFAULT 'pending',          -- [EMAIL]
    email_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0, -- [EMAIL]
    email_batch        CHAR(36)     NULL,                   -- [EMAIL] mailer run id
    email_claimed_at   DATETIME     NULL,                   -- [EMAIL]
    email_sent_at      DATETIME     NULL,                   -- [EMAIL]
    email_error        VARCHAR(255) NULL,                   -- [EMAIL]
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY idx_notifications_user_read (user_id, is_read),
    KEY idx_notifications_email_queue (email_status, notification_id),
    KEY idx_notifications_email_batch (email_batch),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 23. SYSTEM SETTINGS
-- ------------------------------------------------------------
CREATE TABLE system_settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value VARCHAR(500) NOT NULL,
    description   VARCHAR(255) NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_user
        FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################
-- PART G: VIEWS
-- ############################################################

-- Current (open) platform reference price of every category
CREATE VIEW v_current_prices AS
SELECT c.category_id,
       c.category_name,
       p.price_id,
       p.buying_price,
       p.unit,
       p.effective_from
FROM material_categories c
JOIN material_prices p
  ON p.category_id = c.category_id
 AND p.effective_until IS NULL
WHERE c.is_active = 1;

-- Current price list of every junkshop (shown to sellers/collectors).
--       price_updated_at tells users how fresh the price is.
--       Filter on verification_status = 'verified' in PHP if you only
--       want approved shops to appear.
CREATE VIEW v_current_junkshop_prices AS
SELECT jp.offer_id,
       jp.buyer_id,
       bp.company_name      AS junkshop_name,
       bp.barangay          AS junkshop_barangay,
       bp.city              AS junkshop_city,
       bp.verification_status,
       c.category_id,
       c.category_name,
       jp.price_unit,
       jp.buying_price,
       jp.effective_from    AS price_updated_at
FROM junkshop_material_prices jp
JOIN users u               ON u.user_id = jp.buyer_id
                          AND u.is_active = 1 AND u.deleted_at IS NULL
JOIN buyer_profiles bp     ON bp.user_id = jp.buyer_id
JOIN material_categories c ON c.category_id = jp.category_id AND c.is_active = 1
WHERE jp.effective_until IS NULL;

-- Total, paid, and balance of every trade
CREATE VIEW v_trade_totals AS
SELECT x.*,
       (x.total_amount - x.total_paid) AS balance_due
FROM (
    SELECT t.trade_id,
           t.seller_id,
           t.buyer_id,
           t.trade_status,
           COALESCE((SELECT SUM(ti.line_total)
                     FROM trade_items ti
                     WHERE ti.trade_id = t.trade_id), 0) AS total_amount,
           COALESCE((SELECT SUM(p.amount_paid)
                     FROM transaction_payments p
                     WHERE p.trade_id = t.trade_id
                       AND p.payment_status = 'paid'), 0) AS total_paid
    FROM trades t
) x;

-- Stock available per category AND unit (completed collections only)
CREATE VIEW v_inventory AS
SELECT c.category_id,
       c.category_name,
       ci.quantity_unit,
       COALESCE(SUM(CASE WHEN col.collection_status = 'completed'
                         THEN ci.remaining_quantity ELSE 0 END), 0) AS available_quantity
FROM material_categories c
LEFT JOIN collection_items ci ON ci.category_id = c.category_id
LEFT JOIN collections col     ON col.collection_id = ci.collection_id
GROUP BY c.category_id, c.category_name, ci.quantity_unit;

-- ---- PRIVACY VIEWS FOR COLLECTORS ----------------------------

-- What a collector may see BEFORE accepting: matching service area and schedule only.
-- No name, street, landmark or phone. In PHP also add:
--   AND NOT EXISTS (SELECT 1 FROM collection_request_declines d
--                   WHERE d.request_id = v.request_id AND d.collector_id = :me)
-- PHP filters by the collector's exact, uniquely assigned barangay/city.
CREATE VIEW v_collector_open_requests AS
SELECT r.request_id,
       r.barangay,
       r.city,
       r.preferred_schedule,
       r.requested_date
FROM collection_requests r
WHERE r.request_status = 'pending';

-- Full details, only while the request is active. Hidden again once it is
-- completed / cancelled. ALWAYS query with WHERE assigned_collector_id = :me
-- and insert a row in contact_access_logs each time it is opened.
CREATE VIEW v_collector_request_details AS
SELECT r.request_id,
       r.assigned_collector_id,
       r.request_status,
       r.confirmed_schedule,
       u.first_name   AS seller_first_name,
       u.last_name    AS seller_last_name,
       r.contact_number,
       r.street_address,
       r.barangay,
       r.city,
       r.landmark,
       r.pickup_notes,
       r.latitude,
       r.longitude,
       CONCAT_WS(', ', r.street_address, r.barangay, r.city) AS full_address
FROM collection_requests r
JOIN users u ON u.user_id = r.seller_id
WHERE r.assigned_collector_id IS NOT NULL
  AND r.request_status IN ('accepted','awaiting_approval','disputed');

-- What the SELLER sees: request status and, once accepted,
-- who is coming and when. Query with WHERE seller_id = :me.
CREATE VIEW v_seller_requests AS
SELECT r.request_id,
       r.seller_id,
       r.request_status,
       r.preferred_schedule,
       r.confirmed_schedule,
       r.assigned_at            AS accepted_at,
       r.barangay,
       r.city,
       cu.first_name            AS collector_first_name,
       cu.last_name             AS collector_last_name,
       cu.phone_number          AS collector_phone,
       cp.vehicle_type,
       cp.plate_number
FROM collection_requests r
LEFT JOIN users cu              ON cu.user_id = r.assigned_collector_id
LEFT JOIN collector_profiles cp ON cp.user_id = r.assigned_collector_id;

-- Seller's estimate next to the collector's confirmed values.
-- Items the collector added at pickup have NULL estimate columns;
-- estimated items the collector has not confirmed yet have NULL actual columns.
CREATE VIEW v_request_vs_actual AS
SELECT ri.request_id,
       ri.category_id,
       mc.category_name,
       CASE ri.estimate_method
            WHEN 'weight' THEN 'kg'
            WHEN 'pieces' THEN 'piece'
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

-- Final total of each collection (sum of confirmed line totals)
CREATE VIEW v_collection_totals AS
SELECT col.collection_id,
       col.request_id,
       col.collection_status,
       COALESCE(SUM(ci.line_total), 0) AS final_total
FROM collections col
LEFT JOIN collection_items ci ON ci.collection_id = col.collection_id
GROUP BY col.collection_id, col.request_id, col.collection_status;

-- [EMAIL] What the PHP mailer reads: only rows it has CLAIMED
-- with sp_claim_pending_emails(). Always filter by your batch id:
--   SELECT * FROM v_email_outbox WHERE email_batch = :batch;
CREATE VIEW v_email_outbox AS
SELECT n.notification_id,
       n.email_batch,
       n.notification_type,
       n.title,
       n.message,
       n.related_table,
       n.related_id,
       n.email_attempts,
       n.created_at,
       u.user_id,
       u.email       AS to_email,
       u.first_name  AS to_first_name
FROM notifications n
JOIN users u ON u.user_id = n.user_id
WHERE n.email_status = 'sending';


-- ############################################################
-- PART H: STORED PROCEDURES, TRIGGERS
-- ############################################################

DELIMITER $$

-- ------------------------------------------------------------
-- Change a platform reference price safely: closes the old price
-- and opens the new one in one transaction (keeps price history).
--   CALL sp_set_material_price(1, 6.50, 'kg');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_set_material_price(
    IN p_category_id INT UNSIGNED,
    IN p_price       DECIMAL(10,2),
    IN p_unit        VARCHAR(20)
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
        UPDATE material_prices
           SET effective_until = NOW()
         WHERE category_id = p_category_id
           AND effective_until IS NULL;

        INSERT INTO material_prices (category_id, buying_price, unit, effective_from)
        VALUES (p_category_id, p_price, p_unit, NOW());
    COMMIT;
END$$


-- ------------------------------------------------------------
-- A JUNKSHOP updates its own price. The logged-in junkshop's
-- user_id must be passed by PHP (never taken from a form field).
-- Closes the shop's old price for that category + unit and opens the
-- new one; the role check is done by trg_junkshop_prices_bi.
--   CALL sp_set_junkshop_price(12, 1, 'kg', 420.00);
-- ------------------------------------------------------------
CREATE PROCEDURE sp_set_junkshop_price(
    IN p_buyer_id    INT UNSIGNED,
    IN p_category_id INT UNSIGNED,
    IN p_price_unit  VARCHAR(10),
    IN p_price       DECIMAL(10,2)
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_price_unit NOT IN ('kg','piece','small','medium','large') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid price unit';
    END IF;
    IF p_price IS NULL OR p_price < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Price must be zero or more';
    END IF;

    SET @app_user_id = p_buyer_id;

    START TRANSACTION;
        UPDATE junkshop_material_prices
           SET effective_until = NOW()
         WHERE buyer_id = p_buyer_id
           AND category_id = p_category_id
           AND price_unit = p_price_unit
           AND effective_until IS NULL;

        INSERT INTO junkshop_material_prices
               (buyer_id, category_id, price_unit, buying_price, effective_from)
        VALUES (p_buyer_id, p_category_id, p_price_unit, p_price, NOW());
    COMMIT;
END$$


-- ------------------------------------------------------------
-- A COLLECTOR accepts a pending request.
--   * collector must be active, a 'collector' and 'verified'
--   * only succeeds if the request is STILL pending
--   * blocks double-booking the same date/time
--   * confirmed_schedule = the seller's requested date/time
--   * creates the collection row and notifies the seller [EMAIL]
--   CALL sp_accept_request(55, 7);
-- ------------------------------------------------------------
CREATE PROCEDURE sp_accept_request(
    IN p_request_id   INT UNSIGNED,
    IN p_collector_id INT UNSIGNED
)
BEGIN
    DECLARE v_role       VARCHAR(30);
    DECLARE v_verified   VARCHAR(20);
    DECLARE v_service_area VARCHAR(255);
    DECLARE v_request_barangay VARCHAR(100);
    DECLARE v_request_city VARCHAR(100);
    DECLARE v_schedule   DATETIME DEFAULT NULL;
    DECLARE v_seller     INT UNSIGNED;
    DECLARE v_conflicts  INT DEFAULT 0;

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
               assigned_by           = p_collector_id,
               assigned_at           = NOW(),
               confirmed_schedule    = v_schedule,
               request_status        = 'accepted'
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


-- ------------------------------------------------------------
-- A COLLECTOR declines a pending request (hidden from that collector
-- from then on) or RELEASES one they already accepted (only before any
-- item was recorded; the request returns to 'pending' and the seller
-- is notified [EMAIL]).
--   CALL sp_decline_request(55, 7, 'Out of my area');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_decline_request(
    IN p_request_id   INT UNSIGNED,
    IN p_collector_id INT UNSIGNED,
    IN p_reason       VARCHAR(255)
)
BEGIN
    DECLARE v_status   VARCHAR(30);
    DECLARE v_seller   INT UNSIGNED;
    DECLARE v_assigned INT UNSIGNED;
    DECLARE v_items    INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_collector_id;

    START TRANSACTION;
        SELECT request_status, seller_id, assigned_collector_id
          INTO v_status, v_seller, v_assigned
          FROM collection_requests
         WHERE request_id = p_request_id
           FOR UPDATE;

        IF v_status IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request not found';
        END IF;
        IF v_status NOT IN ('pending','accepted') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This request can no longer be declined';
        END IF;
        IF v_status = 'accepted' AND NOT (v_assigned <=> p_collector_id) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Only the assigned collector can release this request';
        END IF;

        INSERT INTO collection_request_declines (request_id, collector_id, reason)
        VALUES (p_request_id, p_collector_id, p_reason)
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), declined_at = NOW();

        IF v_status = 'accepted' THEN
            SELECT COUNT(*) INTO v_items
              FROM collection_items ci
              JOIN collections c ON c.collection_id = ci.collection_id
             WHERE c.request_id = p_request_id;

            IF v_items > 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Cannot release a request after items were recorded';
            END IF;

            DELETE FROM collections WHERE request_id = p_request_id;

            UPDATE collection_requests
               SET assigned_collector_id = NULL,
                   assigned_by           = NULL,
                   assigned_at           = NULL,
                   confirmed_schedule    = NULL,
                   request_status        = 'pending'
             WHERE request_id = p_request_id;

            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_seller, 'request_released', 'Collector unavailable',
                    'Your collector can no longer make the pickup. Your request is open again for other collectors.',
                    'collection_requests', p_request_id);
        END IF;
    COMMIT;
END$$


-- ------------------------------------------------------------
-- The collector finishes weighing/counting and SUBMITS the real
-- quantities and prices (collection_items rows were inserted first).
-- Values are then locked until the seller decides. Seller is
-- notified [EMAIL].
--   CALL sp_submit_actuals(55, 7);
-- ------------------------------------------------------------
CREATE PROCEDURE sp_submit_actuals(
    IN p_request_id   INT UNSIGNED,
    IN p_collector_id INT UNSIGNED
)
BEGIN
    DECLARE v_collection_id INT UNSIGNED;
    DECLARE v_cstatus       VARCHAR(30);
    DECLARE v_seller        INT UNSIGNED;
    DECLARE v_items         INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_collector_id;

    START TRANSACTION;
        SELECT c.collection_id, c.collection_status, r.seller_id
          INTO v_collection_id, v_cstatus, v_seller
          FROM collections c
          JOIN collection_requests r ON r.request_id = c.request_id
         WHERE c.request_id = p_request_id
           AND c.collector_id = p_collector_id
           FOR UPDATE;

        IF v_collection_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No collection assigned to this collector';
        END IF;
        IF v_cstatus NOT IN ('pending','in_progress','disputed') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Values were already submitted';
        END IF;

        SELECT COUNT(*) INTO v_items
          FROM collection_items WHERE collection_id = v_collection_id;
        IF v_items = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Record at least one item first';
        END IF;

        UPDATE collections
           SET collection_status = 'awaiting_seller_approval',
               confirmed_at      = NOW(),
               collected_at     = IFNULL(collected_at, NOW()),
               dispute_reason    = NULL
         WHERE collection_id = v_collection_id;

        UPDATE collection_requests
           SET request_status = 'awaiting_approval'
         WHERE request_id = p_request_id;

        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_seller, 'values_submitted', 'Please review the final amounts',
                'The collector entered the actual quantities and prices. Please approve or dispute them.',
                'collection_requests', p_request_id);
    COMMIT;
END$$


-- ------------------------------------------------------------
-- The SELLER approves (p_approve = 1) or disputes (p_approve = 0)
-- the collector's confirmed values. Collector is notified [EMAIL].
--   CALL sp_seller_decision(55, 3, 1, NULL);
--   CALL sp_seller_decision(55, 3, 0, 'Weight is lower than I measured');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_seller_decision(
    IN p_request_id INT UNSIGNED,
    IN p_seller_id  INT UNSIGNED,
    IN p_approve    TINYINT,
    IN p_reason     VARCHAR(500)
)
BEGIN
    DECLARE v_status    VARCHAR(30);
    DECLARE v_collector INT UNSIGNED;

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
          INTO v_status, v_collector
          FROM collection_requests
         WHERE request_id = p_request_id AND seller_id = p_seller_id
           FOR UPDATE;

        IF v_status IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Request not found for this seller';
        END IF;
        IF v_status <> 'awaiting_approval' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nothing to approve for this request';
        END IF;
        IF v_collector IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This request has no accepting junkshop';
        END IF;

        IF p_approve = 1 THEN
            UPDATE collections
               SET collection_status  = 'completed',
                   collected_at       = IFNULL(collected_at, NOW()),
                   seller_approved_at = NOW()
             WHERE request_id = p_request_id;

            UPDATE collection_requests
               SET request_status = 'completed'
             WHERE request_id = p_request_id;

            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_collector, 'values_approved', 'Seller approved the amounts',
                    'The seller approved the final quantities and prices.',
                    'collection_requests', p_request_id);
        ELSE
            UPDATE collections
               SET collection_status = 'disputed',
                   dispute_reason    = p_reason
             WHERE request_id = p_request_id;

            UPDATE collection_requests
               SET request_status = 'disputed'
             WHERE request_id = p_request_id;

            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_collector, 'values_disputed', 'Seller disputed the amounts',
                    'The seller disagreed with the final values. Please review and resubmit.',
                    'collection_requests', p_request_id);
        END IF;
    COMMIT;
END$$


-- ============================================================
-- [EMAIL] LOGIN PROCEDURES
-- ============================================================

-- ------------------------------------------------------------
-- [EMAIL] Call AFTER the password was verified. Updates last_login_at,
-- clears failed attempts / lock, records the attempt and creates a
-- 'login_alert' notification (which the mailer e-mails).
-- Behaviour is controlled by system_settings.login_email_mode:
--     always  = e-mail on every login        (default)
--     new_ip  = e-mail only from an IP this user never logged in from
--     off     = never
-- A user can also switch it off with users.login_alert_emails = 0.
--   CALL sp_record_login(3, '203.0.113.5', 'Mozilla/5.0 ...');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_record_login(
    IN p_user_id    INT UNSIGNED,
    IN p_ip         VARCHAR(45),
    IN p_user_agent VARCHAR(255)
)
BEGIN
    DECLARE v_email  VARCHAR(150) DEFAULT NULL;
    DECLARE v_alerts TINYINT DEFAULT 0;
    DECLARE v_prior  INT DEFAULT 0;
    DECLARE v_mode   VARCHAR(20) DEFAULT 'always';

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET @app_user_id = p_user_id;

    SELECT email, login_alert_emails
      INTO v_email, v_alerts
      FROM users
     WHERE user_id = p_user_id AND is_active = 1 AND deleted_at IS NULL;

    IF v_email IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User not found or inactive';
    END IF;

    SELECT setting_value INTO v_mode
      FROM system_settings WHERE setting_key = 'login_email_mode';

    -- has this user logged in successfully from this IP before?
    SELECT COUNT(*) INTO v_prior
      FROM login_attempts
     WHERE user_id = p_user_id AND ip_address = p_ip AND was_successful = 1;

    START TRANSACTION;
        UPDATE users
           SET last_login_at = NOW(),
               failed_login_attempts = 0,
               locked_until = NULL
         WHERE user_id = p_user_id;

        INSERT INTO login_attempts (email, user_id, ip_address, was_successful)
        VALUES (v_email, p_user_id, p_ip, 1);

        IF v_alerts = 1
           AND (v_mode = 'always' OR (v_mode = 'new_ip' AND v_prior = 0)) THEN
            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (p_user_id, 'login_alert',
                    IF(v_prior = 0, 'New login to your Kalakal account',
                                    'You logged in to Kalakal'),
                    LEFT(CONCAT('Login on ', DATE_FORMAT(NOW(), '%b %e, %Y at %l:%i %p'),
                                ' from IP ', p_ip,
                                IF(v_prior = 0, ' (a location we have not seen before)', ''),
                                '. Device: ', LEFT(IFNULL(p_user_agent, 'unknown'), 120),
                                '. If this was not you, reset your password right away.'),
                         500),
                    'users', p_user_id);
        END IF;
    COMMIT;
END$$


-- ------------------------------------------------------------
-- [EMAIL] Call AFTER a wrong password. Records the attempt, counts
-- failures, locks the account when system_settings.max_failed_logins
-- is reached (for lockout_minutes) and e-mails the owner an
-- 'account_locked' warning.
--   CALL sp_record_failed_login('juan@gmail.com', '203.0.113.5');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_record_failed_login(
    IN p_email VARCHAR(150),
    IN p_ip    VARCHAR(45)
)
BEGIN
    DECLARE v_user  INT UNSIGNED DEFAULT NULL;
    DECLARE v_fails INT DEFAULT 0;
    DECLARE v_max   INT DEFAULT 5;
    DECLARE v_lock  INT DEFAULT 15;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SELECT user_id INTO v_user
      FROM users
     WHERE email = p_email AND deleted_at IS NULL
     LIMIT 1;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_max
      FROM system_settings WHERE setting_key = 'max_failed_logins';
    SELECT CAST(setting_value AS UNSIGNED) INTO v_lock
      FROM system_settings WHERE setting_key = 'lockout_minutes';

    START TRANSACTION;
        INSERT INTO login_attempts (email, user_id, ip_address, was_successful)
        VALUES (p_email, v_user, p_ip, 0);

        IF v_user IS NOT NULL THEN
            UPDATE users
               SET failed_login_attempts = LEAST(failed_login_attempts + 1, 255)
             WHERE user_id = v_user;

            SELECT failed_login_attempts INTO v_fails
              FROM users WHERE user_id = v_user;

            IF v_fails >= v_max THEN
                UPDATE users
                   SET locked_until = NOW() + INTERVAL v_lock MINUTE,
                       failed_login_attempts = 0
                 WHERE user_id = v_user;

                INSERT INTO notifications
                       (user_id, notification_type, title, message, related_table, related_id)
                VALUES (v_user, 'account_locked', 'Your account was temporarily locked',
                        CONCAT('There were too many failed login attempts (last IP: ', p_ip,
                               '). Your account is locked for ', v_lock,
                               ' minutes. If this was not you, reset your password.'),
                        'users', v_user);
            END IF;
        END IF;
    COMMIT;
END$$


-- ============================================================
-- [EMAIL] MAILER QUEUE PROCEDURES  (used by the PHP mailer script)
-- ============================================================

-- ------------------------------------------------------------
-- [EMAIL] Step 1. Claims up to p_limit pending e-mails for ONE mailer
-- run, so two overlapping cron runs never send the same e-mail twice.
-- p_batch = any unique id you generate in PHP (uuid, 36 chars).
--   * rows stuck in 'sending' for over 30 minutes are retried
--   * rows of users who opted out / were deactivated become 'skipped'
--   CALL sp_claim_pending_emails(20, '3f2b8c1e-....');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_claim_pending_emails(
    IN p_limit INT,
    IN p_batch CHAR(36)
)
BEGIN
    DECLARE v_max INT DEFAULT 3;
    DECLARE v_limit INT DEFAULT 20;

    IF p_limit IS NULL OR p_limit < 1 OR p_batch IS NULL OR TRIM(p_batch) = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid email batch request';
    END IF;
    SELECT CAST(setting_value AS UNSIGNED) INTO v_max
      FROM system_settings WHERE setting_key = 'email_max_attempts';
    SET v_limit = LEAST(p_limit, 100);

    -- recover abandoned claims (mailer crashed)
    UPDATE notifications
       SET email_status = 'pending', email_batch = NULL, email_claimed_at = NULL
     WHERE email_status = 'sending'
       AND email_claimed_at < NOW() - INTERVAL 30 MINUTE;

    -- users who turned e-mails off after the row was created
    UPDATE notifications
       SET email_status = 'skipped', email_batch = NULL, email_claimed_at = NULL
     WHERE email_status = 'pending'
       AND NOT EXISTS (SELECT 1 FROM users u
                        WHERE u.user_id = notifications.user_id
                          AND u.is_active = 1
                          AND u.deleted_at IS NULL
                          AND u.email_notifications = 1);

    -- claim the oldest pending rows
    UPDATE notifications
       SET email_status     = 'sending',
           email_batch      = p_batch,
           email_claimed_at = NOW()
     WHERE email_status = 'pending'
       AND email_attempts < v_max
     ORDER BY notification_id
     LIMIT v_limit;

    SELECT ROW_COUNT() AS claimed;
END$$


-- ------------------------------------------------------------
-- [EMAIL] Step 2a. The e-mail was handed to the SMTP server.
--   CALL sp_mark_email_sent(123, '3f2b8c1e-....');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_mark_email_sent(
    IN p_notification_id INT UNSIGNED,
    IN p_batch CHAR(36)
)
BEGIN
    UPDATE notifications
       SET email_status   = 'sent',
           email_attempts = email_attempts + 1,
           email_sent_at  = NOW(),
           email_error    = NULL,
           email_batch    = NULL,
           email_claimed_at = NULL
     WHERE notification_id = p_notification_id
       AND email_status = 'sending'
       AND email_batch = p_batch;
    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email delivery claim expired or belongs to another batch';
    END IF;
END$$


-- ------------------------------------------------------------
-- [EMAIL] Step 2b. Sending failed. The row goes back to 'pending' for a
-- retry until email_max_attempts is reached, then becomes 'failed'.
--   CALL sp_mark_email_failed(123, '3f2b8c1e-....', 'SMTP connect() failed');
-- ------------------------------------------------------------
CREATE PROCEDURE sp_mark_email_failed(
    IN p_notification_id INT UNSIGNED,
    IN p_batch           CHAR(36),
    IN p_error           VARCHAR(255)
)
BEGIN
    DECLARE v_max INT DEFAULT 3;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_max
      FROM system_settings WHERE setting_key = 'email_max_attempts';

    -- NOTE: email_status must be assigned BEFORE email_attempts, because MySQL
    -- evaluates SET assignments left to right using already-updated values.
    UPDATE notifications
       SET email_status   = IF(email_attempts + 1 >= v_max, 'failed', 'pending'),
           email_attempts = email_attempts + 1,
           email_error    = LEFT(p_error, 255),
       email_batch    = NULL,
       email_claimed_at = NULL
     WHERE notification_id = p_notification_id
       AND email_status = 'sending'
       AND email_batch = p_batch;
    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email delivery claim expired or belongs to another batch';
    END IF;
END$$


-- ============================================================
-- ROLE VALIDATION + STATUS RULES
-- ============================================================
CREATE TRIGGER trg_requests_bi BEFORE INSERT ON collection_requests
FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30);
    SELECT user_role INTO v_role FROM users WHERE user_id = NEW.seller_id;
    IF v_role IS NULL OR v_role <> 'seller' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Request owner must be a seller';
    END IF;
END$$

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

CREATE TRIGGER trg_trades_bi BEFORE INSERT ON trades
FOR EACH ROW
BEGIN
    DECLARE v_seller_role VARCHAR(30);
    DECLARE v_buyer_role  VARCHAR(30);
    SELECT user_role INTO v_seller_role FROM users WHERE user_id = NEW.seller_id;
    SELECT user_role INTO v_buyer_role  FROM users WHERE user_id = NEW.buyer_id;
    IF v_buyer_role IS NULL OR v_buyer_role <> 'buyer' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'buyer_id must belong to a buyer';
    END IF;
    IF v_seller_role IS NULL OR v_seller_role <> 'seller' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'seller_id must belong to a seller';
    END IF;
END$$

-- only junkshops (role 'buyer') can own junkshop prices
CREATE TRIGGER trg_junkshop_prices_bi BEFORE INSERT ON junkshop_material_prices
FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30);
    SELECT user_role INTO v_role FROM users WHERE user_id = NEW.buyer_id;
    IF v_role IS NULL OR v_role <> 'buyer' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only a junkshop (buyer) can set junkshop prices';
    END IF;
END$$


-- ============================================================
-- [EMAIL] NOTIFICATIONS: skip e-mail for opted-out / inactive users
-- ============================================================
CREATE TRIGGER trg_notifications_bi BEFORE INSERT ON notifications
FOR EACH ROW
BEGIN
    DECLARE v_ok INT DEFAULT 0;
    SELECT COUNT(*) INTO v_ok
      FROM users
     WHERE user_id = NEW.user_id
       AND is_active = 1
       AND deleted_at IS NULL
       AND email_notifications = 1;
    IF v_ok = 0 THEN
        SET NEW.email_status = 'skipped';
    END IF;
END$$


-- ------------------------------------------------------------
-- COLLECTION ITEMS: price snapshot, stock init, locking, audit
-- ------------------------------------------------------------
CREATE TRIGGER trg_collection_items_bi BEFORE INSERT ON collection_items
FOR EACH ROW
BEGIN
    DECLARE v_price   DECIMAL(10,2);
    DECLARE v_cstatus VARCHAR(30);

    SELECT collection_status INTO v_cstatus
      FROM collections WHERE collection_id = NEW.collection_id;
    IF v_cstatus IS NULL OR v_cstatus NOT IN ('pending','in_progress','disputed') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Items can only be recorded while the collection is open';
    END IF;

    -- collector did not type a price: fall back to the kg reference price
    IF NEW.unit_price = 0 AND NEW.price_id IS NOT NULL AND NEW.quantity_unit = 'kg' THEN
        SELECT buying_price INTO v_price
          FROM material_prices WHERE price_id = NEW.price_id;
        SET NEW.unit_price = IFNULL(v_price, 0.00);
    END IF;

    SET NEW.remaining_quantity = NEW.actual_quantity;
END$$

CREATE TRIGGER trg_collection_items_ai AFTER INSERT ON collection_items
FOR EACH ROW
BEGIN
    -- first recorded item moves the collection to 'in_progress'
    UPDATE collections
       SET collection_status = 'in_progress'
     WHERE collection_id = NEW.collection_id
       AND collection_status = 'pending';

    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values)
    VALUES (@app_user_id, 'INSERT', 'collection_items', NEW.collection_item_id,
            JSON_OBJECT('category_id', NEW.category_id,
                        'quantity_unit', NEW.quantity_unit,
                        'actual_quantity', NEW.actual_quantity,
                        'unit_price', NEW.unit_price));
END$$

CREATE TRIGGER trg_collection_items_bu BEFORE UPDATE ON collection_items
FOR EACH ROW
BEGIN
    DECLARE v_cstatus VARCHAR(30);

    SELECT collection_status INTO v_cstatus
      FROM collections WHERE collection_id = OLD.collection_id;

    -- once submitted / approved, confirmed values are locked
    -- (only remaining_quantity may change, via trades)
    IF (NOT (OLD.actual_quantity <=> NEW.actual_quantity)
        OR NOT (OLD.unit_price <=> NEW.unit_price)
        OR NOT (OLD.quantity_unit <=> NEW.quantity_unit)
        OR OLD.category_id <> NEW.category_id)
       AND v_cstatus NOT IN ('pending','in_progress','disputed') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Confirmed quantities and prices are locked';
    END IF;

    IF NOT (OLD.actual_quantity <=> NEW.actual_quantity) THEN
        SET NEW.remaining_quantity = NEW.actual_quantity;
    END IF;
END$$

CREATE TRIGGER trg_collection_items_au AFTER UPDATE ON collection_items
FOR EACH ROW
BEGIN
    IF NOT (OLD.actual_quantity <=> NEW.actual_quantity)
       OR NOT (OLD.unit_price <=> NEW.unit_price) THEN
        INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values)
        VALUES (@app_user_id, 'UPDATE', 'collection_items', NEW.collection_item_id,
                JSON_OBJECT('actual_quantity', OLD.actual_quantity,
                            'unit_price', OLD.unit_price),
                JSON_OBJECT('actual_quantity', NEW.actual_quantity,
                            'unit_price', NEW.unit_price));
    END IF;
END$$

CREATE TRIGGER trg_collection_items_bd BEFORE DELETE ON collection_items
FOR EACH ROW
BEGIN
    DECLARE v_cstatus VARCHAR(30);
    SELECT collection_status INTO v_cstatus
      FROM collections WHERE collection_id = OLD.collection_id;
    IF v_cstatus NOT IN ('pending','in_progress','disputed') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Items cannot be removed after the values were submitted';
    END IF;
END$$


-- ------------------------------------------------------------
-- TRADE ITEMS: block over-selling and keep stock in sync
-- ------------------------------------------------------------
CREATE TRIGGER trg_trade_items_bi BEFORE INSERT ON trade_items
FOR EACH ROW
BEGIN
    DECLARE v_remaining DECIMAL(12,3);
    DECLARE v_col_status VARCHAR(30);
    DECLARE v_trade_status VARCHAR(30);

    SELECT trade_status INTO v_trade_status
      FROM trades WHERE trade_id = NEW.trade_id;
    IF v_trade_status IS NULL OR v_trade_status <> 'pending' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Items can only be added to a pending trade';
    END IF;

    SELECT ci.remaining_quantity, col.collection_status
      INTO v_remaining, v_col_status
      FROM collection_items ci
      JOIN collections col ON col.collection_id = ci.collection_id
     WHERE ci.collection_item_id = NEW.collection_item_id;

    IF v_col_status IS NULL OR v_col_status <> 'completed' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only items from completed collections can be traded';
    END IF;

    IF NEW.quantity > v_remaining THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Quantity exceeds available stock';
    END IF;
END$$

CREATE TRIGGER trg_trade_items_ai AFTER INSERT ON trade_items
FOR EACH ROW
BEGIN
    UPDATE collection_items
       SET remaining_quantity = remaining_quantity - NEW.quantity
     WHERE collection_item_id = NEW.collection_item_id;
END$$

CREATE TRIGGER trg_trade_items_ad AFTER DELETE ON trade_items
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(30);
    SELECT trade_status INTO v_status FROM trades WHERE trade_id = OLD.trade_id;
    -- cancelled/rejected trades were already restored by trg_trades_au
    IF v_status NOT IN ('cancelled','rejected') THEN
        UPDATE collection_items
           SET remaining_quantity = remaining_quantity + OLD.quantity
         WHERE collection_item_id = OLD.collection_item_id;
    END IF;
END$$


-- ------------------------------------------------------------
-- TRADES: cancelled/rejected are final; restore stock; log history
-- [EMAIL] new trade -> notify the buyer (junkshop);
--         status change -> notify the other party (not the actor)
-- ------------------------------------------------------------
CREATE TRIGGER trg_trades_bu BEFORE UPDATE ON trades
FOR EACH ROW
BEGIN
    IF OLD.trade_status IN ('cancelled','rejected')
       AND NEW.trade_status <> OLD.trade_status THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cancelled or rejected trades cannot be reopened';
    END IF;
END$$

CREATE TRIGGER trg_trades_ai_notify AFTER INSERT ON trades
FOR EACH ROW
BEGIN
    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    VALUES (NEW.buyer_id, 'trade_new', 'New trade offer',
            CONCAT('You received a new trade offer (Trade #', NEW.trade_id,
                   '). Please review and accept or reject it.'),
            'trades', NEW.trade_id);
END$$

CREATE TRIGGER trg_trades_au AFTER UPDATE ON trades
FOR EACH ROW
BEGIN
    IF NOT (OLD.trade_status <=> NEW.trade_status) THEN
        INSERT INTO status_history
            (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES
            ('trade', NEW.trade_id, OLD.trade_status, NEW.trade_status, @app_user_id);

        IF NEW.trade_status IN ('cancelled','rejected') THEN
            UPDATE collection_items ci
              JOIN trade_items ti
                ON ti.collection_item_id = ci.collection_item_id
               SET ci.remaining_quantity = ci.remaining_quantity + ti.quantity
             WHERE ti.trade_id = NEW.trade_id;
        END IF;

        -- [EMAIL] tell the party who did NOT make the change
        IF NEW.seller_id <> IFNULL(@app_user_id, 0) THEN
            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (NEW.seller_id, 'trade_updated', 'Trade update',
                    CONCAT('Trade #', NEW.trade_id, ' is now ', NEW.trade_status, '.'),
                    'trades', NEW.trade_id);
        END IF;
        IF NEW.buyer_id <> IFNULL(@app_user_id, 0) THEN
            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (NEW.buyer_id, 'trade_updated', 'Trade update',
                    CONCAT('Trade #', NEW.trade_id, ' is now ', NEW.trade_status, '.'),
                    'trades', NEW.trade_id);
        END IF;
    END IF;
END$$


-- ------------------------------------------------------------
-- COLLECTION REQUESTS
-- [EMAIL] new request  -> confirmation to the seller AND an alert to the
--                         verified, available collector of that barangay/city
--                         (only barangay + city are mentioned: privacy)
-- [EMAIL] cancelled    -> assigned collector or eligible area collectors are told
-- [EMAIL] rejected     -> seller is told
-- ------------------------------------------------------------
CREATE TRIGGER trg_requests_ai_notify AFTER INSERT ON collection_requests
FOR EACH ROW
BEGIN
    -- confirmation for the seller
    INSERT INTO notifications
           (user_id, notification_type, title, message, related_table, related_id)
    VALUES (NEW.seller_id, 'request_submitted', 'Pickup request received',
            CONCAT('Your pickup request for ',
                   DATE_FORMAT(NEW.preferred_schedule, '%b %e, %Y at %l:%i %p'),
                   ' was submitted. We will tell you when a collector accepts it.'),
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
END$$

CREATE TRIGGER trg_requests_au AFTER UPDATE ON collection_requests
FOR EACH ROW
BEGIN
    IF NOT (OLD.request_status <=> NEW.request_status) THEN
        INSERT INTO status_history
            (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES
            ('collection_request', NEW.request_id,
             OLD.request_status, NEW.request_status, @app_user_id);

        -- [EMAIL] notify the assigned collector, or matching collectors who
        -- were eligible to see an unassigned request before it was cancelled.
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

CREATE TRIGGER trg_request_declines_ai_notify AFTER INSERT ON collection_request_declines
FOR EACH ROW
BEGIN
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;
    DECLARE v_status VARCHAR(30) DEFAULT NULL;

    SELECT seller_id, request_status
      INTO v_seller, v_status
      FROM collection_requests
     WHERE request_id = NEW.request_id;

    IF v_status = 'pending' THEN
        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (v_seller, 'request_declined', 'A collector declined your pickup request',
                CONCAT('A collector declined pickup request #', NEW.request_id,
                       '. Your request remains open for other eligible collectors.'),
                'collection_requests', NEW.request_id);
    END IF;
END$$

CREATE TRIGGER trg_requests_au_completed_notify AFTER UPDATE ON collection_requests
FOR EACH ROW
BEGIN
    IF OLD.request_status <> 'completed' AND NEW.request_status = 'completed' THEN
        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (NEW.seller_id, 'request_completed', 'Pickup successfully completed',
                CONCAT('You approved the final amounts for pickup request #', NEW.request_id,
                       '. The collection is complete.'),
                'collection_requests', NEW.request_id);
    END IF;
END$$

CREATE TRIGGER trg_collections_au AFTER UPDATE ON collections
FOR EACH ROW
BEGIN
    IF NOT (OLD.collection_status <=> NEW.collection_status) THEN
        INSERT INTO status_history
            (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES
            ('collection', NEW.collection_id,
             OLD.collection_status, NEW.collection_status, @app_user_id);
    END IF;
END$$


-- ------------------------------------------------------------
-- PAYMENTS
-- [EMAIL] paid -> seller is told "payment received";
--         failed / refunded -> both seller and buyer are told
-- ------------------------------------------------------------
CREATE TRIGGER trg_payments_ai_notify AFTER INSERT ON transaction_payments
FOR EACH ROW
BEGIN
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;

    IF NEW.payment_status = 'paid' THEN
        SELECT seller_id INTO v_seller FROM trades WHERE trade_id = NEW.trade_id;
        IF v_seller IS NOT NULL THEN
            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_seller, 'payment_received', 'Payment received',
                    CONCAT('A payment of PHP ', FORMAT(NEW.amount_paid, 2),
                           ' was recorded for Trade #', NEW.trade_id, '.'),
                    'transaction_payments', NEW.payment_id);
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_payments_au AFTER UPDATE ON transaction_payments
FOR EACH ROW
BEGIN
    DECLARE v_seller INT UNSIGNED DEFAULT NULL;
    DECLARE v_buyer  INT UNSIGNED DEFAULT NULL;

    IF NOT (OLD.payment_status <=> NEW.payment_status) THEN
        INSERT INTO status_history
            (entity_type, entity_id, old_status, new_status, changed_by)
        VALUES
            ('payment', NEW.payment_id,
             OLD.payment_status, NEW.payment_status, @app_user_id);

        SELECT seller_id, buyer_id INTO v_seller, v_buyer
          FROM trades WHERE trade_id = NEW.trade_id;

        IF NEW.payment_status = 'paid' AND v_seller IS NOT NULL THEN
            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_seller, 'payment_received', 'Payment received',
                    CONCAT('A payment of PHP ', FORMAT(NEW.amount_paid, 2),
                           ' was confirmed for Trade #', NEW.trade_id, '.'),
                    'transaction_payments', NEW.payment_id);
        ELSEIF NEW.payment_status IN ('failed','refunded') AND v_seller IS NOT NULL THEN
            INSERT INTO notifications
                   (user_id, notification_type, title, message, related_table, related_id)
            VALUES (v_seller, CONCAT('payment_', NEW.payment_status),
                    CONCAT('Payment ', NEW.payment_status),
                    CONCAT('A payment of PHP ', FORMAT(NEW.amount_paid, 2),
                           ' for Trade #', NEW.trade_id, ' is now ', NEW.payment_status, '.'),
                    'transaction_payments', NEW.payment_id),
                   (v_buyer, CONCAT('payment_', NEW.payment_status),
                    CONCAT('Payment ', NEW.payment_status),
                    CONCAT('A payment of PHP ', FORMAT(NEW.amount_paid, 2),
                           ' for Trade #', NEW.trade_id, ' is now ', NEW.payment_status, '.'),
                    'transaction_payments', NEW.payment_id);
        END IF;
    END IF;
END$$


-- ------------------------------------------------------------
-- [EMAIL] ACCOUNT VERIFICATION RESULT (admin approves / rejects)
-- ------------------------------------------------------------
CREATE TRIGGER trg_collector_profiles_au AFTER UPDATE ON collector_profiles
FOR EACH ROW
BEGIN
    IF NOT (OLD.verification_status <=> NEW.verification_status)
       AND NEW.verification_status IN ('verified','rejected') THEN
        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (NEW.user_id, CONCAT('account_', NEW.verification_status),
                IF(NEW.verification_status = 'verified',
                   'Collector account verified', 'Collector verification rejected'),
                IF(NEW.verification_status = 'verified',
                   'Your collector account was approved. You can now accept pickup requests.',
                   'Your collector verification was rejected. Please review your details and ID, then contact support.'),
                'collector_profiles', NEW.user_id);
    END IF;
END$$

CREATE TRIGGER trg_buyer_profiles_au AFTER UPDATE ON buyer_profiles
FOR EACH ROW
BEGIN
    IF NOT (OLD.verification_status <=> NEW.verification_status)
       AND NEW.verification_status IN ('verified','rejected') THEN
        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (NEW.user_id, CONCAT('account_', NEW.verification_status),
                IF(NEW.verification_status = 'verified',
                   'Junkshop account verified', 'Junkshop verification rejected'),
                IF(NEW.verification_status = 'verified',
                   'Your junkshop account was approved. Your prices are now visible to sellers.',
                   'Your junkshop verification was rejected. Please review your business details, then contact support.'),
                'buyer_profiles', NEW.user_id);
    END IF;
END$$

CREATE TRIGGER trg_establishment_profiles_au AFTER UPDATE ON establishment_profiles
FOR EACH ROW
BEGIN
    IF NOT (OLD.verification_status <=> NEW.verification_status)
       AND NEW.verification_status IN ('verified','rejected') THEN
        INSERT INTO notifications
               (user_id, notification_type, title, message, related_table, related_id)
        VALUES (NEW.user_id, CONCAT('account_', NEW.verification_status),
                IF(NEW.verification_status = 'verified',
                   'Establishment account verified', 'Establishment verification rejected'),
                IF(NEW.verification_status = 'verified',
                   'Your establishment account was approved.',
                   'Your establishment verification was rejected. Please review your business details, then contact support.'),
                'establishment_profiles', NEW.user_id);
    END IF;
END$$


-- ------------------------------------------------------------
-- AUDIT: every new price record (platform + junkshop) is logged
-- ------------------------------------------------------------
CREATE TRIGGER trg_material_prices_ai AFTER INSERT ON material_prices
FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values)
    VALUES (@app_user_id, 'INSERT', 'material_prices', NEW.price_id,
            JSON_OBJECT('category_id', NEW.category_id,
                        'buying_price', NEW.buying_price,
                        'unit', NEW.unit));
END$$

CREATE TRIGGER trg_junkshop_prices_ai AFTER INSERT ON junkshop_material_prices
FOR EACH ROW
BEGIN
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values)
    VALUES (@app_user_id, 'INSERT', 'junkshop_material_prices', NEW.offer_id,
            JSON_OBJECT('buyer_id', NEW.buyer_id,
                        'category_id', NEW.category_id,
                        'price_unit', NEW.price_unit,
                        'buying_price', NEW.buying_price));
END$$

DELIMITER ;



-- ############################################################
-- PART H2: v3.2 OWNER / JUNKSHOP WORKFLOW
--   (same statements as database/migrations/20261113_owner_buyer_workflow.sql)
-- ############################################################
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

-- ---------- 7. Junkshop comparison + pickup charge (v3.3) ----------
-- charge = pickup_charge_base + pickup_charge_per_km x distance_km (junkshops outside the owner's city)
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
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid collection request status transition';
    END IF;

    IF NEW.request_status = 'buyer_proposed' AND NEW.accepted_buyer_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'A buyer proposal requires a buyer';
    END IF;
    IF NEW.request_status IN ('accepted','awaiting_approval','disputed','completed')
       AND NEW.assigned_collector_id IS NULL AND NEW.accepted_buyer_id IS NULL THEN
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
                SET MESSAGE_TEXT = 'Accepting user must be a junkshop';
        END IF;
    END IF;
END$$

-- ---------- 5. Junkshop proposes a collection -----------------------
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

    -- alert every junkshop for requests that are open to all buyers
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
                -- the one junkshop chosen by the owner, or every buyer for an open request
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

-- PRIVATE MESSAGES
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
    KEY idx_messages_thread_id (thread_id, message_id),
    KEY idx_messages_sender (sender_id),
    CONSTRAINT fk_messages_thread
        FOREIGN KEY (thread_id) REFERENCES message_threads(thread_id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender
        FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_message_access_logs (
    access_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id      INT UNSIGNED NOT NULL,
    thread_id     BIGINT UNSIGNED NOT NULL,
    action        ENUM('opened','sent') NOT NULL,
    ip_address    VARCHAR(45) NULL,
    accessed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (access_log_id),
    KEY idx_admin_message_access_admin_time (admin_id, accessed_at),
    KEY idx_admin_message_access_thread_time (thread_id, accessed_at),
    CONSTRAINT fk_admin_message_access_admin
        FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_admin_message_access_thread
        FOREIGN KEY (thread_id) REFERENCES message_threads(thread_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ############################################################
-- PART I: INITIAL DATA
-- ############################################################

INSERT INTO material_categories (category_name, description) VALUES
    ('Cardboard', 'Used cardboard and carton materials'),
    ('Bottles',   'Recyclable bottles'),
    ('Cans',      'Aluminum and metal cans'),
    ('Paper',     'Used paper, newspapers, and magazines'),
    ('Plastic',   'Recyclable plastic materials'),
    ('Glass',     'Recyclable glass materials'),
    ('Metal',     'Other recyclable metal materials');

-- SAMPLE prices only (PHP per kg). Verify against local junk-shop rates.
-- Later changes must use:  CALL sp_set_material_price(category_id, price, 'kg');
INSERT INTO material_prices (category_id, buying_price, unit)
SELECT category_id,
       CASE category_name
            WHEN 'Cardboard' THEN 5.00
            WHEN 'Bottles'   THEN 2.00
            WHEN 'Cans'      THEN 65.00
            WHEN 'Paper'     THEN 8.00
            WHEN 'Plastic'   THEN 5.00
            WHEN 'Glass'     THEN 1.00
            WHEN 'Metal'     THEN 30.00
       END,
       'kg'
FROM material_categories;

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('max_failed_logins',        '5',      'Failed logins before the account is locked'),
    ('lockout_minutes',          '15',     'Minutes an account stays locked'),
    ('password_reset_minutes',   '30',     'Minutes a password reset link stays valid'),
    ('session_lifetime_minutes', '120',    'Session lifetime in minutes'),
    ('platform_fee_percent',     '0',      'Commission charged per trade (percent)'),
    ('currency',                 'PHP',    'Currency code'),
    ('max_upload_mb',            '5',      'Maximum upload size in MB'),
    ('email_max_attempts',       '3',      'Send attempts per notification e-mail before it is marked failed'),
    ('login_email_mode',         'always', 'Login alert e-mails: always | new_ip | off'),
    ('email_from_name',          'Kalakal','Sender name shown in notification e-mails');

-- FIRST ADMIN: create it from PHP so the password is hashed properly, e.g.
--   $hash = password_hash('YourStrongPassword', PASSWORD_DEFAULT);
--   INSERT INTO users (first_name,last_name,email,password_hash,user_role,is_active)
--   VALUES ('System','Admin','admin@kalakal.local',:hash,'admin',1);
--
-- JUNKSHOP PRICES: junkshops set them from PHP with
--   SET @app_user_id = :junkshop_user_id;
--   CALL sp_set_junkshop_price(:junkshop_user_id, :category_id, 'kg', 420.00);
--
-- Buyer accounts represent junkshops. Junkshop staff collect items outside
-- the system; the buyer account proposes schedules/payment and records actuals.
--
-- E-MAIL QUICK TEST (after creating users): 
--   INSERT INTO notifications (user_id, notification_type, title, message)
--   VALUES (1, 'test', 'Test e-mail', 'If you can read this, e-mail works.');
--   then run your mailer script and check the inbox.


-- ############################################################
-- PHASE 2: COLLECTION PAYMENT AND STATUS INTEGRITY
-- ############################################################
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

-- ############################################################
-- VERIFICATION
-- ############################################################
SHOW TABLES;

-- ============================================================
-- END OF KALAKAL DATABASE (MySQL / MariaDB)
-- ============================================================