<?php
// User Management CRUD module for IEMS (Restricted to Super Admins & Admins)
require_once 'config.php';
require_role(['super_admin', 'admin']);

$active_page = 'users';
$error = '';
$success = '';

// Edit Mode detection
$edit_mode = false;
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    // Staff role users shouldn't be editing admin/super_admin if they bypass check, but require_role handles guards
    // Admins cannot edit Super Admins to maintain authorization hierarchy
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$edit_id]);
    $edit_user = $stmt->fetch();
    
    if ($edit_user) {
        if ($_SESSION['user_role'] === 'admin' && $edit_user['role'] === 'super_admin') {
            set_flash_message('error', 'Access denied. Admins cannot modify Super Admin profiles.');
            header("Location: users.php");
            exit;
        }
        $edit_mode = true;
    }
}

// Delete User Action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    
    if ($delete_id === (int)$_SESSION['user_id']) {
        set_flash_message('error', 'Self-destruction blocked! You cannot delete your own logged-in account.');
    } else {
        try {
            // Get user info to check role
            $stmt = $pdo->prepare("SELECT role, full_name FROM users WHERE id = ?");
            $stmt->execute([$delete_id]);
            $user_data = $stmt->fetch();
            
            if ($user_data) {
                if ($_SESSION['user_role'] === 'admin' && $user_data['role'] === 'super_admin') {
                    set_flash_message('error', 'Access denied. Admins cannot delete Super Admins.');
                } else {
                    // Check for dependencies in income, expenses, transfers (created_by references)
                    $dep_stmt = $pdo->prepare("SELECT COUNT(*) FROM income WHERE created_by = ?");
                    $dep_stmt->execute([$delete_id]);
                    $income_deps = (int)$dep_stmt->fetchColumn();
                    
                    $dep_stmt2 = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE created_by = ?");
                    $dep_stmt2->execute([$delete_id]);
                    $expense_deps = (int)$dep_stmt2->fetchColumn();
                    
                    if ($income_deps > 0 || $expense_deps > 0) {
                        // Instead of hard deleting, we suggest deactivating to preserve audit logs
                        set_flash_message('warning', 'User has recorded transactions. Deactivating account instead of hard deleting to preserve logs.');
                        $deact = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                        $deact->execute([$delete_id]);
                        log_activity("Deactivated User (due to logged transactions): {$user_data['full_name']} (ID: {$delete_id})");
                    } else {
                        $delete_stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $delete_stmt->execute([$delete_id]);
                        log_activity("Deleted User: {$user_data['full_name']} (ID: {$delete_id})");
                        set_flash_message('success', 'User deleted successfully.');
                    }
                }
            }
        } catch (PDOException $e) {
            set_flash_message('error', 'A database error occurred.');
        }
    }
    header("Location: users.php");
    exit;
}

