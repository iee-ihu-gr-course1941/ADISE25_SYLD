<?php
// api/game.php
require_once '../db_config.php';
require_once '../includes/deck.class.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    // ... existing cases ...
    
    case 'create_game':
        createGame();
        break;
        
    case 'get_game_state':
        getGameState();
        break;
        
    case 'join_game':
        joinGame();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function createGame() {
    global $db;
    
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        return;
    }
    
    $player1Id = $_SESSION['user_id'];
    $gameType = $_POST['game_type'] ?? 'human-human';
    $difficulty = $_POST['difficulty'] ?? 'medium';
    
    // Δημιουργία νέου παιχνιδιού
    $sql = "INSERT INTO games (player1_id, game_type, ai_difficulty, status) 
            VALUES (?, ?, ?, 'waiting')";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iss", $player1Id, $gameType, $difficulty);
    
    if ($stmt->execute()) {
        $gameId = $db->insert_id;
        
        // Αν είναι vs computer, δημιούργησε ψεύτικο player2 (computer)
        if ($gameType === 'human-computer') {
            // Δημιουργία guest user για τον computer
            $guestUsername = 'computer_' . time();
            $guestSql = "INSERT INTO users (username, is_guest) VALUES (?, 1)";
            $guestStmt = $db->prepare($guestSql);
            $guestStmt->bind_param("s", $guestUsername);
            $guestStmt->execute();
            $computerId = $db->insert_id;
            
            // Ενημέρωση του game με τον computer ως player2
            $updateSql = "UPDATE games SET player2_id = ?, status = 'active' WHERE id = ?";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->bind_param("ii", $computerId, $gameId);
            $updateStmt->execute();
            
            // Αρχικοποίηση τράπουλας
            $deck = new Deck();
            if ($deck->initializeGameDeck($gameId)) {
                // Αρχικοποίηση game_state
                initializeGameState($gameId, $player1Id, $computerId);
                
                echo json_encode([
                    'success' => true,
                    'game_id' => $gameId,
                    'player_number' => 1,
                    'message' => 'Game created vs computer'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to initialize deck']);
            }
            
        } else {
            // Human vs Human - περιμένει δεύτερο παίκτη
            echo json_encode([
                'success' => true,
                'game_id' => $gameId,
                'player_number' => 1,
                'message' => 'Game created. Waiting for opponent...'
            ]);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create game']);
    }
}

function getGameState() {
    $gameId = $_GET['game_id'] ?? $_POST['game_id'] ?? 0;
    
    if ($gameId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid game ID']);
        return;
    }
    
    session_start();
    $playerId = $_SESSION['user_id'] ?? 0;
    
    if ($playerId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        return;
    }
    
    $deck = new Deck();
    
    // Βρες τον αριθμό του παίκτη (1 ή 2)
    $playerNumber = getPlayerNumber($gameId, $playerId);
    
    if ($playerNumber === 0) {
        echo json_encode(['success' => false, 'message' => 'Not a player in this game']);
        return;
    }
    
    // Πάρε το χέρι του παίκτη
    $hand = $deck->getPlayerHand($gameId, $playerNumber);
    
    // Πάρε τα τραπεζιακά φύλλα
    $tableCards = $deck->getTableCards($gameId);
    
    // Πάρε το μέγεθος του stock
    $stockSize = $deck->getStockSize($gameId);
    
    // Πάρε την τρέχουσα κατάσταση από τον πίνακα game_state
    $gameState = getFullGameState($gameId);
    
    echo json_encode([
        'success' => true,
        'game_state' => [
            'game_id' => $gameId,
            'player_number' => $playerNumber,
            'hand' => $hand,
            'table_cards' => $tableCards,
            'stock_size' => $stockSize,
            'current_turn' => $gameState['current_player_number'] ?? 1,
            'phase' => $gameState['phase'] ?? 'draw',
            'status' => $gameState['status'] ?? 'active'
        ]
    ]);
}

function joinGame() {
    global $db;
    
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        return;
    }
    
    $player2Id = $_SESSION['user_id'];
    $gameId = $_POST['game_id'] ?? 0;
    
    if ($gameId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid game ID']);
        return;
    }
    
    // Έλεγξε αν το παιχνίδι περιμένει παίκτη
    $checkSql = "SELECT id, player1_id, status FROM games 
                 WHERE id = ? AND status = 'waiting' AND game_type = 'human-human'";
    
    $checkStmt = $db->prepare($checkSql);
    $checkStmt->bind_param("i", $gameId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($game = $result->fetch_assoc()) {
        // Έλεγξε ότι ο παίκτης δεν είναι ο ίδιος
        if ($game['player1_id'] == $player2Id) {
            echo json_encode(['success' => false, 'message' => 'Cannot play against yourself']);
            return;
        }
        
        // Ενημέρωση του game με τον δεύτερο παίκτη
        $updateSql = "UPDATE games SET player2_id = ?, status = 'active', started_at = NOW() 
                      WHERE id = ?";
        
        $updateStmt = $db->prepare($updateSql);
        $updateStmt->bind_param("ii", $player2Id, $gameId);
        
        if ($updateStmt->execute()) {
            // Αρχικοποίηση τράπουλας
            $deck = new Deck();
            if ($deck->initializeGameDeck($gameId)) {
                // Αρχικοποίηση game_state
                initializeGameState($gameId, $game['player1_id'], $player2Id);
                
                echo json_encode([
                    'success' => true,
                    'game_id' => $gameId,
                    'player_number' => 2,
                    'message' => 'Joined game successfully'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to initialize deck']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to join game']);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Game not available for joining']);
    }
}

// Βοηθητικές συναρτήσεις

function getPlayerNumber($gameId, $playerId) {
    global $db;
    
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

function initializeGameState($gameId, $player1Id, $player2Id) {
    global $db;
    
    $sql = "INSERT INTO game_state (game_id, status, current_player_number, 
            player1_hand_size, player2_hand_size, stock_count) 
            VALUES (?, 'active', 1, 6, 6, 40)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
}

function getFullGameState($gameId) {
    global $db;
    
    $sql = "SELECT * FROM game_state WHERE game_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return [];
}
?>