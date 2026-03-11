/**
 * FoxDesk - Header JS (sidebar, dropdowns, theme)
 * Extracted from includes/header.php for defer loading
 */

/* =============================
   Sidebar Management
   ============================= */

function setSidebarOpen(isOpen) {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    if (!sidebar || !overlay) return;

    sidebar.classList.toggle('open', isOpen);
    overlay.classList.toggle('open', isOpen);
    document.body.classList.toggle('overflow-hidden', isOpen);

    // Update aria-expanded on mobile menu button
    var mobileMenuBtn = document.getElementById('mobile-menu-btn');
    if (mobileMenuBtn) mobileMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    try {
        localStorage.setItem('sidebar_open', isOpen ? '1' : '0');
    } catch (e) {}
}

function toggleSidebar(forceState) {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    var shouldOpen = typeof forceState === 'boolean' ? forceState : !sidebar.classList.contains('open');
    setSidebarOpen(shouldOpen);
}

function applySidebarPreference() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (window.innerWidth >= 1024) {
        setSidebarOpen(false);
        return;
    }
    try {
        var saved = localStorage.getItem('sidebar_open');
        if (saved === '1') {
            setSidebarOpen(true);
        }
    } catch (e) {}
}

// Close sidebar when clicking a link (mobile)
document.querySelectorAll('#sidebar a').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth < 1024) {
            setSidebarOpen(false);
        }
    });
});

function applySidebarSectionPreference() {
    var sections = document.querySelectorAll('.sidebar-section');
    sections.forEach(function(section, index) {
        var key = 'sidebar_section_' + (section.dataset.section || index);
        try {
            var saved = localStorage.getItem(key);
            if (saved === '0') {
                section.removeAttribute('open');
            } else if (saved === '1') {
                section.setAttribute('open', 'open');
            }
        } catch (e) {}

        section.addEventListener('toggle', function() {
            try {
                localStorage.setItem(key, section.open ? '1' : '0');
            } catch (e) {}
        });
    });
}

window.addEventListener('resize', applySidebarPreference);
applySidebarPreference();
applySidebarSectionPreference();

/* =============================
   User Menu / Dropdowns
   ============================= */

function toggleSidebarUserMenu() {
    var menu = document.getElementById('sidebar-user-menu');
    var btn = document.getElementById('sidebar-user-btn');
    var chevron = document.querySelector('.sidebar-user-chevron');
    if (menu) {
        menu.classList.toggle('hidden');
        var isOpen = !menu.classList.contains('hidden');
        if (chevron) {
            chevron.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
        }
        if (btn) btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}

function toggleUserDropdownMobile() {
    var dropdown = document.getElementById('user-dropdown-mobile');
    var btn = document.getElementById('mobile-user-btn');
    if (dropdown) {
        dropdown.classList.toggle('hidden');
        var isOpen = !dropdown.classList.contains('hidden');
        if (btn) btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    var userDropdownMobile = document.getElementById('user-dropdown-mobile');
    var sidebarUserMenu = document.getElementById('sidebar-user-menu');
    var sidebarUserBtn = document.getElementById('sidebar-user-btn');

    // Close mobile dropdown
    if (userDropdownMobile && !event.target.closest('#user-dropdown-mobile') && !event.target.closest('#mobile-user-btn')) {
        userDropdownMobile.classList.add('hidden');
        var mobileBtn = document.getElementById('mobile-user-btn');
        if (mobileBtn) mobileBtn.setAttribute('aria-expanded', 'false');
    }

    // Close sidebar user menu
    if (sidebarUserMenu && sidebarUserBtn && !sidebarUserBtn.contains(event.target) && !sidebarUserMenu.contains(event.target)) {
        sidebarUserMenu.classList.add('hidden');
        sidebarUserBtn.setAttribute('aria-expanded', 'false');
        var chevron = document.querySelector('.sidebar-user-chevron');
        if (chevron) chevron.style.transform = 'rotate(0deg)';
    }
});

/* =============================
   Theme Toggle
   ============================= */

function toggleTheme() {
    var html = document.documentElement;
    var current = html.getAttribute('data-theme');
    var next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    updateThemeIcons(next);
    
    var fpThemeLink = document.getElementById('flatpickr-theme-css');
    if (fpThemeLink) {
        fpThemeLink.href = next === 'dark' 
            ? 'https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css' 
            : 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css';
    }
}

function updateThemeIcons(theme) {
    var isDark = theme === 'dark';
    document.querySelectorAll('.theme-icon-light').forEach(function(el) { el.classList.toggle('hidden', isDark); });
    document.querySelectorAll('.theme-icon-dark').forEach(function(el) { el.classList.toggle('hidden', !isDark); });
    document.querySelectorAll('.theme-text-light').forEach(function(el) { el.classList.toggle('hidden', isDark); });
    document.querySelectorAll('.theme-text-dark').forEach(function(el) { el.classList.toggle('hidden', !isDark); });
}

// Initialize theme icons on page load
updateThemeIcons(document.documentElement.getAttribute('data-theme') || 'light');
