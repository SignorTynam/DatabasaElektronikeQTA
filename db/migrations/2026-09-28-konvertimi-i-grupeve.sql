-- =====================================================================
-- Migrimi 2026-09-28 — Konvertimi i grupeve të mëparshme + 8 orë në ditë
-- =====================================================================
--
-- Çfarë bën:
--   0. Kontrollon para çdo ndryshimi:
--        a) që migrimi 2026-09-26 është ekzekutuar;
--        b) që asnjë grup me orar nuk ka më shumë se 8 orë në një ditë
--           (orët në ditë, ditët e orarit, pjesët e tyre, ditët e veçanta).
--      Nëse gjen diçka, ndalet PA NDRYSHUAR ASGJË dhe liston rreshtat që duhen
--      korrigjuar (te faqja e grupit: "Ndrysho fillimin ose orët në ditë" ose
--      dita e veçantë). Pastaj ekzekutohet sërish.
--   1. Kufiri i një dite mësimi bëhet 8 orë (CHECK te group_schedules,
--      group_schedule_days, group_schedule_slots, group_day_rules).
--   2. group_schedules.schedule_mode:
--        'calculated'  grupet e krijuara me orar: fillimi + orët në ditë + ditët
--                      e veçanta → data e mbarimit llogaritet (daily_hours 1–8);
--        'fixed_range' grupet e konvertuara nga regjistri i vjetër: fillimi dhe
--                      mbarimi janë data historike; plani i orëve për çdo datë
--                      (group_fixed_days) është burimi i orarit (daily_hours NULL).
--      Çdo orar ekzistues merr 'calculated' dhe mbetet i njëjtë.
--   3. group_fixed_days: çdo datë nga fillimi te mbarimi i një grupi të
--      konvertuar, me orët e saj (0 = pa mësim, 1–8).
--   4. legacy_conversion_drafts: propozimi që shqyrtohet para konvertimit
--      (një për grup, me versionin dhe gjurmën e të dhënave burimore).
--   5. group_conversions: shënimi i përhershëm i çdo konvertimi (kur, kush,
--      datat historike, mënyra e propozimit, gjurma e planit të miratuar).
--   6. Rregullat e bazës:
--        - 'legacy' → 'scheduled' lejohet VETËM për grupin që ka një konvertim
--          në proces ('applying') brenda të njëjtit transaksion; çdo ndryshim
--          tjetër i llojit refuzohet, si më parë ('scheduled' → 'legacy' kurrë);
--        - fillimi dhe mbarimi i një grupi të konvertuar nuk ndryshojnë;
--        - lloji i orarit nuk ndryshon pas krijimit;
--        - një orar 'fixed_range' krijohet vetëm gjatë një konvertimi;
--        - ditët e veçanta vlejnë vetëm për 'calculated', plani i ditëve vetëm
--          për 'fixed_range'.
--   7. Historiku: konvertimi, lloji i orarit, ndryshimi i llojit të grupit dhe
--      korrigjimet e planit të ditëve (datë për datë).
--
-- Çfarë NUK bën:
--   - nuk konverton asnjë grup, nuk fshin dhe nuk rishkruan asnjë rresht;
--   - nuk prek grupet e mëparshme, kursantët, provimet apo pikët.
--
-- Mund të ekzekutohet disa herë (çdo hap kontrollon nëse është bërë; trigger-at
-- dhe kufijtë rikrijohen njësoj).
-- MariaDB 10.4+ / MySQL 8.0.19+ (ALTER TABLE … DROP CONSTRAINT).
-- Ekzekutimi: mysql qta_db < db/migrations/2026-09-28-konvertimi-i-grupeve.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 0. Kontrollet para çdo ndryshimi
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_20260928_check $$
CREATE PROCEDURE qta_migrate_20260928_check()
BEGIN
  DECLARE v_bad INT DEFAULT 0;

  IF NOT EXISTS (
       SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_groups' AND COLUMN_NAME = 'model')
     OR NOT EXISTS (
       SELECT 1 FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_schedules') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migrimi 2026-09-28 u ndal: ekzekuto së pari 2026-09-26-kurset-modulet-temat-orari.sql. Asgjë nuk ndryshoi.';
  END IF;

  SELECT COUNT(*) INTO v_bad FROM (
    SELECT group_id FROM group_schedules WHERE daily_hours > 8
    UNION ALL SELECT group_id FROM group_schedule_days WHERE hours > 8
    UNION ALL SELECT group_id FROM group_schedule_slots WHERE hours > 8
    UNION ALL SELECT group_id FROM group_day_rules WHERE hours > 8
  ) x;

  IF v_bad > 0 THEN
    /* Lista e plotë e asaj që duhet korrigjuar, para mesazhit të gabimit. */
    SELECT 'Orët në ditë (group_schedules.daily_hours)' AS cfare, group_id AS grupi, NULL AS data, daily_hours AS ore
      FROM group_schedules WHERE daily_hours > 8
    UNION ALL
    SELECT 'Ditë mësimi (group_schedule_days.hours)', group_id, lesson_date, hours
      FROM group_schedule_days WHERE hours > 8
    UNION ALL
    SELECT 'Pjesë e një dite (group_schedule_slots.hours)', s.group_id, d.lesson_date, s.hours
      FROM group_schedule_slots s
      JOIN group_schedule_days d ON d.group_id = s.group_id AND d.day_seq = s.day_seq
      WHERE s.hours > 8
    UNION ALL
    SELECT 'Ditë e veçantë (group_day_rules.hours)', group_id, rule_date, hours
      FROM group_day_rules WHERE hours > 8
    ORDER BY grupi, data;
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migrimi 2026-09-28 u ndal: disa grupe me orar kanë mbi 8 orë në një ditë (lista më sipër). Ul orët te faqja e secilit grup, pastaj ekzekuto sërish migrimin. Asgjë nuk ndryshoi.';
  END IF;
END $$
DELIMITER ;

CALL qta_migrate_20260928_check();
DROP PROCEDURE IF EXISTS qta_migrate_20260928_check;

-- ---------------------------------------------------------------------
-- 1 + 2. Kufiri 8 orë dhe lloji i orarit
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_20260928_check_replace $$
/* Zëvendëson (ose krijon) një CHECK me emrin e dhënë. E njëjta gjendje pas
   çdo ekzekutimi; baza kontrollon rreshtat ekzistues kur e shton. */
CREATE PROCEDURE qta_migrate_20260928_check_replace(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_clause VARCHAR(500))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table
      AND CONSTRAINT_NAME = p_name AND CONSTRAINT_TYPE = 'CHECK'
  ) THEN
    SET @qta_sql = CONCAT('ALTER TABLE `', p_table, '` DROP CONSTRAINT `', p_name, '`');
    PREPARE qta_stmt FROM @qta_sql; EXECUTE qta_stmt; DEALLOCATE PREPARE qta_stmt;
  END IF;
  SET @qta_sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_name, '` CHECK (', p_clause, ')');
  PREPARE qta_stmt FROM @qta_sql; EXECUTE qta_stmt; DEALLOCATE PREPARE qta_stmt;
