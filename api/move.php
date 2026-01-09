<?php
// api/move.php
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
$action = $_POST['action'] ?? '';

// Βοηθητικές συναρτήσεις
function jsonResponse($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// Βοηθητική για να ελέγχει αν είναι η σειρά του παίκτη
function isPlayersTurn($db, $game_id, $user_id) {
    $sql = "SELECT g.current_turn_player_number,
                   CASE 
                       WHEN g.player1_id = ? THEN 1
                       WHEN g.player2_id = ? THEN 2
                       ELSE 0
                   END as player_number
            FROM games g
            WHERE g.id = ? AND g.status = 'active'";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iii", $user_id, $user_id, $game_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return ($row['player_number'] > 0 && 
                $row['current_turn_player_number'] == $row['player_number']);
    }
    
    return false;
}

// Βοηθητική για να παίρνει τον αριθμό παίκτη
function getPlayerNumber($db, $game_id, $user_id) {
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

// Βοηθητική για έλεγχο καρτών στο χέρι του παίκτη
function isCardInPlayerHand($db, $game_id, $user_id, $card_id) {
    $player_number = getPlayerNumber($db, $game_id, $user_id);
    if ($player_number == 0) return false;
    
    $hand_location = ($player_number == 1) ? 'hand_p1' : 'hand_p2';
    
    $sql = "SELECT id FROM game_cards 
            WHERE game_id = ? AND card_id = ? AND location = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iis", $game_id, $card_id, $hand_location);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Βοηθητική για να παίρνει την τελευταία κάρτα στο τραπέζι
function getTopTableCard($db, $game_id) {
    $sql = "SELECT c.* 
            FROM game_cards gc
            JOIN cards c ON gc.card_id = c.id
            WHERE gc.game_id = ? AND gc.location = 'table'
            ORDER BY gc.position_order DESC
            LIMIT 1";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $game_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Βοηθητική για έλεγχο αν μπορεί να πάρει κάρτες
function canClaimCards($db, $game_id, $played_card_id, &$claimable_cards = []) {
    // Παίρνουμε την κάρτα που παίζεται
    $cardSql = "SELECT * FROM cards WHERE id = ?";
    $cardStmt = $db->prepare($cardSql);
    $cardStmt->bind_param("i", $played_card_id);
    $cardStmt->execute();
    $played_card = $cardStmt->get_result()->fetch_assoc();
    
    if (!$played_card) return false;
    
    // Παίρνουμε όλες τις κάρτες στο τραπέζι
    $tableSql = "SELECT c.*, gc.id as game_card_id 
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
    
    $claimable_cards = [];
    
    // Κανόνες:
    // 1. Αν είναι Βαλές (J), παίρνει ΟΛΕΣ τις κάρτες
    if ($played_card['rank'] == 'J') {
        $claimable_cards = $table_cards;
        return 'valet_capture';
    }
    
    // 2. Αν υπάρχει κάρτα με ίδιο rank, παίρνει αυτήν την κάρτα
    foreach ($table_cards as $table_card) {
        if ($table_card['rank'] == $played_card['rank']) {
            $claimable_cards[] = $table_card;
        }
    }
    
    if (!empty($claimable_cards)) {
        return 'capture';
    }
    
    // 3. Αν δεν μπορεί να πάρει τίποτα, απλώς την πετάει
    return 'discard';
}

// Βοηθητική για να ελέγξει αν έγινε ξερή
function checkForXeri($db, $game_id, $player_number, $played_card, $claimed_cards) {
    // Μόνο αν πήρε ακριβώς 1 κάρτα και στο τραπέζι ήταν μόνο 1 κάρτα πριν
    if (count($claimed_cards) == 1) {
        // Μετράμε πόσες κάρτες ήταν στο τραπέζι πριν
        $countSql = "SELECT COUNT(*) as count FROM game_cards 
                     WHERE game_id = ? AND location = 'table' 
                     AND card_id != ?"; // Εξαιρούμε την τρέχουσα κάρτα
        
        $countStmt = $db->prepare($countSql);
        $countStmt->bind_param("ii", $game_id, $played_card['id']);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult->fetch_assoc();
        
        // Αν ήταν μόνο 1 κάρτα πριν (η κάρτα που πήρε) + ίδιο rank = ΞΕΡΗ
        if ($countRow['count'] == 1 && $claimed_cards[0]['rank'] == $played_card['rank']) {
            return true;
        }
    }
    
    return false;
}

switch ($action) {
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
        getValidMoves();
        break;
    case 'get_possible_moves':
        getPossibleMoves();
        break;
    default:
        jsonResponse(false, 'Invalid action: ' . $action);
}

function playCard() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    $card_id = $_POST['card_id'] ?? 0;
    $claimed_cards = $_POST['claimed_cards'] ?? []; // Array of card IDs to claim
    
    if (!$game_id || !$card_id) {
        jsonResponse(false, 'Game ID και Card ID απαιτούνται');
    }
    
    // Έλεγχος αν είναι η σειρά του παίκτη
    if (!isPlayersTurn($db, $game_id, $user_id)) {
        jsonResponse(false, 'Δεν είναι η σειρά σου!');
    }
    
    // Έλεγχος αν η κάρτα είναι στο χέρι του παίκτη
    if (!isCardInPlayerHand($db, $game_id, $user_id, $card_id)) {
        jsonResponse(false, 'Η κάρτα δεν είναι στο χέρι σου');
    }
    
    $db->begin_transaction();
    
    try {
        $player_number = getPlayerNumber($db, $game_id, $user_id);
        $player_hand = ($player_number == 1) ? 'hand_p1' : 'hand_p2';
        
        // 1. Παίρνουμε πληροφορίες για την κάρτα που παίζεται
        $cardSql = "SELECT * FROM cards WHERE id = ?";
        $cardStmt = $db->prepare($cardSql);
        $cardStmt->bind_param("i", $card_id);
        $cardStmt->execute();
        $played_card = $cardStmt->get_result()->fetch_assoc();
        
        // 2. Έλεγχος αν μπορεί να πάρει κάρτες
        $claimable_cards = [];
        $move_type = canClaimCards($db, $game_id, $card_id, $claimable_cards);
        
        // 3. Αν ο χρήστης ζήτησε να πάρει συγκεκριμένες κάρτες, ελέγχουμε αν είναι valid
        $actual_claimed_cards = [];
        $is_xeri = false;
        
        if (!empty($claimed_cards) && $move_type != 'discard') {
            // Φιλτράρουμε μόνο τις claimable cards που ζήτησε ο χρήστης
            foreach ($claimable_cards as $claimable) {
                if (in_array($claimable['id'], $claimed_cards)) {
                    $actual_claimed_cards[] = $claimable;
                }
            }
            
            // Έλεγχος για ξερή
            $is_xeri = checkForXeri($db, $game_id, $player_number, $played_card, $actual_claimed_cards);
        }
        
        // 4. Μετακίνηση της κάρτας από το χέρι στο τραπέζι
        $moveCardSql = "UPDATE game_cards 
                        SET location = 'table', 
                            position_order = (SELECT COALESCE(MAX(position_order), 0) + 1 
                                              FROM game_cards 
                                              WHERE game_id = ? AND location = 'table'),
                            is_visible = TRUE
                        WHERE game_id = ? AND card_id = ? AND location = ?";
        
        $moveStmt = $db->prepare($moveCardSql);
        $moveStmt->bind_param("iiis", $game_id, $game_id, $card_id, $player_hand);
        $moveStmt->execute();
        
        // 5. Αν πήρε κάρτες, τις μεταφέρουμε στο "χέρι" του (αλλά αόρατες - θα τις μετρήσουμε αργότερα)
        if (!empty($actual_claimed_cards)) {
            foreach ($actual_claimed_cards as $claimed_card) {
                $claimSql = "UPDATE game_cards 
                             SET location = ?,
                                 position_order = NULL,
                                 is_visible = FALSE
                             WHERE game_id = ? AND card_id = ? AND location = 'table'";
                
                $claimStmt = $db->prepare($claimSql);
                $claimStmt->bind_param("sii", $player_hand, $game_id, $claimed_card['id']);
                $claimStmt->execute();
            }
        }
        
        // 6. Εγγραφή της κίνησης στον πίνακα moves
        $move_data = [
            'claimed_cards' => array_column($actual_claimed_cards, 'id'),
            'move_type' => $move_type,
            'is_xeri' => $is_xeri
        ];
        
        $moveOrderSql = "SELECT COALESCE(MAX(move_order), 0) + 1 as next_order 
                         FROM moves WHERE game_id = ?";
        $orderStmt = $db->prepare($moveOrderSql);
        $orderStmt->bind_param("i", $game_id);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $next_order = $orderResult->fetch_assoc()['next_order'];
        
        $insertMoveSql = "INSERT INTO moves 
                         (game_id, player_number, move_type, card_id, move_data, move_order, created_at) 
                         VALUES (?, ?, 'discard_card', ?, ?, ?, NOW())";
        
        $moveStmt = $db->prepare($insertMoveSql);
        $move_type_desc = ($move_type == 'valet_capture') ? 'valet_capture' : 
                         ($move_type == 'capture' ? 'capture' : 'discard');
        $move_data_json = json_encode($move_data);
        $moveStmt->bind_param("iiisi", $game_id, $player_number, $card_id, $move_data_json, $next_order);
        $moveStmt->execute();
        
        // 7. Αλλαγή σειράς στον επόμενο παίκτη
        $next_player = ($player_number == 1) ? 2 : 1;
        $updateTurnSql = "UPDATE games 
                          SET current_turn_player_number = ? 
                          WHERE id = ?";
        
        $turnStmt = $db->prepare($updateTurnSql);
        $turnStmt->bind_param("ii", $next_player, $game_id);
        $turnStmt->execute();
        
        // 8. Ενημέρωση game_state
        // Υπολογισμός μεγέθους χεριού
        $handSizeSql = "SELECT COUNT(*) as size FROM game_cards 
                        WHERE game_id = ? AND location = ?";
        
        $handSizeStmt = $db->prepare($handSizeSql);
        $handSizeStmt->bind_param("is", $game_id, $player_hand);
        $handSizeStmt->execute();
        $handSizeResult = $handSizeStmt->get_result();
        $hand_size = $handSizeResult->fetch_assoc()['size'];
        
        // Ενημέρωση
        $stateSql = "UPDATE game_state 
                     SET current_player_number = ?,
                         " . ($player_number == 1 ? 'player1_hand_size' : 'player2_hand_size') . " = ?,
                         last_change = NOW()
                     WHERE game_id = ?";
        
        $stateStmt = $db->prepare($stateSql);
        $stateStmt->bind_param("iii", $next_player, $hand_size, $game_id);
        $stateStmt->execute();
        
        $db->commit();
        
        jsonResponse(true, 'Κάρτα παίχτηκε επιτυχώς!', [
            'is_xeri' => $is_xeri,
            'claimed_count' => count($actual_claimed_cards),
            'move_type' => $move_type,
            'next_player' => $next_player
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function drawCard() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Έλεγχος αν είναι η σειρά του παίκτη
    if (!isPlayersTurn($db, $game_id, $user_id)) {
        jsonResponse(false, 'Δεν είναι η σειρά σου!');
    }
    
    $db->begin_transaction();
    
    try {
        $player_number = getPlayerNumber($db, $game_id, $user_id);
        $player_hand = ($player_number == 1) ? 'hand_p1' : 'hand_p2';
        
        // 1. Βρίσκουμε την πάνω κάρτα από το stock (με το μεγαλύτερο position_order)
        $stockSql = "SELECT gc.card_id, c.* 
                     FROM game_cards gc
                     JOIN cards c ON gc.card_id = c.id
                     WHERE gc.game_id = ? AND gc.location = 'stock'
                     ORDER BY gc.position_order DESC
                     LIMIT 1";
        
        $stockStmt = $db->prepare($stockSql);
        $stockStmt->bind_param("i", $game_id);
        $stockStmt->execute();
        $stockResult = $stockStmt->get_result();
        
        if ($stockResult->num_rows === 0) {
            throw new Exception('Η τράπουλα άδειασε!');
        }
        
        $drawn_card = $stockResult->fetch_assoc();
        $drawn_card_id = $drawn_card['card_id'];
        
        // 2. Μετακίνηση της κάρτας από stock στο χέρι του παίκτη
        $moveSql = "UPDATE game_cards 
                    SET location = ?,
                        position_order = (SELECT COALESCE(MAX(position_order), 0) + 1 
                                          FROM game_cards 
                                          WHERE game_id = ? AND location = ?),
                        is_visible = TRUE
                    WHERE game_id = ? AND card_id = ? AND location = 'stock'";
        
        $moveStmt = $db->prepare($moveSql);
        $moveStmt->bind_param("sisii", $player_hand, $game_id, $player_hand, $game_id, $drawn_card_id);
        $moveStmt->execute();
        
        // 3. Εγγραφή της κίνησης
        $moveOrderSql = "SELECT COALESCE(MAX(move_order), 0) + 1 as next_order 
                         FROM moves WHERE game_id = ?";
        $orderStmt = $db->prepare($moveOrderSql);
        $orderStmt->bind_param("i", $game_id);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $next_order = $orderResult->fetch_assoc()['next_order'];
        
        $insertMoveSql = "INSERT INTO moves 
                         (game_id, player_number, move_type, card_id, move_order, created_at) 
                         VALUES (?, ?, 'draw_from_stock', ?, ?, NOW())";
        
        $moveStmt = $db->prepare($insertMoveSql);
        $moveStmt->bind_param("iii", $game_id, $player_number, $drawn_card_id, $next_order);
        $moveStmt->execute();
        
        // 4. Αλλαγή σειράς στον επόμενο παίκτη
        $next_player = ($player_number == 1) ? 2 : 1;
        $updateTurnSql = "UPDATE games 
                          SET current_turn_player_number = ? 
                          WHERE id = ?";
        
        $turnStmt = $db->prepare($updateTurnSql);
        $turnStmt->bind_param("ii", $next_player, $game_id);
        $turnStmt->execute();
        
        // 5. Ενημέρωση game_state
        // Υπολογισμός stock size
        $stockSizeSql = "SELECT COUNT(*) as size FROM game_cards 
                         WHERE game_id = ? AND location = 'stock'";
        
        $stockSizeStmt = $db->prepare($stockSizeSql);
        $stockSizeStmt->bind_param("i", $game_id);
        $stockSizeStmt->execute();
        $stockSizeResult = $stockSizeStmt->get_result();
        $stock_size = $stockSizeResult->fetch_assoc()['size'];
        
        // Υπολογισμός hand size
        $handSizeSql = "SELECT COUNT(*) as size FROM game_cards 
                        WHERE game_id = ? AND location = ?";
        
        $handSizeStmt = $db->prepare($handSizeSql);
        $handSizeStmt->bind_param("is", $game_id, $player_hand);
        $handSizeStmt->execute();
        $handSizeResult = $handSizeStmt->get_result();
        $hand_size = $handSizeResult->fetch_assoc()['size'];
        
        // Ενημέρωση
        $stateSql = "UPDATE game_state 
                     SET current_player_number = ?,
                         stock_count = ?,
                         " . ($player_number == 1 ? 'player1_hand_size' : 'player2_hand_size') . " = ?,
                         last_change = NOW()
                     WHERE game_id = ?";
        
        $stateStmt = $db->prepare($stateSql);
        $stateStmt->bind_param("iiii", $next_player, $stock_size, $hand_size, $game_id);
        $stateStmt->execute();
        
        $db->commit();
        
        jsonResponse(true, 'Τράβηξες μια κάρτα!', [
            'drawn_card' => $drawn_card,
            'stock_size' => $stock_size,
            'next_player' => $next_player
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        jsonResponse(false, 'Σφάλμα: ' . $e->getMessage());
    }
}

function passTurn() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Έλεγχος αν είναι η σειρά του παίκτη
    if (!isPlayersTurn($db, $game_id, $user_id)) {
        jsonResponse(false, 'Δεν είναι η σειρά σου!');
    }
    
    $player_number = getPlayerNumber($db, $game_id, $user_id);
    $next_player = ($player_number == 1) ? 2 : 1;
    
    // 1. Αλλαγή σειράς
    $updateTurnSql = "UPDATE games 
                      SET current_turn_player_number = ? 
                      WHERE id = ?";
    
    $turnStmt = $db->prepare($updateTurnSql);
    $turnStmt->bind_param("ii", $next_player, $game_id);
    $turnStmt->execute();
    
    // 2. Εγγραφή της κίνησης
    $moveOrderSql = "SELECT COALESCE(MAX(move_order), 0) + 1 as next_order 
                     FROM moves WHERE game_id = ?";
    $orderStmt = $db->prepare($moveOrderSql);
    $orderStmt->bind_param("i", $game_id);
    $orderStmt->execute();
    $orderResult = $orderStmt->get_result();
    $next_order = $orderResult->fetch_assoc()['next_order'];
    
    $insertMoveSql = "INSERT INTO moves 
                     (game_id, player_number, move_type, move_order, created_at) 
                     VALUES (?, ?, 'pass', ?, NOW())";
    
    $moveStmt = $db->prepare($insertMoveSql);
    $moveStmt->bind_param("iii", $game_id, $player_number, $next_order);
    $moveStmt->execute();
    
    // 3. Ενημέρωση game_state
    $stateSql = "UPDATE game_state 
                 SET current_player_number = ?,
                     last_change = NOW()
                 WHERE game_id = ?";
    
    $stateStmt = $db->prepare($stateSql);
    $stateStmt->bind_param("ii", $next_player, $game_id);
    $stateStmt->execute();
    
    jsonResponse(true, 'Παρατήσατε τη σειρά σας', [
        'next_player' => $next_player
    ]);
}

function getValidMoves() {
    global $db, $user_id;
    
    $game_id = $_POST['game_id'] ?? $_GET['game_id'] ?? 0;
    
    if (!$game_id) {
        jsonResponse(false, 'Game ID required');
    }
    
    // Έλεγχος αν είναι η σειρά του παίκτη
    if (!isPlayersTurn($db, $game_id, $user_id)) {
        jsonResponse(false, 'Δεν είναι η σειρά σου');
    }
    
    $player_number = getPlayerNumber($db, $game_id, $user_id);
    $player_hand = ($player_number == 1) ? 'hand_p1' : 'hand_p2';
    
    // 1. Παίρνουμε όλες τις κάρτες στο χέρι του παίκτη
    $handSql = "SELECT c.* 
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = ?
                ORDER BY gc.position_order";
    
    $handStmt = $db->prepare($handSql);
    $handStmt->bind_param("is", $game_id, $player_hand);
    $handStmt->execute();
    $handResult = $handStmt->get_result();
    
    $hand_cards = [];
    while ($row = $handResult->fetch_assoc()) {
        $hand_cards[] = $row;
    }
    
    // 2. Για κάθε κάρτα, ελέγχουμε τι μπορεί να κάνει
    $valid_moves = [];
    
    foreach ($hand_cards as $card) {
        $claimable_cards = [];
        $move_type = canClaimCards($db, $game_id, $card['id'], $claimable_cards);
        
        $valid_moves[] = [
            'card' => $card,
            'move_type' => $move_type,
            'claimable_cards' => $claimable_cards,
            'is_xeri_possible' => ($move_type == 'capture' && count($claimable_cards) == 1) ? 
                                   checkForXeri($db, $game_id, $player_number, $card, $claimable_cards) : false
        ];
    }
    
    // 3. Έλεγχος αν μπορεί να τραβήξει από το stock
    $stockSql = "SELECT COUNT(*) as stock_size FROM game_cards 
                 WHERE game_id = ? AND location = 'stock'";
    
    $stockStmt = $db->prepare($stockSql);
    $stockStmt->bind_param("i", $game_id);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    $stock_size = $stockResult->fetch_assoc()['stock_size'];
    
    $can_draw = ($stock_size > 0);
    
    jsonResponse(true, 'Valid moves loaded', [
        'hand_cards' => $hand_cards,
        'valid_moves' => $valid_moves,
        'can_draw' => $can_draw,
        'stock_size' => $stock_size
    ]);
}

function getPossibleMoves() {
    // Αυτή είναι απλοποιημένη έκδοση για το frontend
    getValidMoves();
}

$db->close();
?>