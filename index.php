<?php
// index.php - ΑΛΛΑΞΕ ΜΟΝΟ ΑΥΤΕΣ ΤΙΣ ΓΡΑΜΜΕΣ
session_start();

// 1. ΚΑΘΑΡΙΣΜΟΣ ΣΥΝΔΕΣΗΣ ΑΝ ΥΠΑΡΧΕΙ
$_SESSION = array();

// 2. ΚΑΤΕΣΤΡΕΨΕ ΤΟ SESSION
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// 3. ΞΕΚΙΝΑ ΝΕΟ ΚΕΝΟ SESSION
session_start();
session_regenerate_id(true);
// Επεξεργασία φόρμας (απλοποιημένη για τώρα)
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'login') {
            // Θα το κάνουμε με AJAX αργότερα
        } elseif ($_POST['action'] === 'signup') {
            // Θα το κάνουμε με AJAX αργότερα
        }
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎴 Ξερί Online - Welcome</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/welcome.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="js/xeri-api-client.js"></script>
    <script src="js/xeri-board-renderer.js"></script>
</head>
<body>
    <div class="container-fluid welcome-container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <!-- Left Column: Game Info & Rules -->
            <div class="col-lg-5 col-md-6 d-none d-md-block">
                <div class="welcome-card info-card">
                    <h1 class="display-4 text-primary">
                        <i class="fas fa-cards"></i> Jeri Online
                    </h1>
                    <p class="lead">Το ελληνικό παραδοσιακό παιχνίδι τράπουλας!</p>
                    
                    <div class="features mt-4">
                        <h4><i class="fas fa-gamepad"></i> Χαρακτηριστικά:</h4>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check-circle text-success"></i> Παίξτε με φίλους ή vs AI</li>
                            <li><i class="fas fa-check-circle text-success"></i> Πραγματικό χρόνο</li>
                            <li><i class="fas fa-check-circle text-success"></i> Βαθμολογία και στατιστικά</li>
                            <li><i class="fas fa-check-circle text-success"></i> Απλό και διαισθητικό</li>
                        </ul>
                    </div>
                    
                    <div class="rules mt-4">
                        <h4><i class="fas fa-book"></i> Βασικοί Κανόνες:</h4>
                        <p>Κάθε παίκτης παίρνει από 6 φύλλα και ρίχνει ένα φύλλο στο τραπέζι όταν έρθει η σειρά του. Σκοπός του παιχνιδιού είναι να μαζέψεις όσο περισσότερα φύλλα μπορείς και αν είναι δυνατόν, να κάνεις ξερή.
