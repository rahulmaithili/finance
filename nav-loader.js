// Navigation Loader & Auth State Guard
// Dynamically renders Sidebar, Mobile Topbar, and Mobile Bottom Nav.
// Manages User profile initials, roles, and dropdowns.

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
        root.style.setProperty("--bg-card", palette.darkBgSecondary);
        root.style.setProperty("--bg-sidebar", palette.darkBgSidebar);
        root.style.setProperty("--border-color", palette.darkBorder);
        root.style.setProperty("--border", palette.darkBorder);
        root.style.setProperty("--text-primary", "#f1f5f9");
        root.style.setProperty("--text-secondary", "#94a3b8");
        root.style.setProperty("--text-light", "#ffffff");
        root.style.setProperty("--bg-input", "#1e2633");
    } else {
        root.style.setProperty("--bg-primary", palette.bgPrimary);
        root.style.setProperty("--bg-secondary", palette.bgSecondary);
        root.style.setProperty("--bg-card", palette.bgSecondary);
        root.style.setProperty("--bg-sidebar", palette.bgSidebar);
        root.style.setProperty("--border-color", palette.border);
        root.style.setProperty("--border", palette.border);
        root.style.setProperty("--text-primary", "#0f172a");
        root.style.setProperty("--text-secondary", "#475569");
        root.style.setProperty("--text-light", "#0f172a");
        root.style.setProperty("--bg-input", "#f1f5f9");
    }
    
    localStorage.setItem("theme-palette", paletteId);
    localStorage.setItem("theme-dark", isDark ? "true" : "false");
};

window.formatCurrency = function(amount, currencyCode = null) {
    const currency = (currencyCode || 'INR').toUpperCase();
    const symbols = { 'INR': '₹', 'USD': '$', 'EUR': '€', 'GBP': '£' };
    const symbol = symbols[currency] || currency;
    const formattedAmount = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);
    return symbol + formattedAmount;
};

// Immediately initialize theme on load to prevent flash
(function() {
    const savedPalette = localStorage.getItem("theme-palette") || "ui_2";
    const savedDark = localStorage.getItem("theme-dark") !== null ? (localStorage.getItem("theme-dark") === "true") : true;
    window.applyThemePalette(savedPalette, savedDark);
})();

