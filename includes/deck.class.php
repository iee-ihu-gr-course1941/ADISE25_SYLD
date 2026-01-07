<?php
// includes/deck.class.php
require_once __DIR__ . '/../db_config.php';

class Deck {
    private $db;
    
    public function __construct() {
        $this->db = getDBConnection();
    }
    
    public function initializeGameDeck($gameId) {
        try {
            $this->db->begin_transaction();
            
            // 1. Πάρε τα 52 φύλλα
            $cards = $this->getAllCards();
            
            // 2. Ανακάτεμα
            shuffle($cards);
            
            // 3. Εισαγωγή στο stock
            $position = 1;
            foreach ($cards as $card) {
                $this->addCardToGame($gameId, $card['id'], 'stock', $position);
                $position++;
            }
            
            // 4. Μοίρασμα 6 φύλλα
            $this->dealInitialHand($gameId, 1, 6);
            $this->dealInitialHand($gameId, 2, 6);
            
            // 5. 4 φύλλα στο τραπέζι
            $this->dealTableCards($gameId, 4);
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Deck initialization failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function getAllCards() {
        $sql = "SELECT id, suit, rank, value, symbol FROM cards ORDER BY suit, value";
        $result = $this->db->query($sql);
        
        $cards = [];
        while ($row = $result->fetch_assoc()) {
            $cards[] = $row;
        }
        return $cards;
    }
    
    private function addCardToGame($gameId, $cardId, $location, $position = null) {
        $sql = "INSERT INTO game_cards (game_id, card_id, location, position_order, is_visible) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $isVisible = ($location === 'table') ? 1 : 0;
        $stmt->bind_param("iissi", $gameId, $cardId, $location, $position, $isVisible);
        return $stmt->execute();
    }
    
    private function dealInitialHand($gameId, $playerNumber, $cardCount) {
        $sql = "SELECT gc.id, gc.card_id 
                FROM game_cards gc 
                WHERE gc.game_id = ? AND gc.location = 'stock' 
                ORDER BY gc.position_order ASC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $gameId, $cardCount);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cardsToDeal = [];
        while ($row = $result->fetch_assoc()) {
            $cardsToDeal[] = $row;
        }
        
        foreach ($cardsToDeal as $index => $card) {
            $this->moveCard(
                $gameId, 
                $card['card_id'], 
                'stock', 
                'hand_p' . $playerNumber,
                $index + 1
            );
        }
    }
    
    private function dealTableCards($gameId, $cardCount) {
        $sql = "SELECT gc.id, gc.card_id 
                FROM game_cards gc 
                WHERE gc.game_id = ? AND gc.location = 'stock' 
                ORDER BY gc.position_order ASC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $gameId, $cardCount);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tableCards = [];
        while ($row = $result->fetch_assoc()) {
            $tableCards[] = $row;
        }
        
        foreach ($tableCards as $card) {
            $this->moveCard($gameId, $card['card_id'], 'stock', 'table', null);
        }
    }
    
    public function moveCard($gameId, $cardId, $fromLocation, $toLocation, $position = null) {
        $sql = "UPDATE game_cards 
                SET location = ?, position_order = ?, 
                    is_visible = ?, last_updated = NOW() 
                WHERE game_id = ? AND card_id = ? AND location = ?";
        
        $stmt = $this->db->prepare($sql);
        $isVisible = ($toLocation === 'table') ? 1 : 0;
        $stmt->bind_param("ssiiss", $toLocation, $position, $isVisible, $gameId, $cardId, $fromLocation);
        return $stmt->execute();
    }
    
    public function drawFromStock($gameId, $playerNumber) {
        $sql = "SELECT gc.card_id 
                FROM game_cards gc 
                WHERE gc.game_id = ? AND gc.location = 'stock' 
                ORDER BY gc.position_order ASC 
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $cardId = $row['card_id'];
            $this->moveCard($gameId, $cardId, 'stock', 'hand_p' . $playerNumber, 
                          $this->getNextHandPosition($gameId, $playerNumber));
            return $cardId;
        }
        return null;
    }
    
    private function getNextHandPosition($gameId, $playerNumber) {
        $sql = "SELECT MAX(position_order) as max_pos 
                FROM game_cards 
                WHERE game_id = ? AND location = ?";
        
        $location = 'hand_p' . $playerNumber;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $gameId, $location);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return ($row['max_pos'] ? $row['max_pos'] + 1 : 1);
        }
        return 1;
    }
    
    public function getTableCards($gameId) {
        $sql = "SELECT c.id, c.symbol, c.rank, c.suit 
                FROM game_cards gc 
                JOIN cards c ON gc.card_id = c.id 
                WHERE gc.game_id = ? AND gc.location = 'table' 
                ORDER BY gc.last_updated ASC";
        
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
    
    public function getPlayerHand($gameId, $playerNumber) {
        $sql = "SELECT c.id, c.symbol, c.rank, c.suit 
                FROM game_cards gc 
                JOIN cards c ON gc.card_id = c.id 
                WHERE gc.game_id = ? AND gc.location = ? 
                ORDER BY gc.position_order ASC";
        
        $location = 'hand_p' . $playerNumber;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $gameId, $location);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $hand = [];
        while ($row = $result->fetch_assoc()) {
            $hand[] = $row;
        }
        return $hand;
    }
    
    public function getStockSize($gameId) {
        $sql = "SELECT COUNT(*) as count 
                FROM game_cards 
                WHERE game_id = ? AND location = 'stock'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $gameId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['count'];
        }
        return 0;
    }
    
    public function isValet($cardId) {
        $sql = "SELECT rank FROM cards WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $cardId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['rank'] === 'J';
        }
        return false;
    }
    
    public function getCardValue($cardId) {
        $sql = "SELECT rank, value FROM cards WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $cardId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row;
        }
        return null;
    }
    
    public function findMatchingCards($playedCardId, $gameId) {
        $playedCard = $this->getCardValue($playedCardId);
        if (!$playedCard) return [];
        
        $playedRank = $playedCard['rank'];
        
        $sql = "SELECT c.id, c.symbol, c.rank, c.suit 
                FROM game_cards gc 
                JOIN cards c ON gc.card_id = c.id 
                WHERE gc.game_id = ? AND gc.location = 'table' 
                AND c.rank = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("is", $gameId, $playedRank);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $matches = [];
        while ($row = $result->fetch_assoc()) {
            $matches[] = $row;
        }
        return $matches;
    }
}
?>