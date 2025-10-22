-- Create the audit_capture stored procedure (safe to run multiple times)
DELIMITER $$
DROP PROCEDURE IF EXISTS audit_capture $$
CREATE PROCEDURE audit_capture (
  IN p_table_name VARCHAR(64),
  IN p_action     VARCHAR(10),   -- INSERT/UPDATE/DELETE
  IN p_row_pk     LONGTEXT,      -- JSON string
  IN p_old        LONGTEXT,      -- JSON string
  IN p_new        LONGTEXT       -- JSON string
)
BEGIN
  INSERT INTO audit_events (action, table_name, row_pk, user_id, ip_address, user_agent, old_data, new_data)
  VALUES (p_action, p_table_name, p_row_pk, @audit_user_id, @audit_ip, @audit_ua, p_old, p_new);

  SET @last_audit_event_id = LAST_INSERT_ID();
END $$
DELIMITER ;
