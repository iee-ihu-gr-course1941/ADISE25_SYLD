<?php
// api/api.php
require_once '../db_config.php';
require_once '../includes/XeriGame.class.php';
require_once '../includes/XeriDeck.class.php';
require_once '../includes/XeriRules.class.php';
require_once '../includes/XeriAI.class.php';

header('Content-Type: application/json');
session_start();

// Έλεγχος αν ο χρήστης είναι συνδεδεμένος
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

// Αν δεν είναι συνδεδεμένος, επιστρέφουμε error
if (!isAuthenticated() && !in_array($_GET['action'] ?? '', ['login', 'signup', 'check_username', 'check_email', 'guest_login'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated', 'redirect' => 'index.php']);
    exit();
}

$db = getDBConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Βοηθητική συνάρτηση για responses
function jsonResponse($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// ============================================
// MAIN ROUTING LOGIC
// ============================================

switch ($action) {
    // ============================================
    // AUTHENTICATION ENDPOINTS
    // ============================================
    case 'login':
    case 'signup':
    case 'guest_login':
    case 'check_username':
    case 'check_email':
    case 'forgot_password':
        handleAuth($action);
        break;
    
    // ============================================
    // GAME MANAGEMENT ENDPOINTS
    // ============================================
    case 'create_game':
        createGame();
        break;
        
    case 'join_game':
        joinGame();
        break;
        
    case 'get_available_games':
        getAvailableGames();
        break;
        
    case 'get_game_state':
        getGameState();
        break;
        
    case 'leave_game':
        leaveGame();
        break;
        
    case 'surrender':
        surrenderGame();
        break;
        
    case 'claim_xeri':
        claimXeri();
        break;
        
    case 'resign':
        resignGame();
        break;
        
    // ============================================
    // GAME MOVE ENDPOINTS
    // ============================================
    case 'play_card':
        playCard();
        break;
        
    case 'draw_card':
        drawCard();
        break;
        
    case 'pass_turn':
        passTurn();
        break;
        
    case 'get_valid_moves':
    case 'get_possible_moves':
        getValidMoves();
        break;
        
    case 'ai_move':
        handleAiMove();
        break;
        
    // ============================================
    // USER & STATS ENDPOINTS
    // ============================================
    case 'get_user_stats':
        getUserStats();
        break;
        
    case 'get_leaderboard':
        getLeaderboard();
        break;
        
    case 'get_my_games':
        getMyGames();
        break;
        
    case 'logout':
        handleLogout();
        break;
        
    default:
        jsonResponse(false, 'Invalid action: ' . $action);
}

// ============================================
// AUTHENTICATION FUNCTIONS
// ============================================

function handleAuth($action) {
    global $db;
    
    // Φορτώνουμε το auth.php
    require_once 'auth.php';
    
    // Καλούμε την αντίστοιχη συνάρτηση
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
    }
}

function handleLogout() {
    session_destroy();
    jsonResponse(true, 'Logged out successfully');
}

// ============================================
// GAME MANAGEMENT FUNCTIONS
// ============================================

function createGame() {
    global $db, $user_id;
    
    $game_type = $_POST['game_type'] ?? 'human-human';
    $difficulty = $_POST['difficulty'] ?? 'medium';
    
    // Έλεγχος αν ο χρήστης έχει ήδη active game
    $checkSql = "SELECT id FROM games 
                 WHERE (player1_id = ? OR player2_id = ?) 
                 AND status IN ('waiting', 'active') 
                 LIMIT 1";
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->bind_param("ii", $user_id, $user_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        $existing = $checkResult->fetch_assoc();
        jsonResponse(false, 'Έχετε ήδη ενεργό παιχνίδι', ['game_id' => $existing['id']]);
    }
    
    $db->begin_transaction();
    
    try {
        // 1. Δημιουργία εγγραφής στον πίνακα games
        $sql = "INSERT INTO games (player1_id, game_type, ai_difficulty, status, created_at) 
                VALUES (?, ?, ?, 'waiting', NOW())";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iss", $user_id, $game_type, $difficulty);
        
        if (!$stmt->execute()) {
            throw new Exception("Σφάλμα δημιουργίας παιχνιδιού: " . $db->error);
        }
        
        $game_id = $stmt->insert_id;
        
        // 2. Αν είναι vs computer, δημιουργούμε το παιχνίδι αμέσως
        if ($game_type === 'human-computer') {
            // Δημιουργία guest account για τον computer
            $computerUsername = 'Computer_' . rand(100, 999);
            
            $guestSql = "INSERT INTO users (username, is_guest, created_at) 
                         VALUES (?, 1, NOW())";
            $guestStmt = $db->prepare($guestSql);
            $guestStmt->bind_param("s", $computerUsername);
            $guestStmt->execute();
            $computer_id = $guestStmt->insert_id;
            
            // Σύνδεση computer ως player2
            $updateSql = "UPDATE games 
                          SET player2_id = ?, 
                              status = 'active',
                              started_at = NOW(),
                              current_turn_player_number = 1 
                          WHERE id = ?";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->bind_param("ii", $computer_id, $game_id);
            $updateStmt->execute();
            
            // Δημιουργία initial deck και μοιρασμα
            $deck = new XeriDeck($db, $game_id);
            $initResult = $deck->initializeDeck();
            
            if (!$initResult['success']) {
                throw new Exception($initResult['message']);
            }
            
        } else {
            // Αν είναι vs human, το παιχνίδι μένει σε waiting
            // Δημιουργία game_state
            $stateSql = "INSERT INTO game_state (game_id, status, stock_count) 
                         VALUES (?, 'waiting', 52)";
            $stateStmt = $db->prepare($stateSql);
            $stateStmt->bind_param("i", $game_id);
            $stateStmt->execute();
        }
        
        $db->commit();
        
        // Προσδιορισμός player_number για τον τρέχοντα χρήστη
        $player_number = 1; // Πάντα player1 όταν δημιουργεί
        
        jsonResponse(true, 'Παιχνίδι δημιουργήθηκε επιτυχώς', [
            'game_id' => $game_id,
            'player_number' => $player_number,
            'game_type' => $game_type,
            'status' => ($game_type === 'human-computer') ? 'active' : 'waiting'
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function joinGame() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Έλεγχος αν το παιχνίδι υπάρχει και είναι waiting
    $checkSql = "SELECT id, player1_id, game_type 
                 FROM games 
                 WHERE id = ? AND status = 'waiting' 
                 AND player2_id IS NULL 
                 AND player1_id != ?";
    
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->bind_param("ii", $game_id, $user_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        jsonResponse(false, 'Το παιχνίδι δεν είναι διαθέσιμο');
    }
    
    $game = $checkResult->fetch_assoc();
    
    // Αν είναι human-human game, ο τρέχων χρήστης γίνεται player2
    if ($game['game_type'] === 'human-human') {
        $db->begin_transaction();
        
        try {
            // Ενημέρωση του games πίνακα
            $updateSql = "UPDATE games 
                          SET player2_id = ?, 
                              status = 'active',
                              started_at = NOW(),
                              current_turn_player_number = 1
                          WHERE id = ?";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->bind_param("ii", $user_id, $game_id);
            $updateStmt->execute();
            
            // Δημιουργία initial deck και μοιρασμα
            $deck = new XeriDeck($db, $game_id);
            $initResult = $deck->initializeDeck();
            
            if (!$initResult['success']) {
                throw new Exception($initResult['message']);
            }
            
            // Ενημέρωση game_state
            $stateSql = "UPDATE game_state 
                         SET status = 'started', 
                             current_player_number = 1,
                             stock_count = 40, -- 52 - (6*2)
                             player1_hand_size = 6,
                             player2_hand_size = 6
                         WHERE game_id = ?";
            $stateStmt = $db->prepare($stateSql);
            $stateStmt->bind_param("i", $game_id);
            $stateStmt->execute();
            
            $db->commit();
            
            jsonResponse(true, 'Μπήκατε στο παιχνίδι!', [
                'game_id' => $game_id,
                'player_number' => 2,
                'status' => 'active'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
        }
    } else {
        jsonResponse(false, 'Αυτό το παιχνίδι δεν είναι για ανθρώπους');
    }
}

function getAvailableGames() {
    global $db, $user_id;
    
    $sql = "SELECT g.id, g.created_at, g.game_type, 
                   u.username as host_name,
                   TIMESTAMPDIFF(SECOND, g.created_at, NOW()) as seconds_ago
            FROM games g
            JOIN users u ON g.player1_id = u.id
            WHERE g.status = 'waiting' 
            AND g.game_type = 'human-human'
            AND g.player2_id IS NULL
            AND g.player1_id != ?
            ORDER BY g.created_at ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $games = [];
    while ($row = $result->fetch_assoc()) {
        $games[] = [
            'id' => $row['id'],
            'host' => $row['host_name'],
            'created' => $row['created_at'],
            'seconds_ago' => $row['seconds_ago'],
            'game_type' => $row['game_type']
        ];
    }
    
    jsonResponse(true, 'Available games loaded', ['games' => $games]);
}

function getGameState() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? $_GET['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Δημιουργία αντικειμένου XeriGame
    try {
        $game = new XeriGame($db, $game_id);
        
        // Βρίσκουμε τον αριθμό του παίκτη
        $player_number = getPlayerNumberInGame($db, $game_id, $user_id);
        
        if ($player_number == 0) {
            jsonResponse(false, 'Δεν έχετε πρόσβαση σε αυτό το παιχνίδι');
        }
        
        // Παίρνουμε τις πληροφορίες του παιχνιδιού
        $gameInfo = $game->getGameInfo();
        
        // Παίρνουμε το χέρι του παίκτη
        $hand = $game->getPlayerHand($player_number);
        
        // Παίρνουμε τις κάρτες στο τραπέζι
        $tableCards = $game->getTableCards();
        
        // Παίρνουμε το χέρι του αντιπάλου (μόνο μέγεθος)
        $opponentNumber = ($player_number == 1) ? 2 : 1;
        $opponentHandSize = $game->getPlayerHandSize($opponentNumber);
        
        // Έλεγχος αν είναι η σειρά του παίκτη
        $isMyTurn = $game->isMyTurn();
        
        // Έλεγχος αν μπορεί να παίξει ή να τραβήξει
        $canPlay = ($isMyTurn && count($hand) > 0);
        $canDraw = false;
        $canPass = $isMyTurn;
        
        // Έλεγχος αν μπορεί να τραβήξει
        if ($isMyTurn) {
            $stockCount = $gameInfo['stock_count'] ?? 0;
            $canDraw = ($stockCount > 0);
        }
        
        // Παίρνουμε το τελευταίο move
        $lastMove = getLastMove($db, $game_id);
        
        $responseData = [
            'game_info' => $gameInfo,
            'my_player_number' => $player_number,
            'my_hand' => $hand,
            'my_hand_size' => count($hand),
            'opponent_hand_size' => $opponentHandSize,
            'table_cards' => $tableCards,
            'is_my_turn' => $isMyTurn,
            'can_play' => $canPlay,
            'can_draw' => $canDraw,
            'can_pass' => $canPass,
            'last_move' => $lastMove
        ];
        
        // Αν είναι vs computer και είναι η σειρά του AI
        if ($gameInfo['game_type'] == 'human-computer' && 
            $gameInfo['current_turn'] == $opponentNumber) {
            $responseData['ai_thinking'] = true;
        }
        
        jsonResponse(true, 'Game state loaded', $responseData);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function leaveGame() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id || !isUserInGame($db, $user_id, $game_id)) {
        jsonResponse(false, 'Invalid game or no access');
    }
    
    // Ενημέρωση status του παιχνιδιού
    $sql = "UPDATE games 
            SET status = 'abandoned',
                finished_at = NOW()
            WHERE id = ? AND (player1_id = ? OR player2_id = ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iii", $game_id, $user_id, $user_id);
    
    if ($stmt->execute()) {
        // Ενημέρωση game_state
        $stateSql = "UPDATE game_state SET status = 'aborted' WHERE game_id = ?";
        $stateStmt = $db->prepare($stateSql);
        $stateStmt->bind_param("i", $game_id);
        $stateStmt->execute();
        
        jsonResponse(true, 'Εγκαταλείψατε το παιχνίδι');
    } else {
        jsonResponse(false, 'Σφάλμα κατά την εγκατάλειψη');
    }
}

function surrenderGame() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id || !isUserInGame($db, $user_id, $game_id)) {
        jsonResponse(false, 'Invalid game or no access');
    }
    
    // Προσδιορισμός του νικητή (ο άλλος παίκτης)
    $gameSql = "SELECT player1_id, player2_id FROM games WHERE id = ?";
    $gameStmt = $db->prepare($gameSql);
    $gameStmt->bind_param("i", $game_id);
    $gameStmt->execute();
    $gameResult = $gameStmt->get_result();
    $game = $gameResult->fetch_assoc();
    
    $winner = ($game['player1_id'] == $user_id) ? 2 : 1;
    
    // Ενημέρωση του παιχνιδιού
    $sql = "UPDATE games 
            SET status = 'finished',
                winner_player_number = ?,
                finished_at = NOW()
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii", $winner, $game_id);
    
    if ($stmt->execute()) {
        // Ενημέρωση game_state
        $stateSql = "UPDATE game_state 
                     SET status = 'ended', 
                         current_player_number = NULL 
                     WHERE game_id = ?";
        $stateStmt = $db->prepare($stateSql);
        $stateStmt->bind_param("i", $game_id);
        $stateStmt->execute();
        
        jsonResponse(true, 'Παραδώσατε το παιχνίδι', ['winner' => $winner]);
    } else {
        jsonResponse(false, 'Σφάλμα κατά την παράδοση');
    }
}

function resignGame() {
    // Αυτό είναι απλά άλλο όνομα για το surrender
    surrenderGame();
}

function claimXeri() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id || !isUserInGame($db, $user_id, $game_id)) {
        jsonResponse(false, 'Invalid game or no access');
    }
    
    // Υλοποίηση καταγραφής ξερής
    // Στην πραγματικότητα, αυτό θα γινόταν αυτόματα στο playCard()
    
    jsonResponse(true, 'Ξερή καταγράφηκε!');
}

// ============================================
// GAME MOVE FUNCTIONS
// ============================================

function playCard() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    $card_id = $_POST['card_id'] ?? 0;
    $claimed_cards = $_POST['claimed_cards'] ?? []; // Array of card IDs to claim
    
    if (!$game_id || !$card_id) {
        jsonResponse(false, 'Game ID και Card ID απαιτούνται');
    }
    
    // Βρίσκουμε τον αριθμό του παίκτη
    $player_number = getPlayerNumberInGame($db, $game_id, $user_id);
    if ($player_number == 0) {
        jsonResponse(false, 'Δεν έχετε πρόσβαση σε αυτό το παιχνίδι');
    }
    
    // Δημιουργία αντικειμένου XeriGame
    try {
        $game = new XeriGame($db, $game_id, $player_number);
        
        // Έλεγχος αν είναι η σειρά του παίκτη
        if (!$game->isMyTurn()) {
            jsonResponse(false, 'Δεν είναι η σειρά σου!');
        }
        
        // Παίζουμε την κάρτα
        $result = $game->playCard($player_number, $card_id, $claimed_cards);
        
        if (!$result['success']) {
            jsonResponse(false, $result['message']);
        }
        
        // Αν είναι vs computer, ελέγχουμε αν τελείωσε το παιχνίδι
        $gameInfo = $game->getGameInfo();
        
        $responseData = [
            'claimed_count' => $result['claimed_count'],
            'is_xeri' => $result['is_xeri'],
            'next_player' => $result['next_player'],
            'game_ended' => $result['game_ended'] ?? false
        ];
        
        // Αν είναι vs computer και έγινε κίνηση, ελέγχουμε αν πρέπει να παίξει το AI
        if ($gameInfo['game_type'] == 'human-computer' && 
            !$result['game_ended'] && 
            $result['next_player'] != $player_number) {
            $responseData['ai_should_play'] = true;
        }
        
        jsonResponse(true, 'Κάρτα παίχτηκε επιτυχώς!', $responseData);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function drawCard() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Βρίσκουμε τον αριθμό του παίκτη
    $player_number = getPlayerNumberInGame($db, $game_id, $user_id);
    if ($player_number == 0) {
        jsonResponse(false, 'Δεν έχετε πρόσβαση σε αυτό το παιχνίδι');
    }
    
    // Δημιουργία αντικειμένου XeriGame
    try {
        $game = new XeriGame($db, $game_id, $player_number);
        
        // Έλεγχος αν είναι η σειρά του παίκτη
        if (!$game->isMyTurn()) {
            jsonResponse(false, 'Δεν είναι η σειρά σου!');
        }
        
        // Τραβάμε κάρτα
        $result = $game->drawFromStock($player_number);
        
        if (!$result['success']) {
            jsonResponse(false, $result['message']);
        }
        
        $responseData = [
            'card_id' => $result['card_id'],
            'next_player' => $result['next_player']
        ];
        
        // Αν είναι vs computer, ελέγχουμε αν πρέπει να παίξει το AI
        $gameInfo = $game->getGameInfo();
        if ($gameInfo['game_type'] == 'human-computer' && 
            $result['next_player'] != $player_number) {
            $responseData['ai_should_play'] = true;
        }
        
        jsonResponse(true, 'Τράβηξες μια κάρτα!', $responseData);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function passTurn() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Βρίσκουμε τον αριθμό του παίκτη
    $player_number = getPlayerNumberInGame($db, $game_id, $user_id);
    if ($player_number == 0) {
        jsonResponse(false, 'Δεν έχετε πρόσβαση σε αυτό το παιχνίδι');
    }
    
    // Δημιουργία αντικειμένου XeriGame
    try {
        $game = new XeriGame($db, $game_id, $player_number);
        
        // Έλεγχος αν είναι η σειρά του παίκτη
        if (!$game->isMyTurn()) {
            jsonResponse(false, 'Δεν είναι η σειρά σου!');
        }
        
        // Παρατάμε τη σειρά
        $result = $game->passTurn($player_number);
        
        if (!$result['success']) {
            jsonResponse(false, $result['message']);
        }
        
        $responseData = [
            'next_player' => $result['next_player']
        ];
        
        // Αν είναι vs computer, ελέγχουμε αν πρέπει να παίξει το AI
        $gameInfo = $game->getGameInfo();
        if ($gameInfo['game_type'] == 'human-computer' && 
            $result['next_player'] != $player_number) {
            $responseData['ai_should_play'] = true;
        }
        
        jsonResponse(true, 'Παρατήσατε τη σειρά σας', $responseData);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function getValidMoves() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? $_GET['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Βρίσκουμε τον αριθμό του παίκτη
    $player_number = getPlayerNumberInGame($db, $game_id, $user_id);
    if ($player_number == 0) {
        jsonResponse(false, 'Δεν έχετε πρόσβαση σε αυτό το παιχνίδι');
    }
    
    // Δημιουργία αντικειμένου XeriGame
    try {
        $game = new XeriGame($db, $game_id, $player_number);
        
        // Έλεγχος αν είναι η σειρά του παίκτη
        if (!$game->isMyTurn()) {
            jsonResponse(false, 'Δεν είναι η σειρά σου');
        }
        
        // Παίρνουμε τις δυνατές κινήσεις
        $validMoves = $game->getValidMoves($player_number);
        
        jsonResponse(true, 'Valid moves loaded', $validMoves);
        
    } catch (Exception $e) {
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function handleAiMove() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Βρίσκουμε τον αριθμό του AI player
    $gameInfo = getGameInfo($db, $game_id);
    if (!$gameInfo) {
        jsonResponse(false, 'Game not found');
    }
    
    if ($gameInfo['game_type'] != 'human-computer') {
        jsonResponse(false, 'Not a computer game');
    }
    
    // Βρίσκουμε τον αριθμό του AI player (πάντα player2)
    $ai_player_number = 2;
    
    // Δημιουργία AI
    $difficulty = $gameInfo['ai_difficulty'] ?? 'medium';
    $ai = new XeriAI($db, $game_id, $ai_player_number, $difficulty);
    
    // Έλεγχος αν είναι η σειρά του AI
    $game = new XeriGame($db, $game_id);
    if ($game->getGameInfo()['current_turn'] != $ai_player_number) {
        jsonResponse(false, 'Not AI turn');
    }
    
    // Κάνει την κίνηση
    $move = $ai->makeMove();
    
    if (!$move['success']) {
        jsonResponse(false, 'AI move failed: ' . $move['message']);
    }
    
    // Ανάλογα με τον τύπο της κίνησης, εκτελούμε την αντίστοιχη ενέργεια
    switch ($move['type']) {
        case 'play':
            // Το AI έπαιξε κάρτα
            $claimedCardIds = $move['claimed_cards'] ?? [];
            $game->playCard($ai_player_number, $move['card']['id'], $claimedCardIds);
            break;
            
        case 'draw':
            // Το AI τράβηξε κάρτα
            $game->drawFromStock($ai_player_number);
            break;
            
        case 'pass':
            // Το AI παρέδωσε τη σειρά
            $game->passTurn($ai_player_number);
            break;
    }
    
    // Έλεγχος αν τελείωσε το παιχνίδι
    $gameEnded = false;
    if ($ai->shouldEndGame()) {
        $gameEnded = true;
    }
    
    jsonResponse(true, 'AI made move', [
        'move_type' => $move['type'],
        'card_played' => $move['card'] ?? null,
        'claimed_count' => $move['claimed_count'] ?? 0,
        'is_xeri' => $move['is_xeri'] ?? false,
        'game_ended' => $gameEnded
    ]);
}

// ============================================
// USER & STATS FUNCTIONS
// ============================================

function getUserStats() {
    global $db, $user_id;
    
    $sql = "SELECT 
               COUNT(*) as total_games,
               SUM(CASE WHEN winner = ? THEN 1 ELSE 0 END) as wins,
               SUM(CASE WHEN winner != ? AND winner IS NOT NULL THEN 1 ELSE 0 END) as losses,
               (SELECT COUNT(*) FROM game_state WHERE game_id IN 
                   (SELECT id FROM games WHERE player1_id = ? OR player2_id = ?) 
                AND player_number = CASE 
                    WHEN (SELECT player1_id FROM games WHERE id = game_state.game_id) = ? THEN 1 
                    ELSE 2 
                END AND xeri_count > 0) as xeri_count
            FROM games 
            WHERE (player1_id = ? OR player2_id = ?)
            AND status = 'finished'";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iiiiiii", $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        jsonResponse(true, 'User stats loaded', [
            'stats' => $row
        ]);
    } else {
        jsonResponse(true, 'User stats loaded', [
            'stats' => [
                'total_games' => 0,
                'wins' => 0,
                'losses' => 0,
                'xeri_count' => 0
            ]
        ]);
    }
}

function getLeaderboard() {
    global $db;
    
    $sql = "SELECT u.id, u.username, 
                   COUNT(DISTINCT g.id) as games_played,
                   SUM(CASE WHEN g.winner_player_number = 1 AND g.player1_id = u.id THEN 1
                            WHEN g.winner_player_number = 2 AND g.player2_id = u.id THEN 1
                            ELSE 0 END) as wins,
                   SUM(CASE WHEN g.winner_player_number = 1 AND g.player2_id = u.id THEN 1
                            WHEN g.winner_player_number = 2 AND g.player1_id = u.id THEN 1
                            ELSE 0 END) as losses,
                   (SELECT COUNT(*) FROM game_state gs 
                    WHERE gs.player_number = CASE 
                        WHEN g.player1_id = u.id THEN 1 
                        ELSE 2 
                    END AND gs.xeri_count > 0) as total_xeri
            FROM users u
            LEFT JOIN games g ON (u.id = g.player1_id OR u.id = g.player2_id) 
                AND g.status = 'finished'
            WHERE u.is_guest = 0
            GROUP BY u.id
            ORDER BY wins DESC, games_played DESC
            LIMIT 20";
    
    $result = $db->query($sql);
    
    $leaderboard = [];
    while ($row = $result->fetch_assoc()) {
        $leaderboard[] = $row;
    }
    
    jsonResponse(true, 'Leaderboard loaded', ['leaderboard' => $leaderboard]);
}

function getMyGames() {
    global $db, $user_id;
    
    $sql = "SELECT g.id, g.status, g.game_type, g.created_at,
                   u1.username as player1_name,
                   u2.username as player2_name,
                   CASE 
                       WHEN g.player1_id = ? THEN 1
                       WHEN g.player2_id = ? THEN 2
                   END as my_player_number,
                   g.winner_player_number
            FROM games g
            LEFT JOIN users u1 ON g.player1_id = u1.id
            LEFT JOIN users u2 ON g.player2_id = u2.id
            WHERE (g.player1_id = ? OR g.player2_id = ?)
            ORDER BY g.created_at DESC
            LIMIT 20";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $games = [];
    while ($row = $result->fetch_assoc()) {
        $games[] = $row;
    }
    
    jsonResponse(true, 'My games loaded', ['games' => $games]);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function isUserInGame($db, $user_id, $game_id) {
    $sql = "SELECT id FROM games 
            WHERE id = ? AND (player1_id = ? OR player2_id = ?) 
            AND status IN ('waiting', 'active')";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iii", $game_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

function getPlayerNumberInGame($db, $game_id, $user_id) {
    $sql = "SELECT CASE 
                   WHEN player1_id = ? THEN 1
                   WHEN player2_id = ? THEN 2
                   ELSE 0
               END as player_number
        FROM games 
        WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iii", $user_id, $user_id, $game_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['player_number'];
    }
    
    return 0;
}

function getGameInfo($db, $game_id) {
    $sql = "SELECT g.*, 
                   u1.username as player1_name,
                   u2.username as player2_name
            FROM games g
            LEFT JOIN users u1 ON g.player1_id = u1.id
            LEFT JOIN users u2 ON g.player2_id = u2.id
            WHERE g.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $game_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

function getLastMove($db, $game_id) {
    $sql = "SELECT m.*, u.username
            FROM moves m
            LEFT JOIN games g ON m.game_id = g.id
            LEFT JOIN users u ON CASE 
                WHEN m.player_number = 1 THEN g.player1_id
                WHEN m.player_number = 2 THEN g.player2_id
                ELSE NULL
            END = u.id
            WHERE m.game_id = ?
            ORDER BY m.move_order DESC
            LIMIT 1";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $game_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Κλείσιμο σύνδεσης
$db->close();
?>