# Account deletion

Requesting deletion immediately deactivates the account, revokes its sessions,
cancels unfinished collection requests and eligible unpaid trades, and notifies
the other parties. The owner can restore the account by signing in with the
current password within 24 hours.

After that recovery window, `task_runner.php` anonymizes eligible accounts when
the administrator has scheduled the task. It retains collection and trade
records, but replaces the account name and email, removes contact and profile
details and saved addresses, revokes remaining sessions, invalidates
authentication tokens, detaches uploaded-file ownership, and removes the
account's e-wallet QR file. Historical collection request snapshots are not
rewritten. Login-attempt records are de-identified, authentication request
records for the old email are removed, and session/request IP and user-agent
details are cleared. Messages and audit records remain associated with the
retained numeric user ID; their free text is not rewritten.

Finalization is irreversible. Back up the database before enabling the task.
The runner processes at most 100 accounts per run by default; `--limit=N` may
be used for values from 1 to 1000.

## Database update

For an existing database, back it up first. Apply
`database/migrations/20261108_account_deletion.sql` only if
`users.deletion_requested_at` is missing. Apply
`database/migrations/20261114_authentication_lifecycle.sql` before deploying
the session-registry and authentication code; it is safe to run repeatedly.
Fresh installations already include these changes. Never import
`database/kalakal_mysql.sql` into a database containing data; that installer
drops and recreates the `kalakal` database.

## Test and schedule on XAMPP

First check eligibility without changing data:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\kalakal\task_runner.php --dry-run
```

In Windows Task Scheduler, create a task to run at least every 15 minutes:

- **Program/script:** `C:\xampp\php\php.exe`
- **Arguments:** `C:\xampp\htdocs\kalakal\task_runner.php`
- **Start in:** `C:\xampp\htdocs\kalakal`

Run the task under an account that can access the project and database. For
non-default database settings, configure `KALAKAL_DB_HOST`,
`KALAKAL_DB_NAME`, `KALAKAL_DB_USER`, and `KALAKAL_DB_PASS` in the task's
environment. Verify the task's history and PHP error log after the first run.
Do not schedule it until the dry-run count and the backup/restore procedure
have been checked.

## Session expiration

Authenticated sessions expire after the configured inactivity period in
`system_settings.session_lifetime_minutes` (120 minutes by default). Users
must log in again after expiration. They can revoke active sessions from the
account security page.
