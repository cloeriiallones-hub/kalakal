<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$uid = (int) $user['user_id'];
$role = $user['user_role'];
$isAdmin = $role === 'admin';
$pdo = db();
$threadIdInput = filter_input(INPUT_GET, 'thread_id', FILTER_VALIDATE_INT);
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
$withId = filter_input(INPUT_GET, 'with_id', FILTER_VALIDATE_INT);
$beforeMessageId = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT);
if ($beforeMessageId !== null && ($beforeMessageId === false || $beforeMessageId < 1)) {
    http_response_code(400);
    exit;
}
$thread = null;
$threadError = null;

if ($threadIdInput) {
    $threadQuery = $pdo->prepare(
        'SELECT thread_id, request_id, participant_a_id, participant_b_id
           FROM message_threads
          WHERE thread_id = ?'
    );
    $threadQuery->execute([$threadIdInput]);
    $existingThread = $threadQuery->fetch();
    if (!$existingThread
        || !in_array($uid, [(int) $existingThread['participant_a_id'], (int) $existingThread['participant_b_id']], true)) {
        http_response_code(404);
        exit;
    }
    $counterpartId = (int) $existingThread['participant_a_id'] === $uid
        ? (int) $existingThread['participant_b_id'] : (int) $existingThread['participant_a_id'];
    $thread = [
        'thread_id' => (int) $existingThread['thread_id'],
        'request_id' => $existingThread['request_id'] !== null ? (int) $existingThread['request_id'] : null,
        'counterpart_id' => $counterpartId,
    ];
    $title = $thread['request_id'] ? 'Request #' . $thread['request_id'] . ' messages' : 'Messages';
} elseif ($requestId) {
    $requestQuery = $pdo->prepare(
        "SELECT request_id, seller_id, accepted_buyer_id, request_status
           FROM collection_requests
          WHERE request_id = ? AND accepted_buyer_id IS NOT NULL
            AND request_status IN ('buyer_proposed','accepted','awaiting_approval','disputed','completed','cancelled')"
    );
    $requestQuery->execute([$requestId]);
    $request = $requestQuery->fetch();
    if ($isAdmin || !$request
        || !in_array($uid, [(int) $request['seller_id'], (int) $request['accepted_buyer_id']], true)) {
        http_response_code(404);
        exit;
    }

    $participants = [(int) $request['seller_id'], (int) $request['accepted_buyer_id']];
    sort($participants, SORT_NUMERIC);
    $find = $pdo->prepare(
        'SELECT thread_id FROM message_threads
          WHERE request_id = ? AND participant_a_id = ? AND participant_b_id = ?'
    );
    $find->execute([$requestId, $participants[0], $participants[1]]);
    $threadId = $find->fetchColumn();
    if (!$threadId) {
        $pdo->prepare(
            'INSERT INTO message_threads
                (request_id, participant_a_id, participant_b_id, created_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$requestId, $participants[0], $participants[1], $uid]);
        $threadId = (int) $pdo->lastInsertId();
    }
    $thread = [
        'thread_id' => (int) $threadId,
        'request_id' => (int) $requestId,
        'counterpart_id' => $isAdmin ? 0 : ($uid === $participants[0] ? $participants[1] : $participants[0]),
    ];
} elseif ($isAdmin && $withId) {
    $targetQuery = $pdo->prepare(
        "SELECT user_id, user_role
           FROM users
          WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL
            AND user_role IN ('seller','buyer')"
    );
    $targetQuery->execute([$withId]);
    $target = $targetQuery->fetch();
    if (!$target) {
        http_response_code(404);
        exit;
    }
    $participants = [$uid, (int) $target['user_id']];
    sort($participants, SORT_NUMERIC);
    $find = $pdo->prepare(
        'SELECT thread_id FROM message_threads
          WHERE request_id IS NULL AND participant_a_id = ? AND participant_b_id = ?
          ORDER BY thread_id LIMIT 1'
    );
    $find->execute($participants);
    $threadId = $find->fetchColumn();
    if (!$threadId) {
        $pdo->prepare(
            'INSERT INTO message_threads
                (request_id, participant_a_id, participant_b_id, created_by)
             VALUES (NULL, ?, ?, ?)'
        )->execute([$participants[0], $participants[1], $uid]);
        $threadId = (int) $pdo->lastInsertId();
    }
    $thread = ['thread_id' => (int) $threadId, 'request_id' => null, 'counterpart_id' => (int) $target['user_id']];
} elseif (!$isAdmin && $withId && in_array($role, ['seller', 'buyer'], true)) {
    $targetQuery = $pdo->prepare(
        "SELECT user_id FROM users
          WHERE user_id = ? AND user_role = 'admin'
            AND is_active = 1 AND deleted_at IS NULL"
    );
    $targetQuery->execute([$withId]);
    $adminId = $targetQuery->fetchColumn();
    if (!$adminId) {
        http_response_code(404);
        exit;
    }
    $participants = [$uid, (int) $adminId];
    sort($participants, SORT_NUMERIC);
    $find = $pdo->prepare(
        'SELECT thread_id FROM message_threads
          WHERE request_id IS NULL AND participant_a_id = ? AND participant_b_id = ?
          ORDER BY thread_id LIMIT 1'
    );
    $find->execute($participants);
    $threadId = $find->fetchColumn();
    if (!$threadId) {
        http_response_code(404);
        exit;
    }
    $thread = ['thread_id' => (int) $threadId, 'request_id' => null, 'counterpart_id' => (int) $adminId];
}

