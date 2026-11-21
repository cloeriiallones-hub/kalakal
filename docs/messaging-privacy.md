# Messaging privacy and pagination

## Conversation access

Sellers and junkshops can open only conversations in which they are
participants. Administrators do not have blanket access to seller-junkshop
threads; they can open only a direct conversation where the administrator is
one of the two participants. A user-to-user thread cannot be opened through a
request ID by an administrator.

Opening or sending a message in an administrator-participant conversation adds
an `admin_message_access_logs` row containing the administrator, thread,
action, timestamp, and IP address. This is an access record, not a message
moderation feature.

## Message history

The latest 50 messages are loaded for a conversation. Use **Load older
messages** to page backward through the thread. Messages are not automatically
refreshed; reopening the thread fetches the latest page and avoids background
polling traffic. Only messages in the displayed page are marked read.

## Database update

For an existing database, back it up and apply
`database/migrations/20261117_messaging_privacy_pagination.sql` after the
three-role messaging schema and collection workflow migrations. A fresh install
already includes the required index and access-log table in
`database/kalakal_mysql.sql`; do not run that destructive installer against an
existing database.
