-- QTA — fixed_range si mënyrë e përgjithshme e orarit me periudhë të përcaktuar
-- 2026-10-07
--
-- Ky migrim nuk ndryshon të dhënat e konvertimit. source_start_date dhe
-- source_end_date te group_conversions vazhdojnë të mbrohen nga trigger-at e
-- migrimit 2026-09-28. Ndryshojnë vetëm rregullat për datat operative të grupit.

DELIMITER $$

DROP PROCEDURE IF EXISTS qta_migrate_20261007_require $$
CREATE PROCEDURE qta_migrate_20261007_require()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_schedules'
  ) OR NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_fixed_days'
  ) OR NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_conversions'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Mungon migrimi 2026-09-28-konvertimi-i-grupeve.sql.';
  END IF;
END $$

CALL qta_migrate_20261007_require() $$
DROP PROCEDURE IF EXISTS qta_migrate_20261007_require $$

/* Modeli dhe kursi mbeten të mbrojtur. Datat operative të një grupi scheduled
   lejohen të ndryshojnë; shërbimi i aplikacionit e bën rindërtimin në një
   transaksion dhe trigger-at e planit kontrollojnë çdo rresht të ri. */
DROP TRIGGER IF EXISTS trg_cg_model_guard_bu $$
CREATE TRIGGER trg_cg_model_guard_bu
BEFORE UPDATE ON course_groups
FOR EACH ROW
BEGIN
  IF NOT (NEW.model <=> OLD.model) THEN
    IF NOT (OLD.model = 'legacy' AND NEW.model = 'scheduled'
            AND EXISTS (SELECT 1 FROM group_conversions c WHERE c.group_id = OLD.id AND c.status = 'applying')) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lloji i grupit nuk mund të ndryshohet. Një grup i mëparshëm kalon te regjistri i kurseve profesionale vetëm me konvertim.';
    END IF;
    IF NOT (NEW.course_id <=> OLD.course_id) OR NOT (NEW.start_date <=> OLD.start_date) OR NOT (NEW.end_date <=> OLD.end_date) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Gjatë konvertimit kursi dhe datat e grupit nuk ndryshojnë.';
    END IF;
  END IF;
  IF OLD.model = 'scheduled' AND NOT (NEW.course_id <=> OLD.course_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Një grup me orar mësimi nuk mund të kalojë te një kurs tjetër.';
  END IF;
END $$

/* fixed_range mund të krijohet si për grup të ri, ashtu edhe gjatë konvertimit. */
DROP TRIGGER IF EXISTS trg_gs_requires_scheduled_bi $$
CREATE TRIGGER trg_gs_requires_scheduled_bi
BEFORE INSERT ON group_schedules
FOR EACH ROW
BEGIN
  DECLARE v_model VARCHAR(16) DEFAULT NULL;
  SELECT model INTO v_model FROM course_groups WHERE id = NEW.group_id;
  IF v_model IS NULL OR v_model <> 'scheduled' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Orari i mësimit lidhet vetëm me grupet me orar. Grupet e mëparshme mbeten pa orar.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_gs_group_fixed_bu $$
CREATE TRIGGER trg_gs_group_fixed_bu
BEFORE UPDATE ON group_schedules
FOR EACH ROW
BEGIN
  IF NOT (NEW.group_id <=> OLD.group_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Orari i një grupi nuk mund të kalojë te një grup tjetër.';
  END IF;
  IF NOT (NEW.schedule_mode <=> OLD.schedule_mode) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Mënyra e orarit të një grupi nuk ndryshon pas krijimit.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_gdr_requires_calculated_bi $$
CREATE TRIGGER trg_gdr_requires_calculated_bi
BEFORE INSERT ON group_day_rules
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM group_schedules s WHERE s.group_id = NEW.group_id AND s.schedule_mode = 'calculated') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Ditët e veçanta vlejnë vetëm për grupet me orar të llogaritur. Një grup me periudhë të përcaktuar ndryshon me planin e ditëve.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_gfd_requires_fixed_bi $$
CREATE TRIGGER trg_gfd_requires_fixed_bi
BEFORE INSERT ON group_fixed_days
FOR EACH ROW
BEGIN
  DECLARE v_mode VARCHAR(16) DEFAULT NULL;
  DECLARE v_start DATE DEFAULT NULL;
  DECLARE v_end DATE DEFAULT NULL;
  SELECT s.schedule_mode, g.start_date, g.end_date INTO v_mode, v_start, v_end
  FROM group_schedules s JOIN course_groups g ON g.id = s.group_id
  WHERE s.group_id = NEW.group_id;
  IF v_mode IS NULL OR v_mode <> 'fixed_range' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Plani i plotë i ditëve vlen vetëm për oraret me periudhë të përcaktuar.';
  END IF;
  IF NEW.lesson_date < v_start OR NEW.lesson_date > v_end THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Një datë e planit është jashtë periudhës së përcaktuar të grupit.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_gfd_fixed_bu $$
CREATE TRIGGER trg_gfd_fixed_bu
BEFORE UPDATE ON group_fixed_days
FOR EACH ROW
BEGIN
  IF NOT (NEW.group_id <=> OLD.group_id) OR NOT (NEW.lesson_date <=> OLD.lesson_date) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Identiteti i një dite të planit nuk ndryshon; rindërtimi fshin dhe shkruan planin e plotë.';
  END IF;
END $$

/* Historiku i kokës së orarit përfshin edhe revision/generated_at, në mënyrë që
   një rindërtim të jetë i dukshëm edhe kur numri i ditëve mbetet i njëjtë. */
DROP TRIGGER IF EXISTS trg_audit_gs_ai $$
CREATE TRIGGER trg_audit_gs_ai AFTER INSERT ON group_schedules
FOR EACH ROW
BEGIN
  CALL audit_capture('group_schedules','INSERT', JSON_OBJECT('group_id', NEW.group_id), NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'schedule_mode', NEW.schedule_mode, 'daily_hours', NEW.daily_hours,
                'course_hours', NEW.course_hours, 'curriculum_taken_at', NEW.curriculum_taken_at,
                'teaching_days', NEW.teaching_days, 'revision', NEW.revision, 'generated_at', NEW.generated_at));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'schedule_mode',NULL,NEW.schedule_mode),(@e,'daily_hours',NULL,NEW.daily_hours),
    (@e,'course_hours',NULL,NEW.course_hours),(@e,'curriculum_taken_at',NULL,NEW.curriculum_taken_at),
    (@e,'teaching_days',NULL,NEW.teaching_days),(@e,'revision',NULL,NEW.revision),(@e,'generated_at',NULL,NEW.generated_at);
