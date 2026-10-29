<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('seller');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $studId = (int)($_POST['stud_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $own = $pdo->prepare('SELECT stud_id FROM stud_dogs WHERE stud_id = ? AND owner_id = ?');
    $own->execute([$studId, $user['id']]);
    if ($own->fetch()) {
        if (in_array($action, ['available', 'unavailable'], true)) {
            $pdo->prepare('UPDATE stud_dogs SET availability = ? WHERE stud_id = ?')->execute([$action, $studId]);
            flash('success', 'Stud listing updated.');
        } elseif ($action === 'delete') {
            delete_listing_files($pdo, 'stud_images', $studId);
            $pdo->prepare('DELETE FROM stud_dogs WHERE stud_id = ?')->execute([$studId]);
            flash('success', 'Stud listing deleted.');
        }
    }
    redirect('/seller/studs.php');
}

$st = $pdo->prepare(
    'SELECT s.*, b.breed_name FROM stud_dogs s
     JOIN breeds b ON b.breed_id = s.breed_id
     WHERE s.owner_id = ? ORDER BY s.created_at DESC'
);
$st->execute([$user['id']]);
$studs = $st->fetchAll();

$pageTitle = 'My Stud Dogs';
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL ?>/seller/dashboard.php">&larr; Back to my dogs</a>
<div class="page-head">
    <h2>My stud dogs</h2>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/seller/stud_form.php">+ List a stud dog</a>
</div>

<?php if (!$studs): ?>
    <div class="empty">You haven't listed a stud dog yet. Click <strong>List a stud dog</strong> to offer your male for mating.</div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Photo</th><th>Stud dog</th><th>Stud fee</th><th>Listing</th><th>Availability</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($studs as $s): ?>
        <tr>
            <td><img class="mini" src="<?= e(listing_image($pdo, 'stud_images', (int)$s['stud_id'])) ?>" alt=""></td>
            <td><strong><?= e($s['name']) ?></strong><br><span class="muted"><?= e($s['breed_name']) ?> &bull; <?= e(age_label((int)$s['age_months'])) ?></span></td>
            <td><?= e(fee_label($s['fee_type'], $s['stud_fee'])) ?></td>
            <td><span class="badge badge-<?= $s['listing_status'] === 'approved' ? 'ok' : ($s['listing_status'] === 'rejected' ? 'bad' : 'warn') ?>"><?= e(ucfirst($s['listing_status'])) ?></span></td>
            <td>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?><input type="hidden" name="stud_id" value="<?= $s['stud_id'] ?>">
                    <select name="action" onchange="this.form.submit()">
                        <option value="available"   <?= $s['availability'] === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="unavailable" <?= $s['availability'] === 'unavailable' ? 'selected' : '' ?>>Not available now</option>
                    </select>
                </form>
            </td>
            <td class="actions">
                <a href="<?= BASE_URL ?>/seller/stud_form.php?id=<?= $s['stud_id'] ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Delete <?= e($s['name']) ?>? This cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="stud_id" value="<?= $s['stud_id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="link-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="muted">New and edited stud listings are reviewed by an admin before they appear on the public Stud Services page.</p>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>