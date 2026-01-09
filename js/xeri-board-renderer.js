// js/xeri-board-renderer.js
class XeriBoardRenderer {
    constructor(gameEngine) {
        this.gameEngine = gameEngine;
        this.cardWidth = 80;
        this.cardHeight = 120;
        this.tableCardsContainer = $('#table-cards-container');
        this.playerHandContainer = $('#player-hand');
        this.stockContainer = $('#stock-container');
        this.opponentHandContainer = $('#opponent-hand-container');
        
        this.init();
    }

    init() {
        console.log('XeriBoardRenderer initialized');
        this.bindEvents();
    }

    bindEvents() {
        // Card hover effects
        $(document).on('mouseenter', '.card-item.playable', function() {
            $(this).css('transform', 'translateY(-10px)');
        });

        $(document).on('mouseleave', '.card-item.playable', function() {
            $(this).css('transform', 'translateY(0)');
        });

        // Card click (handled by game engine)
        $(document).on('click', '.card-item.playable', (e) => {
            const cardId = $(e.currentTarget).data('card-id');
            this.gameEngine.playCard(cardId);
        });
    }

    renderGameState(gameData) {
        this.renderPlayerHand(gameData.hand || []);
        this.renderTableCards(gameData.table_cards || []);
        this.renderStock(gameData.stock_size || 0);
        this.renderOpponentArea(gameData);
        this.updateGameStatus(gameData);
    }

    renderPlayerHand(handCards) {
        this.playerHandContainer.empty();
        
        if (handCards.length === 0) {
            this.playerHandContainer.html(`
                <div class="empty-hand text-center p-4">
                    <i class="fas fa-hand-paper fa-3x text-muted"></i>
                    <p class="mt-2">Κανένα φύλλο στο χέρι</p>
                </div>
            `);
            return;
        }

        // Calculate spacing for even distribution
        const containerWidth = this.playerHandContainer.width();
        const availableWidth = containerWidth - (this.cardWidth * handCards.length);
        const spacing = Math.min(availableWidth / (handCards.length + 1), 20);
        
        handCards.forEach((card, index) => {
            const $card = this.createCardElement(card, 'player');
            $card.css({
                'left': `${spacing + (index * (this.cardWidth + spacing))}px`,
                'z-index': index + 1
            });
            
            this.playerHandContainer.append($card);
        });
    }

    renderTableCards(tableCards) {
        this.tableCardsContainer.empty();
        
        if (tableCards.length === 0) {
            this.tableCardsContainer.html(`
                <div class="empty-table text-center p-4">
                    <i class="fas fa-table fa-3x text-muted"></i>
                    <p class="mt-2">Κανένα φύλλο στο τραπέζι</p>
                </div>
            `);
            return;
        }

        // Arrange table cards in a circular pattern
        const centerX = this.tableCardsContainer.width() / 2;
        const centerY = this.tableCardsContainer.height() / 2;
        const radius = Math.min(centerX, centerY) - 60;
        
        tableCards.forEach((card, index) => {
            const $card = this.createCardElement(card, 'table');
            
            // Calculate position in circle
            const angle = (index / tableCards.length) * (2 * Math.PI);
            const x = centerX + (radius * Math.cos(angle)) - (this.cardWidth / 2);
            const y = centerY + (radius * Math.sin(angle)) - (this.cardHeight / 2);
            
            // Add rotation for visual appeal
            const rotation = (angle * (180 / Math.PI)) + 90;
            
            $card.css({
                'position': 'absolute',
                'left': `${x}px`,
                'top': `${y}px`,
                'transform': `rotate(${rotation}deg)`,
                'z-index': index + 1
            });
            
            this.tableCardsContainer.append($card);
        });
    }

