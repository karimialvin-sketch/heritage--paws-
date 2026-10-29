<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('buyer');
$user = current_user();

$inq = $pdo->prepare(
    'SELECT i.*, d.name AS dog_name, u.kennel_name, u.full_name AS seller_name, u.phone AS seller_phone
     FROM inquiries i
     JOIN dogs d  ON d.dog_id  = i.dog_id
     JOIN users u ON u.user_id = i.seller_id
     WHERE i.buyer_id = ? ORDER BY i.created_at DESC'
);
$inq->execute([$user['id']]);
$inquiries = $inq->fetchAll();

$fav = $pdo->prepare(
    "SELECT d.*, b.breed_name FROM favorites f
     JOIN dogs d ON d.dog_id = f.dog_id
     JOIN breeds b ON b.breed_id = d.breed_id
     WHERE f.buyer_id = ? AND d.listing_status = 'approved' ORDER BY f.created_at DESC"
);
$fav->execute([$user['id']]);
$favorites = $fav->fetchAll();

$pageTitle = 'My Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<h2>My saved dogs</h2>
<?php if (!$favorites): ?>
    <div class="empty">You haven't saved any dogs yet. <a href="<?= BASE_URL ?>/index.php">Browse dogs</a> and tap "Save this dog".</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($favorites as $d): ?>
            <article class="card dog-card">
                <a href="<?= BASE_URL ?>/dog.php?id=<?= $d['dog_id'] ?>"><img src="<?= e(primary_image($pdo, (int)$d['dog_id'])) ?>" alt=""></a>
                <div class="card-body">
                    <h3><?= e($d['name']) ?></h3>
                    <p class="muted"><?= e($d['breed_name']) ?> &bull; <?= e(ucfirst($d['availability'])) ?></p>
                    <p class="price"><?= money($d['price']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2>My messages to sellers</h2>
<?php if (!$inquiries): ?>
    <div class="empty">No messages yet.</div>
<?php endif; ?>
<?php foreach ($inquiries as $r): ?>
    <div class="card thread">
        <div class="thread-head">
            About <a href="<?= BASE_URL ?>/dog.php?id=<?= $r['dog_id'] ?>"><strong><?= e($r['dog_name']) ?></strong></a>
            to <?= e($r['kennel_name'] ?: $r['seller_name']) ?>
            <span class="badge badge-<?= $r['status'] === 'replied' ? 'ok' : ($r['status'] === 'new' ? 'warn' : 'muted') ?>"><?= e(ucfirst($r['status'])) ?></span>
            <span class="muted"><?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></span>
        </div>
        <blockquote><?= nl2br(e($r['message'])) ?></blockquote>
        <?php if ($r['reply']): ?>
            <blockquote class="reply"><strong>Seller replied:</strong><br><?= nl2br(e($r['reply'])) ?></blockquote>
        <?php else: ?>
            <p class="muted">Waiting for the seller's reply. You can also call them on <?= e($r['seller_phone']) ?>.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
