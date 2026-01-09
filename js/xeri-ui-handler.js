// js/xeri-ui-handler.js
/**
 * Xeri UI Handler
 * Handles all UI interactions and events
 */

class XeriUIHandler {
    constructor(gameEngine, apiClient, boardRenderer) {
        this.gameEngine = gameEngine;
        this.api = apiClient || window.xeriAPI;
        this.boardRenderer = boardRenderer;
        this.isAnimating = false;
        
        this.init();
    }
    
    init() {
        console.log('XeriUIHandler initialized');
        this.bindGlobalEvents();
        this.bindGameButtons();  // Προσθήκη binding για κουμπιά παιχνιδιού
        this.initModals();
        this.initTooltips();
        this.initNotifications();
    }
    
    /**
     * Bind global events
     */
    bindGlobalEvents() {
        // Window resize
        $(window).on('resize', () => this.handleResize());
        
        // Keyboard shortcuts
        $(document).on('keydown', (e) => this.handleKeyboardShortcuts(e));
        
        // Before unload
        $(window).on('beforeunload', (e) => this.handleBeforeUnload(e));
        
        // Online/offline detection
        $(window).on('online', () => this.handleConnectionChange(true));
        $(window).on('offline', () => this.handleConnectionChange(false));
    }
    
    /**
     * Bind in-game button events
     */
    bindGameButtons() {
    console.log('Binding game buttons...');
    
    // New game buttons
    $(document).on('click', '#btn-new-game, #btn-new-vs-computer, #btn-new-vs-human', () => {
        console.log('New game button clicked');
        $('#newGameModal').modal('show');
    });
    
    // Draw card - χρησιμοποίησε event delegation
    $(document).on('click', '#btn-draw-card', () => {
        console.log('Draw button clicked');
        if (this.gameEngine && this.gameEngine.isMyTurn) {
            this.gameEngine.drawCard();
        } else {
            this.showNotification('Δεν είναι η σειρά σου!', 'warning');
        }
    });
    
    // Pass turn
    $(document).on('click', '#btn-pass-turn', () => {
        console.log('Pass button clicked');
        if (this.gameEngine && this.gameEngine.isMyTurn) {
            this.gameEngine.passTurn();
        } else {
            this.showNotification('Δεν είναι η σειρά σου!', 'warning');
        }
    });
    
    // Χρησιμοποίησε event delegation για δυναμικά κουμπιά
    $(document).on('click', '#btn-surrender', () => {
        console.log('Surrender button clicked');
        if (this.gameEngine && this.gameEngine.gameId > 0) {
            this.showConfirm(
                'Παράδοση Παιχνιδιού',
                'Είσαι σίγουρος ότι θέλεις να παραδώσεις το παιχνίδι;',
                () => {
                    if (this.gameEngine.surrenderGame) {
                        this.gameEngine.surrenderGame();
                    }
                }
            );
        }
    });
}
    
