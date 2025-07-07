<?php
/**
 * student_reports_handler.php
 * Handles API requests for student-wise attendance reports.
 */

require_once __DIR__ . '/report_helpers.php'; // Contains authenticate_user, sendJsonResponse, isUserAuthorizedForStudentData, etc.

/**
 * Endpoint 1: Individual Student Attendance History
 * Path: GET /api/reports/student/{student_id}/attendance-history
 * Parameters: student_id (from path), start_date, end_date (optional).
 * Authorization: student (self), parent (child), admin, teacher.
 */
function handleGetStudentAttendanceHistory(mysqli $db, array $params, int $student_id) {
    $auth_user = authenticate_user($db);
    if (!$auth_user || !isUserAuthorizedForStudentData($auth_user, $student_id, $db)) {
        sendJsonResponse(403, ['error' => 'Forbidden: You are not authorized to access this student\'s attendance history.']);
        return;
    }

    $safe_student_id = (int)$student_id; // Already int from path

    // Determine date range
    $academicYearDates = getCurrentAcademicYearDates($db);
    $defaultStartDate = $academicYearDates['start_date'] ?: date('Y-m-d', strtotime('-90 days')); // Default to academic year or last 90 days
    $defaultEndDate = date('Y-m-d');

    $startDate = $params['start_date'] ?? $defaultStartDate;
    $endDate = $params['end_date'] ?? $defaultEndDate;

    // CRITICAL SECURITY: Sanitize inputs.
    $safe_start_date = mysqli_real_escape_string($db, $startDate);
    $safe_end_date = mysqli_real_escape_string($db, $endDate);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_end_date)) {
        sendJsonResponse(400, ['error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        return;
    }

    // CUSTOMIZATION: Adjust table names (attendance att, classes cl, subjects sub).
    // Assumes `attendance` has `attendance_date`, `status`, `class_id`, `subject_id` (optional), `remarks` (optional).
    // Assumes `classes` has `class_id`, `name` (class_name).
    // Assumes `subjects` has `subject_id`, `name` (subject_name).
    $sql = "
        SELECT
            att.attendance_date AS date,
            att.status,
            cl.name AS class_name,
            sub.name AS subject_name, -- Subject might not always be applicable or logged
            att.remarks
        FROM attendance att
        LEFT JOIN classes cl ON att.class_id = cl.class_id
        LEFT JOIN subjects sub ON att.subject_id = sub.subject_id -- Assuming subject_id is in attendance table
        WHERE att.student_id = $safe_student_id
          AND att.attendance_date BETWEEN '$safe_start_date' AND '$safe_end_date'
        ORDER BY att.attendance_date DESC
    ";
    // CRITICAL SECURITY WARNING: $safe_student_id is int. $safe_start_date, $safe_end_date are sanitized.
    // For production, PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $result = $db->query($sql);
    if (!$result) {
        error_log("Student Report DB Error (Attendance History for student $safe_student_id): " . $db->error);
        sendJsonResponse(500, ['error' => 'Database error fetching student attendance history.']);
        return;
    }

    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $result->free();

    sendJsonResponse(200, $history);
}


/**
 * Endpoint 2: Student Attendance Summary (Cumulative)
 * Path: GET /api/reports/student/{student_id}/attendance-summary
 * Parameters: student_id (from path), academic_year_start_date (optional).
 * Authorization: student (self), parent (child), admin, teacher.
 */
function handleGetStudentAttendanceSummary(mysqli $db, array $params, int $student_id) {
    $auth_user = authenticate_user($db);
    if (!$auth_user || !isUserAuthorizedForStudentData($auth_user, $student_id, $db)) {
        sendJsonResponse(403, ['error' => 'Forbidden: You are not authorized to access this student\'s attendance summary.']);
        return;
    }

    $safe_student_id = (int)$student_id;

    $academicYearDates = getCurrentAcademicYearDates($db);
    // User can override academic year start if provided, otherwise use default from helper
    $startDate = $params['academic_year_start_date'] ?? $academicYearDates['start_date'];
    $currentDate = date('Y-m-d'); // Summary up to today

    // CRITICAL SECURITY: Sanitize inputs.
    $safe_start_date = mysqli_real_escape_string($db, $startDate);
    // $currentDate is server-generated, considered safe.

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_start_date)) {
        sendJsonResponse(400, ['error' => 'Invalid date format for academic_year_start_date. Please use YYYY-MM-DD.']);
        return;
    }

    $summary = [
        'student_id' => $safe_student_id,
        'student_name' => '',
        'roll_number' => '',
        'class_name' => '',
        'overall_attendance_percentage' => 0,
        'total_present_days' => 0,
        'total_absent_days' => 0,
        'total_late_marks' => 0,
        'total_excused_days' => 0,
        'total_records_in_period' => 0,
        'summary_period_start_date' => $safe_start_date,
        'summary_period_end_date' => $currentDate,
    ];

    // 1. Get student details
    // CUSTOMIZATION: Adjust students s, classes cl table/column names.
    $sqlStudentDetails = "
        SELECT s.full_name, s.admission_no AS roll_number, cl.name AS class_name
        FROM students s
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        WHERE s.student_id = $safe_student_id";
    $resultStudent = $db->query($sqlStudentDetails);
    if ($resultStudent && $rowStudent = $resultStudent->fetch_assoc()) {
        $summary['student_name'] = $rowStudent['full_name'];
        $summary['roll_number'] = $rowStudent['roll_number'];
        $summary['class_name'] = $rowStudent['class_name'];
        $resultStudent->free();
    } else {
        error_log("Student Report DB Error (Student Details for summary, student $safe_student_id): " . $db->error);
        // Proceed, but name/roll/class will be empty
    }


    // 2. Aggregate attendance data for the student
    // CUSTOMIZATION: Adjust attendance table.
    $sqlAttendance = "
        SELECT
            status,
            COUNT(*) AS status_count
        FROM attendance
        WHERE student_id = $safe_student_id
          AND attendance_date BETWEEN '$safe_start_date' AND '$currentDate'
        GROUP BY status
    ";
    // CRITICAL SECURITY WARNING: $safe_student_id is int. $safe_start_date is sanitized. $currentDate is server-generated.
    // For production, PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $resultAttendance = $db->query($sqlAttendance);
    if (!$resultAttendance) {
        error_log("Student Report DB Error (Attendance Summary for student $safe_student_id): " . $db->error);
        sendJsonResponse(500, ['error' => 'Database error fetching student attendance summary.']);
        return;
    }

    while ($row = $resultAttendance->fetch_assoc()) {
        $status = strtolower($row['status']);
        $count = (int)$row['status_count'];
        if ($status === 'present') $summary['total_present_days'] = $count;
        elseif ($status === 'absent') $summary['total_absent_days'] = $count;
        elseif ($status === 'late') $summary['total_late_marks'] = $count;
        elseif ($status === 'excused') $summary['total_excused_days'] = $count;
        $summary['total_records_in_period'] += $count;
    }
    $resultAttendance->free();

    // Calculate overall percentage
    if ($summary['total_records_in_period'] > 0) {
        // Typically, 'late' is also considered 'attended' for percentage calculation.
        $attended_days = $summary['total_present_days'] + $summary['total_late_marks'];
        $summary['overall_attendance_percentage'] = round(($attended_days / $summary['total_records_in_period']) * 100, 2);
    }

    sendJsonResponse(200, $summary);
}

?>
