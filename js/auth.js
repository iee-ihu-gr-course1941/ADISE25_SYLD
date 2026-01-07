// js/auth.js
$(document).ready(function() {
    console.log("Auth module loaded");
    
    // Password visibility toggle
    $('#toggle-login-password').click(togglePasswordVisibility);
    $('#toggle-signup-password').click(togglePasswordVisibility);
    
    // Real-time validation
    $('#signup-username').on('input', checkUsernameAvailability);
    $('#signup-email').on('input', validateEmail);
    $('#signup-password, #signup-confirm').on('input', validatePasswordMatch);
    
    // Form submissions
    $('#login-form').submit(handleLogin);
    $('#signup-form').submit(handleSignup);
    $('#play-as-guest').click(playAsGuest);
    $('#forgot-password').click(showForgotPassword);
    
    // Initialize
    initAuth();
});

function initAuth() {
    // Check if there's a saved session
    if (localStorage.getItem('xeri_remember') === 'true') {
        $('#remember-me').prop('checked', true);
        const savedEmail = localStorage.getItem('xeri_email');
        if (savedEmail) {
            $('#login-identifier').val(savedEmail);
        }
    }
}

function togglePasswordVisibility(e) {
    const $button = $(e.target).closest('button');
    const $input = $button.siblings('input[type="password"]');
    const $icon = $button.find('i');
    
    if ($input.attr('type') === 'password') {
        $input.attr('type', 'text');
        $icon.removeClass('fa-eye').addClass('fa-eye-slash');
    } else {
        $input.attr('type', 'password');
        $icon.removeClass('fa-eye-slash').addClass('fa-eye');
    }
}

function checkUsernameAvailability() {
    const username = $('#signup-username').val().trim();
    const $feedback = $('#username-feedback');
    
    if (username.length < 3) {
        $feedback.html('<i class="fas fa-times-circle invalid-feedback"></i> Τουλάχιστον 3 χαρακτήρες');
        return;
    }
    
    if (!/^[a-zA-Z0-9_]+$/.test(username)) {
        $feedback.html('<i class="fas fa-times-circle invalid-feedback"></i> Μόνο λατινικά, αριθμοί και _');
        return;
    }
    
    // AJAX check
    $.ajax({
        url: 'api/auth.php',
        method: 'POST',
        data: {
            action: 'check_username',
            username: username
        },
        success: function(response) {
            if (response.available) {
                $feedback.html('<i class="fas fa-check-circle valid-feedback"></i> Το username είναι διαθέσιμο');
                $('#signup-username').addClass('is-valid').removeClass('is-invalid');
            } else {
                $feedback.html('<i class="fas fa-times-circle invalid-feedback"></i> Το username υπάρχει ήδη');
                $('#signup-username').addClass('is-invalid').removeClass('is-valid');
            }
        }
    });
}

function validateEmail() {
    const email = $('#signup-email').val().trim();
    const $feedback = $('#email-feedback');
    
    // Basic email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailRegex.test(email)) {
        $feedback.html('<i class="fas fa-times-circle invalid-feedback"></i> Μη έγκυρη διεύθυνση email');
        $('#signup-email').addClass('is-invalid').removeClass('is-valid');
        return false;
    }
    
    // Check for gmail.com
    if (!email.toLowerCase().endsWith('@gmail.com')) {
        $feedback.html('<i class="fas fa-exclamation-triangle text-warning"></i> Χρησιμοποιήστε Gmail (@gmail.com)');
        $('#signup-email').removeClass('is-invalid is-valid');
        return false;
    }
    
    // AJAX check for existing email
    $.ajax({
        url: 'api/auth.php',
        method: 'POST',
        data: {
            action: 'check_email',
            email: email
        },
        success: function(response) {
            if (response.available) {
                $feedback.html('<i class="fas fa-check-circle valid-feedback"></i> Το email είναι διαθέσιμο');
                $('#signup-email').addClass('is-valid').removeClass('is-invalid');
            } else {
                $feedback.html('<i class="fas fa-times-circle invalid-feedback"></i> Το email υπάρχει ήδη');
                $('#signup-email').addClass('is-invalid').removeClass('is-valid');
            }
        }
    });
    
    return true;
}

