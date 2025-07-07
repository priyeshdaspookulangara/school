<?php
/**
 * class_reports_handler.php
 * Handles API requests for class-wise attendance reports.
 */

require_once __DIR__ . '/report_helpers.php'; // Contains authenticate_user, sendJsonResponse, isTeacherAuthorizedForClass etc.

/**
 * Endpoint 1: Class Attendance Register
 * Path: GET /api/reports/class/{class_id}/attendance-register
 * Parameters: class_id (from path), start_date, end_date.
 * Authorization: teacher (assigned to class_id) or admin.
 */
function handleGetClassAttendanceRegister(mysqli $db, array $params, int $class_id) {
    $auth_user = authenticate_user($db);
    if (!$auth_user || !isTeacherAuthorizedForClass($auth_user, $class_id, $db)) {
        sendJsonResponse(403, ['error' => 'Forbidden: You are not authorized to access reports for this class.']);
        return;
    }

    // Validate and sanitize inputs
    $safe_class_id = (int)$class_id; // Already int from path, but good practice

    if (empty($params['start_date']) || empty($params['end_date'])) {
        sendJsonResponse(400, ['error' => 'Missing required parameters: start_date and end_date.']);
        return;
    }

    // CRITICAL SECURITY: Sanitize all user-supplied input before using in SQL.
    $safe_start_date = mysqli_real_escape_string($db, $params['start_date']);
    $safe_end_date = mysqli_real_escape_string($db, $params['end_date']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_end_date)) {
        sendJsonResponse(400, ['error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        return;
    }

    $reportData = [
        'class_id' => $safe_class_id,
        'report_period_start_date' => $safe_start_date,
        'report_period_end_date' => $safe_end_date,
        'students' => [],
        'dates' => [],
        'attendance_data' => new stdClass() // Use stdClass for an empty JSON object {}
    ];

    // 1. Get students in the class
    // CUSTOMIZATION: Adjust students table and column names.
    $sqlStudents = "SELECT student_id, full_name, admission_no AS roll_number FROM students WHERE class_id = $safe_class_id AND status = 'active' ORDER BY full_name";
    // CRITICAL SECURITY WARNING: $safe_class_id is an int, considered safe.
    // For production, PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $resultStudents = $db->query($sqlStudents);
    if (!$resultStudents) {
        error_log("Class Report DB Error (Students for Register): " . $db->error);
        sendJsonResponse(500, ['error' => 'Database error fetching students for class register.']);
        return;
    }
    $studentIds = [];
    while ($student = $resultStudents->fetch_assoc()) {
        $reportData['students'][] = $student;
        $studentIds[] = (int)$student['student_id']; // Collect student IDs for attendance query
        $reportData['attendance_data']->{$student['student_id']} = new stdClass(); // Initialize empty object for each student
    }
    $resultStudents->free();

    if (empty($studentIds)) {
        sendJsonResponse(200, $reportData); // No students in class, return empty structure
        return;
    }

    // 2. Generate date range for the report header
    $current = strtotime($safe_start_date);
    $last = strtotime($safe_end_date);
    while ($current <= $last) {
        $dateStr = date('Y-m-d', $current);
        $reportData['dates'][] = $dateStr;
        // Initialize attendance status for this date for all students (e.g., to '-' or empty)
        foreach ($studentIds as $sId) {
            $reportData['attendance_data']->$sId->$dateStr = null; // Or a default like '-'
        }
        $current = strtotime('+1 day', $current);
    }


    // 3. Get attendance data for these students within the date range
    // CUSTOMIZATION: Adjust attendance table and column names.
    $studentIdsString = implode(',', $studentIds); // Safe because $studentIds contains only integers
    $sqlAttendance = "
        SELECT student_id, attendance_date, status
        FROM attendance
        WHERE student_id IN ($studentIdsString)
          AND class_id = $safe_class_id -- Optional: filter by class_id if attendance table has it and it's indexed
          AND attendance_date BETWEEN '$safe_start_date' AND '$safe_end_date'
    ";
    // CRITICAL SECURITY WARNING: $studentIdsString is derived from integers. $safe_class_id is int.
    // $safe_start_date, $safe_end_date are sanitized.
    // For production, PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $resultAttendance = $db->query($sqlAttendance);
    if (!$resultAttendance) {
        error_log("Class Report DB Error (Attendance Data for Register): " . $db->error);
        sendJsonResponse(500, ['error' => 'Database error fetching attendance data for class register.']);
        return;
    }

    while ($att = $resultAttendance->fetch_assoc()) {
        $student_id_key = $att['student_id'];
        $date_key = $att['attendance_date'];
        // Ensure student_id exists as a key (should from step 1)
        if (isset($reportData['attendance_data']->$student_id_key)) {
             // Use first letter of status (P/A/L/E) or full status
            $reportData['attendance_data']->$student_id_key->$date_key = strtoupper(substr($att['status'], 0, 1));
        }
    }
    $resultAttendance->free();

    sendJsonResponse(200, $reportData);
}


/**
 * Endpoint 2: Class Attendance Percentage Report
 * Path: GET /api/reports/class/{class_id}/attendance-percentage
 * Parameters: class_id (from path), start_date, end_date.
 * Authorization: teacher (assigned to class_id) or admin.
 */
function handleGetClassAttendancePercentageReport(mysqli $db, array $params, int $class_id) {
    $auth_user = authenticate_user($db);
     if (!$auth_user || !isTeacherAuthorizedForClass($auth_user, $class_id, $db)) {
        sendJsonResponse(403, ['error' => 'Forbidden: You are not authorized to access reports for this class.']);
        return;
    }

    $safe_class_id = (int)$class_id;

    if (empty($params['start_date']) || empty($params['end_date'])) {
        sendJsonResponse(400, ['error' => 'Missing required parameters: start_date and end_date.']);
        return;
    }

    // CRITICAL SECURITY: Sanitize inputs.
    $safe_start_date = mysqli_real_escape_string($db, $params['start_date']);
    $safe_end_date = mysqli_real_escape_string($db, $params['end_date']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_end_date)) {
        sendJsonResponse(400, ['error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        return;
    }

    // CUSTOMIZATION: Adjust table names (students s, attendance att).
    // This query calculates total records, present count, and absent count per student in the class.
    // 'total_classes_held' here is interpreted as total attendance records for that student in the period.
    // A more precise 'total_classes_held_for_student' might require joining with a schedule table
    // or counting distinct dates the class was marked for anyone in that class.
    // For simplicity, this version uses total records for *this* student as total_classes_held.
    $sql = "
        SELECT
            s.student_id,
            s.full_name AS student_name,
            s.admission_no AS roll_number,
            COUNT(att.attendance_id) AS total_records_for_student, -- Total days attendance was marked for this student
            SUM(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) AS classes_attended,
            SUM(CASE WHEN att.status = 'late' THEN 1 ELSE 0 END) AS late_marks, -- Also counted as attended for percentage usually
            SUM(CASE WHEN att.status = 'absent' THEN 1 ELSE 0 END) AS absent_days
        FROM students s
        LEFT JOIN attendance att ON s.student_id = att.student_id
            AND att.attendance_date BETWEEN '$safe_start_date' AND '$safe_end_date'
            AND att.class_id = $safe_class_id -- Assuming attendance table has class_id
        WHERE s.class_id = $safe_class_id AND s.status = 'active'
        GROUP BY s.student_id, s.full_name, s.admission_no
        ORDER BY s.full_name
    ";
    // CRITICAL SECURITY WARNING: $safe_start_date, $safe_end_date are sanitized. $safe_class_id is int.
    // For production, PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $result = $db->query($sql);
    if (!$result) {
        error_log("Class Report DB Error (Percentage Report): " . $db->error);
        sendJsonResponse(500, ['error' => 'Database error fetching class attendance percentage report.']);
        return;
    }

    $report = [];
    while ($row = $result->fetch_assoc()) {
        $total_records = (int)$row['total_records_for_student'];
        $attended = (int)$row['classes_attended'] + (int)$row['late_marks']; // Lates are typically counted as attended

        $percentage = 0;
        if ($total_records > 0) {
            $percentage = round(($attended / $total_records) * 100, 2);
        }

        $report[] = [
            'student_id' => (int)$row['student_id'],
            'student_name' => $row['student_name'],
            'roll_number' => $row['roll_number'],
            'total_classes_held_for_student' => $total_records, // Renamed for clarity
            'classes_attended' => $attended,
            'absent_days' => (int)$row['absent_days'],
            'late_marks' => (int)$row['late_marks'],
            'attendance_percentage' => $percentage
        ];
    }
    $result->free();

    sendJsonResponse(200, $report);
}

?>
