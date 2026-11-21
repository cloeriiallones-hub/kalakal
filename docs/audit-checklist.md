# KALAKAL repair checklist

Status reflects only the repository changes and tests recorded below. A
database smoke test does not substitute for a complete browser workflow or
real SMTP delivery.

## Phase 1 — Authentication and account management

| Item | Status | Evidence / remaining work |
|---|---|---|
| Password recovery/reset token workflow | FIXED BUT NOT TESTED | Implemented; live email delivery/configuration was not available. |
| Account email verification | DISABLED BY REQUEST | Registration and login no longer require verification; `tests/auth_email_policy_smoke.php` passed for unverified seller, buyer, and admin accounts. Old verification URLs redirect to login; existing `email_verified_at` data was not modified. |
| Administrator email-code MFA | DISABLED BY REQUEST | Administrator password-only login passed in `tests/auth_email_policy_smoke.php`; old challenge route redirects to login. This reduces admin account security. |
| Active sessions and revocation | FIXED AND TESTED | `tests/auth_session_smoke.php` verified registration, lookup, and revocation. |
| Account deletion recovery/finalizer | FIXED AND TESTED | Disposable-account finalizer test passed; Task Scheduler still must be configured on deployment. |
| Registration, duplicate email, login lockout, invalid attempts, role checks | IN PROGRESS | Code was previously updated; full seller/buyer/admin browser and abuse-case testing remains. |
| Inactivity timeout and deactivation enforcement | FIXED BUT NOT TESTED | Implemented; validate real session expiry and account-status enforcement. |
| Administrator user suspension and reactivation | FIXED AND TESTED | Added admin user search/status filters, required suspension reasons, session revocation, guarded reactivation, and audit history using existing `users` and `audit_logs` data. `tests/admin_account_suspension_smoke.php` passed on a disposable test database; no schema migration was needed. |

## Phase 2 — Core functionality and collection workflow

| Item | Status | Evidence / remaining work |
|---|---|---|
| Selected-material junkshop comparison, quantities, units, incomplete offers, net estimates | FIXED AND TESTED | `tests/comparison_smoke.php` passed on a fresh disposable schema. |
| Request submission idempotency and separate collection/payment states | FIXED AND TESTED | `tests/collection_payment_smoke.php` passed. |
| Seller request-submission destination | FIXED BUT NOT TESTED | Successful or duplicate submissions open `requests.php` at the matching request row; the new row is highlighted. Browser submission flow was not rerun. |
| Seller/buyer final-value approval, dispute, and terminal-state guards | FIXED AND TESTED | Dispute, resolution, and invalid reopening cases passed in the collection smoke test. |
| Buyer reschedule proposal and seller approval/decline | FIXED AND TESTED | Smoke test verified approval, decline preserving the old schedule, duplicate pending rejection, and rejection after item entry. |
| Accepted-request cancellation notification | FIXED AND TESTED | Disposable database test verified one notification to the accepted junkshop. |
| Seller inventory trade offers, stock reservation, and buyer decisions | FIXED AND TESTED | `trades.php` reuses completed-collection stock; `tests/trade_workflow_smoke.php` verified reservation, overstock rejection, and stock restoration on declined trades. |
| Partial trade payments and seller earnings | FIXED AND TESTED | Buyer reports payments, seller confirms or rejects them, and fully confirmed trades close automatically; the trade smoke test verified paid-versus-pending totals and seller report totals. Payments remain manual/offline; no payment gateway is connected. |
| Administrator earnings, collection status, and current-price estimates | FIXED AND TESTED | Added `admin_reports.php` with all-time confirmed/pending payment totals, recent request/final-value visibility, current compatible net estimates, and recent trade totals; database report queries passed in the trade smoke test and the admin page rendered at 320px with no horizontal overflow. Full multi-account admin workflow remains. |
| Seller earnings history | FIXED AND TESTED | Added `earnings.php`, separating confirmed and pending collection/trade payments; seller report totals passed in `tests/trade_workflow_smoke.php` and the page rendered in the disposable browser test. |
| Concurrent rescheduling versus recording collected values | FIXED BUT NOT TESTED | Matching request/collection row locks were added; no multi-connection race test was run. |
| Missed-pickup reporting and participant acknowledgment | FIXED AND TESTED | Smoke test verified overdue eligibility, duplicate rejection, both notifications, participant acknowledgment, and unchanged collection status. |
| Image cleanup on failed database transactions, failed collections, refunds | NOT STARTED | Requires targeted upload and workflow testing; refunds are not part of the current offline payment flow. |
| Historical pickup address/coordinate snapshot | FIXED BUT NOT TESTED | Request records store their submitted location; full profile-edit/history browser test remains. |

