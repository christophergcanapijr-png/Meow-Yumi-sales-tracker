<?php
session_start();
require __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$admins = [];

try {
    $pdo = db($config);
    ensure_admin_usernames($pdo, $config['name']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare("SELECT id, name, password_hash FROM admins WHERE username = ?");
        $stmt->execute([strtolower(trim($_POST['username'] ?? ''))]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($_POST['password'] ?? '', $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Invalid username or password.';
    }
} catch (Throwable $e) {
    $error = 'Database connection failed. Start MySQL in Laragon.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Maeyumi Prems</title>
    <link rel="stylesheet" href="assets/style.css?v=app-3">
</head>
<body class="login-page">
    <main class="login-wrap">
        <section class="login-card">
            <img class="login-logo" src="assets/logo.png" alt="Maeyumi Prems">
            <h1>Welcome back</h1>
            <p class="muted">Sign in to manage your shop.</p>

            <?php if ($error): ?><div class="flash"><?= e($error) ?></div><?php endif; ?>

            <form method="post" class="form">
                <label>Username
                    <input name="username" autocomplete="username" required placeholder="Enter username">
                </label>
                <label>Password
                    <input type="password" name="password" required placeholder="Enter password">
                </label>
                <button class="primary-btn full" type="submit">Login</button>
            </form>
        </section>
    </main>
</body>
</html>
