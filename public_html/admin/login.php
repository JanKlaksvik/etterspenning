<?php
require_once __DIR__ . '/../inc/security.php';
require_once __DIR__ . '/../inc/util.php';
require_once __DIR__ . '/../inc/db.php';

start_secure_session();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = clean_email($_POST['email'] ?? '');
  $pass = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $error = 'Missing email or password.';
  } else {
    $pdo = db();
    $st = $pdo->prepare("SELECT email, password_hash, is_active FROM admin_users WHERE email = ?");
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u || (int)$u['is_active'] !== 1 || !password_verify($pass, $u['password_hash'])) {
      $error = 'Invalid credentials.';
    } else {
      $_SESSION['admin_email'] = $u['email'];
      csrf_token(); // init
      header('Location: /admin/index.php');
      exit;
    }
  }
}

?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin login — posttension.eu</title>
<style>
  body{margin:0;font-family:system-ui;background:#0b0f14;color:#e9f0f7}
  .wrap{max-width:520px;margin:0 auto;padding:18px}
  .card{border:1px solid rgba(255,255,255,.12);border-radius:16px;background:rgba(16,24,38,.72);padding:16px;margin:12px 0}
  label{display:block;font-size:12px;color:#9db0c6;margin:10px 0 6px}
  input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(11,15,20,.35);color:#e9f0f7}
  .btn{border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#e9f0f7;padding:10px 12px;cursor:pointer}
  .err{color:#fb7185;font-size:13px}
</style>
</head><body>
<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 8px">Admin login</h2>
    <div style="color:#9db0c6">Only for the site owner.</div>
    <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
      <label>Email</label><input name="email" type="email" required>
      <label>Password</label><input name="password" type="password" required>
      <div style="margin-top:12px"><button class="btn" type="submit">Sign in</button></div>
    </form>
  </div>
</div>
</body></html>
