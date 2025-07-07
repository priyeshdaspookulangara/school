<?php
/**
 * API Handler for Managing SMS Settings (Admin)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/auth.php'; // For isAuthenticated and isAuthorized

/**
 * Handles retrieving all SMS settings.
 * GET /api/admin/sms_settings
 *
 * @param mysqli $db Database connection object.
 */
function handleGetSmsSettings(mysqli $db) {
    // Authentication and Authorization
    if (!isAuthenticated($db)) {
        sendUnauthorizedResponse('Authentication required.');
        return;
    }
    if (!isAuthorized($db, 'admin')) {
        sendForbiddenResponse('You are not authorized to view SMS settings.');
        return;
    }

    $sql = "SELECT setting_id, setting_key, setting_value, description, last_updated FROM sms_settings ORDER BY setting_key ASC";
    // CRITICAL SECURITY WARNING: This query is static and does not use user input, so less risk here.
    // However, always be cautious. If any part were dynamic, it would need sanitization or prepared statements.

    $result = $db->query($sql);

    if ($result) {
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            // For sensitive settings like API keys, consider masking them or excluding them for general GET requests
            // if non-super-admins have access. For now, assuming 'admin' role is trusted.
            // Example: if ($row['setting_key'] === 'sms_gateway_api_key' && !isSuperAdmin()) $row['setting_value'] = '********';
            $settings[] = $row;
        }
        $result->free();
        http_response_code(200);
        echo json_encode($settings);
    } else {
        http_response_code(500);
        // **SECURITY WARNING:** Do not expose $db->error directly to clients in production.
        error_log("Admin Get SMS Settings DB Error: " . $db->error);
        echo json_encode(['error' => 'Database error while fetching SMS settings.']);
    }
}

/**
 * Handles updating or creating (UPSERT) SMS settings.
 * POST /api/admin/sms_settings
 *
 * Expected JSON payload:
 * [
 *   { "setting_key": "key1", "setting_value": "value1", "description": "desc1 (optional)" },
 *   { "setting_key": "key2", "setting_value": "value2" }
 * ]
 * or a single object:
 * { "setting_key": "key1", "setting_value": "value1", "description": "desc1 (optional)" }
 *
 * @param mysqli $db Database connection object.
 */
