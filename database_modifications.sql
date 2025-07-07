-- Database Modifications for SMS Notification Functionality

-- 1. Create sms_settings table
CREATE TABLE IF NOT EXISTS `sms_settings` (
  `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(255) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `description` VARCHAR(500) DEFAULT NULL,
  `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Seed initial data into sms_settings table
-- CRITICAL SECURITY WARNING: The templates below contain placeholders.
-- Ensure data fetched for these placeholders is properly sanitized if it originates from user input at any point.
-- The primary concern here is ensuring the SQL queries fetching data for these templates are secure.

INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('daily_absent_sms_template', 'Dear {parent_name}, your son {student_name} ({roll_name}) has been absent today {curr_date}. It has been almost {absent_count_curr_year} days absent this year and {absent_count_curr_month} days this month. Please contact {school_phone} or {class_teacher_name}.', 'Template for daily SMS to parents of absent students.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);

INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('absent_threshold_count', '5', 'Default threshold for triggering an alert when a student''s absence count is exceeded.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);

INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('absent_threshold_sms_template', 'Dear {parent_name}, your son {student_name} ({roll_name}) has accumulated {absent_count_curr_year} days absent this year and {absent_count_curr_month} days this month. This is above the threshold. Please contact {school_phone} or {class_teacher_name}.', 'Template for SMS when a student exceeds the absence threshold.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);

INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('school_phone', '+919876543210', 'Default school contact phone number for SMS templates.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);

INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('sms_gateway_api_url', 'YOUR_SMS_GATEWAY_API_URL_HERE', 'The API endpoint URL for your SMS gateway provider.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);

INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('sms_gateway_api_key', 'YOUR_SMS_GATEWAY_API_KEY_HERE', 'Your API key for the SMS gateway.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);

INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('sms_gateway_sender_id', 'YOUR_SENDER_ID_HERE', 'Your approved Sender ID for the SMS gateway.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);

-- 3. Optional ALTER TABLE for students.admission_no (if needed for roll_name)
-- If your `students` table does not have an `admission_no` column, or if it's not suitable
-- for use as `roll_name` (e.g., it's an integer and you need a string, or it's missing),
-- you might need to add or modify it.
-- Example:
-- ALTER TABLE `students` ADD COLUMN `admission_no` VARCHAR(50) NULL DEFAULT NULL;
-- Or, if `admission_no` is used for something else, you might add a dedicated `roll_name` column:
-- ALTER TABLE `students` ADD COLUMN `roll_name` VARCHAR(50) NULL DEFAULT NULL;
-- Ensure this column is populated for existing students if you add it.
-- The placeholder {roll_name} in SMS templates assumes such a field exists and is populated.

-- Note: Additional columns might be needed in your `students`, `users` (for parents/teachers),
-- `classes`, or other tables to fully support all placeholders in the SMS templates.
-- For example:
-- - `students.parent_user_id` (linking to a `users` table for parent details)
-- - `students.class_id` (linking to a `classes` table)
-- - `classes.class_teacher_user_id` (linking to a `users` table for teacher details)
-- - `users.full_name` (for parent_name, student_name, class_teacher_name)
-- - `users.mobile_number` (for parent's mobile to send SMS)

-- CUSTOMIZATION POINT: Define Academic Year Start
-- The calculation of {absent_count_curr_year} depends on when your academic year starts.
-- You might need a setting for 'academic_year_start_month' (e.g., '04' for April)
-- or a specific 'academic_year_start_date' if it varies.
-- For simplicity, the queries in the PHP code will assume the calendar year or require
-- manual adjustment. Consider adding a setting for this:
/*
INSERT INTO `sms_settings` (`setting_key`, `setting_value`, `description`) VALUES
('academic_year_start_month_day', '06-01', 'MM-DD format for academic year start (e.g., June 1st). Used for yearly absence counts.')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `description` = VALUES(`description`);
*/
-- The PHP code will need to be adjusted to use such a setting.

-- End of Database Modifications
-- Remember to execute these SQL statements against your database.
