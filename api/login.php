<?php
require __DIR__ . '/../lib/core.php';

if (current_user()) redirect('index.php');

$mode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
$error = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = ($_POST['mode'] ?? 'login') === 'register' ? 'register' : 'login';

    if ($mode === 'register') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $roleVal  = (string)($_POST['role_id'] ?? '2');
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if ($fullName === '' || str_len($fullName) < 2 || str_len($fullName) > 100) {
            $errors['full_name'] = 'Full name must be between 2 and 100 characters.';
        }
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $errors['username'] = 'Username must be 3-50 characters (letters, numbers, _ . -).';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || str_len($email) > 100) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if (!in_array($roleVal, ['1', '2'], true)) {
            $errors['role_id'] = 'Please select a valid account role.';
        }
        if (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }
        if ($confirm !== $password) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (!$errors) {
            try {
                if ($db->find('app_user', 'username', $username, 'user_id')) {
                    $errors['username'] = 'This username is already taken.';
                }
                if ($db->find('app_user', 'email', $email, 'user_id')) {
                    $errors['email'] = 'This email address is already registered.';
                }
                if (!$errors) {
                    $created = $db->insert('app_user', [
                        'username'      => $username,
                        'full_name'     => $fullName,
                        'email'         => $email,
                        'role_id'       => (int)$roleVal,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'created_by'    => $username,
                        'approval_status' => 'pending',
                    ]);
                    flash('success', 'Your account request was sent. You can sign in once the Super Admin approves it.');
                    redirect('login.php');
                }
            } catch (SupabaseException $ex) {
                $error = friendly($ex, 'create account');
            }
        }
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            try {
                $u = $db->find('app_user', 'username', $username, '*,user_role(role_name)');
                if (!$u && filter_var($username, FILTER_VALIDATE_EMAIL)) {
                    $u = $db->find('app_user', 'email', $username, '*,user_role(role_name)');
                }
                if ($u && password_verify($password, $u['password_hash'])) {
                    $appr = (string)($u['approval_status'] ?? 'approved');
                    if ($appr === 'pending') {
                        $error = 'Your account is waiting for approval by the Super Admin. Please try again later.';
                    } elseif ($appr === 'rejected') {
                        $error = 'Your account request was declined. Please contact the Super Admin.';
                    }
                }
                if (!$error && $u && password_verify($password, $u['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'user_id'   => $u['user_id'],
                        'username'  => $u['username'],
                        'full_name' => $u['full_name'],
                        'role_id'   => (int)$u['role_id'],
                        'logged_in_at' => gmdate('c'),
                    ];
                    redirect('index.php');
                }
                if (!$error) $error = 'Invalid username or password.';
            } catch (SupabaseException $ex) {
                $error = friendly($ex, 'sign in');
            }
        }
    }
}

