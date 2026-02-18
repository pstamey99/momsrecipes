// Authentication System for Mom's Recipes
// Uses localStorage for user management
// Approved users loaded from approved_users.json

// APPROVED USERS - loaded dynamically from server file
let APPROVED_USERS = [];

// Load approved users list, then check auth
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const resp = await fetch('approved_users.json');
        if (resp.ok) {
            const data = await resp.json();
            APPROVED_USERS = (data.users || []).map(u => u.toLowerCase());
            console.log(`Loaded ${APPROVED_USERS.length} approved users`);
        } else {
            console.warn('Could not load approved_users.json, using fallback');
            APPROVED_USERS = ['paul', 'sarah', 'margaret', 'john', 'admin'];
        }
    } catch (e) {
        console.warn('Error loading approved users, using fallback:', e);
        APPROVED_USERS = ['paul', 'sarah', 'margaret', 'john', 'admin'];
    }
    checkAuth();
});

// Check if username is approved
function isApprovedUser(username) {
    return APPROVED_USERS.includes(username.toLowerCase());
}

// Switch between login and register tabs
function switchTab(tab) {
    const loginTab = document.querySelector('.auth-tab:first-child');
    const registerTab = document.querySelector('.auth-tab:last-child');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const resetForm = document.getElementById('reset-form');
    
    // Always hide reset form when switching tabs
    if (resetForm) {
        resetForm.classList.remove('active');
    }
    
    if (tab === 'login') {
        loginTab.classList.add('active');
        registerTab.classList.remove('active');
        loginForm.classList.add('active');
        registerForm.classList.remove('active');
        clearMessages();
    } else {
        loginTab.classList.remove('active');
        registerTab.classList.add('active');
        loginForm.classList.remove('active');
        registerForm.classList.add('active');
        clearMessages();
    }
}

// Show password reset form
function showPasswordReset() {
    const loginTab = document.querySelector('.auth-tab:first-child');
    const registerTab = document.querySelector('.auth-tab:last-child');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const resetForm = document.getElementById('reset-form');
    
    // Hide tabs
    if (loginTab) loginTab.style.display = 'none';
    if (registerTab) registerTab.style.display = 'none';
    
    // Hide forms, show only reset
    loginForm.classList.remove('active');
    registerForm.classList.remove('active');
    resetForm.classList.add('active');
    clearMessages();
}

// Show login form
function showLoginForm() {
    const loginTab = document.querySelector('.auth-tab:first-child');
    const registerTab = document.querySelector('.auth-tab:last-child');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const resetForm = document.getElementById('reset-form');
    
    // Show tabs again
    if (loginTab) loginTab.style.display = '';
    if (registerTab) registerTab.style.display = '';
    
    // Show login form
    loginForm.classList.add('active');
    registerForm.classList.remove('active');
    resetForm.classList.remove('active');
    
    // Make sure login tab is active
    if (loginTab) loginTab.classList.add('active');
    if (registerTab) registerTab.classList.remove('active');
    
    clearMessages();
}

// Clear all messages
function clearMessages() {
    document.getElementById('login-message').style.display = 'none';
    document.getElementById('register-message').style.display = 'none';
    const resetMsg = document.getElementById('reset-message');
    if (resetMsg) resetMsg.style.display = 'none';
}

// Handle password reset
function handlePasswordReset(event) {
    event.preventDefault();
    
    const username = document.getElementById('reset-username').value.toLowerCase().trim();
    const fullname = document.getElementById('reset-fullname').value.trim();
    const newPassword = document.getElementById('reset-new-password').value;
    const confirmPassword = document.getElementById('reset-confirm-password').value;
    
    // Check if user is approved
    if (!isApprovedUser(username)) {
        showMessage('reset-message', 'This username is not approved for registration.', 'error');
        return;
    }
    
    // Check if passwords match
    if (newPassword !== confirmPassword) {
        showMessage('reset-message', 'Passwords do not match.', 'error');
        return;
    }
    
    // Get all users
    const users = JSON.parse(localStorage.getItem('momsrecipes_users') || '{}');
    
    // Check if user exists
    if (!users[username]) {
        showMessage('reset-message', 'User not found. Please register instead.', 'error');
        return;
    }
    
    // Verify full name matches
    if (users[username].fullname.toLowerCase() !== fullname.toLowerCase()) {
        showMessage('reset-message', 'Full name does not match our records.', 'error');
        return;
    }
    
    // Update password
    users[username].password = hashPassword(newPassword);
    localStorage.setItem('momsrecipes_users', JSON.stringify(users));
    
    showMessage('reset-message', 'Password reset successful! You can now login.', 'success');
    
    // Clear form
    document.getElementById('reset-form').reset();
    
    // Show login form after 2 seconds
    setTimeout(() => {
        showLoginForm();
        
        // Give a moment for form to show
        setTimeout(() => {
            // Clear any messages
            clearMessages();
            
            // Pre-fill username only
            document.getElementById('login-username').value = username;
            
            // Clear password field explicitly
            document.getElementById('login-password').value = '';
            
            // Focus on password field
            document.getElementById('login-password').focus();
        }, 100);
    }, 2000);
}

// Show message
function showMessage(elementId, text, type) {
    const el = document.getElementById(elementId);
    el.textContent = text;
    el.className = `auth-message ${type}`;
    el.style.display = 'block';
}

// Hash password (simple client-side hashing)
function hashPassword(password) {
    // Simple hash for demo - in production use proper crypto
    let hash = 0;
    for (let i = 0; i < password.length; i++) {
        const char = password.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash; // Convert to 32bit integer
    }
    return hash.toString(36);
}

