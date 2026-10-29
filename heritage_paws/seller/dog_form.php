<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('seller');
$user = current_user();

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$dog = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM dogs WHERE dog_id = ? AND seller_id = ?');
    $st->execute([$id, $user['id']]);
    $dog = $st->fetch();
    if (!$dog) {
        flash('error', 'Listing not found.');
        redirect('/seller/dashboard.php');
    }
}

$errors = [];
$breeds = $pdo->query('SELECT * FROM breeds ORDER BY breed_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Delete a single photo
    if (isset($_POST['delete_image']) && $dog) {
        $imgId = (int)$_POST['delete_image'];
        $q = $pdo->prepare('SELECT file_name FROM dog_images WHERE image_id = ? AND dog_id = ?');
        $q->execute([$imgId, $id]);
        if ($f = $q->fetchColumn()) {
            delete_image_file($f);
            $pdo->prepare('DELETE FROM dog_images WHERE image_id = ?')->execute([$imgId]);
            flash('success', 'Photo removed.');
        }
        redirect('/seller/dog_form.php?id=' . $id);
    }

    $f = [
        'name'        => trim($_POST['name'] ?? ''),
        'breed_id'    => (int)($_POST['breed_id'] ?? 0),
        'gender'      => $_POST['gender'] ?? '',
        'age_months'  => (int)($_POST['age_months'] ?? -1),
        'price'       => (float)($_POST['price'] ?? 0),
        'color'       => trim($_POST['color'] ?? ''),
        'vaccinated'  => isset($_POST['vaccinated']) ? 1 : 0,
        'location'    => trim($_POST['location'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
    ];

    if ($f['name'] === '')                                   $errors[] = 'Dog name is required.';
    if ($f['breed_id'] < 1)                                  $errors[] = 'Please choose a breed.';
    if (!in_array($f['gender'], ['Male', 'Female'], true))   $errors[] = 'Please choose a gender.';
    if ($f['age_months'] < 0 || $f['age_months'] > 240)      $errors[] = 'Enter a valid age in months.';
    if ($f['price'] <= 0)                                    $errors[] = 'Enter a valid price.';
    if ($f['location'] === '')                               $errors[] = 'Location is required.';
    if (strlen($f['description']) < 20)                      $errors[] = 'Please write a description of at least 20 characters.';

    if (!$errors) {
        if ($dog) {
            // Any edit sends the listing back for admin review
            $pdo->prepare(
                "UPDATE dogs SET breed_id=?, name=?, gender=?, age_months=?, price=?, color=?, vaccinated=?,
                        description=?, location=?, listing_status='pending' WHERE dog_id=? AND seller_id=?"
            )->execute([$f['breed_id'], $f['name'], $f['gender'], $f['age_months'], $f['price'], $f['color'],
                        $f['vaccinated'], $f['description'], $f['location'], $id, $user['id']]);
            $dogId = $id;
        } else {
            $pdo->prepare(
                'INSERT INTO dogs (seller_id, breed_id, name, gender, age_months, price, color, vaccinated, description, location)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$user['id'], $f['breed_id'], $f['name'], $f['gender'], $f['age_months'], $f['price'],
                        $f['color'], $f['vaccinated'], $f['description'], $f['location']]);
            $dogId = (int)$pdo->lastInsertId();
        }

        // Photos
        $existing = $pdo->prepare('SELECT COUNT(*) FROM dog_images WHERE dog_id = ?');
        $existing->execute([$dogId]);
        $have = (int)$existing->fetchColumn();
        $saved = save_dog_images($_FILES['photos'] ?? [], max(0, 5 - $have));
        foreach ($saved as $i => $file) {
            $pdo->prepare('INSERT INTO dog_images (dog_id, file_name, is_primary) VALUES (?,?,?)')
                ->execute([$dogId, $file, ($have === 0 && $i === 0) ? 1 : 0]);
        }

        flash('success', 'Saved! Your listing is awaiting admin approval before it goes public.');
        redirect('/seller/dashboard.php');
    }
    $dog = array_merge($dog ?? [], $f);
}

$images = [];
if ($id) {
    $im = $pdo->prepare('SELECT * FROM dog_images WHERE dog_id = ?');
    $im->execute([$id]);
    $images = $im->fetchAll();
}

$v = fn(string $k, $default = '') => e($dog[$k] ?? $default);
$pageTitle = $id ? 'Edit dog' : 'List a dog';
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL ?>/seller/dashboard.php">&larr; Back to my dogs</a>
<div class="form-card">
    <h2><?= $id ? 'Edit listing' : 'List a new dog' ?></h2>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <?php if ($images): ?>
        <div class="photo-row">
            <?php foreach ($images as $img): ?>
                <form method="post" class="photo-item">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
                    <img src="<?= UPLOAD_URL . e($img['file_name']) ?>" alt="">
                    <button type="submit" name="delete_image" value="<?= $img['image_id'] ?>" class="link-danger">Remove</button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">

        <div class="two-col">
            <label>Dog's name <input type="text" name="name" required value="<?= $v('name') ?>"></label>
            <label>Breed
                <select name="breed_id" required>
                    <option value="">Select breed</option>
                    <?php foreach ($breeds as $b): ?>
                        <option value="<?= $b['breed_id'] ?>" <?= (int)($dog['breed_id'] ?? 0) === (int)$b['breed_id'] ? 'selected' : '' ?>><?= e($b['breed_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="two-col">
            <label>Gender
                <select name="gender" required>
                    <option value="">Select</option>
                    <option <?= ($dog['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                    <option <?= ($dog['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
            </label>
            <label>Age (in months) <input type="number" name="age_months" min="0" max="240" required value="<?= $v('age_months') ?>"></label>
        </div>
        <div class="two-col">
            <label>Price (KES) <input type="number" name="price" min="1" step="1" required value="<?= $v('price') ?>"></label>
            <label>Colour <input type="text" name="color" value="<?= $v('color') ?>"></label>
        </div>
        <label>Location <input type="text" name="location" required value="<?= $v('location', $user ? '' : '') ?>"></label>
        <label class="check"><input type="checkbox" name="vaccinated" <?= !empty($dog['vaccinated']) ? 'checked' : '' ?>> Vaccinated</label>
        <label>Description
            <textarea name="description" rows="5" required minlength="3"
                      placeholder="Temperament, health checks, parents, what's included..."><?= $v('description') ?></textarea>
        </label>
        <label>Photos (JPG, PNG or WebP, up to 5 total )
            <input type="file" name="photos[]" id="photoInput" accept="image/jpeg,image/png,image/webp" multiple>
        </label>
        <div id="photoPreview" class="photo-row"></div>

        <button class="btn btn-primary btn-block" type="submit"><?= $id ? 'Save changes' : 'Submit listing' ?></button>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
