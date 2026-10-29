<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
date_default_timezone_set('Africa/Nairobi');

/* ---------------- Date range (default: last 30 days) ---------------- */
function rp_date(?string $d): ?string {
    if ($d && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $d;
    }
    return null;
}
$today = date('Y-m-d');
$from  = rp_date($_GET['from'] ?? null) ?? date('Y-m-d', strtotime('-29 days'));
$to    = rp_date($_GET['to'] ?? null)   ?? $today;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$range = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];

$q = function (string $sql, array $args = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
};

/* ---------------- 1. Visitor summary ---------------- */
$sum = $q(
    "SELECT COUNT(*) AS views,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(DISTINCT user_id) AS members,
            SUM(CASE WHEN user_role = 'guest' THEN 1 ELSE 0 END) AS guest_views,
            SUM(CASE WHEN device = 'mobile' THEN 1 ELSE 0 END) AS mobile_views
     FROM site_visits WHERE visited_at >= ? AND visited_at < ?", $range)[0];
$views   = (int)$sum['views'];
$visitors = (int)$sum['visitors'];
$mobilePct = $views ? round(((int)$sum['mobile_views'] / $views) * 100) : 0;

$daily = $q(
    "SELECT DATE(visited_at) AS day, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors
     FROM site_visits WHERE visited_at >= ? AND visited_at < ?
     GROUP BY DATE(visited_at) ORDER BY day DESC", $range);
$maxDaily = 0;
foreach ($daily as $d) { $maxDaily = max($maxDaily, (int)$d['views']); }

$topPages = $q(
    "SELECT page, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors
     FROM site_visits WHERE visited_at >= ? AND visited_at < ?
     GROUP BY page ORDER BY views DESC LIMIT 10", $range);
$pageNames = [
    '/index.php' => 'Home / browse dogs', '/dog.php' => 'Dog details', '/login.php' => 'Login',
    '/register.php' => 'Register', '/buyer/dashboard.php' => 'Buyer dashboard',
    '/seller/dashboard.php' => 'Seller dashboard', '/seller/inquiries.php' => 'Seller inquiries',
    '/seller/dog_form.php' => 'Seller dog form',
];

$topDogs = $q(
    "SELECT d.dog_id, d.name, b.breed_name, u.full_name AS seller_name, u.kennel_name,
            COUNT(*) AS views, COUNT(DISTINCT v.visitor_id) AS visitors
     FROM site_visits v
     JOIN dogs d   ON d.dog_id = v.dog_id
     JOIN breeds b ON b.breed_id = d.breed_id
     JOIN users u  ON u.user_id = d.seller_id
     WHERE v.visited_at >= ? AND v.visited_at < ?
     GROUP BY d.dog_id, d.name, b.breed_name, u.full_name, u.kennel_name
     ORDER BY views DESC LIMIT 10", $range);

/* ---------------- 2. Sellers and their listings ---------------- */
$sellers = $q(
    "SELECT u.user_id, u.full_name, u.kennel_name, u.email, u.phone, u.location, u.status, u.created_at,
        (SELECT COUNT(*) FROM dogs d WHERE d.seller_id = u.user_id) AS total_dogs,
        (SELECT COUNT(*) FROM dogs d WHERE d.seller_id = u.user_id AND d.listing_status = 'approved' AND d.availability <> 'sold') AS live_dogs,
        (SELECT COUNT(*) FROM dogs d WHERE d.seller_id = u.user_id AND d.listing_status = 'pending') AS pending_dogs,
        (SELECT COUNT(*) FROM dogs d WHERE d.seller_id = u.user_id AND d.availability = 'sold') AS sold_dogs,
        (SELECT COUNT(*) FROM inquiries i WHERE i.seller_id = u.user_id AND i.created_at >= ? AND i.created_at < ?) AS inquiries,
        (SELECT COUNT(*) FROM site_visits v JOIN dogs d ON d.dog_id = v.dog_id WHERE d.seller_id = u.user_id AND v.visited_at >= ? AND v.visited_at < ?) AS dog_views
     FROM users u WHERE u.role = 'seller'
     ORDER BY total_dogs DESC, u.full_name", array_merge($range, $range));
$tot = ['total_dogs' => 0, 'live_dogs' => 0, 'pending_dogs' => 0, 'sold_dogs' => 0, 'inquiries' => 0, 'dog_views' => 0];
foreach ($sellers as $s) { foreach ($tot as $k => $_) { $tot[$k] += (int)$s[$k]; } }

/* ---------------- 3. Newly registered users ---------------- */
$newUsers = $q(
    "SELECT full_name, role, email, phone, location, kennel_name, status, created_at
     FROM users WHERE role <> 'admin' AND created_at >= ? AND created_at < ?
     ORDER BY created_at DESC", $range);
$newBuyers = $newSellers = 0;
foreach ($newUsers as $u) { $u['role'] === 'seller' ? $newSellers++ : $newBuyers++; }

/* ---------------- CSV export (?export=visitors|pages|dogs|sellers|users) ---------------- */
$export = $_GET['export'] ?? '';
if ($export !== '') {
    $csv = [
        'visitors' => [['Date', 'Page views', 'Unique visitors'],
            array_map(fn($r) => [$r['day'], $r['views'], $r['visitors']], $daily)],
        'pages'    => [['Page', 'Views', 'Unique visitors'],
            array_map(fn($r) => [$pageNames[$r['page']] ?? $r['page'], $r['views'], $r['visitors']], $topPages)],
        'dogs'     => [['Dog', 'Breed', 'Seller', 'Views', 'Unique visitors'],
            array_map(fn($r) => [$r['name'], $r['breed_name'], $r['kennel_name'] ?: $r['seller_name'], $r['views'], $r['visitors']], $topDogs)],
        'sellers'  => [['Seller', 'Kennel', 'Email', 'Phone', 'Location', 'Account status', 'Joined', 'Total listings', 'Live', 'Pending approval', 'Sold', 'Inquiries (period)', 'Dog page views (period)'],
            array_map(fn($r) => [$r['full_name'], $r['kennel_name'], $r['email'], $r['phone'], $r['location'], $r['status'],
                date('Y-m-d', strtotime($r['created_at'])), $r['total_dogs'], $r['live_dogs'], $r['pending_dogs'], $r['sold_dogs'], $r['inquiries'], $r['dog_views']], $sellers)],
        'users'    => [['Name', 'Role', 'Kennel', 'Email', 'Phone', 'Location', 'Status', 'Registered'],
            array_map(fn($r) => [$r['full_name'], $r['role'], $r['kennel_name'], $r['email'], $r['phone'], $r['location'], $r['status'],
                date('Y-m-d H:i', strtotime($r['created_at']))], $newUsers)],
    ];
    if (isset($csv[$export])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="heritage-paws-' . $export . '-' . $from . '-to-' . $to . '.csv"');
        echo "\xEF\xBB\xBF";                       // lets Excel read the file correctly
        $out = fopen('php://output', 'w');
        // Stop spreadsheet formulas ("=...", "@...") typed by users from running when opened
        $safe = fn($v) => (is_string($v) && preg_match('/^[=@\t\r]|^[+-][^0-9\s]/', $v)) ? "'" . $v : $v;
        fputcsv($out, $csv[$export][0], ',', '"', '');
        foreach ($csv[$export][1] as $row) { fputcsv($out, array_map($safe, $row), ',', '"', ''); }
        fclose($out);
        exit;
    }
}

/* ---------------- Page ---------------- */
$presets = [
    'Today'        => [$today, $today],
    'Last 7 days'  => [date('Y-m-d', strtotime('-6 days')), $today],
    'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), $today],
    'This month'   => [date('Y-m-01'), $today],
    'All time'     => ['2000-01-01', $today],
];
$qs  = fn(array $extra = []) => '?' . http_build_query(array_merge(['from' => $from, 'to' => $to], $extra));
$fmt = fn(string $d) => date('d M Y', strtotime($d));

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/_nav.php';
?>
<div class="page-head no-print">
    <h2>Reports</h2>
    <button class="btn btn-outline" type="button" onclick="window.print()">&#128424; Print / save as PDF</button>
</div>

<form class="report-filters no-print" method="get">
    <label>From <input type="date" name="from" value="<?= e($from) ?>" max="<?= e($today) ?>"></label>
    <label>To   <input type="date" name="to"   value="<?= e($to) ?>"   max="<?= e($today) ?>"></label>
    <button class="btn btn-primary" type="submit">Generate report</button>
    <div class="presets">
        <?php foreach ($presets as $label => [$f, $t]): ?>
            <a class="btn btn-outline btn-sm" href="?from=<?= $f ?>&to=<?= $t ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</form>

<div class="print-only">
    <h1>Heritage Paws &ndash; Admin Report</h1>
    <p>Generated <?= e(date('d M Y, H:i')) ?> by <?= e(current_user()['name']) ?></p>
</div>
<p class="muted">Report period: <strong><?= e($fmt($from)) ?></strong> to <strong><?= e($fmt($to)) ?></strong></p>

<!-- ============ 1. WEBSITE VISITORS ============ -->
<div class="report-section">
    <h3>1. Website visitors</h3>
    <span class="no-print">
        <a class="btn btn-outline btn-sm" href="<?= e($qs(['export' => 'visitors'])) ?>">Download daily CSV</a>
    </span>
</div>

<div class="stats">
    <div class="stat"><div class="num"><?= number_format($views) ?></div><div class="lbl">Page views</div></div>
    <div class="stat"><div class="num"><?= number_format($visitors) ?></div><div class="lbl">Unique visitors</div></div>
    <div class="stat"><div class="num"><?= (int)$sum['members'] ?></div><div class="lbl">Logged-in members who visited</div></div>
    <div class="stat"><div class="num"><?= number_format((int)$sum['guest_views']) ?></div><div class="lbl">Views by guests (no account)</div></div>
    <div class="stat"><div class="num"><?= $mobilePct ?>%</div><div class="lbl">Views from phones</div></div>
    <div class="stat"><div class="num"><?= $newBuyers ?> / <?= $newSellers ?></div><div class="lbl">New buyers / new sellers</div></div>
</div>

<?php if (!$daily): ?>
    <div class="empty">No visits were recorded in this period. Visits are counted from the day tracking was switched on.</div>
<?php else: ?>
<div class="table-wrap"><table class="data">
    <thead><tr><th>Date</th><th class="r">Page views</th><th class="r">Unique visitors</th><th style="width:35%">Activity</th></tr></thead>
    <tbody>
    <?php foreach ($daily as $d): ?>
        <tr>
            <td><?= e(date('D, d M Y', strtotime($d['day']))) ?></td>
            <td class="r"><?= (int)$d['views'] ?></td>
            <td class="r"><?= (int)$d['visitors'] ?></td>
            <td><div class="bar"><span style="width: <?= $maxDaily ? round(($d['views'] / $maxDaily) * 100) : 0 ?>%"></span></div></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="two-tables">
    <div>
        <div class="report-section">
            <h3>Most viewed dogs</h3>
            <span class="no-print"><a class="btn btn-outline btn-sm" href="<?= e($qs(['export' => 'dogs'])) ?>">CSV</a></span>
        </div>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Dog</th><th>Seller</th><th class="r">Views</th><th class="r">Visitors</th></tr></thead>
            <tbody>
            <?php foreach ($topDogs as $d): ?>
                <tr>
                    <td><strong><?= e($d['name']) ?></strong><br><span class="muted"><?= e($d['breed_name']) ?></span></td>
                    <td><?= e($d['kennel_name'] ?: $d['seller_name']) ?></td>
                    <td class="r"><?= (int)$d['views'] ?></td><td class="r"><?= (int)$d['visitors'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topDogs): ?><tr><td colspan="4" class="center muted">No dog views yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <div>
        <div class="report-section">
            <h3>Most visited pages</h3>
            <span class="no-print"><a class="btn btn-outline btn-sm" href="<?= e($qs(['export' => 'pages'])) ?>">CSV</a></span>
        </div>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Page</th><th class="r">Views</th><th class="r">Visitors</th></tr></thead>
            <tbody>
            <?php foreach ($topPages as $p): ?>
                <tr><td><?= e($pageNames[$p['page']] ?? $p['page']) ?></td>
                    <td class="r"><?= (int)$p['views'] ?></td><td class="r"><?= (int)$p['visitors'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$topPages): ?><tr><td colspan="3" class="center muted">No visits yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- ============ 2. SELLERS ============ -->
<div class="report-section">
    <h3>2. Sellers and their listed dogs</h3>
    <span class="no-print"><a class="btn btn-outline btn-sm" href="<?= e($qs(['export' => 'sellers'])) ?>">Download CSV</a></span>
</div>
<p class="muted">Listing counts are all-time. Inquiries and dog page views are for the report period.</p>
<div class="table-wrap"><table class="data">
    <thead><tr>
        <th>Seller</th><th>Contact</th><th>Location</th><th>Status</th><th>Joined</th>
        <th class="r">Listed</th><th class="r">Live</th><th class="r">Pending</th><th class="r">Sold</th>
        <th class="r">Inquiries</th><th class="r">Dog views</th>
    </tr></thead>
    <tbody>
    <?php foreach ($sellers as $s): ?>
        <tr>
            <td><strong><?= e($s['full_name']) ?></strong><?php if ($s['kennel_name']): ?><br><span class="muted"><?= e($s['kennel_name']) ?></span><?php endif; ?></td>
            <td><?= e($s['phone']) ?><br><span class="muted"><?= e($s['email']) ?></span></td>
            <td><?= e($s['location']) ?></td>
            <td><span class="badge badge-<?= $s['status'] === 'active' ? 'ok' : ($s['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= e(ucfirst($s['status'])) ?></span></td>
            <td><?= e($fmt($s['created_at'])) ?></td>
            <td class="r"><?= (int)$s['total_dogs'] ?></td><td class="r"><?= (int)$s['live_dogs'] ?></td>
            <td class="r"><?= (int)$s['pending_dogs'] ?></td><td class="r"><?= (int)$s['sold_dogs'] ?></td>
            <td class="r"><?= (int)$s['inquiries'] ?></td><td class="r"><?= (int)$s['dog_views'] ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$sellers): ?><tr><td colspan="11" class="center muted">No sellers registered yet.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($sellers): ?>
    <tfoot><tr>
        <td colspan="5">Total (<?= count($sellers) ?> seller<?= count($sellers) === 1 ? '' : 's' ?>)</td>
        <td class="r"><?= $tot['total_dogs'] ?></td><td class="r"><?= $tot['live_dogs'] ?></td>
        <td class="r"><?= $tot['pending_dogs'] ?></td><td class="r"><?= $tot['sold_dogs'] ?></td>
        <td class="r"><?= $tot['inquiries'] ?></td><td class="r"><?= $tot['dog_views'] ?></td>
    </tr></tfoot>
    <?php endif; ?>
</table></div>

<!-- ============ 3. NEW USERS ============ -->
<div class="report-section">
    <h3>3. Users who registered in this period (<?= count($newUsers) ?>)</h3>
    <span class="no-print"><a class="btn btn-outline btn-sm" href="<?= e($qs(['export' => 'users'])) ?>">Download CSV</a></span>
</div>
<div class="table-wrap"><table class="data">
    <thead><tr><th>Name</th><th>Role</th><th>Contact</th><th>Location</th><th>Status</th><th>Registered</th></tr></thead>
    <tbody>
    <?php foreach ($newUsers as $u): ?>
        <tr>
            <td><strong><?= e($u['full_name']) ?></strong><?php if ($u['kennel_name']): ?><br><span class="muted"><?= e($u['kennel_name']) ?></span><?php endif; ?></td>
            <td><?= e(ucfirst($u['role'])) ?></td>
            <td><?= e($u['phone']) ?><br><span class="muted"><?= e($u['email']) ?></span></td>
            <td><?= e($u['location']) ?></td>
            <td><span class="badge badge-<?= $u['status'] === 'active' ? 'ok' : ($u['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= e(ucfirst($u['status'])) ?></span></td>
            <td><?= e(date('d M Y, H:i', strtotime($u['created_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$newUsers): ?><tr><td colspan="6" class="center muted">Nobody registered in this period.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>