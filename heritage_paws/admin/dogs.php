<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $dogId  = (int)($_POST['dog_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $pdo->prepare("UPDATE dogs SET listing_status='approved' WHERE dog_id=?")->execute([$dogId]);
        flash('success', 'Listing approved and now public.');
    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE dogs SET listing_status='rejected' WHERE dog_id=?")->execute([$dogId]);
        flash('success', 'Listing rejected.');
    } elseif ($action === 'delete') {
        $imgs = $pdo->prepare('SELECT file_name FROM dog_images WHERE dog_id = ?');
        $imgs->execute([$dogId]);
        foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $f) delete_image_file($f);
        $pdo->prepare('DELETE FROM dogs WHERE dog_id=?')->execute([$dogId]);
        flash('success', 'Listing deleted.');
    }
    redirect('/admin/dogs.php?filter=' . urlencode($_POST['back_filter'] ?? 'pending'));
}

$filter = $_GET['filter'] ?? 'pending';
$sql = "SELECT d.*, b.breed_name, u.full_name AS seller_name, u.kennel_name
        FROM dogs d
        JOIN breeds b ON b.breed_id = d.breed_id
        JOIN users u  ON u.user_id = d.seller_id";
$args = [];
if (in_array($filter, ['pending', 'approved', 'rejected'], true)) {
    $sql   .= ' WHERE d.listing_status = ?';
    $args[] = $filter;
}
$sql .= ' ORDER BY d.created_at DESC';
$st = $pdo->prepare($sql);
$st->execute($args);
$dogs = $st->fetchAll();

$pageTitle = 'Manage Listings';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/_nav.php';
?>
<div class="page-head">
    <h2>Dog listings</h2>
    <div class="tabs">
        <?php foreach (['pending' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
            <a class="<?= $filter === $k ? 'active' : '' ?>" href="?filter=<?= $k ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="table-wrap"><table class="data">
    <thead><tr><th>Photo</th><th>Dog</th><th>Seller</th><th>Price</th><th>Listing</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($dogs as $d): ?>
        <tr>
            <td><img class="mini" src="<?= e(primary_image($pdo, (int)$d['dog_id'])) ?>" alt=""></td>
            <td><a href="<?= BASE_URL ?>/dog.php?id=<?= $d['dog_id'] ?>"><strong><?= e($d['name']) ?></strong></a><br>
                <span class="muted"><?= e($d['breed_name']) ?> &bull; <?= e($d['gender']) ?> &bull; <?= e(age_label((int)$d['age_months'])) ?></span></td>
            <td><?= e($d['kennel_name'] ?: $d['seller_name']) ?></td>
            <td><?= money($d['price']) ?></td>
            <td><span class="badge badge-<?= $d['listing_status'] === 'approved' ? 'ok' : ($d['listing_status'] === 'rejected' ? 'bad' : 'warn') ?>"><?= e(ucfirst($d['listing_status'])) ?></span></td>
            <td class="actions">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="dog_id" value="<?= $d['dog_id'] ?>">
                    <input type="hidden" name="back_filter" value="<?= e($filter) ?>">
                    <?php if ($d['listing_status'] !== 'approved'): ?><button class="btn btn-primary btn-sm" name="action" value="approve">Approve</button><?php endif; ?>
                    <?php if ($d['listing_status'] !== 'rejected'): ?><button class="btn btn-outline btn-sm" name="action" value="reject">Reject</button><?php endif; ?>
                </form>
                <form method="post" class="inline-form" data-confirm="Delete this listing permanently?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="dog_id" value="<?= $d['dog_id'] ?>">
                    <input type="hidden" name="back_filter" value="<?= e($filter) ?>">
                    <button class="link-danger" name="action" value="delete">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$dogs): ?><tr><td colspan="6" class="center muted">Nothing here.</td></tr><?php endif; ?>
    </tbody>
</table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