END $$

DROP PROCEDURE IF EXISTS qta_migrate_20260928 $$
CREATE PROCEDURE qta_migrate_20260928()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_schedules' AND COLUMN_NAME = 'schedule_mode'
  ) THEN
    ALTER TABLE group_schedules
      ADD COLUMN schedule_mode ENUM('calculated','fixed_range') NOT NULL DEFAULT 'calculated' AFTER group_id;
  END IF;

  /* daily_hours: vetëm për orarin e llogaritur. Kufiri i vjetër hiqet para se
     kolona të lejojë NULL, pastaj vendoset rregulli i ri. */
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'group_schedules'
      AND CONSTRAINT_NAME = 'chk_gs_daily' AND CONSTRAINT_TYPE = 'CHECK'
  ) THEN
    ALTER TABLE group_schedules DROP CONSTRAINT chk_gs_daily;
  END IF;
  ALTER TABLE group_schedules MODIFY daily_hours TINYINT UNSIGNED NULL;
END $$
DELIMITER ;

CALL qta_migrate_20260928();
DROP PROCEDURE IF EXISTS qta_migrate_20260928;

/* "IS NOT NULL": një CHECK refuzon vetëm kur shprehja del FALSE, jo NULL — pa të,
   një orar 'calculated' pa orë në ditë do të kalonte. */
