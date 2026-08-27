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

    }
};

// Predefined Color Palettes definition
window.THEME_PALETTES = [
    { id: "ui_1", name: "Enterprise Navy", primary: "#3b82f6", primaryHover: "#2563eb", bgSidebar: "#0f172a", bgPrimary: "#f8fafc", bgSecondary: "#ffffff", border: "#e2e8f0", darkBgPrimary: "#0b0f19", darkBgSecondary: "#131a26", darkBgSidebar: "#0f1520", darkBorder: "#222d3d" },
    { id: "ui_2", name: "Graphite & Cyan", primary: "#06b6d4", primaryHover: "#0891b2", bgSidebar: "#1e293b", bgPrimary: "#f8fafc", bgSecondary: "#ffffff", border: "#e2e8f0", darkBgPrimary: "#0f172a", darkBgSecondary: "#1e293b", darkBgSidebar: "#0f172a", darkBorder: "#334155" },
    { id: "ui_3", name: "Forest Executive", primary: "#10b981", primaryHover: "#059669", bgSidebar: "#064e3b", bgPrimary: "#f4fcf7", bgSecondary: "#ffffff", border: "#d1fae5", darkBgPrimary: "#022c22", darkBgSecondary: "#064e3b", darkBgSidebar: "#022c22", darkBorder: "#065f46" },
    { id: "ui_4", name: "Walnut & Sand", primary: "#b45309", primaryHover: "#92400e", bgSidebar: "#451a03", bgPrimary: "#fdfbf7", bgSecondary: "#ffffff", border: "#fef3c7", darkBgPrimary: "#1c1917", darkBgSecondary: "#44403c", darkBgSidebar: "#1c1917", darkBorder: "#78716c" },
    { id: "ui_5", name: "Emerald Corporate", primary: "#059669", primaryHover: "#047857", bgSidebar: "#022c22", bgPrimary: "#f0fdf4", bgSecondary: "#ffffff", border: "#dcfce7", darkBgPrimary: "#090d16", darkBgSecondary: "#0f1520", darkBgSidebar: "#0b0f19", darkBorder: "#1e293b" },
    { id: "ui_6", name: "Mocha Executive", primary: "#854d0e", primaryHover: "#713f12", bgSidebar: "#3f2d20", bgPrimary: "#fafaf9", bgSecondary: "#ffffff", border: "#f5f5f4", darkBgPrimary: "#1c1917", darkBgSecondary: "#292524", darkBgSidebar: "#1c1917", darkBorder: "#44403c" },
    { id: "ui_7", name: "Charcoal & Soft Gold", primary: "#d97706", primaryHover: "#b45309", bgSidebar: "#111827", bgPrimary: "#f9fafb", bgSecondary: "#ffffff", border: "#f3f4f6", darkBgPrimary: "#111827", darkBgSecondary: "#1f2937", darkBgSidebar: "#111827", darkBorder: "#374151" },
    { id: "ui_v1", name: "Zinc & Sky", primary: "#0284c7", primaryHover: "#0369a1", bgSidebar: "#18181b", bgPrimary: "#fafafa", bgSecondary: "#ffffff", border: "#e4e4e7", darkBgPrimary: "#09090b", darkBgSecondary: "#18181b", darkBgSidebar: "#09090b", darkBorder: "#27272a" },
    { id: "ui_v2", name: "Ink & Violet", primary: "#8b5cf6", primaryHover: "#7c3aed", bgSidebar: "#1e1b4b", bgPrimary: "#faf5ff", bgSecondary: "#ffffff", border: "#f3e8ff", darkBgPrimary: "#0f172a", darkBgSecondary: "#1e1b4b", darkBgSidebar: "#0f172a", darkBorder: "#312e81" },
    { id: "ui_v3", name: "Slate & Rose", primary: "#f43f5e", primaryHover: "#e11d48", bgSidebar: "#0f172a", bgPrimary: "#fff1f2", bgSecondary: "#ffffff", border: "#ffe4e6", darkBgPrimary: "#0b0f19", darkBgSecondary: "#1e293b", darkBgSidebar: "#0f1520", darkBorder: "#334155" },
    { id: "ui_v4", name: "Stone & Amber", primary: "#d97706", primaryHover: "#b45309", bgSidebar: "#1c1917", bgPrimary: "#fdfbf7", bgSecondary: "#ffffff", border: "#f5f5f4", darkBgPrimary: "#0c0a09", darkBgSecondary: "#1c1917", darkBgSidebar: "#0c0a09", darkBorder: "#2e2a24" },
    { id: "ui_v5", name: "Mineral Teal", primary: "#0d9488", primaryHover: "#0f766e", bgSidebar: "#115e59", bgPrimary: "#f0fdfa", bgSecondary: "#ffffff", border: "#ccfbf1", darkBgPrimary: "#042f2e", darkBgSecondary: "#115e59", darkBgSidebar: "#042f2e", darkBorder: "#134e4a" },
    { id: "ui_v6", name: "Paper & Copper", primary: "#ea580c", primaryHover: "#ca8a04", bgSidebar: "#2e1e0f", bgPrimary: "#fffbeb", bgSecondary: "#ffffff", border: "#fef3c7", darkBgPrimary: "#170f08", darkBgSecondary: "#2e1e0f", darkBgSidebar: "#170f08", darkBorder: "#451a03" },
    { id: "ui_v7", name: "Obsidian & Mint", primary: "#10b981", primaryHover: "#059669", bgSidebar: "#090d16", bgPrimary: "#f8fafc", bgSecondary: "#ffffff", border: "#e2e8f0", darkBgPrimary: "#090d16", darkBgSecondary: "#131a26", darkBgSidebar: "#090d16", darkBorder: "#222d3d" },
    { id: "ui_v8", name: "Cloud & Indigo Soft", primary: "#6366f1", primaryHover: "#4f46e5", bgSidebar: "#312e81", bgPrimary: "#eef2ff", bgSecondary: "#ffffff", border: "#e0e7ff", darkBgPrimary: "#0f172a", darkBgSecondary: "#312e81", darkBgSidebar: "#0f172a", darkBorder: "#4338ca" }
];

