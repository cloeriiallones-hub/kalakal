# Database migration safety and order

## Important installer warning

`database/kalakal_mysql.sql` starts with `DROP DATABASE IF EXISTS kalakal`.
Use it only to create a fresh database. Never import it into an existing
installation. Existing data was not modified during this work.

## XAMPP installation and update

For a fresh installation, start Apache and MySQL in XAMPP, create a new empty
database, and import the installer only after confirming its database name is
the new target. The checked-in installer creates `kalakal` and drops a database
with that name first; do not import it when `kalakal` already contains data.
Configure database and SMTP settings through the local environment or the
existing `config/config.php` defaults, then visit `http://localhost/kalakal/`.

For an update, back up both the database and project files first. Preserve the
deployment's configuration and `uploads` directory; deploy the updated PHP,
CSS, JavaScript, and migration files without overwriting user uploads. Confirm
the configured database name, review the ordered migration list below, and
record a baseline only after independently verifying the target schema. Then
run the migration runner with `--confirm-database=YOUR_DATABASE_NAME`. Never
use the installer to update an existing installation.

Back up before every schema update. For XAMPP, create a dump with:

```powershell
# Create C:\xampp\backup first.
& $env:ComSpec /c '"C:\xampp\mysql\bin\mysqldump.exe" --user=root --single-transaction kalakal > "C:\xampp\backup\kalakal-before-update.sql"'
```

Restore only after confirming the destination database and backup file:

```powershell
& $env:ComSpec /c '"C:\xampp\mysql\bin\mysql.exe" --user=root kalakal < "C:\xampp\backup\kalakal-before-update.sql"'
```

If MySQL has a password, configure the local client securely; do not put the
password in this project or in a shared command transcript.

## Existing-schema migration order

For a database still on the legacy collector schema, inspect the migration
preconditions and apply in this order. The order is intentionally not simple
filename sorting:

1. `20261108_account_deletion.sql`
2. `20261108_unique_collector_service_areas.sql`
3. `20261108_balanced_collector_areas.sql`
4. `20261109_pending_pickup_cancellation_notifications.sql`
5. `20261113_owner_buyer_workflow.sql`
6. `20261114_junkshop_comparison_pickup_charge.sql`
7. `20261115_three_roles_buyer_messaging.sql`
8. `20261114_authentication_lifecycle.sql`
9. `20261116_collection_payments_and_transitions.sql`
10. `20261117_messaging_privacy_pagination.sql`
11. `20261118_email_notification_queue.sql`
12. `20261119_missed_pickup_reports.sql`
13. `20261120_all_city_buyer_requests.sql`

The final migration makes open seller requests visible and available for proposals
to junkshops in every city, while keeping seller-addressed requests private to the
chosen junkshop. It also notifies buyers of open requests and their cancellations.

The role-consolidation migration stops when collector pickups are still active;
resolve them before proceeding. The earlier collector-area migrations apply
only when those legacy tables and accounts exist. The later workflow and
authentication/payment/messaging/email migrations are repeatable; the older
role/schema migrations are not guaranteed to be repeatable.

## Migration ledger

`database/migrate.php` records checksums and application times in
`schema_migrations`, follows the explicit order above, and runs migrations
through XAMPP's `mysql.exe` so stored procedures and `DELIMITER` blocks work.
It requires the configured database name to be explicitly confirmed:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\kalakal\database\migrate.php `
  --confirm-database=kalakal
```

The runner deliberately refuses to run when the ledger has no baseline. Before
using `--baseline=...`, independently verify each listed migration is already
represented by the target database schema. Baseline mode records names and
checksums only; it does not apply or validate those migrations. Baselines must
be a contiguous prefix of the documented migration order. The runner also
rejects gaps and unknown migration names in an existing ledger. Never baseline
an unknown schema just to bypass the safety check.

After an update, the runner will apply unrecorded migrations in order and stop
on the first error. MySQL DDL may commit statement-by-statement; a failed
migration can therefore be partially applied. Inspect the error and schema,
restore the backup if needed, and reconcile before retrying. Do not edit an
already-recorded migration; add a new migration instead.

Fresh installs already include the application schema in
`database/kalakal_mysql.sql`; after verifying that schema, baseline the
migration names before using the runner for future updates.
