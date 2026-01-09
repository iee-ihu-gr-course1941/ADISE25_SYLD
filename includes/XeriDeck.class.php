<?php
// includes/XeriDeck.class.php

class XeriDeck {
    private $db;
    private $gameId;
    
    public function __construct($db, $gameId = null) {
        $this->db = $db;
        $this->gameId = $gameId;
    }
    
    // Αρχικοποίηση τράπουλας για νέο παιχνίδι
    public function initializeDeck() {
        $this->db->begin_transaction();
        
        try {
            // 1. Λήψη όλων των card_ids από το initial_deck (ανακατεμένες)
            $cardsSql = "SELECT card_id FROM initial_deck ORDER BY RAND()";
            $cardsResult = $this->db->query($cardsSql);
            
            $allCards = [];
            while ($row = $cardsResult->fetch_assoc()) {
                $allCards[] = $row['card_id'];
            }
            
            // 2. Μοίρασμα 6 καρτών στον κάθε παίκτη
            $player1Cards = array_slice($allCards, 0, 6);
            $player2Cards = array_slice($allCards, 6, 6);
            $stockCards = array_slice($allCards, 12);
            
            // 3. Εισαγωγή για player1
            $this->insertCards($player1Cards, 'hand_p1');
            
            // 4. Εισαγωγή για player2
            $this->insertCards($player2Cards, 'hand_p2');
            
            // 5. Εισαγωγή stock cards
            $this->insertCards($stockCards, 'stock');
            
            // 6. Ενημέρωση game_state με stock count
            $stockCount = count($stockCards);
            $this->updateGameState($stockCount);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Τράπουλα αρχικοποιήθηκε',
                'player1_cards' => count($player1Cards),
                'player2_cards' => count($player2Cards),
                'stock_cards' => $stockCount
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => 'Σφάλμα αρχικοποίησης τράπουλας: ' . $e->getMessage()
            ];
        }
    }
    
    // Εισαγωγή καρτών σε συγκεκριμένη τοποθεσία
    private function insertCards($cardIds, $location) {
        $position = 1;
        
        foreach ($cardIds as $cardId) {
            $isVisible = ($location == 'stock') ? false : true;
            
            $sql = "INSERT INTO game_cards (game_id, card_id, location, position_order, is_visible) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("iisii", $this->gameId, $cardId, $location, $position, $isVisible);
            $stmt->execute();
            $position++;
        }
    }
    
    // Ενημέρωση game_state
    private function updateGameState($stockCount) {
        // Έλεγχος αν υπάρχει ήδη εγγραφή
        $checkSql = "SELECT game_id FROM game_state WHERE game_id = ?";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->bind_param("i", $this->gameId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing
            $sql = "UPDATE game_state 
                    SET status = 'started', 
                        current_player_number = 1,
                        stock_count = ?,
                        player1_hand_size = 6,
                        player2_hand_size = 6,
                        last_change = NOW()
                    WHERE game_id = ?";
        } else {
            // Insert new
            $sql = "INSERT INTO game_state 
                    (game_id, status, current_player_number, stock_count, player1_hand_size, player2_hand_size, last_change) 
                    VALUES (?, 'started', 1, ?, 6, 6, NOW())";
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($checkResult->num_rows > 0) {
            $stmt->bind_param("ii", $stockCount, $this->gameId);
        } else {
            $stmt->bind_param("ii", $this->gameId, $stockCount);
        }
        
        $stmt->execute();
    }
    
    // Τράβηγμα κάρτας από το stock
    public function drawFromStock($playerNumber) {
        $this->db->begin_transaction();
        
        try {
            // 1. Βρίσκουμε την πάνω κάρτα από το stock
            $topCard = $this->getTopStockCard();
            
            if (!$topCard) {
                throw new Exception('Η τράπουλα άδειασε');
            }
            
            // 2. Μετακίνηση της κάρτας στο χέρι του παίκτη
            $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
            $this->moveCardToHand($topCard['card_id'], $handLocation);
            
            // 3. Ενημέρωση game_state
            $this->updateStockCount();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'card' => $topCard,
                'hand_location' => $handLocation
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Παίρνει την πάνω κάρτα από το stock
    private function getTopStockCard() {
        $sql = "SELECT gc.card_id, c.* 
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = 'stock'
                ORDER BY gc.position_order DESC
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    // Μετακίνηση κάρτας στο χέρι
    private function moveCardToHand($cardId, $handLocation) {
        // Βρίσκουμε το επόμενο position_order για το χέρι
        $positionSql = "SELECT COALESCE(MAX(position_order), 0) + 1 as next_position 
                        FROM game_cards 
                        WHERE game_id = ? AND location = ?";
        
        $posStmt = $this->db->prepare($positionSql);
        $posStmt->bind_param("is", $this->gameId, $handLocation);
        $posStmt->execute();
        $posResult = $posStmt->get_result();
        $nextPosition = $posResult->fetch_assoc()['next_position'];
        
        // Μετακίνηση της κάρτας
        $moveSql = "UPDATE game_cards 
                    SET location = ?,
                        position_order = ?,
                        is_visible = TRUE
                    WHERE game_id = ? AND card_id = ? AND location = 'stock'";
        
        $moveStmt = $this->db->prepare($moveSql);
        $moveStmt->bind_param("siii", $handLocation, $nextPosition, $this->gameId, $cardId);
        $moveStmt->execute();
    }
    
    // Ενημέρωση του stock count στο game_state
    private function updateStockCount() {
        $countSql = "SELECT COUNT(*) as stock_count FROM game_cards 
                     WHERE game_id = ? AND location = 'stock'";
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param("i", $this->gameId);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $stockCount = $countResult->fetch_assoc()['stock_count'];
        
        $updateSql = "UPDATE game_state 
                      SET stock_count = ?,
                          last_change = NOW()
                      WHERE game_id = ?";
        
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->bind_param("ii", $stockCount, $this->gameId);
        $updateStmt->execute();
    }
    
    // Παίξιμο κάρτας από το χέρι στο τραπέζι
    public function playCardToTable($cardId, $playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        
        // Βρίσκουμε το επόμενο position_order για το τραπέζι
        $positionSql = "SELECT COALESCE(MAX(position_order), 0) + 1 as next_position 
                        FROM game_cards 
                        WHERE game_id = ? AND location = 'table'";
        
        $posStmt = $this->db->prepare($positionSql);
        $posStmt->bind_param("i", $this->gameId);
        $posStmt->execute();
        $posResult = $posStmt->get_result();
        $nextPosition = $posResult->fetch_assoc()['next_position'];
        
        // Μετακίνηση της κάρτας
        $sql = "UPDATE game_cards 
                SET location = 'table',
                    position_order = ?,
                    is_visible = TRUE
                WHERE game_id = ? AND card_id = ? AND location = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("iiis", $nextPosition, $this->gameId, $cardId, $handLocation);
        $stmt->execute();
        
        // Ενημέρωση hand size στο game_state
        $this->updateHandSize($playerNumber);
        
        return [
            'success' => true,
            'position' => $nextPosition
        ];
    }
    
    // Παίρνει κάρτες από το τραπέζι
    public function takeCardsFromTable($cardIds, $playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        
        foreach ($cardIds as $cardId) {
            $sql = "UPDATE game_cards 
                    SET location = ?,
                        position_order = NULL,
                        is_visible = FALSE
                    WHERE game_id = ? AND card_id = ? AND location = 'table'";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("sii", $handLocation, $this->gameId, $cardId);
            $stmt->execute();
        }
        
        // Ενημέρωση hand size
        $this->updateHandSize($playerNumber);
        
        return [
            'success' => true,
            'taken_cards' => count($cardIds)
        ];
    }
    
    // Ενημέρωση του μεγέθους χεριού στο game_state
    private function updateHandSize($playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        
        $countSql = "SELECT COUNT(*) as hand_size FROM game_cards 
                     WHERE game_id = ? AND location = ?";
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->bind_param("is", $this->gameId, $handLocation);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $handSize = $countResult->fetch_assoc()['hand_size'];
        
        $field = ($playerNumber == 1) ? 'player1_hand_size' : 'player2_hand_size';
        
        $updateSql = "UPDATE game_state 
                      SET $field = ?,
                          last_change = NOW()
                      WHERE game_id = ?";
        
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->bind_param("ii", $handSize, $this->gameId);
        $updateStmt->execute();
    }
    
    // Παίρνει όλες τις κάρτες σε μια τοποθεσία
    public function getCardsByLocation($location) {
        $sql = "SELECT c.*, gc.position_order, gc.is_visible
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = ?
                ORDER BY gc.position_order";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $this->gameId, $location);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cards = [];
        while ($row = $result->fetch_assoc()) {
            $cards[] = $row;
        }
        
        return $cards;
    }
    
    // Παίρνει το χέρι ενός παίκτη
    public function getPlayerHand($playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        return $this->getCardsByLocation($handLocation);
    }
    
    // Παίρνει τις κάρτες στο τραπέζι
    public function getTableCards() {
        return $this->getCardsByLocation('table');
    }
    
    // Παίρνει τις κάρτες στο stock
    public function getStockCards() {
        return $this->getCardsByLocation('stock');
    }
    
    // Μετράει τις κάρτες σε μια τοποθεσία
    public function countCardsByLocation($location) {
        $sql = "SELECT COUNT(*) as count FROM game_cards 
                WHERE game_id = ? AND location = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $this->gameId, $location);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    // Παίρνει το stock size
    public function getStockSize() {
        return $this->countCardsByLocation('stock');
    }
    
    // Παίρνει το μέγεθος χεριού ενός παίκτη
    public function getPlayerHandSize($playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        return $this->countCardsByLocation($handLocation);
    }
    
    // Έλεγχος αν η τράπουλα είναι άδεια
    public function isStockEmpty() {
        return $this->getStockSize() == 0;
    }
    
    // Έλεγχος αν ο παίκτης έχει κενό χέρι
    public function isHandEmpty($playerNumber) {
        return $this->getPlayerHandSize($playerNumber) == 0;
    }
    
    // Παίρνει τις πληροφορίες μιας κάρτας
    public function getCardDetails($cardId) {
        $sql = "SELECT * FROM cards WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $cardId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    // Ανανέωση του gameId
    public function setGameId($gameId) {
        $this->gameId = $gameId;
    }
    
    // Παίρνει το gameId
    public function getGameId() {
        return $this->gameId;
    }
}
?>