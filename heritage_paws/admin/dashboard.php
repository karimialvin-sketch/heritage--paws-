<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$count = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
$stats = [
    'Buyers'                => $count("SELECT COUNT(*) FROM users WHERE role='buyer'"),
    'Active sellers'        => $count("SELECT COUNT(*) FROM users WHERE role='seller' AND status='active'"),
    'Sellers awaiting approval' => $count("SELECT COUNT(*) FROM users WHERE role='seller' AND status='pending'"),
    'Live listings'         => $count("SELECT COUNT(*) FROM dogs WHERE listing_status='approved' AND availability<>'sold'"),
    'Listings awaiting approval' => $count("SELECT COUNT(*) FROM dogs WHERE listing_status='pending'"),
    'Dogs sold'             => $count("SELECT COUNT(*) FROM dogs WHERE availability='sold'"),
    'Total inquiries'       => $count('SELECT COUNT(*) FROM inquiries'),
        'Stud dogs listed'      => $count("SELECT COUNT(*) FROM stud_dogs WHERE listing_status='approved' AND availability='available'"),
    'Studs awaiting approval' => $count("SELECT COUNT(*) FROM stud_dogs WHERE listing_status='pending'"),
    'Females seeking a stud' => $count("SELECT COUNT(*) FROM female_requests WHERE listing_status='approved' AND request_status='open'"),
    'Female requests awaiting approval' => $count("SELECT COUNT(*) FROM female_requests WHERE listing_status='pending'"),
    'Agreements pending signatures' => $count("SELECT COUNT(*) FROM stud_agreements WHERE status='pending'"),
'Agreements fully signed' => $count("SELECT COUNT(*) FROM stud_agreements WHERE status='signed'"),
];

$recent = $pdo->query(
    "SELECT i.created_at, d.name AS dog_name, b.full_name AS buyer, s.full_name AS seller, i.status
     FROM inquiries i
     JOIN dogs d  ON d.dog_id = i.dog_id
     JOIN users b ON b.user_id = i.buyer_id
     JOIN users s ON s.user_id = i.seller_id
     ORDER BY i.created_at DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/_nav.php';
?>
<h2>Overview</h2>
<div class="stats">
    <?php foreach ($stats as $label => $n): ?>
        <div class="stat <?= str_contains($label, 'awaiting') && $n > 0 ? 'stat-alert' : '' ?>">
            <div class="num"><?= $n ?></div><div class="lbl"><?= e($label) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<h3>Latest inquiries</h3>
<?php if (!$recent): ?>
    <div class="empty">No inquiries yet.</div>
<?php else: ?>
<div class="table-wrap"><table class="data">
    <thead><tr><th>Date</th><th>Dog</th><th>Buyer</th><th>Seller</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr><td><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['dog_name']) ?></td>
            <td><?= e($r['buyer']) ?></td><td><?= e($r['seller']) ?></td><td><?= e(ucfirst($r['status'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
