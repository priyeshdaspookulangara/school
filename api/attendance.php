<?php
// api/attendance.php

// sms_gateway.php is needed for send_sms and get_sms_setting
require_once __DIR__ . '/../includes/sms_gateway.php';
require_once __DIR__ . '/../includes/whatsapp_gateway.php'; // For WhatsApp messaging

/**
 * Handles marking or updating attendance for multiple students.
 * POST /api/attendance/mark
 *
 * Expected JSON payload:
 * {
 *   "class_id": "C101",
 *   "date": "YYYY-MM-DD",
 *   "records": [
 *     { "student_id": "S1001", "status": "present", "comm_channel": "both" }, // status: 'present', 'absent', 'late', 'excused'
 *     { "student_id": "S1002", "status": "absent", "comm_channel": "sms" }  // comm_channel: 'sms', 'whatsapp', 'both', 'none' (optional, defaults to system setting)
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
    $smsNotificationsSent = 0;
    $smsNotificationsFailed = 0;
    $whatsappNotificationsSent = 0; // New counter
    $whatsappNotificationsFailed = 0; // New counter

    // Get default communication channel from settings, to be used if not provided in record
    $defaultCommChannelSystem = get_sms_setting($db, 'default_comm_channel');
    if (empty($defaultCommChannelSystem)) {
        $defaultCommChannelSystem = 'sms'; // Fallback if not set, or choose 'none'
        error_log("Warning: 'default_comm_channel' not found in settings, defaulting to '$defaultCommChannelSystem'.");
    }

    try {
        foreach ($data['records'] as $record) {
            if (!isset($record['student_id']) || !isset($record['status'])) {
                $failedRecords[] = ['record' => $record, 'error' => 'Missing student_id or status.'];
                continue;
            }

            // Sanitize record inputs
            $student_id_input = $record['student_id']; // Keep original for messages before sanitizing for SQL
            $status_input = $record['status'];         // Keep original for status checks
            // comm_channel from record, or system default if not provided in record
            $comm_channel_input = $record['comm_channel'] ?? $defaultCommChannelSystem;

            $student_id = mysqli_real_escape_string($db, $student_id_input);
            $status = mysqli_real_escape_string($db, $status_input);
            // Sanitize comm_channel as well, though its use is for logic, not SQL directly here.
            // Validate comm_channel against allowed values.
            $allowedCommChannels = ['sms', 'whatsapp', 'both', 'none'];
            if (!in_array(strtolower($comm_channel_input), $allowedCommChannels)) {
                error_log("Invalid comm_channel '{$comm_channel_input}' for student {$student_id_input}. Defaulting to system default '{$defaultCommChannelSystem}'.");
                $comm_channel_input = $defaultCommChannelSystem;
            }


            if (!in_array(strtolower($status_input), $allowedStatuses)) {
                $failedRecords[] = ['student_id' => $student_id_input, 'error' => "Invalid status: {$status_input}."];
                continue;
            }

            // **SECURITY WARNING:** SQL Injection Vulnerability if not sanitized properly.
            // Using REPLACE INTO for simplicity to insert or update.
            // A more robust approach might involve checking existence then INSERT or UPDATE.
            $sql = "REPLACE INTO attendance (class_id, student_id, attendance_date, status)
                    VALUES ('$class_id', '$student_id', '$date', '$status')";

            if ($db->query($sql)) {
                $successfulInserts++;

                // If student is marked 'absent', trigger SMS notification
                if (strtolower($status_input) === 'absent') {
                    // **CRITICAL SECURITY WARNING**: The following SQL queries fetch data for SMS.
                    // Ensure all parts of these queries, especially those involving variables like $student_id, $class_id, $date,
                    // are derived from sanitized inputs or system-generated values.
                    // $student_id, $class_id, $date are already sanitized above.

                    // CUSTOMIZATION POINT: Define academic year start for accurate year_count
                    // This example uses the calendar year. Adjust if your academic year is different.
                    // E.g., by fetching 'academic_year_start_month_day' from sms_settings
                    $academicYearStartSetting = get_sms_setting($db, 'academic_year_start_month_day'); // e.g., '06-01'
                    $currentYear = date('Y');
                    $currentMonth = date('m');
                    $currentDay = date('d');

                    if ($academicYearStartSetting && preg_match('/^(\d{2})-(\d{2})$/', $academicYearStartSetting, $matches)) {
                        $startMonth = $matches[1];
                        $startDay = $matches[2];
                        if (mktime(0,0,0,$currentMonth, $currentDay, $currentYear) < mktime(0,0,0,$startMonth, $startDay, $currentYear) ) {
                            $yearStartDate = ($currentYear - 1) . '-' . $startMonth . '-' . $startDay;
                        } else {
                            $yearStartDate = $currentYear . '-' . $startMonth . '-' . $startDay;
                        }
                    } else {
                        // Default to calendar year if setting is missing or invalid
                        $yearStartDate = $currentYear . "-01-01";
                    }
                    $monthStartDate = $currentYear . "-" . $currentMonth . "-01";


                    // Query to fetch necessary data for the SMS template
                    // Database Column Mappings (example, adjust to your schema):
                    // {parent_name} -> COALESCE(parent_user.full_name, 'Guardian') (parent_user is hypothetical table)
                    // {student_name} -> s.full_name (students table)
                    // {roll_name} -> s.admission_no (students table)
                    // {curr_date} -> $date (already available, formatted as YYYY-MM-DD)
                    // {absent_count_curr_year} -> Calculated from attendance table
                    // {absent_count_curr_month} -> Calculated from attendance table
                    // {school_phone} -> Fetched from sms_settings
                    // {class_teacher_name} -> COALESCE(teacher_user.full_name, 'School Office') (teacher_user is hypothetical)
                    // Parent's mobile number -> parent_user.mobile_number

                    // **SECURITY WARNING**: $student_id, $class_id, $date, $yearStartDate, $monthStartDate are used.
                    // Ensure they are properly sanitized or generated. $student_id, $class_id, $date are sanitized.
                    // $yearStartDate and $monthStartDate are generated from system date and sanitized settings.
                    $smsDataSql = "
                        SELECT
                            s.full_name AS student_name,
                            s.admission_no AS roll_name, -- Assuming admission_no is used as roll_name
                            COALESCE(p_user.full_name, 'Guardian') AS parent_name,
                            p_user.mobile_number AS parent_mobile_number, -- Assuming parent's mobile is in users table
                            COALESCE(ct_user.full_name, 'School Office') AS class_teacher_name,
                            (SELECT COUNT(*) FROM attendance att_year
                             WHERE att_year.student_id = s.student_id AND att_year.status = 'absent'
                             AND att_year.attendance_date >= '$yearStartDate' AND att_year.attendance_date <= '$date') AS absent_count_curr_year,
                            (SELECT COUNT(*) FROM attendance att_month
                             WHERE att_month.student_id = s.student_id AND att_month.status = 'absent'
                             AND att_month.attendance_date >= '$monthStartDate' AND att_month.attendance_date <= '$date') AS absent_count_curr_month
                        FROM
                            students s
                        LEFT JOIN
                            users p_user ON s.parent_user_id = p_user.user_id -- CUSTOMIZATION: Adjust table/column names (e.g., students.parent_id -> parents.id)
                        LEFT JOIN
                            classes c ON s.class_id = c.class_id -- CUSTOMIZATION: Adjust if student not directly linked to class_id in students table
                        LEFT JOIN
                            users ct_user ON c.class_teacher_user_id = ct_user.user_id -- CUSTOMIZATION: Adjust table/column names
                        WHERE
                            s.student_id = '$student_id'
                        LIMIT 1";

                        $smsDataResult = $db->query($smsDataSql); // This SQL should fetch all common data
                    if ($smsDataResult && $smsDataResult->num_rows > 0) {
                            $commonSmsData = $smsDataResult->fetch_assoc();
                        $smsDataResult->free();

                            $schoolPhone = get_sms_setting($db, 'school_phone'); // Common for both
                            $formattedCurrDate = date("d M Y", strtotime($date)); // Common for both

                            $placeholders = [
                                '{parent_name}' => $commonSmsData['parent_name'],
                                '{student_name}' => $commonSmsData['student_name'],
                                '{roll_name}' => $commonSmsData['roll_name'] ? $commonSmsData['roll_name'] : 'N/A',
                                '{curr_date}' => $formattedCurrDate,
                                '{absent_count_curr_year}' => $commonSmsData['absent_count_curr_year'],
                                '{absent_count_curr_month}' => $commonSmsData['absent_count_curr_month'],
                                '{school_phone}' => $schoolPhone ? $schoolPhone : 'the school',
                                '{class_teacher_name}' => $commonSmsData['class_teacher_name'],
                            ];

                            // Send SMS if channel is 'sms' or 'both'
                            if (strtolower($comm_channel_input) === 'sms' || strtolower($comm_channel_input) === 'both') {
                                $dailySmsTemplate = get_sms_setting($db, 'daily_absent_sms_template');
                                if ($dailySmsTemplate && !empty($commonSmsData['parent_mobile_number'])) {
                                    $smsMessage = str_replace(array_keys($placeholders), array_values($placeholders), $dailySmsTemplate);
                                    if (send_sms($db, $commonSmsData['parent_mobile_number'], $smsMessage)) {
                                        $smsNotificationsSent++;
                                        error_log("Daily absent SMS sent to " . $commonSmsData['parent_mobile_number'] . " for student " . $student_id_input);
                                    } else {
                                        $smsNotificationsFailed++;
                                        error_log("Failed to send daily absent SMS to " . $commonSmsData['parent_mobile_number'] . " for student " . $student_id_input);
                                    }
                            } else {
                                $smsNotificationsFailed++;
                                    error_log("Cannot send SMS for student " . $student_id_input . (empty($commonSmsData['parent_mobile_number']) ? ": Parent mobile missing." : ": SMS template missing."));
                            }
                            }

                            // Send WhatsApp if channel is 'whatsapp' or 'both'
                            if (strtolower($comm_channel_input) === 'whatsapp' || strtolower($comm_channel_input) === 'both') {
                                $dailyWhatsappTemplate = get_sms_setting($db, 'daily_absent_whatsapp_template');
                                // CUSTOMIZATION: WhatsApp might use template names instead of full text for $dailyWhatsappTemplate
                                // For this example, assume $dailyWhatsappTemplate is the full message text.
                                // If using WhatsApp template names, $dailyWhatsappTemplate would be the template name,
                                // and $placeholders would be converted to the format send_whatsapp_message expects for template_params.
                                if ($dailyWhatsappTemplate && !empty($commonSmsData['parent_mobile_number'])) {
                                    // For WhatsApp, the message body itself might be the template identifier, or you might pass parameters separately.
                                    // This example directly replaces placeholders in the template string.
                                    // Your send_whatsapp_message function might need adaptation if it uses structured templates.
                                    $whatsappMessage = str_replace(array_keys($placeholders), array_values($placeholders), $dailyWhatsappTemplate);

                                    // Example of passing template name and params if your send_whatsapp_message supports it:
                                    // $templateNameForApi = "daily_absence_notification_v1"; // The actual name registered with WhatsApp
                                    // $templateParamsForApi = [$commonSmsData['parent_name'], $commonSmsData['student_name'], ...];
                                    // if (send_whatsapp_message($db, $commonSmsData['parent_mobile_number'], "", $templateNameForApi, $templateParamsForApi)) {
                                    if (send_whatsapp_message($db, $commonSmsData['parent_mobile_number'], $whatsappMessage)) { // Simpler direct message send
                                        $whatsappNotificationsSent++;
                                        error_log("Daily absent WhatsApp sent to " . $commonSmsData['parent_mobile_number'] . " for student " . $student_id_input);
                                    } else {
                                        $whatsappNotificationsFailed++;
                                        error_log("Failed to send daily absent WhatsApp to " . $commonSmsData['parent_mobile_number'] . " for student " . $student_id_input);
                                    }
                                } else {
                                    $whatsappNotificationsFailed++;
                                    error_log("Cannot send WhatsApp for student " . $student_id_input . (empty($commonSmsData['parent_mobile_number']) ? ": Parent mobile missing." : ": WhatsApp template missing."));
                            }
                        }

                        } else { // Failed to fetch common SMS/WhatsApp data
                            error_log("Failed to fetch notification data for student " . $student_id_input . ". SQL Error: " . $db->error);
                            if (strtolower($comm_channel_input) === 'sms' || strtolower($comm_channel_input) === 'both') $smsNotificationsFailed++;
                            if (strtolower($comm_channel_input) === 'whatsapp' || strtolower($comm_channel_input) === 'both') $whatsappNotificationsFailed++;
                    }
                } // end if status is 'absent'

                } else { // Attendance DB query failed
                $failedRecords[] = ['student_id' => $student_id_input, 'error' => 'Database error during insert/update.'];
                error_log("Attendance DB Error for student $student_id_input on $date: " . $db->error);
            }
        } // end foreach

        $responseCode = 0;
            $finalMessageData = [];

        if (count($failedRecords) > 0 && $successfulInserts === 0) {
            $db->rollback();
                $responseCode = 400;
            $finalMessageData = [
                'error' => 'Failed to mark attendance for all records.',
                'failed_records' => $failedRecords
            ];
        } elseif (count($failedRecords) > 0) {
                $db->commit();
            $responseCode = 207; // Multi-Status
            $finalMessageData = [
                'message' => 'Attendance marked with some failures.',
                'successful_inserts' => $successfulInserts,
                'failed_records' => $failedRecords,
            ];
        } else {
            $db->commit();
            $responseCode = 201; // Created (or 200 OK if considering it an update)
            $finalMessageData = [
                'message' => 'Attendance marked successfully for all students.',
                'successful_inserts' => $successfulInserts,
            ];
        }

        if ($smsNotificationsSent > 0 || $smsNotificationsFailed > 0) {
             $finalMessageData['sms_notifications'] = [
                'sent' => $smsNotificationsSent,
                'failed' => $smsNotificationsFailed
             ];
        }

        http_response_code($responseCode);
        echo json_encode($finalMessageData);

    } catch (Exception $e) {
        $db->rollback();
        http_response_code(500);
        // **SECURITY WARNING:** Do not expose exception messages directly. Log them.
        error_log("Transaction failed for marking attendance: " . $e->getMessage());
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
