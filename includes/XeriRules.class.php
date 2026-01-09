<?php
// includes/XeriRules.class.php

class XeriRules {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    // Έλεγχος αν μια κίνηση είναι έγκυρη
    public function validateMove($gameId, $playerNumber, $playedCardId, $claimedCardIds = []) {
        $validation = [
            'is_valid' => true,
            'message' => '',
            'type' => 'discard',
            'claimable_cards' => [],
            'is_xeri' => false
        ];
        
        try {
            // 1. Έλεγχος αν η κάρτα είναι στο χέρι του παίκτη
            if (!$this->isCardInPlayerHand($gameId, $playerNumber, $playedCardId)) {
                $validation['is_valid'] = false;
                $validation['message'] = 'Η κάρτα δεν είναι στο χέρι σου';
                return $validation;
            }
            
            // 2. Παίρνουμε την κάρτα που παίζεται
            $playedCard = $this->getCardDetails($playedCardId);
            if (!$playedCard) {
                $validation['is_valid'] = false;
                $validation['message'] = 'Η κάρτα δεν βρέθηκε';
                return $validation;
            }
            
            // 3. Παίρνουμε όλες τις κάρτες στο τραπέζι
            $tableCards = $this->getTableCards($gameId);
            
            // 4. Ελέγχουμε τι μπορεί να κάνει με αυτήν την κάρτα
            $claimResult = $this->checkCardCanClaim($playedCard, $tableCards);
            $validation['type'] = $claimResult['type'];
            $validation['claimable_cards'] = $claimResult['cards'];
            
            // 5. Έλεγχος για ξερή
            if ($claimResult['type'] == 'capture' && count($validation['claimable_cards']) == 1) {
                $validation['is_xeri'] = $this->checkForXeri($gameId, $playedCardId, $validation['claimable_cards'][0]['id']);
            }
            
            // 6. Επαλήθευση των claimed cards που ζήτησε ο παίκτης
            if (!empty($claimedCardIds)) {
                $validClaimIds = array_column($validation['claimable_cards'], 'id');
                foreach ($claimedCardIds as $claimedId) {
                    if (!in_array($claimedId, $validClaimIds)) {
                        $validation['is_valid'] = false;
                        $validation['message'] = 'Μη έγκυρη διεκδίκηση καρτών';
                        return $validation;
                    }
                }
            }
            
            return $validation;
            
        } catch (Exception $e) {
            $validation['is_valid'] = false;
            $validation['message'] = 'Σφάλμα επαλήθευσης: ' . $e->getMessage();
            return $validation;
        }
    }
    
    // Έλεγχος αν μια κάρτα μπορεί να πάρει άλλες κάρτες από το τραπέζι
    public function checkCardCanClaim($playedCard, $tableCards) {
        $result = [
            'type' => 'discard',
            'cards' => []
        ];
        
        // Κανόνες:
        // 1. Αν είναι Βαλές (J), παίρνει ΟΛΕΣ τις κάρτες
        if ($playedCard['rank'] == 'J') {
            $result['type'] = 'valet_capture';
            $result['cards'] = $tableCards;
            return $result;
        }
        
        // 2. Αν υπάρχει κάρτα με ίδιο rank, παίρνει αυτήν/ες την/τις κάρτα/ες
        $matchingCards = [];
        foreach ($tableCards as $tableCard) {
            if ($tableCard['rank'] == $playedCard['rank']) {
                $matchingCards[] = $tableCard;
            }
        }
        
        if (!empty($matchingCards)) {
            $result['type'] = 'capture';
            $result['cards'] = $matchingCards;
            return $result;
        }
        
        // 3. Απλή απόρριψη
        return $result;
    }
    
