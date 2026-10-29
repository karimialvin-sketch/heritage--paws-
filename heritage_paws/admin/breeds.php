<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['breed_name'] ?? '');
        if ($name === '' || strlen($name) > 100) {
            flash('error', 'Enter a breed name.');
        } else {
            try {
                $pdo->prepare('INSERT INTO breeds (breed_name) VALUES (?)')->execute([$name]);
                flash('success', 'Breed added.');
            } catch (PDOException $ex) {
                flash('error', 'That breed already exists.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['breed_id'] ?? 0);
        $used = $pdo->prepare('SELECT COUNT(*) FROM dogs WHERE breed_id = ?');
        $used->execute([$id]);
        if ($used->fetchColumn() > 0) {
            flash('error', 'This breed is used by existing listings and cannot be deleted.');
        } else {
            $pdo->prepare('DELETE FROM breeds WHERE breed_id = ?')->execute([$id]);
            flash('success', 'Breed deleted.');
        }
    }
    redirect('/admin/breeds.php');
}

$breeds = $pdo->query(
    'SELECT b.*, (SELECT COUNT(*) FROM dogs d WHERE d.breed_id = b.breed_id) AS dog_count
     FROM breeds b ORDER BY b.breed_name'
)->fetchAll();

$pageTitle = 'Manage Breeds';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/_nav.php';
?>
<h2>Breeds</h2>
<form method="post" class="inline-add">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input type="text" name="breed_name" placeholder="New breed name" required maxlength="100">
    <button class="btn btn-primary" type="submit">Add breed</button>
</form>

<div class="table-wrap"><table class="data">
    <thead><tr><th>Breed</th><th>Listings</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($breeds as $b): ?>
        <tr>
            <td><?= e($b['breed_name']) ?></td>
            <td><?= (int)$b['dog_count'] ?></td>
            <td class="actions">
                <form method="post" class="inline-form" data-confirm="Delete <?= e($b['breed_name']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="breed_id" value="<?= $b['breed_id'] ?>">
                    <button class="link-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
