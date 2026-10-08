-- QTA: kursi ndryshon vetëm nga shërbimi që rindërton të gjithë orarin.
-- Pa ndryshime të të dhënave; kërkon migrimet deri më 2026-10-07.
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_20261008_require $$
CREATE PROCEDURE qta_migrate_20261008_require()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'course_group_students' AND COLUMN_NAME = 'legacy_final_score')
     OR NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'group_fixed_days') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ekzekuto së pari migrimet deri më 2026-10-07.';
  END IF;
END $$
CALL qta_migrate_20261008_require() $$
DROP PROCEDURE IF EXISTS qta_migrate_20261008_require $$
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
    IF COALESCE(@qta_course_change_group, 0) <> OLD.id
       OR NOT EXISTS (SELECT 1 FROM group_schedules s WHERE s.group_id = OLD.id) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Kursi ndryshohet nga faqja e grupit bashkë me rindërtimin e orarit.';
    END IF;
    IF EXISTS (SELECT 1 FROM enrollment_module_scores s WHERE s.group_id = OLD.id)
       OR EXISTS (SELECT 1 FROM course_group_students s WHERE s.group_id = OLD.id
                  AND (s.final_score IS NOT NULL OR s.legacy_final_score IS NOT NULL)) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Grupi ka pikë të regjistruara për kursin aktual; kursi nuk ndryshohet.';
    END IF;
  END IF;
END $$
DELIMITER ;
