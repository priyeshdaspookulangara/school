<?php
// Basic Router
// IMPORTANT: This is a very basic router and lacks many features of a production-ready router (e.g., complex pattern matching, middleware support).

header("Content-Type: application/json"); // All responses will be JSON

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/auth.php';

// Get the request method and path
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestPath = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/';

// Simple routing logic
// In a real application, you'd use a more robust routing library.
switch ($requestPath) {
    case '/api/attendance/mark':
        if ($requestMethod == 'POST') {
            require __DIR__ . '/api/attendance.php';
            handleMarkAttendance($db);
        } else {
            http_response_code(405); // Method Not Allowed
            echo json_encode(['error' => 'Method Not Allowed']);
        }
        break;

    case (preg_match('/\/api\/attendance\/?/', $requestPath) ? true : false): // Matches /api/attendance and /api/attendance/
        if ($requestMethod == 'GET') {
            require __DIR__ . '/api/attendance.php';
            handleGetAttendance($db);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
        break;

    case '/api/timetable/entry':
        if ($requestMethod == 'POST') {
            require __DIR__ . '/api/timetable.php';
            handleCreateTimetableEntry($db);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
        break;

    case (preg_match('/\/api\/timetable\/?/', $requestPath) ? true : false): // Matches /api/timetable and /api/timetable/
        if ($requestMethod == 'GET') {
            require __DIR__ . '/api/timetable.php';
            handleGetTimetable($db);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
        break;

    default:
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Endpoint Not Found']);
        break;
}

?>
