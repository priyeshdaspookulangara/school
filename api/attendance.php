<?php
// api/attendance.php

/**
 * Handles marking or updating attendance for multiple students.
 * POST /api/attendance/mark
 *
 * Expected JSON payload:
 * {
 *   "class_id": "C101",
 *   "date": "YYYY-MM-DD",
 *   "records": [
 *     { "student_id": "S1001", "status": "present" }, // status can be 'present', 'absent', 'late', 'excused'
 *     { "student_id": "S1002", "status": "absent" }
 *   ]
 * }
 */
function handleMarkAttendance(mysqli $db) {
    // **SECURITY:** Authenticate and authorize
    // This is a placeholder. Implement proper auth in a real application.
    /*
    if (!isAuthenticated($db)) {
        sendUnauthorizedResponse('Authentication required.');
        return;
    }
    if (!isAuthorized($db, 'teacher')) { // Assuming only teachers or admins can mark attendance
        sendForbiddenResponse('You are not authorized to mark attendance.');
        return;
    }
    */

    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
    if (strpos($contentType, 'application/json') === false) {
        http_response_code(415); // Unsupported Media Type
        echo json_encode(['error' => 'Invalid content type. Only application/json is accepted.']);
        return;
    }

    $jsonPayload = file_get_contents('php://input');
    $data = json_decode($jsonPayload, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid JSON payload.']);
        return;
    }

    // Validate required fields
    if (!isset($data['class_id']) || !isset($data['date']) || !isset($data['records']) || !is_array($data['records'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: class_id, date, or records. Records must be an array.']);
        return;
    }

    // **SECURITY WARNING:**
    // Using mysqli_real_escape_string is a minimal security measure.
    // For robust protection against SQL injection, PREPARED STATEMENTS are STRONGLY RECOMMENDED,
    // especially when dealing with bulk data or complex queries.
    // This example uses mysqli_real_escape_string for demonstration as per the request.

    // Sanitize top-level inputs
    $class_id = mysqli_real_escape_string($db, $data['class_id']);
    $date = mysqli_real_escape_string($db, $data['date']); // Further validation for date format (e.g., YYYY-MM-DD) is recommended

    // Basic date format validation
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        return;
    }

    $allowedStatuses = ['present', 'absent', 'late', 'excused'];
    $successfulInserts = 0;
    $failedRecords = [];

    // Begin transaction for atomic operations
    $db->begin_transaction();

    try {
        foreach ($data['records'] as $record) {
            if (!isset($record['student_id']) || !isset($record['status'])) {
                $failedRecords[] = ['record' => $record, 'error' => 'Missing student_id or status.'];
                continue;
            }

            // Sanitize record inputs
            $student_id = mysqli_real_escape_string($db, $record['student_id']);
            $status = mysqli_real_escape_string($db, $record['status']);

            if (!in_array(strtolower($status), $allowedStatuses)) {
                $failedRecords[] = ['student_id' => $record['student_id'], 'error' => "Invalid status: {$status}."];
                continue;
            }

            // **SECURITY WARNING:** SQL Injection Vulnerability if not sanitized properly.
            // Using REPLACE INTO for simplicity to insert or update.
            // A more robust approach might involve checking existence then INSERT or UPDATE.
            $sql = "REPLACE INTO attendance (class_id, student_id, attendance_date, status)
                    VALUES ('$class_id', '$student_id', '$date', '$status')";

            if ($db->query($sql)) {
                $successfulInserts++;
            } else {
                // **SECURITY WARNING:** Do not expose $db->error directly to clients in production.
                // Log it securely on the server.
                $failedRecords[] = ['student_id' => $record['student_id'], 'error' => 'Database error during insert/update.'];
                // error_log("Attendance DB Error for student $student_id on $date: " . $db->error);
            }
        }

        if (count($failedRecords) > 0 && $successfulInserts === 0) {
            // If all records failed, roll back
            $db->rollback();
            http_response_code(400); // Or 500 if server-side errors caused all failures
            echo json_encode([
                'error' => 'Failed to mark attendance for all records.',
                'failed_records' => $failedRecords
            ]);
        } elseif (count($failedRecords) > 0) {
            // Partial success, commit successful ones
            $db->commit();
            http_response_code(207); // Multi-Status
            echo json_encode([
                'message' => 'Attendance marked with some failures.',
                'successful_inserts' => $successfulInserts,
                'failed_records' => $failedRecords
            ]);
        } else {
            // All successful
            $db->commit();
            http_response_code(201); // Created (or 200 OK if considering it an update)
            echo json_encode([
                'message' => 'Attendance marked successfully for all students.',
                'successful_inserts' => $successfulInserts
            ]);
        }
    } catch (Exception $e) {
        $db->rollback();
        http_response_code(500);
        // **SECURITY WARNING:** Do not expose exception messages directly. Log them.
        // error_log("Transaction failed for marking attendance: " . $e->getMessage());
        echo json_encode(['error' => 'An unexpected error occurred while processing attendance.']);
    }
}


/**
 * Handles fetching attendance records.
 * GET /api/attendance?class_id=X&date=Y
 * or GET /api/attendance?student_id=Z&month=YYYY-MM
 */
function handleGetAttendance(mysqli $db) {
    // **SECURITY:** Authenticate and authorize
    /*
    if (!isAuthenticated($db)) {
        sendUnauthorizedResponse('Authentication required.');
        return;
    }
    // Authorization might depend on who is requesting (e.g., teacher for class, student for self)
    // if (!isAuthorized($db, 'student')) { // Example: any authenticated user can view some attendance
    //     sendForbiddenResponse('You are not authorized to view attendance.');
    //     return;
    // }
    */

    // **SECURITY WARNING:**
    // Using mysqli_real_escape_string is a minimal security measure.
    // PREPARED STATEMENTS are STRONGLY RECOMMENDED for robust SQL injection protection.

    $queryParams = [];
    $conditions = [];

    if (isset($_GET['class_id']) && isset($_GET['date'])) {
        $class_id = mysqli_real_escape_string($db, $_GET['class_id']);
        $date = mysqli_real_escape_string($db, $_GET['date']);

        // Basic date format validation
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format for "date". Please use YYYY-MM-DD.']);
            return;
        }
        $conditions[] = "class_id = '$class_id'";
        $conditions[] = "attendance_date = '$date'";

    } elseif (isset($_GET['student_id']) && isset($_GET['month'])) {
        $student_id = mysqli_real_escape_string($db, $_GET['student_id']);
        $month = mysqli_real_escape_string($db, $_GET['month']); // e.g., "2023-10"

        // Basic month format validation
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid month format for "month". Please use YYYY-MM.']);
            return;
        }
        $conditions[] = "student_id = '$student_id'";
        $conditions[] = "DATE_FORMAT(attendance_date, '%Y-%m') = '$month'";

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required query parameters. Use (class_id AND date) OR (student_id AND month).']);
        return;
    }

    if (empty($conditions)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid query parameters provided for filtering attendance.']);
        return;
    }

    $sql = "SELECT student_id, class_id, attendance_date, status, last_updated FROM attendance WHERE " . implode(' AND ', $conditions);
    // **SECURITY WARNING:** Constructing SQL queries with string concatenation is risky.
    // Even with mysqli_real_escape_string, prepared statements offer better protection.

    $result = $db->query($sql);

    if ($result) {
        $attendanceRecords = [];
        while ($row = $result->fetch_assoc()) {
            $attendanceRecords[] = $row;
        }
        $result->free();

        if (empty($attendanceRecords)) {
            http_response_code(404);
            echo json_encode(['message' => 'No attendance records found for the given criteria.']);
        } else {
            http_response_code(200);
            echo json_encode($attendanceRecords);
        }
    } else {
        http_response_code(500);
        // **SECURITY WARNING:** Do not expose $db->error directly to clients in production.
        // Log it securely on the server.
        // error_log("Get Attendance DB Error: " . $db->error);
        echo json_encode(['error' => 'Database error while fetching attendance.']);
    }
}

?>
