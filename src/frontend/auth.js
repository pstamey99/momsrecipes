// Authentication System for Mom's Recipes
// Auth handled by backend API — only session token in localStorage

const AUTH_API = 'api/index.php';

const IS_LOCAL = location.hostname === 'localhost' || location.hostname === '127.0.0.1';

document.addEventListener('DOMContentLoaded', function() { checkAuth(); });

function switchTab(tab) {
    const loginTab = document.querySelector('.auth-tab:first-child');
    const registerTab = document.querySelector('.auth-tab:last-child');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const resetForm = document.getElementById('reset-form');
    if (resetForm) resetForm.classList.remove('active');
    if (tab === 'login') {
        loginTab.classList.add('active'); registerTab.classList.remove('active');
        loginForm.classList.add('active'); registerForm.classList.remove('active');
    } else {
        loginTab.classList.remove('active'); registerTab.classList.add('active');
        loginForm.classList.remove('active'); registerForm.classList.add('active');
    }
    clearMessages();
}

function showPasswordReset() {
    const loginTab = document.querySelector('.auth-tab:first-child');
    const registerTab = document.querySelector('.auth-tab:last-child');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const resetForm = document.getElementById('reset-form');
    if (loginTab) loginTab.style.display = 'none';
    if (registerTab) registerTab.style.display = 'none';
    if (loginForm) loginForm.classList.remove('active');
    if (registerForm) registerForm.classList.remove('active');
    if (resetForm) resetForm.classList.add('active');
    clearMessages();
}

function showLoginForm() {
    const loginTab = document.querySelector('.auth-tab:first-child');
    const registerTab = document.querySelector('.auth-tab:last-child');
    const resetForm = document.getElementById('reset-form');
    if (loginTab) loginTab.style.display = '';
    if (registerTab) registerTab.style.display = '';
    if (resetForm) resetForm.classList.remove('active');
    switchTab('login');
}

function clearMessages() {
    document.querySelectorAll('.auth-message').forEach(el => { el.style.display = 'none'; el.textContent = ''; });
}

function showMessage(elementId, text, type) {
    const el = document.getElementById(elementId);
    el.textContent = text;
    el.className = 'auth-message ' + type;
    el.style.display = 'block';
}

// Register via API
async function handleRegister(event) {
    event.preventDefault();
    const username = document.getElementById('register-username').value.trim().toLowerCase();
    const password = document.getElementById('register-password').value;
    const confirm  = document.getElementById('register-confirm').value;
    const fullname = document.getElementById('register-fullname').value.trim();

    if (username.length < 3) { showMessage('register-message', 'Username must be at least 3 characters', 'error'); return; }
    if (password.length < 6) { showMessage('register-message', 'Password must be at least 6 characters', 'error'); return; }
    if (password !== confirm) { showMessage('register-message', 'Passwords do not match', 'error'); return; }

    try {
        const r = await fetch(AUTH_API + '?action=register', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ username, password, fullname })
        });
        const data = await r.json();
        if (data.success) {
            showMessage('register-message', 'Registration successful! Please login.', 'success');
            document.getElementById('register-form').reset();
            document.getElementById('login-username').value = username;
            setTimeout(function() { switchTab('login'); }, 1500);
        } else {
            showMessage('register-message', data.error || 'Registration failed', 'error');
        }
    } catch(e) { showMessage('register-message', 'Server error. Please try again.', 'error'); }
}

// Login via API
async function handleLogin(event) {
    event.preventDefault();
    const username = document.getElementById('login-username').value.trim().toLowerCase();
    const password = document.getElementById('login-password').value;

    try {
        const r = await fetch(AUTH_API + '?action=login', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ username, password })
        });
        const data = await r.json();
        if (data.success) {
            localStorage.setItem('momsrecipes_current_user', JSON.stringify({
                username:  data.username || username,
                fullname:  data.fullname || username,
                is_admin:  data.is_admin || 0,
                loginTime: new Date().toISOString()
            }));
            document.getElementById('login-form').reset();
            showMessage('login-message', 'Login successful!', 'success');
            setTimeout(function() { showMainContent(); }, 500);
        } else {
            showMessage('login-message', data.error || 'Invalid username or password', 'error');
        }
    } catch(e) { showMessage('login-message', 'Server error. Please try again.', 'error'); }
}

// Password Reset via API
async function handlePasswordReset(event) {
    event.preventDefault();
    const username = document.getElementById('reset-username').value.toLowerCase().trim();
    const fullname = document.getElementById('reset-fullname').value.trim();
    const newPassword = document.getElementById('reset-new-password').value;
    const confirmPassword = document.getElementById('reset-confirm-password').value;

    if (newPassword !== confirmPassword) { showMessage('reset-message', 'Passwords do not match.', 'error'); return; }

    try {
        const r = await fetch(AUTH_API + '?action=reset_password', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ username, fullname, new_password: newPassword })
        });
        const data = await r.json();
        if (data.success) {
            showMessage('reset-message', 'Password reset successful!', 'success');
            document.getElementById('reset-form').reset();
            setTimeout(function() {
                showLoginForm();
                setTimeout(function() {
                    clearMessages();
                    document.getElementById('login-username').value = username;
                    document.getElementById('login-password').value = '';
                    document.getElementById('login-password').focus();
                }, 100);
            }, 2000);
        } else {
            showMessage('reset-message', data.error || 'Reset failed', 'error');
        }
    } catch(e) { showMessage('reset-message', 'Server error. Please try again.', 'error'); }
}

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) {
        localStorage.removeItem('momsrecipes_current_user');
        document.getElementById('main-content').classList.remove('visible');
        document.getElementById('user-info').classList.remove('visible');
        document.getElementById('auth-overlay').classList.remove('hidden');
        document.getElementById('login-form').reset();
        clearMessages();
    }
}

function checkAuth() {
    // Auto-bypass auth when running locally
    if (IS_LOCAL) {
        localStorage.setItem('momsrecipes_current_user', JSON.stringify({
            username: 'paul',
            fullname: 'Paul Stamey',
            loginTime: new Date().toISOString()
        }));
        showMainContent();
        return;
    }
    const s = localStorage.getItem('momsrecipes_current_user');
    if (s) {
        showMainContent();
    } else {
        document.getElementById('auth-overlay').classList.remove('hidden');
    }
}

function showMainContent() {
    const s = localStorage.getItem('momsrecipes_current_user');
    if (!s) { document.getElementById('auth-overlay').classList.remove('hidden'); return; }
    const user = JSON.parse(s);
    document.getElementById('auth-overlay').classList.add('hidden');
    document.getElementById('main-content').classList.add('visible');
    document.getElementById('current-username').textContent = user.fullname;
    document.getElementById('user-info').classList.add('visible');
    // Show Admin button — label differs for super admin vs regular admin
    const adminBtn = document.getElementById('btn-admin');
    if (adminBtn) {
        const isSuperAdmin = (user.username === 'paul' || user.username === 'pstamey');
        const isAdmin      = isSuperAdmin || !!user.is_admin;
        if (isAdmin) {
            adminBtn.textContent = isSuperAdmin ? '⭐ Super Admin' : '🛡️ Admin';
            adminBtn.style.background = isSuperAdmin ? '#8a6d3b' : '#5a3e8a';
            adminBtn.style.display = 'inline-block';
        } else {
            adminBtn.style.display = 'none';
        }
    }
}

function getCurrentUsername() {
    const s = localStorage.getItem('momsrecipes_current_user');
    if (s) { try { return JSON.parse(s).username; } catch(e) {} }
    return 'Anonymous';
}

window.getCurrentUsername = getCurrentUsername;
