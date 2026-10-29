<?php
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare(
    "SELECT d.*, b.breed_name, u.user_id AS seller_uid, u.full_name AS seller_name, u.kennel_name,
            u.location AS seller_location, u.phone AS seller_phone, u.email AS seller_email, u.bio AS seller_bio
     FROM dogs d
     JOIN breeds b ON b.breed_id = d.breed_id
     JOIN users  u ON u.user_id  = d.seller_id
     WHERE d.dog_id = ?"
);
$st->execute([$id]);
$dog = $st->fetch();

$user = current_user();
$isOwnerOrAdmin = $user && ($user['role'] === 'admin' || $user['id'] === (int)($dog['seller_uid'] ?? 0));

// Only approved dogs are public; owner and admin can preview their own pending ones.
if (!$dog || ($dog['listing_status'] !== 'approved' && !$isOwnerOrAdmin)) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="empty">This dog listing could not be found.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $dog['name'] . ' - ' . $dog['breed_name'];
$trackDogId = (int)$dog['dog_id'];   // counts this as a view of this dog
require_once __DIR__ . '/includes/header.php';

/* ---------- Handle buyer actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_role('buyer');
    $action = $_POST['action'] ?? '';

    if ($action === 'inquire') {
        $message = trim($_POST['message'] ?? '');
        if (strlen($message) < 10) {
            flash('error', 'Please write a message of at least 10 characters.');
        } elseif ($dog['availability'] === 'sold') {
            flash('error', 'Sorry, this dog has already been sold.');
        } else {
            $ins = $pdo->prepare('INSERT INTO inquiries (dog_id, buyer_id, seller_id, message) VALUES (?,?,?,?)');
            $ins->execute([$dog['dog_id'], $user['id'], $dog['seller_uid'], $message]);
            flash('success', 'Your message was sent to the seller. Check your dashboard for their reply.');
        }
    } elseif ($action === 'favorite') {
        $chk = $pdo->prepare('SELECT 1 FROM favorites WHERE buyer_id = ? AND dog_id = ?');
        $chk->execute([$user['id'], $dog['dog_id']]);
        if ($chk->fetch()) {
            $pdo->prepare('DELETE FROM favorites WHERE buyer_id = ? AND dog_id = ?')->execute([$user['id'], $dog['dog_id']]);
            flash('success', 'Removed from your saved dogs.');
        } else {
            $pdo->prepare('INSERT INTO favorites (buyer_id, dog_id) VALUES (?,?)')->execute([$user['id'], $dog['dog_id']]);
            flash('success', 'Saved to your favourites.');
        }
    }
    redirect('/dog.php?id=' . $dog['dog_id']);
}

$images = $pdo->prepare('SELECT * FROM dog_images WHERE dog_id = ? ORDER BY is_primary DESC, image_id ASC');
$images->execute([$id]);
$images = $images->fetchAll();

$isFav = false;
if ($user && $user['role'] === 'buyer') {
    $f = $pdo->prepare('SELECT 1 FROM favorites WHERE buyer_id = ? AND dog_id = ?');
    $f->execute([$user['id'], $id]);
    $isFav = (bool)$f->fetch();
}

$pageTitle = $dog['name'] . ' - ' . $dog['breed_name'];
require_once __DIR__ . '/includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL ?>/index.php">&larr; Back to all dogs</a>

<?php if ($dog['listing_status'] !== 'approved'): ?>
    <div class="alert alert-warn">This listing is <strong><?= e($dog['listing_status']) ?></strong> and is not visible to the public.</div>
<?php endif; ?>

<div class="detail">
    <div class="gallery">
        <img id="mainPhoto" src="<?= e(primary_image($pdo, (int)$dog['dog_id'])) ?>" alt="<?= e($dog['name']) ?>">
        <?php if (count($images) > 1): ?>
            <div class="thumbs">
                <?php foreach ($images as $img): ?>
                    <img src="<?= UPLOAD_URL . e($img['file_name']) ?>" alt="" class="thumb">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="info">
        <h1><?= e($dog['name']) ?>
            <?php if ($dog['availability'] !== 'available'): ?>
                <span class="badge badge-warn"><?= e(ucfirst($dog['availability'])) ?></span>
            <?php endif; ?>
        </h1>
        <p class="price big"><?= money($dog['price']) ?></p>

        <table class="spec">
            <tr><th>Breed</th><td><?= e($dog['breed_name']) ?></td></tr>
            <tr><th>Gender</th><td><?= e($dog['gender']) ?></td></tr>
            <tr><th>Age</th><td><?= e(age_label((int)$dog['age_months'])) ?></td></tr>
            <tr><th>Colour</th><td><?= e($dog['color'] ?: 'Not specified') ?></td></tr>
            <tr><th>Vaccinated</th><td><?= $dog['vaccinated'] ? 'Yes' : 'No / unknown' ?></td></tr>
            <tr><th>Location</th><td><?= e($dog['location']) ?></td></tr>
        </table>

        <h3>About this dog</h3>
        <p><?= nl2br(e($dog['description'])) ?></p>

        <div class="seller-box">
            <h3>Seller</h3>
            <p><strong><?= e($dog['kennel_name'] ?: $dog['seller_name']) ?></strong><br>
               <span class="muted"><?= e($dog['seller_location']) ?></span></p>
            <?php if ($dog['seller_bio']): ?><p class="muted"><?= nl2br(e($dog['seller_bio'])) ?></p><?php endif; ?>

            <p class="seller-phone">&#128222; <?= e($dog['seller_phone']) ?></p>
            <div class="contact-actions">
                <a class="btn btn-call" href="<?= e(tel_link($dog['seller_phone'])) ?>">&#128222; Call seller</a>
                <a class="btn btn-whatsapp" target="_blank" rel="noopener"
                   href="<?= e(whatsapp_link($dog['seller_phone'], $dog['name'])) ?>">WhatsApp seller</a>
            </div>
        </div>

        <?php if ($user && $user['role'] === 'buyer'): ?>
            <form method="post" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="favorite">
                <button class="btn btn-outline" type="submit"><?= $isFav ? '&#9829; Saved' : '&#9825; Save this dog' ?></button>
            </form>

            <?php if ($dog['availability'] !== 'sold'): ?>
            <form method="post" class="inquiry-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="inquire">
                <h3>Message the seller</h3>
                <textarea name="message" rows="4" required minlength="10"
                          placeholder="Hi, I'm interested in <?= e($dog['name']) ?>. Is he/she still available? Can I visit?"></textarea>
                <button class="btn btn-primary" type="submit">Send message</button>
            </form>
            <?php endif; ?>
        <?php elseif (!$user): ?>
            <p class="muted">Want to save this dog or send a written message? <a href="<?= BASE_URL ?>/register.php?role=buyer">Create a free buyer account</a> or <a href="<?= BASE_URL ?>/login.php">log in</a>.</p>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
