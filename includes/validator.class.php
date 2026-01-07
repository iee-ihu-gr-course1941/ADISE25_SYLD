<?php
// includes/validator.class.php
require_once 'deck.class.php';

class Validator {
    private $deck;
    
    public function __construct() {
        $this->deck = new Deck();
    }
    
    public function validateMove($gameId, $playerNumber, $cardId, $claimedCardIds = []) {
        // Βασικοί έλεγχοι
        if (!$this->isCardInHand($gameId, $playerNumber, $cardId)) {
            return false;
        }
        
        // Βαλέ μπορεί να πάρει όλα
        if ($this->deck->isValet($cardId)) {
            return true;
        }
        
        // Έλεγχος ταύτισης για τα claimed cards
        if (!empty($claimedCardIds)) {
            return $this->checkCardMatch($cardId, $claimedCardIds);
        }
        
        return true; // Απλή απόρριψη
    }
    
    private function isCardInHand($gameId, $playerNumber, $cardId) {
        $hand = $this->deck->getPlayerHand($gameId, $playerNumber);
        foreach ($hand as $card) {
            if ($card['id'] == $cardId) {
                return true;
            }
        }
        return false;
    }
    
    private function checkCardMatch($playedCardId, $claimedCardIds) {
        $playedCard = $this->deck->getCardValue($playedCardId);
        if (!$playedCard) return false;
        
        $playedRank = $playedCard['rank'];
        
        foreach ($claimedCardIds as $claimedId) {
            $claimedCard = $this->deck->getCardValue($claimedId);
            if (!$claimedCard || $claimedCard['rank'] !== $playedRank) {
                return false;
            }
        }
        
        return true;
    }
}
?>