END $$

DROP TRIGGER IF EXISTS trg_audit_gs_au $$
CREATE TRIGGER trg_audit_gs_au AFTER UPDATE ON group_schedules
FOR EACH ROW
BEGIN
  IF NOT (OLD.daily_hours <=> NEW.daily_hours) OR NOT (OLD.course_hours <=> NEW.course_hours)
     OR NOT (OLD.curriculum_taken_at <=> NEW.curriculum_taken_at) OR NOT (OLD.teaching_days <=> NEW.teaching_days)
     OR NOT (OLD.revision <=> NEW.revision) OR NOT (OLD.generated_at <=> NEW.generated_at) THEN
    CALL audit_capture('group_schedules','UPDATE', JSON_OBJECT('group_id', NEW.group_id),
      JSON_OBJECT('group_id', OLD.group_id, 'schedule_mode', OLD.schedule_mode, 'daily_hours', OLD.daily_hours,
                  'course_hours', OLD.course_hours, 'curriculum_taken_at', OLD.curriculum_taken_at,
                  'teaching_days', OLD.teaching_days, 'revision', OLD.revision, 'generated_at', OLD.generated_at),
      JSON_OBJECT('group_id', NEW.group_id, 'schedule_mode', NEW.schedule_mode, 'daily_hours', NEW.daily_hours,
                  'course_hours', NEW.course_hours, 'curriculum_taken_at', NEW.curriculum_taken_at,
                  'teaching_days', NEW.teaching_days, 'revision', NEW.revision, 'generated_at', NEW.generated_at));
    SET @e := @last_audit_event_id;
    IF NOT (OLD.daily_hours <=> NEW.daily_hours) THEN INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES (@e,'daily_hours',OLD.daily_hours,NEW.daily_hours); END IF;
    IF NOT (OLD.course_hours <=> NEW.course_hours) THEN INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES (@e,'course_hours',OLD.course_hours,NEW.course_hours); END IF;
    IF NOT (OLD.curriculum_taken_at <=> NEW.curriculum_taken_at) THEN INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES (@e,'curriculum_taken_at',OLD.curriculum_taken_at,NEW.curriculum_taken_at); END IF;
    IF NOT (OLD.teaching_days <=> NEW.teaching_days) THEN INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES (@e,'teaching_days',OLD.teaching_days,NEW.teaching_days); END IF;
    IF NOT (OLD.revision <=> NEW.revision) THEN INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES (@e,'revision',OLD.revision,NEW.revision); END IF;
    IF NOT (OLD.generated_at <=> NEW.generated_at) THEN INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES (@e,'generated_at',OLD.generated_at,NEW.generated_at); END IF;
  END IF;
END $$

/* Rindërtimi i periudhës fshin/shkruan rreshtat e planit; të dy veprimet
   auditohen. UPDATE vazhdon të auditohet nga migrimi 2026-09-28. */
DROP TRIGGER IF EXISTS trg_audit_gfd_ai $$
CREATE TRIGGER trg_audit_gfd_ai AFTER INSERT ON group_fixed_days
FOR EACH ROW
BEGIN
  CALL audit_capture('group_fixed_days','INSERT', JSON_OBJECT('group_id', NEW.group_id, 'lesson_date', NEW.lesson_date), NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'lesson_date', NEW.lesson_date, 'hours', NEW.hours, 'note', NEW.note));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'lesson_date',NULL,NEW.lesson_date),(@e,'hours',NULL,NEW.hours),(@e,'note',NULL,NEW.note);
END $$

DROP TRIGGER IF EXISTS trg_audit_gfd_ad $$
CREATE TRIGGER trg_audit_gfd_ad AFTER DELETE ON group_fixed_days
FOR EACH ROW
BEGIN
  CALL audit_capture('group_fixed_days','DELETE', JSON_OBJECT('group_id', OLD.group_id, 'lesson_date', OLD.lesson_date),
    JSON_OBJECT('group_id', OLD.group_id, 'lesson_date', OLD.lesson_date, 'hours', OLD.hours, 'note', OLD.note), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id,column_name,old_value,new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'lesson_date',OLD.lesson_date,NULL),(@e,'hours',OLD.hours,NULL),(@e,'note',OLD.note,NULL);
END $$

DELIMITER ;

-- Fund i migrimit.
