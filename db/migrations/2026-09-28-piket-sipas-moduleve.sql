-- =====================================================================
-- Migrimi 2026-09-28 (2) — Pikët sipas moduleve
-- =====================================================================
--
-- Ekzekutohet PAS 2026-09-26-kurset-modulet-temat-orari.sql dhe
-- 2026-09-28-konvertimi-i-grupeve.sql.
--
-- Çfarë bën:
--   0. Kontrollon para çdo ndryshimi që dy migrimet e mëparshme janë ekzekutuar;
--      përndryshe ndalet PA NDRYSHUAR ASGJË.
--   1. enrollment_module_scores: pikët e një kursanti në grup për çdo modul
--      (0–100, dy shifra pas presjes). Një rresht për (grup, kursant, modul);
--      mungesa e rreshtit = pa pikë (bosh nuk është 0). Kur kursanti hiqet nga
--      grupi, pikët e tij fshihen bashkë me të (edhe në historik).
--      Modulet e një grupi: grupi me orar (edhe i konvertuar) ndjek kopjen e tij
--      të moduleve; grupi i mëparshëm (pa kopje) ndjek modulet e kursit tani.
--   2. course_group_students.legacy_final_score: kur një kursant që kishte pikë
--      të shkruara me dorë merr pikët e para të moduleve, pikët e vjetra ruhen
--      këtu, të pandryshueshme. Migrimi nuk kopjon asgjë: kolona mbushet vetëm
--      atëherë (app/shared/results.php).
--   3. course_group_students.final_score mbetet rezultati zyrtar që lexojnë
--      listat, kartela, eksportet dhe procesverbali. Nga tani e shkruan vetëm
--      llogaritja nga pikët e moduleve; baza refuzon çdo shkrim tjetër.
--   4. Rregullat e bazës (vlejnë për çdo klient, jo vetëm për aplikacionin):
--        - pikët e një moduli ruhen vetëm për një kursant të grupit, me datë
--          provimi, dhe vetëm për një modul të grupit;
--        - data e provimit nuk hiqet kur kursanti ka pikë moduli;
--        - pikët e vjetra, pasi ruhen, nuk ndryshojnë;
--        - kursi i një grupi me pikë moduli nuk ndryshon;
--        - një modul i kursit me pikë te grupe të mëparshme nuk fshihet dhe nuk
--          kalon te një kurs tjetër.
--   5. Historiku: çdo pikë moduli (shtim, ndryshim, fshirje).
--
-- Çfarë NUK bën:
--   - nuk ndryshon, nuk fshin dhe nuk zhvendos asnjë rresht ekzistues; çdo
--     rezultat i shkruar deri tani mbetet ashtu siç është;
--   - nuk shpërndan pikët e vjetra nëpër module (nuk dihet sa mori kursanti në
--     secilin modul);
--   - nuk ndryshon trigger-at ekzistues.
--
-- Mund të ekzekutohet disa herë: çdo hap kontrollon nëse është bërë; trigger-at
-- rikrijohen njësoj. MariaDB 10.4+ / MySQL 8.0.19+.
-- Ekzekutimi: mysql qta_db < db/migrations/2026-09-28-piket-sipas-moduleve.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 0. Kontrolli para çdo ndryshimi
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_piket_check $$
CREATE PROCEDURE qta_migrate_piket_check()
BEGIN
  IF NOT EXISTS (
       SELECT 1 FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_modules')
     OR NOT EXISTS (
       SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_schedule_topics' AND COLUMN_NAME = 'source_module_id')
     OR NOT EXISTS (
       SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_schedules' AND COLUMN_NAME = 'schedule_mode') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migrimi i pikëve sipas moduleve u ndal: ekzekuto së pari 2026-09-26-kurset-modulet-temat-orari.sql dhe 2026-09-28-konvertimi-i-grupeve.sql. Asgjë nuk ndryshoi.';
  END IF;
END $$
DELIMITER ;

CALL qta_migrate_piket_check();
DROP PROCEDURE IF EXISTS qta_migrate_piket_check;

-- ---------------------------------------------------------------------
-- 1. Pikët e vjetra të zëvendësuara (kolona, bosh)
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_piket $$
CREATE PROCEDURE qta_migrate_piket()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_group_students' AND COLUMN_NAME = 'legacy_final_score'
  ) THEN
    ALTER TABLE course_group_students ADD COLUMN legacy_final_score DECIMAL(5,2) NULL AFTER final_score;
  END IF;
END $$
DELIMITER ;

CALL qta_migrate_piket();
DROP PROCEDURE IF EXISTS qta_migrate_piket;

-- ---------------------------------------------------------------------
-- 2. Pikët sipas moduleve
-- ---------------------------------------------------------------------
-- module_id = id e modulit te course_modules. Për grupet me orar është
-- source_module_id i kopjes së grupit (group_schedule_topics): si ajo, pa FK, që
-- pikët të mbeten kur kursi ndryshon ose moduli fshihet nga katalogu pas krijimit
-- të grupit. Rregulli "moduli i përket grupit" kontrollohet nga trg_ems_bi; për
-- grupet e mëparshme, fshirja e modulit me pikë ndalohet nga trg_cm_results_bd.
CREATE TABLE IF NOT EXISTS enrollment_module_scores (
  group_id    INT NOT NULL,
  student_id  INT NOT NULL,
  module_id   INT NOT NULL,
  score       DECIMAL(5,2) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, student_id, module_id),
  KEY idx_ems_module (module_id),
  CONSTRAINT fk_ems_enrollment FOREIGN KEY (group_id, student_id)
    REFERENCES course_group_students (group_id, student_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_ems_score CHECK (score >= 0 AND score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 3. Rregullat e bazës
-- ---------------------------------------------------------------------
DELIMITER $$

/* Pikët e një moduli: vetëm për një kursant të grupit, me datë provimi, për një
   modul të grupit (kopja për grupin me orar, kursi për grupin e mëparshëm). */
DROP TRIGGER IF EXISTS trg_ems_bi $$
CREATE TRIGGER trg_ems_bi BEFORE INSERT ON enrollment_module_scores
FOR EACH ROW
BEGIN
  DECLARE v_model VARCHAR(16) DEFAULT NULL;
  DECLARE v_course INT DEFAULT NULL;
  DECLARE v_member INT DEFAULT 0;
  DECLARE v_exam DATE DEFAULT NULL;
  SELECT MAX(model), MAX(course_id) INTO v_model, v_course FROM course_groups WHERE id = NEW.group_id;
  SELECT COUNT(*), MAX(exam_date) INTO v_member, v_exam
  FROM course_group_students WHERE group_id = NEW.group_id AND student_id = NEW.student_id;
  IF v_member = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Pikët e një moduli ruhen vetëm për një kursant të grupit.';
  END IF;
  IF v_exam IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Pikët kërkojnë datën e provimit të kursantit. Cakto së pari datën e provimit.';
  END IF;
  IF v_model = 'scheduled' THEN
    IF NOT EXISTS (SELECT 1 FROM group_schedule_topics t WHERE t.group_id = NEW.group_id AND t.source_module_id = NEW.module_id) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Ky modul nuk është te modulet e grupit (kopja e kursit që ndjek grupi).';
    END IF;
  ELSEIF NOT EXISTS (SELECT 1 FROM course_modules m WHERE m.id = NEW.module_id AND m.course_id = v_course) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Ky modul nuk i përket kursit të grupit.';
  END IF;
END $$

/* Ndryshohen vetëm pikët; kursanti, grupi dhe moduli i një rreshti nuk lëvizin.
   (Kaskada e çelësit — kur një kursant kalon te grupi i ri pas ndarjes së një
   grupi të mëparshëm — nuk kalon nga trigger-at.) */
DROP TRIGGER IF EXISTS trg_ems_bu $$
CREATE TRIGGER trg_ems_bu BEFORE UPDATE ON enrollment_module_scores
FOR EACH ROW
BEGIN
  DECLARE v_exam DATE DEFAULT NULL;
  IF NOT (NEW.group_id <=> OLD.group_id) OR NOT (NEW.student_id <=> OLD.student_id) OR NOT (NEW.module_id <=> OLD.module_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Pikët e një moduli nuk kalojnë te një kursant, grup ose modul tjetër; ndryshohen vetëm pikët.';
  END IF;
  SELECT MAX(exam_date) INTO v_exam FROM course_group_students WHERE group_id = NEW.group_id AND student_id = NEW.student_id;
  IF v_exam IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Pikët kërkojnë datën e provimit të kursantit. Cakto së pari datën e provimit.';
  END IF;
END $$

/* Rezultati zyrtar shkruhet vetëm nga llogaritja (results.php, me @qta_results_sync = 1);
   pikët e vjetra, pasi ruhen, nuk ndryshojnë; data e provimit nuk hiqet kur kursanti ka
   pikë moduli. */
DROP TRIGGER IF EXISTS trg_cgs_results_bu $$
CREATE TRIGGER trg_cgs_results_bu BEFORE UPDATE ON course_group_students
FOR EACH ROW
BEGIN
  IF NOT (NEW.final_score <=> OLD.final_score) AND COALESCE(@qta_results_sync, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Rezultati përfundimtar llogaritet nga pikët e moduleve dhe nuk shkruhet me dorë. Vendos pikët te "Vendos pikët".';
  END IF;
  IF NOT (NEW.legacy_final_score <=> OLD.legacy_final_score)
     AND (OLD.legacy_final_score IS NOT NULL OR COALESCE(@qta_results_sync, 0) <> 1) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Pikët e vjetra të një kursanti ruhen ashtu siç ishin dhe nuk ndryshojnë.';
  END IF;
  IF NEW.exam_date IS NULL AND OLD.exam_date IS NOT NULL
     AND EXISTS (SELECT 1 FROM enrollment_module_scores s WHERE s.group_id = OLD.group_id AND s.student_id = OLD.student_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Kursanti ka pikë moduli, prandaj data e provimit nuk hiqet: pikët kërkojnë datën e provimit.';
  END IF;
END $$

/* Kur kursanti hiqet nga grupi, pikët e moduleve fshihen shprehimisht (jo me
   kaskadë), që historiku të shënojë secilën. */
DROP TRIGGER IF EXISTS trg_cgs_results_bd $$
CREATE TRIGGER trg_cgs_results_bd BEFORE DELETE ON course_group_students
FOR EACH ROW
BEGIN
  DELETE FROM enrollment_module_scores WHERE group_id = OLD.group_id AND student_id = OLD.student_id;
END $$

/* Pikët i përkasin moduleve të kursit të grupit: kursi nuk ndryshon pa i hequr ato. */
DROP TRIGGER IF EXISTS trg_cg_results_course_bu $$
CREATE TRIGGER trg_cg_results_course_bu BEFORE UPDATE ON course_groups
FOR EACH ROW
BEGIN
  IF NOT (NEW.course_id <=> OLD.course_id)
     AND EXISTS (SELECT 1 FROM enrollment_module_scores s WHERE s.group_id = OLD.id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Grupi ka pikë sipas moduleve të kursit të tij, prandaj kursi i grupit nuk ndryshon. Nëse kursi është gabim, hiq së pari pikët e moduleve.';
  END IF;
END $$

/* Grupi i mëparshëm nuk ka kopje: pikët e tij lidhen me modulin e kursit. Moduli
   nuk fshihet dhe nuk kalon te një kurs tjetër sa kohë ka pikë të tilla. */
DROP TRIGGER IF EXISTS trg_cm_results_bd $$
CREATE TRIGGER trg_cm_results_bd BEFORE DELETE ON course_modules
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM enrollment_module_scores s JOIN course_groups g ON g.id = s.group_id
             WHERE s.module_id = OLD.id AND g.model = 'legacy') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Moduli ka pikë të ruajtura te grupe të regjistrit të vjetër dhe nuk fshihet, që pikët e kursantëve të mos humbasin.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_cm_results_bu $$
CREATE TRIGGER trg_cm_results_bu BEFORE UPDATE ON course_modules
FOR EACH ROW
BEGIN
  IF NOT (NEW.course_id <=> OLD.course_id)
     AND EXISTS (SELECT 1 FROM enrollment_module_scores s JOIN course_groups g ON g.id = s.group_id
                 WHERE s.module_id = OLD.id AND g.model = 'legacy') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Moduli ka pikë të ruajtura te grupe të regjistrit të vjetër dhe nuk kalon te një kurs tjetër.';
  END IF;
END $$

-- ---------------------------------------------------------------------
-- 4. Historiku i pikëve të moduleve
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_audit_ems_ai $$
CREATE TRIGGER trg_audit_ems_ai AFTER INSERT ON enrollment_module_scores
FOR EACH ROW
BEGIN
  CALL audit_capture('enrollment_module_scores','INSERT',
    JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id), NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id, 'score', NEW.score));
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'student_id',NULL,NEW.student_id),
    (@e,'module_id',NULL,NEW.module_id),(@e,'score',NULL,NEW.score);
END $$

DROP TRIGGER IF EXISTS trg_audit_ems_au $$
CREATE TRIGGER trg_audit_ems_au AFTER UPDATE ON enrollment_module_scores
FOR EACH ROW
BEGIN
  IF NOT (OLD.score <=> NEW.score) THEN
    CALL audit_capture('enrollment_module_scores','UPDATE',
      JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id),
      JSON_OBJECT('group_id', OLD.group_id, 'student_id', OLD.student_id, 'module_id', OLD.module_id, 'score', OLD.score),
      JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id, 'score', NEW.score));
    SET @e := @last_audit_event_id;
    INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'score',OLD.score,NEW.score);
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_ems_ad $$
CREATE TRIGGER trg_audit_ems_ad AFTER DELETE ON enrollment_module_scores
FOR EACH ROW
BEGIN
  CALL audit_capture('enrollment_module_scores','DELETE',
    JSON_OBJECT('group_id', OLD.group_id, 'student_id', OLD.student_id, 'module_id', OLD.module_id),
    JSON_OBJECT('group_id', OLD.group_id, 'student_id', OLD.student_id, 'module_id', OLD.module_id, 'score', OLD.score), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'student_id',OLD.student_id,NULL),
    (@e,'module_id',OLD.module_id,NULL),(@e,'score',OLD.score,NULL);
END $$

DELIMITER ;

-- Fund i migrimit.