function validatePasswordMatch() {
    const password = $('#signup-password').val();
    const confirm = $('#signup-confirm').val();
    const $feedback = $('#password-match-feedback');
    
    if (password.length < 6) {
        $feedback.html('<i class="fas fa-times-circle invalid-feedback"></i> Τουλάχιστον 6 χαρακτήρες');
        $('#signup-password').addClass('is-invalid').removeClass('is-valid');
        return false;
    }
    
    // Password strength
    updatePasswordStrength(password);
    
    if (confirm.length === 0) {
        $feedback.html('');
        return false;
    }
    
    if (password === confirm) {
        $feedback.html('<i class="fas fa-check-circle valid-feedback"></i> Οι κωδικοί ταιριάζουν');
        $('#signup-password, #signup-confirm').addClass('is-valid').removeClass('is-invalid');
        return true;
    } else {
        $feedback.html('<i class="fas fa-times-circle invalid-feedback"></i> Οι κωδικοί δεν ταιριάζουν');
        $('#signup-password, #signup-confirm').addClass('is-invalid').removeClass('is-valid');
        return false;
    }
}

function updatePasswordStrength(password) {
    let strength = 0;
    const $meter = $('.password-strength');
    const $bar = $('.strength-bar');
    
    if (!$meter.length) {
        // Create strength meter if it doesn't exist
        $('#signup-password').after(`
            <div class="password-strength mt-2">
                <div class="strength-bar"></div>
            </div>
        `);
        $meter = $('.password-strength');
        $bar = $('.strength-bar');
    }
    
    // Criteria
    if (password.length >= 6) strength++;
    if (password.length >= 8) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^A-Za-z0-9]/.test(password)) strength++;
    
    // Update meter
    const classes = ['weak', 'fair', 'good', 'strong', 'strong'];
    const width = strength * 20;
    
    $bar.removeClass('weak fair good strong')
         .addClass(classes[strength - 1] || '')
         .css('width', width + '%');
}

function handleLogin(e) {
    e.preventDefault();
    
    const identifier = $('#login-identifier').val().trim();
    const password = $('#login-password').val();
    const rememberMe = $('#remember-me').is(':checked');
    
    // Clear previous messages
    showMessage('#login-message', '', 'd-none');
    
    // Basic validation
    if (!identifier || !password) {
        showMessage('#login-message', 'Συμπληρώστε όλα τα πεδία', 'alert-danger');
        return;
    }
    
    // Disable button and show loading
    const $button = $('#login-form button[type="submit"]');
    const originalText = $button.html();
    $button.html('<i class="fas fa-spinner fa-spin"></i> Σύνδεση...').prop('disabled', true);
    
    // AJAX request
    $.ajax({
        url: 'api/auth.php',
        method: 'POST',
        data: {
            action: 'login',
            identifier: identifier,
            password: password
        },
        success: function(response) {
            if (response.success) {
                // Save to localStorage if remember me
                if (rememberMe) {
                    localStorage.setItem('xeri_remember', 'true');
                    localStorage.setItem('xeri_email', identifier.includes('@') ? identifier : '');
                } else {
                    localStorage.removeItem('xeri_remember');
                    localStorage.removeItem('xeri_email');
                }
                
                // Save session
                sessionStorage.setItem('xeri_user_id', response.user_id);
                sessionStorage.setItem('xeri_username', response.username);
                
                // Show success and redirect
                showMessage('#login-message', 'Επιτυχής σύνδεση! Ανακατεύθυνση...', 'alert-success');
                
                setTimeout(() => {
                    window.location.href = 'game.php';
                }, 1500);
            } else {
                showMessage('#login-message', response.message || 'Λάθος στοιχεία', 'alert-danger');
                $button.html(originalText).prop('disabled', false);
            }
        },
        error: function() {
            showMessage('#login-message', 'Σφάλμα σύνδεσης με τον διακομιστή', 'alert-danger');
            $button.html(originalText).prop('disabled', false);
        }
    });
}

