<?php
// Sidebar layout for Income & Expense Management System (IEMS)
$current_user_name = $_SESSION['user_name'] ?? 'User';
$current_user_role = $_SESSION['user_role'] ?? 'staff';
$initials = '';
$words = explode(' ', $current_user_name);
foreach ($words as $w) {
    $initials .= strtoupper($w[0] ?? '');
}
$initials = substr($initials, 0, 2);

// Check active menu item helper
function is_active($pageName, $active_page) {
    return ($active_page === $pageName) ? 'active' : '';
}
?>

<div class="sidebar" id="appSidebar">
    <div class="sidebar-header">
        <div class="logo-container">
            <i class="fa-solid fa-wallet logo-img"></i>
            <span class="logo-text">IEMS ERP</span>
        </div>
    </div>
    
    <div class="sidebar-profile">
        <div class="profile-avatar"><?= clean($initials) ?></div>
        <div class="profile-info">
            <span class="profile-name"><?= clean($current_user_name) ?></span>
            <span class="profile-role"><?= str_replace('_', ' ', clean($current_user_role)) ?></span>
        </div>
    </div>
    
    <ul class="sidebar-menu">
        <li class="menu-item <?= is_active('dashboard', $active_page ?? '') ?>">
            <a href="dashboard.php">
                <i class="fa-solid fa-chart-pie" style="color: #3b82f6;"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('accounts', $active_page ?? '') ?>">
            <a href="accounts.php">
                <i class="fa-solid fa-building-columns" style="color: #10b981;"></i>
                <span>Bank Accounts</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('income', $active_page ?? '') ?>">
            <a href="income.php">
                <i class="fa-solid fa-circle-arrow-down" style="color: #22c55e;"></i>
                <span>Income</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('expense', $active_page ?? '') ?>">
            <a href="expense.php">
                <i class="fa-solid fa-circle-arrow-up" style="color: #ef4444;"></i>
                <span>Expenses</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('transfers', $active_page ?? '') ?>">
            <a href="transfers.php">
                <i class="fa-solid fa-right-left" style="color: #6366f1;"></i>
                <span>Transfers</span>
            </a>
        </li>
        <li class="menu-item <?= is_active('reports', $active_page ?? '') ?>">
            <a href="reports.php">
                <i class="fa-solid fa-file-invoice-dollar" style="color: #eab308;"></i>
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
                <i class="fa-solid fa-users-gear" style="color: #ec4899;"></i>
                <span>Users Control</span>
            </a>
        </li>
        <?php endif; ?>
        
        <li class="menu-item <?= is_active('activity_logs', $active_page ?? '') ?>">
            <a href="activity_logs.php">
                <i class="fa-solid fa-clipboard-list" style="color: #14b8a6;"></i>
                <span>Activity Logs</span>
            </a>
        </li>
        
        <?php if ($current_user_role === 'super_admin'): ?>
        <li class="menu-item <?= ($active_page === 'backup' || $active_page === 'settings') ? 'active' : '' ?>">
            <a href="javascript:void(0);" onclick="toggleSettingsDropdown(event);" style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-gears" style="color: #64748b;"></i>
                    <span>Settings</span>
                </div>
                <i class="fa-solid fa-chevron-down dropdown-arrow" style="font-size: 0.8rem; transition: transform 0.3s; <?= ($active_page === 'backup' || $active_page === 'settings') ? 'transform: rotate(180deg);' : '' ?>"></i>
            </a>
            <ul class="submenu" style="display: <?= ($active_page === 'backup' || $active_page === 'settings') ? 'block' : 'none' ?>; list-style: none; padding-left: 20px; margin-top: 5px;">
                <li class="submenu-item" style="margin: 8px 0;">
                    <a href="settings.php" style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; padding: 6px 12px; border-radius: 6px; color: <?= ($active_page === 'settings') ? '#ffffff' : 'var(--text-secondary)' ?>; background: <?= ($active_page === 'settings') ? 'var(--primary)' : 'transparent' ?>; text-decoration: none;">
                        <i class="fa-solid fa-sliders" style="font-size: 0.85rem; color: #f43f5e;"></i>
                        <span>System Settings</span>
                    </a>
                </li>
                <li class="submenu-item" style="margin: 8px 0;">
                    <a href="backup.php" style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; padding: 6px 12px; border-radius: 6px; color: <?= ($active_page === 'backup') ? '#ffffff' : 'var(--text-secondary)' ?>; background: <?= ($active_page === 'backup') ? 'var(--primary)' : 'transparent' ?>; text-decoration: none;">
                        <i class="fa-solid fa-database" style="font-size: 0.85rem; color: #8b5cf6;"></i>
                        <span>Backup DB</span>
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>
        
        <li class="menu-item" style="margin-top: auto;">
            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket" style="color: #ef4444;"></i>
                <span>Sign Out</span>
            </a>
        </li>
    </ul>
    
    <div class="sidebar-footer">
        <button class="sidebar-toggle-btn" id="sidebarCollapseBtn">
            <i class="fa-solid fa-angles-left" id="collapseIcon"></i>
        </button>
    </div>
</div>

<script>
window.toggleSettingsDropdown = function(event) {
    event.preventDefault();
    const item = event.currentTarget.closest(".menu-item");
    const submenu = item.querySelector(".submenu");
    const arrow = item.querySelector(".dropdown-arrow");
    
    if (submenu.style.display === "none" || !submenu.style.display) {
        submenu.style.display = "block";
        arrow.style.transform = "rotate(180deg)";
    } else {
        submenu.style.display = "none";
        arrow.style.transform = "rotate(0deg)";
    }
};

document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.getElementById("appSidebar");
    const collapseBtn = document.getElementById("sidebarCollapseBtn");
    const collapseIcon = document.getElementById("collapseIcon");
    
    // Check local storage for sidebar state
    if (localStorage.getItem("sidebar-collapsed") === "true") {
        sidebar.classList.add("collapsed");
        collapseIcon.classList.replace("fa-angles-left", "fa-angles-right");
    }
    
    collapseBtn.addEventListener("click", function() {
        sidebar.classList.toggle("collapsed");
        const isCollapsed = sidebar.classList.contains("collapsed");
        localStorage.setItem("sidebar-collapsed", isCollapsed);
        
        if (isCollapsed) {
            collapseIcon.classList.replace("fa-angles-left", "fa-angles-right");
        } else {
            collapseIcon.classList.replace("fa-angles-right", "fa-angles-left");
        }
    });
});
</script>
