<?php
// A seller's or buyer's own stud service agreements
require_once __DIR__ . '/../includes/agreement_helpers.php';
require_role('buyer', 'seller');
$user = current_user();

$st = $pdo->prepare('SELECT * FROM stud_agreements WHERE stud_owner_id = ? OR female_owner_id = ? ORDER BY agreement_id DESC');
$st->execute([$user['id'], $user['id']]);
$rows = [];
foreach ($st->fetchAll() as $r) {
    $r['snap'] = json_decode((string)$r['snapshot'], true) ?: [];
    $rows[] = $r;
}

$back = $user['role'] === 'seller' ? '/seller/dashboard.php' : '/buyer/dashboard.php';
$pageTitle = 'My Agreements';
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL . $back ?>">&larr; Back to my dashboard</a>
<div class="page-head">
    <h2>My stud service agreements</h2>
    <div>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/studs.php">Find a stud</a>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/females.php">Mating requests</a>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="empty">You have no agreements yet. To start one, open <a href="<?= BASE_URL ?>/studs.php">Stud Services</a> (if you own the female)
        or <a href="<?= BASE_URL ?>/females.php">Mating Requests</a> (if you own the stud) and click <strong>Draft agreement</strong> on the dog.</div>
<?php else: ?>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Agreement</th><th>Dogs</th><th>Other party</th><th>Stud fee</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a):
        $me    = agreement_party($a, (int)$user['id']);
        $other = $me === 'stud' ? 'female' : 'stud';
        [$label, $cls] = agreement_status_label($a);
        $note = '';
        if ($a['status'] === 'pending') {
            $note = agreement_signed($a, $me) ? 'Waiting for ' . ($a['snap'][$other . '_owner']['name'] ?? 'the other party') . ' to sign' : 'Needs your signature';
        }
    ?>
        <tr>
            <td><strong><?= e($a['agreement_no']) ?></strong><br><span class="muted"><?= e(agr_d($a['created_at'])) ?> &bull; you are the <?= $me === 'stud' ? 'stud owner' : 'female owner' ?></span></td>
            <td><?= e($a['snap']['stud']['name'] ?? '') ?> &times; <?= e($a['snap']['female']['name'] ?? '') ?><br>
                <span class="muted"><?= e($a['snap']['stud']['breed'] ?? '') ?></span></td>
            <td><?= e($a['snap'][$other . '_owner']['name'] ?? '') ?></td>
            <td><?= e(agreement_fee_text($a)) ?></td>
            <td><span class="badge badge-<?= $cls ?>"><?= e($label) ?></span>
                <?php if ($note): ?><br><span class="muted"><?= e($note) ?></span><?php endif; ?></td>
            <td class="actions">
                <a href="<?= BASE_URL ?>/owner/agreement_view.php?id=<?= (int)$a['agreement_id'] ?>"><?= $a['status'] === 'pending' && !agreement_signed($a, $me) ? 'Review &amp; sign' : 'Open' ?></a>
                <a href="<?= BASE_URL ?>/owner/agreement_pdf.php?id=<?= (int)$a['agreement_id'] ?>">Download PDF</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="muted">The PDF can be downloaded at any time. It shows "Awaiting signatures" until both owners have signed, and then becomes the final signed copy.</p>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
