<?php
// api/move.php (ΕΝΗΜΕΡΩΣΗ - πλήρες)
require_once '../db_config.php';
require_once '../includes/deck.class.php';

header('Content-Type: application/json');
session_start();

$action = $_POST['action'] ?? '';

// Έλεγχος αυθεντικοποίησης
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$userId = $_SESSION['user_id'];
$gameId = $_POST['game_id'] ?? 0;

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
        
    case 'get_possible_moves':
        getPossibleMoves();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function playCard() {
    global $userId, $gameId;
    
    $cardId = $_POST['card_id'] ?? 0;
    $claimedCardIds = $_POST['claimed_cards'] ?? [];
    
    // 1. Έλεγχος αν ο χρήστης είναι παίκτης
    $playerNumber = getPlayerNumber($gameId, $userId);
    if ($playerNumber === 0) {
        echo json_encode(['success' => false, 'message' => 'Not a player in this game']);
        return;
    }
    
    // 2. Έλεγχος αν είναι η σειρά του
    if (!isPlayerTurn($gameId, $playerNumber)) {
        echo json_encode(['success' => false, 'message' => 'Not your turn']);
        return;
    }
    
    // 3. Έλεγχος αν το φύλλο είναι στο χέρι του
    $deck = new Deck();
    $hand = $deck->getPlayerHand($gameId, $playerNumber);
    $cardInHand = false;
    foreach ($hand as $card) {
        if ($card['id'] == $cardId) {
            $cardInHand = true;
            break;
        }
    }
    
    if (!$cardInHand) {
        echo json_encode(['success' => false, 'message' => 'Card not in hand']);
        return;
    }
    
    // 4. Βασικός έλεγχος κανόνων
    $tableCards = $deck->getTableCards($gameId);
    
    // Αν παίζεις βαλέ, μπορείς να πάρεις όλα
    if ($deck->isValet($cardId)) {
        // Βαλέ - παίρνεις όλα τα τραπεζιακά
        $claimedCardIds = array_map(function($c) { return $c['id']; }, $tableCards);
    } 
    // Αν δεν είναι βαλέ και claimάρεις φύλλα, έλεγχος ταύτισης
    elseif (!empty($claimedCardIds)) {
        $playedCard = $deck->getCardValue($cardId);
        foreach ($claimedCardIds as $claimedId) {
            $claimedCard = $deck->getCardValue($claimedId);
            if (!$claimedCard || $claimedCard['rank'] !== $playedCard['rank']) {
                echo json_encode(['success' => false, 'message' => 'Cards do not match']);
                return;
            }
        }
    }
    
    // 5. Εκτέλεση κίνησης
    $db = getDBConnection();
    try {
        $db->begin_transaction();
        
        // Μετακίνηση φύλλου από χέρι σε τραπέζι
        $deck->moveCard($gameId, $cardId, 'hand_p' . $playerNumber, 'table', null);
        
        // Αν υπάρχουν claimed cards, μετακίνησέ τα
        if (!empty($claimedCardIds)) {
            foreach ($claimedCardIds as $claimedId) {
                $deck->moveCard($gameId, $claimedId, 'table', 'captured_p' . $playerNumber, null);
            }
            // Μετακίνηση και του φύλλου που παίχτηκε
            $deck->moveCard($gameId, $cardId, 'table', 'captured_p' . $playerNumber, null);
        }
        
        // Έλεγχος για ξερή
        $isXeri = (count($tableCards) === 1 && count($claimedCardIds) === 1);
        
        // Καταγραφή κίνησης
        $moveData = [
            'card_id' => $cardId,
            'claimed_cards' => $claimedCardIds,
            'is_xeri' => $isXeri
        ];
        
        $sql = "INSERT INTO moves (game_id, player_number, move_type, card_id, 
                move_data, move_order, created_at) 
                VALUES (?, ?, 'play_card', ?, ?, 
                (SELECT COALESCE(MAX(move_order), 0) + 1 FROM moves WHERE game_id = ?), NOW())";
        
        $stmt = $db->prepare($sql);
        $moveDataJson = json_encode($moveData);
        $stmt->bind_param("iiisi", $gameId, $playerNumber, $cardId, $moveDataJson, $gameId);
        $stmt->execute();
        
        // Ενημέρωση game_state
        $nextPlayer = ($playerNumber == 1) ? 2 : 1;
        updateGameState($gameId, $nextPlayer);
        
        // Αν είναι ξερή, καταγραφή
        if ($isXeri) {
            recordXeri($gameId, $playerNumber);
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Card played successfully',
            'is_xeri' => $isXeri,
            'next_player' => $nextPlayer,
            'claimed_cards' => $claimedCardIds
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function drawCard() {
    global $userId, $gameId;
    
    $playerNumber = getPlayerNumber($gameId, $userId);
    if ($playerNumber === 0) {
        echo json_encode(['success' => false, 'message' => 'Not a player in this game']);
        return;
    }
    
    if (!isPlayerTurn($gameId, $playerNumber)) {
        echo json_encode(['success' => false, 'message' => 'Not your turn']);
        return;
    }
    
    $deck = new Deck();
    $drawnCardId = $deck->drawFromStock($gameId, $playerNumber);
    
    if ($drawnCardId) {
        $db = getDBConnection();
        
        // Καταγραφή κίνησης
        $sql = "INSERT INTO moves (game_id, player_number, move_type, card_id, 
                move_order, created_at) 
                VALUES (?, ?, 'draw', ?, 
                (SELECT COALESCE(MAX(move_order), 0) + 1 FROM moves WHERE game_id = ?), NOW())";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iiii", $gameId, $playerNumber, $drawnCardId, $gameId);
        $stmt->execute();
        
        // Ενημέρωση game_state
        $nextPlayer = ($playerNumber == 1) ? 2 : 1;
        updateGameState($gameId, $nextPlayer);
        
        echo json_encode([
            'success' => true,
            'message' => 'Card drawn',
            'card_id' => $drawnCardId,
            'next_player' => $nextPlayer
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Stock is empty']);
    }
}

function passTurn() {
    global $userId, $gameId;
    
    $playerNumber = getPlayerNumber($gameId, $userId);
    if ($playerNumber === 0) {
        echo json_encode(['success' => false, 'message' => 'Not a player in this game']);
        return;
    }
    
    if (!isPlayerTurn($gameId, $playerNumber)) {
        echo json_encode(['success' => false, 'message' => 'Not your turn']);
        return;
    }
    
    $db = getDBConnection();
    
    $sql = "INSERT INTO moves (game_id, player_number, move_type, 
            move_order, created_at) 
            VALUES (?, ?, 'pass', 
            (SELECT COALESCE(MAX(move_order), 0) + 1 FROM moves WHERE game_id = ?), NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("isi", $gameId, $playerNumber, $gameId);
    $stmt->execute();
    
    $nextPlayer = ($playerNumber == 1) ? 2 : 1;
    updateGameState($gameId, $nextPlayer);
    
    echo json_encode([
        'success' => true,
        'message' => 'Turn passed',
        'next_player' => $nextPlayer
    ]);
}

function getPossibleMoves() {
    global $userId, $gameId;
    
    $playerNumber = getPlayerNumber($gameId, $userId);
    if ($playerNumber === 0) {
        echo json_encode(['success' => false, 'message' => 'Not a player in this game']);
        return;
    }
    
    $deck = new Deck();
    $hand = $deck->getPlayerHand($gameId, $playerNumber);
    $tableCards = $deck->getTableCards($gameId);
    
    $possibleMoves = [];
    
    foreach ($hand as $card) {
        $matchingCards = $deck->findMatchingCards($card['id'], $gameId);
        
        if (!empty($matchingCards)) {
            foreach ($matchingCards as $match) {
                $possibleMoves[] = [
                    'type' => 'capture',
                    'card' => $card,
                    'claimed' => $match,
                    'is_xeri' => (count($tableCards) === 1)
                ];
            }
        }
        
        // Βαλέ μπορεί να πάρει όλα τα φύλλα
        if ($deck->isValet($card['id']) && !empty($tableCards)) {
            $possibleMoves[] = [
                'type' => 'valet_capture',
                'card' => $card,
                'claimed' => $tableCards,
                'is_xeri' => false
            ];
        }
        
        // Απλή απόρριψη (χωρίς να πάρεις τίποτα)
        $possibleMoves[] = [
            'type' => 'discard',
            'card' => $card,
            'claimed' => [],
            'is_xeri' => false
        ];
    }
    
    echo json_encode([
        'success' => true,
        'possible_moves' => $possibleMoves
    ]);
}

// Βοηθητικές συναρτήσεις
function getPlayerNumber($gameId, $playerId) {
    $db = getDBConnection();
    
    $sql = "SELECT 
                CASE 
                    WHEN player1_id = ? THEN 1
                    WHEN player2_id = ? THEN 2
                    ELSE 0 
                END as player_number
            FROM games 
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iii", $playerId, $playerId, $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['player_number'];
    }
    
    return 0;
}

function isPlayerTurn($gameId, $playerNumber) {
    $db = getDBConnection();
    
    $sql = "SELECT current_player_number FROM game_state WHERE game_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['current_player_number'] == $playerNumber;
    }
    
    return false;
}

function updateGameState($gameId, $nextPlayer) {
    $db = getDBConnection();
    
    $sql = "UPDATE game_state 
            SET current_player_number = ?, last_change = NOW() 
            WHERE game_id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii", $nextPlayer, $gameId);
    $stmt->execute();
}

function recordXeri($gameId, $playerNumber) {
    $db = getDBConnection();
    
    // Προσθήκη ξερής στο game_state
    // ΠΡΟΣΘΗΚΗ ΣΤΟΝ ΠΙΝΑΚΑ game_state: ALTER TABLE game_state ADD COLUMN xeri_count INT DEFAULT 0;
    $sql = "UPDATE game_state 
            SET xeri_count = COALESCE(xeri_count, 0) + 1 
            WHERE game_id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
}
?>