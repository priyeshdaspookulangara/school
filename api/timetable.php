<?php
// api/timetable.php

/**
 * Handles creating a new timetable entry.
 * POST /api/timetable/entry
 *
 * Expected JSON payload:
 * {
 *   "class_id": "C101",
 *   "teacher_id": "T201",
 *   "subject_id": "SUBJ001",
 *   "room_id": "R1",
 *   "day_of_week": "Monday", // e.g., Monday, Tuesday
 *   "start_time": "09:00:00", // HH:MM:SS
 *   "end_time": "10:00:00"   // HH:MM:SS
 * }
 */
function handleCreateTimetableEntry(mysqli $db) {
    // **SECURITY:** Authenticate and authorize
    // This is a placeholder. Implement proper auth in a real application.
    /*
    if (!isAuthenticated($db)) {
        sendUnauthorizedResponse('Authentication required.');
        return;
    }
    if (!isAuthorized($db, 'admin')) { // Assuming only admins can create timetable entries
        sendForbiddenResponse('You are not authorized to create timetable entries.');
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
    $requiredFields = ['class_id', 'teacher_id', 'subject_id', 'room_id', 'day_of_week', 'start_time', 'end_time'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            http_response_code(400);
            echo json_encode(['error' => "Missing or empty required field: $field."]);
            return;
        }
    }

    // **SECURITY WARNING:**
    // Using mysqli_real_escape_string is a minimal security measure.
    // For robust protection against SQL injection, PREPARED STATEMENTS are STRONGLY RECOMMENDED.
    // This example uses mysqli_real_escape_string for demonstration as per the request.

    // Sanitize inputs
    $class_id = mysqli_real_escape_string($db, $data['class_id']);
    $teacher_id = mysqli_real_escape_string($db, $data['teacher_id']);
    $subject_id = mysqli_real_escape_string($db, $data['subject_id']);
    $room_id = mysqli_real_escape_string($db, $data['room_id']);
    $day_of_week = mysqli_real_escape_string($db, $data['day_of_week']); // Consider validating against a list of allowed days
    $start_time = mysqli_real_escape_string($db, $data['start_time']); // Validate time format (HH:MM:SS)
    $end_time = mysqli_real_escape_string($db, $data['end_time']);     // Validate time format (HH:MM:SS)

    // Basic time format validation
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $start_time) ||
        !preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $end_time)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid time format. Please use HH:MM:SS.']);
        return;
    }

    // Validate day_of_week (example)
    $allowedDays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
    if (!in_array($day_of_week, $allowedDays)) {
        http_response_code(400);
        echo json_encode(['error' => "Invalid day_of_week. Allowed values: " . implode(', ', $allowedDays)]);
        return;
    }

    // Conceptual Conflict Check (example - check if teacher is already booked)
    // **SECURITY WARNING:** The following query is also subject to SQL injection if inputs are not sanitized.
    // In a real system, this logic would be more complex and might involve checking room availability, class schedule, etc.
    // This should ideally use prepared statements.
    $conflict_check_sql_teacher = "SELECT entry_id FROM timetable WHERE teacher_id = '$teacher_id' AND day_of_week = '$day_of_week' AND NOT (end_time <= '$start_time' OR start_time >= '$end_time')";
    $conflict_result_teacher = $db->query($conflict_check_sql_teacher);

    if ($conflict_result_teacher && $conflict_result_teacher->num_rows > 0) {
        http_response_code(409); // Conflict
        echo json_encode(['error' => "Conflict: Teacher '$teacher_id' is already scheduled at this time on '$day_of_week'."]);
        $conflict_result_teacher->free();
        return;
    }
    if ($conflict_result_teacher) $conflict_result_teacher->free();


    // Conceptual Conflict Check (example - check if room is already booked)
    $conflict_check_sql_room = "SELECT entry_id FROM timetable WHERE room_id = '$room_id' AND day_of_week = '$day_of_week' AND NOT (end_time <= '$start_time' OR start_time >= '$end_time')";
    $conflict_result_room = $db->query($conflict_check_sql_room);

    if ($conflict_result_room && $conflict_result_room->num_rows > 0) {
        http_response_code(409); // Conflict
        echo json_encode(['error' => "Conflict: Room '$room_id' is already booked at this time on '$day_of_week'."]);
        $conflict_result_room->free();
        return;
    }
    if ($conflict_result_room) $conflict_result_room->free();

    // Conceptual Conflict Check (example - check if class is already scheduled)
    $conflict_check_sql_class = "SELECT entry_id FROM timetable WHERE class_id = '$class_id' AND day_of_week = '$day_of_week' AND NOT (end_time <= '$start_time' OR start_time >= '$end_time')";
    $conflict_result_class = $db->query($conflict_check_sql_class);

    if ($conflict_result_class && $conflict_result_class->num_rows > 0) {
        http_response_code(409); // Conflict
        echo json_encode(['error' => "Conflict: Class '$class_id' already has an entry at this time on '$day_of_week'."]);
        $conflict_result_class->free();
        return;
    }
    if ($conflict_result_class) $conflict_result_class->free();


    // **SECURITY WARNING:** SQL Injection Vulnerability if not sanitized properly.
    // PREPARED STATEMENTS are STRONGLY RECOMMENDED.
    $sql = "INSERT INTO timetable (class_id, teacher_id, subject_id, room_id, day_of_week, start_time, end_time)
            VALUES ('$class_id', '$teacher_id', '$subject_id', '$room_id', '$day_of_week', '$start_time', '$end_time')";

    if ($db->query($sql)) {
        $newEntryId = $db->insert_id;
        http_response_code(201); // Created
        echo json_encode([
            'message' => 'Timetable entry created successfully.',
            'entry_id' => $newEntryId,
            'entry_details' => $data // Return the data that was inserted
        ]);
    } else {
        http_response_code(500);
        // **SECURITY WARNING:** Do not expose $db->error directly to clients in production.
        // Log it securely on the server.
        // error_log("Create Timetable DB Error: " . $db->error);
        echo json_encode(['error' => 'Database error while creating timetable entry. Possible duplicate or invalid foreign key.']);
    }
}


/**
 * Handles fetching timetable for a class.
 * GET /api/timetable?class_id=X
 * Can be extended to support teacher_id, room_id, or day_of_week
 */
function handleGetTimetable(mysqli $db) {
    // **SECURITY:** Authenticate and authorize
    /*
    if (!isAuthenticated($db)) {
        sendUnauthorizedResponse('Authentication required.');
        return;
    }
    // Allow any authenticated user to view timetables, or add specific role checks
    */

    // **SECURITY WARNING:**
    // Using mysqli_real_escape_string is a minimal security measure.
    // PREPARED STATEMENTS are STRONGLY RECOMMENDED for robust SQL injection protection.

    $conditions = [];
    $allowedFilters = ['class_id', 'teacher_id', 'room_id', 'day_of_week'];

    foreach($allowedFilters as $filter) {
        if (isset($_GET[$filter]) && !empty(trim($_GET[$filter]))) {
            $sanitized_value = mysqli_real_escape_string($db, $_GET[$filter]);
            $conditions[] = "$filter = '$sanitized_value'";
        }
    }

    if (empty($conditions)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required query parameter. Provide at least one of: class_id, teacher_id, room_id, day_of_week.']);
        return;
    }

    $sql = "SELECT entry_id, class_id, teacher_id, subject_id, room_id, day_of_week, start_time, end_time, created_at, updated_at
            FROM timetable WHERE " . implode(' AND ', $conditions) . " ORDER BY day_of_week, start_time";
    // **SECURITY WARNING:** Constructing SQL queries with string concatenation is risky.
    // Even with mysqli_real_escape_string, prepared statements offer better protection.

    $result = $db->query($sql);

    if ($result) {
        $timetableEntries = [];
        while ($row = $result->fetch_assoc()) {
            $timetableEntries[] = $row;
        }
        $result->free();

        if (empty($timetableEntries)) {
            http_response_code(404);
            echo json_encode(['message' => 'No timetable entries found for the given criteria.']);
        } else {
            http_response_code(200);
            echo json_encode($timetableEntries);
        }
    } else {
        http_response_code(500);
        // **SECURITY WARNING:** Do not expose $db->error directly to clients in production.
        // Log it securely on the server.
        // error_log("Get Timetable DB Error: " . $db->error);
        echo json_encode(['error' => 'Database error while fetching timetable.']);
    }
}

?>
