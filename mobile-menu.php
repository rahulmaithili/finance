<?php
// Mobile Navigation and Sidebar drawer for IEMS
$current_user_name = $_SESSION['user_name'] ?? 'User';
$current_user_role = $_SESSION['user_role'] ?? 'staff';
$initials = '';
$words = explode(' ', $current_user_name);
foreach ($words as $w) {
    $initials .= strtoupper($w[0] ?? '');
}
$initials = substr($initials, 0, 2);
?>

<!-- Mobile Top Bar -->
<div class="mobile-navbar">
    <button class="mobile-menu-toggle" id="mobileMenuBtn">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="page-title">IEMS ERP</div>
    <a href="?toggle_theme=1" class="nav-btn" style="width: 35px; height: 35px; border-radius: 6px;">
        <i class="fa-solid <?= ($_SESSION['theme'] === 'light') ? 'fa-moon' : 'fa-sun' ?>"></i>
    </a>
</div>

<!-- Mobile Drawer Overlay -->
<div class="drawer-overlay" id="menuOverlay"></div>

<!-- Mobile Drawer -->
<div class="mobile-menu-drawer" id="mobileDrawer">
    <div class="drawer-header">
        <div class="logo-container">
            <i class="fa-solid fa-wallet logo-img"></i>
            <span class="logo-text">IEMS ERP</span>
        </div>
        <button class="mobile-menu-toggle" id="closeDrawerBtn">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    
    <div class="sidebar-profile" style="display: flex; padding: 20px; border-bottom: 1px solid var(--border-color); gap: 12px;">
        <div class="profile-avatar"><?= clean($initials) ?></div>
        <div class="profile-info">
            <span class="profile-name" style="color: white; font-weight:600; font-size:0.9rem;"><?= clean($current_user_name) ?></span>
            <span class="profile-role" style="color: var(--text-secondary); font-size:0.75rem; text-transform:capitalize;"><?= str_replace('_', ' ', clean($current_user_role)) ?></span>
        </div>
    </div>
    
    <ul class="sidebar-menu">
        <li class="menu-item <?= is_active('dashboard', $active_page ?? '') ?>">
            <a href="dashboard.php">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('accounts', $active_page ?? '') ?>">
            <a href="accounts.php">
                <i class="fa-solid fa-building-columns"></i>
                <span>Bank Accounts</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('income', $active_page ?? '') ?>">
            <a href="income.php">
                <i class="fa-solid fa-circle-arrow-down"></i>
                <span>Income</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('expense', $active_page ?? '') ?>">
            <a href="expense.php">
                <i class="fa-solid fa-circle-arrow-up"></i>
                <span>Expenses</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('transfers', $active_page ?? '') ?>">
            <a href="transfers.php">
                <i class="fa-solid fa-right-left"></i>
                <span>Transfers</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('reports', $active_page ?? '') ?>">
            <a href="reports.php">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                <span>Reports</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('loans', $active_page ?? '') ?>">
            <a href="loans.php">
                <i class="fa-solid fa-hand-holding-dollar" style="color: #f59e0b;"></i>
                <span>Loans</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('loans_given', $active_page ?? '') ?>">
            <a href="loans-given.php">
                <i class="fa-solid fa-hand-holding-hand" style="color: #a855f7;"></i>
                <span>Given Loans</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('quick_collect', $active_page ?? '') ?>">
            <a href="quick-collect.php">
                <i class="fa-solid fa-qrcode" style="color: #06b6d4;"></i>
                <span>Quick Collect</span>
            </a>
        </li>
        
        <?php if (in_array($current_user_role, ['super_admin', 'admin'])): ?>
        <li class="menu-item <?= is_active('users', $active_page ?? '') ?>">
            <a href="users.php">
                <i class="fa-solid fa-users-gear"></i>
                <span>Users Control</span>
            </a>
        </li>
        <?php endif; ?>
        
        <li class="menu-item <?= is_active('activity_logs', $active_page ?? '') ?>">
            <a href="activity_logs.php">
                <i class="fa-solid fa-clipboard-list"></i>
                <span>Activity Logs</span>
            </a>
        </li>
        
        <?php if ($current_user_role === 'super_admin'): ?>
        <li class="menu-item <?= ($active_page === 'backup') ? 'active' : '' ?>">
            <a href="javascript:void(0);" onclick="toggleSettingsDropdown(event);" style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-gears"></i>
                    <span>Settings</span>
                </div>
                <i class="fa-solid fa-chevron-down dropdown-arrow" style="font-size: 0.8rem; transition: transform 0.3s; <?= ($active_page === 'backup') ? 'transform: rotate(180deg);' : '' ?>"></i>
            </a>
            <ul class="submenu" style="display: <?= ($active_page === 'backup') ? 'block' : 'none' ?>; list-style: none; padding-left: 20px; margin-top: 5px;">
                <li class="submenu-item" style="margin: 8px 0;">
                    <a href="backup.php" style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; padding: 6px 12px; border-radius: 6px; color: <?= ($active_page === 'backup') ? '#ffffff' : 'var(--text-secondary)' ?>; background: <?= ($active_page === 'backup') ? 'var(--primary)' : 'transparent' ?>; text-decoration: none;">
                        <i class="fa-solid fa-database" style="font-size: 0.85rem;"></i>
                        <span>Backup DB</span>
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>
        
        <li class="menu-item" style="margin-top: auto;">
            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Sign Out</span>
            </a>
        </li>
    </ul>
</div>

<!-- Mobile Bottom Navigation Bar (Quick Actions) -->
<div class="mobile-bottom-nav">
    <a href="dashboard.php" class="nav-item <?= ($active_page === 'dashboard') ? 'active' : '' ?>">
        <i class="fa-solid fa-chart-pie"></i>
        <span>Home</span>
    </a>
    <a href="income.php" class="nav-item <?= ($active_page === 'income') ? 'active' : '' ?>" style="color: var(--success) !important;">
        <i class="fa-solid fa-circle-plus"></i>
        <span>+ Income</span>
    </a>
    <a href="expense.php" class="nav-item <?= ($active_page === 'expense') ? 'active' : '' ?>" style="color: var(--danger) !important;">
        <i class="fa-solid fa-circle-minus"></i>
        <span>- Expense</span>
    </a>
    <a href="accounts.php" class="nav-item <?= ($active_page === 'accounts') ? 'active' : '' ?>">
        <i class="fa-solid fa-building-columns"></i>
        <span>Accounts</span>
    </a>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const mobileMenuBtn = document.getElementById("mobileMenuBtn");
    const closeDrawerBtn = document.getElementById("closeDrawerBtn");
    const mobileDrawer = document.getElementById("mobileDrawer");
    const menuOverlay = document.getElementById("menuOverlay");
    
    function openDrawer() {
        mobileDrawer.classList.add("open");
        menuOverlay.classList.add("show");
    }
    
    function closeDrawer() {
        mobileDrawer.classList.remove("open");
        menuOverlay.classList.remove("show");
    }
    
    mobileMenuBtn.addEventListener("click", openDrawer);
    closeDrawerBtn.addEventListener("click", closeDrawer);
    menuOverlay.addEventListener("click", closeDrawer);
});
</script>