if ($thread && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $body = trim((string) ($_POST['message_body'] ?? ''));
    if ($body === '' || mb_strlen($body) > 3000) {
        $threadError = 'Enter a message of 1 to 3,000 characters.';
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO messages (thread_id, sender_id, message_body) VALUES (?, ?, ?)')
            ->execute([$thread['thread_id'], $uid, $body]);
        if ($isAdmin) {
            $pdo->prepare(
                "INSERT INTO admin_message_access_logs (admin_id, thread_id, action, ip_address)
                 VALUES (?, ?, 'sent', ?)"
            )->execute([$uid, $thread['thread_id'], client_ip()]);
        }
        $recipients = $pdo->prepare(
            'SELECT participant_a_id, participant_b_id FROM message_threads WHERE thread_id = ?'
        );
        $recipients->execute([$thread['thread_id']]);
        foreach ($recipients->fetch() ?: [] as $recipientId) {
            $recipientId = (int) $recipientId;
            if ($recipientId !== $uid) {
                notify($recipientId, 'new_message', 'New message',
                    'You have a new private message.', 'message_threads', $thread['thread_id']);
            }
        }
        $pdo->commit();
        redirect('messages.php?thread_id=' . (int) $thread['thread_id']);
    }
}

if ($thread) {
    if ($isAdmin) {
        $pdo->prepare(
            "INSERT INTO admin_message_access_logs (admin_id, thread_id, action, ip_address)
             VALUES (?, ?, 'opened', ?)"
        )->execute([$uid, $thread['thread_id'], client_ip()]);
    }

    $messagesQuery = $pdo->prepare(
        'SELECT m.message_id, m.sender_id, m.message_body, m.sent_at,
                CONCAT_WS(\' \', u.first_name, u.last_name) AS sender_name
           FROM messages m
           JOIN users u ON u.user_id = m.sender_id
          WHERE m.thread_id = ?
            AND (? IS NULL OR m.message_id < ?)
          ORDER BY m.message_id DESC
          LIMIT 51'
    );
    $messagesQuery->execute([$thread['thread_id'], $beforeMessageId, $beforeMessageId]);
    $messageRows = $messagesQuery->fetchAll();
    $hasOlderMessages = count($messageRows) > 50;
    if ($hasOlderMessages) {
        array_pop($messageRows);
    }
    $messages = array_reverse($messageRows);
    if ($messages) {
        $visibleIds = array_column($messages, 'message_id');
        $markRead = $pdo->prepare(
            'UPDATE messages SET read_at = NOW()
              WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL
                AND message_id BETWEEN ? AND ?'
        );
        $markRead->execute([
            $thread['thread_id'],
            $uid,
            min($visibleIds),
            max($visibleIds),
        ]);
    }
    $olderMessageCursor = $hasOlderMessages ? (int) $messages[0]['message_id'] : null;

    if ($thread['request_id']) {
        $title = 'Request #' . (int) $thread['request_id'] . ' messages';
    } elseif ($isAdmin) {
        $targetNameQuery = $pdo->prepare(
            'SELECT CONCAT_WS(\' \', first_name, last_name) FROM users WHERE user_id = ?'
        );
        $targetNameQuery->execute([$thread['counterpart_id']]);
        $title = 'Messages with ' . (string) $targetNameQuery->fetchColumn();
    } else {
        $title = 'Messages';
    }
}

$listQuery = $pdo->prepare(
    "SELECT mt.thread_id, mt.request_id, mt.participant_a_id, mt.participant_b_id,
            CONCAT_WS(' ', a.first_name, a.last_name) AS participant_a_name,
            CONCAT_WS(' ', b.first_name, b.last_name) AS participant_b_name,
            (SELECT m.message_body FROM messages m
              WHERE m.thread_id = mt.thread_id ORDER BY m.message_id DESC LIMIT 1) AS last_message,
            (SELECT m.sent_at FROM messages m
              WHERE m.thread_id = mt.thread_id ORDER BY m.message_id DESC LIMIT 1) AS last_sent_at,
            (SELECT COUNT(*) FROM messages m
              WHERE m.thread_id = mt.thread_id AND m.sender_id <> ? AND m.read_at IS NULL) AS unread_count
       FROM message_threads mt
       JOIN users a ON a.user_id = mt.participant_a_id
       JOIN users b ON b.user_id = mt.participant_b_id
      WHERE mt.participant_a_id = ? OR mt.participant_b_id = ?
      ORDER BY COALESCE(last_sent_at, mt.created_at) DESC"
);
$listQuery->execute([$uid, $uid, $uid]);
$threads = $listQuery->fetchAll();
$contacts = [];
if ($isAdmin) {
    $contacts = $pdo->query(
        "SELECT user_id, CONCAT_WS(' ', first_name, last_name) AS display_name, user_role
           FROM users
          WHERE is_active = 1 AND deleted_at IS NULL
            AND user_role IN ('seller','buyer')
          ORDER BY user_role, last_name, first_name"
    )->fetchAll();
}

$pageTitle = 'Messages';
require __DIR__ . '/includes/header.php';
?>
<div class="row-between">
    <div><span class="eyebrow">Private conversations</span><h1><?= e(isset($title) ? $title : 'Messages') ?></h1></div>
    <?php if ($thread): ?>
        <div class="message-header-actions">
            <a class="btn btn-small btn-outline"
               href="<?= e(url('messages.php?thread_id=' . (int) $thread['thread_id'] . ($beforeMessageId ? '&before=' . (int) $beforeMessageId : ''))) ?>">Refresh messages</a>
            <a class="btn btn-small btn-outline" href="<?= e(url('messages.php')) ?>">All messages</a>
        </div>
    <?php endif; ?>
</div>
<?php if ($threadError): ?><div class="alert alert-error"><?= e($threadError) ?></div><?php endif; ?>

<?php if ($isAdmin && !$thread): ?>
<section class="card">
    <h2>Start a private conversation</h2>
    <form method="get">
        <label>Buyer or seller
            <select name="with_id" required>
                <option value="">Choose an account</option>
                <?php foreach ($contacts as $contact): ?>
                    <option value="<?= (int) $contact['user_id'] ?>"><?= e(ucfirst($contact['user_role']) . ' · ' . $contact['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn" type="submit">Open conversation</button>
    </form>
</section>
<?php endif; ?>

<?php if ($thread): ?>
<section class="card">
    <?php if (!$messages && !$beforeMessageId): ?><p class="muted">No messages yet. Send the first message below.</p><?php endif; ?>
    <div class="notes">
        <?php if ($hasOlderMessages): ?>
            <p><a class="btn btn-small btn-outline"
                  href="<?= e(url('messages.php?thread_id=' . (int) $thread['thread_id'] . '&before=' . $olderMessageCursor)) ?>">Load older messages</a></p>
        <?php endif; ?>
        <?php foreach ($messages as $message): ?>
            <article class="note <?= (int) $message['sender_id'] === $uid ? 'message-own' : '' ?>">
                <strong><?= e($message['sender_name']) ?></strong>
                <span><?= nl2br(e($message['message_body'])) ?></span>
                <small><?= e(fmt_date($message['sent_at'])) ?></small>
            </article>
        <?php endforeach; ?>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <label>Message
            <textarea name="message_body" rows="3" maxlength="3000" required><?= e((string) ($_POST['message_body'] ?? '')) ?></textarea>
        </label>
        <button class="btn" type="submit">Send message</button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2>Conversations</h2>
    <?php if (!$threads): ?>
        <p class="muted">
            <?php if ($isAdmin): ?>
                No conversations yet. Start a private conversation above.
            <?php elseif ($role === 'seller'): ?>
                No conversations yet. Open My Requests and choose Message buyer on a request with a junkshop proposal to start a conversation.
            <?php else: ?>
                No conversations yet. Open Collection Requests and choose Message seller on one of your proposals to start a conversation.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Conversation</th><th>Latest message</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($threads as $row):
                    $otherId = (int) $row['participant_a_id'] === $uid
                        ? (int) $row['participant_b_id'] : (int) $row['participant_a_id'];
                    $conversationUrl = url('messages.php?thread_id=' . (int) $row['thread_id']);
                    $conversationName = $row['request_id']
                        ? 'Collection request #' . (int) $row['request_id']
                            . ($isAdmin ? ' · ' . $row['participant_a_name'] . ' / ' . $row['participant_b_name'] : '')
                        : ((int) $row['participant_a_id'] === $uid
                            ? $row['participant_b_name'] : $row['participant_a_name']);
                ?>
                    <tr>
                        <td><strong><?= e($conversationName) ?></strong>
                            <?php if ((int) $row['unread_count'] > 0): ?><br><span class="badge badge-accepted"><?= (int) $row['unread_count'] ?> unread</span><?php endif; ?>
                        </td>
                        <td><?= e($row['last_message'] ?? 'No messages yet') ?>
                            <?php if ($row['last_sent_at']): ?><br><small class="muted"><?= e(fmt_date($row['last_sent_at'])) ?></small><?php endif; ?>
                        </td>
                        <td><a class="btn btn-small" href="<?= e($conversationUrl) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
