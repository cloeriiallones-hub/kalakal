<?php
declare(strict_types=1);

// ---- Site settings --------------------------------------------------
define('BASE_URL', '/kalakal');          // = folder name inside C:\xampp\htdocs
define('APP_ORIGIN', rtrim(getenv('KALAKAL_APP_ORIGIN') ?: 'http://localhost', '/'));
define('APP_NAME', 'KALAKAL');
define('APP_TIMEZONE', 'Asia/Manila');
define('DB_TIME_OFFSET', '+08:00');

// ---- Database settings (XAMPP defaults) -----------------------------
define('DB_HOST', getenv('KALAKAL_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('KALAKAL_DB_NAME') ?: 'kalakal');
define('DB_USER', getenv('KALAKAL_DB_USER') ?: 'root');
define('DB_PASS', getenv('KALAKAL_DB_PASS') ?: ''); // XAMPP root has no password by default

// ---- E-mail settings -------------------------------------------------
define('MAIL_SMTP_HOST', getenv('SMTP_HOST') ?: (getenv('KALAKAL_MAIL_SMTP_HOST') ?: 'smtp.gmail.com'));
define('MAIL_SMTP_PORT', (int) (getenv('SMTP_PORT') ?: (getenv('KALAKAL_MAIL_SMTP_PORT') ?: 587)));
define('MAIL_SMTP_ENCRYPTION', strtolower(getenv('SMTP_ENCRYPTION') ?: (getenv('KALAKAL_MAIL_SMTP_ENCRYPTION') ?: 'tls')));
define('MAIL_SMTP_USERNAME', getenv('SMTP_USERNAME') ?: (getenv('KALAKAL_MAIL_SMTP_USERNAME') ?: ''));
define('MAIL_SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: (getenv('KALAKAL_MAIL_SMTP_PASSWORD') ?: ''));
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM') ?: (getenv('KALAKAL_MAIL_FROM_ADDRESS') ?: MAIL_SMTP_USERNAME));
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: (getenv('KALAKAL_MAIL_FROM_NAME') ?: APP_NAME));

// ---- Security settings ----------------------------------------------
define('LOCKOUT_MINUTES', 15);

/**
 * One shared PDO connection for the whole request.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            http_response_code(500);
            exit('Database connection failed. Is MySQL running in XAMPP, and did you import the database?');
        }

        $pdo->exec("SET time_zone = '" . DB_TIME_OFFSET . "'");

        // Lets the DB triggers (status_history / audit_logs) know who is acting
        if (!empty($_SESSION['user_id'])) {
            $pdo->exec('SET @app_user_id = ' . (int) $_SESSION['user_id']);
        }
    }
    return $pdo;
}
