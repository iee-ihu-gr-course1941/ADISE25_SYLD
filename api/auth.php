<?php
// api/auth.php
require_once '../db_config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'check_username':
        checkUsername();
        break;
        
    case 'check_email':
        checkEmail();
        break;
        
    case 'login':
        loginUser();
        break;
        
    case 'signup':
        signupUser();
        break;
        
    case 'guest_login':
        guestLogin();
        break;
        
    case 'forgot_password':
        forgotPassword();
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
}

function checkUsername() {
    global $db;
    
    $username = trim($_POST['username'] ?? '');
    
    if (strlen($username) < 3) {
        echo json_encode(['available' => false]);
        return;
    }
    
    $sql = "SELECT id FROM users WHERE username = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    
    echo json_encode([
        'available' => $stmt->num_rows === 0
    ]);
}

function checkEmail() {
    global $db;
    
    $email = trim($_POST['email'] ?? '');
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['available' => false]);
        return;
    }
    
    // Check for gmail.com (as requested)
    if (!preg_match('/@gmail\.com$/i', $email)) {
        echo json_encode(['available' => false, 'message' => 'Χρησιμοποιήστε Gmail']);
        return;
    }
    
    $sql = "SELECT id FROM users WHERE email = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    
    echo json_encode([
        'available' => $stmt->num_rows === 0
    ]);
}

function loginUser() {
    global $db;
    
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($identifier) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Συμπληρώστε όλα τα πεδία']);
        return;
    }
    
    // Check if identifier is email or username
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        $sql = "SELECT id, username, password_hash, is_guest FROM users WHERE email = ?";
    } else {
        $sql = "SELECT id, username, password_hash, is_guest FROM users WHERE username = ?";
    }
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if it's a guest account (no password)
        if ($row['is_guest'] == 1) {
            echo json_encode(['success' => false, 'message' => 'Ο λογαριασμός guest δεν μπορεί να συνδεθεί']);
            return;
        }
        
        // Verify password
        if (password_verify($password, $row['password_hash'])) {
            // Start session
            session_start();
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['is_guest'] = false;
            
            // Update last login
            $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
            $update_stmt = $db->prepare($update_sql);
            $update_stmt->bind_param("i", $row['id']);
            $update_stmt->execute();
            
            echo json_encode([
                'success' => true,
                'user_id' => $row['id'],
                'username' => $row['username']
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Λάθος κωδικός']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Ο χρήστης δεν βρέθηκε']);
    }
}

function signupUser() {
    global $db;
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $avatar = $_POST['avatar'] ?? 'default';
    
    // Validation
    if (strlen($username) < 3) {
        echo json_encode(['success' => false, 'message' => 'Το username πρέπει να έχει τουλάχιστον 3 χαρακτήρες']);
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Μη έγκυρη διεύθυνση email']);
        return;
    }
    
    if (!preg_match('/@gmail\.com$/i', $email)) {
        echo json_encode(['success' => false, 'message' => 'Χρησιμοποιήστε μόνο Gmail (@gmail.com)']);
        return;
    }
    
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες']);
        return;
    }
    
    // Check if username exists
    $check_sql = "SELECT id FROM users WHERE username = ? OR email = ?";
    $check_stmt = $db->prepare($check_sql);
    $check_stmt->bind_param("ss", $username, $email);
    $check_stmt->execute();
    $check_stmt->store_result();
    
    if ($check_stmt->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Το username ή email υπάρχει ήδη']);
        return;
    }
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert user
    $sql = "INSERT INTO users (username, email, password_hash, is_guest, created_at) 
            VALUES (?, ?, ?, 0, NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sss", $username, $email, $password_hash);
    
    if ($stmt->execute()) {
        $user_id = $db->insert_id;
        
        // Start session
        session_start();
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['is_guest'] = false;
        
        echo json_encode([
            'success' => true,
            'user_id' => $user_id,
            'username' => $username,
            'message' => 'Επιτυχής εγγραφή!'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Σφάλμα κατά την εγγραφή']);
    }
}

function guestLogin() {
    global $db;
    
    $guest_id = $_POST['guest_id'] ?? 'guest_' . rand(1000, 9999);
    
    // Create guest user
    $sql = "INSERT INTO users (username, is_guest, created_at) 
            VALUES (?, 1, NOW()) 
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $guest_id);
    
    if ($stmt->execute()) {
        $user_id = $db->insert_id;
        
        // Start session
        session_start();
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $guest_id;
        $_SESSION['is_guest'] = true;
        
        echo json_encode([
            'success' => true,
            'user_id' => $user_id,
            'username' => $guest_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Σφάλμα δημιουργίας guest']);
    }
}

function forgotPassword() {
    // Simplified version - just send a message
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Εισάγετε email']);
        return;
    }
    
    // In a real app, you would:
    // 1. Generate a reset token
    // 2. Save it to database
    // 3. Send email with reset link
    
    echo json_encode([
        'success' => true,
        'message' => 'Οδηγίες επαναφοράς στάλθηκαν στο email σας (όχι υλοποιημένο στο demo)'
    ]);
}
?>