# Email notifications

KALAKAL stores each notification in the `notifications` table. Database
triggers and procedures create notifications for login alerts, pickup requests
and status changes, trades, and payments. The existing `smtp_send_message()`
transport uses PHPMailer for password recovery and queued notifications.
Account email verification and administrator email-code MFA have been
disabled by request.

## Configure Gmail SMTP

1. For Gmail, enable 2-Step Verification on the account that will send
   notifications, then create a Google App Password. Use the App Password,
   not your normal Gmail password. Google may not offer App Passwords for
   Workspace accounts or accounts with Advanced Protection.
2. Set the following environment variables for the Windows account running
   Apache. If Apache runs as a Windows service, use System variables. Never
   put the App Password in PHP, JavaScript, an HTTP-accessible file, or source
   control:

   ```text
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_ENCRYPTION=tls
   SMTP_USERNAME=your-sender@gmail.com
   SMTP_PASSWORD=your-gmail-app-password
   MAIL_FROM=your-sender@gmail.com
   MAIL_FROM_NAME=KALAKAL System
   ```

   The supported values are `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`,
   `SMTP_USERNAME`, `SMTP_PASSWORD`, `MAIL_FROM`, and `MAIL_FROM_NAME`.
   `MAIL_FROM_NAME` defaults to `KALAKAL System`; `MAIL_FROM` defaults to the
   SMTP username. For compatibility, the previous `KALAKAL_MAIL_SMTP_*`,
   `KALAKAL_MAIL_FROM_ADDRESS`, and `KALAKAL_MAIL_FROM_NAME` names remain
   supported. Gmail requires the sender to be the authenticated account or an
   authorized alias.
3. In Windows, open **System Properties → Advanced → Environment Variables**,
   add the values above as User variables (or System variables if Apache runs
   as a service), then fully stop and restart Apache in the XAMPP Control
   Panel. Open a new PowerShell window after changing User variables so the
   PHP CLI and scheduled-task processes can inherit them as well.
4. From the project directory, install the locked dependency:

   ```powershell
   cd C:\xampp\htdocs\kalakal
   C:\xampp\php\php.exe C:\xampp\php\composer.phar install --no-dev --prefer-dist
   ```

   The dependency is declared in `composer.json` and pinned in
   `composer.lock`. PHPMailer uses authenticated SMTP with STARTTLS on port
   587 and certificate verification enabled. SMTP debugging is disabled.
5. For an existing database, back it up and apply
   `database/migrations/20261118_email_notification_queue.sql`. It adds any
   missing queue columns, indexes, view, settings, and retry procedures without
   replacing notifications. A fresh install already has this schema in
   `database/kalakal_mysql.sql`. Do not re-import that installer into an
   existing database; it drops and recreates the database.

## Start the mailer

Run the mailer once from PowerShell to attempt delivery of queued messages:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\kalakal\send_notification_emails.php
```

For ongoing delivery, create a Windows Task Scheduler task that runs this
command every minute. The task must use an account with access to the project,
the SMTP environment variables, and the database. The script processes up to
20 emails per run; failed sends are retried up to the configured
`email_max_attempts` value. Queue claims are tied to a per-run batch token;
stale claims are recovered after 30 minutes, and a different mailer run cannot
mark another batch's message as sent or failed. A notification is marked sent
only after the SMTP server accepts the message.

## Send a test email

The test script is CLI-only and sends one message to a mailbox you control.
Set the recipient in the current PowerShell session, then run:

```powershell
$env:KALAKAL_SMTP_TEST_TO = 'your-recipient@example.com'
C:\xampp\php\php.exe C:\xampp\htdocs\kalakal\tests\send_test_email.php
```

The script reports SMTP acceptance, not final inbox delivery. Check the
recipient mailbox and spam folder before considering delivery verified. The
test script does not display or accept SMTP credentials as arguments.
To verify configuration, PHPMailer loading, and credential redaction without
connecting to Gmail or sending a message, run:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\kalakal\tests\smtp_config_smoke.php
```

Successful logins create an email-only login alert according to the
`login_email_mode` setting (`always`, `new_ip`, or `off`) and the user's
`login_alert_emails` preference. These alerts are not shown in the KALAKAL
dashboard. Other email notifications also honor the user's
`email_notifications` preference. KALAKAL never needs or requests a user's
Gmail password; the server's SMTP credentials deliver alerts to the email
address registered on the user's KALAKAL account.

All roles, including administrators, sign in with their account password;
email verification and administrator email-code MFA are disabled. Password
recovery and queued notification email still depend on this SMTP configuration.
For SMTP failures, check the latest Apache error log at
`C:\xampp\apache\logs\error.log`. `SMTP settings are incomplete or invalid`
means required variables are missing; connection or authentication errors
require checking the host, port, encryption, network access, or App Password.

SMTP errors are logged without SMTP username/password values. Do not enable
PHPMailer debug output on a public or shared server.

The database retry and batch-ownership behavior can be checked on a disposable
database with `tests/email_queue_smoke.php`. This test simulates SMTP failures;
it does not verify connectivity or delivery to a real mailbox.
