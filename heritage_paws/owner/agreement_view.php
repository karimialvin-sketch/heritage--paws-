<?php
// Read an agreement, sign it (typed name + password), cancel it, or download the PDF
require_once __DIR__ . '/../includes/agreement_helpers.php';
require_role('buyer', 'seller', 'admin');
$user = current_user();
$me   = (int)$user['id'];

$a = agreement_load($pdo, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$a || !agreement_can_view($a, $user)) {
    http_response_code(404);
    die('Agreement not found.');
}
$party  = agreement_party($a, $me);          // 'stud', 'female' or null (admin)
$other  = $party === 'stud' ? 'female' : 'stud';
$errors = [];

$norm = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$party) { http_response_code(403); die('Access denied.'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'sign') {
        $fails = $_SESSION['sign_fail'] ?? ['n' => 0, 'until' => 0];
        $typed = trim(preg_replace('/\s+/u', ' ', (string)($_POST['typed_name'] ?? '')));

        if ($a['status'] !== 'pending')              $errors[] = 'This agreement is no longer open for signing.';
        elseif (agreement_signed($a, $party))        $errors[] = 'You have already signed this agreement.';
        elseif (time() < $fails['until'])            $errors[] = 'Too many wrong attempts. Please wait 10 minutes and try again.';
        else {
            if ($norm($typed) !== $norm($a['snap'][$party . '_owner']['name'] ?? '')) {
                $errors[] = 'Type your full name exactly as it appears in the box above (' . $a['snap'][$party . '_owner']['name'] . ').';
            }
            if (empty($_POST['agree']))              $errors[] = 'Tick the box to confirm you have read and agree to the agreement.';
            if (!$errors) {
                $h = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
                $h->execute([$me]);
                if (!password_verify((string)($_POST['password'] ?? ''), (string)$h->fetchColumn())) {
                    $errors[] = 'Your password is not correct.';
                    $fails['n']++;
                    if ($fails['n'] >= 5) { $fails = ['n' => 0, 'until' => time() + 600]; }
                    $_SESSION['sign_fail'] = $fails;
                }
            }
        }

        if (!$errors) {
            $up = $pdo->prepare("UPDATE stud_agreements SET {$party}_signed_name = ?, {$party}_signed_at = ?, {$party}_signed_ip = ?
                                 WHERE agreement_id = ? AND status = 'pending' AND {$party}_signed_at IS NULL");
            $up->execute([mb_substr($typed, 0, 120), agr_now(), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $a['agreement_id']]);
            unset($_SESSION['sign_fail']);

            $fresh = agreement_load($pdo, (int)$a['agreement_id']);
            if ($fresh && agreement_signed($fresh, 'stud') && agreement_signed($fresh, 'female')) {
                $pdo->prepare("UPDATE stud_agreements SET status = 'signed' WHERE agreement_id = ? AND status = 'pending'")->execute([$a['agreement_id']]);
                flash('success', 'You have signed. Both owners have now signed. The agreement is complete and the PDF is ready to download.');
            } else {
                flash('success', 'You have signed. The other owner can now sign. You can send them a WhatsApp message from this page.');
            }
            redirect('/owner/agreement_view.php?id=' . (int)$a['agreement_id']);
        }
    } elseif ($action === 'cancel' && $a['status'] === 'pending') {
        $pdo->prepare("UPDATE stud_agreements SET status = 'cancelled', cancelled_by = ?, cancelled_at = ? WHERE agreement_id = ? AND status = 'pending'")
            ->execute([$me, agr_now(), $a['agreement_id']]);
        flash('success', 'Agreement cancelled.');
        redirect('/owner/agreement_view.php?id=' . (int)$a['agreement_id']);
    }
}

$snap = $a['snap'];
[$statusText, $statusCls] = agreement_status_label($a);
$iSigned     = $party ? agreement_signed($a, $party) : false;
$canSign     = $party && $a['status'] === 'pending' && !$iSigned;
$otherName   = $snap[$other . '_owner']['name'] ?? 'the other owner';
$backUrl     = $user['role'] === 'admin' ? '/admin/manage_agreements.php' : '/owner/my_agreements.php';
$wa = '';
if ($party && $a['status'] === 'pending' && !empty($snap[$other . '_owner']['phone'])) {
    $wa = whatsapp_text_link($snap[$other . '_owner']['phone'],
        "Hello {$otherName}, I have drafted a stud service agreement for {$snap['stud']['name']} and {$snap['female']['name']} on Heritage Paws ({$a['agreement_no']}). "
        . 'Please log in to review and sign it here: ' . agreement_url($a));
}

$pageTitle = 'Agreement ' . $a['agreement_no'];
require_once __DIR__ . '/../includes/header.php';
?>
<a class="back-link" href="<?= BASE_URL . $backUrl ?>">&larr; <?= $user['role'] === 'admin' ? 'Back to all agreements' : 'Back to my agreements' ?></a>

<div class="page-head">
    <h2>Stud service agreement <span class="agr-no"><?= e($a['agreement_no']) ?></span></h2>
    <span class="badge badge-<?= $statusCls ?>"><?= e($statusText) ?></span>
</div>

<div class="agr-actions no-print">
    <a class="btn btn-primary" href="<?= BASE_URL ?>/owner/agreement_pdf.php?id=<?= (int)$a['agreement_id'] ?>">&#11015; Download PDF<?= $a['status'] === 'pending' ? ' (draft copy)' : '' ?></a>
    <?php if ($party && agreement_editable($a, $me)): ?>
        <a class="btn btn-outline" href="<?= BASE_URL ?>/owner/agreement_new.php?id=<?= (int)$a['agreement_id'] ?>">Edit terms</a>
    <?php endif; ?>
    <?php if ($wa): ?>
        <a class="btn btn-whatsapp" target="_blank" rel="noopener" href="<?= e($wa) ?>">WhatsApp <?= e(explode(' ', $otherName)[0]) ?></a>
    <?php endif; ?>
    <?php if ($party && $a['status'] === 'pending'): ?>
        <form method="post" class="inline-form" data-confirm="Cancel this agreement? The other owner will no longer be able to sign it.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['agreement_id'] ?>"><input type="hidden" name="action" value="cancel">
            <button class="btn btn-outline" type="submit">Cancel agreement</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($a['status'] === 'signed'): ?>
    <div class="alert alert-success">Fully signed. Both owners signed this agreement, and the PDF above is the final signed copy.</div>
<?php elseif ($a['status'] === 'cancelled'): ?>
    <div class="alert alert-error">This agreement was cancelled<?= $a['cancelled_at'] ? ' on ' . e(agr_dt($a['cancelled_at'])) : '' ?>. It can no longer be signed.</div>
<?php elseif ($canSign): ?>
    <div class="alert alert-warn">Please read the whole agreement below. When you agree, sign at the bottom of the page.</div>
<?php elseif ($iSigned): ?>
    <div class="alert alert-success">You have signed. Waiting for <?= e($otherName) ?> to sign.</div>
<?php elseif (!$party): ?>
    <div class="alert alert-warn">You are viewing this agreement as an admin. Only the two owners can sign it.</div>
<?php endif; ?>

<article class="agr-doc">
    <h3>1. The parties</h3>
    <div class="agr-boxes">
        <?php foreach ([['Stud Owner', 'stud_owner'], ['Female Owner', 'female_owner']] as [$title, $key]): ?>
            <div class="agr-box"><h4><?= e($title) ?></h4>
                <dl><?php foreach (agreement_party_rows($snap[$key]) as [$l, $val]): ?><dt><?= e($l) ?></dt><dd><?= e($val) ?></dd><?php endforeach; ?></dl>
            </div>
        <?php endforeach; ?>
    </div>

    <h3>2. The dogs</h3>
    <div class="agr-boxes">
        <?php foreach ([['Stud Dog', 'stud'], ['Female Dog', 'female']] as [$title, $key]): ?>
            <div class="agr-box"><h4><?= e($title) ?></h4>
                <dl><?php foreach (agreement_dog_rows($snap[$key], $key) as [$l, $val]): ?><dt><?= e($l) ?></dt><dd><?= e($val) ?></dd><?php endforeach; ?></dl>
            </div>
        <?php endforeach; ?>
    </div>

    <?php foreach (agreement_sections($a) as $sc): ?>
        <h3><?= (int)$sc['no'] ?>. <?= e($sc['title']) ?></h3>
        <?php foreach ($sc['items'] as $it): ?>
            <p class="agr-clause"><span class="agr-n"><?= e($it['no']) ?></span><span><?= agr_html($it['text']) ?></span></p>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <h3>13. Signatures</h3>
    <div class="agr-boxes">
        <?php foreach ([['Stud Owner', 'stud'], ['Female Owner', 'female']] as [$title, $who]):
            $signed = agreement_signed($a, $who); ?>
            <div class="agr-box agr-sig <?= $signed ? 'is-signed' : '' ?>"><h4><?= e($title) ?></h4>
                <?php if ($signed): ?>
                    <div class="sig-name"><?= e($a[$who . '_signed_name']) ?></div>
                    <div class="sig-meta">Signed electronically by <?= e($snap[$who . '_owner']['name']) ?><br>
                        <?= e(agr_dt($a[$who . '_signed_at'])) ?><br>IP address: <?= e($a[$who . '_signed_ip'] ?: 'not recorded') ?></div>
                <?php else: ?>
                    <div class="sig-name sig-wait">Awaiting signature</div>
                    <div class="sig-meta"><?= e($snap[$who . '_owner']['name']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="muted fp">Document fingerprint (SHA-256): <code><?= e(agreement_fingerprint($a)) ?></code></p>
</article>

<?php if ($canSign): ?>
<div class="form-card agr-sign-form no-print">
    <h2>Sign this agreement</h2>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <p>You are signing as the <strong><?= $party === 'stud' ? 'Stud Owner' : 'Female Owner' ?></strong>:
       <strong><?= e($snap[$party . '_owner']['name']) ?></strong></p>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['agreement_id'] ?>"><input type="hidden" name="action" value="sign">
        <label>Type your full name as your signature
            <input type="text" name="typed_name" required placeholder="<?= e($snap[$party . '_owner']['name']) ?>" value="<?= e($_POST['typed_name'] ?? '') ?>"></label>
        <label>Confirm with your account password
            <input type="password" name="password" required autocomplete="current-password"></label>
        <label class="check"><input type="checkbox" name="agree" required> I have read this agreement and agree to its terms. I understand that typing my name here is my signature.</label>
        <button class="btn btn-primary btn-block" type="submit">Sign agreement</button>
    </form>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