## Phase 3 — Database integrity and migrations

| Item | Status | Evidence / remaining work |
|---|---|---|
| Workflow schema migration repeatability | FIXED AND TESTED | Payment/transition, messaging, and email migrations applied repeatedly to disposable schemas. |
| Schema changes for inventory trading and earnings reports | NOT REQUIRED | Reused existing `trades`, `trade_items`, `transaction_payments`, `collection_items.remaining_quantity`, `v_trade_totals`, and collection payment views/triggers; no migration or live-database change was made. |
| Explicit migration order, checksum ledger, safe runner | FIXED AND TESTED | `database/migrate.php` baseline/apply/skip behavior tested on a disposable schema. |
| Fresh installer schema | FIXED AND TESTED | Destructive installer was redirected to a uniquely named disposable database and imported successfully. |
| Existing-install migration preconditions and restoration instructions | FIXED BUT NOT TESTED | Documented in `docs/database-migrations.md`; no live-database migration or restore was attempted. |
| Full legacy schema/trigger/procedure audit across every historical deployment | IN PROGRESS | The new workflow procedures and triggers were exercised; every legacy migration combination was not tested. |
| Concurrent database behavior under multiple simultaneous web requests | NOT STARTED | Needs a dedicated multi-connection integration test. |

## Phase 4 — Messaging and notifications

| Item | Status | Evidence / remaining work |
|---|---|---|
| Message history pagination and bounded read marking | FIXED AND TESTED | `tests/message_pagination_smoke.php` verified ordered, duplicate-free 50-message pages. |
| Administrator conversation privacy and access logging | FIXED BUT NOT TESTED | Route restricts admin access to participant threads; access-log table/query smoke test passed, but HTTP authorization was not exercised. |
| Manual conversation refresh control | FIXED BUT NOT TESTED | Added a visible refresh link that reloads the current conversation page and preserves its older-message cursor. |
| Automatic message refresh, email preference UX, notification pagination | NOT STARTED | No polling or notification pagination was added; broader notification UX remains unaudited. |
| Email queue batch ownership and bounded retries | FIXED AND TESTED | `tests/email_queue_smoke.php` passed simulated delivery/failure cases. |
| Real SMTP delivery and mailbox verification | BLOCKED | Configure SMTP credentials/from address and run the mailer against a controlled mailbox. |

## Phase 5 — UI/UX

### Audit findings and priorities

| Priority | Finding | Recommendation / current action |
|---|---|---|
| P1 | The login/registration two-column layout compressed the form at 521–760px; the email input was 23px at 521px. | Stack the illustration and form on tablet/mobile widths. Implemented in `assets/css/style.css` and browser-checked across the breakpoint. |
| P2 | Actual request states such as `buyer_proposed`, `accepted`, `awaiting_approval`, and `disputed` had no distinct badge styling. | Give each workflow state a readable, consistent color. Implemented; CSS contrast was checked, but authenticated request tables still need visual testing. |
| P2 | The buyer dashboard discarded status counts already loaded for the current junkshop. | Show the existing request and unread-notification summaries before quick actions. Implemented in `dashboard.php`; buyer-account rendering still needs browser verification. |
| P2 | An open message thread had pagination but no visible refresh action. | Add a manual refresh that preserves the current message cursor. Implemented; automatic polling remains out of scope. |
| P2 | Public navigation called prices “More Info”; workspace navigation lacked clear grouping and active-page semantics. | Use grouped, reference-inspired navigation with KALAKAL’s green palette, clearer active state, and accessible labels. Implemented in `includes/header.php` and `assets/css/style.css`; the shared layout covers all account roles. |
| P3 | Request action cells could become crowded where multiple forms and decisions appeared together. | Add spacing and grouping without changing actions. Implemented in `assets/css/style.css`; representative authenticated tables remain unverified. |

