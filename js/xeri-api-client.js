// js/xeri-api-client.js
/**
 * Xeri API Client
 * Handles all API communication with the backend
 */

class XeriAPIClient {
    constructor() {
        this.baseUrl = 'api/';
        this.csrfToken = this.getCSRFToken();
        this.setupAjaxDefaults();
    }

    /**
     * Get CSRF token from meta tag
     */
    getCSRFToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Setup default AJAX settings
     */
    setupAjaxDefaults() {
        $.ajaxSetup({
            cache: false,
            beforeSend: (xhr) => {
                if (this.csrfToken) {
                    xhr.setRequestHeader('X-CSRF-Token', this.csrfToken);
                }
            }
        });
    }

    /**
     * Make API request
     * @param {string} endpoint - API endpoint
     * @param {string} method - HTTP method
     * @param {object} data - Request data
     * @param {object} options - Additional options
     */
    async request(endpoint, method = 'GET', data = {}, options = {}) {
        const url = this.baseUrl + endpoint;
        
        // Default options
        const defaults = {
            method: method,
            data: method === 'GET' ? data : JSON.stringify(data),
            contentType: method === 'GET' ? 'application/x-www-form-urlencoded' : 'application/json',
            dataType: 'json',
            timeout: 10000 // 10 seconds timeout
        };

        const ajaxOptions = { ...defaults, ...options };
        
        try {
            return await $.ajax(url, ajaxOptions);
        } catch (error) {
            return this.handleError(error);
        }
    }

    /**
     * Handle API errors
     */
    handleError(error) {
        console.error('API Error:', error);
        
        let errorMessage = 'Σφάλμα σύνδεσης';
        let errorCode = 'network_error';
        
        if (error.status === 401) {
            errorMessage = 'Απαιτείται σύνδεση';
            errorCode = 'unauthorized';
            // Redirect to login if not guest
            if (!sessionStorage.getItem('xeri_is_guest')) {
                setTimeout(() => window.location.href = 'index.php', 2000);
            }
        } else if (error.status === 403) {
            errorMessage = 'Δεν έχετε πρόσβαση';
            errorCode = 'forbidden';
        } else if (error.status === 404) {
            errorMessage = 'Δεν βρέθηκε ο πόρος';
            errorCode = 'not_found';
        } else if (error.status === 422) {
            errorMessage = 'Μη έγκυρα δεδομένα';
            errorCode = 'validation_error';
        } else if (error.status === 500) {
            errorMessage = 'Σφάλμα διακομιστή';
            errorCode = 'server_error';
        }
        
        return {
            success: false,
            error: {
                code: errorCode,
                message: errorMessage,
                original: error
            }
        };
    }

    /**
     * ========================
     * AUTHENTICATION API
     * ========================
     */

    /**
     * User login
     */
    async login(identifier, password, rememberMe = false) {
        return this.request('auth.php', 'POST', {
            action: 'login',
            identifier: identifier,
            password: password,
            remember_me: rememberMe ? 1 : 0
        });
    }

    /**
     * User registration
     */
    async register(username, email, password, avatar = 'default') {
        return this.request('auth.php', 'POST', {
            action: 'signup',
            username: username,
            email: email,
            password: password,
            avatar: avatar
        });
    }

    /**
     * Guest login
     */
    async guestLogin(guestId = null) {
        return this.request('auth.php', 'POST', {
            action: 'guest_login',
            guest_id: guestId || `guest_${Date.now()}`
        });
    }

    /**
     * Check username availability
     */
    async checkUsername(username) {
        return this.request('auth.php', 'POST', {
            action: 'check_username',
            username: username
        });
    }

    /**
     * Check email availability
     */
    async checkEmail(email) {
        return this.request('auth.php', 'POST', {
            action: 'check_email',
            email: email
        });
    }

    /**
     * Forgot password
     */
    async forgotPassword(email) {
        return this.request('auth.php', 'POST', {
            action: 'forgot_password',
            email: email
        });
    }

    /**
     * Logout
     */
    async logout() {
        return this.request('auth.php', 'POST', {
            action: 'logout'
        });
    }

    /**
     * ========================
     * GAME MANAGEMENT API
     * ========================
     */

    /**
     * Create new game
     */
    async createGame(gameType = 'human-computer', difficulty = 'medium') {
        return this.request('game.php', 'POST', {
            action: 'create_game',
            game_type: gameType,
            difficulty: difficulty
        });
    }

    /**
     * Join existing game
     */
    async joinGame(gameId) {
        return this.request('game.php', 'POST', {
            action: 'join_game',
            game_id: gameId
        });
    }

    /**
     * Get game state
     */
    async getGameState(gameId) {
        return this.request('game.php', 'POST', {
            action: 'get_game_state',
            game_id: gameId
        });
    }