layout_start($mode === 'register' ? 'Create Account' : 'Login'); ?>
<div class="login-wrap">
  <div class="auth-backdrop" aria-hidden="true">
    <div class="auth-blob auth-blob-green"></div>
    <div class="auth-blob auth-blob-pink"></div>
    <svg viewBox="0 0 1440 220" preserveAspectRatio="none" class="auth-hills">
      <path d="M0,120 C240,40 460,180 740,115 C1020,50 1240,165 1440,95 L1440,220 L0,220 Z" fill="#e4f1e4"/>
      <path d="M0,158 C310,110 560,205 880,145 C1150,95 1310,175 1440,140 L1440,220 L0,220 Z" fill="#d5ead6"/>
    </svg>
  </div>

  <div class="card auth-card-php">
    <div class="auth-banner-php">
      <svg viewBox="0 0 44 44" class="auth-sticker-left" aria-hidden="true">
        <path d="M22 36 C22 25 22 18 22 14" stroke="#6ea57b" stroke-width="2.2" stroke-linecap="round" fill="none"/>
        <path d="M21 24 C14 24 10 19 10 14 C16 13 20 17 21 24 Z" fill="#a8dab2"/>
        <path d="M23 20 C30 20 34 15 34 10 C28 9 24 13 23 20 Z" fill="#bfe6c7"/>
      </svg>
      <svg viewBox="0 0 44 44" class="auth-sticker-right" aria-hidden="true">
        <circle cx="22" cy="14" r="4.5" fill="#f9c6d3"/><circle cx="29.5" cy="19.5" r="4.5" fill="#f9c6d3"/><circle cx="26.5" cy="28" r="4.5" fill="#f9c6d3"/><circle cx="17.5" cy="28" r="4.5" fill="#f9c6d3"/><circle cx="14.5" cy="19.5" r="4.5" fill="#f9c6d3"/><circle cx="22" cy="22" r="3.6" fill="#ffe394"/>
      </svg>
      <svg viewBox="0 0 112 98" class="auth-mascot-php" aria-hidden="true">
        <circle cx="56" cy="50" r="40" fill="#e6f4e8"/>
        <circle cx="24" cy="34" r="10" fill="#fde9ee" opacity="0.9"/>
        <circle cx="88" cy="32" r="8" fill="#e5f1fc" opacity="0.9"/>
        <g transform="translate(18 24)"><circle cx="0" cy="-4.5" r="3.4" fill="#f7b7c6"/><circle cx="4.3" cy="-1.4" r="3.4" fill="#f7b7c6"/><circle cx="2.7" cy="3.6" r="3.4" fill="#f7b7c6"/><circle cx="-2.7" cy="3.6" r="3.4" fill="#f7b7c6"/><circle cx="-4.3" cy="-1.4" r="3.4" fill="#f7b7c6"/><circle cx="0" cy="0" r="2.6" fill="#ffe08a"/></g>
        <path d="M91 18 C94 23 95.5 26 95.5 28.2 A4.5 4.5 0 1 1 86.5 28.2 C86.5 26 88 23 91 18 Z" fill="#b9def7" stroke="#8bc2ea" stroke-width="1.2"/>
        <path d="M27 65 L28.5 68.5 L32 70 L28.5 71.5 L27 75 L25.5 71.5 L22 70 L25.5 68.5 Z" fill="#f9d889"/>
        <path d="M56 50 C56 38 56 31 56 26" fill="none" stroke="#467353" stroke-width="3.5" stroke-linecap="round"/>
        <path d="M55 34 C44 35 34 29 33 20 C42 18 52 23 55 34 Z" fill="#82c491" stroke="#467353" stroke-width="2.2" stroke-linejoin="round"/>
        <path d="M53 32 C46 28 42 25 39 23" fill="none" stroke="#d8f3dc" stroke-width="1.5" stroke-linecap="round"/>
        <path d="M57 30 C67 31 77 25 78 16 C69 14 59 19 57 30 Z" fill="#99d4a5" stroke="#467353" stroke-width="2.2" stroke-linejoin="round"/>
        <path d="M59 28 C66 24 70 21 73 19" fill="none" stroke="#e6f8e9" stroke-width="1.5" stroke-linecap="round"/>
        <ellipse cx="56" cy="51" rx="20" ry="5" fill="#7c5d49"/>
        <path d="M36 53 H76 L71 81 C70.4 84.5 67.4 87 63.8 87 H48.2 C44.6 87 41.6 84.5 41 81 Z" fill="#f6d8c7" stroke="#b88469" stroke-width="2.2" stroke-linejoin="round"/>
        <rect x="33" y="48" width="46" height="8" rx="4" fill="#fce8dc" stroke="#b88469" stroke-width="2.2"/>
        <path d="M46.5 67.5 Q48.5 64.8 50.5 67.5" fill="none" stroke="#5b3e2f" stroke-width="2" stroke-linecap="round"/>
        <path d="M61.5 67.5 Q63.5 64.8 65.5 67.5" fill="none" stroke="#5b3e2f" stroke-width="2" stroke-linecap="round"/>
        <ellipse cx="44.5" cy="70.5" rx="3" ry="1.8" fill="#f39fb2" opacity="0.85"/>
        <ellipse cx="67.5" cy="70.5" rx="3" ry="1.8" fill="#f39fb2" opacity="0.85"/>
        <path d="M53.2 70.5 Q56 73.5 58.8 70.5" fill="none" stroke="#5b3e2f" stroke-width="2" stroke-linecap="round"/>
      </svg>
      <h4 class="auth-title-php">Sproutly</h4>
      <p class="auth-subtitle-php">Cozy farm, crop &amp; harvest logbook</p>
    </div>

    <div class="auth-body-php">
      <div class="auth-tabs-php" role="tablist">
        <a class="auth-tab-php <?= $mode === 'login' ? 'active-login' : '' ?>" href="<?= e(purl('login.php')) ?>">Sign In</a>
        <a class="auth-tab-php <?= $mode === 'register' ? 'active-register' : '' ?>" href="<?= e(purl('login.php', ['mode' => 'register'])) ?>">Create Account</a>
      </div>

      <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="alert alert-danger py-2">Please fix the highlighted fields below.</div><?php endif; ?>

      <?php if ($mode === 'register'): ?>
        <form method="post" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="mode" value="register">
          <div class="mb-3">
            <label class="form-label" for="reg_full_name">Full name</label>
            <input id="reg_full_name" name="full_name" placeholder="e.g. Maria Santos" class="form-control auth-input-php <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" required value="<?= e($_POST['full_name'] ?? '') ?>">
            <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback d-block"><?= e($errors['full_name']) ?></div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="reg_username">Username</label>
            <input id="reg_username" name="username" placeholder="Choose a username" class="form-control auth-input-php <?= isset($errors['username']) ? 'is-invalid' : '' ?>" required value="<?= e($_POST['username'] ?? '') ?>">
            <?php if (isset($errors['username'])): ?><div class="invalid-feedback d-block"><?= e($errors['username']) ?></div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="reg_email">Email</label>
            <input id="reg_email" type="email" name="email" placeholder="you@example.com" class="form-control auth-input-php <?= isset($errors['email']) ? 'is-invalid' : '' ?>" required value="<?= e($_POST['email'] ?? '') ?>">
            <?php if (isset($errors['email'])): ?><div class="invalid-feedback d-block"><?= e($errors['email']) ?></div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="reg_role">Requested role</label>
            <select id="reg_role" name="role_id" class="form-select auth-input-php <?= isset($errors['role_id']) ? 'is-invalid' : '' ?>">
              <option value="2" <?= ($_POST['role_id'] ?? '2') === '2' ? 'selected' : '' ?>>Staff (Read &amp; Filter)</option>
              <option value="1" <?= ($_POST['role_id'] ?? '') === '1' ? 'selected' : '' ?>>Administrator (Full CRUD)</option>
            </select>
            <?php if (isset($errors['role_id'])): ?><div class="invalid-feedback d-block"><?= e($errors['role_id']) ?></div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="reg_password">Password</label>
            <input id="reg_password" type="password" name="password" placeholder="Enter your password" class="form-control auth-input-php <?= isset($errors['password']) ? 'is-invalid' : '' ?>" required minlength="6">
            <?php if (isset($errors['password'])): ?><div class="invalid-feedback d-block"><?= e($errors['password']) ?></div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="reg_confirm">Confirm password</label>
            <input id="reg_confirm" type="password" name="confirm_password" placeholder="Re-enter your password" class="form-control auth-input-php <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" required minlength="6">
            <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback d-block"><?= e($errors['confirm_password']) ?></div><?php endif; ?>
          </div>
          <button class="btn btn-success w-100 auth-btn-php"><i class="bi bi-flower2 me-1"></i> Create Account</button>
        </form>
      <?php else: ?>
        <form method="post" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="mode" value="login">
          <div class="mb-3">
            <label class="form-label" for="username">Username or Email</label>
            <input id="username" name="username" placeholder="Enter username or email" class="form-control auth-input-php" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input id="password" type="password" name="password" placeholder="Enter your password" class="form-control auth-input-php" required>
          </div>
          <button class="btn btn-success w-100 auth-btn-php"><i class="bi bi-flower2 me-1"></i> Login</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php layout_end();