| Item | Status | Evidence / remaining work |
|---|---|---|
| Public mobile navigation, responsive registration/home/prices layouts | FIXED AND TESTED | Home, login, registration, and prices were checked at representative widths from 320–1024px with no page overflow; registration controls remain usable across 375, 520, 521, 600, 760, and 761px; the mobile menu opened, closed with Escape, and returned focus to its toggle. Prices tables scroll inside their wrapper at 320/375px. |
| Tablet registration/login layout collapse at 521–760px | FIXED AND TESTED | Before the fix, the email input measured 23px at 521px and 45px at 600px; the auth layout now stacks the form and illustration at widths up to 760px. |
| Public/sidebar navigation labels and active-page accessibility | FIXED BUT NOT TESTED | Renamed the vague public “More Info” link to “Prices,” added `aria-current`, hid decorative sidebar glyphs from assistive technology, and added Escape handling to the mobile menu. |
| Reference-inspired sidebar for seller, junkshop, and admin roles | FIXED BUT NOT TESTED | Added a KALAKAL workspace header, grouped role-specific links, green active rows, and a profile footer in the shared sidebar; role-authenticated visual checks remain. |
| Authenticated workspace header and profile menu | FIXED BUT NOT TESTED | Added a centered breadcrumb and right-aligned Messages/Notifications links, moved profile and CSRF-protected POST logout actions into a keyboard-accessible sidebar disclosure, and made the KALAKAL header logo/name open the collapsed sidebar; the collapse control sits beside the sidebar workspace brand. Full role and workflow checks remain. |
| Administrator account management | FIXED AND TESTED | Added a Manage users entry in the admin sidebar/dashboard with searchable, filterable account status, required reason logging, session revocation on suspension, and reactivation limited to accounts suspended through this page. Self-suspension, last-admin suspension, pending-deletion accounts, and unsupported reactivation are blocked; admin actions are logged in the existing audit table. |
| Header branding follows sidebar visibility | FIXED AND TESTED | On an authenticated administrator page, verified that branding is hidden with the sidebar expanded and visible when collapsed; the workspace breadcrumb measured centered in both states. Seller and buyer dashboards use the same shared header and CSS. |
| Request status badges and action-column layout | FIXED BUT NOT TESTED | Added colors for buyer-proposed, accepted, awaiting-approval, and disputed states and improved action-form spacing; authenticated request tables still need visual testing. |
| Buyer dashboard activity summaries | FIXED BUT NOT TESTED | Displays the existing request-status and unread-notification counts; buyer-account rendering still needs browser verification. |
| Collection/request tables and all seller/buyer/admin dashboards on mobile | IN PROGRESS | Existing table wrappers are horizontally scrollable; authenticated pages were not all visually exercised. |
| Loading/duplicate-submit feedback, accessibility, empty states, print reports | IN PROGRESS | Focus indicators and navigation semantics were improved; page-by-page keyboard, contrast, and workflow checks remain. |

## Phase 6 — Testing and readiness

| Item | Status | Evidence / remaining work |
|---|---|---|
| PHP syntax | FIXED AND TESTED | All 46 project PHP files outside `vendor/` passed `php -l` after the latest feature edits; `database/migrate.php` is included. |
| Fresh install plus focused smoke tests | FIXED AND TESTED | Fresh installer imported into `kalakal_audit_installer_20261119_test`; auth, account suspension, comparison, collection/payment, trade/earnings, messaging, and email-queue smoke tests all passed without applying migrations over the installer procedures. |
| Migration runner ledger safety | FIXED AND TESTED | On a disposable schema, contiguous baseline, repeatable skip, and rejection of a non-prefix baseline were verified. |
| Full seller/buyer/admin browser integration | NOT STARTED | No end-to-end account workflow was run in a browser. |
| Failure/security/concurrency suite | IN PROGRESS | Focused idempotency, authorization-query, invalid-transition, and wrong-email-batch cases passed; broad negative testing remains. |
| Production readiness | BLOCKED | SMTP, real multi-account browser testing, concurrency testing, migration review against a backed-up target, and deployment operations remain. |

## Test commands

Run each script only against a disposable database whose name contains
`_test`:

```powershell
$env:KALAKAL_DB_NAME = 'kalakal_audit_installer_20261119_test'
$env:KALAKAL_TEST_DB = '1'
C:\xampp\php\php.exe tests\auth_session_smoke.php
C:\xampp\php\php.exe tests\admin_account_suspension_smoke.php
C:\xampp\php\php.exe tests\auth_email_policy_smoke.php seller
C:\xampp\php\php.exe tests\auth_email_policy_smoke.php buyer
C:\xampp\php\php.exe tests\auth_email_policy_smoke.php admin
C:\xampp\php\php.exe tests\comparison_smoke.php
C:\xampp\php\php.exe tests\collection_payment_smoke.php
C:\xampp\php\php.exe tests\trade_workflow_smoke.php
C:\xampp\php\php.exe tests\message_pagination_smoke.php
C:\xampp\php\php.exe tests\email_queue_smoke.php
```

The email queue script simulates failures and does not send an email.
