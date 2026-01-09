// js/xeri-game-engine.js
class XeriGameEngine {
    constructor() {
        this.gameId = 0;
        this.playerNumber = 0;
        this.userId = 0;
        this.username = '';
        this.isGuest = false;
        
        this.isMyTurn = false;
        this.gameState = {};
        this.pollInterval = null;
        this.aiPollInterval = null;
        
        // Game data
        this.hand = [];
        this.tableCards = [];
        this.stockSize = 52;
        this.myScore = 0;
        this.opponentScore = 0;
        this.myHandSize = 0;
        this.opponentHandSize = 0;
        
        // API Client
        this.api = window.xeriAPI || null;
        
        this.init();
    }

    init() {
        console.log('XeriGameEngine initialized');
        
        // Get data from session
        const session = this.api ? this.api.getSessionData() : this.getSessionData();
        this.userId = session.user_id;
        this.username = session.username;
        this.isGuest = session.is_guest;
        
        // Get game data from PHP (set in game.php)
        if (typeof window.GameEngine !== 'undefined') {
            this.gameId = window.GameEngine.gameId || 0;
            this.playerNumber = window.GameEngine.playerNumber || 0;
        } else {
            // Try to get from API
            this.gameId = this.api ? this.api.getCurrentGameId() : 0;
        }
        
        // If we have a game, start polling
        if (this.gameId > 0) {
            this.loadGameState();
            this.startPolling();
            this.startAIPolling();
        }
        
        this.bindEvents();
        this.updateUI();
        
        // Trigger event for other components
        setTimeout(() => {
            $(window).trigger('gameEngineReady', [this]);
        }, 500);
    }
    
    getSessionData() {
        return {
            user_id: parseInt(sessionStorage.getItem('xeri_user_id') || '0'),
            username: sessionStorage.getItem('xeri_username') || '',
            is_guest: sessionStorage.getItem('xeri_is_guest') === 'true'
        };
    }

    bindEvents() {
        // New game buttons
        $('#btn-new-game, #btn-new-vs-computer, #btn-new-vs-human').click(() => {
            $('#newGameModal').modal('show');
        });

        $('#btn-create-game').click(() => {
            this.createNewGame();
        });

        // Game controls
        $('#btn-draw-card').click(() => this.drawCard());
        $('#btn-pass-turn').click(() => this.passTurn());
        
        // Logout
        $('#btn-logout').click(() => {
            if (confirm('Αποσύνδεση;')) {
                this.logout();
            }
        });
        
        // Surrender
        $('#btn-surrender').click(() => {
            if (confirm('Παράδοση παιχνιδιού;')) {
                this.surrenderGame();
            }
        });

        // Auto-update when modal closes
        $('#newGameModal').on('hidden.bs.modal', () => {
            if (this.gameId > 0) {
                this.loadGameState();
            }
        });
        
        // Join game buttons
        $(document).on('click', '.join-game-btn', (e) => {
            const gameId = $(e.target).data('game-id');
            this.joinGame(gameId);
        });
        
        // Hint button
        $('#btn-hint').click(() => {
            this.showHint();
        });
    }

    startPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
        }

        // Poll every 3 seconds for game updates
        this.pollInterval = setInterval(() => {
            this.loadGameState();
        }, 3000);
    }

    startAIPolling() {
        if (this.aiPollInterval) {
            clearInterval(this.aiPollInterval);
        }

        // Check for AI turn every 2 seconds
        this.aiPollInterval = setInterval(() => {
            this.checkForAITurn();
        }, 2000);
    }

    createNewGame() {
        const gameType = $('#game-type').val();
        const difficulty = $('#ai-difficulty').val();

        $('#newGameModal').modal('hide');
        this.showMessage('Δημιουργία παιχνιδιού...', 'info');

        if (this.api) {
            this.api.createGame(gameType, difficulty)
                .then(response => this.handleCreateGameResponse(response));
        } else {
            // Fallback to old AJAX
            this.createNewGameLegacy(gameType, difficulty);
        }
    }
    
    handleCreateGameResponse(response) {
        if (response.success) {
            this.gameId = response.data.game_id;
            this.playerNumber = response.data.player_number;
            
            // Save game ID to API client
            if (this.api) {
                this.api.setCurrentGameId(this.gameId);
            }
            
            this.showMessage('Παιχνίδι δημιουργήθηκε!', 'success');
            
            // Update UI
            this.updateGameInfo();
            
            // Start polling
            setTimeout(() => {
                this.loadGameState();
                this.startPolling();
                this.startAIPolling();
            }, 1000);
            
        } else {
            this.showMessage('Σφάλμα: ' + response.message, 'danger');
        }
    }
    
    createNewGameLegacy(gameType, difficulty) {
        $.ajax({
            url: 'api/game.php',
            method: 'POST',
            data: {
                action: 'create_game',
                game_type: gameType,
                difficulty: difficulty
            },
            success: (response) => {
                if (response.success) {
                    this.gameId = response.data.game_id;
                    this.playerNumber = response.data.player_number;
                    
                    this.showMessage('Παιχνίδι δημιουργήθηκε!', 'success');
                    
                    // Update UI
                    this.updateGameInfo();
                    
                    // Start polling
                    setTimeout(() => {
                        this.loadGameState();
                        this.startPolling();
                        this.startAIPolling();
                    }, 1000);
                    
                } else {
                    this.showMessage('Σφάλμα: ' + response.message, 'danger');
                }
            },
            error: (xhr, status, error) => {
                console.error('Error creating game:', error);
                this.showMessage('Σφάλμα σύνδεσης', 'danger');
            }
        });
    }

    joinGame(gameId) {
        this.showMessage('Σύνδεση σε παιχνίδι...', 'info');
        
        if (this.api) {
            this.api.joinGame(gameId)
                .then(response => this.handleJoinGameResponse(response));
        } else {
            this.joinGameLegacy(gameId);
        }
    }
    
    handleJoinGameResponse(response) {
        if (response.success) {
            this.gameId = response.data.game_id;
            this.playerNumber = response.data.player_number;
            
            // Save game ID to API client
            if (this.api) {
                this.api.setCurrentGameId(this.gameId);
            }
            
            this.showMessage('Μπήκατε στο παιχνίδι!', 'success');
            
            // Update UI
            this.updateGameInfo();
            
            // Start polling
            setTimeout(() => {
                this.loadGameState();
                this.startPolling();
                this.startAIPolling();
            }, 1000);
            
        } else {
            this.showMessage('Σφάλμα: ' + response.message, 'danger');
        }
    }
    
    joinGameLegacy(gameId) {
        $.ajax({
            url: 'api/game.php',
            method: 'POST',
            data: {
                action: 'join_game',
                game_id: gameId
            },
            success: (response) => {
                if (response.success) {
                    this.gameId = response.data.game_id;
                    this.playerNumber = response.data.player_number;
                    
                    this.showMessage('Μπήκατε στο παιχνίδι!', 'success');
                    
                    // Update UI
                    this.updateGameInfo();
                    
                    // Start polling
                    setTimeout(() => {
                        this.loadGameState();
                        this.startPolling();
                        this.startAIPolling();
                    }, 1000);
                    
                } else {
                    this.showMessage('Σφάλμα: ' + response.message, 'danger');
                }
            },
            error: (xhr, status, error) => {
                console.error('Error joining game:', error);
                this.showMessage('Σφάλμα σύνδεσης', 'danger');
            }
        });
    }

    loadGameState() {
        if (!this.gameId || this.gameId <= 0) {
            console.log('No game ID to load');
            return;
        }
        
        if (this.api) {
            this.api.getGameState(this.gameId)
                .then(response => this.handleGameStateResponse(response));
        } else {
            this.loadGameStateLegacy();
        }
    }
    
    handleGameStateResponse(response) {
        if (response.success) {
            this.updateGameData(response.data);
            this.renderGame();
            
            // Check if game is over
            if (response.data.status === 'finished') {
                this.handleGameOver(response.data);
            }
            
        } else {
            if (response.message.includes('δεν βρέθηκε') || 
                response.message.includes('πρόσβαση')) {
                // Game no longer exists or we lost access
                this.resetGame();
            }
        }
    }
    
    loadGameStateLegacy() {
        $.ajax({
            url: 'api/game.php',
            method: 'POST',
            data: {
                action: 'get_game_state',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    this.updateGameData(response.data);
                    this.renderGame();
                    
                    // Check if game is over
                    if (response.data.status === 'finished') {
                        this.handleGameOver(response.data);
                    }
                    
                } else {
                    if (response.message.includes('δεν βρέθηκε') || 
                        response.message.includes('πρόσβαση')) {
                        // Game no longer exists or we lost access
                        this.resetGame();
                    }
                }
            },
            error: (xhr, status, error) => {
                console.error('Error loading game state:', error);
            }
        });
    }

    updateGameData(gameData) {
        this.gameState = gameData;
        
        // Update game info
        this.gameId = gameData.game_id;
        this.playerNumber = gameData.my_player_number;
        this.isMyTurn = gameData.can_i_play;
        
        // Update game data
        this.hand = gameData.hand || [];
        this.tableCards = gameData.table_cards || [];
        this.stockSize = gameData.stock_size || 0;
        this.myScore = gameData.my_score || 0;
        this.opponentScore = gameData.opponent_score || 0;
        this.myHandSize = gameData.my_hand_size || 0;
        this.opponentHandSize = gameData.opponent_hand_size || 0;
        
        // Update opponent info
        if (this.playerNumber === 1) {
            this.opponentName = gameData.player2_name || 'Αντίπαλος';
        } else {
            this.opponentName = gameData.player1_name || 'Αντίπαλος';
        }
        
        // Update UI handler if exists
        if (window.uiHandler) {
            window.uiHandler.updateTurnIndicator(this.isMyTurn);
        }
    }

    renderGame() {
        if (window.boardRenderer) {
            window.boardRenderer.renderGameState(this.gameState);
        } else {
            // Fallback to old render methods
            this.updateGameInfo();
            this.updateScores();
            this.updateCardCounts();
            this.renderPlayerHand();
            this.renderTableCards();
            this.updateControls();
        }
        
        // Update turn indicator
        this.updateTurnIndicator();
    }

    updateGameInfo() {
        $('#game-id').text(this.gameId);
        $('#player-number').text(this.playerNumber);
        
        // Update game status
        const $status = $('#game-status');
        if (this.gameState.status === 'active') {
            if (this.isMyTurn) {
                $status.text('ΣΕΙΡΑ ΣΟΥ').removeClass('bg-success bg-secondary').addClass('bg-warning');
            } else {
                $status.text('Ενεργό').removeClass('bg-warning').addClass('bg-success');
            }
        } else if (this.gameState.status === 'waiting') {
            $status.text('Αναμονή').removeClass('bg-success bg-warning').addClass('bg-secondary');
        } else if (this.gameState.status === 'finished') {
            $status.text('Τελειωμένο').removeClass('bg-success bg-warning').addClass('bg-danger');
        }
    }

    updateScores() {
        $('#my-score').text(this.myScore);
        $('#opponent-score').text(this.opponentScore);
    }

    updateCardCounts() {
        $('#my-cards-count').text(this.myHandSize + ' κάρτες');
        $('#opponent-cards-count').text(this.opponentHandSize + ' κάρτες');
        $('#stock-count').text(this.stockSize + ' κάρτες');
    }

    renderPlayerHand() {
        const $handContainer = $('#player-hand');
        $handContainer.empty();
        
        if (this.hand.length === 0) {
            $handContainer.html('<div class="empty-hand text-muted">Κανένα φύλλο στο χέρι</div>');
            return;
        }
        
        this.hand.forEach(card => {
            const $card = $(`
                <div class="card-item" data-card-id="${card.id}" 
                     data-card-symbol="${card.symbol}" 
                     data-card-rank="${card.rank}" 
                     data-card-suit="${card.suit}"
                     title="${card.symbol}">
                    <div class="card-value">${this.getRankSymbol(card.rank)}</div>
                    <div class="card-suit ${card.suit}">${this.getSuitSymbol(card.suit)}</div>
                </div>
            `);
            
            // Add click event for playing the card
            if (this.isMyTurn) {
                $card.addClass('playable');
                $card.click(() => {
                    this.playCard(card.id);
                });
            }
            
            $handContainer.append($card);
        });
    }

    renderTableCards() {
        const $tableContainer = $('#table-cards-container');
        $tableContainer.empty();
        
        if (this.tableCards.length === 0) {
            $tableContainer.html('<div class="empty-table text-muted">Κανένα φύλλο στο τραπέζι</div>');
            return;
        }
        
        this.tableCards.forEach(card => {
            const $card = $(`
                <div class="table-card-item" data-card-id="${card.id}" 
                     title="${card.symbol}">
                    <div class="card-value">${this.getRankSymbol(card.rank)}</div>
                    <div class="card-suit ${card.suit}">${this.getSuitSymbol(card.suit)}</div>
                </div>
            `);
            
            $tableContainer.append($card);
        });
    }

    updateControls() {
        // Enable/disable buttons based on turn
        $('#btn-draw-card').prop('disabled', !this.isMyTurn);
        $('#btn-pass-turn').prop('disabled', !this.isMyTurn);
        $('#btn-hint').prop('disabled', !this.isMyTurn);
        
        // Update button text
        if (this.isMyTurn) {
            $('#btn-draw-card').html('<i class="fas fa-download"></i> Τράβηξε');
            $('#btn-pass-turn').html('<i class="fas fa-forward"></i> Παράτα');
        }
    }
    
    updateTurnIndicator() {
        if (!this.isMyTurn && window.uiHandler) {
            window.uiHandler.updateTurnIndicator(false);
        }
    }

    playCard(cardId) {
        if (!this.isMyTurn) {
            this.showMessage('Δεν είναι η σειρά σου!', 'warning');
            return;
        }
        
        // First check what we can do with this card
        if (this.api) {
            this.api.getValidMoves(this.gameId)
                .then(response => this.handleValidMovesResponse(response, cardId));
        } else {
            this.playCardLegacy(cardId);
        }
    }
    
    handleValidMovesResponse(response, cardId) {
        if (response.success) {
            const movesForThisCard = response.data.valid_moves.filter(
                move => move.card.id === cardId
            );
            
            if (movesForThisCard.length > 0) {
                const move = movesForThisCard[0];
                
                if (move.can_claim && move.claimable_cards.length > 0) {
                    // Show options for claiming
                    this.showCardOptions(move);
                } else {
                    // Simple discard
                    this.executePlayCard(cardId, []);
                }
            } else {
                this.executePlayCard(cardId, []);
            }
        } else {
            // If error, try simple play
            this.executePlayCard(cardId, []);
        }
    }
    
    playCardLegacy(cardId) {
        // First check what we can do with this card
        $.ajax({
            url: 'api/move.php',
            method: 'POST',
            data: {
                action: 'get_valid_moves',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    const movesForThisCard = response.data.valid_moves.filter(
                        move => move.card.id === cardId
                    );
                    
                    if (movesForThisCard.length > 0) {
                        const move = movesForThisCard[0];
                        
                        if (move.can_claim && move.claimable_cards.length > 0) {
                            // Show options for claiming
                            this.showCardOptions(move);
                        } else {
                            // Simple discard
                            this.executePlayCard(cardId, []);
                        }
                    } else {
                        this.executePlayCard(cardId, []);
                    }
                }
            },
            error: () => {
                // If error, try simple play
                this.executePlayCard(cardId, []);
            }
        });
    }

    showCardOptions(move) {
        const card = move.card;
        let message = `Τι θέλεις να κάνεις με το ${card.symbol}?`;
        const options = [];
        
        // Option 1: Simple discard
        options.push({
            text: 'Απλή απόρριψη (ρίξε στο τραπέζι)',
            action: () => this.executePlayCard(card.id, [])
        });
        
        // Option 2: Claim cards (if possible)
        if (move.can_claim) {
            if (move.move_type === 'valet_capture') {
                options.push({
                    text: `Πάρε όλες τις κάρτες (Βαλές)`,
                    action: () => {
                        const claimIds = move.claimable_cards.map(c => c.id);
                        this.executePlayCard(card.id, claimIds);
                    }
                });
            } else if (move.move_type === 'capture') {
                move.claimable_cards.forEach(claimable => {
                    const isXeri = move.is_xeri_possible && move.claimable_cards.length === 1;
                    const xeriText = isXeri ? ' - ΚΑΙ ΞΕΡΗ!' : '';
                    
                    options.push({
                        text: `Πάρε το ${claimable.symbol}${xeriText}`,
                        action: () => this.executePlayCard(card.id, [claimable.id])
                    });
                });
            }
        }
        
        // Create modal for options
        this.createOptionsModal(message, options);
    }

    createOptionsModal(message, options) {
        // Remove existing modal if any
        $('#card-options-modal').remove();
        
        // Create modal HTML
        const modalHtml = `
            <div class="modal fade" id="card-options-modal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="fas fa-question-circle"></i> Επιλογή Κίνησης</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>${message}</p>
                            <div class="list-group">
                                ${options.map((option, index) => `
                                    <button type="button" class="list-group-item list-group-item-action" 
                                            data-action-index="${index}">
                                        ${option.text}
                                    </button>
                                `).join('')}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Άκυρο</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Add to body and show
        $('body').append(modalHtml);
        const $modal = $('#card-options-modal');
        
        // Add click handlers
        $modal.find('.list-group-item').click(function() {
            const index = $(this).data('action-index');
            options[index].action();
            $modal.modal('hide');
        });
        
        $modal.modal('show');
        
        // Remove modal when hidden
        $modal.on('hidden.bs.modal', function() {
            $(this).remove();
        });
    }

    executePlayCard(cardId, claimedCardIds) {
        if (!this.isMyTurn) return;
        
        this.showMessage('Παίξιμο κάρτας...', 'info');
        
        if (this.api) {
            this.api.playCard(this.gameId, cardId, claimedCardIds)
                .then(response => this.handlePlayCardResponse(response));
        } else {
            this.executePlayCardLegacy(cardId, claimedCardIds);
        }
    }
    
    handlePlayCardResponse(response) {
        if (response.success) {
            if (response.data.is_xeri) {
                this.showMessage('ΣΥΓΧΑΡΗΤΗΡΙΑ! Έκανες ΞΕΡΗ! 🎉', 'success', 5000);
                this.addToLog(`ΞΕΡΗ! Πήρες ${response.data.claimed_count} κάρτα(ες)`);
                
                // Show xeri animation if renderer exists
                if (window.boardRenderer && window.boardRenderer.showXeriAnimation) {
                    window.boardRenderer.showXeriAnimation(response.data);
                }
            } else if (response.data.claimed_count > 0) {
                this.showMessage(`Πήρες ${response.data.claimed_count} κάρτα(ες)!`, 'success');
                this.addToLog(`Πήρες ${response.data.claimed_count} κάρτα(ες)`);
                
                // Show capture animation
                if (window.boardRenderer && window.boardRenderer.showCaptureAnimation) {
                    window.boardRenderer.showCaptureAnimation(response.data.claimed_cards);
                }
            } else {
                this.showMessage('Κάρτα παίχτηκε', 'info');
            }
            
            // Play sound
            if (window.uiHandler) {
                window.uiHandler.playSound('card_play');
            }
            
            // Reload game state
            setTimeout(() => {
                this.loadGameState();
            }, 1000);
            
        } else {
            this.showMessage(response.message || 'Σφάλμα', 'danger');
        }
    }
    
    executePlayCardLegacy(cardId, claimedCardIds) {
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
                    if (response.data.is_xeri) {
                        this.showMessage('ΣΥΓΧΑΡΗΤΗΡΙΑ! Έκανες ΞΕΡΗ! 🎉', 'success', 5000);
                        this.addToLog(`ΞΕΡΗ! Πήρες ${response.data.claimed_count} κάρτα(ες)`);
                    } else if (response.data.claimed_count > 0) {
                        this.showMessage(`Πήρες ${response.data.claimed_count} κάρτα(ες)!`, 'success');
                        this.addToLog(`Πήρες ${response.data.claimed_count} κάρτα(ες)`);
                    } else {
                        this.showMessage('Κάρτα παίχτηκε', 'info');
                    }
                    
                    // Reload game state
                    setTimeout(() => {
                        this.loadGameState();
                    }, 1000);
                    
                } else {
                    this.showMessage(response.message || 'Σφάλμα', 'danger');
                }
            },
            error: (xhr, status, error) => {
                console.error('Error playing card:', error);
                this.showMessage('Σφάλμα σύνδεσης', 'danger');
            }
        });
    }

    drawCard() {
        if (!this.isMyTurn) {
            this.showMessage('Δεν είναι η σειρά σου!', 'warning');
            return;
        }
        
        this.showMessage('Τράβηγμα κάρτας...', 'info');
        
        if (this.api) {
            this.api.drawCard(this.gameId)
                .then(response => this.handleDrawCardResponse(response));
        } else {
            this.drawCardLegacy();
        }
    }
    
    handleDrawCardResponse(response) {
        if (response.success) {
            this.showMessage('Τράβηξες μια κάρτα!', 'success');
            this.addToLog('Τράβηξες κάρτα από την τράπουλα');
            
            // Play sound
            if (window.uiHandler) {
                window.uiHandler.playSound('card_draw');
            }
            
            setTimeout(() => {
                this.loadGameState();
            }, 1000);
        } else {
            this.showMessage(response.message || 'Σφάλμα', 'danger');
        }
    }
    
    drawCardLegacy() {
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
                    this.addToLog('Τράβηξες κάρτα από την τράπουλα');
                    
                    setTimeout(() => {
                        this.loadGameState();
                    }, 1000);
                } else {
                    this.showMessage(response.message || 'Σφάλμα', 'danger');
                }
            },
            error: (xhr, status, error) => {
                console.error('Error drawing card:', error);
                this.showMessage('Σφάλμα σύνδεσης', 'danger');
            }
        });
    }

    passTurn() {
        if (!this.isMyTurn) {
            this.showMessage('Δεν είναι η σειρά σου!', 'warning');
            return;
        }
        
        if (window.uiHandler) {
            window.uiHandler.showConfirm(
                'Παράτηση Σειράς',
                'Είσαι σίγουρος ότι θέλεις να παρατήσεις τη σειρά σου;',
                () => this.confirmPassTurn(),
                () => console.log('Pass cancelled')
            );
        } else {
            if (!confirm('Είσαι σίγουρος ότι θέλεις να παρατήσεις τη σειρά σου;')) {
                return;
            }
            this.confirmPassTurn();
        }
    }
    
    confirmPassTurn() {
        this.showMessage('Παράτηση σειράς...', 'info');
        
        if (this.api) {
            this.api.passTurn(this.gameId)
                .then(response => this.handlePassTurnResponse(response));
        } else {
            this.passTurnLegacy();
        }
    }
    
    handlePassTurnResponse(response) {
        if (response.success) {
            this.showMessage('Παράτησες τη σειρά σου', 'info');
            this.addToLog('Παρέδωσες τη σειρά σου');
            
            setTimeout(() => {
                this.loadGameState();
            }, 1000);
        }
    }
    
    passTurnLegacy() {
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
                    this.addToLog('Παρέδωσες τη σειρά σου');
                    
                    setTimeout(() => {
                        this.loadGameState();
                    }, 1000);
                }
            },
            error: (xhr, status, error) => {
                console.error('Error passing turn:', error);
                this.showMessage('Σφάλμα σύνδεσης', 'danger');
            }
        });
    }
    
    surrenderGame() {
        if (!this.gameId) return;
        
        if (window.uiHandler) {
            window.uiHandler.showConfirm(
                'Παράδοση Παιχνιδιού',
                'Είσαι σίγουρος ότι θέλεις να παραδώσεις το παιχνίδι;',
                () => this.confirmSurrender(),
                () => console.log('Surrender cancelled')
            );
        } else {
            if (!confirm('Είσαι σίγουρος ότι θέλεις να παραδώσεις το παιχνίδι;')) {
                return;
            }
            this.confirmSurrender();
        }
    }
    
    confirmSurrender() {
        this.showMessage('Παράδοση παιχνιδιού...', 'info');
        
        if (this.api) {
            this.api.surrenderGame(this.gameId)
                .then(response => this.handleSurrenderResponse(response));
        } else {
            // Legacy surrender
            $.ajax({
                url: 'api/game.php',
                method: 'POST',
                data: {
                    action: 'surrender',
                    game_id: this.gameId
                },
                success: (response) => {
                    if (response.success) {
                        this.showMessage('Παραδώσατε το παιχνίδι', 'info');
                        setTimeout(() => {
                            this.loadGameState();
                        }, 1000);
                    }
                }
            });
        }
    }
    
    handleSurrenderResponse(response) {
        if (response.success) {
            this.showMessage('Παραδώσατε το παιχνίδι', 'info');
            setTimeout(() => {
                this.loadGameState();
            }, 1000);
        }
    }
    
    logout() {
        if (this.api) {
            this.api.logout()
                .then(() => {
                    this.api.clearSessionData();
                    window.location.href = 'logout.php';
                });
        } else {
            this.clearSessionData();
            window.location.href = 'logout.php';
        }
    }
    
    clearSessionData() {
        sessionStorage.removeItem('xeri_user_id');
        sessionStorage.removeItem('xeri_username');
        sessionStorage.removeItem('xeri_is_guest');
        sessionStorage.removeItem('xeri_current_game');
    }

    checkForAITurn() {
        if (this.gameId > 0 && !this.isMyTurn) {
            // Check if it's AI's turn
            if (this.api) {
                this.api.getAIState(this.gameId)
                    .then(response => {
                        if (response.success && response.data.is_computer_turn) {
                            // AI plays automatically after a short delay
                            setTimeout(() => {
                                this.processAITurn();
                            }, 1500);
                        }
                    });
            } else {
                // Legacy AI check
                $.ajax({
                    url: 'api/ai.php',
                    method: 'POST',
                    data: {
                        action: 'get_ai_state',
                        game_id: this.gameId
                    },
                    success: (response) => {
                        if (response.success && response.data.is_computer_turn) {
                            // AI plays automatically after a short delay
                            setTimeout(() => {
                                this.processAITurn();
                            }, 1500);
                        }
                    }
                });
            }
        }
    }

    processAITurn() {
        if (this.api) {
            this.api.processAITurn(this.gameId)
                .then(response => this.handleAITurnResponse(response));
        } else {
            this.processAITurnLegacy();
        }
    }
    
    handleAITurnResponse(response) {
        if (response.success) {
            // Add to log
            let logMessage = 'Computer ';
            
            if (response.data.action === 'play') {
                logMessage += `έπαιξε ${response.data.card.symbol}`;
                if (response.data.claimed_count > 0) {
                    logMessage += ` και πήρε ${response.data.claimed_count} κάρτα(ες)`;
                    if (response.data.is_xeri) {
                        logMessage += ' (ΞΕΡΗ!)';
                    }
                }
            } else if (response.data.action === 'draw') {
                logMessage += 'τράβηξε κάρτα';
            } else if (response.data.action === 'pass') {
                logMessage += 'παρέδωσε τη σειρά';
            }
            
            this.addToLog(logMessage);
            
            // Play sound
            if (window.uiHandler) {
                window.uiHandler.playSound('ai_move');
            }
            
            // Reload game state
            setTimeout(() => {
                this.loadGameState();
            }, 1000);
        }
    }
    
    processAITurnLegacy() {
        $.ajax({
            url: 'api/ai.php',
            method: 'POST',
            data: {
                action: 'process_turn',
                game_id: this.gameId
            },
            success: (response) => {
                if (response.success) {
                    // Add to log
                    let logMessage = 'Computer ';
                    
                    if (response.data.action === 'play') {
                        logMessage += `έπαιξε ${response.data.card.symbol}`;
                        if (response.data.claimed_count > 0) {
                            logMessage += ` και πήρε ${response.data.claimed_count} κάρτα(ες)`;
                            if (response.data.is_xeri) {
                                logMessage += ' (ΞΕΡΗ!)';
                            }
                        }
                    } else if (response.data.action === 'draw') {
                        logMessage += 'τράβηξε κάρτα';
                    } else if (response.data.action === 'pass') {
                        logMessage += 'παρέδωσε τη σειρά';
                    }
                    
                    this.addToLog(logMessage);
                    
                    // Reload game state
                    setTimeout(() => {
                        this.loadGameState();
                    }, 1000);
                }
            }
        });
    }
    
    showHint() {
        if (!this.isMyTurn) {
            this.showMessage('Δεν είναι η σειρά σου!', 'warning');
            return;
        }
        
        if (window.uiHandler) {
            window.uiHandler.showMoveHint();
        } else {
            this.showMessage('Πατήστε μια κάρτα για να παίξετε', 'info');
        }
    }

    handleGameOver(gameData) {
        // Stop polling
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
        
        if (this.aiPollInterval) {
            clearInterval(this.aiPollInterval);
            this.aiPollInterval = null;
        }
        
        // Disable controls
        this.isMyTurn = false;
        this.updateControls();
        
        // Stop timer if exists
        if (window.uiHandler) {
            window.uiHandler.stopGameTimer();
        }
        
        // Clear game ID from API
        if (this.api) {
            this.api.setCurrentGameId(0);
        }
        
        // Show game over modal
        this.showGameOverModal(gameData);
    }

    showGameOverModal(gameData) {
        // Determine winner
        let winnerText = '';
        let winnerClass = '';
        
        if (gameData.winner_player_number === this.playerNumber) {
            winnerText = 'ΝΙΚΗΣΕΣ! 🏆';
            winnerClass = 'text-success';
            
            // Play win sound
            if (window.uiHandler) {
                window.uiHandler.playSound('game_win');
            }
        } else if (gameData.winner_player_number === 0) {
            winnerText = 'ΙΣΟΠΑΛΙΑ!';
            winnerClass = 'text-warning';
            
            // Play draw sound
            if (window.uiHandler) {
                window.uiHandler.playSound('game_draw');
            }
        } else {
            winnerText = 'ΗΤΤΑ!';
            winnerClass = 'text-danger';
            
            // Play lose sound
            if (window.uiHandler) {
                window.uiHandler.playSound('game_lose');
            }
        }
        
        // Create modal HTML
        const modalHtml = `
            <div class="modal fade" id="gameOverModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-dark text-white">
                            <h5 class="modal-title"><i class="fas fa-trophy"></i> Τέλος Παιχνιδιού</h5>
                        </div>
                        <div class="modal-body text-center">
                            <h3 class="${winnerClass} mb-4">${winnerText}</h3>
                            
                            <div class="row mb-4">
                                <div class="col-6">
                                    <h5>${this.username}</h5>
                                    <div class="display-4">${this.myScore}</div>
                                    <small>πόντοι</small>
                                </div>
                                <div class="col-6">
                                    <h5>${this.opponentName}</h5>
                                    <div class="display-4">${this.opponentScore}</div>
                                    <small>πόντοι</small>
                                </div>
                            </div>
                            
                            <div class="game-stats mb-4">
                                <p><i class="fas fa-cards"></i> Κάρτες στο χέρι: ${this.myHandSize}</p>
                                <p><i class="fas fa-table"></i> Κάρτες στο τραπέζι: ${this.tableCards.length}</p>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary" id="btn-play-again">
                                    <i class="fas fa-redo"></i> Νέο Παιχνίδι
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-home"></i> Αρχική Σελίδα
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Add to body and show
        $('body').append(modalHtml);
        const $modal = $('#gameOverModal');
        
        // Make modal non-dismissible
        $modal.modal({
            backdrop: 'static',
            keyboard: false
        });
        
        $modal.modal('show');
        
        // Add event for play again
        $modal.on('click', '#btn-play-again', () => {
            $modal.modal('hide');
            setTimeout(() => {
                $('#newGameModal').modal('show');
            }, 500);
        });
        
        // Clean up when modal is hidden
        $modal.on('hidden.bs.modal', function() {
            $(this).remove();
        });
    }

    resetGame() {
        this.gameId = 0;
        this.playerNumber = 0;
        this.isMyTurn = false;
        
        // Stop polling
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
        
        if (this.aiPollInterval) {
            clearInterval(this.aiPollInterval);
            this.aiPollInterval = null;
        }
        
        // Clear game ID from API
        if (this.api) {
            this.api.setCurrentGameId(0);
        }
        
        // Stop timer if exists
        if (window.uiHandler) {
            window.uiHandler.stopGameTimer();
        }
        
        // Reset UI
        this.updateUI();
    }

    updateUI() {
        $('#game-id').text(this.gameId || '-');
        $('#player-number').text(this.playerNumber || '-');
        $('#game-status').text(this.gameId ? 'Ενεργό' : 'Χωρίς παιχνίδι')
            .removeClass('bg-success bg-warning bg-danger')
            .addClass(this.gameId ? 'bg-success' : 'bg-secondary');
        
        $('#my-score').text('0');
        $('#opponent-score').text('0');
        
        $('#my-cards-count').text('0 κάρτες');
        $('#opponent-cards-count').text('0 κάρτες');
        $('#stock-count').text('52 κάρτες');
        
        $('#player-hand').html('<div class="empty-hand text-muted">Δεν έχετε ενεργό παιχνίδι</div>');
        $('#table-cards-container').html('<div class="empty-table text-muted">Κανένα φύλλο στο τραπέζι</div>');
        
        $('#btn-draw-card').prop('disabled', true);
        $('#btn-pass-turn').prop('disabled', true);
        $('#btn-hint').prop('disabled', true);
        $('#btn-surrender').prop('disabled', !this.gameId);
    }

    addToLog(message) {
        const timestamp = new Date().toLocaleTimeString('el-GR', { 
            hour: '2-digit', 
            minute: '2-digit',
            second: '2-digit'
        });
        
        const $log = $('#move-log');
        const $logItem = $(`
            <li>
                <small class="text-muted">[${timestamp}]</small> ${message}
            </li>
        `);
        
        $log.prepend($logItem);
        
        // Keep only last 20 items
        if ($log.children().length > 20) {
            $log.children().last().remove();
        }
        
        // Play log sound
        if (window.uiHandler && message.includes('ΞΕΡΗ')) {
            window.uiHandler.playSound('xeri');
        }
    }

    showMessage(text, type = 'info', duration = 3000) {
        const $message = $('#game-message');
        const $text = $('#message-text');
        
        // Remove all alert classes
        $message.removeClass('alert-info alert-success alert-danger alert-warning');
        
        // Add the correct class
        $message.addClass('alert-' + type);
        
        // Set text and show
        $text.text(text);
        $message.fadeIn();
        
        // Play notification sound
        if (window.uiHandler) {
            if (type === 'success') window.uiHandler.playSound('success');
            else if (type === 'danger') window.uiHandler.playSound('error');
            else if (type === 'warning') window.uiHandler.playSound('warning');
        }
        
        // Auto-hide after duration
        if (duration > 0) {
            setTimeout(() => {
                $message.fadeOut();
            }, duration);
        }
    }

    // Helper functions
    getRankSymbol(rank) {
        const symbols = {
            'A': 'A', '2': '2', '3': '3', '4': '4', '5': '5',
            '6': '6', '7': '7', '8': '8', '9': '9', '10': '10',
            'J': 'J', 'Q': 'Q', 'K': 'K'
        };
        return symbols[rank] || rank;
    }

    getSuitSymbol(suit) {
        const symbols = {
            'hearts': '♥',
            'diamonds': '♦', 
            'clubs': '♣',
            'spades': '♠'
        };
        return symbols[suit] || suit;
    }

    getSuitColorClass(suit) {
        const colors = {
            'hearts': 'text-danger',
            'diamonds': 'text-danger',
            'clubs': 'text-dark',
            'spades': 'text-dark'
        };
        return colors[suit] || '';
    }
}

// Initialize when document is ready
$(document).ready(function() {
    window.xeriGame = new XeriGameEngine();
    
    // Expose some functions for debugging
    window.debugGame = {
        reload: () => window.xeriGame.loadGameState(),
        reset: () => window.xeriGame.resetGame(),
        state: () => window.xeriGame.gameState,
        renderer: () => window.boardRenderer,
        ui: () => window.uiHandler,
        api: () => window.xeriAPI
    };
});