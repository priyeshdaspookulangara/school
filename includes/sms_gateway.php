<?php
/**
 * SMS Gateway Integration
 *
 * This file contains functions to interact with an SMS gateway
 * and to retrieve SMS related settings from the database.
 */

/**
 * Retrieves a specific SMS setting value from the sms_settings table.
 *
 * CRITICAL SECURITY WARNING: The key is used directly in an SQL query.
 * While this function is intended for internal use with predefined keys,
 * if $key could ever come from external input, it MUST be validated against a whitelist
 * or the query MUST be converted to use prepared statements.
 *
 * @param mysqli $conn The database connection object.
 * @param string $key The setting_key to retrieve.
 * @return string|null The setting_value if found, null otherwise.
 */
function get_sms_setting(mysqli $conn, string $key): ?string {
    // CRITICAL SECURITY WARNING: $key comes from code, not user input in current design.
    // If $key could be user-influenced, this is a SQL injection risk without whitelisting or prepared statements.
    // For this internal function, we assume $key is safe.
    $sanitized_key = mysqli_real_escape_string($conn, $key);
    $sql = "SELECT setting_value FROM sms_settings WHERE setting_key = '$sanitized_key' LIMIT 1";

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $result->free();
        return $row['setting_value'];
    } else {
        // Log error if query failed for reasons other than not found
        if (!$result) {
            error_log("SMS Setting SQL Error for key '$sanitized_key': " . $conn->error);
        }
        return null;
    }
}

/**
 * Sends an SMS message using a generic SMS Gateway API via cURL.
 *
 * CUSTOMIZATION POINT: This function is a placeholder.
 * You MUST replace the placeholder API URL, key, sender ID, and request parameters
 * with the actual details provided by your SMS gateway vendor.
 *
 * @param mysqli $conn The database connection object (used to fetch gateway settings).
 * @param string $mobile_number The recipient's mobile number (international format preferred).
 * @param string $message The text message to send.
 * @return bool True if the SMS was accepted by the gateway (or simulated successfully), false otherwise.
 */
function send_sms(mysqli $conn, string $mobile_number, string $message): bool {
    // CUSTOMIZATION POINT: Retrieve SMS Gateway credentials from settings
    $apiUrl = get_sms_setting($conn, 'sms_gateway_api_url');
    $apiKey = get_sms_setting($conn, 'sms_gateway_api_key');
    $senderId = get_sms_setting($conn, 'sms_gateway_sender_id');

    if (empty($apiUrl) || empty($apiKey) || empty($senderId) ||
        $apiUrl === 'YOUR_SMS_GATEWAY_API_URL_HERE' ||
        $apiKey === 'YOUR_SMS_GATEWAY_API_KEY_HERE' ||
        $senderId === 'YOUR_SENDER_ID_HERE') {
        error_log("SMS Gateway settings (API URL, Key, or Sender ID) are not configured or are placeholders.");
        // To prevent accidental real sends with placeholder data during development,
        // you might return true here and log, or return false.
        // For this example, we'll log and return false to indicate a configuration issue.
        return false;
    }

    // Basic validation
    if (empty($mobile_number) || empty($message)) {
        error_log("SMS send failed: Mobile number or message is empty.");
        return false;
    }

    // **CRITICAL SECURITY WARNING:**
    // Ensure $mobile_number is validated to be a phone number to prevent SSRF or other injection if the API URL were dynamic.
    // Ensure $message is properly encoded if your SMS gateway requires it (e.g., URL encoding for GET parameters).

    // CUSTOMIZATION POINT: SMS Gateway API Interaction
    // The following is a generic cURL example. Adjust it based on your SMS gateway's API documentation.
    // Common parameters include: 'apikey', 'secret', 'token', 'to', 'from', 'senderid', 'text', 'message', 'route', 'type', etc.

    $postData = [
        // Example parameters - REPLACE THESE with your gateway's actual parameter names and values
        'apikey' => $apiKey,
        'numbers' => $mobile_number, // Parameter name for recipient number(s)
        'sender' => $senderId,    // Parameter name for sender ID
        'message' => $message,     // Parameter name for the message text
        // Add any other required parameters by your gateway (e.g., route, country code, message type)
        // 'route' => 'transactional', // Example: some gateways require a route
        // 'country_code' => '91' // Example: if number is national and gateway needs country code
    ];

    // Using http_build_query for POST data if the gateway expects application/x-www-form-urlencoded
    $payload = http_build_query($postData);
    // If your gateway expects JSON, use:
    // $payload = json_encode($postData);
    // And set Content-Type header to application/json

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); // Uncomment if sending JSON

    // CRITICAL SECURITY WARNING: For production, ensure SSL/TLS verification is enabled.
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    // You might need to configure CURLOPT_CAINFO with the path to your CA bundle.
    // For development/testing with self-signed certs, you might temporarily disable (NOT recommended for production):
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // REMOVE OR SET TO TRUE IN PRODUCTION
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // REMOVE OR SET TO 2 IN PRODUCTION


    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("SMS Gateway cURL Error for $mobile_number: " . $curlError);
        return false;
    }

    // CUSTOMIZATION POINT: Interpret SMS Gateway Response
    // The success condition depends on your gateway's response format (e.g., HTTP status code, JSON/XML response body).
    // Typically, a 2xx HTTP status code indicates success or that the message was queued.
    if ($httpCode >= 200 && $httpCode < 300) {
        // Further check response body if necessary
        // Example: $responseData = json_decode($response, true);
        // if ($responseData && isset($responseData['status']) && $responseData['status'] == 'success') {
        //     return true;
        // }
        // error_log("SMS Gateway success for $mobile_number, but response content indicates failure: $response");
        // return false;
        error_log("SMS sent/queued via gateway for $mobile_number. HTTP: $httpCode. Response: $response");
        return true; // Assuming 2xx means success for this placeholder
    } else {
        error_log("SMS Gateway Error for $mobile_number: HTTP $httpCode. Response: $response");
        return false;
    }

    // --- Fallback for environments where cURL might not be available or for testing ---
    /*
    // This is a dummy implementation for testing without a live gateway.
    // In a real application, you'd remove this or guard it with a development environment check.
    if (defined('SIMULATE_SMS') && SIMULATE_SMS === true) {
        $logMessage = "SIMULATED SMS to $mobile_number: \"$message\"\n";
        // Log to a file or system log
        error_log($logMessage);
        // file_put_contents(__DIR__ . '/sms_log.txt', date('[Y-m-d H:i:s] ') . $logMessage, FILE_APPEND);
        return true;
    }
    // If not simulating and cURL part is commented out/failed before this point,
    // it implies an issue or that the actual implementation is missing.
    error_log("SMS sending is not fully implemented or configured.");
    return false;
    */
}

?>