    /**
     * Get available games
     */
    async getAvailableGames() {
        return this.request('game.php', 'GET', {
            action: 'get_available_games'
        });
    }

    /**
     * Leave game
     */
    async leaveGame(gameId) {
        return this.request('game.php', 'POST', {
            action: 'leave_game',
            game_id: gameId
        });
    }

    /**
     * Surrender game
     */
    async surrenderGame(gameId) {
        return this.request('game.php', 'POST', {
            action: 'surrender',
            game_id: gameId
        });
    }

    /**
     * Claim xeri
     */
    async claimXeri(gameId) {
        return this.request('game.php', 'POST', {
            action: 'claim_xeri',
            game_id: gameId
        });
    }

    /**
     * Get user statistics
     */
    async getUserStats() {
        return this.request('api.php', 'GET', {
            action: 'get_user_stats'
        });
    }

    /**
     * ========================
     * GAME MOVES API
     * ========================
     */

    /**
     * Play a card
     */
    async playCard(gameId, cardId, claimedCardIds = []) {
        return this.request('move.php', 'POST', {
            action: 'play_card',
            game_id: gameId,
            card_id: cardId,
            claimed_cards: claimedCardIds
        });
    }

    /**
     * Draw a card from stock
     */
    async drawCard(gameId) {
        return this.request('move.php', 'POST', {
            action: 'draw_card',
            game_id: gameId
        });
    }

    /**
     * Pass turn
     */
    async passTurn(gameId) {
        return this.request('move.php', 'POST', {
            action: 'pass_turn',
            game_id: gameId
        });
    }

    /**
     * Get valid moves
     */
    async getValidMoves(gameId) {
        return this.request('move.php', 'POST', {
            action: 'get_valid_moves',
            game_id: gameId
        });
    }

    /**
     * Get possible moves
     */
    async getPossibleMoves(gameId) {
        return this.request('move.php', 'POST', {
            action: 'get_possible_moves',
            game_id: gameId
        });
    }

    /**
     * ========================
     * AI API
     * ========================
     */

    /**
     * Get AI state
     */
    async getAIState(gameId) {
        return this.request('ai.php', 'POST', {
            action: 'get_ai_state',
            game_id: gameId
        });
    }

    /**
     * Process AI turn
     */
    async processAITurn(gameId) {
        return this.request('ai.php', 'POST', {
            action: 'process_turn',
            game_id: gameId
        });
    }

    /**
     * Get AI difficulty
     */
    async getAIDifficulty(gameId) {
        return this.request('ai.php', 'POST', {
            action: 'get_difficulty',
            game_id: gameId
        });
    }

    /**
     * Set AI difficulty
     */
    async setAIDifficulty(gameId, difficulty) {
        return this.request('ai.php', 'POST', {
            action: 'set_difficulty',
            game_id: gameId,
            difficulty: difficulty
        });
    }

    /**
     * ========================
     * UTILITY FUNCTIONS
     * ========================
     */

    /**
     * Save session data
     */
    saveSessionData(userData) {
        if (userData.user_id) {
            sessionStorage.setItem('xeri_user_id', userData.user_id);
        }
        if (userData.username) {
            sessionStorage.setItem('xeri_username', userData.username);
        }
        if (userData.is_guest !== undefined) {
            sessionStorage.setItem('xeri_is_guest', userData.is_guest);
        }
        
        // Set CSRF token if provided
        if (userData.csrf_token) {
            this.csrfToken = userData.csrf_token;
            // Update meta tag
            let meta = document.querySelector('meta[name="csrf-token"]');
            if (!meta) {
                meta = document.createElement('meta');
                meta.name = 'csrf-token';
                document.head.appendChild(meta);
            }
            meta.setAttribute('content', userData.csrf_token);
        }
    }

    /**
     * Clear session data
     */
    clearSessionData() {
        sessionStorage.removeItem('xeri_user_id');
        sessionStorage.removeItem('xeri_username');
        sessionStorage.removeItem('xeri_is_guest');
        sessionStorage.removeItem('xeri_current_game');
    }

    /**
     * Get session data
     */
    getSessionData() {
        return {
            user_id: parseInt(sessionStorage.getItem('xeri_user_id') || '0'),
            username: sessionStorage.getItem('xeri_username') || '',
            is_guest: sessionStorage.getItem('xeri_is_guest') === 'true'
        };
    }

    /**
     * Check if user is authenticated
     */
    isAuthenticated() {
        const session = this.getSessionData();
        return session.user_id > 0;
    }

    /**
     * Check if user is guest
     */
    isGuest() {
        return sessionStorage.getItem('xeri_is_guest') === 'true';
    }

