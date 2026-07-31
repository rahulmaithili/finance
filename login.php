<?php
// Login page for Income & Expense Management System (IEMS)
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf)) {
        $error = 'Invalid request verification (CSRF Token mismatch).';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'active') {
                    // Start secure session
                    secure_login_session($user);
                    
                    // Log activity
                    log_activity("User Logged In Successfully");
                    
                    set_flash_message('success', "Welcome back, {$user['full_name']}!");
                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error = 'Your account has been deactivated. Please contact an admin.';
                    log_activity("Inactive user attempted login: {$email}");
                }
            } else {
                $error = 'Invalid email or password.';
                log_activity("Failed login attempt for email: {$email}");
            }
        } catch (PDOException $e) {
            $error = 'A database error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-container">

    <div class="login-card">
        <div class="login-header">
            <div class="logo-icon">
                <i class="fa-solid fa-wallet"></i>
            </div>
            <h1>IEMS ERP</h1>
            <p>Income & Expense Management System</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= clean($error) ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <form action="login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <div class="input-icon-wrapper">
                    <input type="email" id="email" name="email" class="form-control" placeholder="name@domain.com" required value="<?= isset($_POST['email']) ? clean($_POST['email']) : '' ?>">
                    <i class="fa-solid fa-envelope"></i>
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom: 30px;">
                <label class="form-label" for="password">Password</label>
                <div class="input-icon-wrapper">
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                    <i class="fa-solid fa-lock"></i>
                </div>
            </div>
            
            <button type="submit" class="btn-primary">
                <span>Sign In</span>
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>
        
        <div style="text-align: center; margin-top: 25px; font-size: 0.8rem; color: var(--text-secondary);">
            Demo: admin@demo.com / admin123
        </div>
    </div>

</body>
</html>
