<?php
$pageTitle = 'Stud Services';
require_once __DIR__ . '/includes/header.php';

// ---- Filters (all optional) ----
$q      = trim($_GET['q'] ?? '');
$breed  = (int)($_GET['breed'] ?? 0);
$maxFee = (int)($_GET['max_fee'] ?? 0);
$proven = isset($_GET['proven']);
$health = isset($_GET['health']);
$sort   = $_GET['sort'] ?? 'newest';

$where  = ["s.listing_status = 'approved'", "s.availability = 'available'", "u.status = 'active'"];
$params = [];

if ($q !== '') {
    $where[] = '(s.name LIKE ? OR s.description LIKE ? OR s.location LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($breed > 0)  { $where[] = 's.breed_id = ?'; $params[] = $breed; }
if ($maxFee > 0) { $where[] = "(s.fee_type <> 'cash' OR s.stud_fee <= ?)"; $params[] = $maxFee; }
if ($proven)     { $where[] = 's.proven_sire = 1'; }
if ($health)     { $where[] = 's.health_tested = 1'; }

$order = match ($sort) {
    'fee_low'  => "CASE WHEN s.fee_type = 'cash' THEN s.stud_fee ELSE 999999999 END ASC",
    'fee_high' => "CASE WHEN s.fee_type = 'cash' THEN s.stud_fee ELSE -1 END DESC",
    default    => 's.created_at DESC',
};

$sql = "SELECT s.*, b.breed_name, u.full_name AS owner_name, u.kennel_name, u.phone AS owner_phone
        FROM stud_dogs s
        JOIN breeds b ON b.breed_id = s.breed_id
        JOIN users  u ON u.user_id  = s.owner_id
        WHERE " . implode(' AND ', $where) . " ORDER BY $order";
$st = $pdo->prepare($sql);
$st->execute($params);
$studs = $st->fetchAll();

$breeds = $pdo->query('SELECT * FROM breeds ORDER BY breed_name')->fetchAll();
?>

<div class="page-intro">
    <h1>Stud Services</h1>
    <p>Find a quality male dog for your female. Browse studs offered by their owners, then call or WhatsApp the owner directly.
       Are you the owner of a female looking for a stud? <a href="<?= BASE_URL ?>/females.php">See mating requests</a>
       or <a href="<?= BASE_URL ?>/owner/female_form.php">post your female</a>.</p>
</div>

<form class="filters filters-stud" method="get">
    <input type="text" name="q" placeholder="Search name, location..." value="<?= e($q) ?>">
    <select name="breed">
        <option value="0">All breeds</option>
        <?php foreach ($breeds as $b): ?>
            <option value="<?= $b['breed_id'] ?>" <?= $breed === (int)$b['breed_id'] ? 'selected' : '' ?>><?= e($b['breed_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="number" name="max_fee" min="0" placeholder="Max fee (KES)" value="<?= $maxFee ?: '' ?>">
    <select name="sort">
        <option value="newest"   <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        <option value="fee_low"  <?= $sort === 'fee_low' ? 'selected' : '' ?>>Fee: low to high</option>
        <option value="fee_high" <?= $sort === 'fee_high' ? 'selected' : '' ?>>Fee: high to low</option>
    </select>
    <label class="check"><input type="checkbox" name="proven" <?= $proven ? 'checked' : '' ?>> Proven sire</label>
    <label class="check"><input type="checkbox" name="health" <?= $health ? 'checked' : '' ?>> Health tested</label>
    <button class="btn btn-primary" type="submit">Search</button>
</form>

<p class="result-count"><?= count($studs) ?> stud dog<?= count($studs) === 1 ? '' : 's' ?> available</p>

<?php if (!$studs): ?>
    <div class="empty">No stud dogs match your search yet. Try changing the filters.</div>
<?php else: ?>
<div class="grid">
    <?php foreach ($studs as $s):
        $imgs = listing_images($pdo, 'stud_images', (int)$s['stud_id']);
        $main = $imgs ? UPLOAD_URL . $imgs[0] : BASE_URL . '/assets/img/no-photo.svg';
        $msg  = "Hello, I saw your stud dog {$s['name']} on Heritage Paws. I have a female and I'd like to ask about stud service.";
    ?>
        <article class="card dog-card">
            <img src="<?= e($main) ?>" alt="<?= e($s['name']) ?>">
            <div class="card-body">
                <h3><?= e($s['name']) ?></h3>
                <p class="muted"><?= e($s['breed_name']) ?> &bull; <?= e(age_label((int)$s['age_months'])) ?><?= $s['color'] ? ' &bull; ' . e($s['color']) : '' ?></p>
                <div class="tags">
                    <?php if ($s['proven_sire']): ?><span class="tag tag-ok">Proven sire<?= $s['litters_sired'] > 0 ? ' (' . (int)$s['litters_sired'] . ' litters)' : '' ?></span><?php endif; ?>
                    <?php if ($s['health_tested']): ?><span class="tag tag-ok">Health tested</span><?php endif; ?>
                    <?php if ($s['vaccinated']): ?><span class="tag">Vaccinated</span><?php endif; ?>
                    <?php if ($s['pedigree']): ?><span class="tag">Pedigree papers</span><?php endif; ?>
                </div>
                <p class="fee">Stud fee: <?= e(fee_label($s['fee_type'], $s['stud_fee'])) ?></p>
                <p class="muted">&#128205; <?= e($s['location']) ?> &bull; <?= e($s['kennel_name'] ?: $s['owner_name']) ?></p>
                <details class="more"><summary>Read more</summary><p><?= nl2br(e($s['description'])) ?></p></details>
                <?php if (count($imgs) > 1): ?>
                    <div class="thumb-strip">
                        <?php foreach (array_slice($imgs, 1) as $f): ?>
                            <a href="<?= e(UPLOAD_URL . $f) ?>" target="_blank" rel="noopener"><img src="<?= e(UPLOAD_URL . $f) ?>" alt=""></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="contact-actions">
                    <a class="btn btn-call btn-sm" href="<?= e(tel_link($s['owner_phone'])) ?>">&#128222; Call owner</a>
                    <a class="btn btn-whatsapp btn-sm" target="_blank" rel="noopener"
                       href="<?= e(whatsapp_text_link($s['owner_phone'], $msg)) ?>">WhatsApp</a>
                       <?php if ($user && in_array($user['role'], ['buyer', 'seller'], true) && (int)$user['id'] !== (int)$s['owner_id']): ?>
    <a class="btn btn-outline btn-sm" href="<?= BASE_URL ?>/owner/agreement_new.php?stud_id=<?= (int)$s['stud_id'] ?>">&#128196; Draft agreement</a>
<?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="notice notice-wide">Heritage Paws only connects dog owners. Agree the stud fee, health tests, timing and what happens if there is no litter directly with the owner, and put it in writing.</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
