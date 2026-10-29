<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('buyer', 'seller');
$user = current_user();

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$female = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM female_requests WHERE request_id = ? AND owner_id = ?');
    $st->execute([$id, $user['id']]);
    $female = $st->fetch();
    if (!$female) {
        flash('error', 'Listing not found.');
        redirect('/owner/females.php');
    }
}

$errors = [];
$breeds = $pdo->query('SELECT * FROM breeds ORDER BY breed_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Delete a single photo
    if (isset($_POST['delete_image']) && $female) {
        $imgId = (int)$_POST['delete_image'];
        $q = $pdo->prepare('SELECT file_name FROM female_images WHERE image_id = ? AND request_id = ?');
        $q->execute([$imgId, $id]);
        if ($file = $q->fetchColumn()) {
            delete_image_file($file);
            $pdo->prepare('DELETE FROM female_images WHERE image_id = ?')->execute([$imgId]);
            flash('success', 'Photo removed.');
        }
        redirect('/owner/female_form.php?id=' . $id);
    }

    $offerType  = in_array($_POST['offer_type'] ?? '', ['cash', 'puppy_pick', 'negotiable'], true) ? $_POST['offer_type'] : 'negotiable';
    $heatStatus = in_array($_POST['heat_status'] ?? '', ['in_heat', 'expected_soon', 'not_sure'], true) ? $_POST['heat_status'] : 'not_sure';
    $heatDate   = trim($_POST['expected_heat'] ?? '');
    if ($heatStatus !== 'expected_soon' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $heatDate)) $heatDate = null;

    $f = [
        'name'             => trim($_POST['name'] ?? ''),
        'breed_id'         => (int)($_POST['breed_id'] ?? 0),
        'age_months'       => (int)($_POST['age_months'] ?? -1),
        'color'            => trim($_POST['color'] ?? ''),
        'vaccinated'       => isset($_POST['vaccinated']) ? 1 : 0,
        'previous_litters' => max(0, (int)($_POST['previous_litters'] ?? 0)),
        'heat_status'      => $heatStatus,
        'expected_heat'    => $heatDate,
        'offer_type'       => $offerType,
        'offer_amount'     => $offerType === 'cash' ? (float)($_POST['offer_amount'] ?? 0) : 0,
        'location'         => trim($_POST['location'] ?? ''),
        'description'      => trim($_POST['description'] ?? ''),
    ];

    if ($f['name'] === '')                                  $errors[] = 'Dog name is required.';
    if ($f['breed_id'] < 1)                                 $errors[] = 'Please choose a breed.';
    if ($f['age_months'] < 6 || $f['age_months'] > 240)     $errors[] = 'Enter the dog\'s age in months (at least 6).';
    if ($offerType === 'cash' && $f['offer_amount'] <= 0)   $errors[] = 'Enter the amount you will pay, or choose another option.';
    if ($f['location'] === '')                              $errors[] = 'Location is required.';
    if (strlen($f['description']) < 20)                     $errors[] = 'Please describe your female and what kind of stud you want (at least 20 characters).';

    if (!$errors) {
        if ($female) {
            // Any edit sends the listing back for admin review
            $pdo->prepare(
                "UPDATE female_requests SET breed_id=?, name=?, age_months=?, color=?, vaccinated=?, previous_litters=?,
                        heat_status=?, expected_heat=?, offer_type=?, offer_amount=?, description=?, location=?,
                        listing_status='pending' WHERE request_id=? AND owner_id=?"
            )->execute([$f['breed_id'], $f['name'], $f['age_months'], $f['color'], $f['vaccinated'], $f['previous_litters'],
                        $f['heat_status'], $f['expected_heat'], $f['offer_type'], $f['offer_amount'], $f['description'],
                        $f['location'], $id, $user['id']]);
            $reqId = $id;
        } else {
            $pdo->prepare(
                'INSERT INTO female_requests (owner_id, breed_id, name, age_months, color, vaccinated, previous_litters,
                        heat_status, expected_heat, offer_type, offer_amount, description, location)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$user['id'], $f['breed_id'], $f['name'], $f['age_months'], $f['color'], $f['vaccinated'],
                        $f['previous_litters'], $f['heat_status'], $f['expected_heat'], $f['offer_type'],
                        $f['offer_amount'], $f['description'], $f['location']]);
            $reqId = (int)$pdo->lastInsertId();
        }

        // Photos (up to 5 in total)
        $have = count(listing_images($pdo, 'female_images', $reqId));
        $saved = save_dog_images($_FILES['photos'] ?? [], max(0, 5 - $have));
        foreach ($saved as $i => $file) {
            $pdo->prepare('INSERT INTO female_images (request_id, file_name, is_primary) VALUES (?,?,?)')
                ->execute([$reqId, $file, ($have === 0 && $i === 0) ? 1 : 0]);
        }

        flash('success', 'Saved! Your request is awaiting admin approval before it goes public.');
        redirect('/owner/females.php');
    }
    $female = array_merge($female ?? [], $f);
}

