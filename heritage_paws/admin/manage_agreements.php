<?php
// Admin: every stud service agreement on the platform
require_once __DIR__ . '/../includes/agreement_helpers.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id     = (int)($_POST['agreement_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel') {
        $pdo->prepare("UPDATE stud_agreements SET status = 'cancelled', cancelled_by = ?, cancelled_at = ? WHERE agreement_id = ? AND status = 'pending'")
            ->execute([current_user()['id'], agr_now(), $id]);
        flash('success', 'Agreement cancelled.');
    } elseif ($action === 'delete') {
        // only cancelled drafts can be removed; signed agreements are kept as a record
        $pdo->prepare("DELETE FROM stud_agreements WHERE agreement_id = ? AND status = 'cancelled'")->execute([$id]);
        flash('success', 'Agreement deleted.');
    }
    redirect('/admin/manage_agreements.php' . (!empty($_POST['back']) ? '?' . ltrim((string)$_POST['back'], '?') : ''));
}

$status = in_array($_GET['status'] ?? '', ['pending', 'signed', 'cancelled'], true) ? $_GET['status'] : '';
$q      = trim($_GET['q'] ?? '');

$where = []; $params = [];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '')      { $where[] = '(agreement_no LIKE ? OR snapshot LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }

$st = $pdo->prepare('SELECT * FROM stud_agreements' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY agreement_id DESC');
$st->execute($params);
$rows = [];
foreach ($st->fetchAll() as $r) { $r['snap'] = json_decode((string)$r['snapshot'], true) ?: []; $rows[] = $r; }

$counts = ['pending' => 0, 'signed' => 0, 'cancelled' => 0];
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM stud_agreements GROUP BY status')->fetchAll() as $c) $counts[$c['status']] = (int)$c['n'];

$back = http_build_query(array_filter(['status' => $status, 'q' => $q]));
$pageTitle = 'Agreements';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/_nav.php';
?>
<div class="page-head"><h2>Stud service agreements</h2></div>

<div class="stats">
    <div class="stat <?= $counts['pending'] ? 'stat-alert' : '' ?>"><div class="num"><?= $counts['pending'] ?></div><div class="lbl">Awaiting signatures</div></div>
    <div class="stat"><div class="num"><?= $counts['signed'] ?></div><div class="lbl">Fully signed</div></div>
    <div class="stat"><div class="num"><?= $counts['cancelled'] ?></div><div class="lbl">Cancelled</div></div>
</div>

<form class="filters filters-agr" method="get">
    <input type="text" name="q" placeholder="Search agreement no. or a name..." value="<?= e($q) ?>">
    <select name="status">
        <option value="">All statuses</option>
        <option value="pending"   <?= $status === 'pending' ? 'selected' : '' ?>>Awaiting signatures</option>
        <option value="signed"    <?= $status === 'signed' ? 'selected' : '' ?>>Fully signed</option>
        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
    </select>
    <button class="btn btn-primary" type="submit">Filter</button>
</form>

<?php if (!$rows): ?>
    <div class="empty">No agreements found.</div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Agreement</th><th>Stud owner &amp; dog</th><th>Female owner &amp; dog</th><th>Fee</th><th>Signatures</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): [$label, $cls] = agreement_status_label($a); ?>
        <tr>
            <td><strong><?= e($a['agreement_no']) ?></strong><br><span class="muted"><?= e(agr_d($a['created_at'])) ?></span></td>
            <td><?= e($a['snap']['stud_owner']['name'] ?? '') ?><br><span class="muted"><?= e($a['snap']['stud']['name'] ?? '') ?></span></td>
            <td><?= e($a['snap']['female_owner']['name'] ?? '') ?><br><span class="muted"><?= e($a['snap']['female']['name'] ?? '') ?></span></td>
            <td><?= e(agreement_fee_text($a)) ?></td>
            <td><span class="muted">Stud:</span> <?= agreement_signed($a, 'stud') ? '&#10003; ' . e(agr_d($a['stud_signed_at'])) : '&mdash;' ?><br>
                <span class="muted">Female:</span> <?= agreement_signed($a, 'female') ? '&#10003; ' . e(agr_d($a['female_signed_at'])) : '&mdash;' ?></td>
            <td><span class="badge badge-<?= $cls ?>"><?= e($label) ?></span></td>
            <td class="actions">
                <a href="<?= BASE_URL ?>/owner/agreement_view.php?id=<?= (int)$a['agreement_id'] ?>">View</a>
                <a href="<?= BASE_URL ?>/owner/agreement_pdf.php?id=<?= (int)$a['agreement_id'] ?>">PDF</a>
                <?php if ($a['status'] === 'pending'): ?>
                    <form method="post" class="inline-form" data-confirm="Cancel agreement <?= e($a['agreement_no']) ?>?">
                        <?= csrf_field() ?><input type="hidden" name="agreement_id" value="<?= (int)$a['agreement_id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                        <input type="hidden" name="action" value="cancel"><button class="link-danger" type="submit">Cancel</button>
                    </form>
                <?php elseif ($a['status'] === 'cancelled'): ?>
                    <form method="post" class="inline-form" data-confirm="Delete this cancelled agreement permanently?">
                        <?= csrf_field() ?><input type="hidden" name="agreement_id" value="<?= (int)$a['agreement_id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                        <input type="hidden" name="action" value="delete"><button class="link-danger" type="submit">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="muted">Signed agreements are kept as a permanent record and cannot be deleted. Only cancelled ones can be removed.</p>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
