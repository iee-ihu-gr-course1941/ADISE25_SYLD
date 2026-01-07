// js/game-engine.js
class GameEngine {
    static gameId = 0;
    static playerNumber = 0;
    static isMyTurn = false;
    static pollInterval = null;
    
    static init(gameId, playerNumber) {
        this.gameId = gameId;
        this.playerNumber = playerNumber;
        
        console.log(`GameEngine initialized - Game: ${gameId}, Player: ${playerNumber}`);
        
        // Update UI
        $('#game-id').text(gameId);
        $('#player-number').text(playerNumber);
        
        // Start polling for game state
        this.startPolling();
        
        // Load initial state
        this.loadGameState();
    }
    
    static startPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }
        
        this.pollInterval = setInterval(() => {
            this.loadGameState();
        }, GAME_CONFIG.pollInterval);
    }
    
    static loadGameState() {
        if (!this.gameId) return;
        
        $.ajax({
            url: 'api/game.php',
            method: 'GET',
            data: {
                action: 'get_game_state',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    this.updateUI(response.game_state);
                    this.checkTurn(response.game_state);
                }
            },
            error: (xhr, status, error) => {
                console.error('Error loading game state:', error);
            }
        });
    }
    
    static updateUI(gameState) {
        // Update scores
        $('#my-score').text(gameState.my_score || 0);
        $('#opponent-score').text(gameState.opponent_score || 0);
        
        // Update card counts
        $('#my-cards-count').text(gameState.hand?.length || 0 + ' κάρτες');
        $('#opponent-cards-count').text(gameState.opponent_hand_size || 6 + ' κάρτες');
        
        // Update stock
        $('#stock-count').text(gameState.stock_size + ' κάρτες');
        
        // Render player hand
        this.renderPlayerHand(gameState.hand || []);
        
        // Render table cards
        this.renderTableCards(gameState.table_cards || []);
        
        // Update game status
        $('#game-status').text(gameState.status === 'active' ? 'Ενεργό' : gameState.status);
        
        // Update turn indicator
        if (gameState.current_turn === this.playerNumber) {
            $('#game-status').removeClass('bg-success bg-secondary').addClass('bg-warning');
            $('#game-status').text('ΣΕΙΡΑ ΣΟΥ');
        } else {
            $('#game-status').removeClass('bg-warning').addClass('bg-success');
        }
    }
    
    static renderPlayerHand(hand) {
        const $handContainer = $('#player-hand');
        $handContainer.empty();
        
        if (hand.length === 0) {
            $handContainer.html('<div class="empty-hand text-muted">Κανένα φύλλο στο χέρι</div>');
            return;
        }
        
        hand.forEach(card => {
            const $card = $(`
                <div class="card-item" data-card-id="${card.id}" 
                     data-card-symbol="${card.symbol}" 
                     data-card-rank="${card.rank}" 
                     data-card-suit="${card.suit}"
                     title="${card.symbol}">
                    <div class="card-value">${this.getRankSymbol(card.rank)}</div>
                    <div class="card-suit">${this.getSuitSymbol(card.suit)}</div>
                </div>
            `);
            
            // Add click event for playing the card
            $card.click(() => {
                if (this.isMyTurn) {
                    this.showCardOptions(card);
                } else {
                    this.showMessage('Δεν είναι η σειρά σου!', 'warning');
                }
            });
            
            $handContainer.append($card);
        });
    }
    
    static renderTableCards(tableCards) {
        const $tableContainer = $('#table-cards-container');
        $tableContainer.empty();
        
        if (tableCards.length === 0) {
            $tableContainer.html('<div class="empty-table text-muted">Κανένα φύλλο στο τραπέζι</div>');
            return;
        }
        
        tableCards.forEach(card => {
            const $card = $(`
                <div class="table-card-item" data-card-id="${card.id}" 
                     title="${card.symbol}">
                    <div class="card-value">${this.getRankSymbol(card.rank)}</div>
                    <div class="card-suit">${this.getSuitSymbol(card.suit)}</div>
                </div>
            `);
            
            $tableContainer.append($card);
        });
    }
    
    static checkTurn(gameState) {
        this.isMyTurn = (gameState.current_turn === this.playerNumber);
        
        // Enable/disable controls based on turn
        $('#btn-draw-card').prop('disabled', !this.isMyTurn);
        $('#btn-pass-turn').prop('disabled', !this.isMyTurn);
        
        // Show message if it's our turn
        if (this.isMyTurn) {
            this.showMessage('Είναι η σειρά σου! Παίξε μια κάρτα ή τράβηξε από την τράπουλα.', 'info');
        }
    }
    
    static showCardOptions(card) {
        // First, check what cards we can claim with this card
        $.ajax({
            url: 'api/move.php',
            method: 'POST',
            data: {
                action: 'get_possible_moves',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    const movesForThisCard = response.possible_moves.filter(
                        move => move.card.id === card.id
                    );
                    
                    if (movesForThisCard.length > 0) {
                        this.showMoveSelection(movesForThisCard, card);
                    } else {
                        // Simple discard
                        this.playCard(card.id, []);
                    }
                }
            }
        });
    }
    
    static showMoveSelection(moves, card) {
        let message = `Τι θέλεις να κάνεις με το ${card.symbol};`;
        let options = ['Απλή απόρριψη (ρίξε στο τραπέζι)'];
        
        moves.forEach(move => {
            if (move.type === 'capture' || move.type === 'valet_capture') {
                const claimText = move.type === 'valet_capture' 
                    ? 'Πάρε όλα τα φύλλα (Βαλές)' 
                    : `Πάρε το ${move.claimed.symbol}`;
                
                if (move.is_xeri) {
                    options.push(`${claimText} - ΚΑΙ ΞΕΡΗ!`);
                } else {
                    options.push(claimText);
                }
            }
        });
        
        const selected = prompt(message + '\n\n' + options.map((opt, idx) => `${idx + 1}. ${opt}`).join('\n'));
        
        if (selected !== null) {
            const optionIndex = parseInt(selected) - 1;
            
            if (optionIndex === 0) {
                // Simple discard
                this.playCard(card.id, []);
            } else if (optionIndex > 0 && optionIndex - 1 < moves.length) {
                const move = moves[optionIndex - 1];
                const claimedCards = Array.isArray(move.claimed) 
                    ? move.claimed.map(c => c.id) 
                    : [move.claimed.id];
                
                this.playCard(card.id, claimedCards);
            }
        }
    }
    
    static playCard(cardId, claimedCardIds) {
        $.ajax({
            url: 'api/move.php',
            method: 'POST',
            data: {
                action: 'play_card',
                game_id: this.gameId,
                card_id: cardId,
                claimed_cards: claimedCardIds
            },
            success: (response) => {
                if (response.success) {
                    this.showMessage('Κάρτα παίχτηκε!', 'success');
                    
                    if (response.is_xeri) {
                        this.showMessage('ΣΥΓΧΑΡΗΤΗΡΙΑ! Έκανες ΞΕΡΗ! 🎉', 'warning');
                        $('#btn-claim-xeri').prop('disabled', false);
                    }
                    
                    // Reload game state
                    setTimeout(() => this.loadGameState(), 500);
                } else {
                    this.showMessage(response.message || 'Σφάλμα', 'danger');
                }
            }
        });
    }
    
    static drawCard() {
        if (!this.isMyTurn) {
            this.showMessage('Δεν είναι η σειρά σου!', 'warning');
            return;
        }
        
        $.ajax({
            url: 'api/move.php',
            method: 'POST',
            data: {
                action: 'draw_card',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    this.showMessage('Τράβηξες μια κάρτα!', 'success');
                    setTimeout(() => this.loadGameState(), 500);
                } else {
                    this.showMessage(response.message || 'Σφάλμα', 'danger');
                }
            }
        });
    }
    
    static passTurn() {
        if (!this.isMyTurn) {
            this.showMessage('Δεν είναι η σειρά σου!', 'warning');
            return;
        }
        
        $.ajax({
            url: 'api/move.php',
            method: 'POST',
            data: {
                action: 'pass_turn',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    this.showMessage('Παράτησες τη σειρά σου', 'info');
                    setTimeout(() => this.loadGameState(), 500);
                }
            }
        });
    }
    
    static claimXeri() {
        $.ajax({
            url: 'api/move.php',
            method: 'POST',
            data: {
                action: 'claim_xeri',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    this.showMessage('Ξερή καταγράφηκε!', 'success');
                    $('#btn-claim-xeri').prop('disabled', true);
                }
            }
        });
    }
    
    // Helper methods
    static getRankSymbol(rank) {
        const symbols = {
            'A': 'A', '2': '2', '3': '3', '4': '4', '5': '5',
            '6': '6', '7': '7', '8': '8', '9': '9', '10': '10',
            'J': 'J', 'Q': 'Q', 'K': 'K'
        };
        return symbols[rank] || rank;
    }
    
    static getSuitSymbol(suit) {
        const symbols = {
            'hearts': '♥',
            'diamonds': '♦',
            'clubs': '♣',
            'spades': '♠'
        };
        return symbols[suit] || suit;
    }
    
    static showMessage(text, type = 'info') {
        console.log(`[${type.toUpperCase()}] ${text}`);
        
        // Add to move log
        const timestamp = new Date().toLocaleTimeString();
        const $log = $('#move-log');
        $log.prepend(`<li><small class="text-muted">[${timestamp}]</small> ${text}</li>`);
        
        // Keep log manageable
        if ($log.children().length > 20) {
            $log.children().last().remove();
        }
        
        // Show in game message area if it's important
        if (type === 'warning' || type === 'danger') {
            const $gameMsg = $('#game-message');
            $gameMsg.removeClass('alert-info alert-success alert-danger alert-warning')
                   .addClass('alert-' + type)
                   .show();
            $('#message-text').text(text);
            
            setTimeout(() => {
                $gameMsg.fadeOut();
            }, 3000);
        }
    }
    
    static gameOver(results) {
        clearInterval(this.pollInterval);
        
        $('#final-my-score').text(results.myScore || 0);
        $('#final-opponent-score').text(results.opponentScore || 0);
        $('#final-my-xeri').text(results.myXeri || 0);
        $('#final-opponent-xeri').text(results.opponentXeri || 0);
        
        if (results.winner === this.playerNumber) {
            $('#game-result-title').text('ΝΙΚΗΣΕΣ! 🏆').addClass('text-success');
        } else if (results.winner === 0) {
            $('#game-result-title').text('ΙΣΟΠΑΛΙΑ!').addClass('text-warning');
        } else {
            $('#game-result-title').text('ΗΤΤΑ!').addClass('text-danger');
        }
        
        $('#gameOverModal').modal('show');
    }
}

// Global event handlers
$(document).ready(function() {
    // Draw card button
    $('#btn-draw-card').click(() => {
        GameEngine.drawCard();
    });
    
    // Pass turn button
    $('#btn-pass-turn').click(() => {
        if (confirm('Είσαι σίγουρος ότι θέλεις να παρατήσεις τη σειρά σου;')) {
            GameEngine.passTurn();
        }
    });
    
    // Claim xeri button
    $('#btn-claim-xeri').click(() => {
        GameEngine.claimXeri();
    });
});