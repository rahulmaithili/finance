<?php
// Login page for Income & Expense Management System (IEMS)
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

// Handle AJAX actions for password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    // Helper to get system settings
    if (!function_exists('getSetting')) {
        function getSetting($key, $default = '') {
            global $pdo;
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                $row = $stmt->fetch();
                return $row ? $row['setting_value'] : $default;
            } catch (PDOException $e) { return $default; }
        }
    }
    
    if ($action === 'send_otp') {
        $email = clean($_POST['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email is required.']);
            exit;
        }
        
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'No user account found with this email.']);
            exit;
        }
        
        $otp = (string)rand(100000, 999900);
        $expiry = time() + 600; // 10 minutes
        
        try {
            // Delete old OTPs for this email
            $del = $pdo->prepare("DELETE FROM password_reset_otps WHERE email = ?");
            $del->execute([$email]);
            
            // Save new OTP
            $ins = $pdo->prepare("INSERT INTO password_reset_otps (email, otp, expires_at) VALUES (?, ?, ?)");
            $ins->execute([$email, $otp, $expiry]);
            
            // Send email via SMTP helper
            $smtp_email = getSetting('upi_id'); // Wait, get SMTP email
            $smtp_email = getSetting('smtp_email');
            $smtp_pass = getSetting('smtp_password');
            
            if (empty($smtp_email) || empty($smtp_pass)) {
                echo json_encode(['success' => false, 'message' => 'SMTP server configuration (SMTP_EMAIL, SMTP_PASSWORD) is missing in system settings. Please ask an admin to configure it.']);
                exit;
            }
            
            // SMTP Socket sender
            $host = "smtp.gmail.com";
            $port = 465;
            $socket = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 10);
            if (!$socket) {
                echo json_encode(['success' => false, 'message' => "Could not connect to SMTP server: $errstr"]);
                exit;
            }
            
            if (!function_exists('read_smtp_response')) {
                function read_smtp_response($socket) {
                    $data = "";
                    while (strpos($data, "\r\n") === false || $data[3] === '-') {
                        $line = fgets($socket, 512);
                        if ($line === false) break;
                        $data .= $line;
                    }
                    return $data;
                }
            }
            
            read_smtp_response($socket);
            fwrite($socket, "EHLO localhost\r\n"); read_smtp_response($socket);
            fwrite($socket, "AUTH LOGIN\r\n"); read_smtp_response($socket);
            fwrite($socket, base64_encode($smtp_email) . "\r\n"); read_smtp_response($socket);
            fwrite($socket, base64_encode($smtp_pass) . "\r\n"); 
            $auth_res = read_smtp_response($socket);
            if (strpos($auth_res, "235") === false) {
                echo json_encode(['success' => false, 'message' => 'SMTP Authentication failed. Verify your App Password.']);
                fclose($socket);
                exit;
            }
            
            fwrite($socket, "MAIL FROM: <$smtp_email>\r\n"); read_smtp_response($socket);
            fwrite($socket, "RCPT TO: <$email>\r\n"); read_smtp_response($socket);
            fwrite($socket, "DATA\r\n"); read_smtp_response($socket);
            
            $subject = "IEMS ERP - Password Reset Verification Code";
            $body = "
            <div style=\"font-family: Arial, sans-serif; background-color: #0f172a; color: #f8fafc; padding: 40px; border-radius: 16px; max-width: 500px; margin: 0 auto; border: 1px solid #1e293b;\">
              <h2 style=\"color: #6366f1; text-align: center; margin-top: 0; font-size: 1.5rem; font-weight: 800;\">Password Recovery Verification</h2>
              <p style=\"font-size: 0.95rem; line-height: 1.6; color: #94a3b8; text-align: center;\">You requested a password reset for your account. Please use the following 6-digit verification code (OTP) to reset your password:</p>
              <div style=\"text-align: center; margin: 30px 0;\">
                <span style=\"font-size: 2.2rem; font-weight: 800; letter-spacing: 6px; color: #fff; background-color: #1e1b4b; border: 2px dashed #6366f1; padding: 12px 24px; border-radius: 12px; display: inline-block;\">$otp</span>
              </div>
              <p style=\"font-size: 0.8rem; line-height: 1.5; color: #64748b; text-align: center; margin-bottom: 0;\">This code is valid for 10 minutes. If you did not make this request, you can safely ignore this email.</p>
            </div>";
            
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: \"IEMS Security\" <$smtp_email>\r\nTo: <$email>\r\nSubject: $subject\r\n";
            fwrite($socket, "$headers\r\n$body\r\n.\r\n"); read_smtp_response($socket);
            fwrite($socket, "QUIT\r\n"); fclose($socket);
            
            echo json_encode(['success' => true, 'message' => 'OTP sent successfully to Gmail.']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'verify_otp_reset') {
        $email = clean($_POST['email'] ?? '');
        $otp = clean($_POST['otp'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        
        if (empty($email) || empty($otp) || empty($new_password)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }
        
        if (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
            exit;
        }
        
        // Verify from DB
        $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'No active OTP verification session found.']);
            exit;
        }
        
        if ($row['otp'] !== $otp) {
            echo json_encode(['success' => false, 'message' => 'Invalid OTP code. Please verify.']);
            exit;
        }
        
        if (time() > (int)$row['expires_at']) {
            $del = $pdo->prepare("DELETE FROM password_reset_otps WHERE email = ?");
            $del->execute([$email]);
            echo json_encode(['success' => false, 'message' => 'OTP code has expired. Request a new one.']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Hash and update
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $upd->execute([$hash, $email]);
            
            // Delete OTP log
            $del = $pdo->prepare("DELETE FROM password_reset_otps WHERE email = ?");
            $del->execute([$email]);
            
            log_activity("Password reset via OTP completed for user: {$email}");
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Password reset successfully! You can now log in.']);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to reset password: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Normal POST submit for login
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .swal2-container {
            z-index: 99999 !important;
        }
    </style>
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="form-label" for="password" style="margin-bottom: 0;">Password</label>
                    <a href="javascript:void(0);" onclick="openForgotModal()" style="font-size: 0.78rem; color: var(--primary); text-decoration: none; font-weight: 600;">Forgot Password?</a>
                </div>
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

    <!-- Forgot Password Modal -->
    <div id="forgotPasswordModal" style="display:none; position: fixed; inset:0; background:rgba(0,0,0,0.85); z-index:9999; align-items:center; justify-content:center;">
        <div class="login-card" style="width:90%; max-width:400px; margin:auto;">
            <div class="login-header">
                <h2>Reset Password</h2>
                <p id="forgotModalDesc">Enter your email to receive a 6-digit OTP code on Gmail</p>
            </div>
            <div id="forgotErrorAlert" class="alert alert-danger" style="margin-bottom: 20px; display: none;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span id="forgotErrorMessage">Failed to send verification code.</span>
                </div>
            </div>
            <form id="forgotPasswordForm">
                <!-- Step 1: Email Address -->
                <div class="form-group">
                    <label class="form-label">Registered Email Address</label>
                    <div class="input-icon-wrapper">
                        <input type="email" id="forgotEmail" class="form-control" style="padding-left:42px;" placeholder="name@domain.com" required>
                        <i class="fa-solid fa-envelope" style="left: 14px;"></i>
                    </div>
                </div>

                <!-- Step 2: OTP & New Password (Initially Hidden) -->
                <div id="otpResetSection" style="display: none;">
                    <div class="form-group">
                        <label class="form-label">6-Digit OTP Code</label>
                        <div class="input-icon-wrapper">
                            <input type="text" id="forgotOtp" class="form-control" style="padding-left:42px;" placeholder="e.g. 123456" pattern="[0-9]{6}">
                            <i class="fa-solid fa-key" style="left: 14px;"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <div class="input-icon-wrapper">
                            <input type="password" id="forgotNewPassword" class="form-control" style="padding-left:42px;" placeholder="At least 6 characters">
                            <i class="fa-solid fa-lock" style="left: 14px;"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-icon-wrapper">
                            <input type="password" id="forgotConfirmPassword" class="form-control" style="padding-left:42px;" placeholder="Re-enter password">
                            <i class="fa-solid fa-check" style="left: 14px;"></i>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-primary" style="margin-top:20px;" id="sendResetBtn">
                    <span id="sendResetBtnText">Send OTP Code</span>
                    <i class="fa-solid fa-paper-plane" id="sendResetBtnIcon" style="margin-left: 5px;"></i>
                </button>
                <button type="button" class="btn-secondary" style="margin-top:10px; width:100%;" id="cancelForgot">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        let forgotStep = 1;
        const CSRF = '<?= $_SESSION['csrf_token'] ?>';

        function openForgotModal() {
            const emailInput = document.getElementById('email').value.trim();
            document.getElementById('forgotEmail').value = emailInput;
            document.getElementById('forgotEmail').readOnly = false;
            document.getElementById('otpResetSection').style.display = 'none';
            document.getElementById('sendResetBtnText').textContent = "Send OTP Code";
            document.getElementById('sendResetBtnIcon').className = "fa-solid fa-paper-plane";
            document.getElementById('forgotModalDesc').textContent = "Enter your email to receive a 6-digit OTP code on Gmail";
            document.getElementById('forgotErrorAlert').style.display = 'none';
            
            document.getElementById('forgotOtp').value = '';
            document.getElementById('forgotNewPassword').value = '';
            document.getElementById('forgotConfirmPassword').value = '';
            
            document.getElementById('forgotOtp').required = false;
            document.getElementById('forgotNewPassword').required = false;
            document.getElementById('forgotConfirmPassword').required = false;

            forgotStep = 1;
            document.getElementById('forgotPasswordModal').style.display = 'flex';
        }

        document.getElementById('cancelForgot').addEventListener('click', () => {
            document.getElementById('forgotPasswordModal').style.display = 'none';
        });

        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const email = document.getElementById('forgotEmail').value.trim();
            const sendResetBtn = document.getElementById('sendResetBtn');
            const sendResetBtnText = document.getElementById('sendResetBtnText');
            const sendResetBtnIcon = document.getElementById('sendResetBtnIcon');
            const forgotErrorAlert = document.getElementById('forgotErrorAlert');
            const forgotErrorMessage = document.getElementById('forgotErrorMessage');

            forgotErrorAlert.style.display = "none";

            if (forgotStep === 1) {
                sendResetBtn.disabled = true;
                sendResetBtnText.textContent = "Sending Code...";
                sendResetBtnIcon.className = "fa-solid fa-spinner fa-spin";

                $.post('login.php', {
                    action: 'send_otp',
                    email: email,
                    csrf_token: CSRF
                }, function(r) {
                    sendResetBtn.disabled = false;
                    if (r.success) {
                        forgotStep = 2;
                        document.getElementById('forgotEmail').readOnly = true;
                        document.getElementById('otpResetSection').style.display = 'block';
                        
                        document.getElementById('forgotOtp').required = true;
                        document.getElementById('forgotNewPassword').required = true;
                        document.getElementById('forgotConfirmPassword').required = true;

                        sendResetBtnText.textContent = "Verify & Reset Password";
                        sendResetBtnIcon.className = "fa-solid fa-circle-check";
                        document.getElementById('forgotModalDesc').textContent = "Enter the 6-digit OTP code sent to your email and set a new password.";
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'OTP Code Sent!',
                            text: 'A 6-digit verification code has been successfully sent to your Gmail inbox.',
                            confirmButtonColor: 'var(--primary)'
                        });
                    } else {
                        sendResetBtnText.textContent = "Send OTP Code";
                        sendResetBtnIcon.className = "fa-solid fa-paper-plane";
                        forgotErrorAlert.style.display = "block";
                        forgotErrorMessage.textContent = r.message || 'Failed to send OTP.';
                    }
                }, 'json').fail(function() {
                    sendResetBtn.disabled = false;
                    sendResetBtnText.textContent = "Send OTP Code";
                    sendResetBtnIcon.className = "fa-solid fa-paper-plane";
                    forgotErrorAlert.style.display = "block";
                    forgotErrorMessage.textContent = 'Server connection failed.';
                });
            } else if (forgotStep === 2) {
                const otp = document.getElementById('forgotOtp').value.trim();
                const newPass = document.getElementById('forgotNewPassword').value;
                const confirmPass = document.getElementById('forgotConfirmPassword').value;

                if (newPass !== confirmPass) {
                    forgotErrorAlert.style.display = "block";
                    forgotErrorMessage.textContent = "New passwords do not match. Please verify.";
                    return;
                }

                if (newPass.length < 6) {
                    forgotErrorAlert.style.display = "block";
                    forgotErrorMessage.textContent = "Password must be at least 6 characters long.";
                    return;
                }

                sendResetBtn.disabled = true;
                sendResetBtnText.textContent = "Resetting Password...";
                sendResetBtnIcon.className = "fa-solid fa-spinner fa-spin";

                $.post('login.php', {
                    action: 'verify_otp_reset',
                    email: email,
                    otp: otp,
                    new_password: newPass,
                    csrf_token: CSRF
                }, function(r) {
                    sendResetBtn.disabled = false;
                    if (r.success) {
                        document.getElementById('forgotPasswordModal').style.display = 'none';
                        Swal.fire({
                            icon: 'success',
                            title: 'Password Updated!',
                            text: 'Your password has been successfully reset! You can now log in using your new credentials.',
                            confirmButtonColor: 'var(--primary)'
                        });
                        document.getElementById('email').value = email;
                        document.getElementById('password').value = '';
                    } else {
                        sendResetBtnText.textContent = "Verify & Reset Password";
                        sendResetBtnIcon.className = "fa-solid fa-circle-check";
                        forgotErrorAlert.style.display = "block";
                        forgotErrorMessage.textContent = r.message || 'Verification failed.';
                    }
                }, 'json').fail(function() {
                    sendResetBtn.disabled = false;
                    sendResetBtnText.textContent = "Verify & Reset Password";
                    sendResetBtnIcon.className = "fa-solid fa-circle-check";
                    forgotErrorAlert.style.display = "block";
                    forgotErrorMessage.textContent = 'Server connection failed.';
                });
            }
        });
    </script>
</body>
</html>