CALL qta_migrate_20260928_check_replace('group_schedules', 'chk_gs_daily',
  '(schedule_mode = ''calculated'' AND daily_hours IS NOT NULL AND daily_hours BETWEEN 1 AND 8) OR (schedule_mode = ''fixed_range'' AND daily_hours IS NULL)');
CALL qta_migrate_20260928_check_replace('group_schedule_days', 'chk_gsd_hours', 'hours BETWEEN 1 AND 8');
CALL qta_migrate_20260928_check_replace('group_schedule_slots', 'chk_gss_hours', 'hours BETWEEN 1 AND 8');
CALL qta_migrate_20260928_check_replace('group_day_rules', 'chk_gdr_hours', 'hours IS NULL OR hours BETWEEN 0 AND 8');

-- ---------------------------------------------------------------------
-- 3. Plani i ditëve të një grupi të konvertuar
-- ---------------------------------------------------------------------
-- Çdo datë nga fillimi te mbarimi ka rreshtin e vet: 0 = pa mësim, 1–8 = aq
-- orë mësimi. Ditët dhe pjesët e orarit (group_schedule_days/slots) ndërtohen
-- nga ky plan dhe nga kopja e temave.
CREATE TABLE IF NOT EXISTS group_fixed_days (
  group_id     INT NOT NULL,
  lesson_date  DATE NOT NULL,
  hours        TINYINT UNSIGNED NOT NULL,
  note         VARCHAR(160) NULL,
  updated_at   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, lesson_date),
  CONSTRAINT fk_gfd_schedule FOREIGN KEY (group_id) REFERENCES group_schedules(group_id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT chk_gfd_hours CHECK (hours BETWEEN 0 AND 8)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 4. Propozimi që shqyrtohet para konvertimit
-- ---------------------------------------------------------------------
-- Një për grup të mëparshëm. Ruajtja e tij nuk prek grupin. revision rritet me
-- çdo ruajtje (skeda të vjetra refuzohen); source_fingerprint = gjurma e grupit,
-- kursantëve dhe strukturës së kursit kur u ruajt (ndryshim → draft i vjetëruar).
CREATE TABLE IF NOT EXISTS legacy_conversion_drafts (
  group_id            INT NOT NULL PRIMARY KEY,
  revision            INT UNSIGNED NOT NULL DEFAULT 1,
  source_fingerprint  CHAR(64) NOT NULL,
  algorithm_version   VARCHAR(32) NOT NULL,
  plan_json           LONGTEXT NOT NULL,
  created_by          INT NULL,
  updated_by          INT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_lcd_group FOREIGN KEY (group_id) REFERENCES course_groups(id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_lcd_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_lcd_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_lcd_revision CHECK (revision >= 1),
  CONSTRAINT chk_lcd_plan CHECK (JSON_VALID(plan_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 5. Shënimi i përhershëm i konvertimit
-- ---------------------------------------------------------------------
-- 'applying' ekziston vetëm brenda transaksionit të konvertimit (askush tjetër
-- nuk e sheh); në fund bëhet 'completed'. Një grup konvertohet vetëm një herë.
CREATE TABLE IF NOT EXISTS group_conversions (
  group_id            INT NOT NULL PRIMARY KEY,
  source_start_date   DATE NOT NULL,
  source_end_date     DATE NOT NULL,
  course_hours        SMALLINT UNSIGNED NOT NULL,
  teaching_days       SMALLINT UNSIGNED NOT NULL,
  algorithm_version   VARCHAR(32) NOT NULL,
  approved_plan_hash  CHAR(64) NOT NULL,
  source_fingerprint  CHAR(64) NOT NULL,
  status              ENUM('applying','completed') NOT NULL DEFAULT 'applying',
  converted_by        INT NULL,
  converted_at        DATETIME NOT NULL,
  CONSTRAINT fk_gc_group FOREIGN KEY (group_id) REFERENCES course_groups(id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_gc_user FOREIGN KEY (converted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_gc_dates CHECK (source_end_date >= source_start_date),
  CONSTRAINT chk_gc_hours CHECK (course_hours >= 1 AND teaching_days >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 6. Rregullat e bazës
-- ---------------------------------------------------------------------
DELIMITER $$

/* Lloji i grupit: 'legacy' → 'scheduled' vetëm gjatë konvertimit të këtij grupi;
   asnjë ndryshim tjetër. Gjatë konvertimit kursi dhe datat nuk ndryshojnë.
   Grupi me orar nuk kalon te një kurs tjetër; datat historike të një grupi të
   konvertuar nuk ndryshojnë kurrë. */
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
  IF OLD.model = 'scheduled'
     AND (NOT (NEW.start_date <=> OLD.start_date) OR NOT (NEW.end_date <=> OLD.end_date))
     AND EXISTS (SELECT 1 FROM group_schedules s WHERE s.group_id = OLD.id AND s.schedule_mode = 'fixed_range') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Fillimi dhe mbarimi i një grupi të konvertuar janë data historike dhe nuk ndryshojnë.';
  END IF;
END $$

/* Orari lidhet vetëm me grupe me orar; orari me data historike krijohet vetëm
   brenda konvertimit të atij grupi. */
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
  IF NEW.schedule_mode = 'fixed_range'
     AND NOT EXISTS (SELECT 1 FROM group_conversions c WHERE c.group_id = NEW.group_id AND c.status = 'applying') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Një orar me data historike krijohet vetëm gjatë konvertimit të një grupi të mëparshëm.';
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
      SET MESSAGE_TEXT = 'Lloji i orarit të një grupi nuk ndryshon: një grup i konvertuar mbetet me datat e tij historike.';
  END IF;
END $$

/* Ditët e veçanta i përkasin orarit të llogaritur. */
DROP TRIGGER IF EXISTS trg_gdr_requires_calculated_bi $$
CREATE TRIGGER trg_gdr_requires_calculated_bi
BEFORE INSERT ON group_day_rules
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM group_schedules s WHERE s.group_id = NEW.group_id AND s.schedule_mode = 'calculated') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Ditët e veçanta vlejnë vetëm për grupet me orar të llogaritur. Një grup i konvertuar ndryshon me planin e ditëve.';
  END IF;
END $$

/* Plani i ditëve: vetëm për grupet e konvertuara, vetëm brenda datave historike. */
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
      SET MESSAGE_TEXT = 'Plani i ditëve vlen vetëm për grupet e konvertuara nga regjistri i vjetër.';
  END IF;
  IF NEW.lesson_date < v_start OR NEW.lesson_date > v_end THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Një datë e planit është jashtë periudhës historike të grupit.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_gfd_fixed_bu $$
CREATE TRIGGER trg_gfd_fixed_bu
BEFORE UPDATE ON group_fixed_days
FOR EACH ROW
BEGIN
  IF NOT (NEW.group_id <=> OLD.group_id) OR NOT (NEW.lesson_date <=> OLD.lesson_date) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Data e një dite të planit nuk ndryshon; ndryshohen vetëm orët e saj.';
  END IF;
END $$

/* Propozimi ruhet vetëm për një grup të mëparshëm. */
DROP TRIGGER IF EXISTS trg_lcd_requires_legacy_bi $$
CREATE TRIGGER trg_lcd_requires_legacy_bi
BEFORE INSERT ON legacy_conversion_drafts
FOR EACH ROW
BEGIN
  IF NOT EXISTS (SELECT 1 FROM course_groups g WHERE g.id = NEW.group_id AND g.model = 'legacy') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Një propozim konvertimi ruhet vetëm për një grup të regjistrit të vjetër.';
  END IF;
END $$

/* Konvertimi nis vetëm për një grup të mëparshëm, me datat e tij historike. */
DROP TRIGGER IF EXISTS trg_gc_start_bi $$
CREATE TRIGGER trg_gc_start_bi
BEFORE INSERT ON group_conversions
FOR EACH ROW
BEGIN
  DECLARE v_model VARCHAR(16) DEFAULT NULL;
  DECLARE v_start DATE DEFAULT NULL;
  DECLARE v_end DATE DEFAULT NULL;
  SELECT model, start_date, end_date INTO v_model, v_start, v_end FROM course_groups WHERE id = NEW.group_id;
  IF v_model IS NULL OR v_model <> 'legacy' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Konvertohet vetëm një grup i regjistrit të vjetër.';
  END IF;
  IF NEW.status <> 'applying' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Një konvertim nis gjithmonë si në proces.';
  END IF;
  IF NOT (NEW.source_start_date <=> v_start) OR NOT (NEW.source_end_date <=> v_end) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Datat e konvertimit duhet të jenë datat historike të grupit.';
  END IF;
END $$

/* Vetëm 'applying' → 'completed', dhe vetëm kur grupi ka orarin e tij historik. */
DROP TRIGGER IF EXISTS trg_gc_complete_bu $$
CREATE TRIGGER trg_gc_complete_bu
BEFORE UPDATE ON group_conversions
FOR EACH ROW
BEGIN
  IF OLD.status = 'completed' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Shënimi i një konvertimi të kryer nuk ndryshon.';
  END IF;
  IF NOT (NEW.group_id <=> OLD.group_id) OR NOT (NEW.source_start_date <=> OLD.source_start_date)
     OR NOT (NEW.source_end_date <=> OLD.source_end_date) OR NOT (NEW.course_hours <=> OLD.course_hours)
     OR NOT (NEW.teaching_days <=> OLD.teaching_days) OR NOT (NEW.algorithm_version <=> OLD.algorithm_version)
     OR NOT (NEW.approved_plan_hash <=> OLD.approved_plan_hash) OR NOT (NEW.source_fingerprint <=> OLD.source_fingerprint)
     OR NOT (NEW.converted_by <=> OLD.converted_by) OR NOT (NEW.converted_at <=> OLD.converted_at) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Të dhënat e një konvertimi nuk ndryshojnë.';
  END IF;
  IF NEW.status = 'completed' AND NOT EXISTS (
       SELECT 1 FROM course_groups g JOIN group_schedules s ON s.group_id = g.id
       WHERE g.id = NEW.group_id AND g.model = 'scheduled' AND s.schedule_mode = 'fixed_range') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Konvertimi nuk mbyllet pa orarin historik të grupit.';
  END IF;
END $$

-- ---------------------------------------------------------------------
-- 7. Historiku (e njëjta arkitekturë: audit_capture + fushat)
-- ---------------------------------------------------------------------

/* ====================== group_schedules (me llojin e orarit) ====================== */
DROP TRIGGER IF EXISTS trg_audit_gs_ai $$
CREATE TRIGGER trg_audit_gs_ai AFTER INSERT ON group_schedules
FOR EACH ROW
BEGIN
  CALL audit_capture('group_schedules','INSERT', JSON_OBJECT('group_id', NEW.group_id), NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'schedule_mode', NEW.schedule_mode, 'daily_hours', NEW.daily_hours,
                'course_hours', NEW.course_hours, 'curriculum_taken_at', NEW.curriculum_taken_at, 'teaching_days', NEW.teaching_days));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'schedule_mode',NULL,NEW.schedule_mode),(@e,'daily_hours',NULL,NEW.daily_hours),
    (@e,'course_hours',NULL,NEW.course_hours),(@e,'curriculum_taken_at',NULL,NEW.curriculum_taken_at),
    (@e,'teaching_days',NULL,NEW.teaching_days);
END $$

DROP TRIGGER IF EXISTS trg_audit_gs_au $$
CREATE TRIGGER trg_audit_gs_au AFTER UPDATE ON group_schedules
FOR EACH ROW
BEGIN
  IF NOT (OLD.daily_hours <=> NEW.daily_hours) OR NOT (OLD.course_hours <=> NEW.course_hours)
     OR NOT (OLD.curriculum_taken_at <=> NEW.curriculum_taken_at) OR NOT (OLD.teaching_days <=> NEW.teaching_days) THEN
    CALL audit_capture('group_schedules','UPDATE', JSON_OBJECT('group_id', NEW.group_id),
      JSON_OBJECT('group_id', OLD.group_id, 'schedule_mode', OLD.schedule_mode, 'daily_hours', OLD.daily_hours, 'course_hours', OLD.course_hours,
                  'curriculum_taken_at', OLD.curriculum_taken_at, 'teaching_days', OLD.teaching_days),
      JSON_OBJECT('group_id', NEW.group_id, 'schedule_mode', NEW.schedule_mode, 'daily_hours', NEW.daily_hours, 'course_hours', NEW.course_hours,
                  'curriculum_taken_at', NEW.curriculum_taken_at, 'teaching_days', NEW.teaching_days));
    SET @e := @last_audit_event_id;
    IF NOT (OLD.daily_hours <=> NEW.daily_hours) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'daily_hours',OLD.daily_hours,NEW.daily_hours); END IF;
    IF NOT (OLD.course_hours <=> NEW.course_hours) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'course_hours',OLD.course_hours,NEW.course_hours); END IF;
    IF NOT (OLD.curriculum_taken_at <=> NEW.curriculum_taken_at) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'curriculum_taken_at',OLD.curriculum_taken_at,NEW.curriculum_taken_at); END IF;
    IF NOT (OLD.teaching_days <=> NEW.teaching_days) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'teaching_days',OLD.teaching_days,NEW.teaching_days); END IF;
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_gs_ad $$
CREATE TRIGGER trg_audit_gs_ad AFTER DELETE ON group_schedules
FOR EACH ROW
BEGIN
  CALL audit_capture('group_schedules','DELETE', JSON_OBJECT('group_id', OLD.group_id),
    JSON_OBJECT('group_id', OLD.group_id, 'schedule_mode', OLD.schedule_mode, 'daily_hours', OLD.daily_hours,
                'course_hours', OLD.course_hours, 'curriculum_taken_at', OLD.curriculum_taken_at, 'teaching_days', OLD.teaching_days), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'schedule_mode',OLD.schedule_mode,NULL),(@e,'daily_hours',OLD.daily_hours,NULL),
    (@e,'course_hours',OLD.course_hours,NULL),(@e,'teaching_days',OLD.teaching_days,NULL);
END $$

/* ====================== course_groups: edhe ndryshimi i llojit shënohet ====================== */
DROP TRIGGER IF EXISTS trg_audit_cg_au $$
CREATE TRIGGER trg_audit_cg_au AFTER UPDATE ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture('course_groups','UPDATE', JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'course_id', OLD.course_id, 'start_date', OLD.start_date, 'end_date', OLD.end_date,
                'is_completed', OLD.is_completed, 'model', OLD.model, 'exam_date', OLD.exam_date, 'created_at', OLD.created_at),
    JSON_OBJECT('id', NEW.id, 'course_id', NEW.course_id, 'start_date', NEW.start_date, 'end_date', NEW.end_date,
                'is_completed', NEW.is_completed, 'model', NEW.model, 'exam_date', NEW.exam_date, 'created_at', NEW.created_at));
  SET @e := @last_audit_event_id;
  IF NOT (OLD.course_id    <=> NEW.course_id)    THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'course_id',    OLD.course_id,    NEW.course_id);    END IF;
  IF NOT (OLD.start_date   <=> NEW.start_date)   THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'start_date',   OLD.start_date,   NEW.start_date);   END IF;
  IF NOT (OLD.end_date     <=> NEW.end_date)     THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'end_date',     OLD.end_date,     NEW.end_date);     END IF;
  IF NOT (OLD.is_completed <=> NEW.is_completed) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'is_completed', OLD.is_completed, NEW.is_completed); END IF;
  IF NOT (OLD.model        <=> NEW.model)        THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'model',        OLD.model,        NEW.model);        END IF;
  IF NOT (OLD.exam_date    <=> NEW.exam_date)    THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'exam_date',    OLD.exam_date,    NEW.exam_date);    END IF;
