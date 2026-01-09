<?php
// includes/XeriAI.class.php
require_once 'XeriDeck.class.php';
require_once 'XeriRules.class.php';

class XeriAI {
    private $db;
    private $gameId;
    private $aiPlayerNumber;
    private $difficulty;
    private $deck;
    private $rules;
    
    public function __construct($db, $gameId, $aiPlayerNumber, $difficulty = 'medium') {
        $this->db = $db;
        $this->gameId = $gameId;
        $this->aiPlayerNumber = $aiPlayerNumber;
        $this->difficulty = $difficulty;
        $this->deck = new XeriDeck($db, $gameId);
        $this->rules = new XeriRules($db);
    }
    
    // Κύρια συνάρτηση για να κάνει την επόμενη κίνηση
    public function makeMove() {
        $move = null;
        
        switch ($this->difficulty) {
            case 'easy':
                $move = $this->easyMove();
                break;
            case 'medium':
                $move = $this->mediumMove();
                break;
            case 'hard':
                $move = $this->hardMove();
                break;
            default:
                $move = $this->mediumMove();
        }
        
        return $move;
    }
    
    // Easy: Τυχαίες κινήσεις
    private function easyMove() {
        $possibleMoves = $this->getPossibleMoves();
        
        if (empty($possibleMoves['possible_moves'])) {
            return $this->tryDrawCard();
        }
        
        // Τυχαία επιλογή κίνησης
        $randomIndex = array_rand($possibleMoves['possible_moves']);
        $chosenMove = $possibleMoves['possible_moves'][$randomIndex];
        
        // Τυχαία απόφαση: 70% να παίξει, 30% να τραβήξει
        if (rand(1, 100) <= 70) {
            return $this->executePlayMove($chosenMove);
        } else {
            return $this->tryDrawCard();
        }
    }
    
