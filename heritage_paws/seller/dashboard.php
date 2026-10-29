<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('seller');
$user = current_user();

// Quick actions on own listings: mark sold / reserved / available, or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $dogId  = (int)($_POST['dog_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $own = $pdo->prepare('SELECT dog_id FROM dogs WHERE dog_id = ? AND seller_id = ?');
    $own->execute([$dogId, $user['id']]);
    if ($own->fetch()) {
        if (in_array($action, ['available', 'reserved', 'sold'], true)) {
            $pdo->prepare('UPDATE dogs SET availability = ? WHERE dog_id = ?')->execute([$action, $dogId]);
            flash('success', 'Listing updated.');
        } elseif ($action === 'delete') {
            $imgs = $pdo->prepare('SELECT file_name FROM dog_images WHERE dog_id = ?');
            $imgs->execute([$dogId]);
            foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $f) delete_image_file($f);
            $pdo->prepare('DELETE FROM dogs WHERE dog_id = ?')->execute([$dogId]);
            flash('success', 'Listing deleted.');
        }
    }
    redirect('/seller/dashboard.php');
}

$st = $pdo->prepare(
    'SELECT d.*, b.breed_name,
            (SELECT COUNT(*) FROM inquiries i WHERE i.dog_id = d.dog_id) AS inquiry_count
     FROM dogs d JOIN breeds b ON b.breed_id = d.breed_id
     WHERE d.seller_id = ? ORDER BY d.created_at DESC'
);
$st->execute([$user['id']]);
$dogs = $st->fetchAll();

$newInq = $pdo->prepare("SELECT COUNT(*) FROM inquiries WHERE seller_id = ? AND status = 'new'");
$newInq->execute([$user['id']]);
$newInq = (int)$newInq->fetchColumn();

$pageTitle = 'Seller Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <h2>My Dogs</h2>
    <div>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/seller/inquiries.php">Inquiries
            <?php if ($newInq): ?><span class="pill"><?= $newInq ?></span><?php endif; ?></a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/seller/studs.php">My stud dogs</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/seller/females.php">My female dogs</a>  
        <a class="btn btn-outline" href="<?= BASE_URL ?>/owner/my_agreements.php">My agreements</a>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/seller/dog_form.php">+ List a new dog</a>
    </div>
</div>

<?php if (!$dogs): ?>
    <div class="empty">You haven't listed any dogs yet. Click <strong>List a new dog</strong> to get started.</div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Photo</th><th>Dog</th><th>Price</th><th>Listing</th><th>Availability</th><th>Inquiries</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($dogs as $d): ?>
        <tr>
            <td><img class="mini" src="<?= e(primary_image($pdo, (int)$d['dog_id'])) ?>" alt=""></td>
            <td><strong><?= e($d['name']) ?></strong><br><span class="muted"><?= e($d['breed_name']) ?></span></td>
            <td><?= money($d['price']) ?></td>
            <td><span class="badge badge-<?= $d['listing_status'] === 'approved' ? 'ok' : ($d['listing_status'] === 'rejected' ? 'bad' : 'warn') ?>"><?= e(ucfirst($d['listing_status'])) ?></span></td>
            <td>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?><input type="hidden" name="dog_id" value="<?= $d['dog_id'] ?>">
                    <select name="action" onchange="this.form.submit()">
                        <?php foreach (['available', 'reserved', 'sold'] as $opt): ?>
                            <option value="<?= $opt ?>" <?= $d['availability'] === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td><?= (int)$d['inquiry_count'] ?></td>
            <td class="actions">
                <a href="<?= BASE_URL ?>/dog.php?id=<?= $d['dog_id'] ?>">View</a>
                <a href="<?= BASE_URL ?>/seller/dog_form.php?id=<?= $d['dog_id'] ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Delete <?= e($d['name']) ?>? This cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="dog_id" value="<?= $d['dog_id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="link-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="muted">New and edited listings are reviewed by an admin before they appear on the public site.</p>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
