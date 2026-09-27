-- =====================================================================
-- Migrimi 2026-09-26 — Kurset → Modulet → Temat + Grupet me orar mësimi
-- =====================================================================
--
-- Çfarë bën:
--   1. Tabela të reja për strukturën e kursit: course_modules, course_topics.
--   2. course_groups.model — dallon grupet e mëparshme ('legacy') nga grupet
--      me orar mësimi ('scheduled'). Çdo grup ekzistues merr 'legacy' nga
--      vlera e paracaktuar dhe mbetet i tillë përgjithmonë (trigger).
--   3. Tabela të reja për orarin e grupeve me orar: group_schedules (1:1),
--      group_schedule_topics (kopja e ngrirë e temave), group_schedule_days,
--      group_schedule_slots (ndarja e orëve të temave nëpër ditë) dhe
--      group_day_rules (ditët e veçanta: orë të tjera, pa mësim, e diel me mësim).
--   4. fk_cg_course: CASCADE → RESTRICT. Një kurs me grupe nuk fshihet nga baza
--      (aplikacioni e ndalonte tashmë); kështu edhe një fshirje e gabuar jashtë
--      aplikacionit nuk mund të fshijë grupe, provime dhe pikë.
--   5. Historiku (audit_events): trigger-a për tabelat e reja dhe kolona 'model'
--      te trigger-at e course_groups.
--
-- Çfarë NUK bën:
--   - nuk fshin, nuk rishkruan dhe nuk zhvendos asnjë rresht ekzistues;
--   - nuk krijon orar për grupet ekzistuese;
--   - nuk riemërton tabela (tabela 'courses' mbetet; në ndërfaqe quhet "Kurset").
--
-- Mund të ekzekutohet disa herë (çdo hap kontrollon nëse është bërë).
-- MariaDB 10.4+ / MySQL 8.0.16+ (CHECK, JSON_OBJECT, trigger-a të shumëfishtë).
-- Ekzekutimi: mysql qta_db < db/migrations/2026-09-26-kurset-modulet-temat-orari.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Struktura e kursit
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_modules (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  course_id   INT NOT NULL,
  position    SMALLINT UNSIGNED NOT NULL,          -- radha brenda kursit: 1, 2, 3 …
  title       VARCHAR(200) NOT NULL,
  hours       SMALLINT UNSIGNED NOT NULL,          -- orë mësimore të plota
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cm_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_cm_position CHECK (position >= 1),
  CONSTRAINT chk_cm_hours CHECK (hours >= 1),
  KEY idx_cm_course_position (course_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS course_topics (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  module_id   INT NOT NULL,
  position    SMALLINT UNSIGNED NOT NULL,          -- radha brenda modulit: 1, 2, 3 …
  title       VARCHAR(255) NOT NULL,
  hours       SMALLINT UNSIGNED NOT NULL,          -- orë mësimore të plota
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ct_module FOREIGN KEY (module_id) REFERENCES course_modules(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_ct_position CHECK (position >= 1),
  CONSTRAINT chk_ct_hours CHECK (hours >= 1),
  KEY idx_ct_module_position (module_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2 + 4. course_groups: kolona 'model' dhe FK pa fshirje zinxhir
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_20260926 $$
CREATE PROCEDURE qta_migrate_20260926()
BEGIN
  DECLARE v_fk VARCHAR(64) DEFAULT NULL;
  DECLARE v_rule VARCHAR(16) DEFAULT NULL;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_groups' AND COLUMN_NAME = 'model'
  ) THEN
    ALTER TABLE course_groups
      ADD COLUMN model ENUM('legacy','scheduled') NOT NULL DEFAULT 'legacy' AFTER is_completed;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_groups' AND INDEX_NAME = 'idx_cg_model_start'
  ) THEN
    ALTER TABLE course_groups ADD INDEX idx_cg_model_start (model, start_date);
  END IF;

  /* FK course_groups.course_id → courses.id: gjej emrin (mund të ndryshojë
     nga instalimi në instalim) dhe rregullin e fshirjes. */
  SELECT k.CONSTRAINT_NAME, r.DELETE_RULE INTO v_fk, v_rule
  FROM information_schema.KEY_COLUMN_USAGE k
  JOIN information_schema.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
  WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'course_groups'
    AND k.COLUMN_NAME = 'course_id' AND k.REFERENCED_TABLE_NAME = 'courses'
  LIMIT 1;

  IF v_fk IS NOT NULL AND v_rule = 'CASCADE' THEN
    SET @qta_sql = CONCAT('ALTER TABLE course_groups DROP FOREIGN KEY `', v_fk, '`');
    PREPARE qta_stmt FROM @qta_sql; EXECUTE qta_stmt; DEALLOCATE PREPARE qta_stmt;
    ALTER TABLE course_groups
      ADD CONSTRAINT fk_cg_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  ELSEIF v_fk IS NULL THEN
    ALTER TABLE course_groups
      ADD CONSTRAINT fk_cg_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
  END IF;
END $$
DELIMITER ;

CALL qta_migrate_20260926();
DROP PROCEDURE IF EXISTS qta_migrate_20260926;

-- Lloji i grupit nuk ndryshon kurrë: një grup i mëparshëm mbetet pa orar dhe një
-- grup me orar nuk kthehet në grup pa orar. Grupi me orar nuk kalon te një kurs
-- tjetër, sepse orari i tij është ndërtuar nga temat e kursit të vet.
DELIMITER $$
DROP TRIGGER IF EXISTS trg_cg_model_guard_bu $$
CREATE TRIGGER trg_cg_model_guard_bu
BEFORE UPDATE ON course_groups
FOR EACH ROW
BEGIN
  IF NOT (NEW.model <=> OLD.model) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Lloji i grupit (pa orar ose me orar mësimi) nuk mund të ndryshohet.';
  END IF;
  IF OLD.model = 'scheduled' AND NOT (NEW.course_id <=> OLD.course_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Një grup me orar mësimi nuk mund të kalojë te një kurs tjetër.';
  END IF;
END $$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 3. Orari i mësimit për grupet me orar
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS group_schedules (
  group_id             INT NOT NULL PRIMARY KEY,
  daily_hours          TINYINT UNSIGNED NOT NULL,          -- orë mësimi në një ditë të zakonshme
  course_hours         SMALLINT UNSIGNED NOT NULL,         -- orët e kursit në kopjen e grupit
  curriculum_taken_at  DATETIME NOT NULL,                  -- kur u kopjuan modulet dhe temat
  teaching_days        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  revision             INT UNSIGNED NOT NULL DEFAULT 0,    -- rritet me çdo rillogaritje
  generated_at         DATETIME NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gs_group FOREIGN KEY (group_id) REFERENCES course_groups(id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT chk_gs_daily CHECK (daily_hours BETWEEN 1 AND 12),
  CONSTRAINT chk_gs_course_hours CHECK (course_hours >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Kopja e ngrirë e moduleve dhe temave të kursit, në radhë, për çdo grup.
-- Ndryshimet e mëvonshme të kursit nuk e prekin.
CREATE TABLE IF NOT EXISTS group_schedule_topics (
  group_id          INT NOT NULL,
  seq               SMALLINT UNSIGNED NOT NULL,   -- radha e temës në gjithë kursin: 1 … n
  module_seq        SMALLINT UNSIGNED NOT NULL,   -- radha e modulit: 1 … m
  module_title      VARCHAR(200) NOT NULL,
  module_hours      SMALLINT UNSIGNED NOT NULL,
  topic_seq         SMALLINT UNSIGNED NOT NULL,   -- radha e temës brenda modulit: 1 … k
  topic_title       VARCHAR(255) NOT NULL,
  topic_hours       SMALLINT UNSIGNED NOT NULL,
  source_module_id  INT NULL,                     -- vetëm për gjurmim; pa FK, që kopja të mbijetojë
  source_topic_id   INT NULL,
  PRIMARY KEY (group_id, seq),
  UNIQUE KEY uq_gst_module_topic (group_id, module_seq, topic_seq),
  CONSTRAINT fk_gst_schedule FOREIGN KEY (group_id) REFERENCES group_schedules(group_id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT chk_gst_seq CHECK (seq >= 1 AND module_seq >= 1 AND topic_seq >= 1),
  CONSTRAINT chk_gst_hours CHECK (topic_hours >= 1 AND module_hours >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS group_schedule_days (
  group_id     INT NOT NULL,
  day_seq      SMALLINT UNSIGNED NOT NULL,        -- dita e mësimit: 1 … d
  lesson_date  DATE NOT NULL,
  hours        TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, day_seq),
  UNIQUE KEY uq_gsd_date (group_id, lesson_date),
  KEY idx_gsd_lesson_date (lesson_date),
  CONSTRAINT fk_gsd_schedule FOREIGN KEY (group_id) REFERENCES group_schedules(group_id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT chk_gsd_seq CHECK (day_seq >= 1),
  CONSTRAINT chk_gsd_hours CHECK (hours BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Një temë mund të ndahet në disa ditë; një ditë mund të ketë disa tema
-- (edhe nga dy module, kur moduli mbaron në mes të ditës).
CREATE TABLE IF NOT EXISTS group_schedule_slots (
  group_id   INT NOT NULL,
  day_seq    SMALLINT UNSIGNED NOT NULL,
  slot_seq   TINYINT UNSIGNED NOT NULL,           -- radha brenda ditës: 1 … s
  topic_seq  SMALLINT UNSIGNED NOT NULL,          -- → group_schedule_topics.seq
  hours      TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, day_seq, slot_seq),
  KEY idx_gss_topic (group_id, topic_seq),
  CONSTRAINT fk_gss_day FOREIGN KEY (group_id, day_seq) REFERENCES group_schedule_days(group_id, day_seq) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_gss_topic FOREIGN KEY (group_id, topic_seq) REFERENCES group_schedule_topics(group_id, seq) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT chk_gss_seq CHECK (slot_seq >= 1),
  CONSTRAINT chk_gss_hours CHECK (hours BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ditët e veçanta të një grupi. hours = NULL → orari i zakonshëm (p.sh. një e
-- diel që bëhet ditë mësimi); 0 → pa mësim; 1–12 → pikërisht aq orë.
CREATE TABLE IF NOT EXISTS group_day_rules (
  group_id    INT NOT NULL,
  rule_date   DATE NOT NULL,
  hours       TINYINT UNSIGNED NULL,
  note        VARCHAR(160) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, rule_date),
  CONSTRAINT fk_gdr_schedule FOREIGN KEY (group_id) REFERENCES group_schedules(group_id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT chk_gdr_hours CHECK (hours IS NULL OR hours BETWEEN 0 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Orari lidhet vetëm me grupe me orar. Kjo e bën të pamundur (edhe jashtë
-- aplikacionit) t'i ngjitet një orar një grupi të mëparshëm.
DELIMITER $$
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
END $$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 5. Historiku i ndryshimeve (e njëjta arkitekturë: audit_capture + fushat)
-- ---------------------------------------------------------------------
DELIMITER $$

/* ====================== course_modules ====================== */
DROP TRIGGER IF EXISTS trg_audit_cm_ai $$
CREATE TRIGGER trg_audit_cm_ai AFTER INSERT ON course_modules
FOR EACH ROW
BEGIN
  CALL audit_capture('course_modules','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'course_id', NEW.course_id, 'position', NEW.position, 'title', NEW.title, 'hours', NEW.hours));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'course_id',NULL,NEW.course_id),(@e,'position',NULL,NEW.position),
    (@e,'title',NULL,NEW.title),(@e,'hours',NULL,NEW.hours);
END $$

DROP TRIGGER IF EXISTS trg_audit_cm_au $$
CREATE TRIGGER trg_audit_cm_au AFTER UPDATE ON course_modules
FOR EACH ROW
BEGIN
  IF NOT (OLD.course_id <=> NEW.course_id) OR NOT (OLD.position <=> NEW.position)
     OR NOT (OLD.title <=> NEW.title) OR NOT (OLD.hours <=> NEW.hours) THEN
    CALL audit_capture('course_modules','UPDATE', JSON_OBJECT('id', NEW.id),
      JSON_OBJECT('id', OLD.id, 'course_id', OLD.course_id, 'position', OLD.position, 'title', OLD.title, 'hours', OLD.hours),
      JSON_OBJECT('id', NEW.id, 'course_id', NEW.course_id, 'position', NEW.position, 'title', NEW.title, 'hours', NEW.hours));
    SET @e := @last_audit_event_id;
    IF NOT (OLD.course_id <=> NEW.course_id) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'course_id',OLD.course_id,NEW.course_id); END IF;
    IF NOT (OLD.position  <=> NEW.position)  THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'position',OLD.position,NEW.position); END IF;
    IF NOT (OLD.title     <=> NEW.title)     THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'title',OLD.title,NEW.title); END IF;
    IF NOT (OLD.hours     <=> NEW.hours)     THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'hours',OLD.hours,NEW.hours); END IF;
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_cm_ad $$
CREATE TRIGGER trg_audit_cm_ad AFTER DELETE ON course_modules
FOR EACH ROW
BEGIN
  CALL audit_capture('course_modules','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'course_id', OLD.course_id, 'position', OLD.position, 'title', OLD.title, 'hours', OLD.hours), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'course_id',OLD.course_id,NULL),(@e,'position',OLD.position,NULL),
    (@e,'title',OLD.title,NULL),(@e,'hours',OLD.hours,NULL);
END $$

/* ====================== course_topics ====================== */
DROP TRIGGER IF EXISTS trg_audit_ct_ai $$
CREATE TRIGGER trg_audit_ct_ai AFTER INSERT ON course_topics
FOR EACH ROW
BEGIN
  CALL audit_capture('course_topics','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'module_id', NEW.module_id, 'position', NEW.position, 'title', NEW.title, 'hours', NEW.hours));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'module_id',NULL,NEW.module_id),(@e,'position',NULL,NEW.position),
    (@e,'title',NULL,NEW.title),(@e,'hours',NULL,NEW.hours);
END $$

DROP TRIGGER IF EXISTS trg_audit_ct_au $$
CREATE TRIGGER trg_audit_ct_au AFTER UPDATE ON course_topics
FOR EACH ROW
BEGIN
  IF NOT (OLD.module_id <=> NEW.module_id) OR NOT (OLD.position <=> NEW.position)
     OR NOT (OLD.title <=> NEW.title) OR NOT (OLD.hours <=> NEW.hours) THEN
    CALL audit_capture('course_topics','UPDATE', JSON_OBJECT('id', NEW.id),
      JSON_OBJECT('id', OLD.id, 'module_id', OLD.module_id, 'position', OLD.position, 'title', OLD.title, 'hours', OLD.hours),
      JSON_OBJECT('id', NEW.id, 'module_id', NEW.module_id, 'position', NEW.position, 'title', NEW.title, 'hours', NEW.hours));
    SET @e := @last_audit_event_id;
    IF NOT (OLD.module_id <=> NEW.module_id) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'module_id',OLD.module_id,NEW.module_id); END IF;
    IF NOT (OLD.position  <=> NEW.position)  THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'position',OLD.position,NEW.position); END IF;
    IF NOT (OLD.title     <=> NEW.title)     THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'title',OLD.title,NEW.title); END IF;
    IF NOT (OLD.hours     <=> NEW.hours)     THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'hours',OLD.hours,NEW.hours); END IF;
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_ct_ad $$
CREATE TRIGGER trg_audit_ct_ad AFTER DELETE ON course_topics
FOR EACH ROW
BEGIN
  CALL audit_capture('course_topics','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'module_id', OLD.module_id, 'position', OLD.position, 'title', OLD.title, 'hours', OLD.hours), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'module_id',OLD.module_id,NULL),(@e,'position',OLD.position,NULL),
    (@e,'title',OLD.title,NULL),(@e,'hours',OLD.hours,NULL);
END $$

/* ====================== group_schedules ======================
   Rreshtat e ditëve dhe të ndarjes së temave llogariten nga ky konfigurim dhe
   nga kopja e temave; nuk shënohen një nga një. Historiku ruan konfigurimin
   (orë në ditë), kopjen e temave dhe sa ditë mësimi dolën; datat e fillimit
   dhe të mbarimit shënohen nga trigger-at e course_groups. */
DROP TRIGGER IF EXISTS trg_audit_gs_ai $$
CREATE TRIGGER trg_audit_gs_ai AFTER INSERT ON group_schedules
FOR EACH ROW
BEGIN
  CALL audit_capture('group_schedules','INSERT', JSON_OBJECT('group_id', NEW.group_id), NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'daily_hours', NEW.daily_hours, 'course_hours', NEW.course_hours,
                'curriculum_taken_at', NEW.curriculum_taken_at, 'teaching_days', NEW.teaching_days));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'daily_hours',NULL,NEW.daily_hours),
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
      JSON_OBJECT('group_id', OLD.group_id, 'daily_hours', OLD.daily_hours, 'course_hours', OLD.course_hours,
                  'curriculum_taken_at', OLD.curriculum_taken_at, 'teaching_days', OLD.teaching_days),
      JSON_OBJECT('group_id', NEW.group_id, 'daily_hours', NEW.daily_hours, 'course_hours', NEW.course_hours,
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
    JSON_OBJECT('group_id', OLD.group_id, 'daily_hours', OLD.daily_hours, 'course_hours', OLD.course_hours,
                'curriculum_taken_at', OLD.curriculum_taken_at, 'teaching_days', OLD.teaching_days), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'daily_hours',OLD.daily_hours,NULL),
    (@e,'course_hours',OLD.course_hours,NULL),(@e,'teaching_days',OLD.teaching_days,NULL);
END $$

/* ====================== group_day_rules ====================== */
DROP TRIGGER IF EXISTS trg_audit_gdr_ai $$
CREATE TRIGGER trg_audit_gdr_ai AFTER INSERT ON group_day_rules
FOR EACH ROW
BEGIN
  CALL audit_capture('group_day_rules','INSERT', JSON_OBJECT('group_id', NEW.group_id, 'rule_date', NEW.rule_date), NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'rule_date', NEW.rule_date, 'hours', NEW.hours, 'note', NEW.note));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'rule_date',NULL,NEW.rule_date),
    (@e,'hours',NULL,IFNULL(NEW.hours,'default')),(@e,'note',NULL,NEW.note);
END $$

DROP TRIGGER IF EXISTS trg_audit_gdr_au $$
CREATE TRIGGER trg_audit_gdr_au AFTER UPDATE ON group_day_rules
FOR EACH ROW
BEGIN
  IF NOT (OLD.hours <=> NEW.hours) OR NOT (OLD.note <=> NEW.note) OR NOT (OLD.rule_date <=> NEW.rule_date) THEN
    CALL audit_capture('group_day_rules','UPDATE', JSON_OBJECT('group_id', NEW.group_id, 'rule_date', NEW.rule_date),
      JSON_OBJECT('group_id', OLD.group_id, 'rule_date', OLD.rule_date, 'hours', OLD.hours, 'note', OLD.note),
      JSON_OBJECT('group_id', NEW.group_id, 'rule_date', NEW.rule_date, 'hours', NEW.hours, 'note', NEW.note));
    SET @e := @last_audit_event_id;
    IF NOT (OLD.rule_date <=> NEW.rule_date) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'rule_date',OLD.rule_date,NEW.rule_date); END IF;
    IF NOT (OLD.hours <=> NEW.hours) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'hours',IFNULL(OLD.hours,'default'),IFNULL(NEW.hours,'default')); END IF;
    IF NOT (OLD.note <=> NEW.note) THEN INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'note',OLD.note,NEW.note); END IF;
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_gdr_ad $$
CREATE TRIGGER trg_audit_gdr_ad AFTER DELETE ON group_day_rules
FOR EACH ROW
BEGIN
  CALL audit_capture('group_day_rules','DELETE', JSON_OBJECT('group_id', OLD.group_id, 'rule_date', OLD.rule_date),
    JSON_OBJECT('group_id', OLD.group_id, 'rule_date', OLD.rule_date, 'hours', OLD.hours, 'note', OLD.note), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'rule_date',OLD.rule_date,NULL),
    (@e,'hours',IFNULL(OLD.hours,'default'),NULL),(@e,'note',OLD.note,NULL);
END $$

/* ====================== course_groups (me kolonën 'model') ====================== */
DROP TRIGGER IF EXISTS trg_audit_cg_ai $$
CREATE TRIGGER trg_audit_cg_ai AFTER INSERT ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture('course_groups','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'course_id', NEW.course_id, 'start_date', NEW.start_date, 'end_date', NEW.end_date,
                'is_completed', NEW.is_completed, 'model', NEW.model, 'exam_date', NEW.exam_date, 'created_at', NEW.created_at));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'course_id',NULL,NEW.course_id),(@e,'start_date',NULL,NEW.start_date),
    (@e,'end_date',NULL,NEW.end_date),(@e,'is_completed',NULL,NEW.is_completed),(@e,'model',NULL,NEW.model),
    (@e,'exam_date',NULL,NEW.exam_date),(@e,'created_at',NULL,NEW.created_at);
END $$

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
  IF NOT (OLD.exam_date    <=> NEW.exam_date)    THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'exam_date',    OLD.exam_date,    NEW.exam_date);    END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_cg_ad $$
CREATE TRIGGER trg_audit_cg_ad AFTER DELETE ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture('course_groups','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'course_id', OLD.course_id, 'start_date', OLD.start_date, 'end_date', OLD.end_date,
                'is_completed', OLD.is_completed, 'model', OLD.model, 'exam_date', OLD.exam_date, 'created_at', OLD.created_at),
    NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'course_id',OLD.course_id,NULL),(@e,'start_date',OLD.start_date,NULL),
    (@e,'end_date',OLD.end_date,NULL),(@e,'is_completed',OLD.is_completed,NULL),(@e,'model',OLD.model,NULL),
    (@e,'exam_date',OLD.exam_date,NULL),(@e,'created_at',OLD.created_at,NULL);
END $$

DELIMITER ;

-- Fund i migrimit.