    // Medium: Βασική στρατηγική
    private function mediumMove() {
        $possibleMoves = $this->getPossibleMoves();
        
        if (empty($possibleMoves['possible_moves'])) {
            return $this->tryDrawCard();
        }
        
        // Βαθμολόγηση κάθε κίνησης
        $ratedMoves = [];
        foreach ($possibleMoves['possible_moves'] as $move) {
            $score = $this->rateMove($move);
            $ratedMoves[] = [
                'move' => $move,
                'score' => $score
            ];
        }
        
        // Ταξινόμηση με βάση το score
        usort($ratedMoves, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Επιλογή της καλύτερης κίνησης
        $bestMove = $ratedMoves[0]['move'];
        $bestScore = $ratedMoves[0]['score'];
        
        // Αν η καλύτερη κίνηση έχει πολύ χαμηλό score, ίσως καλύτερα να τραβήξει
        if ($bestScore < 10 && $possibleMoves['can_draw']) {
            return $this->tryDrawCard();
        }
        
        return $this->executePlayMove($bestMove);
    }
    
    // Hard: Προηγμένη στρατηγική
    private function hardMove() {
        $possibleMoves = $this->getPossibleMoves();
        
        if (empty($possibleMoves['possible_moves'])) {
            return $this->tryDrawCard();
        }
        
        // Βαθμολόγηση με προηγμένους κανόνες
        $ratedMoves = [];
        foreach ($possibleMoves['possible_moves'] as $move) {
            $score = $this->rateMoveHard($move, $possibleMoves);
            $ratedMoves[] = [
                'move' => $move,
                'score' => $score
            ];
        }
        
        // Ταξινόμηση
        usort($ratedMoves, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Επιλογή καλύτερης
        $bestMove = $ratedMoves[0]['move'];
        
        // Στρατηγική απόφαση: μερικές φορές να κρατάει καλές κάρτες
        $handSize = $possibleMoves['hand_size'];
        if ($handSize <= 3) {
            // Αν έχει λίγες κάρτες, να είναι πιο συντηρητικός
            $moveScore = $ratedMoves[0]['score'];
            if ($moveScore < 30 && $possibleMoves['can_draw']) {
                return $this->tryDrawCard();
            }
        }
        
        return $this->executePlayMove($bestMove);
    }
    
    // Βαθμολόγηση κίνησης για medium difficulty
    private function rateMove($move) {
        $score = 0;
        
        // 1. Βασικοί πόντοι βάσει τύπου κίνησης
        switch ($move['type']) {
            case 'valet_capture':
                $score += 50; // Βαλές είναι εξαιρετικός
                break;
            case 'capture':
                $score += 20; // Παίρνει κάρτες
                break;
            case 'discard':
                $score += 5;  // Απλή απόρριψη
                break;
        }
        
        // 2. Πόντοι για ξερή
        if ($move['is_xeri_possible']) {
            $score += 100; // Ξερή είναι το καλύτερο!
        }
        
        // 3. Αξία της κάρτας που θα παίξει
        $cardValue = $this->getCardValue($move['card']['rank']);
        $score -= ($cardValue * 2); // Αφαιρούμε για να μην πετάξει πολύτιμες κάρτες
        
        // 4. Αξία των καρτών που θα πάρει
        if (!empty($move['claimable_cards'])) {
            foreach ($move['claimable_cards'] as $claimedCard) {
                $claimedValue = $this->getCardValue($claimedCard['rank']);
                $score += ($claimedValue * 3); // Bonus για να πάρει πολύτιμες κάρτες
            }
        }
        
        return $score;
    }
    
    // Βαθμολόγηση για hard difficulty
    private function rateMoveHard($move, $allMoves) {
        $score = $this->rateMove($move);
        
        // Επιπλέον στρατηγικοί παράγοντες:
        
        // 1. Μέγεθος χεριού
        $handSize = $allMoves['hand_size'];
        if ($handSize >= 8) {
            // Αν έχει πολλές κάρτες, να ξεφορτωθεί
            $score += 15;
        } elseif ($handSize <= 3) {
            // Αν έχει λίγες, να είναι προσεκτικός
            $score -= 10;
        }
        
        // 2. Αν είναι Βαλές και υπάρχουν πολλές κάρτες στο τραπέζι
        if ($move['card']['rank'] == 'J') {
            $tableCardCount = $this->deck->countCardsByLocation('table');
            if ($tableCardCount >= 3) {
                $score += 30; // Μεγάλο bonus αν υπάρχουν πολλές κάρτες
            }
        }
        
        // 3. Προσπάθεια να μην δώσει ευκαιρία για ξερή στον αντίπαλο
        if ($move['type'] == 'discard') {
            // Αν πετάει κάρτα που δεν ταιριάζει με τίποτα, είναι ασφαλές
            $score += 5;
        }
        
        return $score;
    }
    
    // Προσπαθεί να τραβήξει κάρτα
    private function tryDrawCard() {
        $result = $this->deck->drawFromStock($this->aiPlayerNumber);
        
        if ($result['success']) {
            return [
                'type' => 'draw',
                'success' => true,
                'message' => 'AI τράβηξε κάρτα',
                'card' => $result['card']
            ];
        } else {
            // Αν δεν μπορεί να τραβήξει, παρατάει
            return $this->passTurn();
        }
    }
    
    // Εκτέλεση play move
    private function executePlayMove($move) {
        // Αποφασίζει ποιες κάρτες θα πάρει (αν μπορεί)
        $claimedCardIds = [];
        
        if ($move['type'] == 'valet_capture') {
            // Παίρνει όλες τις κάρτες
            $claimedCardIds = array_column($move['claimable_cards'], 'id');
        } elseif ($move['type'] == 'capture') {
            // Παίρνει όλες τις matching cards
            $claimedCardIds = array_column($move['claimable_cards'], 'id');
        }
        
        // Παίζει την κάρτα
        $playResult = $this->deck->playCardToTable($move['card']['id'], $this->aiPlayerNumber);
        
        if (!$playResult['success']) {
            return [
                'type' => 'error',
                'success' => false,
                'message' => 'Αδυναμία παίξιμο κάρτας'
            ];
        }
        
        // Παίρνει τις κάρτες (αν υπάρχουν)
        if (!empty($claimedCardIds)) {
            $takeResult = $this->deck->takeCardsFromTable($claimedCardIds, $this->aiPlayerNumber);
        }
        
        // Έλεγχος για ξερή
        $isXeri = false;
        if ($move['is_xeri_possible']) {
            $isXeri = true;
        }
        
        return [
            'type' => 'play',
            'success' => true,
            'message' => 'AI έπαιξε κάρτα',
            'card' => $move['card'],
            'claimed_cards' => $claimedCardIds,
            'claimed_count' => count($claimedCardIds),
            'move_type' => $move['type'],
            'is_xeri' => $isXeri
        ];
    }
    
    // Παράτηση σειράς
    private function passTurn() {
        return [
            'type' => 'pass',
            'success' => true,
            'message' => 'AI παρέδωσε τη σειρά'
        ];
    }
    
    // Παίρνει όλες τις δυνατές κινήσεις
    private function getPossibleMoves() {
        $handCards = $this->deck->getPlayerHand($this->aiPlayerNumber);
        $tableCards = $this->deck->getTableCards();
        $handSize = count($handCards);
        
        $possibleMoves = [];
        
        foreach ($handCards as $card) {
            $validation = $this->rules->validateMove(
                $this->gameId, 
                $this->aiPlayerNumber, 
                $card['id']
            );
            
            if ($validation['is_valid']) {
                $move = [
                    'card' => $card,
                    'type' => $validation['type'],
                    'claimable_cards' => $validation['claimable_cards'],
                    'is_xeri_possible' => $validation['is_xeri']
                ];
                
                $possibleMoves[] = $move;
            }
        }
        
        $canDraw = !$this->deck->isStockEmpty();
        
        return [
            'possible_moves' => $possibleMoves,
            'hand_cards' => $handCards,
            'hand_size' => $handSize,
            'can_draw' => $canDraw,
            'stock_size' => $this->deck->getStockSize()
        ];
    }
    
    // Βαθμολόγηση αξίας κάρτας
    private function getCardValue($rank) {
        $values = [
            'A' => 10,
            'K' => 8,
            'Q' => 7,
            'J' => 6,  // Βαλές - ειδική περίπτωση
            '10' => 5,
            '9' => 4,
            '8' => 3,
            '7' => 2,
            '6' => 1,
            '5' => 1,
            '4' => 1,
            '3' => 1,
            '2' => 1
        ];
        
        return $values[$rank] ?? 1;
    }
    
    // Έλεγχος αν το παιχνίδι πρέπει να τελειώσει
    public function shouldEndGame() {
        // 1. Έλεγχος αν η τράπουλα άδειασε και κάποιος έχει κενό χέρι
        if ($this->deck->isStockEmpty()) {
            $aiHandEmpty = $this->deck->isHandEmpty($this->aiPlayerNumber);
            
            // Βρίσκουμε τον αντίπαλο
            $opponentNumber = ($this->aiPlayerNumber == 1) ? 2 : 1;
            $opponentHandEmpty = $this->deck->isHandEmpty($opponentNumber);
            
            if ($aiHandEmpty || $opponentHandEmpty) {
                return true;
            }
        }
        
        return false;
    }
    
    // Προσομοίωση κίνησης για να δει τι θα γίνει (για hard AI)
    private function simulateMove($move) {
        // Αυτή είναι απλοποιημένη προσομοίωση
        // Στην πραγματικότητα, θα έπρεπε να δημιουργήσει temporary game state
        
        $simulatedScore = $this->rateMove($move);
        
        // Προσθέτουμε τυχαία παραλλαγή για προσομοίωση
        $variation = rand(-5, 5);
        
        return $simulatedScore + $variation;
    }
    
    // Αλλαγή difficulty
    public function setDifficulty($difficulty) {
        $this->difficulty = $difficulty;
    }
    
    // Παίρνει το difficulty
    public function getDifficulty() {
        return $this->difficulty;
    }
    
    // Παίρνει τον αριθμό του AI player
    public function getPlayerNumber() {
        return $this->aiPlayerNumber;
    }
}
?>