    renderStock(stockSize) {
        this.stockContainer.empty();
        
        if (stockSize <= 0) {
            this.stockContainer.html(`
                <div class="empty-stock text-center p-3">
                    <i class="fas fa-layer-group fa-2x text-muted"></i>
                    <p class="mt-1">Άδεια τράπουλα</p>
                </div>
            `);
            return;
        }

        // Create a stacked deck visualization
        const stackHeight = Math.min(stockSize, 10);
        
        for (let i = 0; i < stackHeight; i++) {
            const $card = $(`
                <div class="card-item stock-card" 
                     style="position: absolute; left: ${i * 2}px; top: ${i * 2}px">
                    <div class="card-back">
                        <i class="fas fa-cards"></i>
                    </div>
                </div>
            `);
            
            this.stockContainer.append($card);
        }

        // Add count badge
        const $badge = $(`
            <div class="stock-count-badge">
                <span class="badge bg-dark">${stockSize}</span>
            </div>
        `);
        
        this.stockContainer.append($badge);
    }

    renderOpponentArea(gameData) {
        const opponentHandSize = gameData.opponent_hand_size || 0;
        const opponentName = gameData.opponent_name || 'Αντίπαλος';
        
        this.opponentHandContainer.empty();
        
        // Render opponent info
        const $info = $(`
            <div class="opponent-info mb-3">
                <h5 class="mb-1">${opponentName}</h5>
                <small class="text-muted">${opponentHandSize} κάρτες</small>
            </div>
        `);
        
        this.opponentHandContainer.append($info);
        
        // Render opponent's cards as hidden
        const $hand = $('<div class="opponent-hand d-flex justify-content-center"></div>');
        
        for (let i = 0; i < opponentHandSize; i++) {
            const $card = $(`
                <div class="card-item opponent-card" 
                     style="margin-left: ${i === 0 ? '0' : '-30px'}; z-index: ${i}">
                    <div class="card-back">
                        <i class="fas fa-question"></i>
                    </div>
                </div>
            `);
            
            $hand.append($card);
        }
        
        this.opponentHandContainer.append($hand);
    }

    createCardElement(card, location) {
        const rankSymbol = this.getRankSymbol(card.rank);
        const suitSymbol = this.getSuitSymbol(card.suit);
        const suitColor = this.getSuitColor(card.suit);
        
        const isPlayable = (location === 'player' && this.gameEngine.isMyTurn);
        const cardClass = isPlayable ? 'card-item playable' : 'card-item';
        
        return $(`
            <div class="${cardClass}" 
                 data-card-id="${card.id}"
                 data-card-rank="${card.rank}"
                 data-card-suit="${card.suit}"
                 title="${card.symbol || `${card.rank} ${card.suit}`}"
                 style="width: ${this.cardWidth}px; height: ${this.cardHeight}px;">
                
                <div class="card-inner ${isPlayable ? 'playable' : ''}">
                    <div class="card-corner top-left">
                        <div class="card-rank ${suitColor}">${rankSymbol}</div>
                        <div class="card-suit ${suitColor}">${suitSymbol}</div>
                    </div>
                    
                    <div class="card-center">
                        <div class="card-suit-large ${suitColor}">${suitSymbol}</div>
                        <div class="card-rank-large ${suitColor}">${rankSymbol}</div>
                    </div>
                    
                    <div class="card-corner bottom-right">
                        <div class="card-rank ${suitColor}">${rankSymbol}</div>
                        <div class="card-suit ${suitColor}">${suitSymbol}</div>
                    </div>
                </div>
            </div>
        `);
    }