function handleSignup(e) {
    e.preventDefault();
    
    const username = $('#signup-username').val().trim();
    const email = $('#signup-email').val().trim();
    const password = $('#signup-password').val();
    const confirm = $('#signup-confirm').val();
    const avatar = $('#signup-avatar').val();
    
    // Clear previous messages
    showMessage('#signup-message', '', 'd-none');
    
    // Validation
    if (!username || !email || !password || !confirm) {
        showMessage('#signup-message', 'Συμπληρώστε όλα τα υποχρεωτικά πεδία', 'alert-danger');
        return;
    }
    
    if (!validateEmail()) {
        showMessage('#signup-message', 'Μη έγκυρη διεύθυνση email', 'alert-danger');
        return;
    }
    
    if (!validatePasswordMatch()) {
        showMessage('#signup-message', 'Οι κωδικοί δεν ταιριάζουν ή είναι πολύ μικροί', 'alert-danger');
        return;
    }
    
    if (!$('#terms').is(':checked')) {
        showMessage('#signup-message', 'Πρέπει να αποδεχτείτε τους όρους χρήσης', 'alert-danger');
        return;
    }
    
    // Disable button and show loading
    const $button = $('#signup-form button[type="submit"]');
    const originalText = $button.html();
    $button.html('<i class="fas fa-spinner fa-spin"></i> Δημιουργία...').prop('disabled', true);
    
    // AJAX request
    $.ajax({
        url: 'api/auth.php',
        method: 'POST',
        data: {
            action: 'signup',
            username: username,
            email: email,
            password: password,
            avatar: avatar
        },
        success: function(response) {
            if (response.success) {
                showMessage('#signup-message', 'Επιτυχής εγγραφή! Ανακατεύθυνση...', 'alert-success');
                
                // Auto-login
                sessionStorage.setItem('xeri_user_id', response.user_id);
                sessionStorage.setItem('xeri_username', username);
                
                setTimeout(() => {
                    window.location.href = 'game.php';
                }, 2000);
            } else {
                showMessage('#signup-message', response.message || 'Σφάλμα κατά την εγγραφή', 'alert-danger');
                $button.html(originalText).prop('disabled', false);
            }
        },
        error: function() {
            showMessage('#signup-message', 'Σφάλμα σύνδεσης με τον διακομιστή', 'alert-danger');
            $button.html(originalText).prop('disabled', false);
        }
    });
}

function playAsGuest() {
    // Generate random guest username
    const guestId = 'guest_' + Math.floor(Math.random() * 10000);
    
    $.ajax({
        url: 'api/auth.php',
        method: 'POST',
        data: {
            action: 'guest_login',
            guest_id: guestId
        },
        success: function(response) {
            if (response.success) {
                sessionStorage.setItem('xeri_user_id', response.user_id);
                sessionStorage.setItem('xeri_username', guestId);
                sessionStorage.setItem('xeri_is_guest', 'true');
                
                window.location.href = 'game.php';
            }
        }
    });
}

function showForgotPassword(e) {
    e.preventDefault();
    
    const email = prompt("Εισάγετε το email σας για επαναφορά κωδικού:");
    
    if (email && email.includes('@')) {
        $.ajax({
            url: 'api/auth.php',
            method: 'POST',
            data: {
                action: 'forgot_password',
                email: email
            },
            success: function(response) {
                alert(response.message || 'Ελέγξτε το email σας για οδηγίες επαναφοράς');
            }
        });
    }
}

function showMessage(selector, message, type) {
    const $element = $(selector);
    
    if (message) {
        $element.removeClass('d-none')
                .removeClass('alert-success alert-danger alert-warning')
                .addClass(type)
                .html(message);
    } else {
        $element.addClass('d-none').html('');
    }
}