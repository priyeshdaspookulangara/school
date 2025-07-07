<?php
/**
 * API Handler for SMS Threshold Alerts
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php'; // For isAuthenticated and isAuthorized
require_once __DIR__ . '/../includes/sms_gateway.php'; // For get_sms_setting and send_sms
require_once __DIR__ . '/../includes/whatsapp_gateway.php'; // For WhatsApp messaging

/**
 * Handles sending SMS and/or WhatsApp alerts to students who have exceeded the absence threshold.
 * POST /api/sms/send-threshold-alerts
 *
 * @param mysqli $db Database connection object.
 */
function handleSendThresholdAlerts(mysqli $db) {
    // --------------------------------------------------------------------------
    // 1. Authentication and Authorization
    // --------------------------------------------------------------------------
    // CRITICAL SECURITY: Enforce strong authentication and authorization.
    if (!isAuthenticated($db)) { // Pass $db if your isAuthenticated function needs it
        sendUnauthorizedResponse('Authentication required. Provide a valid X-API-Key.');
        return;
    }
    if (!isAuthorized($db, 'admin')) { // Pass $db if your isAuthorized function needs it
        sendForbiddenResponse('You do not have sufficient permissions to send threshold alerts.');
        return;
    }

    // --------------------------------------------------------------------------
    // 2. Retrieve Communication Settings
    // --------------------------------------------------------------------------
    $absentThresholdCount = (int)get_sms_setting($db, 'absent_threshold_count');
    $defaultCommChannel = get_sms_setting($db, 'default_comm_channel');
    $schoolPhone = get_sms_setting($db, 'school_phone'); // Common for both

    if (empty($defaultCommChannel)) {
        $defaultCommChannel = 'sms'; // Fallback if not set
        error_log("Warning: 'default_comm_channel' for threshold alerts not found in settings, defaulting to '$defaultCommChannel'.");
    }

    $thresholdSmsTemplate = null;
    if ($defaultCommChannel === 'sms' || $defaultCommChannel === 'both') {
        $thresholdSmsTemplate = get_sms_setting($db, 'absent_threshold_sms_template');
        if (empty($thresholdSmsTemplate)) {
            error_log("SMS Threshold Alert Error: SMS template ('absent_threshold_sms_template') is missing for the selected communication channel.");
            // Potentially continue if WhatsApp is also an option, or fail here. For now, we'll log and proceed.
        }
    }

    $thresholdWhatsappTemplate = null;
    if ($defaultCommChannel === 'whatsapp' || $defaultCommChannel === 'both') {
        $thresholdWhatsappTemplate = get_sms_setting($db, 'absent_threshold_whatsapp_template');
        if (empty($thresholdWhatsappTemplate)) {
            error_log("WhatsApp Threshold Alert Error: WhatsApp template ('absent_threshold_whatsapp_template') is missing for the selected communication channel.");
        }
    }

    if ($absentThresholdCount <= 0) {
        http_response_code(500); // Server Configuration Error
        echo json_encode(['error' => 'Absent threshold count is not properly configured.']);
        error_log("Threshold Alert Error: Absent threshold count is zero or not configured.");
        return;
    }
    if (($defaultCommChannel === 'sms' || $defaultCommChannel === 'both') && empty($thresholdSmsTemplate)) {
         if (!(($defaultCommChannel === 'whatsapp' || $defaultCommChannel === 'both') && !empty($thresholdWhatsappTemplate))) {
            // If SMS is expected but template missing, AND WhatsApp isn't a valid fallback
            http_response_code(500);
            echo json_encode(['error' => 'SMS communication channel is enabled but SMS template is missing.']);
            return;
         }
    }
    if (($defaultCommChannel === 'whatsapp' || $defaultCommChannel === 'both') && empty($thresholdWhatsappTemplate)) {
        if (!(($defaultCommChannel === 'sms' || $defaultCommChannel === 'both') && !empty($thresholdSmsTemplate))) {
            // If WhatsApp is expected but template missing, AND SMS isn't a valid fallback
            http_response_code(500);
            echo json_encode(['error' => 'WhatsApp communication channel is enabled but WhatsApp template is missing.']);
            return;
        }
    }
    if ($defaultCommChannel === 'none') {
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Communication channel is set to none. No alerts sent.']);
        return;
    }

    // --------------------------------------------------------------------------
    // 3. Define Look-back Period and Date Calculations
    // --------------------------------------------------------------------------
    // CUSTOMIZATION POINT: Configure the look-back period for checking absence threshold.
    $lookBackDays = 30; // Check absences in the last 30 days.
    $endDate = date('Y-m-d'); // Today
    $startDate = date('Y-m-d', strtotime("-$lookBackDays days"));

    // CUSTOMIZATION POINT: Define Academic Year Start for accurate year_count
    // This logic is similar to the one in attendance_handler.php for consistency.
    $academicYearStartSetting = get_sms_setting($db, 'academic_year_start_month_day'); // e.g., '06-01'
    $currentYear = date('Y');
    $currentMonth = date('m');
    $currentDay = date('d');

    if ($academicYearStartSetting && preg_match('/^(\d{2})-(\d{2})$/', $academicYearStartSetting, $matches)) {
        $startMonth = $matches[1];
        $startDay = $matches[2];
        if (mktime(0,0,0,$currentMonth, $currentDay, $currentYear) < mktime(0,0,0,$startMonth, $startDay, $currentYear) ) {
            $yearStartDateForCounts = ($currentYear - 1) . '-' . $startMonth . '-' . $startDay;
        } else {
            $yearStartDateForCounts = $currentYear . '-' . $startMonth . '-' . $startDay;
        }
    } else {
        $yearStartDateForCounts = $currentYear . "-01-01"; // Default to calendar year
    }
    $monthStartDateForCounts = $currentYear . "-" . $currentMonth . "-01";


    // --------------------------------------------------------------------------
    // 4. Identify Students Exceeding Threshold
    // --------------------------------------------------------------------------
    // CRITICAL SECURITY WARNING: All parts of the SQL query below that might be influenced
    // by external data (even settings) must be handled carefully. Here, $startDate, $endDate,
    // $yearStartDateForCounts, $monthStartDateForCounts are generated from system time or sanitized settings.
    // $absentThresholdCount is cast to int.
    // No direct user input is part of this specific query construction.
    // However, always be mindful of the origin of variables used in SQL.
    // Using mysqli_real_escape_string for variables that *could* be string-based is a good habit,
    // though for date strings generated by PHP's date functions, it's less critical than user input.

    $studentsToAlertSql = "
        SELECT
            s.student_id,
            s.full_name AS student_name,
            s.admission_no AS roll_name, -- Assuming admission_no is roll_name
            COALESCE(p_user.full_name, 'Guardian') AS parent_name,
            p_user.mobile_number AS parent_mobile_number,
            COALESCE(ct_user.full_name, 'School Office') AS class_teacher_name,
            COUNT(att.attendance_id) AS recent_absence_count, -- Absences within look-back period
            (SELECT COUNT(*) FROM attendance att_year
             WHERE att_year.student_id = s.student_id AND att_year.status = 'absent'
             AND att_year.attendance_date >= '" . mysqli_real_escape_string($db, $yearStartDateForCounts) . "'
             AND att_year.attendance_date <= '" . mysqli_real_escape_string($db, $endDate) . "') AS absent_count_curr_year,
            (SELECT COUNT(*) FROM attendance att_month
             WHERE att_month.student_id = s.student_id AND att_month.status = 'absent'
             AND att_month.attendance_date >= '" . mysqli_real_escape_string($db, $monthStartDateForCounts) . "'
             AND att_month.attendance_date <= '" . mysqli_real_escape_string($db, $endDate) . "') AS absent_count_curr_month
        FROM
            students s
        JOIN
            attendance att ON s.student_id = att.student_id
        LEFT JOIN
            users p_user ON s.parent_user_id = p_user.user_id -- CUSTOMIZATION: Adjust parent linkage
        LEFT JOIN
            classes c ON s.class_id = c.class_id -- CUSTOMIZATION: Adjust class linkage
        LEFT JOIN
            users ct_user ON c.class_teacher_user_id = ct_user.user_id -- CUSTOMIZATION: Adjust teacher linkage
        WHERE
            att.status = 'absent'
            AND att.attendance_date BETWEEN '" . mysqli_real_escape_string($db, $startDate) . "' AND '" . mysqli_real_escape_string($db, $endDate) . "'
        GROUP BY
            s.student_id, s.full_name, s.admission_no, p_user.full_name, p_user.mobile_number, ct_user.full_name
        HAVING
            COUNT(att.attendance_id) >= $absentThresholdCount
    ";
    // Database Column Mappings (example, adjust to your schema):
    // {parent_name} -> COALESCE(p_user.full_name, 'Guardian')
    // {student_name} -> s.full_name
    // {roll_name} -> s.admission_no
    // {absent_count_curr_year} -> Calculated
    // {absent_count_curr_month} -> Calculated
    // {school_phone} -> Fetched from sms_settings
    // {class_teacher_name} -> COALESCE(ct_user.full_name, 'School Office')
    // Parent's mobile number -> p_user.mobile_number


    $result = $db->query($studentsToAlertSql);
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error while identifying students for threshold alerts.']);
        error_log("SMS Threshold Alert SQL Error: " . $db->error);
        return;
    }

    $alertedStudentsList = [];
    $smsSentCount = 0;
    $smsFailedCount = 0;
    $whatsappSentCount = 0; // New counter
    $whatsappFailedCount = 0; // New counter

    if ($result->num_rows === 0) {
        http_response_code(200);
        $responsePayload = [
            'status' => 'success',
            'message' => 'No students found exceeding the absence threshold.',
            'students_alerted_count' => 0,
        ];
        if ($defaultCommChannel === 'sms' || $defaultCommChannel === 'both') {
            $responsePayload['sms_sent_count'] = 0;
            $responsePayload['sms_failed_count'] = 0;
        }
        if ($defaultCommChannel === 'whatsapp' || $defaultCommChannel === 'both') {
            $responsePayload['whatsapp_sent_count'] = 0;
            $responsePayload['whatsapp_failed_count'] = 0;
        }
        echo json_encode($responsePayload);
        $result->free();
        return;
    }

    // --------------------------------------------------------------------------
    // 5. Generate and Send SMS for Each Identified Student
    // --------------------------------------------------------------------------
    while ($studentData = $result->fetch_assoc()) {
        if (empty($studentData['parent_mobile_number'])) {
            error_log("SMS Threshold Alert: Skipping student " . $studentData['student_id'] . " due to missing parent mobile number.");
            $smsFailedCount++; // Count as failed if vital info missing
            continue;
        }

        $currentDateFormatted = date("d M Y"); // For {curr_date} if needed, though not in threshold template

        $placeholders = [
            '{parent_name}' => $studentData['parent_name'],
            '{student_name}' => $studentData['student_name'],
            '{roll_name}' => $studentData['roll_name'] ? $studentData['roll_name'] : 'N/A',
            // '{curr_date}' => $currentDateFormatted, // Not in the specified threshold template, but available
            '{absent_count_curr_year}' => $studentData['absent_count_curr_year'],
            '{absent_count_curr_month}' => $studentData['absent_count_curr_month'],
            '{school_phone}' => $schoolPhone ? $schoolPhone : 'the school',
            '{class_teacher_name}' => $studentData['class_teacher_name'],
        ];

        $studentAlertStatus = ['student_id' => $studentData['student_id'], 'name' => $studentData['student_name'], 'mobile' => $studentData['parent_mobile_number'], 'sms_status' => 'not_attempted', 'whatsapp_status' => 'not_attempted'];

        // Send SMS if channel is 'sms' or 'both' AND template is available
        if (($defaultCommChannel === 'sms' || $defaultCommChannel === 'both') && !empty($thresholdSmsTemplate)) {
            $smsMessage = str_replace(array_keys($placeholders), array_values($placeholders), $thresholdSmsTemplate);
            if (send_sms($db, $studentData['parent_mobile_number'], $smsMessage)) {
                $smsSentCount++;
                $studentAlertStatus['sms_status'] = 'sent';
                error_log("Threshold SMS sent to " . $studentData['parent_mobile_number'] . " for student " . $studentData['student_id']);
            } else {
                $smsFailedCount++;
                $studentAlertStatus['sms_status'] = 'failed';
                error_log("Failed to send threshold SMS to " . $studentData['parent_mobile_number'] . " for student " . $studentData['student_id']);
            }
        } elseif (($defaultCommChannel === 'sms' || $defaultCommChannel === 'both') && empty($thresholdSmsTemplate)) {
            $smsFailedCount++;
            $studentAlertStatus['sms_status'] = 'failed_template_missing';
            error_log("Threshold SMS not sent for student " . $studentData['student_id'] . ": SMS template missing.");
        }


        // Send WhatsApp if channel is 'whatsapp' or 'both' AND template is available
        if (($defaultCommChannel === 'whatsapp' || $defaultCommChannel === 'both') && !empty($thresholdWhatsappTemplate)) {
            // CUSTOMIZATION: Adapt for WhatsApp template name and parameters if needed
            $whatsappMessage = str_replace(array_keys($placeholders), array_values($placeholders), $thresholdWhatsappTemplate);
            if (send_whatsapp_message($db, $studentData['parent_mobile_number'], $whatsappMessage)) {
                $whatsappSentCount++;
                $studentAlertStatus['whatsapp_status'] = 'sent';
                error_log("Threshold WhatsApp sent to " . $studentData['parent_mobile_number'] . " for student " . $studentData['student_id']);
            } else {
                $whatsappFailedCount++;
                $studentAlertStatus['whatsapp_status'] = 'failed';
                error_log("Failed to send threshold WhatsApp to " . $studentData['parent_mobile_number'] . " for student " . $studentData['student_id']);
            }
        } elseif (($defaultCommChannel === 'whatsapp' || $defaultCommChannel === 'both') && empty($thresholdWhatsappTemplate)) {
            $whatsappFailedCount++;
            $studentAlertStatus['whatsapp_status'] = 'failed_template_missing';
            error_log("Threshold WhatsApp not sent for student " . $studentData['student_id'] . ": WhatsApp template missing.");
        }
        $alertedStudentsList[] = $studentAlertStatus;
    }
    $result->free();

    // --------------------------------------------------------------------------
    // 6. Return JSON Response
    // --------------------------------------------------------------------------
    $responsePayload = [
        'status' => 'success',
        'message' => "Threshold alert process completed.",
        'students_processed_count' => count($alertedStudentsList),
    ];
    if ($defaultCommChannel === 'sms' || $defaultCommChannel === 'both') {
        $responsePayload['sms_notifications'] = ['sent' => $smsSentCount, 'failed' => $smsFailedCount];
    }
    if ($defaultCommChannel === 'whatsapp' || $defaultCommChannel === 'both') {
        $responsePayload['whatsapp_notifications'] = ['sent' => $whatsappSentCount, 'failed' => $whatsappFailedCount];
    }
    $responsePayload['alerted_students_details'] = $alertedStudentsList; // Optional: provide details

    http_response_code(200);
    echo json_encode($responsePayload);
}

?>