    updateGameStatus(gameData) {
        const $status = $('#game-status');
        const $turnIndicator = $('#turn-indicator');
        const $playerInfo = $('#player-info');
        const $opponentInfo = $('#opponent-info');
        
        // Update game status
        switch (gameData.status) {
            case 'active':
                if (gameData.can_i_play) {
                    $status.text('ΣΕΙΡΑ ΣΟΥ').removeClass('bg-secondary bg-danger').addClass('bg-success');
                    $turnIndicator.html('<i class="fas fa-play-circle"></i> Σειρά σου!');
                } else {
                    $status.text('Ενεργό').removeClass('bg-success bg-danger').addClass('bg-secondary');
                    $turnIndicator.html('<i class="fas fa-clock"></i> Σειρά αντιπάλου');
                }
                break;
                
            case 'waiting':
                $status.text('Αναμονή').removeClass('bg-success bg-danger').addClass('bg-warning');
                $turnIndicator.html('<i class="fas fa-user-clock"></i> Αναμονή για παίκτη');
                break;
                
            case 'finished':
                $status.text('Τελειωμένο').removeClass('bg-success bg-secondary').addClass('bg-danger');
                $turnIndicator.html('<i class="fas fa-flag-checkered"></i> Τέλος παιχνιδιού');
                break;
        }
        
        // Highlight current player
        if (gameData.can_i_play) {
            $playerInfo.addClass('current-player');
            $opponentInfo.removeClass('current-player');
        } else {
            $playerInfo.removeClass('current-player');
            $opponentInfo.addClass('current-player');
        }
    }

    showCardAnimation(cardId, fromLocation, toLocation) {
        const $card = $(`.card-item[data-card-id="${cardId}"]`);
        
        if ($card.length === 0) {
            console.log(`Card ${cardId} not found for animation`);
            return;
        }
        
        const startPos = $card.offset();
        const endContainer = toLocation === 'table' ? this.tableCardsContainer : 
                           toLocation === 'hand' ? this.playerHandContainer : null;
        
        if (!endContainer) return;
        
        const endPos = endContainer.offset();
        const $clone = $card.clone();
        
        $clone.css({
            position: 'fixed',
            left: startPos.left,
            top: startPos.top,
            width: $card.width(),
            height: $card.height(),
            'z-index': 1000,
            pointerEvents: 'none'
        });
        
        $('body').append($clone);
        
        // Animate
        $clone.animate({
            left: endPos.left + (endContainer.width() / 2) - ($clone.width() / 2),
            top: endPos.top + (endContainer.height() / 2) - ($clone.height() / 2),
            opacity: 0.7
        }, 500, () => {
            $clone.remove();
            // Update the board after animation
            this.gameEngine.loadGameState();
        });
    }

    showXeriAnimation(xeriData) {
        const $animation = $(`
            <div class="xeri-animation-overlay">
                <div class="xeri-content">
                    <h1 class="xeri-text">ΞΕΡΗ!</h1>
                    <div class="xeri-stars">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                    </div>
                    <p class="xeri-message">+${xeriData.score || 10} Πόντοι!</p>
                </div>
            </div>
        `);
        
        $('body').append($animation);
        
        // Remove after animation
        setTimeout(() => {
            $animation.fadeOut(1000, () => $animation.remove());
        }, 3000);
    }

    showCaptureAnimation(capturedCards) {
        if (!capturedCards || capturedCards.length === 0) return;
        
        const $animation = $(`
            <div class="capture-animation">
                <div class="capture-content">
                    <i class="fas fa-hand-holding-heart fa-2x"></i>
                    <span class="ms-2">Πήρες ${capturedCards.length} κάρτα(ες)!</span>
                </div>
            </div>
        `);
        
        $('body').append($animation);
        
        // Animate from table to player
        setTimeout(() => {
            $animation.fadeOut(500, () => $animation.remove());
        }, 2000);
    }

    highlightClaimableCards(cardId, claimableCards) {
        // Highlight the played card
        $(`.card-item[data-card-id="${cardId}"]`).addClass('selected-card');
        
        // Highlight claimable cards on table
        if (claimableCards && claimableCards.length > 0) {
            claimableCards.forEach(claimable => {
                $(`.table-card-item[data-card-id="${claimable.id}"]`).addClass('claimable-card');
            });
        }
    }

    clearHighlights() {
        $('.card-item').removeClass('selected-card claimable-card');
    }

    updateScores(playerScore, opponentScore) {
        $('#my-score').text(playerScore).addClass('score-updated');
        $('#opponent-score').text(opponentScore);
        
        setTimeout(() => {
            $('#my-score').removeClass('score-updated');
        }, 1000);
    }