    // Έλεγχος για ξερή
    public function checkForXeri($gameId, $playedCardId, $claimedCardId) {
        // Μετράμε πόσες κάρτες ήταν στο τραπέζι πριν (εκτός από αυτές που αφορούν)
        $countSql = "SELECT COUNT(*) as count FROM game_cards 
                     WHERE game_id = ? AND location = 'table' 
                     AND card_id NOT IN (?, ?)";
        
        $stmt = $this->db->prepare($countSql);
        $stmt->bind_param("iii", $gameId, $playedCardId, $claimedCardId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        // Αν δεν υπήρχαν άλλες κάρτες στο τραπέζι = ΞΕΡΗ
        return $row['count'] == 0;
    }
    
    // Υπολογισμός πόντων
    public function calculateScore($gameId, $playerNumber) {
        $score = 0;
        $xeriCount = 0;
        
        // 1. Πόντοι από κάρτες που πήρε
        $capturedCards = $this->getCapturedCards($gameId, $playerNumber);
        
        foreach ($capturedCards as $card) {
            // Κάθε κάρτα που πήρε = 1 πόντος
            $score += 1;
            
            // Επιπλέον πόντοι για high cards
            if (in_array($card['rank'], ['A', 'K', 'Q', 'J', '10'])) {
                $score += 2; // Bonus για high cards
            }
        }
        
        // 2. Πόντοι για ξερή
        $xeriCount = $this->countXeri($gameId, $playerNumber);
        $score += ($xeriCount * 10); // 10 πόντοι για κάθε ξερή
        
        return [
            'score' => $score,
            'captured_cards' => count($capturedCards),
            'xeri_count' => $xeriCount
        ];
    }
    
    // Έλεγχος αν το παιχνίδι τελείωσε
    public function isGameOver($gameId) {
        // 1. Έλεγχος αν και οι δύο παίκτες έχουν κενά χέρια
        $handSql = "SELECT 
                       SUM(CASE WHEN location = 'hand_p1' THEN 1 ELSE 0 END) as p1_hand,
                       SUM(CASE WHEN location = 'hand_p2' THEN 1 ELSE 0 END) as p2_hand,
                       SUM(CASE WHEN location = 'stock' THEN 1 ELSE 0 END) as stock
                    FROM game_cards 
                    WHERE game_id = ?";
        
        $handStmt = $this->db->prepare($handSql);
        $handStmt->bind_param("i", $gameId);
        $handStmt->execute();
        $handResult = $handStmt->get_result();
        $handData = $handResult->fetch_assoc();
        
        $p1HandEmpty = ($handData['p1_hand'] == 0);
        $p2HandEmpty = ($handData['p2_hand'] == 0);
        $stockEmpty = ($handData['stock'] == 0);
        
        // Το παιχνίδι τελειώνει όταν:
        // 1. Η τράπουλα τελείωσε ΚΑΙ
        // 2. Κάποιος παίκτης έμεινε χωρίς κάρτες
        if ($stockEmpty && ($p1HandEmpty || $p2HandEmpty)) {
            return true;
        }
        
        return false;
    }
    
    // Προσδιορισμός νικητή
    public function determineWinner($gameId) {
        // 1. Υπολογισμός πόντων για κάθε παίκτη
        $player1Score = $this->calculateScore($gameId, 1);
        $player2Score = $this->calculateScore($gameId, 2);
        
        // 2. Σύγκριση πόντων
        if ($player1Score['score'] > $player2Score['score']) {
            return [
                'winner' => 1,
                'player1_score' => $player1Score,
                'player2_score' => $player2Score
            ];
        } elseif ($player2Score['score'] > $player1Score['score']) {
            return [
                'winner' => 2,
                'player1_score' => $player1Score,
                'player2_score' => $player2Score
            ];
        } else {
            // Ισοπαλία - νικάει αυτός με τις περισσότερες ξερές
            if ($player1Score['xeri_count'] > $player2Score['xeri_count']) {
                return [
                    'winner' => 1,
                    'player1_score' => $player1Score,
                    'player2_score' => $player2Score,
                    'reason' => 'Περισσότερες ξερές'
                ];
            } elseif ($player2Score['xeri_count'] > $player1Score['xeri_count']) {
                return [
                    'winner' => 2,
                    'player1_score' => $player1Score,
                    'player2_score' => $player2Score,
                    'reason' => 'Περισσότερες ξερές'
                ];
            } else {
                // Πλήρης ισοπαλία
                return [
                    'winner' => 0, // Ισοπαλία
                    'player1_score' => $player1Score,
                    'player2_score' => $player2Score,
                    'reason' => 'Πλήρης ισοπαλία'
                ];
            }
        }
    }
    
    // Παίρνει όλες τις κάρτες που έχει πάρει ένας παίκτης
    private function getCapturedCards($gameId, $playerNumber) {
        $sql = "SELECT c.* 
                FROM moves m
                JOIN cards c ON m.card_id = c.id
                WHERE m.game_id = ? AND m.player_number = ? 
                AND m.move_type IN ('capture', 'valet_capture')
                GROUP BY c.id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $gameId, $playerNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cards = [];
        while ($row = $result->fetch_assoc()) {
            $cards[] = $row;
        }
        
        return $cards;
    }
    
    // Μετράει τις ξερές ενός παίκτη
    private function countXeri($gameId, $playerNumber) {
        $sql = "SELECT COUNT(*) as count 
                FROM moves 
                WHERE game_id = ? AND player_number = ? 
                AND JSON_EXTRACT(move_data, '$.is_xeri') = TRUE";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $gameId, $playerNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    // Βοηθητικές συναρτήσεις
    private function isCardInPlayerHand($gameId, $playerNumber, $cardId) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        
        $sql = "SELECT id FROM game_cards 
                WHERE game_id = ? AND card_id = ? AND location = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("iis", $gameId, $cardId, $handLocation);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0;
    }
    
    private function getCardDetails($cardId) {
        $sql = "SELECT * FROM cards WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $cardId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    private function getTableCards($gameId) {
        $sql = "SELECT c.* 
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = 'table'
                ORDER BY gc.position_order";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cards = [];
        while ($row = $result->fetch_assoc()) {
            $cards[] = $row;
        }
        
        return $cards;
    }
    
    // Επικύρωση αν ο παίκτης μπορεί να τραβήξει κάρτα
    public function canPlayerDraw($gameId, $playerNumber) {
        // 1. Έλεγχος αν είναι η σειρά του
        if (!$this->isPlayersTurn($gameId, $playerNumber)) {
            return [
                'can_draw' => false,
                'message' => 'Δεν είναι η σειρά σου'
            ];
        }
        
        // 2. Έλεγχος αν έχει κάρτες η τράπουλα
        $stockSize = $this->getStockSize($gameId);
        
        if ($stockSize == 0) {
            return [
                'can_draw' => false,
                'message' => 'Η τράπουλα άδειασε'
            ];
        }
        
        // 3. Έλεγχος αν ο παίκτης έχει ήδη 10+ κάρτες (όριο για να μην τραβάει συνέχεια)
        $handSize = $this->getPlayerHandSize($gameId, $playerNumber);
        
        if ($handSize >= 10) {
            return [
                'can_draw' => false,
                'message' => 'Έχεις πολλές κάρτες στο χέρι'
            ];
        }
        
        return [
            'can_draw' => true,
            'message' => 'Μπορείς να τραβήξεις',
            'stock_size' => $stockSize
        ];
    }
    
    private function isPlayersTurn($gameId, $playerNumber) {
        $sql = "SELECT current_turn_player_number FROM games WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['current_turn_player_number'] == $playerNumber;
        }
        
        return false;
    }
    