function handleUpdateSmsSettings(mysqli $db) {
    // Authentication and Authorization
    if (!isAuthenticated($db)) {
        sendUnauthorizedResponse('Authentication required.');
        return;
    }
    if (!isAuthorized($db, 'admin')) {
        sendForbiddenResponse('You are not authorized to update SMS settings.');
        return;
    }

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

    if (empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'No settings data provided.']);
        return;
    }

    // Handle both single object and array of objects
    $settingsToUpdate = [];
    if (isset($data['setting_key'])) { // Single object
        $settingsToUpdate[] = $data;
    } elseif (is_array($data)) { // Array of objects
        $settingsToUpdate = $data;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data format. Expecting a single setting object or an array of setting objects.']);
        return;
    }


    $db->begin_transaction();
    $updatedCount = 0;
    $failedUpdates = [];

    // Define a whitelist of updatable setting keys for extra security, if desired.
    $allowedKeys = [
        'daily_absent_sms_template',
        'absent_threshold_count',
        'absent_threshold_sms_template',
        'school_phone',
        'sms_gateway_api_url',
        'sms_gateway_api_key',
        'sms_gateway_sender_id',
        'academic_year_start_month_day',
        'daily_absent_whatsapp_template',      // New
        'absent_threshold_whatsapp_template',  // New
        'default_comm_channel',                // New
        'whatsapp_gateway_api_url',            // New (if you add it)
        'whatsapp_gateway_api_token',          // New (if you add it)
        'whatsapp_gateway_phone_number_id'     // New (if you add it)
    ];

    foreach ($settingsToUpdate as $setting) {
        if (!isset($setting['setting_key']) || !array_key_exists('setting_value', $setting)) { // Check array_key_exists for potentially empty but intentional values
            $failedUpdates[] = ['item' => $setting, 'error' => 'Missing setting_key or setting_value.'];
            continue;
        }

        // **CRITICAL SECURITY WARNING:**
        // User-supplied `setting_key` and `setting_value` are used in SQL.
        // They MUST be sanitized using mysqli_real_escape_string.
        // PREPARED STATEMENTS are STRONGLY RECOMMENDED for production.
        $key = mysqli_real_escape_string($db, trim($setting['setting_key']));
        $value = mysqli_real_escape_string($db, $setting['setting_value']); // Value can be long, so TEXT type is appropriate
        $description = isset($setting['description']) ? mysqli_real_escape_string($db, $setting['description']) : null;

        // Validate $key against a whitelist of allowed setting keys
        if (!in_array($setting['setting_key'], $allowedKeys)) {
            $failedUpdates[] = ['key' => $setting['setting_key'], 'error' => 'Invalid or not updatable setting_key.'];
            continue;
        }

        // Specific validation for default_comm_channel
        if ($key === 'default_comm_channel') {
            $allowedCommValues = ['sms', 'whatsapp', 'both', 'none'];
            if (!in_array(strtolower($setting['setting_value']), $allowedCommValues)) {
                $failedUpdates[] = ['key' => $setting['setting_key'], 'value' => $setting['setting_value'], 'error' => 'Invalid value for default_comm_channel. Allowed: sms, whatsapp, both, none.'];
                continue;
            }
            // Ensure value is stored in lowercase for consistency
            $value = mysqli_real_escape_string($db, strtolower($setting['setting_value']));
        }


        if (empty($key)) { // This check is somewhat redundant if using the $allowedKeys whitelist correctly
            $failedUpdates[] = ['item' => $setting, 'error' => 'setting_key cannot be empty.'];
            continue;
        }

        // UPSERT logic: INSERT ... ON DUPLICATE KEY UPDATE
        // The `description` is updated only if provided, otherwise it keeps its existing value or default NULL.
        if ($description !== null) {
            $sql = "INSERT INTO sms_settings (setting_key, setting_value, description)
                    VALUES ('$key', '$value', '$description')
                    ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    description = VALUES(description),
                    last_updated = CURRENT_TIMESTAMP";
        } else {
            // If description is not provided in input, don't try to set it to NULL,
            // let it keep its current value on update.
            $sql = "INSERT INTO sms_settings (setting_key, setting_value)
                    VALUES ('$key', '$value')
                    ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    last_updated = CURRENT_TIMESTAMP";
        }


        if ($db->query($sql)) {
            // $db->affected_rows will be 1 for an INSERT, 2 for an UPDATE (if value changed), 0 if value was same.
            if ($db->affected_rows > 0) {
                 $updatedCount++;
            } else {
                // Potentially log if a key was submitted but value was identical, so no actual DB change.
                // For simplicity, we count it as "processed" but not strictly "updated".
            }
        } else {
            // **SECURITY WARNING:** Do not expose $db->error directly.
            error_log("Admin Update SMS Setting DB Error for key '$key': " . $db->error);
            $failedUpdates[] = ['key' => $setting['setting_key'], 'error' => 'Database error during update.'];
        }
    }

    if (count($failedUpdates) > 0 && $updatedCount === 0 && count($failedUpdates) === count($settingsToUpdate)) {
        $db->rollback();
        http_response_code(400); // All items failed
        echo json_encode([
            'error' => 'Failed to update any SMS settings.',
            'failed_settings' => $failedUpdates
        ]);
    } elseif (count($failedUpdates) > 0) {
        $db->commit(); // Commit successful ones
        http_response_code(207); // Multi-Status
        echo json_encode([
            'message' => 'SMS settings processed with some failures.',
            'updated_count' => $updatedCount,
            'failed_settings' => $failedUpdates
        ]);
    } else {
        $db->commit();
        http_response_code(200); // OK
        echo json_encode([
            'message' => 'SMS settings updated successfully.',
            'updated_count' => $updatedCount
        ]);
    }
}

?>
