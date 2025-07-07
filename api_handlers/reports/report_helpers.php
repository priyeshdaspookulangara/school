<?php
/**
 * report_helpers.php
 *
 * Helper functions for reporting API handlers.
 * This includes placeholder authentication and JSON response functions.
 * In a real system, these would be part of your core application structure.
 */

/**
 * Placeholder for user authentication.
 * In a real application, this would validate a token, session, or API key
 * and return user details including user_id and user_type.
 *
 * @param mysqli $db Database connection (optional, might be needed for token validation against DB).
 * @return array|null An array containing 'user_id' and 'user_type', or null if not authenticated.
 *                    Example: ['user_id' => 1, 'user_type' => 'admin']
 *                             ['user_id' => 10, 'user_type' => 'teacher', 'assigned_class_ids' => [101, 102]]
 *                             ['user_id' => 100, 'user_type' => 'student', 'student_id_link' => 500]
 *                             ['user_id' => 200, 'user_type' => 'parent', 'child_student_ids' => [500, 501]]
 */
function authenticate_user(mysqli $db): ?array {
    // CRITICAL SECURITY: This is a MOCK function.
    // Replace with your actual robust authentication logic.
    // For demonstration, we'll cycle through user types based on a query parameter if present,
    // or default to an admin. This is NOT secure for production.
    $headers = getallheaders();
    $apiKey = $headers['X-API-Key'] ?? $_GET['test_api_key'] ?? null;

    if ($apiKey === 'admin_key') {
        return ['user_id' => 1, 'user_type' => 'admin'];
    } elseif ($apiKey === 'teacher_key') {
        // In a real system, you'd look up which classes this teacher is assigned to.
        return ['user_id' => 10, 'user_type' => 'teacher', 'assigned_class_ids' => [1, 2]]; // Example class IDs
    } elseif ($apiKey === 'student_key') {
        // student_id_link would be the actual ID from the 'students' table.
        return ['user_id' => 100, 'user_type' => 'student', 'student_id_link' => 1]; // Example student ID
    } elseif ($apiKey === 'parent_key') {
        // child_student_ids would be an array of student IDs linked to this parent.
        return ['user_id' => 200, 'user_type' => 'parent', 'child_student_ids' => [1, 2]]; // Example student IDs
    } elseif (isset($_GET['test_user_type'])) {
        // Fallback for easier testing if no API key is set
        $user_type = $_GET['test_user_type'];
        if ($user_type === 'admin') return ['user_id' => 1, 'user_type' => 'admin'];
        if ($user_type === 'teacher') return ['user_id' => 10, 'user_type' => 'teacher', 'assigned_class_ids' => [1,2]]; // Example
        if ($user_type === 'student') return ['user_id' => 100, 'user_type' => 'student', 'student_id_link' => 1]; // Example
        if ($user_type === 'parent') return ['user_id' => 200, 'user_type' => 'parent', 'child_student_ids' => [1]]; // Example
    }

    // Default to not authenticated if no valid key or test_user_type
    // return null;
    // Forcing admin for generation if no other key matches, REMOVE THIS FOR PRODUCTION
    return ['user_id' => 1, 'user_type' => 'admin']; // Defaulting to admin for ease of generation
}

/**
 * Sends a JSON response with appropriate headers and status code.
 *
 * @param int $statusCode HTTP status code.
 * @param mixed $data Data to be JSON encoded.
 */
function sendJsonResponse(int $statusCode, $data): void {
    header("Content-Type: application/json");
    http_response_code($statusCode);
    echo json_encode($data);
    exit; // Important to stop script execution after sending response
}

/**
 * Helper to check if a teacher is authorized for a specific class.
 * This is a conceptual placeholder. Your actual logic might involve querying a
 * class_teacher_assignments table or similar.
 *
 * @param array $auth_user The authenticated user data.
 * @param int $class_id The class ID to check.
 * @param mysqli $db Database connection (optional, might be needed for DB lookup).
 * @return bool True if authorized, false otherwise.
 */
function isTeacherAuthorizedForClass(array $auth_user, int $class_id, mysqli $db): bool {
    if ($auth_user['user_type'] === 'admin') {
        return true; // Admins can access any class
    }
    if ($auth_user['user_type'] === 'teacher') {
        // Assuming 'assigned_class_ids' is populated during authentication for teachers
        return isset($auth_user['assigned_class_ids']) && in_array($class_id, $auth_user['assigned_class_ids']);
    }
    return false;
}

