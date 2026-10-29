<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('seller');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $inqId  = (int)($_POST['inquiry_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        $reply = trim($_POST['reply'] ?? '');
        if ($reply === '') {
            flash('error', 'Reply cannot be empty.');
        } else {
            $pdo->prepare("UPDATE inquiries SET reply = ?, status = 'replied', replied_at = NOW()
                           WHERE inquiry_id = ? AND seller_id = ?")
                ->execute([$reply, $inqId, $user['id']]);
            flash('success', 'Reply sent to the buyer.');
        }
    } elseif ($action === 'close') {
        $pdo->prepare("UPDATE inquiries SET status = 'closed' WHERE inquiry_id = ? AND seller_id = ?")
            ->execute([$inqId, $user['id']]);
        flash('success', 'Conversation closed.');
    }
    redirect('/seller/inquiries.php');
}

$st = $pdo->prepare(
    'SELECT i.*, d.name AS dog_name, u.full_name AS buyer_name, u.phone AS buyer_phone, u.email AS buyer_email
     FROM inquiries i
     JOIN dogs d  ON d.dog_id  = i.dog_id
     JOIN users u ON u.user_id = i.buyer_id
     WHERE i.seller_id = ? ORDER BY (i.status = \'new\') DESC, i.created_at DESC'
);
$st->execute([$user['id']]);
$rows = $st->fetchAll();

$pageTitle = 'Buyer Inquiries';
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL ?>/seller/dashboard.php">&larr; Back to my dogs</a>
<h2>Buyer inquiries</h2>

<?php if (!$rows): ?>
    <div class="empty">No inquiries yet. They will appear here when buyers message you about your dogs.</div>
<?php endif; ?>

<?php foreach ($rows as $r): ?>
    <div class="card thread">
        <div class="thread-head">
            <strong><?= e($r['buyer_name']) ?></strong> asked about <strong><?= e($r['dog_name']) ?></strong>
            <span class="badge badge-<?= $r['status'] === 'new' ? 'warn' : ($r['status'] === 'replied' ? 'ok' : 'muted') ?>"><?= e(ucfirst($r['status'])) ?></span>
            <span class="muted"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></span>
        </div>
        <p class="muted">&#128222; <?= e($r['buyer_phone']) ?> &nbsp; &#9993; <?= e($r['buyer_email']) ?></p>
        <blockquote><?= nl2br(e($r['message'])) ?></blockquote>

        <?php if ($r['reply']): ?>
            <blockquote class="reply"><strong>Your reply:</strong><br><?= nl2br(e($r['reply'])) ?></blockquote>
        <?php endif; ?>

        <?php if ($r['status'] !== 'closed'): ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="inquiry_id" value="<?= $r['inquiry_id'] ?>">
                <textarea name="reply" rows="2" placeholder="Write a reply..."></textarea>
                <button class="btn btn-primary btn-sm" name="action" value="reply" type="submit">Send reply</button>
                <button class="btn btn-outline btn-sm" name="action" value="close" type="submit">Close</button>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