// Handle registration
function handleRegister(event) {
    event.preventDefault();
    
    const username = document.getElementById('register-username').value.trim();
    const password = document.getElementById('register-password').value;
    const confirm = document.getElementById('register-confirm').value;
    const fullname = document.getElementById('register-fullname').value.trim();
    
    // Check if user is approved
    if (!isApprovedUser(username)) {
        showMessage('register-message', 
            'Registration denied. Username not on approved family list. Contact administrator.', 
            'error');
        return;
    }
    
    // Validate
    if (username.length < 3) {
        showMessage('register-message', 'Username must be at least 3 characters', 'error');
        return;
    }
    
    if (password.length < 6) {
        showMessage('register-message', 'Password must be at least 6 characters', 'error');
        return;
    }
    
    if (password !== confirm) {
        showMessage('register-message', 'Passwords do not match', 'error');
        return;
    }
    
    // Check if username already exists
    const users = JSON.parse(localStorage.getItem('momsrecipes_users') || '{}');
    
    if (users[username]) {
        showMessage('register-message', 'Username already registered', 'error');
        return;
    }
    
    // Create new user
    users[username] = {
        password: hashPassword(password),
        fullname: fullname,
        registered: new Date().toISOString(),
        lastLogin: null,
        approved: true
    };
    
    // Save users
    localStorage.setItem('momsrecipes_users', JSON.stringify(users));
    
    // Show success and switch to login
    showMessage('register-message', 'Registration successful! Please login.', 'success');
    
    // Clear form
    document.getElementById('register-form').reset();
    
    // Auto-fill login form
    document.getElementById('login-username').value = username;
    
    // Switch to login tab after 1.5 seconds
    setTimeout(() => {
        switchTab('login');
    }, 1500);
}

// Handle login
function handleLogin(event) {
    event.preventDefault();
    
    const username = document.getElementById('login-username').value.trim();
    const password = document.getElementById('login-password').value;
    
    // Check if user is approved
    if (!isApprovedUser(username)) {
        showMessage('login-message', 
            'Access denied. Not an approved family member.', 
            'error');
        return;
    }
    
    // Get users
    const users = JSON.parse(localStorage.getItem('momsrecipes_users') || '{}');
    
    // Check credentials
    if (!users[username]) {
        showMessage('login-message', 'Invalid username or password', 'error');
        return;
    }
    
    if (users[username].password !== hashPassword(password)) {
        showMessage('login-message', 'Invalid username or password', 'error');
        return;
    }
    
    // Double-check user is approved (in case list changed)
    if (!isApprovedUser(username)) {
        showMessage('login-message', 
            'Access denied. User removed from approved list.', 
            'error');
        return;
    }
    
    // Update last login
    users[username].lastLogin = new Date().toISOString();
    localStorage.setItem('momsrecipes_users', JSON.stringify(users));
    
    // Set current user session
    localStorage.setItem('momsrecipes_current_user', JSON.stringify({
        username: username,
        fullname: users[username].fullname,
        loginTime: new Date().toISOString()
    }));
    
    // Clear form
    document.getElementById('login-form').reset();
    
    // Show success briefly
    showMessage('login-message', 'Login successful!', 'success');
    
    // Load main content
    setTimeout(() => {
        showMainContent();
    }, 500);
}

// Handle logout
function handleLogout() {
    if (confirm('Are you sure you want to logout?')) {
        localStorage.removeItem('momsrecipes_current_user');
        
        // Hide main content
        document.getElementById('main-content').classList.remove('visible');
        document.getElementById('user-info').classList.remove('visible');
        
        // Show auth overlay
        document.getElementById('auth-overlay').classList.remove('hidden');
        
        // Clear forms
        document.getElementById('login-form').reset();
        clearMessages();
    }
}

// Check authentication status
function checkAuth() {
    const currentUser = localStorage.getItem('momsrecipes_current_user');
    
    if (currentUser) {
        const user = JSON.parse(currentUser);
        
        // Verify user is still approved
        if (!isApprovedUser(user.username)) {
            // User removed from approved list
            localStorage.removeItem('momsrecipes_current_user');
            showMessage('login-message', 
                'Your access has been revoked. Contact administrator.', 
                'error');
            document.getElementById('auth-overlay').classList.remove('hidden');
            return;
        }
        
        // User is logged in and approved
        showMainContent();
    } else {
        // Show login screen
        document.getElementById('auth-overlay').classList.remove('hidden');
    }
}

// Show main content (after successful login)
function showMainContent() {
    const currentUser = JSON.parse(localStorage.getItem('momsrecipes_current_user'));
    
    if (!currentUser) {
        // No user logged in
        document.getElementById('auth-overlay').classList.remove('hidden');
        return;
    }
    
    // Verify approved status
    if (!isApprovedUser(currentUser.username)) {
        localStorage.removeItem('momsrecipes_current_user');
        document.getElementById('auth-overlay').classList.remove('hidden');
        return;
    }
    
    // Hide auth overlay
    document.getElementById('auth-overlay').classList.add('hidden');
    
    // Show main content
    document.getElementById('main-content').classList.add('visible');
    
    // Show user info
    document.getElementById('current-username').textContent = currentUser.fullname;
    document.getElementById('user-info').classList.add('visible');
}

// Get current logged-in username (for edit tracking)
function getCurrentUsername() {
    const currentUser = localStorage.getItem('momsrecipes_current_user');
    if (currentUser) {
        const user = JSON.parse(currentUser);
        return user.username;
    }
    return 'Anonymous';
}

// View approved users list (for admin debugging)
function viewApprovedUsers() {
    console.log('Approved Users:', APPROVED_USERS);
    console.log('Total:', APPROVED_USERS.length, 'approved users');
}

// Export for use in edit tracking
window.getCurrentUsername = getCurrentUsername;
window.viewApprovedUsers = viewApprovedUsers;
