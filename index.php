<?php
// --- index.php ---
// Main API Router

// Ensure error reporting is on for development, off for production
// error_reporting(E_ALL);
// ini_set('display_errors', 1); // Turn off in production

header("Content-Type: application/json"); // Default response type

// Core Includes (Order can be important)
require_once __DIR__ . '/includes/db_connect.php'; // Establishes $db connection
require_once __DIR__ . '/includes/auth.php';       // Provides authentication functions like isAuthenticated, isAuthorized

// API Handler Includes
// Group them by functionality or include them as needed by specific routes.
// For simplicity, including them here. In a larger app, an autoloader is preferred.

// Existing Handlers (ensure paths are correct)
require_once __DIR__ . '/api/attendance.php';
require_once __DIR__ . '/api/timetable.php';
require_once __DIR__ . '/api/sms_alert_handler.php';
require_once __DIR__ . '/api/admin_sms_settings_handler.php';

// New Reporting Handlers
require_once __DIR__ . '/api_handlers/reports/report_helpers.php'; // Helpers used by report handlers
require_once __DIR__ . '/api_handlers/reports/admin_reports_handler.php';
require_once __DIR__ . '/api_handlers/reports/class_reports_handler.php';
require_once __DIR__ . '/api_handlers/reports/student_reports_handler.php';


// Get Request Details
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestPath = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/');
$queryParams = $_GET;

// Basic Input Sanitization for path (though specific params are handled in functions)
$requestPath = filter_var(trim($requestPath, '/'), FILTER_SANITIZE_URL);
$pathSegments = explode('/', $requestPath);

// Check for DB connection (critical)
if (!isset($db) || !$db instanceof mysqli) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available. Critical configuration error.']);
    error_log("FATAL: Database connection not available in index.php router.");
    exit;
}

// --- Routing Logic ---
// A more robust router (e.g., FastRoute, Bramus/Router) is recommended for complex applications.
// This is a basic segment-based router.

