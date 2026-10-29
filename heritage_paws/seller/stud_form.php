<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('seller');
$user = current_user();

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stud = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM stud_dogs WHERE stud_id = ? AND owner_id = ?');
    $st->execute([$id, $user['id']]);
    $stud = $st->fetch();
    if (!$stud) {
        flash('error', 'Listing not found.');
        redirect('/seller/studs.php');
    }
}

$errors = [];
$breeds = $pdo->query('SELECT * FROM breeds ORDER BY breed_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Delete a single photo
    if (isset($_POST['delete_image']) && $stud) {
        $imgId = (int)$_POST['delete_image'];
        $q = $pdo->prepare('SELECT file_name FROM stud_images WHERE image_id = ? AND stud_id = ?');
        $q->execute([$imgId, $id]);
        if ($file = $q->fetchColumn()) {
            delete_image_file($file);
            $pdo->prepare('DELETE FROM stud_images WHERE image_id = ?')->execute([$imgId]);
            flash('success', 'Photo removed.');
        }
        redirect('/seller/stud_form.php?id=' . $id);
    }

    $feeType = in_array($_POST['fee_type'] ?? '', ['cash', 'puppy_pick', 'negotiable'], true) ? $_POST['fee_type'] : 'cash';
    $f = [
        'name'          => trim($_POST['name'] ?? ''),
        'breed_id'      => (int)($_POST['breed_id'] ?? 0),
        'age_months'    => (int)($_POST['age_months'] ?? -1),
        'color'         => trim($_POST['color'] ?? ''),
        'fee_type'      => $feeType,
        'stud_fee'      => $feeType === 'cash' ? (float)($_POST['stud_fee'] ?? 0) : 0,
        'proven_sire'   => isset($_POST['proven_sire']) ? 1 : 0,
        'litters_sired' => max(0, (int)($_POST['litters_sired'] ?? 0)),
        'health_tested' => isset($_POST['health_tested']) ? 1 : 0,
        'vaccinated'    => isset($_POST['vaccinated']) ? 1 : 0,
        'pedigree'      => isset($_POST['pedigree']) ? 1 : 0,
        'location'      => trim($_POST['location'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
    ];
    if (!$f['proven_sire']) $f['litters_sired'] = 0;

    if ($f['name'] === '')                                  $errors[] = 'Dog name is required.';
    if ($f['breed_id'] < 1)                                 $errors[] = 'Please choose a breed.';
    if ($f['age_months'] < 6 || $f['age_months'] > 240)     $errors[] = 'Enter the dog\'s age in months (at least 6).';
    if ($feeType === 'cash' && $f['stud_fee'] <= 0)         $errors[] = 'Enter the stud fee, or choose "Pick of the litter" / "Negotiable".';
    if ($f['location'] === '')                              $errors[] = 'Location is required.';
    if (strlen($f['description']) < 20)                     $errors[] = 'Please write a description of at least 20 characters.';

    if (!$errors) {
        if ($stud) {
            // Any edit sends the listing back for admin review
            $pdo->prepare(
                "UPDATE stud_dogs SET breed_id=?, name=?, age_months=?, color=?, fee_type=?, stud_fee=?, proven_sire=?,
                        litters_sired=?, health_tested=?, vaccinated=?, pedigree=?, description=?, location=?,
                        listing_status='pending' WHERE stud_id=? AND owner_id=?"
            )->execute([$f['breed_id'], $f['name'], $f['age_months'], $f['color'], $f['fee_type'], $f['stud_fee'],
                        $f['proven_sire'], $f['litters_sired'], $f['health_tested'], $f['vaccinated'], $f['pedigree'],
                        $f['description'], $f['location'], $id, $user['id']]);
            $studId = $id;
        } else {
            $pdo->prepare(
                'INSERT INTO stud_dogs (owner_id, breed_id, name, age_months, color, fee_type, stud_fee, proven_sire,
                        litters_sired, health_tested, vaccinated, pedigree, description, location)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$user['id'], $f['breed_id'], $f['name'], $f['age_months'], $f['color'], $f['fee_type'],
                        $f['stud_fee'], $f['proven_sire'], $f['litters_sired'], $f['health_tested'], $f['vaccinated'],
                        $f['pedigree'], $f['description'], $f['location']]);
            $studId = (int)$pdo->lastInsertId();
        }

        // Photos (up to 5 in total)
        $have = count(listing_images($pdo, 'stud_images', $studId));
        $saved = save_dog_images($_FILES['photos'] ?? [], max(0, 5 - $have));
        foreach ($saved as $i => $file) {
            $pdo->prepare('INSERT INTO stud_images (stud_id, file_name, is_primary) VALUES (?,?,?)')
                ->execute([$studId, $file, ($have === 0 && $i === 0) ? 1 : 0]);
        }

        flash('success', 'Saved! Your stud listing is awaiting admin approval before it goes public.');
        redirect('/seller/studs.php');
    }
    $stud = array_merge($stud ?? [], $f);
}

