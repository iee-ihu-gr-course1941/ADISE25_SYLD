<?php
// includes/XeriGame.class.php

class XeriGame {
    private $db;
    private $gameId;
    private $playerNumber;
    private $gameData;
    
    // Τύποι κινήσεων για το Ξερί
    const MOVE_DRAW_STOCK = 'draw_from_stock';
    const MOVE_PLAY_CARD = 'play_card';
    const MOVE_PASS = 'pass';
    
    // Καταστάσεις παιχνιδιού
    const GAME_WAITING = 'waiting';
    const GAME_ACTIVE = 'active';
    const GAME_FINISHED = 'finished';
    
    public function __construct($db, $gameId, $playerNumber = null) {
        $this->db = $db;
        $this->gameId = $gameId;
        $this->playerNumber = $playerNumber;
        $this->loadGameData();
    }
    
    // Φόρτωση βασικών πληροφοριών παιχνιδιού
    private function loadGameData() {
        $sql = "SELECT g.*, 
                       u1.username as player1_name,
                       u2.username as player2_name,
                       gs.current_player_number,
                       gs.stock_count,
                       gs.player1_hand_size,
                       gs.player2_hand_size
                FROM games g
                LEFT JOIN users u1 ON g.player1_id = u1.id
                LEFT JOIN users u2 ON g.player2_id = u2.id
                LEFT JOIN game_state gs ON g.id = gs.game_id
                WHERE g.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $this->gameData = $result->fetch_assoc();
        }
    }
    
    // ============================================
    // 1. ΒΑΣΙΚΕΣ ΠΛΗΡΟΦΟΡΙΕΣ
    // ============================================
    
    public function getGameInfo() {
        return [
            'game_id' => $this->gameId,
            'status' => $this->gameData['status'] ?? self::GAME_WAITING,
            'current_turn' => $this->gameData['current_player_number'] ?? 1,
            'player1_name' => $this->gameData['player1_name'] ?? 'Player 1',
            'player2_name' => $this->gameData['player2_name'] ?? 'Player 2',
            'game_type' => $this->gameData['game_type'] ?? 'human-human',
            'stock_count' => $this->gameData['stock_count'] ?? 52
        ];
    }
    
    public function isMyTurn() {
        if (!$this->playerNumber) return false;
        return ($this->gameData['current_player_number'] ?? 0) == $this->playerNumber;
    }
    
    // ============================================
    // 2. ΧΕΡΙ ΠΑΙΚΤΗ
    // ============================================
    
    public function getPlayerHand($playerNumber = null) {
        $playerNum = $playerNumber ?? $this->playerNumber;
        if (!$playerNum) return [];
        
        $handLocation = ($playerNum == 1) ? 'hand_p1' : 'hand_p2';
        
        $sql = "SELECT c.id, c.suit, c.rank, c.value, c.symbol
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = ?
                ORDER BY gc.position_order";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $this->gameId, $handLocation);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $hand = [];
        while ($row = $result->fetch_assoc()) {
            $hand[] = $row;
        }
        
        return $hand;
    }
    
    // ============================================
    // 3. ΚΑΡΤΕΣ ΣΤΟ ΤΡΑΠΕΖΙ
    // ============================================
    
    public function getTableCards() {
        $sql = "SELECT c.id, c.suit, c.rank, c.value, c.symbol
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = 'table'
                ORDER BY gc.position_order";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tableCards = [];
        while ($row = $result->fetch_assoc()) {
            $tableCards[] = $row;
        }
        
        return $tableCards;
    }
    
    public function getTopTableCard() {
        $sql = "SELECT c.id, c.suit, c.rank, c.value, c.symbol
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = 'table'
                ORDER BY gc.position_order DESC
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    // ============================================
    // 4. ΕΛΕΓΧΟΣ ΚΑΝΟΝΩΝ ΞΕΡΙΟΥ
    // ============================================
    
    /**
     * Ελέγχει αν μια κάρτα μπορεί να πάρει άλλες κάρτες από το τραπέζι
     * Επιστρέφει array με τα IDs των καρτών που μπορεί να πάρει
     */
    public function getClaimableCards($playedCardId) {
        // Παίρνουμε την κάρτα που θα παιχτεί
        $cardSql = "SELECT * FROM cards WHERE id = ?";
        $cardStmt = $this->db->prepare($cardSql);
        $cardStmt->bind_param("i", $playedCardId);
        $cardStmt->execute();
        $playedCard = $cardStmt->get_result()->fetch_assoc();
        
        if (!$playedCard) return [];
        
        // Παίρνουμε όλες τις κάρτες στο τραπέζι
        $tableCards = $this->getTableCards();
        $claimableCards = [];
        
        // ΚΑΝΟΝΑΣ 1: Αν είναι Βαλές (J), παίρνει ΟΛΕΣ τις κάρτες
        if ($playedCard['rank'] == 'J') {
            return $tableCards; // Επιστρέφει όλες τις κάρτες
        }
        
        // ΚΑΝΟΝΑΣ 2: Παίρνει κάρτες με ίδιο rank
        foreach ($tableCards as $tableCard) {
            if ($tableCard['rank'] == $playedCard['rank']) {
                $claimableCards[] = $tableCard;
            }
        }
        
        return $claimableCards;
    }
    
    /**
     * Ελέγχει αν μια κίνηση είναι ξερή
     */
    public function isXeriMove($playedCardId, $claimedCards) {
        // Για να είναι ξερή:
        // 1. Πρέπει να πήρε ακριβώς 1 κάρτα
        if (count($claimedCards) != 1) {
            return false;
        }
        
        // 2. Η κάρτα που πήρε πρέπει να έχει ίδιο rank με αυτή που έπαιξε
        $cardSql = "SELECT rank FROM cards WHERE id = ?";
        $cardStmt = $this->db->prepare($cardSql);
        $cardStmt->bind_param("i", $playedCardId);
        $cardStmt->execute();
        $playedCardRank = $cardStmt->get_result()->fetch_assoc()['rank'];
        
        if ($claimedCards[0]['rank'] != $playedCardRank) {
            return false;
        }
        
        // 3. Στο τραπέζι πρέπει να υπήρχε ΜΟΝΟ 1 κάρτα πριν (η κάρτα που πήρε)
        $tableCountSql = "SELECT COUNT(*) as count FROM game_cards 
                          WHERE game_id = ? AND location = 'table' 
                          AND card_id != ?";
        $countStmt = $this->db->prepare($tableCountSql);
        $countStmt->bind_param("ii", $this->gameId, $claimedCards[0]['id']);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        
        return ($countResult['count'] == 1);
    }
    
    // ============================================
    // 5. ΚΙΝΗΣΕΙΣ ΠΑΙΚΤΩΝ
    // ============================================
    
    /**
     * Τραβάει κάρτα από το stock
     */
    public function drawFromStock($playerNumber) {
        $this->db->begin_transaction();
        
        try {
            // Βρίσκουμε την πάνω κάρτα από το stock
            $stockSql = "SELECT gc.id as game_card_id, gc.card_id
                        FROM game_cards gc
                        WHERE gc.game_id = ? AND gc.location = 'stock'
                        ORDER BY gc.position_order DESC
                        LIMIT 1";
            
            $stockStmt = $this->db->prepare($stockSql);
            $stockStmt->bind_param("i", $this->gameId);
            $stockStmt->execute();
            $stockResult = $stockStmt->get_result();
            
            if ($stockResult->num_rows === 0) {
                throw new Exception('Η τράπουλα άδειασε!');
            }
            
            $stockCard = $stockResult->fetch_assoc();
            $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
            
            // Μετακίνηση στο χέρι του παίκτη
            $moveSql = "UPDATE game_cards 
                       SET location = ?, 
                           position_order = (SELECT COALESCE(MAX(position_order), 0) + 1 
                                           FROM game_cards 
                                           WHERE game_id = ? AND location = ?),
                           is_visible = TRUE
                       WHERE id = ?";
            
            $moveStmt = $this->db->prepare($moveSql);
            $moveStmt->bind_param("sisi", $handLocation, $this->gameId, $handLocation, $stockCard['game_card_id']);
            $moveStmt->execute();
            
            // Εγγραφή κίνησης
            $this->recordMove($playerNumber, self::MOVE_DRAW_STOCK, $stockCard['card_id']);
            
            // Αλλαγή σειράς
            $this->switchTurn($playerNumber);
            
            // Ενημέρωση game_state
            $this->updateGameState();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'card_id' => $stockCard['card_id'],
                'next_player' => ($playerNumber == 1) ? 2 : 1
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Παίζει μια κάρτα από το χέρι
     */
    public function playCard($playerNumber, $cardId, $claimCardIds = []) {
        $this->db->begin_transaction();
        
        try {
            // Έλεγχος αν η κάρτα είναι στο χέρι του παίκτη
            $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
            $checkSql = "SELECT id FROM game_cards 
                        WHERE game_id = ? AND card_id = ? AND location = ?";
            
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->bind_param("iis", $this->gameId, $cardId, $handLocation);
            $checkStmt->execute();
            
            if ($checkStmt->get_result()->num_rows === 0) {
                throw new Exception('Η κάρτα δεν είναι στο χέρι σου');
            }
            
            // Ελέγχουμε τι μπορεί να πάρει
            $claimableCards = $this->getClaimableCards($cardId);
            $actualClaimedCards = [];
            
            // Φιλτράρουμε μόνο αυτά που ζήτησε και είναι valid
            if (!empty($claimCardIds)) {
                foreach ($claimableCards as $claimable) {
                    if (in_array($claimable['id'], $claimCardIds)) {
                        $actualClaimedCards[] = $claimable;
                    }
                }
            }
            
            $isXeri = $this->isXeriMove($cardId, $actualClaimedCards);
            
            // 1. Μετακίνηση της κάρτας στο τραπέζι
            $moveToTableSql = "UPDATE game_cards 
                              SET location = 'table', 
                                  position_order = (SELECT COALESCE(MAX(position_order), 0) + 1 
                                                  FROM game_cards 
                                                  WHERE game_id = ? AND location = 'table'),
                                  is_visible = TRUE
                              WHERE game_id = ? AND card_id = ? AND location = ?";
            
            $moveStmt = $this->db->prepare($moveToTableSql);
            $moveStmt->bind_param("iiis", $this->gameId, $this->gameId, $cardId, $handLocation);
            $moveStmt->execute();
            
            // 2. Αν πήρε κάρτες, τις μετακινεί στο χέρι του
            if (!empty($actualClaimedCards)) {
                foreach ($actualClaimedCards as $claimedCard) {
                    $claimSql = "UPDATE game_cards 
                                SET location = ?,
                                    position_order = NULL,
                                    is_visible = FALSE
                                WHERE game_id = ? AND card_id = ? AND location = 'table'";
                    
                    $claimStmt = $this->db->prepare($claimSql);
                    $claimStmt->bind_param("sii", $handLocation, $this->gameId, $claimedCard['id']);
                    $claimStmt->execute();
                }
            }
            
            // 3. Ενημέρωση στατιστικών
            $this->updatePlayerStats($playerNumber, count($actualClaimedCards), $isXeri);
            
            // 4. Εγγραφή κίνησης
            $moveData = [
                'claimed_cards' => array_column($actualClaimedCards, 'id'),
                'is_xeri' => $isXeri,
                'move_type' => (!empty($actualClaimedCards)) ? 'capture' : 'discard'
            ];
            
            $this->recordMove($playerNumber, self::MOVE_PLAY_CARD, $cardId, $moveData);
            
            // 5. Αλλαγή σειράς
            $this->switchTurn($playerNumber);
            
            // 6. Έλεγχος αν τελείωσε το παιχνίδι
            $gameEnded = $this->checkGameEnd();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'claimed_count' => count($actualClaimedCards),
                'is_xeri' => $isXeri,
                'next_player' => ($playerNumber == 1) ? 2 : 1,
                'game_ended' => $gameEnded
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Παρατάει τη σειρά
     */
    public function passTurn($playerNumber) {
        $this->db->begin_transaction();
        
        try {
            // Εγγραφή κίνησης
            $this->recordMove($playerNumber, self::MOVE_PASS);
            
            // Αλλαγή σειράς
            $this->switchTurn($playerNumber);
            
            // Ενημέρωση game_state
            $this->updateGameState();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'next_player' => ($playerNumber == 1) ? 2 : 1
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // ============================================
    // 6. ΒΟΗΘΗΤΙΚΕΣ ΣΥΝΑΡΤΗΣΕΙΣ
    // ============================================
    
    private function recordMove($playerNumber, $moveType, $cardId = null, $moveData = null) {
        $moveOrderSql = "SELECT COALESCE(MAX(move_order), 0) + 1 as next_order 
                        FROM moves WHERE game_id = ?";
        $orderStmt = $this->db->prepare($moveOrderSql);
        $orderStmt->bind_param("i", $this->gameId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $nextOrder = $orderResult->fetch_assoc()['next_order'];
        
        $moveDataJson = $moveData ? json_encode($moveData) : null;
        
        $insertSql = "INSERT INTO moves 
                     (game_id, player_number, move_type, card_id, move_data, move_order, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $insertStmt = $this->db->prepare($insertSql);
        $insertStmt->bind_param("iissi", 
            $this->gameId, 
            $playerNumber, 
            $moveType, 
            $cardId, 
            $moveDataJson, 
            $nextOrder
        );
        $insertStmt->execute();
    }
    
    private function switchTurn($currentPlayer) {
        $nextPlayer = ($currentPlayer == 1) ? 2 : 1;
        
        $updateSql = "UPDATE games 
                     SET current_turn_player_number = ? 
                     WHERE id = ?";
        
        $stmt = $this->db->prepare($updateSql);
        $stmt->bind_param("ii", $nextPlayer, $this->gameId);
        $stmt->execute();
        
        // Ενημέρωση game_state
        $stateSql = "UPDATE game_state 
                    SET current_player_number = ?,
                        last_change = NOW()
                    WHERE game_id = ?";
        
        $stateStmt = $this->db->prepare($stateSql);
        $stateStmt->bind_param("ii", $nextPlayer, $this->gameId);
        $stateStmt->execute();
    }
    
    private function updateGameState() {
        // Υπολογισμός στοιχείων
        $stockCountSql = "SELECT COUNT(*) as count FROM game_cards 
                         WHERE game_id = ? AND location = 'stock'";
        $stockStmt = $this->db->prepare($stockCountSql);
        $stockStmt->bind_param("i", $this->gameId);
        $stockStmt->execute();
        $stockCount = $stockStmt->get_result()->fetch_assoc()['count'];
        
        $p1HandSql = "SELECT COUNT(*) as count FROM game_cards 
                     WHERE game_id = ? AND location = 'hand_p1'";
        $p1Stmt = $this->db->prepare($p1HandSql);
        $p1Stmt->bind_param("i", $this->gameId);
        $p1Stmt->execute();
        $p1HandCount = $p1Stmt->get_result()->fetch_assoc()['count'];
        
        $p2HandSql = "SELECT COUNT(*) as count FROM game_cards 
                     WHERE game_id = ? AND location = 'hand_p2'";
        $p2Stmt = $this->db->prepare($p2HandSql);
        $p2Stmt->bind_param("i", $this->gameId);
        $p2Stmt->execute();
        $p2HandCount = $p2Stmt->get_result()->fetch_assoc()['count'];
        
        // Ενημέρωση
        $updateSql = "UPDATE game_state 
                     SET stock_count = ?,
                         player1_hand_size = ?,
                         player2_hand_size = ?,
                         last_change = NOW()
                     WHERE game_id = ?";
        
        $updateStmt = $this->db->prepare($updateSql);
        $updateStmt->bind_param("iiii", $stockCount, $p1HandCount, $p2HandCount, $this->gameId);
        $updateStmt->execute();
    }
    
    private function updatePlayerStats($playerNumber, $cardsClaimed, $isXeri) {
        // Ελέγχουμε αν υπάρχει ήδη εγγραφή
        $checkSql = "SELECT * FROM player_stats 
                    WHERE game_id = ? AND player_number = ?";
        
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->bind_param("ii", $this->gameId, $playerNumber);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing
            $updateSql = "UPDATE player_stats 
                         SET cards_collected = cards_collected + ?,
                             xeri_count = xeri_count + ?
                         WHERE game_id = ? AND player_number = ?";
            
            $xeriIncrement = $isXeri ? 1 : 0;
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->bind_param("iiii", $cardsClaimed, $xeriIncrement, $this->gameId, $playerNumber);
            $updateStmt->execute();
        } else {
            // Insert new
            $insertSql = "INSERT INTO player_stats 
                         (game_id, player_number, cards_collected, xeri_count) 
                         VALUES (?, ?, ?, ?)";
            
            $xeriCount = $isXeri ? 1 : 0;
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->bind_param("iiii", $this->gameId, $playerNumber, $cardsClaimed, $xeriCount);
            $insertStmt->execute();
        }
    }
    
    // ============================================
    // 7. ΕΛΕΓΧΟΣ ΤΕΛΟΥΣ ΠΑΙΧΝΙΔΙΟΥ
    // ============================================
    
    private function checkGameEnd() {
        // 1. Έλεγχος αν έχουν τελειώσει οι κάρτες στο stock
        $stockSql = "SELECT COUNT(*) as count FROM game_cards 
                    WHERE game_id = ? AND location = 'stock'";
        $stockStmt = $this->db->prepare($stockSql);
        $stockStmt->bind_param("i", $this->gameId);
        $stockStmt->execute();
        $stockCount = $stockStmt->get_result()->fetch_assoc()['count'];
        
        // 2. Έλεγχος αν έχουν τελειώσει οι κάρτες στα χέρια
        $p1HandSql = "SELECT COUNT(*) as count FROM game_cards 
                     WHERE game_id = ? AND location = 'hand_p1'";
        $p1Stmt = $this->db->prepare($p1HandSql);
        $p1Stmt->bind_param("i", $this->gameId);
        $p1Stmt->execute();
        $p1HandCount = $p1Stmt->get_result()->fetch_assoc()['count'];
        
        $p2HandSql = "SELECT COUNT(*) as count FROM game_cards 
                     WHERE game_id = ? AND location = 'hand_p2'";
        $p2Stmt = $this->db->prepare($p2HandSql);
        $p2Stmt->bind_param("i", $this->gameId);
        $p2Stmt->execute();
        $p2HandCount = $p2Stmt->get_result()->fetch_assoc()['count'];
        
        // 3. Αν stock άδειο ΚΑΙ και τα δύο χέρια άδεια, τελείωσε
        if ($stockCount == 0 && $p1HandCount == 0 && $p2HandCount == 0) {
            $this->endGame();
            return true;
        }
        
        return false;
    }
    
    private function endGame() {
        // 1. Υπολογισμός τελικών πόντων
        $this->calculateFinalPoints();
        
        // 2. Καθορισμός νικητή
        $winner = $this->determineWinner();
        
        // 3. Ενημέρωση games πίνακα
        $updateSql = "UPDATE games 
                     SET status = 'finished',
                         winner_player_number = ?,
                         finished_at = NOW()
                     WHERE id = ?";
        
        $stmt = $this->db->prepare($updateSql);
        $stmt->bind_param("ii", $winner, $this->gameId);
        $stmt->execute();
        
        // 4. Ενημέρωση game_state
        $stateSql = "UPDATE game_state 
                    SET status = 'ended',
                        current_player_number = NULL
                    WHERE game_id = ?";
        
        $stateStmt = $this->db->prepare($stateSql);
        $stateStmt->bind_param("i", $this->gameId);
        $stateStmt->execute();
    }
    
    private function calculateFinalPoints() {
        // Παίρνουμε τα στατιστικά και των δύο παικτών
        $sql = "SELECT * FROM player_stats WHERE game_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $playersStats = [];
        while ($row = $result->fetch_assoc()) {
            $playersStats[$row['player_number']] = $row;
        }
        
        // Υπολογισμός πόντων για κάθε παίκτη
        foreach ($playersStats as $playerNumber => $stats) {
            $points = $stats['cards_collected']; // 1 πόντος ανά κάρτα
            
            // +10 πόντους για κάθε ξερή
            $points += ($stats['xeri_count'] * 10);
            
            // Bonus για βαλέ (θα μπορούσε να υπολογιστεί από τις κάρτες που μάζεψε)
            // Ας υποθέσουμε ότι ορίζουμε έναν γενικό bonus
            $valetBonus = 0;
            // (Θα μπορούσαμε να μετρήσουμε πόσες κάρτες J έχει μαζέψει)
            
            $totalPoints = $points + $valetBonus;
            
            // Ενημέρωση
            $updateSql = "UPDATE player_stats 
                         SET total_points = ?
                         WHERE game_id = ? AND player_number = ?";
            
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->bind_param("iii", $totalPoints, $this->gameId, $playerNumber);
            $updateStmt->execute();
        }
    }
    
    private function determineWinner() {
        // Παίρνουμε τους πόντους των παικτών
        $sql = "SELECT player_number, total_points 
                FROM player_stats 
                WHERE game_id = ? 
                ORDER BY total_points DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $players = [];
        while ($row = $result->fetch_assoc()) {
            $players[] = $row;
        }
        
        if (count($players) >= 2) {
            if ($players[0]['total_points'] > $players[1]['total_points']) {
                return $players[0]['player_number'];
            } else {
                // Ισοπαλία, νικητής είναι ο player1 ή random
                return 1;
            }
        }
        
        return 1; // Default
    }
    
    // ============================================
    // 8. ΔΗΜΟΣΙΕΣ ΜΕΘΟΔΟΙ ΓΙΑ API
    // ============================================
    
    public function getValidMoves($playerNumber) {
        $validMoves = [
            'can_draw' => false,
            'can_pass' => true,
            'playable_cards' => []
        ];
        
        // Έλεγχος αν μπορεί να τραβήξει
        $stockSql = "SELECT COUNT(*) as count FROM game_cards 
                    WHERE game_id = ? AND location = 'stock'";
        $stockStmt = $this->db->prepare($stockSql);
        $stockStmt->bind_param("i", $this->gameId);
        $stockStmt->execute();
        $stockCount = $stockStmt->get_result()->fetch_assoc()['count'];
        
        $validMoves['can_draw'] = ($stockCount > 0);
        
        // Παίρνουμε το χέρι του παίκτη
        $hand = $this->getPlayerHand($playerNumber);
        
        // Για κάθε κάρτα, ελέγχουμε τι μπορεί να κάνει
        foreach ($hand as $card) {
            $claimableCards = $this->getClaimableCards($card['id']);
            
            $validMoves['playable_cards'][] = [
                'card' => $card,
                'can_claim' => !empty($claimableCards),
                'claimable_count' => count($claimableCards),
                'is_valet' => ($card['rank'] == 'J')
            ];
        }
        
        return $validMoves;
    }
    
    public function getGameStateForPlayer($playerNumber) {
        $myHand = $this->getPlayerHand($playerNumber);
        $opponentNumber = ($playerNumber == 1) ? 2 : 1;
        
        // Μόνο το μέγεθος του αντιπάλου, όχι τις κάρτες
        $opponentHandSize = $this->getPlayerHandSize($opponentNumber);
        $tableCards = $this->getTableCards();
        
        return [
            'my_hand' => $myHand,
            'my_hand_size' => count($myHand),
            'opponent_hand_size' => $opponentHandSize,
            'table_cards' => $tableCards,
            'stock_count' => $this->gameData['stock_count'] ?? 52,
            'is_my_turn' => $this->isMyTurn(),
            'game_status' => $this->gameData['status'] ?? self::GAME_WAITING
        ];
    }
    
    public function getPlayerHandSize($playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        
        $sql = "SELECT COUNT(*) as count FROM game_cards 
                WHERE game_id = ? AND location = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $this->gameId, $handLocation);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc()['count'];
    }
}
?>