// Form Submission handling (Add or Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf)) {
        $error = 'CSRF verification failed.';
    } else {
        $full_name = clean($_POST['full_name'] ?? '');
        $email = clean($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        
        if (empty($full_name) || empty($email)) {
            $error = 'Full Name and Email Address are required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } else {
            try {
                if (isset($_POST['action']) && $_POST['action'] === 'update') {
                    // UPDATE MODE
                    $user_id = (int)$_POST['user_id'];
                    
                    // Auth check: Admin cannot update Super Admin role/status
                    $check_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $check_stmt->execute([$user_id]);
                    $target_role = $check_stmt->fetchColumn();
                    
                    if ($_SESSION['user_role'] === 'admin' && $target_role === 'super_admin') {
                        $error = 'Admins cannot modify Super Admin profiles.';
                    } else {
                        // Check if email already registered to another user
                        $email_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                        $email_check->execute([$email, $user_id]);
                        
                        if ($email_check->rowCount() > 0) {
                            $error = 'Email address is already in use by another user.';
                        } else {
                            if (!empty($password)) {
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, password = ?, role = ?, status = ? WHERE id = ?");
                                $stmt->execute([$full_name, $email, $hashed_password, $role, $status, $user_id]);
                            } else {
                                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?");
                                $stmt->execute([$full_name, $email, $role, $status, $user_id]);
                            }
                            
                            // If editing self, update active session name
                            if ($user_id === (int)$_SESSION['user_id']) {
                                $_SESSION['user_name'] = $full_name;
                                $_SESSION['user_role'] = $role;
                            }
                            
                            log_activity("Updated User Profile: {$full_name} (ID: {$user_id})");
                            set_flash_message('success', 'User profile updated successfully.');
                            header("Location: users.php");
                            exit;
                        }
                    }
                } else {
                    // CREATE MODE
                    // Check if email is unique
                    $email_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $email_check->execute([$email]);
                    
                    if ($email_check->rowCount() > 0) {
                        $error = 'Email address is already registered.';
                    } elseif (empty($password)) {
                        $error = 'Password is required when creating a new user.';
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$full_name, $email, $hashed_password, $role, $status]);
                        
                        log_activity("Created User Account: {$full_name} ({$email})");
                        set_flash_message('success', 'New user account created successfully.');
                        header("Location: users.php");
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $error = 'Database error occurred: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all registered users
$users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Controls - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <style>
        .btn-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-edit {
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
            border-color: rgba(99, 102, 241, 0.3);
        }
        .btn-edit:hover {
            background: rgba(99, 102, 241, 0.3);
            color: #a5b4fc;
        }
        .btn-delete {
            background: rgba(239, 68, 68, 0.12);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.25);
        }
        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.25);
            color: #fca5a5;
        }
        .actions-cell {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .module-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
            align-items: flex-start;
        }
        @media (max-width: 900px) {
            .module-grid { grid-template-columns: 1fr; }
        }
        .form-card { background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color); padding: 24px; }
        .form-card-title { font-size: 1rem; font-weight: 700; color: var(--text-light); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
    </style>
</head>
<body>

    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Panel Content -->
        <div class="main-content">
            <!-- Mobile Menu -->
            <?php include 'mobile-menu.php'; ?>
            
            <!-- Navbar -->
            <div class="navbar">
                <div class="page-title">User Controls & Settings</div>
                <div class="nav-actions">
                    <a href="?toggle_theme=1" class="nav-btn" title="Toggle Theme">
                        <i class="fa-solid <?= ($_SESSION['theme'] === 'light') ? 'fa-moon' : 'fa-sun' ?>"></i>
                    </a>
                </div>
            </div>
            
            <!-- Content Body -->
            <div class="content-body">
                <!-- Flash messages -->
                <?php display_flash_message(); ?>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span><?= clean($error) ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="module-grid">
                    <!-- Left: Users List Table -->
                    <div class="table-card">
                        <div class="header-title-section" style="margin-bottom: 20px;">
                            <h2>Registered Accounts</h2>
                            <p>Manage system users, roles, and administrative statuses.</p>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="custom-table" id="usersTable">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Joined Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600; color: var(--text-light);"><?= clean($u['full_name']) ?></div>
                                                <?php if ($u['id'] === (int)$_SESSION['user_id']): ?>
                                                    <span style="font-size:0.7rem; color:var(--primary); font-weight:600;">(You)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= clean($u['email']) ?></td>
                                            <td>
                                                <?php 
                                                $roleBadge = 'badge-info';
                                                if ($u['role'] === 'super_admin') {
                                                    $roleBadge = 'badge-danger';
                                                } elseif ($u['role'] === 'admin') {
                                                    $roleBadge = 'badge-warning';
                                                }
                                                ?>
                                                <span class="badge <?= $roleBadge ?>">
                                                    <?= str_replace('_', ' ', clean($u['role'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= ($u['status'] === 'active') ? 'badge-success' : 'badge-danger' ?>">
                                                    <?= clean($u['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= clean(date('d M Y', strtotime($u['created_at']))) ?></td>
                                            <td class="actions-cell">
                                                <!-- Admins cannot edit/delete Super Admins -->
                                                <?php if ($_SESSION['user_role'] === 'super_admin' || $u['role'] !== 'super_admin'): ?>
                                                    <a href="?edit=<?= $u['id'] ?>" class="btn-icon btn-edit" title="Edit">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </a>
                                                    <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                                        <a href="?delete=<?= $u['id'] ?>" class="btn-icon btn-delete" title="Delete" onclick="return confirm('Are you sure you want to delete or deactivate this user?');">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Right: Add / Edit User Form -->
                    <div class="form-card">
                        <div class="form-card-title">
                            <span><?= $edit_mode ? 'Edit User details' : 'Register User' ?></span>
                            <?php if ($edit_mode): ?>
                                <a href="users.php" class="btn-icon" style="border: none;" title="Cancel Edit">
                                    <i class="fa-solid fa-xmark"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <form action="users.php" method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <?php if ($edit_mode): ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label class="form-label" for="full_name">Full Name</label>
                                <div class="input-icon-wrapper">
                                    <input type="text" id="full_name" name="full_name" class="form-control" placeholder="e.g. John Doe" required value="<?= $edit_mode ? clean($edit_user['full_name']) : '' ?>">
                                    <i class="fa-solid fa-user" style="left: 14px;"></i>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="email">Email Address</label>
                                <div class="input-icon-wrapper">
                                    <input type="email" id="email" name="email" class="form-control" placeholder="e.g. john@domain.com" required value="<?= $edit_mode ? clean($edit_user['email']) : '' ?>">
                                    <i class="fa-solid fa-envelope" style="left: 14px;"></i>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="password">Password <?= $edit_mode ? '(Leave blank to keep same)' : '' ?></label>
                                <div class="input-icon-wrapper">
                                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" <?= $edit_mode ? '' : 'required' ?>>
                                    <i class="fa-solid fa-lock" style="left: 14px;"></i>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="role">User Role</label>
                                <select id="role" name="role" class="form-control" style="padding-left: 15px;" required>
                                    <!-- Only Super Admin can promote/demote other Super Admins -->
                                    <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                                        <option value="super_admin" <?= ($edit_mode && $edit_user['role'] === 'super_admin') ? 'selected' : '' ?>>Super Admin</option>
                                    <?php endif; ?>
                                    <option value="admin" <?= ($edit_mode && $edit_user['role'] === 'admin') ? 'selected' : '' ?>>Admin (Full Access)</option>
                                    <option value="staff" <?= ($edit_mode && $edit_user['role'] === 'staff') ? 'selected' : '' ?>>Entry Staff (Log Transactions, No Edit/Delete)</option>
                                    <option value="viewer" <?= ($edit_mode && $edit_user['role'] === 'viewer') ? 'selected' : '' ?>>Viewer (Read-Only Reports)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="status">Account Status</label>
                                <select id="status" name="status" class="form-control" style="padding-left: 15px;" required>
                                    <option value="active" <?= ($edit_mode && $edit_user['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                                    <!-- Logged in user cannot deactivate themselves -->
                                    <?php if (!$edit_mode || (int)$edit_user['id'] !== (int)$_SESSION['user_id']): ?>
                                        <option value="inactive" <?= ($edit_mode && $edit_user['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn-primary" style="margin-top: 10px;">
                                <i class="fa-solid <?= $edit_mode ? 'fa-user-pen' : 'fa-user-plus' ?>"></i>
                                <span><?= $edit_mode ? 'Save Changes' : 'Create User' ?></span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#usersTable').DataTable({
            order: [[0, 'asc']],
            responsive: true
        });
    });
    </script>
</body>
</html>
