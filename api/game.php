<?php
// api/game.php
require_once '../db_config.php';

header('Content-Type: application/json');
session_start();

// Έλεγχος αν ο χρήστης είναι συνδεδεμένος
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated', 'redirect' => 'index.php']);
    exit();
}

$db = getDBConnection();
$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Βοηθητική συνάρτηση για responses
function jsonResponse($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// Βοηθητική για να τσεκάρει αν ο χρήστης είναι σε game
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

switch ($action) {
    case 'create_game':
        createGame();
        break;
    case 'get_game_state':
        getGameState();
        break;
    case 'join_game':
        joinGame();
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
    case 'get_available_games':
        getAvailableGames();
        break;
    default:
        jsonResponse(false, 'Invalid action: ' . $action);
}

function createGame() {
    global $db, $user_id;
    
    $game_type = $_POST['game_type'] ?? 'human-computer';
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
    
    // Δημιουργία νέου παιχνιδιού
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
        
        // 2. Αν είναι vs computer, δημιουργούμε guest account για τον computer
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
            initializeGameDeck($db, $game_id);
            
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

function getGameState() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? $_GET['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Έλεγχος πρόσβασης
    if (!isUserInGame($db, $user_id, $game_id)) {
        jsonResponse(false, 'Δεν έχετε πρόσβαση σε αυτό το παιχνίδι');
    }
    
    // 1. Βασικές πληροφορίες παιχνιδιού
    $gameSql = "SELECT g.*, 
                       u1.username as player1_name,
                       u2.username as player2_name,
                       CASE 
                           WHEN g.player1_id = ? THEN 1
                           WHEN g.player2_id = ? THEN 2
                           ELSE 0
                       END as my_player_number
                FROM games g
                LEFT JOIN users u1 ON g.player1_id = u1.id
                LEFT JOIN users u2 ON g.player2_id = u2.id
                WHERE g.id = ?";
    
    $gameStmt = $db->prepare($gameSql);
    $gameStmt->bind_param("iii", $user_id, $user_id, $game_id);
    $gameStmt->execute();
    $gameResult = $gameStmt->get_result();
    
    if ($gameResult->num_rows === 0) {
        jsonResponse(false, 'Το παιχνίδι δεν βρέθηκε');
    }
    
    $game = $gameResult->fetch_assoc();
    $my_player_number = $game['my_player_number'];
    
    // 2. Χέρι του παίκτη
    $hand_location = ($my_player_number == 1) ? 'hand_p1' : 'hand_p2';
    $handSql = "SELECT c.* 
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = ?
                ORDER BY gc.position_order";
    
    $handStmt = $db->prepare($handSql);
    $handStmt->bind_param("is", $game_id, $hand_location);
    $handStmt->execute();
    $handResult = $handStmt->get_result();
    
    $hand = [];
    while ($row = $handResult->fetch_assoc()) {
        $hand[] = $row;
    }
    
    // 3. Κάρτες στο τραπέζι
    $tableSql = "SELECT c.* 
                 FROM game_cards gc
                 JOIN cards c ON gc.card_id = c.id
                 WHERE gc.game_id = ? AND gc.location = 'table'
                 ORDER BY gc.position_order";
    
    $tableStmt = $db->prepare($tableSql);
    $tableStmt->bind_param("i", $game_id);
    $tableStmt->execute();
    $tableResult = $tableStmt->get_result();
    
    $table_cards = [];
    while ($row = $tableResult->fetch_assoc()) {
        $table_cards[] = $row;
    }
    
    // 4. Στατιστικά
    $statsSql = "SELECT 
                    COUNT(CASE WHEN location = 'stock' THEN 1 END) as stock_size,
                    COUNT(CASE WHEN location = ? THEN 1 END) as my_hand_size,
                    COUNT(CASE WHEN location = ? THEN 1 END) as opponent_hand_size
                 FROM game_cards 
                 WHERE game_id = ?";
    
    $opponent_hand_location = ($my_player_number == 1) ? 'hand_p2' : 'hand_p1';
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->bind_param("ssi", $hand_location, $opponent_hand_location, $game_id);
    $statsStmt->execute();
    $statsResult = $statsStmt->get_result();
    $stats = $statsResult->fetch_assoc();
    
    // 5. Game state
    $stateSql = "SELECT * FROM game_state WHERE game_id = ?";
    $stateStmt = $db->prepare($stateSql);
    $stateStmt->bind_param("i", $game_id);
    $stateStmt->execute();
    $stateResult = $stateStmt->get_result();
    $game_state = $stateResult->fetch_assoc() ?? [];
    
    // Προσαρμογή δεδομένων για το frontend
    $response_data = [
        'game_id' => $game_id,
        'status' => $game['status'],
        'game_type' => $game['game_type'],
        'current_turn' => $game['current_turn_player_number'],
        'my_player_number' => $my_player_number,
        'player1_name' => $game['player1_name'],
        'player2_name' => $game['player2_name'],
        'hand' => $hand,
        'table_cards' => $table_cards,
        'stock_size' => $stats['stock_size'] ?? 52,
        'my_hand_size' => $stats['my_hand_size'] ?? 0,
        'opponent_hand_size' => $stats['opponent_hand_size'] ?? 0,
        'my_score' => 0, // Θα το προσθέσουμε αργότερα
        'opponent_score' => 0, // Θα το προσθέσουμε αργότερα
        'can_i_play' => ($game['current_turn_player_number'] == $my_player_number),
        'game_state' => $game_state
    ];
    
    jsonResponse(true, 'Game state loaded', $response_data);
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
            initializeGameDeck($db, $game_id);
            
            // Ενημέρωση game_state
            $stateSql = "UPDATE game_state 
                         SET status = 'started', 
                             current_player_number = 1,
                             stock_count = 40 -- 52 - (6*2)
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

function claimXeri() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id || !isUserInGame($db, $user_id, $game_id)) {
        jsonResponse(false, 'Invalid game or no access');
    }
    
    // TODO: Υλοποίηση λογικής για να ελέγξουμε αν πραγματικά έγινε ξερή
    // Για τώρα, απλά επιστρέφουμε επιτυχία
    
    jsonResponse(true, 'Ξερή καταγράφηκε!');
}

// Βοηθητική συνάρτηση για αρχικοποίηση τράπουλας
function initializeGameDeck($db, $game_id) {
    // 1. Λήψη όλων των καρτών από το initial_deck (ανακατεμένες)
    $cardsSql = "SELECT card_id FROM initial_deck ORDER BY RAND()";
    $cardsResult = $db->query($cardsSql);
    
    $cards = [];
    while ($row = $cardsResult->fetch_assoc()) {
        $cards[] = $row['card_id'];
    }
    
    // 2. Μοίρασμα 6 καρτών στον κάθε παίκτη
    $player1_cards = array_slice($cards, 0, 6);
    $player2_cards = array_slice($cards, 6, 6);
    $stock_cards = array_slice($cards, 12);
    
    // 3. Εισαγωγή καρτών για player1
    $position = 1;
    foreach ($player1_cards as $card_id) {
        $sql = "INSERT INTO game_cards (game_id, card_id, location, position_order, is_visible) 
                VALUES (?, ?, 'hand_p1', ?, FALSE)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iii", $game_id, $card_id, $position);
        $stmt->execute();
        $position++;
    }
    
    // 4. Εισαγωγή καρτών για player2
    $position = 1;
    foreach ($player2_cards as $card_id) {
        $sql = "INSERT INTO game_cards (game_id, card_id, location, position_order, is_visible) 
                VALUES (?, ?, 'hand_p2', ?, FALSE)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iii", $game_id, $card_id, $position);
        $stmt->execute();
        $position++;
    }
    
    // 5. Εισαγωγή stock cards
    $position = 1;
    foreach ($stock_cards as $card_id) {
        $sql = "INSERT INTO game_cards (game_id, card_id, location, position_order, is_visible) 
                VALUES (?, ?, 'stock', ?, FALSE)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iii", $game_id, $card_id, $position);
        $stmt->execute();
        $position++;
    }
    
    // 6. Δημιουργία/εξασφάλιση game_state
    $stateSql = "INSERT INTO game_state (game_id, status, current_player_number, stock_count, player1_hand_size, player2_hand_size) 
                 VALUES (?, 'started', 1, ?, 6, 6) 
                 ON DUPLICATE KEY UPDATE 
                 status = 'started', 
                 current_player_number = 1,
                 stock_count = ?,
                 player1_hand_size = 6,
                 player2_hand_size = 6";
    
    $stock_count = count($stock_cards);
    $stateStmt = $db->prepare($stateSql);
    $stateStmt->bind_param("iii", $game_id, $stock_count, $stock_count);
    $stateStmt->execute();
}

$db->close();
?>