/**
 * Helper to check if a user (student/parent) is authorized for a specific student's data.
 *
 * @param array $auth_user The authenticated user data.
 * @param int $target_student_id The student ID whose data is being accessed.
 * @param mysqli $db Database connection (optional).
 * @return bool True if authorized, false otherwise.
 */
function isUserAuthorizedForStudentData(array $auth_user, int $target_student_id, mysqli $db): bool {
    if ($auth_user['user_type'] === 'admin' || $auth_user['user_type'] === 'teacher') {
        // Teachers might have broader access, or this could be restricted further
        // to only teachers of that student's class. For now, allowing all teachers.
        return true;
    }
    if ($auth_user['user_type'] === 'student') {
        // A student can access their own data.
        // 'student_id_link' should be the ID from the 'students' table linked to the user_id.
        return isset($auth_user['student_id_link']) && $auth_user['student_id_link'] == $target_student_id;
    }
    if ($auth_user['user_type'] === 'parent') {
        // A parent can access their children's data.
        // 'child_student_ids' should be an array of student IDs from the 'students' table.
        return isset($auth_user['child_student_ids']) && in_array($target_student_id, $auth_user['child_student_ids']);
    }
    return false;
}

/**
 * Provides a conceptual CREATE TABLE statement for a notification_log table.
 * This is for the SMS/WhatsApp Notification Log report.
 * You would need to create this table in your database.
 */
function getNotificationLogTableSchema(): string {
    return "
    -- Conceptual CREATE TABLE statement for notification_log
    -- You need to create this table in your database to use the notification log report.
    /*
    CREATE TABLE IF NOT EXISTS `notification_log` (
      `log_id` INT AUTO_INCREMENT PRIMARY KEY,
      `student_id` INT NULL, -- Link to students table
      `student_name` VARCHAR(255) DEFAULT NULL, -- Denormalized for easier reporting, or join
      `parent_user_id` INT NULL, -- Link to users table (for parent)
      `parent_contact` VARCHAR(255) DEFAULT NULL, -- Phone number or WA ID, denormalized
      `message_content` TEXT,
      `channel_used` ENUM('sms', 'whatsapp', 'email') NOT NULL,
      `send_status` ENUM('success', 'failed', 'pending') NOT NULL,
      `gateway_response` TEXT DEFAULT NULL, -- Optional: Store response from SMS/WA gateway
      `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`student_id`) REFERENCES `students`(`student_id`) ON DELETE SET NULL,
      FOREIGN KEY (`parent_user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
      -- Add other relevant indexes, e.g., on timestamp, channel_used, send_status
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    */
    ";
}

/**
 * Gets the start and end dates for the current academic year.
 * CUSTOMIZATION POINT: Adjust this logic based on how your school defines its academic year.
 * This is a simplified example.
 *
 * @param mysqli $db (Optional) Database connection, if academic year settings are stored there.
 * @return array ['start_date' => 'YYYY-MM-DD', 'end_date' => 'YYYY-MM-DD']
 */
function getCurrentAcademicYearDates(mysqli $db = null): array {
    // Example: Academic year is June 1st to May 31st
    $currentMonth = (int)date('m');
    $currentYear = (int)date('Y');
    $academicYearStartMonth = 6; // June
    $academicYearStartDay = 1;

    if ($currentMonth >= $academicYearStartMonth) {
        $yearStart = $currentYear;
    } else {
        $yearStart = $currentYear - 1;
    }
    $startDate = date('Y-m-d', mktime(0, 0, 0, $academicYearStartMonth, $academicYearStartDay, $yearStart));
    $endDate = date('Y-m-d', mktime(0, 0, 0, $academicYearStartMonth, $academicYearStartDay -1 , $yearStart + 1));

    // Fallback or alternative: fetch from a settings table
    // $setting_start = get_setting($db, 'academic_year_start_date_config'); // e.g., "YYYY-06-01"
    // if ($setting_start) { ... adjust logic ... }

    return [
        'start_date' => $startDate,
        'end_date' => $endDate // Or today's date if you only want up to current
    ];
}

?>
