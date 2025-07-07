<?php
/**
 * admin_reports_handler.php
 * Handles API requests for administrative level attendance reports.
 */

require_once __DIR__ . '/report_helpers.php'; // Contains authenticate_user, sendJsonResponse, etc.

/**
 * Endpoint 1: Overall Attendance Summary
 * Path: GET /api/reports/admin/overall-attendance-summary
 * Parameters: start_date=YYYY-MM-DD, end_date=YYYY-MM-DD (optional)
 * Authorization: admin user_type.
 */
function handleGetOverallAttendanceSummary(mysqli $db, array $params) {
    $auth_user = authenticate_user($db);
    if (!$auth_user || $auth_user['user_type'] !== 'admin') {
        sendJsonResponse(403, ['error' => 'Forbidden: Access denied.']);
        return;
    }

    // Determine date range
    $academicYearDates = getCurrentAcademicYearDates($db);
    $defaultStartDate = date('Y-m-d', strtotime('-30 days')); // Default to last 30 days if no academic year logic
    $defaultEndDate = date('Y-m-d');

    // Use academic year start if available and no specific start_date is given
    $startDate = $params['start_date'] ?? ($academicYearDates['start_date'] ?: $defaultStartDate);
    $endDate = $params['end_date'] ?? $defaultEndDate;


    // CRITICAL SECURITY: Sanitize all user-supplied input before using in SQL.
    $safe_start_date = mysqli_real_escape_string($db, $startDate);
    $safe_end_date = mysqli_real_escape_string($db, $endDate);

    // Validate date formats (basic validation)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_end_date)) {
        sendJsonResponse(400, ['error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        return;
    }

    $summary = [
        'total_students_registered' => 0,
        'total_attendance_records_in_period' => 0,
        'overall_present_count' => 0,
        'overall_absent_count' => 0,
        'overall_late_count' => 0,
        'overall_excused_count' => 0,
        'overall_present_percentage' => 0,
        'overall_absent_percentage' => 0,
        'overall_late_percentage' => 0,
        'report_period_start_date' => $safe_start_date,
        'report_period_end_date' => $safe_end_date,
    ];

    // 1. Get total registered students (example: from a 'students' table)
    // CUSTOMIZATION: Adjust table and column names as per your schema.
    $sqlTotalStudents = "SELECT COUNT(student_id) AS total_students FROM students WHERE status = 'active'"; // Assuming an 'active' status
    $resultTotalStudents = $db->query($sqlTotalStudents);
    if ($resultTotalStudents) {
        $row = $resultTotalStudents->fetch_assoc();
        $summary['total_students_registered'] = (int)$row['total_students'];
        $resultTotalStudents->free();
    } else {
        error_log("Admin Report DB Error (Total Students): " . $db->error);
        // Continue, but this part of summary will be 0
    }

    // 2. Aggregate attendance data
    // CUSTOMIZATION: Assumes an 'attendance' table with 'attendance_date', 'status'.
    // 'status' can be 'present', 'absent', 'late', 'excused'.
    // This query calculates counts for each status within the period.
    $sqlAttendanceSummary = "
        SELECT
            status,
            COUNT(*) AS status_count
        FROM attendance
        WHERE attendance_date BETWEEN '$safe_start_date' AND '$safe_end_date'
        GROUP BY status
    ";
    // CRITICAL SECURITY WARNING: $safe_start_date and $safe_end_date are derived from user input
    // and MUST be sanitized using mysqli_real_escape_string(), which has been done above.
    // For production, PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $resultAttendance = $db->query($sqlAttendanceSummary);
    if ($resultAttendance) {
        while ($row = $resultAttendance->fetch_assoc()) {
            $status = strtolower($row['status']);
            $count = (int)$row['status_count'];
            if ($status === 'present') $summary['overall_present_count'] = $count;
            elseif ($status === 'absent') $summary['overall_absent_count'] = $count;
            elseif ($status === 'late') $summary['overall_late_count'] = $count;
            elseif ($status === 'excused') $summary['overall_excused_count'] = $count;
            $summary['total_attendance_records_in_period'] += $count;
        }
        $resultAttendance->free();

        // Calculate percentages
        if ($summary['total_attendance_records_in_period'] > 0) {
            $summary['overall_present_percentage'] = round(($summary['overall_present_count'] / $summary['total_attendance_records_in_period']) * 100, 2);
            $summary['overall_absent_percentage'] = round(($summary['overall_absent_count'] / $summary['total_attendance_records_in_period']) * 100, 2);
            $summary['overall_late_percentage'] = round(($summary['overall_late_count'] / $summary['total_attendance_records_in_period']) * 100, 2);
        }
    } else {
        error_log("Admin Report DB Error (Attendance Summary): " . $db->error);
        sendJsonResponse(500, ['error' => 'Database error fetching attendance summary.']);
        return;
    }

    // The 'total_classes_held_in_period' is a bit tricky without knowing your exact schema.
    // It could be:
    // 1. COUNT(DISTINCT class_id, attendance_date) FROM attendance.
    // 2. COUNT(*) FROM a `scheduled_classes` table within the date range.
    // For this example, let's use a simplified version based on distinct (class_id, date) from attendance.
    // This assumes each entry in `attendance` table for a student on a given day for a class implies a class was "held" for that student.
    // A more accurate count might require a different table or logic.
    $sqlTotalClassesHeld = "
        SELECT COUNT(DISTINCT class_id, attendance_date) as total_classes
        FROM attendance
        WHERE attendance_date BETWEEN '$safe_start_date' AND '$safe_end_date'";
    $resTotalClasses = $db->query($sqlTotalClassesHeld);
    if($resTotalClasses && $row = $resTotalClasses->fetch_assoc()){
        $summary['total_classes_held_in_period'] = (int)$row['total_classes'];
    } else {
        error_log("Admin Report DB Error (Total Classes Held): " . $db->error);
        $summary['total_classes_held_in_period'] = 0; // Default if query fails
    }


    sendJsonResponse(200, $summary);
}


/**
 * Endpoint 2: Chronic Absenteeism / Defaulter List
 * Path: GET /api/reports/admin/chronic-absenteeism
 * Parameters: threshold=INT (optional, default 3), lookback_days=INT (optional, default 30)
 * Authorization: admin user_type.
 */
function handleGetChronicAbsenteeismReport(mysqli $db, array $params) {
    $auth_user = authenticate_user($db);
    if (!$auth_user || $auth_user['user_type'] !== 'admin') {
        sendJsonResponse(403, ['error' => 'Forbidden: Access denied.']);
        return;
    }

    $threshold = isset($params['threshold']) ? (int)$params['threshold'] : 3;
    $lookback_days = isset($params['lookback_days']) ? (int)$params['lookback_days'] : 30;

    if ($threshold <= 0) $threshold = 3;
    if ($lookback_days <= 0) $lookback_days = 30;

    $startDate = date('Y-m-d', strtotime("-$lookback_days days"));
    $endDate = date('Y-m-d');

    // CRITICAL SECURITY: Sanitize all user-supplied input before using in SQL.
    // Here, $threshold and $lookback_days are converted to int, so they are safe.
    // $startDate and $endDate are generated server-side, considered safe.
    // If they were from user input, they would need mysqli_real_escape_string.
    $safe_start_date = mysqli_real_escape_string($db, $startDate);
    $safe_end_date = mysqli_real_escape_string($db, $endDate);


    // CUSTOMIZATION: Adjust table and column names (students s, classes cl, users p_user for parent).
    // Assumes `students` has `student_id`, `full_name`, `admission_no` (for roll_number), `class_id`, `parent_user_id`.
    // Assumes `classes` has `class_id`, `name` (class_name).
    // Assumes `users` (for parents) has `user_id`, `mobile_number`.
    // Assumes `attendance` has `student_id`, `attendance_date`, `status`.
    $sql = "
        SELECT
            s.student_id,
            s.full_name AS student_name,
            s.admission_no AS roll_number,
            cl.name AS class_name,
            COUNT(DISTINCT att.attendance_date) AS total_absences_in_period,
            p_user.mobile_number AS parent_contact_no
        FROM students s
        JOIN attendance att ON s.student_id = att.student_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        LEFT JOIN users p_user ON s.parent_user_id = p_user.user_id -- Assuming parent link
        WHERE att.status = 'absent'
          AND att.attendance_date BETWEEN '$safe_start_date' AND '$safe_end_date'
        GROUP BY s.student_id, s.full_name, s.admission_no, cl.name, p_user.mobile_number
        HAVING COUNT(DISTINCT att.attendance_date) >= $threshold
        ORDER BY total_absences_in_period DESC, s.full_name ASC
    ";
    // CRITICAL SECURITY WARNING: $safe_start_date, $safe_end_date are sanitized. $threshold is int.
    // For production, PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $result = $db->query($sql);
    if ($result) {
        $defaulters = [];
        while ($row = $result->fetch_assoc()) {
            $defaulters[] = $row;
        }
        $result->free();
        sendJsonResponse(200, $defaulters);
    } else {
        error_log("Admin Report DB Error (Chronic Absenteeism): " . $db->error);
        sendJsonResponse(500, ['error' => 'Database error fetching chronic absenteeism report.']);
    }
}

/**
 * Endpoint 3: SMS/WhatsApp Notification Log
 * Path: GET /api/reports/admin/notification-log
 * Parameters: start_date, end_date (optional), channel (optional: 'sms', 'whatsapp', 'all')
 * Authorization: admin user_type.
 */
function handleGetNotificationLogReport(mysqli $db, array $params) {
    $auth_user = authenticate_user($db);
    if (!$auth_user || $auth_user['user_type'] !== 'admin') {
        sendJsonResponse(403, ['error' => 'Forbidden: Access denied.']);
        return;
    }

    // Conceptual: This endpoint relies on a `notification_log` table.
    // The schema for this table is provided as a comment in `report_helpers.php`.
    // echo "<!-- " . getNotificationLogTableSchema() . " -->"; // For outputting schema if needed during dev

    $defaultEndDate = date('Y-m-d');
    $defaultStartDate = date('Y-m-d', strtotime('-7 days')); // Default to last 7 days

    $startDate = $params['start_date'] ?? $defaultStartDate;
    $endDate = $params['end_date'] ?? $defaultEndDate;
    $channel = $params['channel'] ?? 'all';

    // CRITICAL SECURITY: Sanitize inputs.
    $safe_start_date = mysqli_real_escape_string($db, $startDate);
    $safe_end_date = mysqli_real_escape_string($db, $endDate);
    $safe_channel = mysqli_real_escape_string($db, $channel);

    // Validate date formats
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $safe_end_date)) {
        sendJsonResponse(400, ['error' => 'Invalid date format. Please use YYYY-MM-DD.']);
        return;
    }
    // Validate channel
    $allowedChannels = ['sms', 'whatsapp', 'all'];
    if (!in_array(strtolower($safe_channel), $allowedChannels)) {
        sendJsonResponse(400, ['error' => "Invalid channel. Allowed values: " . implode(', ', $allowedChannels)]);
        return;
    }

    $sql = "SELECT log_id, student_name, parent_contact, message_content, channel_used, send_status, gateway_response, timestamp
            FROM notification_log "; // Ensure this table exists

    $conditions = [];
    $conditions[] = "timestamp BETWEEN '$safe_start_date 00:00:00' AND '$safe_end_date 23:59:59'";

    if (strtolower($safe_channel) !== 'all') {
        $conditions[] = "channel_used = '$safe_channel'";
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY timestamp DESC LIMIT 500"; // Add a limit for performance

    // CRITICAL SECURITY WARNING: Variables used in $sql are sanitized.
    // PREPARED STATEMENTS are STRONGLY RECOMMENDED.

    $result = $db->query($sql);
    if ($result) {
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        $result->free();
        sendJsonResponse(200, $logs);
    } else {
        error_log("Admin Report DB Error (Notification Log) for query $sql: " . $db->error);
        // Check if table exists, this is a common issue for new features
        if (strpos($db->error, "Table") !== false && strpos($db->error, "doesn't exist") !== false) {
             sendJsonResponse(501, ['error' => 'Notification log feature not fully implemented. Table missing.', 'debug_table_schema_needed' => getNotificationLogTableSchema()]);
        } else {
            sendJsonResponse(500, ['error' => 'Database error fetching notification log.']);
        }
    }
}

?>
