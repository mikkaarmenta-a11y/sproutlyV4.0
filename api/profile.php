<?php
require __DIR__ . '/../lib/core.php';
require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    try {
        $u = $db->find('app_user', 'user_id', current_user()['user_id']);
        if (!$u || !password_verify($current, $u['password_hash'])) $errors['current_password'] = 'Your current password is incorrect.';
        if (strlen($new) < 6) $errors['new_password'] = 'The new password must be at least 6 characters.';
        if ($new !== $confirm) $errors['confirm_password'] = 'The two new passwords do not match.';
        if (!$errors) {
            $db->update('app_user', 'user_id', $u['user_id'], [
                'password_hash' => password_hash($new, PASSWORD_DEFAULT),
                'updated_by'    => $u['username'],
            ]);
            flash('success', 'Password changed successfully.');
            redirect('profile.php');
        }
    } catch (SupabaseException $ex) {
        $errors['_'] = friendly($ex, 'update');
    }
}

layout_start('Change password'); ?>
<h3 class="mb-3"><i class="bi bi-key"></i> Change password</h3>
<?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php endif; ?>
<form method="post" class="card p-3 needs-validation" novalidate style="max-width:460px">
  <?= csrf_field() ?>
  <?php foreach (['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password'] as $n => $label): ?>
    <div class="mb-3">
      <label class="form-label" for="<?= $n ?>"><?= $label ?> <span class="text-danger">*</span></label>
      <input type="password" id="<?= $n ?>" name="<?= $n ?>" class="form-control <?= isset($errors[$n]) ? 'is-invalid' : '' ?>" required minlength="<?= $n === 'current_password' ? 1 : 6 ?>">
      <?php if (isset($errors[$n])): ?><div class="invalid-feedback d-block"><?= e($errors[$n]) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="d-flex gap-2">
    <button class="btn btn-success"><i class="bi bi-check-lg"></i> Update password</button>
    <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
  </div>
</form>
<?php layout_end();