END $$

/* ====================== group_conversions ======================
   Një ngjarje për çdo konvertim. 'applying' → 'completed' ndodh brenda të
   njëjtit transaksion dhe nuk shënohet më vete. */
DROP TRIGGER IF EXISTS trg_audit_gc_ai $$
CREATE TRIGGER trg_audit_gc_ai AFTER INSERT ON group_conversions
FOR EACH ROW
BEGIN
  CALL audit_capture('group_conversions','INSERT', JSON_OBJECT('group_id', NEW.group_id), NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'source_start_date', NEW.source_start_date, 'source_end_date', NEW.source_end_date,
                'course_hours', NEW.course_hours, 'teaching_days', NEW.teaching_days, 'algorithm_version', NEW.algorithm_version,
                'approved_plan_hash', NEW.approved_plan_hash, 'converted_by', NEW.converted_by, 'converted_at', NEW.converted_at));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'source_start_date',NULL,NEW.source_start_date),
    (@e,'source_end_date',NULL,NEW.source_end_date),(@e,'course_hours',NULL,NEW.course_hours),
    (@e,'teaching_days',NULL,NEW.teaching_days),(@e,'algorithm_version',NULL,NEW.algorithm_version);
END $$

DROP TRIGGER IF EXISTS trg_audit_gc_ad $$
CREATE TRIGGER trg_audit_gc_ad AFTER DELETE ON group_conversions
FOR EACH ROW
BEGIN
  CALL audit_capture('group_conversions','DELETE', JSON_OBJECT('group_id', OLD.group_id),
    JSON_OBJECT('group_id', OLD.group_id, 'source_start_date', OLD.source_start_date, 'source_end_date', OLD.source_end_date,
                'course_hours', OLD.course_hours, 'teaching_days', OLD.teaching_days, 'algorithm_version', OLD.algorithm_version,
                'approved_plan_hash', OLD.approved_plan_hash, 'converted_by', OLD.converted_by, 'converted_at', OLD.converted_at), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'source_start_date',OLD.source_start_date,NULL),
    (@e,'source_end_date',OLD.source_end_date,NULL),(@e,'course_hours',OLD.course_hours,NULL);