    /**
     * Get current game ID
     */
    getCurrentGameId() {
        return parseInt(sessionStorage.getItem('xeri_current_game') || '0');
    }

    /**
     * Set current game ID
     */
    setCurrentGameId(gameId) {
        if (gameId > 0) {
            sessionStorage.setItem('xeri_current_game', gameId);
        } else {
            sessionStorage.removeItem('xeri_current_game');
        }
    }

    /**
     * Upload avatar image
     */
    async uploadAvatar(file) {
        const formData = new FormData();
        formData.append('action', 'upload_avatar');
        formData.append('avatar', file);
        
        return $.ajax({
            url: this.baseUrl + 'upload.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        });
    }

    /**
     * Get leaderboard
     */
    async getLeaderboard(limit = 10, offset = 0) {
        return this.request('api.php', 'GET', {
            action: 'get_leaderboard',
            limit: limit,
            offset: offset
        });
    }

    /**
     * Get user profile
     */
    async getUserProfile(userId = null) {
        const params = { action: 'get_user_profile' };
        if (userId) {
            params.user_id = userId;
        }
        
        return this.request('api.php', 'GET', params);
    }

    /**
     * Update user profile
     */
    async updateProfile(data) {
        return this.request('api.php', 'POST', {
            action: 'update_profile',
            ...data
        });
    }

    /**
     * Send chat message
     */
    async sendChatMessage(gameId, message) {
        return this.request('chat.php', 'POST', {
            action: 'send_message',
            game_id: gameId,
            message: message
        });
    }

    /**
     * Get chat messages
     */
    async getChatMessages(gameId, lastMessageId = 0) {
        return this.request('chat.php', 'GET', {
            action: 'get_messages',
            game_id: gameId,
            last_id: lastMessageId
        });
    }

    /**
     * Health check
     */
    async healthCheck() {
        return this.request('api.php', 'GET', {
            action: 'health_check'
        });
    }

    /**
     * Get server time
     */
    async getServerTime() {
        return this.request('api.php', 'GET', {
            action: 'get_server_time'
        });
    }

    /**
     * ========================
     * BATCH REQUESTS
     * ========================
     */

    /**
     * Execute multiple requests in parallel
     */
    async batchRequests(requests) {
        const promises = requests.map(req => 
            this.request(req.endpoint, req.method, req.data, req.options)
        );
        
        return Promise.all(promises);
    }

    /**
     * Execute sequential requests
     */
    async sequentialRequests(requests) {
        const results = [];
        
        for (const req of requests) {
            const result = await this.request(
                req.endpoint, 
                req.method, 
                req.data, 
                req.options
            );
            results.push(result);
            
            // Stop if request failed
            if (!result.success) {
                break;
            }
        }
        
        return results;
    }

    /**
     * ========================
     * WEBSOCKET FALLBACK
     * ========================
     */

    /**
     * Long polling for real-time updates
     */
    startLongPolling(gameId, callback, interval = 3000) {
        let isPolling = true;
        
        const poll = async () => {
            if (!isPolling) return;
            
            try {
                const result = await this.getGameState(gameId);
                if (result.success) {
                    callback(result.data);
                }
            } catch (error) {
                console.error('Polling error:', error);
            }
            
            if (isPolling) {
                setTimeout(poll, interval);
            }
        };
        
        // Start polling
        poll();
        
        // Return stop function
        return () => {
            isPolling = false;
        };
    }

    /**
     * Subscribe to game events
     */
    subscribeToGame(gameId, eventTypes = ['state_change', 'move', 'chat']) {
        // This would be for WebSocket implementation
        console.log('Subscribe to game events:', gameId, eventTypes);
        // Implement WebSocket connection here
    }
}

/**
 * Global API instance
 */
window.xeriAPI = new XeriAPIClient();

/**
 * Helper function for quick API calls
 */
window.api = {
    // Auth
    login: (id, pass) => window.xeriAPI.login(id, pass),
    register: (user, email, pass) => window.xeriAPI.register(user, email, pass),
    guest: () => window.xeriAPI.guestLogin(),
    
    // Games
    createGame: (type, diff) => window.xeriAPI.createGame(type, diff),
    joinGame: (id) => window.xeriAPI.joinGame(id),
    getGame: (id) => window.xeriAPI.getGameState(id),
    
    // Moves
    playCard: (gameId, cardId) => window.xeriAPI.playCard(gameId, cardId),
    drawCard: (gameId) => window.xeriAPI.drawCard(gameId),
    passTurn: (gameId) => window.xeriAPI.passTurn(gameId),
    
    // Session
    getSession: () => window.xeriAPI.getSessionData(),
    clearSession: () => window.xeriAPI.clearSessionData(),
    
    // Debug
    health: () => window.xeriAPI.healthCheck()
};