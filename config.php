<?php
// Secure configuration file for Income & Expense Management System (IEMS)

// Set security headers
if (php_sapi_name() !== 'cli') {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Content-Security-Policy: default-src 'self' https://cdnjs.cloudflare.com https://cdn.datatables.net https://fonts.googleapis.com https://fonts.gstatic.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.datatables.net https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://cdn.datatables.net; img-src 'self' data: blob:;");
}

// Error reporting (disable in production, enable for debug if needed)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure session cookie parameters are secure
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Check if HTTPS is active
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    $cookieParams = [
        'lifetime' => 86400,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    
    // Extract hostname without port number
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
    if ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false) {
        $cookieParams['domain'] = $host;
    }
    
    session_set_cookie_params($cookieParams);
    session_start();
}

// Database Credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'income_expense_erp');

// PDO Connection
try {
    // We connect without a DB name initially to allow setup.php to create the database if it doesn't exist
    $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Select the DB if it exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Generate CSRF Token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF check function
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Input Sanitization helper
function clean($data) {
    if (is_array($data)) {
        return array_map('clean', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Session regeneration helper (protect against session fixation)
function secure_login_session($user) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    if (empty($_SESSION['theme'])) {
        $_SESSION['theme'] = 'dark'; // default theme is dark for modern feel
    }
}

// Authentication guard
function require_login() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        set_flash_message('error', 'Please log in to access this page.');
        header("Location: login.php");
        exit;
    }
}

// Role authorization guard
function require_role($allowed_roles = []) {
    require_login();
    if (!in_array($_SESSION['user_role'], $allowed_roles)) {
        set_flash_message('error', 'Unauthorized access! You do not have permission to view this page.');
        header("Location: dashboard.php");
        exit;
    }
}

// Flash messages helpers
function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'error', 'info', 'warning'
        'message' => $message
    ];
}

function display_flash_message() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        
        $class = 'alert-info';
        $icon = 'fa-info-circle';
        if ($flash['type'] === 'success') {
            $class = 'alert-success';
            $icon = 'fa-check-circle';
        } elseif ($flash['type'] === 'error') {
            $class = 'alert-danger';
            $icon = 'fa-exclamation-circle';
        } elseif ($flash['type'] === 'warning') {
            $class = 'alert-warning';
            $icon = 'fa-exclamation-triangle';
        }
        
        echo "<div class='alert {$class} alert-dismissible fade show' role='alert'>
                <i class='fa-solid {$icon} me-2'></i>
                <span>{$flash['message']}</span>
                <button type='button' class='btn-close' onclick='this.parentElement.remove();' aria-label='Close'></button>
              </div>";
    }
}

// Format numbers as Currency
function format_currency($amount) {
    global $pdo;
    static $symbol = null;
    if ($symbol === null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'currency_symbol'");
            $stmt->execute();
            $row = $stmt->fetch();
            $symbol = $row ? $row['setting_value'] : '₹';
        } catch (Exception $e) {
            $symbol = '₹';
        }
    }
    return $symbol . number_format((float)$amount, 2);
}

// Activity Logging helper
function log_activity($action) {
    global $pdo;
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $action, $ip]);
    } catch (PDOException $e) {
        // Fail silently so database errors on logging don't crash main actions
    }
}

// Theme handling via query parameter
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    // Return back to referrer or dashboard
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
    header("Location: " . $redirect);
    exit;
}

// Helper to upload document attachments (PDF, JPEG, or Base64 camera photo)
function handle_attachment_upload($old_attachment = null) {
    $upload_dir = 'uploads/';
    $filename = null;
    
    // Create upload directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Check if camera base64 photo is submitted
    if (!empty($_POST['camera_photo'])) {
        $data = $_POST['camera_photo'];
        if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
            $data = substr($data, strpos($data, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, etc.
            if (!in_array($type, ['jpg', 'jpeg', 'png'])) {
                $type = 'jpg';
            }
            $data = base64_decode($data);
            if ($data !== false) {
                $filename = 'cam_' . uniqid() . '.' . $type;
                file_put_contents($upload_dir . $filename, $data);
                // Remove old attachment if exists
                if ($old_attachment && file_exists($upload_dir . $old_attachment)) {
                    @unlink($upload_dir . $old_attachment);
                }
                return $filename;
            }
        }
    }
    
    // Check if file is uploaded
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file_name = $_FILES['attachment']['name'];
        $file_tmp = $_FILES['attachment']['tmp_name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
        if (in_array($ext, $allowed_exts)) {
            $filename = 'file_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file_tmp, $upload_dir . $filename)) {
                // Remove old attachment if exists
                if ($old_attachment && file_exists($upload_dir . $old_attachment)) {
                    @unlink($upload_dir . $old_attachment);
                }
                return $filename;
            }
        }
    }
    
    // Check if user requested to remove existing attachment
    if (isset($_POST['remove_attachment']) && $_POST['remove_attachment'] == '1') {
        if ($old_attachment && file_exists($upload_dir . $old_attachment)) {
            @unlink($upload_dir . $old_attachment);
        }
        return null;
    }
    
    return $old_attachment; // Keep original if no change
}

// Default theme configuration
if (empty($_SESSION['theme'])) {
    $_SESSION['theme'] = 'dark';
}
?>
