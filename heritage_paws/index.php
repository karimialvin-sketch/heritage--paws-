<?php
$pageTitle = 'Browse Dogs';
require_once __DIR__ . '/includes/header.php';

// ---- Filters (all optional) ----
$q       = trim($_GET['q'] ?? '');
$breed   = (int)($_GET['breed'] ?? 0);
$gender  = $_GET['gender'] ?? '';
$maxPrice = (int)($_GET['max_price'] ?? 0);
$sort    = $_GET['sort'] ?? 'newest';

$where  = ["d.listing_status = 'approved'", "d.availability <> 'sold'", "u.status = 'active'"];
$params = [];

if ($q !== '') {
    $where[]  = '(d.name LIKE ? OR d.description LIKE ? OR d.location LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($breed > 0) {
    $where[]  = 'd.breed_id = ?';
    $params[] = $breed;
}
if (in_array($gender, ['Male', 'Female'], true)) {
    $where[]  = 'd.gender = ?';
    $params[] = $gender;
}
if ($maxPrice > 0) {
    $where[]  = 'd.price <= ?';
    $params[] = $maxPrice;
}

$order = match ($sort) {
    'price_low'  => 'd.price ASC',
    'price_high' => 'd.price DESC',
    default      => 'd.created_at DESC',
};

$sql = "SELECT d.*, b.breed_name, u.kennel_name, u.full_name AS seller_name, u.phone AS seller_phone
        FROM dogs d
        JOIN breeds b ON b.breed_id = d.breed_id
        JOIN users  u ON u.user_id  = d.seller_id
        WHERE " . implode(' AND ', $where) . " ORDER BY $order";
$st = $pdo->prepare($sql);
$st->execute($params);
$dogs = $st->fetchAll();

$breeds = $pdo->query('SELECT * FROM breeds ORDER BY breed_name')->fetchAll();
?>

<section class="hero">
    <div class="hero-banner">
        <img src="<?= BASE_URL ?>/assets/img/heritage paws black png.png" alt="Heritage Paws">
    </div>
    <h1>Find your perfect companion</h1>
    <p>Discover your perfect dog with ease. Browse listings freely, connect directly with sellers via call or WhatsApp, and find your ideal companion today.</p>
    <?php if (!is_logged_in()): ?>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/register.php?role=seller">Become a Seller</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/register.php?role=buyer">Create a Buyer account</a>
    <?php endif; ?>
</section>

<form class="filters" method="get">
    <input type="text" name="q" placeholder="Search name, location..." value="<?= e($q) ?>">
    <select name="breed">
        <option value="0">All breeds</option>
        <?php foreach ($breeds as $b): ?>
            <option value="<?= $b['breed_id'] ?>" <?= $breed === (int)$b['breed_id'] ? 'selected' : '' ?>><?= e($b['breed_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="gender">
        <option value="">Any gender</option>
        <option value="Male"   <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
        <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
    </select>
    <input type="number" name="max_price" min="0" placeholder="Max price (KES)" value="<?= $maxPrice ?: '' ?>">
    <select name="sort">
        <option value="newest"     <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        <option value="price_low"  <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: low to high</option>
        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: high to low</option>
    </select>
    <button class="btn btn-primary" type="submit">Search</button>
</form>

<p class="result-count"><?= count($dogs) ?> dog<?= count($dogs) === 1 ? '' : 's' ?> found</p>

<?php if (!$dogs): ?>
    <div class="empty">No dogs match your search yet. Try changing the filters.</div>
<?php else: ?>
<div class="grid">
    <?php foreach ($dogs as $d): ?>
        <article class="card dog-card">
            <a href="<?= BASE_URL ?>/dog.php?id=<?= $d['dog_id'] ?>">
                <img src="<?= e(primary_image($pdo, (int)$d['dog_id'])) ?>" alt="<?= e($d['name']) ?>">
            </a>
            <div class="card-body">
                <?php if ($d['availability'] === 'reserved'): ?><span class="badge badge-warn">Reserved</span><?php endif; ?>
                <h3><?= e($d['name']) ?></h3>
                <p class="muted"><?= e($d['breed_name']) ?> &bull; <?= e($d['gender']) ?> &bull; <?= e(age_label((int)$d['age_months'])) ?></p>
                <p class="muted">&#128205; <?= e($d['location']) ?></p>
                <p class="price"><?= money($d['price']) ?></p>
                <a class="btn btn-outline btn-block" href="<?= BASE_URL ?>/dog.php?id=<?= $d['dog_id'] ?>">View details</a>
                <div class="contact-actions card-contact">
                    <a class="btn btn-call btn-sm" href="<?= e(tel_link($d['seller_phone'])) ?>">&#128222; Call</a>
                    <a class="btn btn-whatsapp btn-sm" target="_blank" rel="noopener"
                       href="<?= e(whatsapp_link($d['seller_phone'], $d['name'])) ?>">WhatsApp</a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>