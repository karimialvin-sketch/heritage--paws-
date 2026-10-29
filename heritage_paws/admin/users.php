<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid    = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    // Admin accounts cannot be changed from this screen (prevents locking yourself out)
    $target = $pdo->prepare("SELECT role FROM users WHERE user_id = ? AND role <> 'admin'");
    $target->execute([$uid]);
    if ($target->fetch()) {
        if ($action === 'approve') {
            $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$uid]);
            flash('success', 'Account approved.');
        } elseif ($action === 'suspend') {
            $pdo->prepare("UPDATE users SET status='suspended' WHERE user_id=?")->execute([$uid]);
            flash('success', 'Account suspended.');
        } elseif ($action === 'activate') {
            $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$uid]);
            flash('success', 'Account re-activated.');
        } elseif ($action === 'delete') {
            $imgs = $pdo->prepare('SELECT i.file_name FROM dog_images i JOIN dogs d ON d.dog_id = i.dog_id WHERE d.seller_id = ?');
            $imgs->execute([$uid]);
            foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $f) delete_image_file($f);
            $pdo->prepare('DELETE FROM users WHERE user_id=?')->execute([$uid]);
            flash('success', 'User and their data deleted.');
             $studImgs = $pdo->prepare('SELECT i.file_name FROM stud_images i JOIN stud_dogs s ON s.stud_id = i.stud_id WHERE s.owner_id = ?');
            $studImgs->execute([$uid]);
            foreach ($studImgs->fetchAll(PDO::FETCH_COLUMN) as $f) delete_image_file($f);
            $femImgs = $pdo->prepare('SELECT i.file_name FROM female_images i JOIN female_requests r ON r.request_id = i.request_id WHERE r.owner_id = ?');
            $femImgs->execute([$uid]);
            foreach ($femImgs->fetchAll(PDO::FETCH_COLUMN) as $f) delete_image_file($f);
        }

    }
    redirect('/admin/users.php?role=' . urlencode($_POST['back_role'] ?? 'all'));
}

$role = $_GET['role'] ?? 'all';
$sql  = "SELECT * FROM users WHERE role <> 'admin'";
$args = [];
if (in_array($role, ['buyer', 'seller'], true)) {
    $sql   .= ' AND role = ?';
    $args[] = $role;
}
$sql .= " ORDER BY (status = 'pending') DESC, created_at DESC";
$st = $pdo->prepare($sql);
$st->execute($args);
$users = $st->fetchAll();

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/_nav.php';
?>
<div class="page-head">
    <h2>Users</h2>
    <div class="tabs">
        <a class="<?= $role === 'all' ? 'active' : '' ?>"    href="?role=all">All</a>
        <a class="<?= $role === 'seller' ? 'active' : '' ?>" href="?role=seller">Sellers</a>
        <a class="<?= $role === 'buyer' ? 'active' : '' ?>"  href="?role=buyer">Buyers</a>
    </div>
</div>

<div class="table-wrap"><table class="data">
    <thead><tr><th>Name</th><th>Role</th><th>Contact</th><th>Location</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><strong><?= e($u['full_name']) ?></strong><?php if ($u['kennel_name']): ?><br><span class="muted"><?= e($u['kennel_name']) ?></span><?php endif; ?></td>
            <td><?= e(ucfirst($u['role'])) ?></td>
            <td><?= e($u['email']) ?><br><span class="muted"><?= e($u['phone']) ?></span></td>
            <td><?= e($u['location']) ?></td>
            <td><?= e(date('d M Y', strtotime($u['created_at']))) ?></td>
            <td><span class="badge badge-<?= $u['status'] === 'active' ? 'ok' : ($u['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= e(ucfirst($u['status'])) ?></span></td>
            <td class="actions">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                    <input type="hidden" name="back_role" value="<?= e($role) ?>">
                    <?php if ($u['status'] === 'pending'): ?>
                        <button class="btn btn-primary btn-sm" name="action" value="approve">Approve</button>
                    <?php elseif ($u['status'] === 'active'): ?>
                        <button class="btn btn-outline btn-sm" name="action" value="suspend">Suspend</button>
                    <?php else: ?>
                        <button class="btn btn-outline btn-sm" name="action" value="activate">Re-activate</button>
                    <?php endif; ?>
                </form>
                <form method="post" class="inline-form" data-confirm="Permanently delete <?= e($u['full_name']) ?> and all their listings and messages?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                    <input type="hidden" name="back_role" value="<?= e($role) ?>">
                    <button class="link-danger" name="action" value="delete">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="7" class="center muted">No users found.</td></tr><?php endif; ?>
    </tbody>
</table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
