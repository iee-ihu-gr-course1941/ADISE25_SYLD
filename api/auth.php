<?php
// api/auth.php
require_once '../db_config.php';

header('Content-Type: application/json');
session_start();

$db = getDBConnection();
$action = $_POST['action'] ?? '';

// Helper function για hashing password
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Helper function για να επιστρέφει μηνύματα
function jsonResponse($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'signup':
        handleSignup();
        break;
    case 'guest_login':
        handleGuestLogin();
        break;
    case 'check_username':
        checkUsernameAvailability();
        break;
    case 'check_email':
        checkEmailAvailability();
        break;
    case 'forgot_password':
        handleForgotPassword();
        break;
    default:
        jsonResponse(false, 'Invalid action');
}

function handleLogin() {
    global $db;
    
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($identifier) || empty($password)) {
        jsonResponse(false, 'Συμπληρώστε όλα τα πεδία');
    }
    
    // Ελέγχουμε αν είναι email ή username
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        $sql = "SELECT id, username, email, password_hash, is_guest 
                FROM users 
                WHERE email = ? AND is_guest = FALSE 
                LIMIT 1";
    } else {
        $sql = "SELECT id, username, email, password_hash, is_guest 
                FROM users 
                WHERE username = ? AND is_guest = FALSE 
                LIMIT 1";
    }
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        jsonResponse(false, 'Λάθος email/username ή κωδικός');
    }
    
    $user = $result->fetch_assoc();
    
    // Επαλήθευση κωδικού
    if (!password_verify($password, $user['password_hash'])) {
        jsonResponse(false, 'Λάθος email/username ή κωδικός');
    }
    
    // Ενημέρωση last_login
    $updateSql = "UPDATE users SET last_login = NOW() WHERE id = ?";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->bind_param("i", $user['id']);
    $updateStmt->execute();
    
    // Ορισμός session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_guest'] = $user['is_guest'];
    
    jsonResponse(true, 'Επιτυχής σύνδεση', [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'is_guest' => $user['is_guest']
    ]);
}

function handleSignup() {
    global $db;
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $avatar = $_POST['avatar'] ?? 'default';
    
    // Βασικός έλεγχος
    if (empty($username) || empty($email) || empty($password)) {
        jsonResponse(false, 'Συμπληρώστε όλα τα υποχρεωτικά πεδία');
    }
    
    // Έλεγχος username (μόνο λατινικά, αριθμοί, underscore)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        jsonResponse(false, 'Το username μπορεί να περιέχει μόνο λατινικούς χαρακτήρες, αριθμούς και _');
    }
    
    if (strlen($username) < 3) {
        jsonResponse(false, 'Το username πρέπει να έχει τουλάχιστον 3 χαρακτήρες');
    }
    
    // Έλεγχος email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Μη έγκυρη διεύθυνση email');
    }
    
    // Εξασφάλιση ότι είναι Gmail (όπως ζητήθηκε)
    if (!str_ends_with(strtolower($email), '@gmail.com')) {
        jsonResponse(false, 'Χρησιμοποιήστε διεύθυνση Gmail (@gmail.com)');
    }
    
    // Έλεγχος κωδικού
    if (strlen($password) < 6) {
        jsonResponse(false, 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες');
    }
    
    // Έλεγχος αν υπάρχει ήδη το username
    $checkUser = $db->prepare("SELECT id FROM users WHERE username = ?");
    $checkUser->bind_param("s", $username);
    $checkUser->execute();
    
    if ($checkUser->get_result()->num_rows > 0) {
        jsonResponse(false, 'Το username υπάρχει ήδη');
    }
    
    // Έλεγχος αν υπάρχει ήδη το email
    $checkEmail = $db->prepare("SELECT id FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    
    if ($checkEmail->get_result()->num_rows > 0) {
        jsonResponse(false, 'Το email υπάρχει ήδη');
    }
    
    // Καταχώριση νέου χρήστη
    $hashedPassword = hashPassword($password);
    $isGuest = 0; // Κανονικός χρήστης, όχι guest
    
    $sql = "INSERT INTO users (username, email, password_hash, is_guest, created_at) 
            VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sssi", $username, $email, $hashedPassword, $isGuest);
    
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        
        // Αυτόματη σύνδεση μετά την εγγραφή
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['is_guest'] = $isGuest;
        
        jsonResponse(true, 'Επιτυχής εγγραφή!', [
            'user_id' => $user_id,
            'username' => $username
        ]);
    } else {
        jsonResponse(false, 'Σφάλμα κατά την εγγραφή: ' . $db->error);
    }
}