if (isset($pathSegments[0]) && $pathSegments[0] === 'api') {
    array_shift($pathSegments); // Remove 'api' segment

    if (empty($pathSegments)) {
        http_response_code(404);
        echo json_encode(['error' => 'API endpoint not specified.']);
        exit;
    }

    $primaryRoute = $pathSegments[0];
    array_shift($pathSegments); // Current $pathSegments now contains sub-routes and IDs

    switch ($primaryRoute) {
        case 'attendance':
            if (empty($pathSegments)) { // Matches /api/attendance (for GET)
                if ($requestMethod === 'GET') {
                    handleGetAttendance($db); // Assumes $queryParams are read by the function
                } else {
                    sendJsonResponse(405, ['error' => 'Method Not Allowed for /api/attendance.']);
                }
            } elseif ($pathSegments[0] === 'mark' && empty(array_slice($pathSegments, 1))) { // Matches /api/attendance/mark
                if ($requestMethod === 'POST') {
                    handleMarkAttendance($db);
                } else {
                    sendJsonResponse(405, ['error' => 'Method Not Allowed for /api/attendance/mark.']);
                }
            } else {
                sendJsonResponse(404, ['error' => 'Attendance endpoint not found.']);
            }
            break;

        case 'timetable':
            if (empty($pathSegments)) { // Matches /api/timetable (for GET)
                if ($requestMethod === 'GET') {
                    handleGetTimetable($db);
                } else {
                    sendJsonResponse(405, ['error' => 'Method Not Allowed for /api/timetable.']);
                }
            } elseif ($pathSegments[0] === 'entry' && empty(array_slice($pathSegments, 1))) { // Matches /api/timetable/entry
                if ($requestMethod === 'POST') {
                    handleCreateTimetableEntry($db);
                } else {
                    sendJsonResponse(405, ['error' => 'Method Not Allowed for /api/timetable/entry.']);
                }
            } else {
                sendJsonResponse(404, ['error' => 'Timetable endpoint not found.']);
            }
            break;

        case 'sms':
            if (isset($pathSegments[0]) && $pathSegments[0] === 'send-threshold-alerts' && empty(array_slice($pathSegments, 1))) {
                if ($requestMethod === 'POST') {
                    handleSendThresholdAlerts($db);
                } else {
                    sendJsonResponse(405, ['error' => 'Method Not Allowed for /api/sms/send-threshold-alerts.']);
                }
            } else {
                sendJsonResponse(404, ['error' => 'SMS endpoint not found.']);
            }
            break;

        case 'admin':
            if (isset($pathSegments[0]) && $pathSegments[0] === 'sms_settings' && empty(array_slice($pathSegments, 1))) {
                if ($requestMethod === 'GET') {
                    handleGetSmsSettings($db);
                } elseif ($requestMethod === 'POST') {
                    handleUpdateSmsSettings($db);
                } else {
                    sendJsonResponse(405, ['error' => 'Method Not Allowed for /api/admin/sms_settings.']);
                }
            } else {
                sendJsonResponse(404, ['error' => 'Admin endpoint not found.']);
            }
            break;

        case 'reports':
            if ($requestMethod !== 'GET') {
                sendJsonResponse(405, ['error' => 'Method Not Allowed for report endpoints. Only GET is supported.']);
                exit;
            }
            // $pathSegments for reports now holds category, id (if any), action
            $reportCategory = $pathSegments[0] ?? null;
            array_shift($pathSegments); // Remove category

            switch ($reportCategory) {
                case 'admin':
                    $adminAction = $pathSegments[0] ?? null;
                    if (!$adminAction) { sendJsonResponse(400, ['error' => 'Admin report action not specified.']); break; }
                    array_shift($pathSegments); // Remove action
                    if (!empty($pathSegments)) { sendJsonResponse(400, ['error' => 'Too many segments for admin report path.']); break;}

                    switch ($adminAction) {
                        case 'overall-attendance-summary':
                            handleGetOverallAttendanceSummary($db, $queryParams);
                            break;
                        case 'chronic-absenteeism':
                            handleGetChronicAbsenteeismReport($db, $queryParams);
                            break;
                        case 'notification-log':
                            handleGetNotificationLogReport($db, $queryParams);
                            break;
                        default:
                            sendJsonResponse(404, ['error' => 'Admin report endpoint not found: ' . $adminAction]);
                            break;
                    }
                    break; // End admin reports

                case 'class':
                    $classId = $pathSegments[0] ?? null;
                    if (!$classId || !is_numeric($classId)) { sendJsonResponse(400, ['error' => 'Class ID not specified or invalid.']); break; }
                    array_shift($pathSegments); // Remove classId
                    $classAction = $pathSegments[0] ?? null;
                    if (!$classAction) { sendJsonResponse(400, ['error' => 'Class report action not specified.']); break; }
                    array_shift($pathSegments); // Remove action
                    if (!empty($pathSegments)) { sendJsonResponse(400, ['error' => 'Too many segments for class report path.']); break;}


                    switch ($classAction) {
                        case 'attendance-register':
                            handleGetClassAttendanceRegister($db, $queryParams, (int)$classId);
                            break;
                        case 'attendance-percentage':
                            handleGetClassAttendancePercentageReport($db, $queryParams, (int)$classId);
                            break;
                        default:
                            sendJsonResponse(404, ['error' => 'Class report endpoint not found: ' . $classAction]);
                            break;
                    }
                    break; // End class reports

                case 'student':
                    $studentId = $pathSegments[0] ?? null;
                    if (!$studentId || !is_numeric($studentId)) { sendJsonResponse(400, ['error' => 'Student ID not specified or invalid.']); break; }
                    array_shift($pathSegments); // Remove studentId
                    $studentAction = $pathSegments[0] ?? null;
                    if (!$studentAction) { sendJsonResponse(400, ['error' => 'Student report action not specified.']); break; }
                    array_shift($pathSegments); // Remove action
                     if (!empty($pathSegments)) { sendJsonResponse(400, ['error' => 'Too many segments for student report path.']); break;}

                    switch ($studentAction) {
                        case 'attendance-history':
                            handleGetStudentAttendanceHistory($db, $queryParams, (int)$studentId);
                            break;
                        case 'attendance-summary':
                            handleGetStudentAttendanceSummary($db, $queryParams, (int)$studentId);
                            break;
                        default:
                            sendJsonResponse(404, ['error' => 'Student report endpoint not found: ' . $studentAction]);
                            break;
                    }
                    break; // End student reports
                default:
                    sendJsonResponse(404, ['error' => 'Report category not found: ' . $reportCategory]);
                    break;
            }
            break; // End reports

        default:
            sendJsonResponse(404, ['error' => 'API endpoint not found. Primary route: /' . $primaryRoute]);
            break;
    }

} else { // Path does not start with /api
    sendJsonResponse(404, ['error' => 'Resource Not Found. API endpoints must start with /api/']);
}

?>
