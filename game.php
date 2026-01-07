<?php
// game.php (το κύριο αρχείο - ΕΝΗΜΕΡΩΣΗ)
require_once 'db_config.php';
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$is_guest = $_SESSION['is_guest'] ?? false;

// Check if we're in an active game
$active_game_id = 0;
$player_number = 0;

// Try to find active game for this user
$sql = "SELECT g.id, 
               CASE 
                   WHEN g.player1_id = ? THEN 1
                   WHEN g.player2_id = ? THEN 2
                   ELSE 0 
               END as player_num
        FROM games g
        WHERE (g.player1_id = ? OR g.player2_id = ?) 
          AND g.status = 'active' 
        ORDER BY g.created_at DESC 
        LIMIT 1";

$stmt = $db->prepare($sql);
$stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $active_game_id = $row['id'];
    $player_number = $row['player_num'];
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎴 Ξερί Online - Game</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/board.css">
    <link rel="stylesheet" href="css/cards.css">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="game.php">
                <i class="fas fa-cards"></i> Ξερί Online
            </a>
            
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="game.php">
                            <i class="fas fa-home"></i> Αρχική
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" id="btn-new-game-nav">
                            <i class="fas fa-plus-circle"></i> Νέο Παιχνίδι
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="stats.php">
                            <i class="fas fa-chart-bar"></i> Στατιστικά
                        </a>
                    </li>
                </ul>
                
                <div class="navbar-text">
                    <span class="me-3">
                        <i class="fas fa-user"></i> 
                        <?php echo htmlspecialchars($username); ?>
                        <?php if ($is_guest): ?>
                            <span class="badge bg-warning">Guest</span>
                        <?php endif; ?>
                    </span>
                    <button class="btn btn-sm btn-outline-light" id="btn-logout">
                        <i class="fas fa-sign-out-alt"></i> Αποσύνδεση
                    </button>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Game Container -->
    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Left Panel: Game Info & Controls -->
            <div class="col-md-3">
                <div class="card mb-3">
                    <div class="card-header bg-info text-white">
                        <i class="fas fa-info-circle"></i> Πληροφορίες Παιχνιδιού
                    </div>
                    <div class="card-body">
                        <div id="game-info">
                            <?php if ($active_game_id > 0): ?>
                                <p><strong>Παιχνίδι ID:</strong> <span id="game-id"><?php echo $active_game_id; ?></span></p>
                                <p><strong>Παίκτης:</strong> <span id="player-number"><?php echo $player_number; ?></span></p>
                                <p><strong>Κατάσταση:</strong> <span class="badge bg-success" id="game-status">Ενεργό</span></p>
                            <?php else: ?>
                                <p class="text-muted">Δεν έχετε ενεργό παιχνίδι</p>
                                <button class="btn btn-primary btn-sm" id="btn-find-game">
                                    <i class="fas fa-search"></i> Βρες Παιχνίδι
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        
                        <div class="game-controls">
                            <h6><i class="fas fa-gamepad"></i> Ελέγχους:</h6>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" id="btn-draw-card" disabled>
                                    <i class="fas fa-download"></i> Τράβηξε Κάρτα
                                </button>
                                <button class="btn btn-secondary" id="btn-pass-turn" disabled>
                                    <i class="fas fa-forward"></i> Παράτα Σειρά
                                </button>
                                <button class="btn btn-warning" id="btn-claim-xeri" disabled>
                                    <i class="fas fa-star"></i> Δήλωσε Ξερή
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Player Stats -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="fas fa-chart-line"></i> Στατιστικά
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <h5 id="my-score">0</h5>
                                <small>Πόντοι σου</small>
                            </div>
                            <div class="col-6">
                                <h5 id="opponent-score">0</h5>
                                <small>Πόντοι αντιπάλου</small>
                            </div>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <h5 id="my-xeri">0</h5>
                                <small>Ξερές σου</small>
                            </div>
                            <div class="col-6">
                                <h5 id="opponent-xeri">0</h5>
                                <small>Ξερές αντιπάλου</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Center Panel: Game Board -->
            <div class="col-md-6">
                <div class="game-board">
                    <!-- Opponent Area -->
                    <div class="player-area opponent">
                        <div class="player-header">
                            <h5><i class="fas fa-robot"></i> Αντίπαλος</h5>
                            <div class="player-stats">
                                <span class="badge bg-secondary" id="opponent-cards-count">6 κάρτες</span>
                            </div>
                        </div>
                        <div class="hand-area" id="opponent-hand">
                            <!-- Computer's cards (face down) -->
                            <div class="card-back" title="6 κάρτες">
                                <i class="fas fa-question"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Table Area -->
                    <div class="table-area">
                        <div class="table-header">
                            <h4><i class="fas fa-table"></i> Τραπέζι</h4>
                            <div class="table-stats">
                                <span class="badge bg-info" id="stock-count">40 κάρτες</span>
                            </div>
                        </div>
                        <div class="table-cards" id="table-cards-container">
                            <!-- Table cards will be rendered here -->
                            <div class="empty-table">
                                <p class="text-muted">Κανένα φύλλο στο τραπέζι</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Player Area -->
                    <div class="player-area player">
                        <div class="player-header">
                            <h5><i class="fas fa-user"></i> Εσύ (<?php echo htmlspecialchars($username); ?>)</h5>
                            <div class="player-stats">
                                <span class="badge bg-success" id="my-cards-count">6 κάρτες</span>
                            </div>
                        </div>
                        <div class="hand-area" id="player-hand">
                            <!-- Player's cards will be rendered here -->
                            <div class="loading-cards">
                                <i class="fas fa-spinner fa-spin"></i> Φόρτωση καρτών...
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Game Messages -->
                <div class="alert alert-info mt-3" id="game-message" style="display: none;">
                    <i class="fas fa-info-circle"></i> <span id="message-text"></span>
                </div>
            </div>
            
            <!-- Right Panel: Game Log & Chat -->
            <div class="col-md-3">
                <div class="card mb-3">
                    <div class="card-header bg-dark text-white">
                        <i class="fas fa-history"></i> Ιστορικό Κινήσεων
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <ul class="list-unstyled" id="move-log">
                            <li class="text-muted">Καμία κίνηση ακόμα</li>
                        </ul>
                    </div>
                </div>
                
                <!-- New Game Options -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-plus-circle"></i> Νέα Παιχνίδια
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button class="btn btn-success" id="btn-new-game-vs-computer">
                                <i class="fas fa-robot"></i> vs Computer
                            </button>
                            <button class="btn btn-warning" id="btn-new-game-vs-human">
                                <i class="fas fa-users"></i> vs Άνθρωπο
                            </button>
                        </div>
                        
                        <hr>
                        
                        <div class="game-settings">
                            <h6><i class="fas fa-cog"></i> Ρυθμίσεις:</h6>
                            <div class="mb-3">
                                <label class="form-label">Δυσκολία AI:</label>
                                <select class="form-select" id="ai-difficulty">
                                    <option value="easy">Εύκολη</option>
                                    <option value="medium" selected>Μεσαία</option>
                                    <option value="hard">Δύσκολη</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Game Over Modal -->
    <div class="modal fade" id="gameOverModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-trophy"></i> Τέλος Παιχνιδιού!</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <h3 id="game-result-title">Νίκη!</h3>
                    <div class="my-4">
                        <i class="fas fa-trophy fa-4x text-warning"></i>
                    </div>
                    <p><strong>Σκορ:</strong> <span id="final-my-score">0</span> - <span id="final-opponent-score">0</span></p>
                    <p><strong>Ξερές:</strong> <span id="final-my-xeri">0</span> - <span id="final-opponent-xeri">0</span></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
                    <button type="button" class="btn btn-primary" id="btn-play-again">Παίξε Ξανά</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Game Engine -->
    <script src="js/game-engine.js"></script>
    
    <script>
    // Game configuration
    const GAME_CONFIG = {
        userId: <?php echo $user_id; ?>,
        username: "<?php echo addslashes($username); ?>",
        isGuest: <?php echo $is_guest ? 'true' : 'false'; ?>,
        activeGameId: <?php echo $active_game_id; ?>,
        playerNumber: <?php echo $player_number; ?>,
        pollInterval: 2000 // 2 seconds
    };
    
    $(document).ready(function() {
        // Initialize game
        if (GAME_CONFIG.activeGameId > 0) {
            console.log("Active game found:", GAME_CONFIG.activeGameId);
            GameEngine.init(GAME_CONFIG.activeGameId, GAME_CONFIG.playerNumber);
        }
        
        // Event Listeners
        $('#btn-new-game-vs-computer').click(createGameVsComputer);
        $('#btn-new-game-vs-human').click(createGameVsHuman);
        $('#btn-find-game').click(findAvailableGame);
        $('#btn-play-again').click(playAgain);
        $('#btn-logout').click(logout);
        
        // Logout function
        function logout() {
            if (confirm('Είστε σίγουρος ότι θέλετε να αποσυνδεθείτε;')) {
                $.ajax({
                    url: 'api/auth.php',
                    method: 'POST',
                    data: { action: 'logout' },
                    success: function() {
                        window.location.href = 'index.php';
                    }
                });
            }
        }
    });
    
    function createGameVsComputer() {
        const difficulty = $('#ai-difficulty').val();
        
        $.ajax({
            url: 'api/game.php',
            method: 'POST',
            data: {
                action: 'create_game',
                game_type: 'human-computer',
                difficulty: difficulty
            },
            success: function(response) {
                if (response.success) {
                    showMessage('Δημιουργήθηκε νέο παιχνίδι vs Computer!', 'success');
                    GameEngine.init(response.game_id, response.player_number);
                    GAME_CONFIG.activeGameId = response.game_id;
                    GAME_CONFIG.playerNumber = response.player_number;
                } else {
                    showMessage(response.message || 'Σφάλμα δημιουργίας', 'danger');
                }
            }
        });
    }
    
    function createGameVsHuman() {
        $.ajax({
            url: 'api/game.php',
            method: 'POST',
            data: {
                action: 'create_game',
                game_type: 'human-human'
            },
            success: function(response) {
                if (response.success) {
                    showMessage('Δημιουργήθηκε νέο παιχνίδι! Περιμένετε αντίπαλο...', 'info');
                    GameEngine.init(response.game_id, response.player_number);
                    GAME_CONFIG.activeGameId = response.game_id;
                    GAME_CONFIG.playerNumber = response.player_number;
                }
            }
        });
    }
    
    function findAvailableGame() {
        // This would search for available games to join
        showMessage('Αναζήτηση διαθέσιμων παιχνιδιών...', 'info');
        // Implement join game logic
    }
    
    function playAgain() {
        $('#gameOverModal').modal('hide');
        createGameVsComputer(); // Default to vs computer
    }
    
    function showMessage(text, type) {
        const $msg = $('#game-message');
        $msg.removeClass('alert-info alert-success alert-danger')
            .addClass('alert-' + type)
            .show();
        $('#message-text').text(text);
        
        setTimeout(() => {
            $msg.fadeOut();
        }, 3000);
    }
    </script>
</body>
</html>