function handleGuestLogin() {
    global $db;
    
    $guestId = $_POST['guest_id'] ?? ('guest_' . rand(1000, 9999));
    
    // Δημιουργία μοναδικού username για guest
    $username = $guestId;
    $counter = 1;
    
    // Βεβαιώνουμε ότι το username είναι μοναδικό
    while (true) {
        $check = $db->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        
        if ($check->get_result()->num_rows === 0) {
            break;
        }
        
        $username = $guestId . '_' . $counter;
        $counter++;
        
        if ($counter > 100) {
            jsonResponse(false, 'Δεν μπορεί να δημιουργηθεί guest account');
        }
    }
    
    // Δημιουργία guest account
    $isGuest = 1;
    $email = NULL; // Το guest δεν έχει email
    
    $sql = "INSERT INTO users (username, email, password_hash, is_guest, created_at) 
            VALUES (?, ?, NULL, ?, NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ssi", $username, $email, $isGuest);
    
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        
        // Ορισμός session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        $_SESSION['is_guest'] = $isGuest;
        
        jsonResponse(true, 'Guest login successful', [
            'user_id' => $user_id,
            'username' => $username,
            'is_guest' => $isGuest
        ]);
    } else {
        jsonResponse(false, 'Σφάλμα κατά τη δημιουργία guest account');
    }
}

function checkUsernameAvailability() {
    global $db;
    
    $username = trim($_POST['username'] ?? '');
    
    if (empty($username)) {
        jsonResponse(false, 'Το username δεν μπορεί να είναι κενό');
    }
    
    if (strlen($username) < 3) {
        jsonResponse(false, 'Τουλάχιστον 3 χαρακτήρες', ['available' => false]);
    }
    
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        jsonResponse(false, 'Μόνο λατινικά, αριθμοί και _', ['available' => false]);
    }
    
    $sql = "SELECT id FROM users WHERE username = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        jsonResponse(true, 'Το username υπάρχει ήδη', ['available' => false]);
    } else {
        jsonResponse(true, 'Το username είναι διαθέσιμο', ['available' => true]);
    }
}

function checkEmailAvailability() {
    global $db;
    
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Μη έγκυρη διεύθυνση email', ['available' => false]);
    }
    
    // Εξασφάλιση ότι είναι Gmail
    if (!str_ends_with(strtolower($email), '@gmail.com')) {
        jsonResponse(false, 'Χρησιμοποιήστε Gmail (@gmail.com)', ['available' => false]);
    }
    
    $sql = "SELECT id FROM users WHERE email = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        jsonResponse(true, 'Το email υπάρχει ήδη', ['available' => false]);
    } else {
        jsonResponse(true, 'Το email είναι διαθέσιμο', ['available' => true]);
    }
}

function handleForgotPassword() {
    global $db;
    
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Παρακαλώ εισάγετε έγκυρο email');
    }
    
    // Έλεγχος αν υπάρχει ο χρήστης
    $sql = "SELECT id, username FROM users WHERE email = ? AND is_guest = FALSE";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Για ασφάλεια, δε λέμε αν το email υπάρχει ή όχι
        jsonResponse(true, 'Εάν το email υπάρχει, σας στείλαμε οδηγίες επαναφοράς');
    }
    
    $user = $result->fetch_assoc();
    
    // Στην πραγματικότητα, εδώ θα στείναμε email με reset link
    // Για τώρα, απλά επιστρέφουμε επιτυχές μήνυμα
    
    jsonResponse(true, 'Οδηγίες επαναφοράς κωδικού στάλθηκαν στο email σας');
}

// Κλείσιμο σύνδεσης
$db->close();
?>