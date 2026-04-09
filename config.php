<?php
// config.php - Optimized for performance
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_PORT', '3308');
define('DB_USER', 'root');
define('DB_PASSWORD', '3pf_@dm!n');
define('DB_NAME', 'imfsdb');

// Create connection with timeout and error handling
function getDBConnection() {
    static $conn = null;
    
    // Reuse connection if exists
    if ($conn !== null && $conn->ping()) {
        return $conn;
    }
    
    // Create new connection
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
    
    if ($conn->connect_error) {
        $error_msg = $conn->connect_error;
        
        // Try to connect without database first
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, '', DB_PORT);
        
        if ($conn->connect_error) {
            die(json_encode([
                'error' => true,
                'message' => "Database Connection Failed",
                'details' => $error_msg,
                'config' => [
                    'host' => DB_HOST,
                    'port' => DB_PORT,
                    'user' => DB_USER,
                    'database' => DB_NAME
                ]
            ]));
        }
        
        // Try to select database
        if (!$conn->select_db(DB_NAME)) {
            die(json_encode([
                'error' => true,
                'message' => "Database '" . DB_NAME . "' not found"
            ]));
        }
    }
    
    // Optimize connection settings
    $conn->set_charset("utf8mb4");
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $conn->options(MYSQLI_OPT_READ_TIMEOUT, 30);
    
    return $conn;
}

// Test connection quickly
function testDBConnection() {
    try {
        $conn = getDBConnection();
        return [
            'success' => true,
            'version' => $conn->server_version,
            'info' => $conn->host_info
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

date_default_timezone_set('Asia/Manila');

// Optional: Auto-test on load for debugging
if (isset($_GET['debug']) && $_GET['debug'] == 'db') {
    header('Content-Type: application/json');
    echo json_encode(testDBConnection());
    exit;
}
?>