    /**
     * Handle keyboard shortcuts
     */
    handleKeyboardShortcuts(e) {
        // Only if in game screen
        if (!$('#game-container').is(':visible')) return;
        
        // Don't trigger if typing in input
        if ($(e.target).is('input, textarea, select')) return;
        
        console.log('Key pressed:', e.key);
        
        switch(e.key.toLowerCase()) {
            case 'd':
            case 'δ': // Greek d
                if (this.gameEngine.isMyTurn) {
                    e.preventDefault();
                    console.log('Keyboard shortcut: Draw card');
                    this.gameEngine.drawCard();
                }
                break;
                
            case 'p':
            case 'π': // Greek p
                if (this.gameEngine.isMyTurn) {
                    e.preventDefault();
                    console.log('Keyboard shortcut: Pass turn');
                    this.gameEngine.passTurn();
                }
                break;
                
            case '1':
            case '2':
            case '3':
            case '4':
            case '5':
            case '6':
                if (this.gameEngine.isMyTurn && this.gameEngine.hand.length > 0) {
                    e.preventDefault();
                    const index = parseInt(e.key) - 1;
                    if (index < this.gameEngine.hand.length) {
                        const card = this.gameEngine.hand[index];
                        if (card) {
                            console.log('Keyboard shortcut: Play card at index', index);
                            this.gameEngine.playCard(card.id);
                        }
                    }
                }
                break;
                
            case 'f11':
                e.preventDefault();
                this.toggleFullscreen();
                break;
                
            case 'escape':
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                }
                break;
        }
    }
    
    /**
     * Handle window resize
     */
    handleResize() {
        // Update board renderer if exists
        if (this.boardRenderer && typeof this.boardRenderer.handleResize === 'function') {
            this.boardRenderer.handleResize();
        }
        
        // Update card positions
        this.updateCardLayouts();
        
        // Adjust modal sizes
        this.adjustModalSizes();
    }
    
    /**
     * Handle before unload
     */
    handleBeforeUnload(e) {
        // If game is active, show confirmation
        if (this.gameEngine.gameId > 0 && this.gameEngine.gameState.status === 'active') {
            const message = 'Έχετε ενεργό παιχνίδι. Είστε σίγουρος ότι θέλετε να φύγετε;';
            e.returnValue = message;
            return message;
        }
    }
    
    /**
     * Handle connection changes
     */
    handleConnectionChange(isOnline) {
        const message = isOnline ? 
            'Η σύνδεση αποκαταστάθηκε' : 
            'Χάσατε τη σύνδεση. Παρακαλώ ελέγξτε το internet σας.';
        
        this.showNotification(message, isOnline ? 'success' : 'warning', 5000);
        
        if (isOnline) {
            // Try to reconnect to game
            if (this.gameEngine.gameId > 0) {
                setTimeout(() => {
                    this.gameEngine.loadGameState();
                }, 1000);
            }
        }
    }
    
    /**
     * Initialize modals
     */
    initModals() {
        // Game over modal
        this.initGameOverModal();
        
        // Card options modal
        this.initCardOptionsModal();
        
        // Settings modal
        this.initSettingsModal();
        
        // Confirm modal
        this.initConfirmModal();
    }
    
    /**
     * Initialize game over modal
     */
    initGameOverModal() {
        // Modal already handled by game engine
        // Add custom handlers here if needed
    }
    
    /**
     * Initialize card options modal
     */
    initCardOptionsModal() {
        // Click outside to close
        $(document).on('click', '.modal-backdrop, .modal', function(e) {
            if ($(e.target).hasClass('modal-backdrop') || 
                $(e.target).hasClass('modal')) {
                $(this).modal('hide');
            }
        });
    }
    
    /**
     * Initialize settings modal
     */
    initSettingsModal() {
        // Settings button
        $('#btn-settings').click(() => {
            this.showSettingsModal();
        });
        
        // Sound toggle
        $('#toggle-sound').change(function() {
            const isChecked = $(this).is(':checked');
            localStorage.setItem('xeri_sound_enabled', isChecked);
            if (window.xeriSound) {
                window.xeriSound.setEnabled(isChecked);
            }
        });
        
        // Music toggle
        $('#toggle-music').change(function() {
            const isChecked = $(this).is(':checked');
            localStorage.setItem('xeri_music_enabled', isChecked);
            if (window.xeriMusic) {
                window.xeriMusic.setEnabled(isChecked);
            }
        });
        
        // Animation toggle
        $('#toggle-animations').change(function() {
            const isChecked = $(this).is(':checked');
            localStorage.setItem('xeri_animations_enabled', isChecked);
            document.body.classList.toggle('no-animations', !isChecked);
        });
        
        // Difficulty change
        $('#ai-difficulty').change(function() {
            const difficulty = $(this).val();
            localStorage.setItem('xeri_ai_difficulty', difficulty);
            
            // If in game with AI, update difficulty
            if (window.xeriGame && window.xeriGame.gameId > 0) {
                if (window.xeriAPI && window.xeriAPI.setAIDifficulty) {
                    window.xeriAPI.setAIDifficulty(window.xeriGame.gameId, difficulty);
                }
            }
        });
    }
    
    /**
     * Initialize confirm modal
     */
    initConfirmModal() {
        // Create confirm modal if doesn't exist
        if (!$('#confirm-modal').length) {
            $('body').append(`
                <div class="modal fade" id="confirm-modal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="confirm-title"></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="confirm-body"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Άκυρο</button>
                                <button type="button" class="btn btn-primary" id="confirm-ok">ΟΚ</button>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
    }
    
    /**
     * Show settings modal
     */
    showSettingsModal() {
        // Load saved settings
        $('#toggle-sound').prop('checked', 
            localStorage.getItem('xeri_sound_enabled') !== 'false');
        $('#toggle-music').prop('checked', 
            localStorage.getItem('xeri_music_enabled') !== 'false');
        $('#toggle-animations').prop('checked', 
            localStorage.getItem('xeri_animations_enabled') !== 'false');
        $('#ai-difficulty').val(
            localStorage.getItem('xeri_ai_difficulty') || 'medium');
        
        $('#settingsModal').modal('show');
    }
    
    /**
     * Show confirm dialog
     */
    showConfirm(title, message, onConfirm, onCancel = null) {
        $('#confirm-title').text(title);
        $('#confirm-body').text(message);
        
        const $modal = $('#confirm-modal');
        
        // Remove previous handlers
        $('#confirm-ok').off('click');
        $modal.off('hidden.bs.modal');
        
        // Add new handlers
        $('#confirm-ok').click(() => {
            $modal.modal('hide');
            if (onConfirm) onConfirm();
        });
        
        if (onCancel) {
            $modal.on('hidden.bs.modal', () => {
                onCancel();
            });
        }
        
        $modal.modal('show');
    }
    
    /**
     * Show notification
     */
    showNotification(message, type = 'info', duration = 3000) {
        const $container = $('#notification-container');
        const notificationId = 'notif_' + Date.now();
        
        const $notification = $(`
            <div class="notification alert alert-${type} alert-dismissible fade show" 
                 id="${notificationId}" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `);
        
        $container.append($notification);
        
        // Auto remove after duration
        if (duration > 0) {
            setTimeout(() => {
                $notification.alert('close');
            }, duration);
        }
        
        // Remove from DOM after animation
        $notification.on('closed.bs.alert', function() {
            $(this).remove();
        });
    }
    
    /**
     * Show loading spinner
     */
    showLoading(container = '#game-container', text = 'Φόρτωση...') {
        const $spinner = $(`
            <div class="loading-overlay">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">${text}</span>
                </div>
                <p class="mt-2">${text}</p>
            </div>
        `);
        
        $(container).append($spinner);
        
        return {
            hide: () => $spinner.remove(),
            updateText: (newText) => $spinner.find('p').text(newText)
        };
    }
    
    /**
     * Update card layouts
     */
    updateCardLayouts() {
        // Update player hand layout
        this.updatePlayerHandLayout();
        
        // Update table cards layout
        this.updateTableCardsLayout();
        
        // Update opponent hand layout
        this.updateOpponentHandLayout();
    }
    
    /**
     * Update player hand layout
     */
    updatePlayerHandLayout() {
        const $hand = $('#player-hand');
        const $cards = $hand.find('.card-item');
        const cardCount = $cards.length;
        
        if (cardCount === 0) return;
        
        const containerWidth = $hand.width();
        const cardWidth = 80; // Default card width
        const minSpacing = 10;
        
        // Calculate optimal spacing
        let spacing = minSpacing;
        const requiredWidth = (cardWidth * cardCount) + (spacing * (cardCount - 1));
        
        if (requiredWidth > containerWidth) {
            // Cards overlap
            const overlap = Math.min(30, (requiredWidth - containerWidth) / (cardCount - 1));
            spacing = Math.max(-30, minSpacing - overlap);
        } else {
            // Center cards
            const extraSpace = containerWidth - requiredWidth;
            spacing += extraSpace / (cardCount + 1);
        }
        
        // Position cards
        $cards.each(function(index) {
            const left = (spacing * (index + 1)) + (cardWidth * index);
            $(this).css({
                left: left + 'px',
                zIndex: index
            });
        });
    }
    
    /**
     * Update table cards layout
     */
    updateTableCardsLayout() {
        const $table = $('#table-cards-container');
        const $cards = $table.find('.table-card-item');
        const cardCount = $cards.length;
        
        if (cardCount === 0) return;
        
        const centerX = $table.width() / 2;
        const centerY = $table.height() / 2;
        const radius = Math.min(centerX, centerY) * 0.7;
        
        // Circular layout
        $cards.each(function(index) {
            const angle = (index / cardCount) * (2 * Math.PI);
            const x = centerX + (radius * Math.cos(angle)) - 40; // Half card width
            const y = centerY + (radius * Math.sin(angle)) - 60; // Half card height
            
            $(this).css({
                left: x + 'px',
                top: y + 'px',
                transform: `rotate(${angle * (180 / Math.PI)}deg)`
            });
        });
    }
    
    /**
     * Update opponent hand layout
     */
    updateOpponentHandLayout() {
        const $hand = $('#opponent-hand .opponent-cards');
        const $cards = $hand.find('.card-item');
        const cardCount = $cards.length;
        
        if (cardCount === 0) return;
        
        // Simple horizontal layout with overlap
        $cards.each(function(index) {
            $(this).css({
                marginLeft: index === 0 ? '0' : '-20px',
                zIndex: index
            });
        });
    }
    
    /**
     * Adjust modal sizes
     */
    adjustModalSizes() {
        const windowHeight = $(window).height();
        
        $('.modal').each(function() {
            const $modal = $(this);
            const maxHeight = windowHeight * 0.8;
            
            $modal.find('.modal-body').css('max-height', maxHeight + 'px');
        });
    }
    
    /**
     * Toggle fullscreen
     */
    toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {
                this.showNotification('Δεν υποστηρίζεται πλήρης οθόνη', 'warning');
            });
        } else {
            document.exitFullscreen();
        }
    }
    
    /**
     * Play sound effect
     */
    playSound(soundName) {
        if (window.xeriSound && localStorage.getItem('xeri_sound_enabled') !== 'false') {
            window.xeriSound.play(soundName);
        }
    }
    
    /**
     * Update player stats display
     */
    updatePlayerStats(stats) {
        $('#player-wins').text(stats.wins || 0);
        $('#player-losses').text(stats.losses || 0);
        $('#player-xeri').text(stats.xeri_count || 0);
        $('#player-total').text(stats.total_games || 0);
        
        // Calculate win rate
        if (stats.total_games > 0) {
            const winRate = Math.round((stats.wins / stats.total_games) * 100);
            $('#player-winrate').text(`${winRate}%`);
        } else {
            $('#player-winrate').text('0%');
        }
    }
    
    /**
     * Update game timer
     */
    updateGameTimer(startTime) {
        const $timer = $('#game-timer');
        if (!$timer.length) return;
        
        const update = () => {
            const now = new Date();
            const start = new Date(startTime);
            const diff = Math.floor((now - start) / 1000);
            
            const hours = Math.floor(diff / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;
            
            const timeStr = hours > 0 ? 
                `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}` :
                `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            $timer.text(timeStr);
        };
        
        update();
        this.timerInterval = setInterval(update, 1000);
    }
    
    /**
     * Stop game timer
     */
    stopGameTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }
    
    /**
     * Highlight valid moves
     */
    highlightValidMoves(validMoves) {
        // Clear previous highlights
        $('.card-item').removeClass('valid-move');
        
        // Highlight valid cards
        validMoves.forEach(move => {
            $(`.card-item[data-card-id="${move.card.id}"]`).addClass('valid-move');
        });
    }
    
    /**
     * Show move hint
     */
    showMoveHint() {
        if (!this.gameEngine.isMyTurn || !this.gameEngine.hand || this.gameEngine.hand.length === 0) return;
        
        console.log('Showing move hint...');
        
        // Simple hint: highlight first playable card
        const $playableCards = $('#player-hand .card-item');
        if ($playableCards.length > 0) {
            const $firstCard = $($playableCards[0]);
            
            // Add hint animation
            $firstCard.addClass('hint-card');
            
            // Show notification
            this.showNotification('Προτείνεται: ' + $firstCard.attr('title'), 'info', 2000);
            
            // Remove hint after delay
            setTimeout(() => {
                $firstCard.removeClass('hint-card');
            }, 2000);
        }
    }
    
    /**
     * Update turn indicator
     */
    updateTurnIndicator(isMyTurn) {
        const $indicator = $('#turn-indicator');
        
        if (!$indicator.length) {
            // Create turn indicator if doesn't exist
            $('.game-board').prepend(`
                <div class="turn-indicator" id="turn-indicator">
                    <i class="fas fa-clock text-secondary"></i>
                    <span>Σειρά αντιπάλου</span>
                </div>
            `);
        }
        
        if (isMyTurn) {
            $indicator.html(`
                <i class="fas fa-play-circle text-success"></i>
                <span class="text-success">ΣΕΙΡΑ ΣΟΥ</span>
            `).addClass('your-turn');
            
            // Play sound if available
            this.playSound('turn_start');
        } else {
            $indicator.html(`
                <i class="fas fa-clock text-secondary"></i>
                <span>Σειρά αντιπάλου</span>
            `).removeClass('your-turn');
        }
    }
    
    /**
     * Show card played animation
     */
    showCardPlayedAnimation(card, playerName, isXeri = false) {
        const $animation = $(`
            <div class="card-played-animation">
                <div class="card-played-content">
                    <div class="player-name">${playerName}</div>
                    <div class="card-preview">
                        <div class="card-symbol">${this.getRankSymbol(card.rank)}${this.getSuitSymbol(card.suit)}</div>
                    </div>
                    ${isXeri ? '<div class="xeri-badge">ΞΕΡΗ!</div>' : ''}
                </div>
            </div>
        `);
        
        $('body').append($animation);
        
        // Remove after animation
        setTimeout(() => {
            $animation.fadeOut(500, () => $animation.remove());
        }, 2000);
    }
    
    // Helper methods for card symbols
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
    
    /**
     * Clean up
     */
    destroy() {
        $(window).off('resize');
        $(document).off('keydown');
        $(window).off('beforeunload');
        $(window).off('online offline');
        
        // Remove game button bindings
        $('#btn-draw-card').off('click');
        $('#btn-pass-turn').off('click');
        $('#btn-surrender').off('click');
        $('#btn-hint').off('click');
        $('#btn-new-game, #btn-new-vs-computer, #btn-new-vs-human').off('click');
        $('#btn-create-game').off('click');
        $('#btn-logout').off('click');
        
        this.stopGameTimer();
        
        // Remove all notifications
        $('#notification-container').empty();
        
        console.log('XeriUIHandler destroyed');
    }
}

// Initialize when ready
$(document).ready(function() {
    console.log('UI Handler script loaded, waiting for game engine...');
    
    // Wait for game engine to be ready
    $(window).on('gameEngineReady', function(event, gameEngine) {
        console.log('Game engine ready, initializing UI Handler...');
        
        // Initialize UI handler
        window.uiHandler = new XeriUIHandler(
            gameEngine,
            window.xeriAPI,
            window.boardRenderer
        );
        
        console.log('UI Handler initialized successfully');
    });
});