// Default location for a new listing = the seller's own location
$defaultLoc = '';
if (!$stud) {
    $l = $pdo->prepare('SELECT location FROM users WHERE user_id = ?');
    $l->execute([$user['id']]);
    $defaultLoc = (string)$l->fetchColumn();
}

$images = [];
if ($id) {
    $im = $pdo->prepare('SELECT * FROM stud_images WHERE stud_id = ?');
    $im->execute([$id]);
    $images = $im->fetchAll();
}

$v   = fn(string $k, $default = '') => e($stud[$k] ?? $default);
$chk = fn(string $k) => !empty($stud[$k]) ? 'checked' : '';
$pageTitle = $id ? 'Edit stud dog' : 'List a stud dog';
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL ?>/seller/studs.php">&larr; Back to my stud dogs</a>
<div class="form-card">
    <h2><?= $id ? 'Edit stud listing' : 'List a stud dog' ?></h2>
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
                        <option value="<?= $b['breed_id'] ?>" <?= (int)($stud['breed_id'] ?? 0) === (int)$b['breed_id'] ? 'selected' : '' ?>><?= e($b['breed_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="two-col">
            <label>Age (in months) <input type="number" name="age_months" min="6" max="240" required value="<?= $v('age_months') ?>"></label>
            <label>Colour <input type="text" name="color" value="<?= $v('color') ?>"></label>
        </div>
        <div class="two-col">
            <label>Stud fee type
                <select name="fee_type" id="feeType">
                    <option value="cash"       <?= ($stud['fee_type'] ?? 'cash') === 'cash' ? 'selected' : '' ?>>Cash fee</option>
                    <option value="puppy_pick" <?= ($stud['fee_type'] ?? '') === 'puppy_pick' ? 'selected' : '' ?>>Pick of the litter</option>
                    <option value="negotiable" <?= ($stud['fee_type'] ?? '') === 'negotiable' ? 'selected' : '' ?>>Negotiable</option>
                </select>
            </label>
            <label id="feeAmountWrap">Stud fee (KES)
                <input type="number" name="stud_fee" min="0" step="1" value="<?= !empty($stud['stud_fee']) ? e((int)$stud['stud_fee']) : '' ?>">
            </label>
        </div>
        <label>Location <input type="text" name="location" required value="<?= $v('location', $defaultLoc) ?>"></label>

        <div class="check-grid">
            <label class="check"><input type="checkbox" name="proven_sire" id="provenSire" <?= $chk('proven_sire') ?>> Proven sire (has sired litters)</label>
            <label class="check"><input type="checkbox" name="health_tested" <?= $chk('health_tested') ?>> Health tested</label>
            <label class="check"><input type="checkbox" name="vaccinated" <?= $chk('vaccinated') ?>> Vaccinated</label>
            <label class="check"><input type="checkbox" name="pedigree" <?= $chk('pedigree') ?>> Has pedigree / registration papers</label>
        </div>
        <label id="littersWrap">Number of litters sired
            <input type="number" name="litters_sired" min="0" value="<?= $v('litters_sired', '0') ?>">
        </label>

        <label>Description
            <textarea name="description" rows="5" required minlength="20"
                      placeholder="Temperament, size, achievements, health test results, conditions for stud service..."><?= $v('description') ?></textarea>
        </label>
        <label>Photos (JPG, PNG or WebP, up to 5 total, 3 MB each)
            <input type="file" name="photos[]" id="photoInput" accept="image/jpeg,image/png,image/webp" multiple>
        </label>
        <div id="photoPreview" class="photo-row"></div>

        <button class="btn btn-primary btn-block" type="submit"><?= $id ? 'Save changes' : 'Submit stud listing' ?></button>
    </form>
</div>
<script>
// Show the fee box only for cash fees, and the litters box only for proven sires
(function () {
    var type = document.getElementById('feeType'), fee = document.getElementById('feeAmountWrap');
    var sire = document.getElementById('provenSire'), lit = document.getElementById('littersWrap');
    function sync() { fee.style.display = type.value === 'cash' ? '' : 'none'; lit.style.display = sire.checked ? '' : 'none'; }
    type.addEventListener('change', sync); sire.addEventListener('change', sync); sync();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