(function () {
    // 1. Create global navigation containers if they don't exist
    document.addEventListener("DOMContentLoaded", function () {
        // Enforce Authentication Guard on every page except login.html
        if (!window.location.pathname.endsWith('login.html')) {
            auth.onAuthStateChanged((user) => {
                if (!user) {
                    window.location.href = 'login.html';
                } else {
                    // Fetch user details from Firestore
                    db.collection('users').doc(user.uid).get().then((doc) => {
                        if (doc.exists) {
                            const userData = doc.data();
                            renderNavigation(userData);
                            if (window.onNavigationLoaded) {
                                window.onNavigationLoaded(user, userData);
                            }
                        } else {
                            // Document does not exist (failed during locked rules signup)
                            // Auto-provision user as super_admin
                            const newAdmin = {
                                full_name: user.email.split('@')[0],
                                email: user.email,
                                role: 'super_admin',
                                status: 'active',
                                created_at: firebase.firestore.FieldValue.serverTimestamp()
                            };
                            db.collection('users').doc(user.uid).set(newAdmin).then(() => {
                                renderNavigation(newAdmin);
                                if (window.onNavigationLoaded) {
                                    window.onNavigationLoaded(user, newAdmin);
                                }
                            }).catch(err => {
                                const fallbackData = { full_name: user.email.split('@')[0], role: 'staff' };
                                renderNavigation(fallbackData);
                                if (window.onNavigationLoaded) {
                                    window.onNavigationLoaded(user, fallbackData);
                                }
                            });
                        }
                    }).catch(err => {
                        console.error("Firestore user load failed, using fallback:", err);
                        const fallbackData = { full_name: user.email.split('@')[0], role: 'staff' };
                        renderNavigation(fallbackData);
                        if (window.onNavigationLoaded) {
                            window.onNavigationLoaded(user, fallbackData);
                        }
                        // Show warning alert for rules block
                        setTimeout(() => {
                            if (window.Swal) {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Database Rules Warning',
                                    text: 'Failed to read user profile: ' + err.message + '. Please ensure Firestore Database Rules are published.'
                                });
                            }
                        }, 500);
                    });
                }
            });
        }
    });

    // 2. Render Sidebar & Mobile menu elements
    function renderNavigation(user) {
        const activePage = window.location.pathname.split("/").pop().replace(".html", "").replace("-", "_") || 'index';
        
        // Generate Initials
        let initials = '';
        let words = user.full_name.split(' ');
        words.forEach(w => { if (w) initials += w[0].toUpperCase(); });
        initials = initials.substring(0, 2) || 'US';

        const roleText = user.role.replace('_', ' ');

        // Profile picture html helper
        const profileAvatarHTML = user.profile_pic_url 
            ? `<div class="profile-avatar" style="background-image: url('${user.profile_pic_url}'); background-size: cover; background-position: center; border-radius: 50%; font-size: 0; border: 2px solid var(--primary);"></div>`
            : `<div class="profile-avatar">${initials}</div>`;

        // HTML Templates
        const sidebarHTML = `
        <div class="sidebar" id="appSidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <i class="fa-solid fa-wallet logo-img"></i>
                    <span class="logo-text" id="sidebarLogoText">IEMS ERP</span>
                </div>
            </div>
            
            <a href="profile.html" class="sidebar-profile" style="text-decoration: none;">
                ${profileAvatarHTML}
                <div class="profile-info">
                    <span class="profile-name">${user.full_name}</span>
                    <span class="profile-role" style="text-transform: capitalize;">${roleText}</span>
                </div>
            </a>
            
            <ul class="sidebar-menu">
                <li class="menu-item ${activePage === 'index' ? 'active' : ''}">
                    <a href="index.html">
                        <i class="fa-solid fa-chart-pie" style="color: #3b82f6;"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'accounts' ? 'active' : ''}">
                    <a href="accounts.html">
                        <i class="fa-solid fa-building-columns" style="color: #10b981;"></i>
                        <span>Bank Accounts</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'income' ? 'active' : ''}">
                    <a href="income.html">
                        <i class="fa-solid fa-circle-arrow-down" style="color: #059669;"></i>
                        <span>Income</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'expense' ? 'active' : ''}">
                    <a href="expense.html">
                        <i class="fa-solid fa-circle-arrow-up" style="color: #dc2626;"></i>
                        <span>Expenses</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'transfers' ? 'active' : ''}">
                    <a href="transfers.html">
                        <i class="fa-solid fa-right-left" style="color: #8b5cf6;"></i>
                        <span>Transfers</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'reports' ? 'active' : ''}">
                    <a href="reports.html">
                        <i class="fa-solid fa-file-invoice-dollar" style="color: #6366f1;"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'loans' ? 'active' : ''}">
                    <a href="loans.html">
                        <i class="fa-solid fa-hand-holding-dollar" style="color: #f59e0b;"></i>
                        <span>Loans</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'loans_given' ? 'active' : ''}">
                    <a href="loans-given.html">
                        <i class="fa-solid fa-hand-holding-hand" style="color: #a855f7;"></i>
                        <span>Given Loans</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'quick_collect' ? 'active' : ''}">
                    <a href="quick-collect.html">
                        <i class="fa-solid fa-qrcode" style="color: #06b6d4;"></i>
                        <span>Quick Collect</span>
                    </a>
                </li>
                
                ${(user.role === 'super_admin' || user.role === 'admin') ? `
                <li class="menu-item ${activePage === 'users' ? 'active' : ''}">
                    <a href="users.html">
                        <i class="fa-solid fa-users-gear" style="color: #ec4899;"></i>
                        <span>Users Control</span>
                    </a>
                </li>
                ` : ''}
                
                <li class="menu-item ${activePage === 'activity_logs' ? 'active' : ''}">
                    <a href="activity_logs.html">
                        <i class="fa-solid fa-clipboard-list" style="color: #06b6d4;"></i>
                        <span>Activity Logs</span>
                    </a>
                </li>
                
                ${user.role === 'super_admin' ? `
                <li class="menu-item ${activePage === 'settings' ? 'active' : ''}">
                    <a href="settings.html">
                        <i class="fa-solid fa-gears" style="color: #a8a29e;"></i>
                        <span>System Settings</span>
                    </a>
                </li>
                ` : ''}

                <li class="menu-item">
                    <a href="#" id="themeToggleBtnPC">
                        <i class="fa-solid fa-sun" id="themeIconPC" style="color: #eab308;"></i>
                        <span>Theme Toggle</span>
                    </a>
                </li>
                
                <li class="menu-item" style="margin-top: auto;">
                    <a href="#" id="signOutBtn">
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
        </div>`;

        const mobileNavHTML = `
        <!-- Mobile Top Bar -->
        <div class="mobile-navbar">
            <button class="mobile-menu-toggle" id="mobileMenuBtn">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="page-title" id="mobileNavbarTitle">IEMS ERP</div>
            <button class="nav-btn" id="themeToggleBtn" style="width: 35px; height: 35px; border-radius: 6px; border:none; background:transparent; color:inherit;">
                <i class="fa-solid fa-sun" id="themeIcon"></i>
            </button>
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
            
            <a href="profile.html" class="sidebar-profile" style="display: flex; padding: 20px; border-bottom: 1px solid var(--border-color); gap: 12px; text-decoration: none;">
                ${profileAvatarHTML}
                <div class="profile-info">
                    <span class="profile-name" style="color: white; font-weight:600; font-size:0.9rem;">${user.full_name}</span>
                    <span class="profile-role" style="color: var(--text-secondary); font-size:0.75rem; text-transform:capitalize;">${roleText}</span>
                </div>
            </a>
            
            <ul class="sidebar-menu">
                <li class="menu-item ${activePage === 'index' ? 'active' : ''}">
                    <a href="index.html">
                        <i class="fa-solid fa-chart-pie" style="color: #3b82f6;"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'accounts' ? 'active' : ''}">
                    <a href="accounts.html">
                        <i class="fa-solid fa-building-columns" style="color: #10b981;"></i>
                        <span>Bank Accounts</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'income' ? 'active' : ''}">
                    <a href="income.html">
                        <i class="fa-solid fa-circle-arrow-down" style="color: #059669;"></i>
                        <span>Income</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'expense' ? 'active' : ''}">
                    <a href="expense.html">
                        <i class="fa-solid fa-circle-arrow-up" style="color: #dc2626;"></i>
                        <span>Expenses</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'transfers' ? 'active' : ''}">
                    <a href="transfers.html">
                        <i class="fa-solid fa-right-left" style="color: #8b5cf6;"></i>
                        <span>Transfers</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'reports' ? 'active' : ''}">
                    <a href="reports.html">
                        <i class="fa-solid fa-file-invoice-dollar" style="color: #6366f1;"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'loans' ? 'active' : ''}">
                    <a href="loans.html">
                        <i class="fa-solid fa-hand-holding-dollar" style="color: #f59e0b;"></i>
                        <span>Loans</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'loans_given' ? 'active' : ''}">
                    <a href="loans-given.html">
                        <i class="fa-solid fa-hand-holding-hand" style="color: #a855f7;"></i>
                        <span>Given Loans</span>
                    </a>
                </li>
                <li class="menu-item ${activePage === 'quick_collect' ? 'active' : ''}">
                    <a href="quick-collect.html">
                        <i class="fa-solid fa-qrcode" style="color: #06b6d4;"></i>
                        <span>Quick Collect</span>
                    </a>
                </li>
                ${(user.role === 'super_admin' || user.role === 'admin') ? `
                <li class="menu-item ${activePage === 'users' ? 'active' : ''}">
                    <a href="users.html">
                        <i class="fa-solid fa-users-gear" style="color: #ec4899;"></i>
                        <span>Users Control</span>
                    </a>
                </li>
                ` : ''}
                <li class="menu-item ${activePage === 'activity_logs' ? 'active' : ''}">
                    <a href="activity_logs.html">
                        <i class="fa-solid fa-clipboard-list" style="color: #06b6d4;"></i>
                        <span>Activity Logs</span>
                    </a>
                </li>
                ${user.role === 'super_admin' ? `
                <li class="menu-item ${activePage === 'settings' ? 'active' : ''}">
                    <a href="settings.html">
                        <i class="fa-solid fa-gears" style="color: #a8a29e;"></i>
                        <span>System Settings</span>
                    </a>
                </li>
                ` : ''}
                <li class="menu-item">
                    <a href="#" id="signOutBtnMobile">
                        <i class="fa-solid fa-right-from-bracket" style="color: #ef4444;"></i>
                        <span>Sign Out</span>
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Mobile Bottom Navigation Bar (Quick Actions) -->
        <div class="mobile-bottom-nav">
            <a href="index.html" class="nav-item ${activePage === 'index' ? 'active' : ''}">
                <i class="fa-solid fa-chart-pie" style="color: #3b82f6;"></i>
                <span>Home</span>
            </a>
            <a href="income.html" class="nav-item ${activePage === 'income' ? 'active' : ''}">
                <i class="fa-solid fa-circle-plus" style="color: #10b981;"></i>
                <span>Income</span>
            </a>
            <a href="expense.html" class="nav-item ${activePage === 'expense' ? 'active' : ''}">
                <i class="fa-solid fa-circle-minus" style="color: #ef4444;"></i>
                <span>Expense</span>
            </a>
            <a href="reports.html" class="nav-item ${activePage === 'reports' ? 'active' : ''}">
                <i class="fa-solid fa-chart-column" style="color: #8b5cf6;"></i>
                <span>Reports</span>
            </a>
            <a href="#" class="nav-item" id="mobileMoreBtn">
                <i class="fa-solid fa-ellipsis" style="color: #f59e0b;"></i>
                <span>More</span>
            </a>
        </div>`;

        // Inject elements
        const wrapper = document.querySelector(".app-wrapper");
        if (wrapper) {
            // Desktop Sidebar injection
            const sidebarDiv = document.createElement("div");
            sidebarDiv.innerHTML = sidebarHTML;
            wrapper.insertBefore(sidebarDiv.firstElementChild, wrapper.firstChild);

            // Mobile Topbar + Bottom Nav injection
            const mobileContainer = document.createElement("div");
            mobileContainer.innerHTML = mobileNavHTML;
            while(mobileContainer.children.length > 0){
                wrapper.parentNode.insertBefore(mobileContainer.children[0], wrapper);
            }
        }

        const navbar = document.querySelector(".navbar");
        if (navbar) {
            let navActions = navbar.querySelector(".nav-actions");
            if (!navActions) {
                navActions = document.createElement("div");
                navActions.className = "nav-actions";
                navActions.innerHTML = `
                    <span style="font-size: 0.9rem; color: var(--text-secondary);">
                        Session: <strong id="userRoleText">${user.role.replace('_', ' ')}</strong>
                    </span>
                `;
                navbar.appendChild(navActions);
            }
            
            // Check if themePickerBtn already exists
            if (!document.getElementById("themePickerBtn")) {
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "nav-btn";
                btn.id = "themePickerBtn";
                btn.title = "Theme Settings";
                btn.style.marginRight = "8px";
                btn.innerHTML = '<i class="fa-solid fa-palette"></i>';
                navActions.prepend(btn);
            }

            // Check if themePickerPopover already exists in body
            let popover = document.getElementById("themePickerPopover");
            if (!popover) {
                popover = document.createElement("div");
                popover.id = "themePickerPopover";
                popover.style.cssText = "display:none; position:fixed; z-index:99999; width:310px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; box-shadow:var(--shadow-main); padding:16px; box-sizing:border-box;";
                
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
                document.body.appendChild(popover);
                
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
                let tempPalette = localStorage.getItem("theme-palette") || "ui_2";
                let tempDark = localStorage.getItem("theme-dark") !== null ? (localStorage.getItem("theme-dark") === "true") : true;
                
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
                    
                    pItem.onclick = function(e) {
                        e.stopPropagation();
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
                
                // Trigger popover on PC click
                const themeBtnPC = document.getElementById("themePickerBtn");
                if (themeBtnPC) {
                    themeBtnPC.onclick = function(e) {
                        e.stopPropagation();
                        popover.style.top = "55px";
                        popover.style.right = "16px";
                        popover.style.display = popover.style.display === "none" ? "block" : "none";
                    };
                }
                
                // Trigger popover on Mobile click
                const themeBtnMobile = document.getElementById("themeToggleBtn");
                if (themeBtnMobile) {
                    themeBtnMobile.onclick = function(e) {
                        e.stopPropagation();
                        popover.style.top = "60px";
                        popover.style.right = "10px";
                        popover.style.display = popover.style.display === "none" ? "block" : "none";
                    };
                }
                
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
                    setTimeout(() => location.reload(), 200);
                };
                
                // Reset handler
                document.getElementById("popoverThemeReset").onclick = function() {
                    localStorage.removeItem("theme-palette");
                    localStorage.removeItem("theme-dark");
                    location.reload();
                };
            }
        }

        // Attach Navigation Event Listeners
        attachNavListeners();

        // Sync theme display
        syncThemeDisplay();

        // Load branding names dynamically
        loadNavBranding();
    }

    function attachNavListeners() {
        const mobileMenuBtn = document.getElementById("mobileMenuBtn");
        const closeDrawerBtn = document.getElementById("closeDrawerBtn");
        const mobileDrawer = document.getElementById("mobileDrawer");
        const menuOverlay = document.getElementById("menuOverlay");
        
        if (mobileMenuBtn && closeDrawerBtn && mobileDrawer && menuOverlay) {
            mobileMenuBtn.addEventListener("click", () => {
                mobileDrawer.classList.add("open");
                menuOverlay.classList.add("show");
            });
            
            closeDrawerBtn.addEventListener("click", () => {
                mobileDrawer.classList.remove("open");
                menuOverlay.classList.remove("show");
            });
            
            menuOverlay.addEventListener("click", () => {
                mobileDrawer.classList.remove("open");
                menuOverlay.classList.remove("show");
            });
        }

        // Sign Out action
        const signOut = (e) => {
            e.preventDefault();
            auth.signOut().then(() => {
                window.location.href = 'login.html';
            });
        };
        const soBtn = document.getElementById("signOutBtn");
        const soBtnMob = document.getElementById("signOutBtnMobile");
        if (soBtn) soBtn.addEventListener("click", signOut);
        if (soBtnMob) soBtnMob.addEventListener("click", signOut);

        // "More" bottom nav opens the drawer
        const moreBtn = document.getElementById("mobileMoreBtn");
        if (moreBtn && mobileDrawer && menuOverlay) {
            moreBtn.addEventListener("click", (e) => {
                e.preventDefault();
                mobileDrawer.classList.add("open");
                menuOverlay.classList.add("show");
            });
        }

        // Theme Toggle Action (Mobile + PC)
        const toggleTheme = () => {
            const currentTheme = document.documentElement.getAttribute("data-theme") || "dark";
            const newTheme = currentTheme === "light" ? "dark" : "light";
            document.documentElement.setAttribute("data-theme", newTheme);
            localStorage.setItem("theme", newTheme);
            syncThemeDisplay();
        };

        const themeBtn = document.getElementById("themeToggleBtn");
        const themeBtnPC = document.getElementById("themeToggleBtnPC");
        if (themeBtn) themeBtn.addEventListener("click", toggleTheme);
        if (themeBtnPC) {
            themeBtnPC.addEventListener("click", (e) => {
                e.preventDefault();
                toggleTheme();
            });
        }

        // Sidebar Collapse Action
        const sidebar = document.getElementById("appSidebar");
        const collapseBtn = document.getElementById("sidebarCollapseBtn");
        const collapseIcon = document.getElementById("collapseIcon");
        
        if (sidebar && collapseBtn && collapseIcon) {
            // Check previous saved state
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
        }
    }

    function syncThemeDisplay() {
        const currentTheme = localStorage.getItem("theme") || "dark";
        document.documentElement.setAttribute("data-theme", currentTheme);
        
        const icon = document.getElementById("themeIcon");
        const iconPC = document.getElementById("themeIconPC");
        
        if (icon) {
            icon.className = currentTheme === "light" ? "fa-solid fa-moon" : "fa-solid fa-sun";
        }
        if (iconPC) {
            iconPC.className = currentTheme === "light" ? "fa-solid fa-moon" : "fa-solid fa-sun";
            iconPC.style.color = currentTheme === "light" ? "#6366f1" : "#eab308";
        }
    }

    function loadNavBranding() {
        db.collection('system_settings').doc('branding').get().then((doc) => {
            if (doc.exists) {
                const data = doc.data();
                if (data.site_name) {
                    const sbLogo = document.getElementById("sidebarLogoText");
                    const mbTitle = document.getElementById("mobileNavbarTitle");
                    if (sbLogo) sbLogo.textContent = data.site_name;
                    if (mbTitle) mbTitle.textContent = data.site_name;
                }
            }
        }).catch(err => console.log("Using default nav branding"));
    }

    // Suppress generic browser alert warnings for DataTables globally and log to console instead
    if (window.jQuery) {
        $(document).ready(function() {
            if ($.fn.dataTable) {
                $.fn.dataTable.ext.errMode = 'none'; // Silent in console, no browser alert()
            }
        });
    }
})();