Για να μαζέψεις τα φύλλα από το τραπέζι θα πρέπει να ρίξεις ένα φύλλο με όμοια φιγούρα με αυτό που έχει πέσει τελευταίο (π.χ. "οκτώ" αν το τελευταίο φύλλο είναι "οκτώ"). Εναλλακτικά, μπορείς να ρίξεις βαλέ, οπότε και παίρνεις τα φύλλα σε οποιαδήποτε περίπτωση.
Αν υπάρχει μόνο ένα φύλλο στο τραπέζι και το πάρεις ρίχνοντας ένα φύλλο με ίδια φιγούρα, τότε παίρνεις τα φύλλα και ταυτόχρονα κάνεις "Ξερή".</p>
                    </div>
                    
                    <div class="stats mt-4">
                        <div class="row text-center">
                            <div class="col">
                                <h3>100</h3>
                                <p>Παίκτες</p>
                            </div>
                            <div class="col">
                                <h3>5,678</h3>
                                <p>Παιχνίδια</p>
                            </div>
                            <div class="col">
                                <h3>24/7</h3>
                                <p>Διαθεσιμότητα</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Login/Signup Forms -->
            <div class="col-lg-4 col-md-6 col-sm-10">
                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs nav-justified mb-4" id="authTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button">
                            <i class="fas fa-sign-in-alt"></i> Σύνδεση
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="signup-tab" data-bs-toggle="tab" data-bs-target="#signup" type="button">
                            <i class="fas fa-user-plus"></i> Εγγραφή
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="guest-tab" data-bs-toggle="tab" data-bs-target="#guest" type="button">
                            <i class="fas fa-user"></i> Guest
                        </button>
                    </li>
                </ul>
                
                <!-- Tabs Content -->
                <div class="tab-content" id="authTabsContent">
                    <!-- LOGIN TAB -->
                    <div class="tab-pane fade show active" id="login" role="tabpanel">
                        <div class="welcome-card">
                            <h3 class="text-center mb-4">Σύνδεση</h3>
                            
                            <div id="login-message" class="alert d-none"></div>
                            
                            <form id="login-form">
                                <div class="mb-3">
                                    <label class="form-label">Email ή Username</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-envelope"></i>
                                        </span>
                                        <input type="text" class="form-control" id="login-identifier" 
                                               placeholder="email ή username" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Κωδικός</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                        <input type="password" class="form-control" id="login-password" 
                                               placeholder="Κωδικός πρόσβασης" required>
                                        <button class="btn btn-outline-secondary" type="button" id="toggle-login-password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="remember-me">
                                    <label class="form-check-label" for="remember-me">Να με θυμάσαι</label>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-sign-in-alt"></i> Σύνδεση
                                    </button>
                                </div>
                            </form>
                            
                            <div class="text-center mt-3">
                                <a href="#" class="text-decoration-none" id="forgot-password">
                                    Ξέχασα τον κωδικό μου
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- SIGNUP TAB -->
                    <div class="tab-pane fade" id="signup" role="tabpanel">
                        <div class="welcome-card">
                            <h3 class="text-center mb-4">Δημιουργία Λογαριασμού</h3>
                            
                            <div id="signup-message" class="alert d-none"></div>
                            
                            <form id="signup-form">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Username *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-user"></i>
                                            </span>
                                            <input type="text" class="form-control" id="signup-username" 
                                                   placeholder="username" required>
                                        </div>
                                        <div class="form-text" id="username-feedback"></div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Email *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-at"></i>
                                            </span>
                                            <input type="email" class="form-control" id="signup-email" 
                                                   placeholder="example@gmail.com" required>
                                        </div>
                                        <div class="form-text" id="email-feedback"></div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Κωδικός *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                            <input type="password" class="form-control" id="signup-password" 
                                                   placeholder="Κωδικός" required>
                                            <button class="btn btn-outline-secondary" type="button" id="toggle-signup-password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle"></i> Τουλάχιστον 6 χαρακτήρες
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Επιβεβαίωση Κωδικού *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                            <input type="password" class="form-control" id="signup-confirm" 
                                                   placeholder="Επαλήθευση" required>
                                        </div>
                                        <div class="form-text" id="password-match-feedback"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Προφίλ (προαιρετικό)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-image"></i>
                                        </span>
                                        <select class="form-select" id="signup-avatar">
                                            <option value="default">Προεπιλεγμένο</option>
                                            <option value="male1">Άντρας 1</option>
                                            <option value="male2">Άντρας 2</option>
                                            <option value="female1">Γυναίκα 1</option>
                                            <option value="female2">Γυναίκα 2</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="terms" required>
                                    <label class="form-check-label" for="terms">
                                        Συμφωνώ με τους <a href="#" class="text-decoration-none">Όρους Χρήσης</a>
                                    </label>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-user-plus"></i> Δημιουργία Λογαριασμού
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- GUEST TAB -->
                    <div class="tab-pane fade" id="guest" role="tabpanel">
                        <div class="welcome-card text-center">
                            <h3 class="mb-4">Παίξτε ως Guest</h3>
                            <div class="mb-4">
                                <i class="fas fa-user-secret fa-5x text-secondary"></i>
                            </div>
                            <p class="mb-4">
                                Παίξτε χωρίς εγγραφή! Οι πόντοι δεν αποθηκεύονται και ο λογαριασμός διαγράφεται μετά.
                            </p>
                            
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-warning btn-lg" id="play-as-guest">
                                    <i class="fas fa-play-circle"></i> Παίξτε Τώρα
                                </button>
                            </div>
                            
                            <div class="mt-4">
                                <h5>Περιορισμοί Guest:</h5>
                                <ul class="list-unstyled text-start">
                                    <li><i class="fas fa-times-circle text-danger"></i> Δεν αποθηκεύονται στατιστικά</li>
                                    <li><i class="fas fa-times-circle text-danger"></i> Δεν υπάρχει βαθμολογία</li>
                                    <li><i class="fas fa-check-circle text-success"></i> Πλήρης πρόσβαση στο παιχνίδι</li>
                                    <li><i class="fas fa-check-circle text-success"></i> Παίξτε με φίλους</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="text-center mt-4">
                    <p class="text-muted">
                        &copy; 2024 Ξερί Online | 
                        <a href="#" class="text-decoration-none">Βοήθεια</a> | 
                        <a href="#" class="text-decoration-none">Πολιτική Απορρήτου</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script src="js/auth.js"></script>
</body>
</html>