END $$

/* ====================== group_fixed_days ======================
   Rreshtat e plotë të planit shkruhen nga konvertimi (gjurma e planit të
   miratuar ruhet te group_conversions); këtu shënohet çdo korrigjim i
   mëvonshëm, datë për datë. */
DROP TRIGGER IF EXISTS trg_audit_gfd_au $$
CREATE TRIGGER trg_audit_gfd_au AFTER UPDATE ON group_fixed_days
FOR EACH ROW
BEGIN
  IF NOT (OLD.hours <=> NEW.hours) OR NOT (OLD.note <=> NEW.note) THEN
    CALL audit_capture('group_fixed_days','UPDATE', JSON_OBJECT('group_id', NEW.group_id, 'lesson_date', NEW.lesson_date),
      JSON_OBJECT('group_id', OLD.group_id, 'lesson_date', OLD.lesson_date, 'hours', OLD.hours, 'note', OLD.note),
      JSON_OBJECT('group_id', NEW.group_id, 'lesson_date', NEW.lesson_date, 'hours', NEW.hours, 'note', NEW.note));
    SET @e := @last_audit_event_id;
    IF NOT (OLD.hours <=> NEW.hours) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'hours',OLD.hours,NEW.hours); END IF;
    IF NOT (OLD.note <=> NEW.note) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'note',OLD.note,NEW.note); END IF;
  END IF;
END $$

DELIMITER ;

DROP PROCEDURE IF EXISTS qta_migrate_20260928_check_replace;

-- Fund i migrimit.
