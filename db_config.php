<?php
// db_config.php
define('DB_HOST', 'localhost');
define('DB_PORT', '3308');  
define('DB_USER', 'iee2020122');
define('DB_PASS', '2837issiml');  
define('DB_NAME', 'syld_db');

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($conn->connect_error) {
        die("Σφάλμα σύνδεσης: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Σύνδεση για το session
$db = getDBConnection();
?>