// Default location for a new listing = the owner's own location
$defaultLoc = '';
if (!$female) {
    $l = $pdo->prepare('SELECT location FROM users WHERE user_id = ?');
    $l->execute([$user['id']]);
    $defaultLoc = (string)$l->fetchColumn();
}

$images = [];
if ($id) {
    $im = $pdo->prepare('SELECT * FROM female_images WHERE request_id = ?');
    $im->execute([$id]);
    $images = $im->fetchAll();
}

$v = fn(string $k, $default = '') => e($female[$k] ?? $default);
$pageTitle = $id ? 'Edit female dog' : 'Post a female dog';
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL ?>/owner/females.php">&larr; Back to my female dogs</a>
<div class="form-card">
    <h2><?= $id ? 'Edit female dog' : 'Post a female dog looking for a stud' ?></h2>
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
                        <option value="<?= $b['breed_id'] ?>" <?= (int)($female['breed_id'] ?? 0) === (int)$b['breed_id'] ? 'selected' : '' ?>><?= e($b['breed_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="two-col">
            <label>Age (in months) <input type="number" name="age_months" min="6" max="240" required value="<?= $v('age_months') ?>"></label>
            <label>Colour <input type="text" name="color" value="<?= $v('color') ?>"></label>
        </div>
        <div class="two-col">
            <label>Previous litters <input type="number" name="previous_litters" min="0" value="<?= $v('previous_litters', '0') ?>"></label>
            <label class="check check-pad"><input type="checkbox" name="vaccinated" <?= !empty($female['vaccinated']) ? 'checked' : '' ?>> Vaccinated</label>
        </div>
        <div class="two-col">
            <label>Heat status
                <select name="heat_status" id="heatStatus">
                    <option value="not_sure"      <?= ($female['heat_status'] ?? 'not_sure') === 'not_sure' ? 'selected' : '' ?>>Not sure yet</option>
                    <option value="in_heat"       <?= ($female['heat_status'] ?? '') === 'in_heat' ? 'selected' : '' ?>>In heat now</option>
                    <option value="expected_soon" <?= ($female['heat_status'] ?? '') === 'expected_soon' ? 'selected' : '' ?>>Expected in heat soon</option>
                </select>
            </label>
            <label id="heatDateWrap">Expected heat date
                <input type="date" name="expected_heat" value="<?= $v('expected_heat') ?>">
            </label>
        </div>
        <div class="two-col">
            <label>What you offer the stud owner
                <select name="offer_type" id="offerType">
                    <option value="negotiable" <?= ($female['offer_type'] ?? 'negotiable') === 'negotiable' ? 'selected' : '' ?>>Negotiable</option>
                    <option value="cash"       <?= ($female['offer_type'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash fee</option>
                    <option value="puppy_pick" <?= ($female['offer_type'] ?? '') === 'puppy_pick' ? 'selected' : '' ?>>Pick of the litter</option>
                </select>
            </label>
            <label id="offerAmountWrap">Amount you will pay (KES)
                <input type="number" name="offer_amount" min="0" step="1" value="<?= !empty($female['offer_amount']) ? e((int)$female['offer_amount']) : '' ?>">
            </label>
        </div>
        <label>Location <input type="text" name="location" required value="<?= $v('location', $defaultLoc) ?>"></label>
        <label>Description
            <textarea name="description" rows="5" required minlength="20"
                      placeholder="Temperament, health, and what kind of stud you want (breed, size, health tests, papers)..."><?= $v('description') ?></textarea>
        </label>
        <label>Photos (JPG, PNG or WebP, up to 5 total, 3 MB each)
            <input type="file" name="photos[]" id="photoInput" accept="image/jpeg,image/png,image/webp" multiple>
        </label>
        <div id="photoPreview" class="photo-row"></div>

        <p class="notice">Your phone number is shown publicly on this request so stud owners can call or WhatsApp you.</p>
        <button class="btn btn-primary btn-block" type="submit"><?= $id ? 'Save changes' : 'Submit request' ?></button>
    </form>
</div>
<script>
// Show the date box only when heat is "expected soon", and the amount box only for a cash offer
(function () {
    var heat = document.getElementById('heatStatus'), date = document.getElementById('heatDateWrap');
    var offer = document.getElementById('offerType'), amt = document.getElementById('offerAmountWrap');
    function sync() { date.style.display = heat.value === 'expected_soon' ? '' : 'none'; amt.style.display = offer.value === 'cash' ? '' : 'none'; }
    heat.addEventListener('change', sync); offer.addEventListener('change', sync); sync();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
