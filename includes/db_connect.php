<?php
require_once __DIR__ . '/../config/db_config.php';

// Create database connection
$db = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($db->connect_error) {
    // Log error to a file or error tracking service in a production environment
    // error_log("Database connection failed: " . $db->connect_error);

    // For demonstration, we'll output a JSON error and exit.
    // In a real API, you might have a more centralized error handling mechanism.
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'error' => 'Database connection failed. Please try again later.',
        'detail' => 'Could not connect to the database server.' // In production, you might not want to expose detailed errors.
    ]);
    exit; // Stop script execution
}

// Set charset to utf8mb4 for better Unicode support
if (!$db->set_charset("utf8mb4")) {
    // Log error
    // error_log("Error loading character set utf8mb4: %s\n", $db->error);

    // Output JSON error (optional, depends on how critical this is for your app)
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'Database character set configuration failed.',
        'detail' => 'Failed to set UTF-8 character set.'
    ]);
    exit;
}

// The $db variable is now available for use in other scripts that include this file.
?>