window.applyThemePalette = function(paletteId, isDark) {
    const palette = window.THEME_PALETTES.find(p => p.id === paletteId) || window.THEME_PALETTES[1]; // default Graphite & Cyan
    const root = document.documentElement;
    
    // Set HTML theme attribute
    root.setAttribute("data-theme", isDark ? "dark" : "light");
    
    // Set custom CSS variables
    root.style.setProperty("--primary", palette.primary);
    root.style.setProperty("--primary-hover", palette.primaryHover);
    root.style.setProperty("--primary-light", isDark ? "rgba(99,102,241,0.12)" : "rgba(99,102,241,0.06)");
    
    if (isDark) {
        root.style.setProperty("--bg-primary", palette.darkBgPrimary);
        root.style.setProperty("--bg-secondary", palette.darkBgSecondary);
        root.style.setProperty("--bg-sidebar", palette.darkBgSidebar);
        root.style.setProperty("--border-color", palette.darkBorder);
        root.style.setProperty("--text-primary", "#f1f5f9");
        root.style.setProperty("--text-secondary", "#94a3b8");
        root.style.setProperty("--text-light", "#ffffff");
        root.style.setProperty("--bg-input", "#1e2633");
    } else {
        root.style.setProperty("--bg-primary", palette.bgPrimary);
        root.style.setProperty("--bg-secondary", palette.bgSecondary);
        root.style.setProperty("--bg-sidebar", palette.bgSidebar);
        root.style.setProperty("--border-color", palette.border);
        root.style.setProperty("--text-primary", "#0f172a");
        root.style.setProperty("--text-secondary", "#475569");
        root.style.setProperty("--text-light", "#0f172a");
        root.style.setProperty("--bg-input", "#f1f5f9");
    }
    
    localStorage.setItem("theme-palette", paletteId);
    localStorage.setItem("theme-dark", isDark ? "true" : "false");
};