    private function getStockSize($gameId) {
        $sql = "SELECT COUNT(*) as size FROM game_cards 
                WHERE game_id = ? AND location = 'stock'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['size'] ?? 0;
    }
    
    private function getPlayerHandSize($gameId, $playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        
        $sql = "SELECT COUNT(*) as size FROM game_cards 
                WHERE game_id = ? AND location = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $gameId, $handLocation);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['size'] ?? 0;
    }
    
    // Παίρνει όλες τις δυνατές κινήσεις για έναν παίκτη
    public function getAllPossibleMoves($gameId, $playerNumber) {
        $moves = [];
        
        // 1. Παίρνουμε όλες τις κάρτες στο χέρι του παίκτη
        $handCards = $this->getPlayerHand($gameId, $playerNumber);
        
        // 2. Για κάθε κάρτα, βρίσκουμε τι μπορεί να κάνει
        foreach ($handCards as $card) {
            $tableCards = $this->getTableCards($gameId);
            $claimResult = $this->checkCardCanClaim($card, $tableCards);
            
            $move = [
                'card' => $card,
                'type' => $claimResult['type'],
                'claimable_cards' => $claimResult['cards'],
                'is_xeri_possible' => false
            ];
            
            // Έλεγχος για ξερή
            if ($claimResult['type'] == 'capture' && count($claimResult['cards']) == 1) {
                $move['is_xeri_possible'] = $this->checkForXeri(
                    $gameId, 
                    $card['id'], 
                    $claimResult['cards'][0]['id']
                );
            }
            
            $moves[] = $move;
        }
        
        // 3. Έλεγχος αν μπορεί να τραβήξει
        $canDrawResult = $this->canPlayerDraw($gameId, $playerNumber);
        
        return [
            'hand_cards' => $handCards,
            'possible_moves' => $moves,
            'can_draw' => $canDrawResult['can_draw'],
            'draw_message' => $canDrawResult['message'],
            'stock_size' => $canDrawResult['stock_size'] ?? 0
        ];
    }
    
    private function getPlayerHand($gameId, $playerNumber) {
        $handLocation = ($playerNumber == 1) ? 'hand_p1' : 'hand_p2';
        
        $sql = "SELECT c.* 
                FROM game_cards gc
                JOIN cards c ON gc.card_id = c.id
                WHERE gc.game_id = ? AND gc.location = ?
                ORDER BY gc.position_order";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $gameId, $handLocation);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cards = [];
        while ($row = $result->fetch_assoc()) {
            $cards[] = $row;
        }
        
        return $cards;
    }
}
?>