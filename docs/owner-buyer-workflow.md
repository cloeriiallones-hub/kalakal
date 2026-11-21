# Seller, buyer, and admin workflow

## Account roles and database update

KALAKAL has three account roles: **admin**, **buyer** (junkshop), and **seller** (resident or establishment). A seller selects a resident/establishment type during registration. Junkshop workers do not need separate collector accounts.

Back up the `kalakal` database first. For an existing v3.3 database, run `database/migrations/20261115_three_roles_buyer_messaging.sql` once using phpMyAdmin or the MySQL command line. Finish or cancel active collector-assigned pickups before running it; the migration stops if any remain. Old collector accounts are made inactive and retained as buyer-role records for history. Fresh installs use the updated `database/kalakal_mysql.sql`; do not also run the migration.

## Seller

- Sellers create collection requests with pickup details, schedule, waste estimates, and photos.
- A junkshop proposes a collection time and a payment method (physical cash, GCash, or Maya). The seller can approve or decline the proposal. Pickup details are shared after approval.
- Sellers and buyers can privately message each other on a proposed or approved collection. Administrators can start direct conversations with buyers or sellers, but cannot open seller-buyer collection threads unless they are a participant.
- Collection completion does not record payment. After paying, the junkshop reports the amount from **My Collections**; it remains pending until the seller confirms receipt from **My Requests**.
- Sellers can configure an e-wallet and QR code in their profile; the accepted buyer can view it when relevant.
- Existing account deletion, request cancellation, material valuation, and notifications remain available.

## Buyer (junkshop)

- Junkshops see pending collection requests from any city, with the seller's estimates and photos. A request addressed to one specific junkshop is visible only to that junkshop.
- Buyers can browse requests before finishing onboarding, but must complete their profile before proposing.
- The buyer proposes a schedule and one payment method from their profile options. The seller reviews both before pickup details are shared.
- After approval, the buyer records actual quantities and prices; the seller approves or disputes the final values.
- After making payment for a completed collection, the buyer reports it from **My Collections**. The payment remains pending until the seller confirms receipt; completing a pickup alone does not mark it paid.
- Use **Message seller** on a proposal or completed collection to open or start its private conversation.
- If an accepted pickup time has passed and no collected materials were recorded, either participant can report a missed pickup. The other participant can acknowledge the report; the report itself does not change collection or payment status.
- Buying prices are managed in `prices.php`; junkshop location and payment preference are edited in the profile.
- Buyer and seller conversations are attached to a collection request. Admins can also start private conversations from **Messages**.

## Admin

- Admins can review requests, manage system features, and privately message buyers or sellers.
- **Messages** shows only conversations where the administrator is a participant. Opening or sending in an administrator-participant conversation is recorded in `admin_message_access_logs`; user-to-user collection conversations are not exposed to administrators.

## Nearby junkshops and pickup charge

The seller dashboard compares junkshop prices. For an outside-city junkshop, the seller must agree to a pickup charge based on straight-line distance. The server recalculates distance and charge when the request is submitted.

For details, see `database/migrations/20261114_junkshop_comparison_pickup_charge.sql` and the current database schema.
