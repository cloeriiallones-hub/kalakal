# Legacy collector-area migrations

The application now uses only three account roles: admin, buyer (junkshop), and seller (resident or establishment). Junkshop staff who perform pickups do not need separate collector logins; the buyer account proposes the schedule and payment method, then records the collected quantities and prices after the seller approves.

The older collector-area migrations remain in this folder for historical database upgrades. Do not apply them to a new database. Before applying `database/migrations/20261115_three_roles_buyer_messaging.sql` to an existing v3.3 database, finish or cancel any pickup still assigned to a collector. The migration stops if a collector-owned pickup is still active, and disables old collector accounts while retaining their historical records.