    updateCardCounts(myHandSize, opponentHandSize, stockSize) {
        $('#my-cards-count').text(`${myHandSize} κάρτες`);
        $('#opponent-cards-count').text(`${opponentHandSize} κάρτες`);
        $('#stock-count').text(`${stockSize} κάρτες`);
    }

    // Helper methods
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

    getSuitColor(suit) {
        const colors = {
            'hearts': 'text-danger',
            'diamonds': 'text-danger',
            'clubs': 'text-dark',
            'spades': 'text-dark'
        };
        return colors[suit] || '';
    }

    // Responsive adjustments
    handleResize() {
        const width = $(window).width();
        
        if (width < 768) {
            this.cardWidth = 60;
            this.cardHeight = 90;
        } else if (width < 992) {
            this.cardWidth = 70;
            this.cardHeight = 105;
        } else {
            this.cardWidth = 80;
            this.cardHeight = 120;
        }
        
        // Re-render if game state exists
        if (this.gameEngine.gameState) {
            this.renderGameState(this.gameEngine.gameState);
        }
    }
}

// Add CSS for animations and styles
$(document).ready(function() {
    // Inject CSS for animations
    const animationCSS = `
        .card-item {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
            position: relative;
            display: inline-block;
            margin: 5px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .card-item.playable:hover {
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 8px 15px rgba(0,0,0,0.2);
            z-index: 100 !important;
        }
        
        .card-item.selected-card {
            box-shadow: 0 0 0 3px #ffc107;
        }
        
        .card-item.claimable-card {
            box-shadow: 0 0 0 3px #28a745;
            animation: pulse 1s infinite;
        }
        
        .card-inner {
            width: 100%;
            height: 100%;
            position: relative;
            border-radius: 8px;
            border: 1px solid #ddd;
            overflow: hidden;
        }
        
        .card-back {
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, #3498db, #2c3e50);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .card-corner {
            position: absolute;
            text-align: center;
            font-size: 12px;
            font-weight: bold;
        }
        
        .card-corner.top-left {
            top: 5px;
            left: 5px;
        }
        
        .card-corner.bottom-right {
            bottom: 5px;
            right: 5px;
            transform: rotate(180deg);
        }
        
        .card-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .card-suit-large {
            font-size: 36px;
            line-height: 1;
        }
        
        .card-rank-large {
            font-size: 24px;
            font-weight: bold;
            margin-top: 5px;
        }
        
        .empty-hand, .empty-table, .empty-stock {
            opacity: 0.7;
        }
        
        .xeri-animation-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.5s;
        }
        
        .xeri-content {
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .xeri-text {
            font-size: 72px;
            color: #e74c3c;
            font-weight: bold;
            text-shadow: 3px 3px 0 #f39c12;
            animation: bounce 1s infinite;
        }
        
        .xeri-stars {
            font-size: 48px;
            color: #f1c40f;
            margin: 20px 0;
            animation: spin 2s linear infinite;
        }
        
        .capture-animation {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            animation: slideInRight 0.5s, slideOutRight 0.5s 1.5s forwards;
        }
        
        .current-player {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid #28a745;
            padding-left: 10px;
        }
        
        .score-updated {
            animation: scorePulse 1s;
        }
        
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 3px #28a745; }
            50% { box-shadow: 0 0 0 6px #28a745; }
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        @keyframes spin {
            100% { transform: rotate(360deg); }
        }
        
        @keyframes scorePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); }
            to { transform: translateX(100%); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    `;
    
    $('<style>').text(animationCSS).appendTo('head');
    
    // Initialize renderer when game engine is ready
    $(window).on('gameEngineReady', function(event, gameEngine) {
        window.boardRenderer = new XeriBoardRenderer(gameEngine);
        
        // Handle window resize
        $(window).on('resize', () => {
            window.boardRenderer.handleResize();
        });
    });
});