// Immediately initialize theme on load to prevent flash
(function() {
    const defaultPalette = "<?= getSetting('default_theme_palette', 'ui_2') ?>";
    const defaultDark = "<?= getSetting('default_theme_dark', '1') ?>";
    
    const savedPalette = localStorage.getItem("theme-palette") || defaultPalette;
    const savedDark = localStorage.getItem("theme-dark") !== null ? (localStorage.getItem("theme-dark") === "true") : (defaultDark === "1");
    
    window.applyThemePalette(savedPalette, savedDark);
})();

document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.getElementById("appSidebar");
    const collapseBtn = document.getElementById("sidebarCollapseBtn");
    const collapseIcon = document.getElementById("collapseIcon");
    
    // Check local storage for sidebar state
    if (sidebar && localStorage.getItem("sidebar-collapsed") === "true") {
        sidebar.classList.add("collapsed");
        if (collapseIcon) collapseIcon.classList.replace("fa-angles-left", "fa-angles-right");
    }
    
    if (collapseBtn) {
        collapseBtn.addEventListener("click", function() {
            if (sidebar) {
                sidebar.classList.toggle("collapsed");
                const isCollapsed = sidebar.classList.contains("collapsed");
                localStorage.setItem("sidebar-collapsed", isCollapsed);
                
                if (collapseIcon) {
                    if (isCollapsed) {
                        collapseIcon.classList.replace("fa-angles-left", "fa-angles-right");
                    } else {
                        collapseIcon.classList.replace("fa-angles-right", "fa-angles-left");
                    }
                }
            }
        });
    }

    // ── Theme Popover Injection ──
    const navActions = document.querySelector(".nav-actions");
    if (navActions) {
        // Hide default php theme toggle anchor
        const oldToggle = navActions.querySelector('a[href*="toggle_theme"]');
        if (oldToggle) oldToggle.style.display = "none";
        
        // Check if button already exists
        if (!document.getElementById("themePickerBtn")) {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "nav-btn";
            btn.id = "themePickerBtn";
            btn.title = "Theme Settings";
            btn.style.marginRight = "8px";
            btn.innerHTML = '<i class="fa-solid fa-palette"></i>';
            navActions.prepend(btn);
            
            // Popover layout
            const popover = document.createElement("div");
            popover.id = "themePickerPopover";
            popover.style.cssText = "display:none; position:absolute; top:55px; right:0; width:310px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; box-shadow:var(--shadow-main); z-index:99999; padding:16px; box-sizing:border-box;";
            
            popover.innerHTML = `
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid var(--border-color); color:var(--text-light);">
                    <span style="font-weight:700; font-size:0.88rem; color:var(--text-light);"><i class="fa-solid fa-palette" style="color:var(--primary); margin-right:6px;"></i>Theme Picker</span>
                    <button id="closeThemePopover" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                </div>
                
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                    <span style="font-size:0.82rem; font-weight:600; color:var(--text-light);">Dark Mode</span>
                    <label style="position:relative; display:inline-block; width:40px; height:20px; margin:0;">
                        <input type="checkbox" id="popoverDarkModeToggle" style="opacity:0; width:0; height:0;">
                        <span class="theme-switch-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:var(--border-color); transition:.3s; border-radius:20px;"></span>
                    </label>
                </div>
                
                <div style="font-size:0.7rem; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">Color Palette</div>
                <div class="popover-palettes-grid" id="popoverPalettesGrid" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin-bottom:14px; max-height:150px; overflow-y:auto; padding-right:4px;">
                    <!-- Palettes generated dynamically -->
                </div>
                
                <div style="display:flex; gap:8px; border-top:1px solid var(--border-color); padding-top:12px; margin-top:8px;">
                    <button type="button" class="btn-secondary" id="popoverThemeReset" style="flex:1; padding:6px; font-size:0.75rem; justify-content:center; margin:0;"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                    <button type="button" class="btn-primary" id="popoverThemeApply" style="flex:1.5; padding:6px; font-size:0.75rem; justify-content:center; margin:0; background:var(--primary); border-color:var(--primary);"><i class="fa-solid fa-check"></i> Apply</button>
                </div>
            `;
            
            navActions.style.position = "relative";
            navActions.appendChild(popover);
            
            // Add style rules
            if (!document.getElementById("theme-switch-styles")) {
                const style = document.createElement("style");
                style.id = "theme-switch-styles";
                style.textContent = `
                    .theme-switch-slider:before {
                        position: absolute; content: "";
                        height: 14px; width: 14px;
                        left: 3px; bottom: 3px;
                        background-color: white; transition: .3s;
                        border-radius: 50%;
                    }
                    input:checked + .theme-switch-slider { background-color: var(--primary); }
                    input:checked + .theme-switch-slider:before { transform: translateX(20px); }
                    .popover-palette-dot { width:8px; height:8px; border-radius:50%; }
                    .popover-palette-item.active { border-color: var(--primary) !important; outline: 1px solid var(--primary); }
                `;
                document.head.appendChild(style);
            }
            
            // Generate palette previews in popover
            const pGrid = document.getElementById("popoverPalettesGrid");
            let tempPalette = localStorage.getItem("theme-palette") || defaultPalette;
            let tempDark = localStorage.getItem("theme-dark") !== null ? (localStorage.getItem("theme-dark") === "true") : (defaultDark === "1");
            
            window.THEME_PALETTES.forEach(p => {
                const pItem = document.createElement("button");
                pItem.type = "button";
                pItem.className = "popover-palette-item" + (tempPalette === p.id ? " active" : "");
                pItem.title = p.name;
                pItem.style.cssText = "height:32px; border-radius:6px; border:2px solid var(--border-color); background:" + (tempDark ? p.darkBgSecondary : p.bgSecondary) + "; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:3px; padding:2px; box-sizing:border-box;";
                pItem.innerHTML = `
                    <span class="popover-palette-dot" style="background:${p.primary};"></span>
                    <span class="popover-palette-dot" style="background:${p.bgSidebar}; border:1px solid rgba(255,255,255,0.08);"></span>
                `;
                
                pItem.onclick = function() {
                    document.querySelectorAll(".popover-palette-item").forEach(el => el.classList.remove("active"));
                    pItem.classList.add("active");
                    tempPalette = p.id;
                };
                pGrid.appendChild(pItem);
            });
            
            // Set dark mode checkbox status
            const darkToggle = document.getElementById("popoverDarkModeToggle");
            darkToggle.checked = tempDark;
            darkToggle.onchange = function() {
                tempDark = darkToggle.checked;
                // Update palette preview item backgrounds dynamically to show light/dark colors
                document.querySelectorAll(".popover-palette-item").forEach((el, index) => {
                    const p = window.THEME_PALETTES[index];
                    el.style.background = tempDark ? p.darkBgSecondary : p.bgSecondary;
                });
            };
            
            // Toggle Popover visibility
            btn.onclick = function(e) {
                e.stopPropagation();
                popover.style.display = popover.style.display === "none" ? "block" : "none";
            };
            
            document.getElementById("closeThemePopover").onclick = function(e) {
                e.stopPropagation();
                popover.style.display = "none";
            };
            
            popover.onclick = function(e) { e.stopPropagation(); };
            document.addEventListener("click", function() { popover.style.display = "none"; });
            
            // Apply handler
            document.getElementById("popoverThemeApply").onclick = function() {
                window.applyThemePalette(tempPalette, tempDark);
                popover.style.display = "none";
                if (typeof showToast !== 'undefined') showToast(true, "Theme palette applied!");
                setTimeout(() => location.reload(), 300);
            };
            
            // Reset handler
            document.getElementById("popoverThemeReset").onclick = function() {
                localStorage.removeItem("theme-palette");
                localStorage.removeItem("theme-dark");
                location.reload();
            };
        }
    }
});
</script>
