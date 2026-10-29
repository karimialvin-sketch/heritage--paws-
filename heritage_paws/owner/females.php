<?php
$pageTitle = 'Mating Requests';
require_once __DIR__ . '/includes/header.php';

// ---- Filters (all optional) ----
$q     = trim($_GET['q'] ?? '');
$breed = (int)($_GET['breed'] ?? 0);
$heat  = $_GET['heat'] ?? '';
$sort  = $_GET['sort'] ?? 'newest';

$where  = ["f.listing_status = 'approved'", "f.request_status = 'open'", "u.status = 'active'"];
$params = [];

if ($q !== '') {
    $where[] = '(f.name LIKE ? OR f.description LIKE ? OR f.location LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($breed > 0) { $where[] = 'f.breed_id = ?'; $params[] = $breed; }
if (in_array($heat, ['in_heat', 'expected_soon', 'not_sure'], true)) { $where[] = 'f.heat_status = ?'; $params[] = $heat; }

$order = $sort === 'oldest' ? 'f.created_at ASC' : 'f.created_at DESC';

$sql = "SELECT f.*, b.breed_name, u.full_name AS owner_name, u.phone AS owner_phone
        FROM female_requests f
        JOIN breeds b ON b.breed_id = f.breed_id
        JOIN users  u ON u.user_id  = f.owner_id
        WHERE " . implode(' AND ', $where) . " ORDER BY $order";
$st = $pdo->prepare($sql);
$st->execute($params);
$females = $st->fetchAll();

$breeds = $pdo->query('SELECT * FROM breeds ORDER BY breed_name')->fetchAll();
?>

<div class="page-intro">
    <h1>Mating Requests</h1>
    <p>These female dogs are looking for a stud. If you own a male, contact the female's owner directly.
       Want to offer your male for stud? <a href="<?= BASE_URL ?>/seller/stud_form.php">List your stud dog</a>
       or <a href="<?= BASE_URL ?>/studs.php">browse available studs</a>.</p>
</div>

<form class="filters filters-female" method="get">
    <input type="text" name="q" placeholder="Search name, location..." value="<?= e($q) ?>">
    <select name="breed">
        <option value="0">All breeds</option>
        <?php foreach ($breeds as $b): ?>
            <option value="<?= $b['breed_id'] ?>" <?= $breed === (int)$b['breed_id'] ? 'selected' : '' ?>><?= e($b['breed_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="heat">
        <option value="">Any heat status</option>
        <option value="in_heat"       <?= $heat === 'in_heat' ? 'selected' : '' ?>>In heat now</option>
        <option value="expected_soon" <?= $heat === 'expected_soon' ? 'selected' : '' ?>>Expected soon</option>
        <option value="not_sure"      <?= $heat === 'not_sure' ? 'selected' : '' ?>>Not sure yet</option>
    </select>
    <select name="sort">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
    </select>
    <button class="btn btn-primary" type="submit">Search</button>
</form>

<p class="result-count"><?= count($females) ?> female dog<?= count($females) === 1 ? '' : 's' ?> looking for a stud</p>

<?php if (!$females): ?>
    <div class="empty">No mating requests match your search yet.</div>
<?php else: ?>
<div class="grid">
    <?php foreach ($females as $f):
        $imgs = listing_images($pdo, 'female_images', (int)$f['request_id']);
        $main = $imgs ? UPLOAD_URL . $imgs[0] : BASE_URL . '/assets/img/no-photo.svg';
        $msg  = "Hello, I saw your female {$f['name']} on Heritage Paws. I have a stud dog and I'd like to talk about mating.";
    ?>
        <article class="card dog-card">
            <img src="<?= e($main) ?>" alt="<?= e($f['name']) ?>">
            <div class="card-body">
                <h3><?= e($f['name']) ?></h3>
                <p class="muted"><?= e($f['breed_name']) ?> &bull; <?= e(age_label((int)$f['age_months'])) ?><?= $f['color'] ? ' &bull; ' . e($f['color']) : '' ?></p>
                <div class="tags">
                    <span class="tag <?= $f['heat_status'] === 'in_heat' ? 'tag-warn' : '' ?>"><?= e(heat_label($f['heat_status'], $f['expected_heat'])) ?></span>
                    <span class="tag"><?= (int)$f['previous_litters'] === 0 ? 'First litter' : (int)$f['previous_litters'] . ' previous litter' . ((int)$f['previous_litters'] === 1 ? '' : 's') ?></span>
                    <?php if ($f['vaccinated']): ?><span class="tag tag-ok">Vaccinated</span><?php endif; ?>
                </div>
                <p class="fee">Owner offers: <?= e(fee_label($f['offer_type'], $f['offer_amount'])) ?></p>
                <p class="muted">&#128205; <?= e($f['location']) ?> &bull; <?= e($f['owner_name']) ?></p>
                <details class="more"><summary>Read more</summary><p><?= nl2br(e($f['description'])) ?></p></details>
                <?php if (count($imgs) > 1): ?>
                    <div class="thumb-strip">
                        <?php foreach (array_slice($imgs, 1) as $file): ?>
                            <a href="<?= e(UPLOAD_URL . $file) ?>" target="_blank" rel="noopener"><img src="<?= e(UPLOAD_URL . $file) ?>" alt=""></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="contact-actions">
                    <a class="btn btn-call btn-sm" href="<?= e(tel_link($f['owner_phone'])) ?>">&#128222; Call owner</a>
                    <a class="btn btn-whatsapp btn-sm" target="_blank" rel="noopener"
                       href="<?= e(whatsapp_text_link($f['owner_phone'], $msg)) ?>">WhatsApp</a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="notice notice-wide">Heritage Paws only connects dog owners. Agree the stud fee, health tests, timing and what happens if there is no litter directly with the other owner, and put it in writing.</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
