<?php
// Activity Logs viewer module for IEMS
require_once 'config.php';
require_login();

$active_page = 'activity_logs';

// Fetch all activity logs (only super_admin and admin can see all. Staff can only see their own logs)
$current_role = $_SESSION['user_role'] ?? 'staff';
$current_user_id = (int)$_SESSION['user_id'];

if (in_array($current_role, ['super_admin', 'admin'])) {
    // Admins see all logs
    $logs = $pdo->query("
        SELECT l.*, u.full_name, u.email, u.role
        FROM activity_logs l
        LEFT JOIN users u ON l.user_id = u.id
        ORDER BY l.created_at DESC
    ")->fetchAll();
} else {
    // Staff see only their own logs
    $stmt = $pdo->prepare("
        SELECT l.*, u.full_name, u.email, u.role
        FROM activity_logs l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE l.user_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$current_user_id]);
    $logs = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'dark' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - IEMS ERP</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.dataTables.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
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
                <div class="page-title">Audit Trails / Activity Logs</div>
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
                
                <div class="table-card single-col">
                    <div class="header-title-section" style="margin-bottom: 20px;">
                        <h2>System Audit Trails</h2>
                        <p>Complete logs of user logins, database modifications, and security activities.</p>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="custom-table" id="activityLogsTable">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Performed By</th>
                                    <th>Role</th>
                                    <th>Action Logged</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td style="font-weight: 600; color: var(--text-light);">
                                            <?= clean(date('d M Y, h:i A', strtotime($log['created_at']))) ?>
                                        </td>
                                        <td>
                                            <?php if ($log['full_name']): ?>
                                                <div style="font-weight: 600; color: var(--text-light);"><?= clean($log['full_name']) ?></div>
                                                <span style="font-size:0.75rem; color:var(--text-secondary);"><?= clean($log['email']) ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-style:italic;">System / Seed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($log['role']): ?>
                                                <?php 
                                                $roleBadge = 'badge-info';
                                                if ($log['role'] === 'super_admin') {
                                                    $roleBadge = 'badge-danger';
                                                } elseif ($log['role'] === 'admin') {
                                                    $roleBadge = 'badge-warning';
                                                }
                                                ?>
                                                <span class="badge <?= $roleBadge ?>">
                                                    <?= str_replace('_', ' ', clean($log['role'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background-color: var(--bg-tertiary); color: var(--text-secondary);">daemon</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.9rem; color: var(--text-primary); font-family: monospace;">
                                                <?= clean($log['action']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.85rem; color: var(--text-secondary);"><?= clean($log['ip_address']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#activityLogsTable').DataTable({
            order: [[0, 'desc']], // Show latest logs first
            responsive: true
        });
    });
    </script>
</body>
</html>
