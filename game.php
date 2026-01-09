<?php
// game.php - ΟΛΟΚΛΗΡΩΜΕΝΟ (FIXED)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

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

// Check for active game
$active_game_id = 0;
$player_number = 0;

$db = getDBConnection();

// Find active game - FIXED: 4 parameters needed
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
// FIXED: Changed from "iii" to "iiii" - 4 parameters needed
$stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $active_game_id = $row['id'];
    $player_number = $row['player_num'];
}

// Debug info
// echo "User ID: $user_id<br>";
// echo "Active Game ID: $active_game_id<br>";
// echo "Player Number: $player_number<br>";
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


<script src="js/xeri-game-engine.js"></script>
    <script src="js/xeri-board-renderer.js"></script>
    <script src="js/xeri-api-client.js"></script>
    <script src="js/xeri-ui-handler.js"></script>

</head>

<script>
    // Pass PHP variables to JavaScript
    window.gameData = {
        gameId: <?php echo $active_game_id; ?>,
        playerNumber: <?php echo $player_number; ?>,
        userId: <?php echo $user_id; ?>,
        username: '<?php echo addslashes($username); ?>',
        isGuest: <?php echo $is_guest ? 'true' : 'false'; ?>
    };

    // Initialize when document is ready
    $(document).ready(function() {
        console.log('Game page loaded with data:', window.gameData);
        
        // Initialize the game engine
        if (typeof XeriGameEngine !== 'undefined') {
            const gameEngine = new XeriGameEngine();
            
            // Set the initial data from PHP
            gameEngine.gameId = window.gameData.gameId;
            gameEngine.playerNumber = window.gameData.playerNumber;
            gameEngine.userId = window.gameData.userId;
            gameEngine.username = window.gameData.username;
            gameEngine.isGuest = window.gameData.isGuest;
            
            // Start the engine
            gameEngine.init();
            
            // Make it globally accessible for debugging
            window.xeriGame = gameEngine;
        }
        
        // Show/hide difficulty based on game type
        $('#game-type').change(function() {
            if ($(this).val() === 'human-computer') {
                $('#difficulty-container').show();
            } else {
                $('#difficulty-container').hide();
            }
        });
        
        // Initialize game type change
        $('#game-type').trigger('change');
    });
    </script>


<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="game.php">
                <i class="fas fa-cards"></i> Ξερί Online
            </a>
            
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
    </nav>
    <!-- Main Game Container -->
    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Left Panel -->
            <div class="col-md-3">
                <div class="card mb-3">
                    <div class="card-header bg-info text-white">
                        <i class="fas fa-info-circle"></i> Πληροφορίες
                    </div>
                    <div class="card-body">
                        <div id="game-info">
                            <?php if ($active_game_id > 0): ?>
                                <p><strong>Παιχνίδι ID:</strong> <span id="game-id"><?php echo $active_game_id; ?></span></p>
                                <p><strong>Παίκτης:</strong> <span id="player-number"><?php echo $player_number; ?></span></p>
                                <p><strong>Κατάσταση:</strong> <span class="badge bg-success" id="game-status">Ενεργό</span></p>
                            <?php else: ?>
                                <p class="text-muted">Δεν έχετε ενεργό παιχνίδι</p>
                                <button class="btn btn-primary btn-sm" id="btn-new-game">
                                    <i class="fas fa-plus"></i> Νέο Παιχνίδι
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        
                        <div class="game-controls">
                            <h6><i class="fas fa-gamepad"></i> Ελέγχους:</h6>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" id="btn-draw-card" disabled>
                                    <i class="fas fa-download"></i> Τράβηξε
                                </button>
                                <button class="btn btn-secondary" id="btn-pass-turn" disabled>
                                    <i class="fas fa-forward"></i> Παράτα
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Player Stats -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="fas fa-chart-line"></i> Σκορ
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <h5 id="my-score">0</h5>
                                <small>Εσύ</small>
                            </div>
                            <div class="col-6">
                                <h5 id="opponent-score">0</h5>
                                <small>Αντίπαλος</small>
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
                                <span class="badge bg-secondary" id="opponent-cards-count">0 κάρτες</span>
                            </div>
                        </div>
                        <div class="hand-area" id="opponent-hand">
                            <!-- Computer's cards -->
                            <div class="card-back" title="Κάρτες αντιπάλου">
                                <i class="fas fa-question"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Table Area -->
                    <div class="table-area">
                        <div class="table-header">
                            <h4><i class="fas fa-table"></i> Τραπέζι</h4>
                            <div class="table-stats">
                                <span class="badge bg-info" id="stock-count">52 κάρτες</span>
                            </div>
                        </div>
                        <div class="table-cards" id="table-cards-container">
                            <!-- Table cards will load here -->
                            <div class="empty-table">
                                <p class="text-muted">Φόρτωση καρτών...</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Player Area -->
                    <div class="player-area player">
                        <div class="player-header">
                            <h5><i class="fas fa-user"></i> Εσύ</h5>
                            <div class="player-stats">
                                <span class="badge bg-success" id="my-cards-count">0 κάρτες</span>
                            </div>
                        </div>
                        <div class="hand-area" id="player-hand">
                            <!-- Player's cards -->
                            <div class="loading-cards">
                                <i class="fas fa-spinner fa-spin"></i> Φόρτωση χεριού...
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Game Messages -->
                <div class="alert alert-info mt-3" id="game-message" style="display: none;">
                    <i class="fas fa-info-circle"></i> <span id="message-text"></span>
                </div>
            </div>
            
            <!-- Right Panel -->
            <div class="col-md-3">
                <div class="card mb-3">
                    <div class="card-header bg-dark text-white">
                        <i class="fas fa-history"></i> Ιστορικό
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
                            <button class="btn btn-success" id="btn-new-vs-computer">
                                <i class="fas fa-robot"></i> vs Computer
                            </button>
                            <button class="btn btn-warning" id="btn-new-vs-human">
                                <i class="fas fa-users"></i> vs Άνθρωπο
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- New Game Modal -->
    <div class="modal fade" id="newGameModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Νέο Παιχνίδι</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Τύπος Παιχνιδιού:</label>
                        <select class="form-select" id="game-type">
                            <option value="human-computer">vs Computer</option>
                            <option value="human-human">vs Άνθρωπο</option>
                        </select>
                    </div>
                    <div class="mb-3" id="difficulty-container">
                        <label class="form-label">Δυσκολία:</label>
                        <select class="form-select" id="ai-difficulty">
                            <option value="easy">Εύκολη</option>
                            <option value="medium" selected>Μεσαία</option>
                            <option value="hard">Δύσκολη</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Άκυρο</button>
                    <button type="button" class="btn btn-primary" id="btn-create-game">Δημιουργία</button>
                </div>
            </div>
        </div>
    </div>
    

</body>
</html>