<?php
require_once 'config.php';
require_login();
require_role(['super_admin', 'admin']);

$active_page = 'settings';

// ─── Handle theme toggle ───────────────────────────────────────────────────
// (already handled globally in config.php)

// ─── Create system_settings table if not exists ───────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS company_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(200) DEFAULT 'My Company',
        email VARCHAR(200) DEFAULT '',
        phone VARCHAR(50) DEFAULT '',
        address TEXT DEFAULT '',
        vat_number VARCHAR(100) DEFAULT '',
        tin_number VARCHAR(100) DEFAULT '',
        facebook_url VARCHAR(500) DEFAULT '',
        twitter_url VARCHAR(500) DEFAULT '',
        linkedin_url VARCHAR(500) DEFAULT '',
        youtube_url VARCHAR(500) DEFAULT '',
        logo VARCHAR(500) DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Tables may already exist - fail silently
}

// ─── Helper: get / set setting ────────────────────────────────────────────
function getSetting($key, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (PDOException $e) { return $default; }
}

function saveSetting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

// ─── AJAX Handlers ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    // CSRF check for write actions
    $read_actions = ['getSettings', 'getCompanySettings'];
    if (!in_array($action, $read_actions)) {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($csrf)) {
            echo json_encode(['success' => false, 'message' => 'CSRF token mismatch. Please refresh.']);
            exit;
        }
    }

    // ── getSettings ────────────────────────────────────────
    if ($action === 'getSettings') {
        echo json_encode([
            'success' => true,
            'data' => [
                'site_name'        => getSetting('site_name', 'IEMS ERP'),
                'copyright_text'   => getSetting('copyright_text', '© ' . date('Y') . ' IEMS ERP. All rights reserved.'),
                'currency_symbol'  => getSetting('currency_symbol', '₹'),
                'site_logo'        => getSetting('site_logo', ''),
                'maintenance_mode' => getSetting('maintenance_mode', '0'),
                'allow_user_uploads' => getSetting('allow_user_uploads', '1'),
                'upi_id'           => getSetting('upi_id', ''),
                'upi_name'         => getSetting('upi_name', ''),
                'static_qr'        => getSetting('static_qr', ''),
                'razorpay_enabled' => getSetting('razorpay_enabled', '0'),
                'razorpay_key_id'  => getSetting('razorpay_key_id', ''),
                'razorpay_key_secret' => getSetting('razorpay_key_secret', ''),
                'default_theme_palette' => getSetting('default_theme_palette', 'ui_2'),
                'default_theme_dark'    => getSetting('default_theme_dark', '1'),
            ]
        ]);
        exit;
    }

    // ── saveSettings ───────────────────────────────────────
    if ($action === 'saveSettings') {
        try {
            saveSetting('site_name',         clean($_POST['site_name'] ?? 'IEMS ERP'));
            saveSetting('copyright_text',    clean($_POST['copyright_text'] ?? ''));
            saveSetting('currency_symbol',   clean($_POST['currency_symbol'] ?? '₹'));
            saveSetting('maintenance_mode',  ($_POST['maintenance_mode'] ?? '0') === '1' ? '1' : '0');
            saveSetting('allow_user_uploads', ($_POST['allow_user_uploads'] ?? '0') === '1' ? '1' : '0');
            saveSetting('upi_id',            clean($_POST['upi_id'] ?? ''));
            saveSetting('upi_name',          clean($_POST['upi_name'] ?? ''));
            saveSetting('razorpay_enabled',  ($_POST['razorpay_enabled'] ?? '0') === '1' ? '1' : '0');
            saveSetting('razorpay_key_id',   clean($_POST['razorpay_key_id'] ?? ''));
            saveSetting('razorpay_key_secret', clean($_POST['razorpay_key_secret'] ?? ''));
            saveSetting('default_theme_palette', clean($_POST['default_theme_palette'] ?? 'ui_2'));
            saveSetting('default_theme_dark',    ($_POST['default_theme_dark'] ?? '1') === '1' ? '1' : '0');
            log_activity('System Settings Updated');
            echo json_encode(['success' => true, 'message' => 'Settings saved successfully!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── getCompanySettings ─────────────────────────────────
    if ($action === 'getCompanySettings') {
        try {
            $row = $pdo->query("SELECT * FROM company_settings LIMIT 1")->fetch();
            echo json_encode(['success' => true, 'data' => $row ?: null]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error']);
        }
        exit;
    }

    // ── saveCompanySettings ────────────────────────────────
    if ($action === 'saveCompanySettings') {
        $company_name = clean($_POST['company_name'] ?? '');
        $email    = clean($_POST['email'] ?? '');
        $phone    = clean($_POST['phone'] ?? '');
        $address  = clean($_POST['address'] ?? '');
        if (empty($company_name) || empty($email) || empty($phone) || empty($address)) {
            echo json_encode(['success' => false, 'message' => 'Company Name, Email, Phone, and Address are required.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
            exit;
        }
        $vat = clean($_POST['vat_number'] ?? '');
        $tin = clean($_POST['tin_number'] ?? '');
        $fb  = clean($_POST['facebook_url'] ?? '');
        $tw  = clean($_POST['twitter_url'] ?? '');
        $li  = clean($_POST['linkedin_url'] ?? '');
        $yt  = clean($_POST['youtube_url'] ?? '');

        try {
            $exists = $pdo->query("SELECT id FROM company_settings LIMIT 1")->fetch();
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE company_settings SET company_name=?,email=?,phone=?,address=?,vat_number=?,tin_number=?,facebook_url=?,twitter_url=?,linkedin_url=?,youtube_url=? WHERE id=?");
                $stmt->execute([$company_name,$email,$phone,$address,$vat,$tin,$fb,$tw,$li,$yt,$exists['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO company_settings (company_name,email,phone,address,vat_number,tin_number,facebook_url,twitter_url,linkedin_url,youtube_url) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$company_name,$email,$phone,$address,$vat,$tin,$fb,$tw,$li,$yt]);
            }
            log_activity('Company Settings Updated');
            echo json_encode(['success' => true, 'message' => 'Company settings saved!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── uploadStaticQr ─────────────────────────────────────
    if ($action === 'uploadStaticQr') {
        if (!isset($_FILES['qr_file']) || $_FILES['qr_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
            exit;
        }
        $file = $_FILES['qr_file'];
        $upload_dir = __DIR__ . '/uploads/branding/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

        $allowed_types = ['image/jpeg','image/png','image/gif','image/webp'];
        $allowed_exts  = ['jpg','jpeg','png','gif','webp'];
        if ($file['size'] > 2 * 1024 * 1024) { echo json_encode(['success'=>false,'message'=>'Max 2MB allowed.']); exit; }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($mime, $allowed_types) || !in_array($ext, $allowed_exts)) {
            echo json_encode(['success'=>false,'message'=>'Only JPG, PNG, GIF, WEBP allowed.']); exit;
        }

        $filename = 'static_qr_' . time() . '.' . $ext;
        
        // Remove old file
        $old = getSetting('static_qr', '');
        if ($old && strpos($old, 'uploads/branding/') === 0 && file_exists(__DIR__ . '/' . $old)) {
            @unlink(__DIR__ . '/' . $old);
        }

        if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
            echo json_encode(['success'=>false,'message'=>'Upload failed.']); exit;
        }
        $path = 'uploads/branding/' . $filename;
        saveSetting('static_qr', $path);

        echo json_encode(['success'=>true,'message'=>'Static QR uploaded!','qr_path'=>$path]);
        exit;
    }

    // ── uploadSiteLogo ─────────────────────────────────────
    if ($action === 'uploadSiteLogo') {
        if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
            exit;
        }
        $file = $_FILES['logo_file'];
        $upload_dir = __DIR__ . '/uploads/branding/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

        $allowed_types = ['image/jpeg','image/png','image/gif','image/webp'];
        $allowed_exts  = ['jpg','jpeg','png','gif','webp'];
        if ($file['size'] > 2 * 1024 * 1024) { echo json_encode(['success'=>false,'message'=>'Max 2MB allowed.']); exit; }

        // Detect MIME - use finfo if available, fallback to mime_content_type
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        } else {
            // Fallback: trust extension only
            $ext_check = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = in_array($ext_check, ['jpg','jpeg']) ? 'image/jpeg' :
                   (in_array($ext_check, ['png']) ? 'image/png' :
                   (in_array($ext_check, ['gif']) ? 'image/gif' : 'image/webp'));
        }

        if (!in_array($mime, $allowed_types)) { echo json_encode(['success'=>false,'message'=>'Only JPG/PNG/GIF/WEBP allowed.']); exit; }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts)) { echo json_encode(['success'=>false,'message'=>'Invalid extension.']); exit; }

        // Delete old logo
        $old = getSetting('site_logo', '');
        if ($old && strpos($old,'uploads/branding/') === 0 && file_exists(__DIR__.'/'.$old)) @unlink(__DIR__.'/'.$old);

        $filename = 'site_logo_' . time() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
            echo json_encode(['success'=>false,'message'=>'Upload failed.']); exit;
        }
        $path = 'uploads/branding/' . $filename;
        saveSetting('site_logo', $path);
        log_activity('Site Logo Updated');
        echo json_encode(['success'=>true,'message'=>'Logo uploaded!','logo_path'=>$path]);
        exit;
    }

    // ── uploadCompanyLogo ─────────────────────────────────
    if ($action === 'uploadCompanyLogo') {
        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'message'=>'No file uploaded.']); exit;
        }
        $file = $_FILES['logo'];
        $upload_dir = __DIR__ . '/uploads/branding/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

        $allowed_types = ['image/jpeg','image/png','image/gif','image/webp'];
        $allowed_exts  = ['jpg','jpeg','png','gif','webp'];
        if ($file['size'] > 2*1024*1024) { echo json_encode(['success'=>false,'message'=>'Max 2MB.']); exit; }

        // Detect MIME - use finfo if available, fallback to mime_content_type
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        } else {
            $ext_check = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = in_array($ext_check, ['jpg','jpeg']) ? 'image/jpeg' :
                   (in_array($ext_check, ['png']) ? 'image/png' :
                   (in_array($ext_check, ['gif']) ? 'image/gif' : 'image/webp'));
        }

        if (!in_array($mime, $allowed_types)) { echo json_encode(['success'=>false,'message'=>'Invalid type.']); exit; }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts)) { echo json_encode(['success'=>false,'message'=>'Invalid ext.']); exit; }

        // Delete old company logo
        try {
            $old_row = $pdo->query("SELECT logo FROM company_settings LIMIT 1")->fetch();
            if ($old_row && $old_row['logo'] && file_exists(__DIR__.'/'.$old_row['logo'])) @unlink(__DIR__.'/'.$old_row['logo']);
        } catch(Exception $e){}

        $filename = 'company_logo_' . time() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
            echo json_encode(['success'=>false,'message'=>'Upload failed.']); exit;
        }
        $path = 'uploads/branding/' . $filename;

        try {
            $exists = $pdo->query("SELECT id FROM company_settings LIMIT 1")->fetch();
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE company_settings SET logo=? WHERE id=?");
                $stmt->execute([$path, $exists['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO company_settings (logo,company_name,address,phone,email) VALUES (?,'My Company','Address','0000','info@company.com')");
                $stmt->execute([$path]);
            }
            log_activity('Company Logo Updated');
            echo json_encode(['success'=>true,'message'=>'Logo uploaded!','logo'=>$path]);
        } catch(PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'DB error.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <style>
    /* ── Settings Page Styles ─────────────────────────── */
    .settings-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0;
    }
    .settings-tab {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        border: none;
        background: transparent;
        color: var(--text-secondary);
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
        border-radius: 8px 8px 0 0;
        white-space: nowrap;
    }
    .settings-tab:hover { color: var(--primary); background: var(--bg-primary); }
    .settings-tab.active { color: var(--primary); border-bottom-color: var(--primary); background: var(--bg-primary); }
    .settings-panel { display: none !important; }
    .settings-panel.active { display: block !important; }

    .settings-section { margin-bottom: 24px; }
    .settings-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 20px;
    }
    .settings-card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-primary);
    }
    .settings-card-icon {
        width: 38px; height: 38px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; color: white; flex-shrink: 0;
    }
    .icon-primary { background: linear-gradient(135deg, var(--primary), #818cf8); }
    .icon-danger  { background: linear-gradient(135deg, #e11d48, #f43f5e); }
    .icon-success { background: linear-gradient(135deg, #10b981, #34d399); }
    .icon-warning { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
    .icon-purple  { background: linear-gradient(135deg, #7c3aed, #a78bfa); }

    .settings-card-header h3 { font-size: 1rem; font-weight: 700; color: var(--text-light); margin: 0; }
    .settings-card-header p  { font-size: 0.78rem; color: var(--text-secondary); margin: 2px 0 0; }
    .settings-card-body { padding: 20px; }

    .form-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .sform-group { margin-bottom: 16px; }
    .sform-group:last-child { margin-bottom: 0; }
    .sform-group label {
        display: block;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 6px;
    }
    .sform-group label i { margin-right: 5px; opacity: 0.7; }
    .sform-group input,
    .sform-group textarea,
    .sform-group select {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-primary);
        color: var(--text-light);
        font-size: 0.9rem;
        box-sizing: border-box;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .sform-group input:focus,
    .sform-group textarea:focus,
    .sform-group select:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
    }
    .sform-group textarea { height: 80px; resize: vertical; }

    /* Toggle Switch */
    .toggle-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 0;
        border-bottom: 1px solid var(--border-color);
    }
    .toggle-row:last-child { border-bottom: none; }
    .toggle-info h4 { font-size: 0.9rem; font-weight: 600; color: var(--text-light); margin: 0 0 3px; }
    .toggle-info p  { font-size: 0.78rem; color: var(--text-secondary); margin: 0; }
    .toggle-switch { position: relative; width: 52px; height: 28px; flex-shrink: 0; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
        position: absolute; inset: 0;
        background: var(--border-color);
        border-radius: 28px;
        cursor: pointer;
        transition: 0.3s;
    }
    .toggle-slider:before {
        content: '';
        position: absolute;
        width: 22px; height: 22px;
        left: 3px; top: 3px;
        background: white;
        border-radius: 50%;
        transition: 0.3s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    .toggle-switch input:checked + .toggle-slider { background: var(--primary); }
    .toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); }
    .toggle-switch input:checked ~ .toggle-label { color: var(--primary); }

    /* Server info table */
    .server-info-table { width: 100%; border-collapse: collapse; }
    .server-info-table tr:not(:last-child) td { border-bottom: 1px solid var(--border-color); }
    .server-info-table td { padding: 10px 0; font-size: 0.88rem; }
    .server-info-table td:first-child { color: var(--text-secondary); font-weight: 600; width: 50%; }
    .server-info-table td:last-child { color: var(--text-light); text-align: right; }

    /* Security badge */
    .security-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: 700;
        background: rgba(16,185,129,0.15);
        color: #10b981;
        border: 1px solid rgba(16,185,129,0.3);
    }

    /* Company logo circle */
    .company-logo-circle {
        width: 110px; height: 110px;
        border-radius: 50%;
        border: 3px solid var(--border-color);
        overflow: hidden;
        display: flex; align-items: center; justify-content: center;
        background: var(--bg-primary);
        position: relative; cursor: pointer;
        transition: border-color 0.2s;
        margin: 0 auto 12px;
    }
    .company-logo-circle:hover { border-color: var(--primary); }
    .company-logo-circle img { width: 100%; height: 100%; object-fit: cover; }
    .logo-overlay {
        position: absolute; inset: 0;
        background: rgba(0,0,0,0.5);
        display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity 0.2s; border-radius: 50%;
    }
    .company-logo-circle:hover .logo-overlay { opacity: 1; }
    .logo-overlay i { color: white; font-size: 22px; }

    /* Social links */
    .social-row {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 12px;
    }
    .social-icon {
        width: 38px; height: 38px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        color: white; font-size: 16px; flex-shrink: 0;
    }
    .si-fb { background: #1877f2; }
    .si-tw { background: #1da1f2; }
    .si-li { background: #0077b5; }
    .si-yt { background: #ff0000; }
    .social-row input {
        flex: 1; padding: 9px 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-primary);
        color: var(--text-light);
        font-size: 0.88rem;
    }

    /* Action bar */
    .settings-action-bar {
        display: flex; justify-content: flex-end; gap: 10px;
        margin-bottom: 20px;
        padding: 14px 18px;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        position: sticky; top: 0; z-index: 100;
    }
    .settings-action-bar .btn-primary {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 22px; font-size: 0.9rem;
    }
    .settings-action-bar .btn-secondary {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 18px; font-size: 0.9rem;
    }

    /* Logo upload row */
    .logo-upload-row {
        display: flex; align-items: center; gap: 16px;
    }
    .logo-preview-box {
        width: 72px; height: 72px;
        border-radius: 10px; border: 2px solid var(--border-color);
        overflow: hidden; background: var(--bg-primary);
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .logo-preview-box img { width: 100%; height: 100%; object-fit: contain; }
    .logo-placeholder { font-size: 28px; color: var(--text-secondary); }

    /* Alert info box */
    .info-box {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 12px 14px;
        background: rgba(99,102,241,0.1);
        border: 1px solid rgba(99,102,241,0.25);
        border-radius: 8px;
        margin-top: 16px;
        font-size: 0.82rem;
        color: var(--text-secondary);
    }
    .info-box i { color: var(--primary); margin-top: 2px; flex-shrink: 0; }
    .info-box.warning {
        background: rgba(239,68,68,0.1);
        border-color: rgba(239,68,68,0.25);
    }
    .info-box.warning i { color: #ef4444; }

    /* Mobile responsive settings */
    @media (max-width: 768px) {
        .settings-action-bar { flex-wrap: wrap; }
        .form-grid-2 { grid-template-columns: 1fr; }
        .settings-tabs { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 0; }
        .settings-tab { font-size: 0.8rem; padding: 8px 12px; }
        .logo-upload-row { flex-direction: column; align-items: flex-start; }
        .settings-card-body { padding: 14px; }
    }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <?php include 'mobile-menu.php'; ?>

        <!-- Navbar -->
        <div class="navbar">
            <div class="page-title"><i class="fa-solid fa-gears" style="margin-right:8px;color:var(--primary);"></i>System Settings</div>
            <div class="nav-actions">
                <a href="?toggle_theme=1" class="nav-btn" title="Toggle Theme">
                    <i class="fa-solid <?= ($_SESSION['theme'] === 'light') ? 'fa-moon' : 'fa-sun' ?>"></i>
                </a>
            </div>
        </div>

        <div class="content-body">
            <?php display_flash_message(); ?>

            <!-- ── Tab Navigation ───────────────────────────────── -->
            <div class="settings-tabs">
                <button class="settings-tab active" onclick="switchTab('general', this)">
                    <i class="fa-solid fa-sliders"></i> General
                </button>
                <button class="settings-tab" onclick="switchTab('company', this)">
                    <i class="fa-solid fa-building"></i> Company
                </button>
                <button class="settings-tab" onclick="switchTab('security', this)">
                    <i class="fa-solid fa-shield-halved"></i> Security
                </button>
                <button class="settings-tab" onclick="switchTab('server', this)">
                    <i class="fa-solid fa-server"></i> Server Info
                </button>
            </div>

            <!-- ═══════════════════════════════════════════════════
                 TAB 1: GENERAL
                 ═══════════════════════════════════════════════════ -->
            <div class="settings-panel active" id="tab-general">

                <!-- Sticky Action Bar -->
                <div class="settings-action-bar">
                    <button class="btn-secondary" onclick="loadSettings()">
                        <i class="fa-solid fa-rotate"></i> Reload
                    </button>
                    <button class="btn-primary" onclick="saveSettings()">
                        <i class="fa-solid fa-floppy-disk"></i> Save Settings
                    </button>
                </div>

                <!-- Site Branding -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-primary">
                            <i class="fa-solid fa-paint-brush"></i>
                        </div>
                        <div>
                            <h3>Site Branding</h3>
                            <p>Customize site name, logo, copyright and currency</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="form-grid-2">
                            <div class="sform-group">
                                <label><i class="fa-solid fa-heading"></i> Site Name</label>
                                <input type="text" id="siteName" placeholder="e.g. IEMS ERP" maxlength="100">
                            </div>
                            <div class="sform-group">
                                <label><i class="fa-solid fa-copyright"></i> Copyright Text</label>
                                <input type="text" id="copyrightText" placeholder="e.g. © 2026 My Company" maxlength="200">
                            </div>
                            <div class="sform-group">
                                <label><i class="fa-solid fa-indian-rupee-sign"></i> Currency Symbol</label>
                                <select id="currencySymbol">
                                    <option value="₹">₹ - Indian Rupee</option>
                                    <option value="$">$ - US Dollar</option>
                                    <option value="€">€ - Euro</option>
                                    <option value="£">£ - British Pound</option>
                                    <option value="¥">¥ - Japanese Yen</option>
                                    <option value="₨">₨ - Pakistani Rupee</option>
                                    <option value="₱">₱ - Philippine Peso</option>
                                    <option value="₩">₩ - Korean Won</option>
                                    <option value="﷼">﷼ - Saudi Riyal</option>
                                    <option value="RM">RM - Malaysian Ringgit</option>
                                    <option value="R">R - South African Rand</option>
                                    <option value="A$">A$ - Australian Dollar</option>
                                    <option value="C$">C$ - Canadian Dollar</option>
                                    <option value="৳">৳ - Bangladeshi Taka</option>
                                    <option value="د.إ">د.إ - UAE Dirham</option>
                                    <option value="฿">฿ - Thai Baht</option>
                                    <option value="Rp">Rp - Indonesian Rupiah</option>
                                </select>
                            </div>
                        </div>

                        <!-- UPI Repayment Settings -->
                        <h4 style="margin-top: 24px; margin-bottom: 12px; font-weight: 700; font-size: 0.95rem; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;"><i class="fa-solid fa-qrcode" style="margin-right: 6px; color: var(--primary);"></i>UPI Repayment Settings</h4>
                        <div class="form-grid-2" style="margin-bottom: 20px;">
                            <div class="sform-group">
                                <label><i class="fa-solid fa-hashtag"></i> UPI ID (VPA) *</label>
                                <input type="text" id="upiId" placeholder="e.g. corporate@upi" maxlength="100">
                            </div>
                            <div class="sform-group">
                                <label><i class="fa-solid fa-user"></i> Payee Name (Merchant/Payee Name) *</label>
                                <input type="text" id="upiName" placeholder="e.g. IEMS ERP Financials" maxlength="100">
                            </div>
                        </div>

                        <!-- Static QR Code Image (Backup) -->
                        <div class="sform-group" style="margin-top:16px;">
                            <label><i class="fa-solid fa-qrcode"></i> Static QR Code Image (Backup)</label>
                            <p style="font-size:0.75rem; color:var(--text-secondary); margin-bottom: 8px;">Upload static image if you don't want dynamic calculations.</p>
                            <div class="logo-upload-row">
                                <div class="logo-preview-box" style="width: 100px; height: 100px; border: 1px dashed var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: var(--bg-primary);">
                                    <img id="qrPreview" src="" alt="" style="display:none; width: 100%; height: 100%; object-fit: contain;">
                                    <i class="fa-solid fa-qrcode logo-placeholder" id="qrPlaceholder" style="font-size: 2rem; opacity: 0.3;"></i>
                                </div>
                                <div>
                                    <label for="qrFileInput" class="btn-secondary" style="display:inline-flex;align-items:center;gap:8px;padding:9px 16px;cursor:pointer;font-size:0.88rem;border-radius:8px;">
                                        <i class="fa-solid fa-upload"></i> Upload Static QR
                                    </label>
                                    <input type="file" id="qrFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" onchange="uploadStaticQr(this)">
                                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:6px;">JPG, PNG, GIF, WEBP — Max 2MB</div>
                                </div>
                            </div>
                        </div>

                        <!-- Razorpay Online Gateway Integration -->
                        <h4 style="margin-top: 24px; margin-bottom: 12px; font-weight: 700; font-size: 0.95rem; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;"><i class="fa-solid fa-credit-card" style="margin-right: 6px; color: var(--primary);"></i>Razorpay Online Gateway Integration</h4>
                        <p style="font-size:0.78rem; color:var(--text-secondary); margin-bottom: 15px;">Enable debtors to pay their EMIs online using card, netbanking, or UPI</p>
                        
                        <div class="sform-group" style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
                            <input type="checkbox" id="razorpayEnabled" style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="razorpayEnabled" style="margin: 0; cursor: pointer; font-weight: 600;">Enable Online Payments (Razorpay Checkout)</label>
                        </div>
                        
                        <div class="form-grid-2" id="razorpayFields" style="margin-bottom: 20px;">
                            <div class="sform-group">
                                <label><i class="fa-solid fa-key"></i> Razorpay Key ID *</label>
                                <input type="text" id="razorpayKeyId" placeholder="e.g. rzp_live_xxxxxxxxxxxx" maxlength="100">
                            </div>
                            <div class="sform-group">
                                <label><i class="fa-solid fa-lock"></i> Razorpay Key Secret *</label>
                                <input type="password" id="razorpayKeySecret" placeholder="e.g. xxxxxxxxxxxxxxxxxxxxxxxx" maxlength="100">
                            </div>
                        </div>

                        <!-- Logo Upload -->
                        <div class="sform-group" style="margin-top:16px;">
                            <label><i class="fa-solid fa-image"></i> Site Logo</label>
                            <div class="logo-upload-row">
                                <div class="logo-preview-box">
                                    <img id="logoPreview" src="" alt="" style="display:none;">
                                    <i class="fa-solid fa-image logo-placeholder" id="logoPlaceholder"></i>
                                </div>
                                <div>
                                    <label for="logoFileInput" class="btn-secondary" style="display:inline-flex;align-items:center;gap:8px;padding:9px 16px;cursor:pointer;font-size:0.88rem;border-radius:8px;">
                                        <i class="fa-solid fa-upload"></i> Choose Image
                                    </label>
                                    <input type="file" id="logoFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:6px;">JPG, PNG, GIF, WEBP — Max 2MB</div>
                                </div>
                            </div>
                        </div>

                        <div class="info-box">
                            <i class="fa-solid fa-circle-info"></i>
                            <span>Site name and logo will appear on the login page and page titles.</span>
                        </div>
                    </div>
                </div>

                <!-- Theme Settings Card -->
                <div class="settings-card" id="theme-settings-card" style="margin-top: 20px;">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-primary">
                            <i class="fa-solid fa-palette"></i>
                        </div>
                        <div>
                            <h3>Theme Palette Selection</h3>
                            <p>Choose a color scheme to apply across the entire system</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <p style="font-size:0.82rem; color:var(--text-secondary); margin-bottom: 20px;">
                            Tap a palette to preview it below. As admin, this will set the default color scheme for all visitors.
                        </p>

                        <!-- Predefined Palettes Grid -->
                        <div class="theme-palettes-grid" id="settingsPalettesGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
                            <!-- Dynamically loaded via Javascript -->
                        </div>

                        <!-- Theme Preview Section -->
                        <div id="settingsThemePreviewContainer" style="border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; background: var(--bg-primary); margin-bottom: 20px;">
                            <h4 id="settingsPreviewPaletteName" style="margin: 0 0 16px 0; font-size: 0.95rem; font-weight: 700; color: var(--text-light); display: flex; align-items: center; justify-content: space-between;">
                                <span>Selected Palette: <strong id="settingsPreviewNameText" style="color:var(--primary);">Graphite & Cyan</strong></span>
                                <span class="badge" id="settingsAppliedBadge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.68rem;">APPLIED</span>
                            </h4>

                            <!-- App Preview Mock Bar -->
                            <div style="display: flex; height: 100px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color); position: relative; margin-bottom: 16px;">
                                <!-- Sidebar Mock -->
                                <div style="width: 50px; background: var(--bg-sidebar); padding: 8px; display: flex; flex-direction: column; gap: 6px; border-right: 1px solid var(--border-color);">
                                    <div style="width: 100%; height: 6px; background: var(--text-secondary); opacity: 0.2; border-radius: 2px;"></div>
                                    <div style="width: 100%; height: 12px; background: var(--primary); border-radius: 3px;"></div>
                                    <div style="width: 80%; height: 6px; background: var(--text-secondary); opacity: 0.15; border-radius: 2px;"></div>
                                    <div style="width: 70%; height: 6px; background: var(--text-secondary); opacity: 0.15; border-radius: 2px;"></div>
                                </div>
                                <!-- Content Mock -->
                                <div style="flex: 1; background: var(--bg-primary); padding: 12px; display: flex; flex-direction: column; gap: 10px;">
                                    <!-- Header -->
                                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 6px;">
                                        <div style="width: 60px; height: 8px; background: var(--text-primary); opacity: 0.2; border-radius: 2px;"></div>
                                        <div style="width: 30px; height: 8px; background: var(--text-secondary); opacity: 0.2; border-radius: 2px;"></div>
                                    </div>
                                    <!-- Cards row -->
                                    <div style="display: flex; gap: 8px;">
                                        <div style="flex: 1; height: 35px; background: var(--bg-secondary); border-radius: 6px; border: 1px solid var(--border-color); padding: 6px; display: flex; flex-direction: column; justify-content: space-between;">
                                            <div style="width: 50%; height: 4px; background: var(--text-secondary); opacity: 0.15; border-radius: 1px;"></div>
                                            <div style="width: 70%; height: 6px; background: var(--success); border-radius: 2px;"></div>
                                        </div>
                                        <div style="flex: 1; height: 35px; background: var(--bg-secondary); border-radius: 6px; border: 1px solid var(--border-color); padding: 6px; display: flex; flex-direction: column; justify-content: space-between;">
                                            <div style="width: 50%; height: 4px; background: var(--text-secondary); opacity: 0.15; border-radius: 1px;"></div>
                                            <div style="width: 70%; height: 6px; background: var(--primary); border-radius: 2px;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Color Legend circles -->
                            <div style="display: flex; flex-wrap: wrap; gap: 16px; font-size: 0.78rem; color: var(--text-secondary); margin-bottom: 16px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="width: 12px; height: 12px; border-radius: 50%; display: inline-block; background: var(--primary);"></span> Primary
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="width: 12px; height: 12px; border-radius: 50%; display: inline-block; background: var(--bg-sidebar); border: 1px solid var(--border-color);"></span> Sidebar
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="width: 12px; height: 12px; border-radius: 50%; display: inline-block; background: var(--bg-primary); border: 1px solid var(--border-color);"></span> Background
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="width: 12px; height: 12px; border-radius: 50%; display: inline-block; background: var(--bg-secondary); border: 1px solid var(--border-color);"></span> Card
                                </div>
                            </div>

                            <!-- Action buttons -->
                            <div style="display: flex; gap: 10px;">
                                <button type="button" class="btn-secondary" id="btnApplyDevice" style="font-size: 0.85rem; padding: 8px 16px;" onclick="applyPreviewPalette()">
                                    <i class="fa-solid fa-check"></i> Preview / Apply Local
                                </button>
                                <button type="button" class="btn-primary" id="btnSetSystemDefault" style="font-size: 0.85rem; padding: 8px 16px;" onclick="setSystemDefaultPalette()">
                                    <i class="fa-solid fa-shield-halved"></i> Set as Default
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Controls -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-warning">
                            <i class="fa-solid fa-toggle-on"></i>
                        </div>
                        <div>
                            <h3>System Controls</h3>
                            <p>Toggle system-wide feature flags</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <h4><i class="fa-solid fa-image" style="margin-right:6px;color:var(--primary);"></i> Allow User Profile Uploads</h4>
                                <p>Let users upload their own profile pictures</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="allowUserUploads">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <h4 style="color:#ef4444;"><i class="fa-solid fa-hard-hat" style="margin-right:6px;"></i> Maintenance Mode</h4>
                                <p>Take site offline — only admins can log in</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="maintenanceMode">
                                <span class="toggle-slider" style="background:var(--danger,#ef4444);"></span>
                            </label>
                        </div>
                        <div class="info-box warning" style="margin-top:14px;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Enabling Maintenance Mode will block all non-admin users from accessing the system.</span>
                        </div>
                    </div>
                </div>

            </div><!-- /tab-general -->

            <!-- ═══════════════════════════════════════════════════
                 TAB 2: COMPANY
                 ═══════════════════════════════════════════════════ -->
            <div class="settings-panel" id="tab-company">

                <div class="settings-action-bar">
                    <button class="btn-secondary" onclick="loadCompanySettings()">
                        <i class="fa-solid fa-rotate"></i> Reload
                    </button>
                    <button class="btn-primary" onclick="saveCompanySettings()">
                        <i class="fa-solid fa-floppy-disk"></i> Save Company
                    </button>
                </div>

                <!-- Company Info + Logo grid -->
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px;" class="company-layout-grid">

                    <!-- Info -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-success">
                                <i class="fa-solid fa-circle-info"></i>
                            </div>
                            <div><h3>Company Information</h3><p>Contact and registration details</p></div>
                        </div>
                        <div class="settings-card-body">
                            <div class="sform-group">
                                <label><i class="fa-solid fa-building"></i> Company Name *</label>
                                <input type="text" id="companyName" placeholder="My Company Ltd">
                            </div>
                            <div class="sform-group">
                                <label><i class="fa-solid fa-envelope"></i> Email *</label>
                                <input type="email" id="companyEmail" placeholder="info@company.com">
                            </div>
                            <div class="sform-group">
                                <label><i class="fa-solid fa-phone"></i> Phone *</label>
                                <input type="tel" id="companyPhone" placeholder="+91-XXXXX-XXXXX">
                            </div>
                            <div class="sform-group">
                                <label><i class="fa-solid fa-location-dot"></i> Address *</label>
                                <textarea id="companyAddress" placeholder="Full company address"></textarea>
                            </div>
                            <div class="form-grid-2">
                                <div class="sform-group">
                                    <label><i class="fa-solid fa-file-invoice"></i> GST / VAT Number</label>
                                    <input type="text" id="companyVat" placeholder="GST/VAT Number">
                                </div>
                                <div class="sform-group">
                                    <label><i class="fa-solid fa-id-card"></i> PAN / TIN Number</label>
                                    <input type="text" id="companyTin" placeholder="PAN/TIN Number">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Logo -->
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-purple">
                                <i class="fa-solid fa-image"></i>
                            </div>
                            <div><h3>Company Logo</h3><p>Appears on invoices & reports</p></div>
                        </div>
                        <div class="settings-card-body" style="text-align:center;">
                            <div class="company-logo-circle" onclick="document.getElementById('companyLogoInput').click()">
                                <i class="fa-solid fa-building" id="companyLogoPlaceholder" style="font-size:36px;color:var(--text-secondary);"></i>
                                <img id="companyLogoPreview" src="" alt="Logo" style="display:none;width:100%;height:100%;object-fit:cover;">
                                <div class="logo-overlay"><i class="fa-solid fa-camera"></i></div>
                            </div>
                            <p style="font-size:0.75rem;color:var(--text-secondary);">Click to upload<br>JPG/PNG/GIF/WEBP · Max 2MB</p>
                            <input type="file" id="companyLogoInput" accept=".jpg,.jpeg,.png,.gif,.webp" style="display:none;" onchange="uploadCompanyLogo(this)">
                        </div>
                    </div>
                </div>

                <!-- Social Media -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-primary">
                            <i class="fa-solid fa-share-nodes"></i>
                        </div>
                        <div><h3>Social Media Links</h3><p>Appear on invoices and footer</p></div>
                    </div>
                    <div class="settings-card-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" class="social-grid">
                            <div class="social-row">
                                <div class="social-icon si-fb"><i class="fa-brands fa-facebook-f"></i></div>
                                <input type="url" id="socialFacebook" placeholder="https://facebook.com/...">
                            </div>
                            <div class="social-row">
                                <div class="social-icon si-tw"><i class="fa-brands fa-twitter"></i></div>
                                <input type="url" id="socialTwitter" placeholder="https://twitter.com/...">
                            </div>
                            <div class="social-row">
                                <div class="social-icon si-li"><i class="fa-brands fa-linkedin-in"></i></div>
                                <input type="url" id="socialLinkedin" placeholder="https://linkedin.com/...">
                            </div>
                            <div class="social-row">
                                <div class="social-icon si-yt"><i class="fa-brands fa-youtube"></i></div>
                                <input type="url" id="socialYoutube" placeholder="https://youtube.com/...">
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /tab-company -->

            <!-- ═══════════════════════════════════════════════════
                 TAB 3: SECURITY
                 ═══════════════════════════════════════════════════ -->
            <div class="settings-panel" id="tab-security">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-success">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div><h3>Active Security Features</h3><p>Built-in protections for this system</p></div>
                    </div>
                    <div class="settings-card-body">
                        <?php
                        $security_features = [
                            ['fa-cookie-bite',   'Session Security',       'HTTPOnly cookies & SameSite=Lax',          true],
                            ['fa-lock',          'Password Encryption',    'bcrypt (PHP PASSWORD_DEFAULT)',             true],
                            ['fa-user-clock',    'Login Rate Limiting',    (defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5) . ' attempts, ' . ((defined('LOGIN_LOCKOUT_TIME') ? LOGIN_LOCKOUT_TIME : 900)/60) . ' min lockout', true],
                            ['fa-shield-alt',    'CSRF Protection',        'Token-based validation on all POST forms',  true],
                            ['fa-file-shield',   'Input Sanitization',     'htmlspecialchars + PDO parameterized queries', true],
                            ['fa-eye',           'Activity Logging',       'All actions logged with user + IP',         true],
                            ['fa-network-wired', 'Content Security Policy','Strict CSP headers on all responses',       true],
                        ];
                        foreach ($security_features as $f): ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border-color);">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <div style="width:36px;height:36px;border-radius:8px;background:rgba(16,185,129,0.15);display:flex;align-items:center;justify-content:center;">
                                    <i class="fa-solid <?= $f[0] ?>" style="color:#10b981;font-size:15px;"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:0.88rem;color:var(--text-light);"><?= $f[1] ?></div>
                                    <div style="font-size:0.75rem;color:var(--text-secondary);"><?= $f[2] ?></div>
                                </div>
                            </div>
                            <span class="security-badge"><i class="fa-solid fa-circle-check"></i> Active</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-danger">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>
                        <div><h3>Session Configuration</h3><p>Current session security parameters</p></div>
                    </div>
                    <div class="settings-card-body">
                        <table class="server-info-table">
                            <tr><td>Session Timeout</td><td><?= defined('SESSION_TIMEOUT') ? (SESSION_TIMEOUT/60) : 30 ?> minutes</td></tr>
                            <tr><td>Max Login Attempts</td><td><?= defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5 ?> attempts</td></tr>
                            <tr><td>Lockout Duration</td><td><?= defined('LOGIN_LOCKOUT_TIME') ? (LOGIN_LOCKOUT_TIME/60) : 15 ?> minutes</td></tr>
                            <tr><td>Cookie HttpOnly</td><td><span class="security-badge">Enabled</span></td></tr>
                            <tr><td>Cookie SameSite</td><td>Lax</td></tr>
                        </table>
                    </div>
                </div>
            </div><!-- /tab-security -->

            <!-- ═══════════════════════════════════════════════════
                 TAB 4: SERVER INFO
                 ═══════════════════════════════════════════════════ -->
            <div class="settings-panel" id="tab-server">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-warning">
                            <i class="fa-solid fa-server"></i>
                        </div>
                        <div><h3>Server Environment</h3><p>PHP, Database & system details</p></div>
                    </div>
                    <div class="settings-card-body">
                        <table class="server-info-table">
                            <tr><td><i class="fa-brands fa-php" style="margin-right:6px;"></i> PHP Version</td><td><span class="badge badge-success"><?= phpversion() ?></span></td></tr>
                            <tr><td><i class="fa-solid fa-database" style="margin-right:6px;"></i> Database Name</td><td><?= DB_NAME ?></td></tr>
                            <tr><td><i class="fa-solid fa-user-gear" style="margin-right:6px;"></i> Database User</td><td><?= DB_USER ?></td></tr>
                            <tr><td><i class="fa-solid fa-network-wired" style="margin-right:6px;"></i> Database Host</td><td><?= DB_HOST ?></td></tr>
                            <tr><td><i class="fa-solid fa-upload" style="margin-right:6px;"></i> Max Upload Size</td><td><?= ini_get('upload_max_filesize') ?></td></tr>
                            <tr><td><i class="fa-solid fa-memory" style="margin-right:6px;"></i> Memory Limit</td><td><?= ini_get('memory_limit') ?></td></tr>
                            <tr><td><i class="fa-solid fa-clock" style="margin-right:6px;"></i> Max Execution Time</td><td><?= ini_get('max_execution_time') ?>s</td></tr>
                            <tr><td><i class="fa-solid fa-calendar-day" style="margin-right:6px;"></i> Server Date/Time</td><td><?= date('d M Y, H:i:s') ?></td></tr>
                            <tr><td><i class="fa-solid fa-earth-asia" style="margin-right:6px;"></i> Server Timezone</td><td><?= date_default_timezone_get() ?></td></tr>
                            <tr><td><i class="fa-solid fa-globe" style="margin-right:6px;"></i> Server Software</td><td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></td></tr>
                        </table>
                    </div>
                </div>

                <?php
                // Check PHP Extensions
                $extensions = ['pdo','pdo_mysql','gd','fileinfo','mbstring','json','curl','openssl'];
                ?>
                <div class="settings-card">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-purple">
                            <i class="fa-solid fa-puzzle-piece"></i>
                        </div>
                        <div><h3>PHP Extensions</h3><p>Required extensions status</p></div>
                    </div>
                    <div class="settings-card-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <?php foreach ($extensions as $ext):
                            $loaded = extension_loaded($ext);
                        ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg-primary);border-radius:8px;">
                            <span style="font-size:0.85rem;font-weight:600;color:var(--text-light);"><?= strtoupper($ext) ?></span>
                            <?php if ($loaded): ?>
                            <span class="security-badge"><i class="fa-solid fa-check"></i> Loaded</span>
                            <?php else: ?>
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;background:rgba(239,68,68,0.15);color:#ef4444;border:1px solid rgba(239,68,68,0.3);">
                                <i class="fa-solid fa-xmark"></i> Missing
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div><!-- /tab-server -->

        </div><!-- /content-body -->
    </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<!-- Mobile FAB (scroll to top on settings page) -->
<button class="mobile-fab fab-account" id="settingsFab" title="Save Settings" style="bottom:75px;" onclick="saveSettings()">
    <i class="fa-solid fa-floppy-disk"></i>
</button>

<script>
const CSRF = '<?= $_SESSION['csrf_token'] ?>';

// ── Tab switching (BULLETPROOF - no CSS dependency) ────────
var currentTab = 'general';

function switchTab(tab, btn) {
    currentTab = tab;
    // Get ALL panels and hide them completely
    var panels = ['general','company','security','server'];
    for (var i = 0; i < panels.length; i++) {
        var p = document.getElementById('tab-' + panels[i]);
        if (p) {
            p.style.cssText = 'display:none !important; visibility:hidden; pointer-events:none;';
            p.setAttribute('aria-hidden','true');
        }
    }
    // Get ALL tab buttons and deactivate
    var btns = document.querySelectorAll('.settings-tab');
    for (var j = 0; j < btns.length; j++) {
        btns[j].classList.remove('active');
        btns[j].style.cssText = '';
    }
    // Show target panel
    var target = document.getElementById('tab-' + tab);
    if (target) {
        target.style.cssText = 'display:block !important; visibility:visible; pointer-events:auto;';
        target.setAttribute('aria-hidden','false');
    }
    // Activate clicked button
    if (btn) {
        btn.classList.add('active');
        btn.style.color = 'var(--primary)';
    }
    // FAB visibility (mobile only - save button)
    var fab = document.getElementById('settingsFab');
    if (fab) fab.style.display = (tab === 'general') ? '' : 'none';
}

// Run immediately - no waiting needed
(function initTabs() {
    var panels = ['general','company','security','server'];
    for (var i = 0; i < panels.length; i++) {
        var p = document.getElementById('tab-' + panels[i]);
        if (!p) continue;
        if (panels[i] === 'general') {
            p.style.cssText = 'display:block !important; visibility:visible; pointer-events:auto;';
        } else {
            p.style.cssText = 'display:none !important; visibility:hidden; pointer-events:none;';
        }
    }
})();

// Theme Selector states
let settingsSelectedPalette = "ui_2";
let settingsSelectedDark = true;

// Populate settings palettes grid on page load
function initSettingsThemeSelector() {
    const grid = document.getElementById("settingsPalettesGrid");
    if (!grid) return;
    grid.innerHTML = "";
    
    window.THEME_PALETTES.forEach(p => {
        const item = document.createElement("div");
        item.className = "settings-palette-card";
        item.style.cssText = `
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        `;
        
        // CSS style injection for hover/active state on load
        if (!document.getElementById("settings-palette-styles")) {
            const styles = document.createElement("style");
            styles.id = "settings-palette-styles";
            styles.textContent = `
                .settings-palette-card:hover {
                    border-color: var(--primary) !important;
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                .settings-palette-card.active {
                    border-color: var(--primary) !important;
                    background: var(--primary-light) !important;
                }
            `;
            document.head.appendChild(styles);
        }
        
        item.innerHTML = `
            <div style="font-weight: 700; font-size: 0.82rem; color: var(--text-light); margin-bottom: 8px;">${p.name}</div>
            <div style="display: flex; gap: 4px; height: 16px; border-radius: 4px; overflow: hidden; border: 1px solid rgba(255,255,255,0.05);">
                <div style="flex: 1.5; background: ${p.primary};"></div>
                <div style="flex: 1; background: ${p.bgSidebar};"></div>
                <div style="flex: 1.5; background: ${p.bgPrimary};"></div>
            </div>
        `;
        
        item.onclick = function() {
            document.querySelectorAll(".settings-palette-card").forEach(el => el.classList.remove("active"));
            item.classList.add("active");
            settingsSelectedPalette = p.id;
            updateSettingsThemePreview();
        };
        
        grid.appendChild(item);
    });
}

function updateSettingsThemePreview() {
    const palette = window.THEME_PALETTES.find(p => p.id === settingsSelectedPalette) || window.THEME_PALETTES[1];
    
    // Update active badge in cards
    document.querySelectorAll(".settings-palette-card").forEach((el, idx) => {
        const p = window.THEME_PALETTES[idx];
        if (p.id === settingsSelectedPalette) {
            el.classList.add("active");
        } else {
            el.classList.remove("active");
        }
    });

    document.getElementById("settingsPreviewNameText").textContent = palette.name;
    document.getElementById("settingsPreviewNameText").style.color = palette.primary;
    
    // Check if currently active
    const curPal = localStorage.getItem("theme-palette") || "ui_2";
    const isApplied = (settingsSelectedPalette === curPal);
    document.getElementById("settingsAppliedBadge").style.display = isApplied ? "inline-block" : "none";
}

function applyPreviewPalette() {
    window.applyThemePalette(settingsSelectedPalette, settingsSelectedDark);
    updateSettingsThemePreview();
    if (typeof showToast !== 'undefined') {
        showToast(true, "Theme preview applied to this device!");
    } else {
        alert("Theme preview applied to this device!");
    }
    setTimeout(() => location.reload(), 300);
}

function setSystemDefaultPalette() {
    saveSettings();
}

// ── Load Settings ──────────────────────────────────────────
function loadSettings() {
    $.post('settings.php', {action:'getSettings'}, function(r) {
        if (r.success) {
            const d = r.data;
            document.getElementById('siteName').value        = d.site_name || '';
            document.getElementById('copyrightText').value   = d.copyright_text || '';
            document.getElementById('currencySymbol').value  = d.currency_symbol || '₹';
            document.getElementById('upiId').value           = d.upi_id || '';
            document.getElementById('upiName').value         = d.upi_name || '';
            document.getElementById('allowUserUploads').checked = (d.allow_user_uploads === '1');
            document.getElementById('maintenanceMode').checked  = (d.maintenance_mode === '1');
            document.getElementById('razorpayEnabled').checked  = (d.razorpay_enabled === '1');
            document.getElementById('razorpayKeyId').value      = d.razorpay_key_id || '';
            document.getElementById('razorpayKeySecret').value  = d.razorpay_key_secret || '';
            
            settingsSelectedPalette = d.default_theme_palette || "ui_2";
            settingsSelectedDark = (d.default_theme_dark === "1");
            
            initSettingsThemeSelector();
            updateSettingsThemePreview();
            
            if (d.site_logo) {
                document.getElementById('logoPreview').src = d.site_logo;
                document.getElementById('logoPreview').style.display = 'block';
                document.getElementById('logoPlaceholder').style.display = 'none';
            }
            if (d.static_qr) {
                document.getElementById('qrPreview').src = d.static_qr;
                document.getElementById('qrPreview').style.display = 'block';
                document.getElementById('qrPlaceholder').style.display = 'none';
            } else {
                document.getElementById('qrPreview').src = '';
                document.getElementById('qrPreview').style.display = 'none';
                document.getElementById('qrPlaceholder').style.display = 'block';
            }
        }
    }, 'json');
}

// ── Save Settings ──────────────────────────────────────────
function saveSettings() {
    $.post('settings.php', {
        action:            'saveSettings',
        csrf_token:        CSRF,
        site_name:         document.getElementById('siteName').value.trim(),
        copyright_text:    document.getElementById('copyrightText').value.trim(),
        currency_symbol:   document.getElementById('currencySymbol').value,
        allow_user_uploads: document.getElementById('allowUserUploads').checked ? '1' : '0',
        maintenance_mode:  document.getElementById('maintenanceMode').checked  ? '1' : '0',
        upi_id:            document.getElementById('upiId').value.trim(),
        upi_name:          document.getElementById('upiName').value.trim(),
        razorpay_enabled:  document.getElementById('razorpayEnabled').checked ? '1' : '0',
        razorpay_key_id:   document.getElementById('razorpayKeyId').value.trim(),
        razorpay_key_secret: document.getElementById('razorpayKeySecret').value.trim(),
        default_theme_palette: settingsSelectedPalette,
        default_theme_dark:    settingsSelectedDark ? '1' : '0'
    }, function(r) {
        showToast(r.success, r.message);
        if (r.success) {
            setTimeout(() => location.reload(), 500);
        }
    }, 'json').fail(function() {
        showToast(false, 'Connection error. Please try again.');
    });
}

// ── Load Company Settings ──────────────────────────────────
function loadCompanySettings() {
    $.post('settings.php', {action:'getCompanySettings'}, function(r) {
        if (r.success && r.data) {
            const d = r.data;
            document.getElementById('companyName').value     = d.company_name || '';
            document.getElementById('companyEmail').value    = d.email || '';
            document.getElementById('companyPhone').value    = d.phone || '';
            document.getElementById('companyAddress').value  = d.address || '';
            document.getElementById('companyVat').value      = d.vat_number || '';
            document.getElementById('companyTin').value      = d.tin_number || '';
            document.getElementById('socialFacebook').value  = d.facebook_url || '';
            document.getElementById('socialTwitter').value   = d.twitter_url || '';
            document.getElementById('socialLinkedin').value  = d.linkedin_url || '';
            document.getElementById('socialYoutube').value   = d.youtube_url || '';
            if (d.logo) {
                document.getElementById('companyLogoPreview').src = d.logo;
                document.getElementById('companyLogoPreview').style.display = 'block';
                document.getElementById('companyLogoPlaceholder').style.display = 'none';
            }
        }
    }, 'json');
}

// ── Save Company Settings ──────────────────────────────────
function saveCompanySettings() {
    const company_name = document.getElementById('companyName').value.trim();
    const email   = document.getElementById('companyEmail').value.trim();
    const phone   = document.getElementById('companyPhone').value.trim();
    const address = document.getElementById('companyAddress').value.trim();

    if (!company_name || !email || !phone || !address) {
        showToast(false, 'Company Name, Email, Phone & Address are required!');
        return;
    }

    $.post('settings.php', {
        action:       'saveCompanySettings',
        csrf_token:   CSRF,
        company_name, email,  phone,  address,
        vat_number:   document.getElementById('companyVat').value.trim(),
        tin_number:   document.getElementById('companyTin').value.trim(),
        facebook_url: document.getElementById('socialFacebook').value.trim(),
        twitter_url:  document.getElementById('socialTwitter').value.trim(),
        linkedin_url: document.getElementById('socialLinkedin').value.trim(),
        youtube_url:  document.getElementById('socialYoutube').value.trim(),
    }, function(r) {
        showToast(r.success, r.message);
    }, 'json').fail(function() {
        showToast(false, 'Connection error. Please try again.');
    });
}

// ── Upload Site Logo ───────────────────────────────────────
document.getElementById('logoFileInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('logoPreview').src = e.target.result;
        document.getElementById('logoPreview').style.display = 'block';
        document.getElementById('logoPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(file);

    const fd = new FormData();
    fd.append('action', 'uploadSiteLogo');
    fd.append('csrf_token', CSRF);
    fd.append('logo_file', file);

    $.ajax({ url:'settings.php', method:'POST', data:fd, processData:false, contentType:false, dataType:'json',
        success: r => showToast(r.success, r.message),
        error:   () => showToast(false, 'Upload failed.')
    });
});

// ── Upload Company Logo ────────────────────────────────────
function uploadCompanyLogo(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('companyLogoPreview').src = e.target.result;
        document.getElementById('companyLogoPreview').style.display = 'block';
        document.getElementById('companyLogoPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);

    const fd = new FormData();
    fd.append('action', 'uploadCompanyLogo');
    fd.append('csrf_token', CSRF);
    fd.append('logo', input.files[0]);

    $.ajax({ url:'settings.php', method:'POST', data:fd, processData:false, contentType:false, dataType:'json',
        success: r => showToast(r.success, r.message),
        error:   () => showToast(false, 'Upload failed.')
    });
}

// ── Upload Static QR ───────────────────────────────────────
function uploadStaticQr(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    
    // Preview immediately
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('qrPreview').src = e.target.result;
        document.getElementById('qrPreview').style.display = 'block';
        document.getElementById('qrPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(file);

    // Loader alert
    Swal.fire({
        title: 'Uploading QR...',
        text: 'Saving static backup code to disk.',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    const fd = new FormData();
    fd.append('qr_file', file);
    fd.append('action', 'uploadStaticQr');
    fd.append('csrf_token', CSRF);
    
    $.ajax({
        url:         'settings.php',
        type:        'POST',
        data:        fd,
        processData: false,
        contentType: false,
        dataType:    'json',
        success:     (r) => {
            Swal.close();
            if (r.success) {
                document.getElementById('qrPreview').src = r.qr_path;
                document.getElementById('qrPreview').style.display = 'block';
                document.getElementById('qrPlaceholder').style.display = 'none';
                showToast(true, r.message);
            } else {
                showToast(false, r.message);
            }
        },
        error:       () => {
            Swal.close();
            showToast(false, 'Upload failed.');
        }
    });
}

// ── Toast Notification ─────────────────────────────────────
function showToast(success, message) {
    const existing = document.querySelector('.iems-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'iems-toast';
    toast.innerHTML = `<i class="fa-solid ${success ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ${message}`;
    toast.style.cssText = `
        position:fixed; bottom:90px; right:18px; z-index:99999;
        background:${success ? '#10b981' : '#e11d48'};
        color:white; padding:12px 20px; border-radius:10px;
        font-size:0.9rem; font-weight:600;
        display:flex; align-items:center; gap:10px;
        box-shadow:0 4px 20px rgba(0,0,0,0.35);
        animation:slideUp 0.3s ease; max-width:320px;
    `;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity='0'; toast.style.transform='translateY(20px)'; toast.style.transition='all 0.3s'; setTimeout(()=>toast.remove(),300); }, 3500);
}

// Add slide-up animation
const style = document.createElement('style');
style.textContent = `@keyframes slideUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
@media(max-width:768px){ .company-layout-grid{grid-template-columns:1fr !important;} .social-grid{grid-template-columns:1fr !important;} }`;
document.head.appendChild(style);

// ── Init ───────────────────────────────────────────────────
$(document).ready(function() {
    loadSettings();
    loadCompanySettings();
});
</script>
</body>
</html>
