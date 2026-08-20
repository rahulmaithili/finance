// Navigation Loader & Auth State Guard
// Dynamically renders Sidebar, Mobile Topbar, and Mobile Bottom Nav.
// Manages User profile initials, roles, and dropdowns.

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
