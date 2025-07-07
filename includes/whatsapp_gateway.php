<?php
/**
 * WhatsApp Gateway Integration
 *
 * This file contains functions to interact with a WhatsApp Business API.
 * Note: Interacting with WhatsApp Business APIs (e.g., Meta, Twilio, Vonage)
 * is often more complex than typical SMS gateways. It usually involves:
 * - Pre-approved message templates for notifications.
 * - Specific API endpoints and authentication mechanisms (e.g., Bearer tokens).
 * - Potentially different rate limits and compliance requirements.
 */

// It's good practice to have get_sms_setting here if it's used for WhatsApp config,
// or ensure it's available from where this function is called.
// require_once __DIR__ . '/sms_gateway.php'; // If get_sms_setting is in there

/**
 * Sends a WhatsApp message using a generic WhatsApp Business API via cURL.
 *
 * CUSTOMIZATION POINT: This function is a highly conceptual placeholder.
 * You MUST replace the placeholder API URL, token, phone number ID, and request parameters
 * with the actual details provided by your WhatsApp Business API provider.
 * WhatsApp APIs often require using pre-approved templates for business-initiated messages.
 *
 * @param mysqli $db_conn The database connection object (used to fetch gateway settings).
 * @param string $mobile_number The recipient's mobile number (international format, e.g., +91XXXXXXXXXX).
 * @param string $message The text message to send. If using templates, this might be structured data.
 * @param string $template_name (Optional) The name of the pre-approved WhatsApp template, if applicable.
 * @param array $template_params (Optional) Parameters for the WhatsApp template, if applicable.
 * @return bool True if the message was accepted by the gateway (or simulated successfully), false otherwise.
 */
function send_whatsapp_message(mysqli $db_conn, string $mobile_number, string $message, string $template_name = '', array $template_params = []): bool {
    // CUSTOMIZATION POINT: Retrieve WhatsApp Gateway credentials from settings
    // These settings should be stored in your 'sms_settings' table or a similar config.
    $apiUrl = get_sms_setting($db_conn, 'whatsapp_gateway_api_url'); // e.g., https://graph.facebook.com/v17.0/YOUR_PHONE_NUMBER_ID/messages
    $apiToken = get_sms_setting($db_conn, 'whatsapp_gateway_api_token'); // Bearer token
    $phoneNumberId = get_sms_setting($db_conn, 'whatsapp_gateway_phone_number_id'); // Your WhatsApp Business Phone Number ID

    if (empty($apiUrl) || empty($apiToken) || empty($phoneNumberId) ||
        $apiUrl === 'YOUR_WHATSAPP_GATEWAY_API_URL' || // Check for placeholder values
        $apiToken === 'YOUR_WHATSAPP_GATEWAY_TOKEN' ||
        $phoneNumberId === 'YOUR_WHATSAPP_PHONE_NUMBER_ID') {
        error_log("WhatsApp Gateway settings (API URL, Token, or Phone Number ID) are not configured or are placeholders.");
        return false;
    }

    // Basic validation
    if (empty($mobile_number) || (empty($message) && empty($template_name))) {
        error_log("WhatsApp send failed: Mobile number or message/template_name is empty.");
        return false;
    }

    // **CRITICAL SECURITY WARNING:**
    // Ensure $mobile_number is validated. WhatsApp numbers usually don't include '+'.
    // The message content might need to conform to a pre-approved template if sending notifications.
    $recipientWaId = preg_replace('/[^0-9]/', '', $mobile_number); // Strip non-numeric for WhatsApp ID format

    // Construct the payload based on whether it's a template message or a free-form text (less common for notifications)
    // This is a common structure for Meta's WhatsApp API. Other providers might differ.
    $payloadData = [
        'messaging_product' => 'whatsapp',
        'to' => $recipientWaId, // WhatsApp ID (phone number without '+')
        'type' => '', // 'template' or 'text'
    ];

    if (!empty($template_name)) {
        $payloadData['type'] = 'template';
        $payloadData['template'] = [
            'name' => $template_name,
            'language' => ['code' => 'en_US'], // Or your desired language code
        ];
        // Add components if your template has variables, headers, buttons etc.
        // Example for template with variable body parameters:
        if (!empty($template_params)) {
             $components = [];
             // Example: if template has body variables
             $body_parameters = [];
             foreach($template_params as $param_value) {
                 $body_parameters[] = ['type' => 'text', 'text' => $param_value];
             }
             $components[] = ['type' => 'body', 'parameters' => $body_parameters];
             // Add other components like header, buttons if needed
             $payloadData['template']['components'] = $components;
        }
    } else {
        // Sending free-form text (often restricted for business-initiated messages, usually for customer care responses)
        $payloadData['type'] = 'text';
        $payloadData['text'] = ['body' => $message];
    }

    $jsonPayload = json_encode($payloadData);

    $ch = curl_init();
    // CUSTOMIZATION POINT: Adjust URL if $phoneNumberId is not part of base $apiUrl
    // Example for Meta API: $apiUrl would be "https://graph.facebook.com/vXX.X/" and $phoneNumberId is part of the path.
    // So, the URL might be $apiUrl . $phoneNumberId . "/messages"
    // For simplicity, assume $apiUrl is the full base path to the /messages endpoint for now.
    // If $apiUrl from settings is like "https://graph.facebook.com/v17.0/", then:
    // curl_setopt($ch, CURLOPT_URL, $apiUrl . $phoneNumberId . '/messages');
    // If $apiUrl from settings is already the full thing:
    curl_setopt($ch, CURLOPT_URL, $apiUrl);


    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
    ]);

    // CRITICAL SECURITY WARNING: For production, ensure SSL/TLS verification is enabled.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // SHOULD BE TRUE IN PRODUCTION
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // SHOULD BE 2 IN PRODUCTION
    // For testing locally if you have SSL issues, you might temporarily use:
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // REMOVE OR SET TO TRUE IN PRODUCTION
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // REMOVE OR SET TO 2 IN PRODUCTION

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("WhatsApp Gateway cURL Error for $recipientWaId: " . $curlError);
        return false;
    }

    // CUSTOMIZATION POINT: Interpret WhatsApp Gateway Response
    // Success is typically indicated by HTTP 200 OK and a specific response body.
    if ($httpCode == 200) {
        $responseData = json_decode($response, true);
        // Example check (Meta API): response might contain message IDs
        if ($responseData && isset($responseData['messages'][0]['id'])) {
            error_log("WhatsApp message sent/queued for $recipientWaId. HTTP: $httpCode. Response: $response");
            return true;
        } else {
            error_log("WhatsApp Gateway success HTTP for $recipientWaId, but response content indicates failure or unexpected format: $response");
            return false;
        }
    } else {
        error_log("WhatsApp Gateway Error for $recipientWaId: HTTP $httpCode. Response: $response");
        return false;
    }

    // Fallback for simulation if needed (similar to SMS gateway)
    /*
    if (defined('SIMULATE_WHATSAPP') && SIMULATE_WHATSAPP === true) {
        $logMessage = "SIMULATED WHATSAPP to $recipientWaId: Template: '$template_name', Message: \"$message\"\n";
        error_log($logMessage);
        return true;
    }
    error_log("WhatsApp sending is not fully implemented or configured correctly beyond simulation.");
    return false;
    */
}

?>
