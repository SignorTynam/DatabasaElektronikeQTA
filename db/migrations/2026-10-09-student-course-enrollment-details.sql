-- Persistent enrollment. Run after 2026-10-08. No fabricated scores or changed IDs.
SET NAMES utf8mb4;
-- The completed migration's trigger is the marker for the one-time legacy exam
-- backfill. Re-running must not resurrect an individually cleared exam later.
SET @qta_enrollment_backfill = NOT EXISTS (
  SELECT 1 FROM information_schema.TRIGGERS
  WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_cgs_enrollment_bi'
);
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_enrollment $$
CREATE PROCEDURE qta_migrate_enrollment()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_cg_model_guard_bu' AND ACTION_STATEMENT LIKE '%@qta_course_change_group%')
     OR NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_module_scores') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ekzekuto së pari migrimet deri më 2026-10-08.';
  END IF;
  IF EXISTS (SELECT student_id FROM course_group_students GROUP BY student_id HAVING COUNT(*)>1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migrimi u ndal: një numër amze ka disa anëtarësi. Zgjidhi para migrimit.';
  END IF;
  IF EXISTS(SELECT group_id FROM course_group_students GROUP BY group_id HAVING COUNT(*)>10) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migrimi u ndal: grup me më shumë se 10 kursantë. Zgjidhe para migrimit.';
  END IF;
  IF EXISTS (SELECT 1 FROM course_group_students m JOIN course_groups g ON g.id=m.group_id
             WHERE m.exam_date IS NULL AND g.exam_date IS NOT NULL AND g.exam_date<g.end_date) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migrimi u ndal: provim historik para mbarimit të grupit.';
  END IF;
  IF EXISTS (
    SELECT 1 FROM enrollment_module_scores s JOIN course_groups g ON g.id=s.group_id
    LEFT JOIN group_schedule_topics t ON t.group_id=g.id AND t.source_module_id=s.module_id
    LEFT JOIN course_modules m ON m.id=s.module_id AND m.course_id=g.course_id
    WHERE (g.model='scheduled' AND t.group_id IS NULL) OR (g.model='legacy' AND m.id IS NULL)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migrimi u ndal: pikë pa modul burimor të vlefshëm. Verifiko kopjen e grupit.';
  END IF;
  IF NOT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='course_group_students' AND INDEX_NAME='uq_cgs_student') THEN
    ALTER TABLE course_group_students ADD UNIQUE KEY uq_cgs_student(student_id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_course_plans' AND COLUMN_NAME='start_date') THEN
    ALTER TABLE student_course_plans
      ADD start_date DATE NULL, ADD end_date DATE NULL,
      ADD exam_date DATE NULL COMMENT 'Only while ungrouped; membership owns assigned exams',
      ADD manual_final_score DECIMAL(13,10) NULL,
      ADD legacy_result DECIMAL(5,2) NULL,
      ADD result_source ENUM('none','modules','manual','legacy') NOT NULL DEFAULT 'none',
      ADD revision INT NOT NULL DEFAULT 1,
      ADD CONSTRAINT chk_scp_dates CHECK ((start_date IS NULL AND end_date IS NULL) OR (start_date IS NOT NULL AND end_date IS NOT NULL AND start_date<=end_date)),
      ADD CONSTRAINT chk_scp_manual CHECK (manual_final_score IS NULL OR manual_final_score BETWEEN 0 AND 100),
      ADD UNIQUE KEY uq_scp_id_student (id,student_id);
    ALTER TABLE course_groups ADD KEY idx_cg_course_period (course_id,start_date,end_date);
  END IF;
END $$
CALL qta_migrate_enrollment() $$
DROP PROCEDURE IF EXISTS qta_migrate_enrollment $$
DELIMITER ;

-- A small operational projection provides an atomic DB capacity guard. MySQL forbids
-- locking the mutating membership table inside its own trigger.
CREATE TABLE IF NOT EXISTS group_enrollment_capacity (
  group_id INT NOT NULL PRIMARY KEY, members INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_gec_group FOREIGN KEY(group_id) REFERENCES course_groups(id) ON DELETE CASCADE,
  CONSTRAINT chk_gec_members CHECK(members BETWEEN 0 AND 10)
) ENGINE=InnoDB;
INSERT INTO group_enrollment_capacity(group_id,members)
SELECT g.id,COUNT(m.student_id) FROM course_groups g LEFT JOIN course_group_students m ON m.group_id=g.id GROUP BY g.id
ON DUPLICATE KEY UPDATE members=VALUES(members);
DELIMITER $$
DROP TRIGGER IF EXISTS trg_cg_capacity_ai $$
CREATE TRIGGER trg_cg_capacity_ai AFTER INSERT ON course_groups FOR EACH ROW
BEGIN
  INSERT INTO group_enrollment_capacity(group_id,members) VALUES(NEW.id,0);
END $$
DELIMITER ;

ALTER TABLE student_course_plans MODIFY manual_final_score DECIMAL(13,10) NULL;
ALTER TABLE enrollment_module_scores MODIFY score DECIMAL(13,10) NOT NULL;
DELIMITER $$
DROP PROCEDURE IF EXISTS qta_enrollment_precision $$
CREATE PROCEDURE qta_enrollment_precision()
BEGIN
  IF NOT EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_module_scores' AND CONSTRAINT_NAME='chk_ems_precision') THEN
    ALTER TABLE enrollment_module_scores ADD CONSTRAINT chk_ems_precision CHECK(score=ROUND(score,2));
    ALTER TABLE student_course_plans ADD CONSTRAINT chk_scp_precision CHECK(manual_final_score IS NULL OR manual_final_score=ROUND(manual_final_score,2));
  END IF;
END $$
CALL qta_enrollment_precision() $$
DROP PROCEDURE IF EXISTS qta_enrollment_precision $$
DELIMITER ;

-- Disable only the membership synchronization while backfilling its persistent identity.
DROP TRIGGER IF EXISTS trg_cgs_after_insert_ai;
INSERT INTO student_course_plans (student_id,course_id,status,group_id,assigned_at)
SELECT m.student_id,g.course_id,'assigned',g.id,NOW()
FROM course_group_students m JOIN course_groups g ON g.id=m.group_id
WHERE NOT EXISTS(SELECT 1 FROM student_course_plans e WHERE e.student_id=m.student_id AND e.course_id=g.course_id);
UPDATE student_course_plans e JOIN course_groups g ON g.course_id=e.course_id
JOIN course_group_students m ON m.group_id=g.id AND m.student_id=e.student_id
SET e.group_id=g.id,e.status='assigned' WHERE NOT(e.group_id<=>g.id) OR e.status<>'assigned';
UPDATE student_course_plans e JOIN course_groups g ON g.id=e.group_id
SET e.start_date=COALESCE(e.start_date,g.start_date),e.end_date=COALESCE(e.end_date,g.end_date)
WHERE e.start_date IS NULL OR e.end_date IS NULL;
UPDATE course_group_students m JOIN course_groups g ON g.id=m.group_id
SET m.exam_date=g.exam_date WHERE @qta_enrollment_backfill=1 AND m.exam_date IS NULL AND g.exam_date IS NOT NULL;

CREATE TABLE IF NOT EXISTS enrollment_result_modules (
  enrollment_id INT NOT NULL,
  module_id INT NOT NULL COMMENT 'Stable source_module_id; historical snapshot survives catalogue deletion',
  seq INT NOT NULL, title VARCHAR(200) NOT NULL, hours INT NOT NULL,
  PRIMARY KEY(enrollment_id,module_id), UNIQUE KEY uq_erm_seq(enrollment_id,seq),
  CONSTRAINT fk_erm_enrollment FOREIGN KEY(enrollment_id) REFERENCES student_course_plans(id) ON DELETE CASCADE,
  CONSTRAINT chk_erm_hours CHECK(hours>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Exact source IDs, never title-based mapping. No result rows are created.
INSERT IGNORE INTO enrollment_result_modules(enrollment_id,module_id,seq,title,hours)
SELECT e.id,t.source_module_id,t.module_seq,MIN(t.module_title),MIN(t.module_hours)
FROM student_course_plans e JOIN course_groups g ON g.id=e.group_id AND g.model='scheduled'
JOIN group_schedule_topics t ON t.group_id=g.id
GROUP BY e.id,t.module_seq,t.source_module_id HAVING t.source_module_id IS NOT NULL;
INSERT IGNORE INTO enrollment_result_modules(enrollment_id,module_id,seq,title,hours)
SELECT e.id,m.id,ROW_NUMBER() OVER(PARTITION BY e.id ORDER BY m.position,m.id),m.title,m.hours
FROM student_course_plans e JOIN course_groups g ON g.id=e.group_id AND g.model='legacy'
JOIN course_modules m ON m.course_id=e.course_id;

DELIMITER $$
DROP PROCEDURE IF EXISTS qta_migrate_enrollment_scores $$
CREATE PROCEDURE qta_migrate_enrollment_scores()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enrollment_module_scores' AND COLUMN_NAME='enrollment_id') THEN
    ALTER TABLE enrollment_module_scores ADD enrollment_id INT NULL;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_ems_enrollment') THEN
    UPDATE enrollment_module_scores s JOIN course_groups g ON g.id=s.group_id
    JOIN student_course_plans e ON e.student_id=s.student_id AND e.course_id=g.course_id
    SET s.enrollment_id=e.id;
    ALTER TABLE enrollment_module_scores DROP FOREIGN KEY fk_ems_enrollment,
      DROP PRIMARY KEY, MODIFY group_id INT NULL, MODIFY enrollment_id INT NOT NULL,
      ADD PRIMARY KEY(enrollment_id,module_id), ADD UNIQUE KEY uq_ems_membership(group_id,student_id,module_id),
      ADD CONSTRAINT fk_ems_course_enrollment FOREIGN KEY(enrollment_id,student_id) REFERENCES student_course_plans(id,student_id) ON DELETE CASCADE,
      ADD CONSTRAINT fk_ems_module FOREIGN KEY(enrollment_id,module_id) REFERENCES enrollment_result_modules(enrollment_id,module_id) ON DELETE RESTRICT;
  END IF;
END $$
-- Old update guards prohibit identity changes; the replacement below validates ownership.
DROP TRIGGER IF EXISTS trg_ems_bu $$
CALL qta_migrate_enrollment_scores() $$
DROP PROCEDURE IF EXISTS qta_migrate_enrollment_scores $$
DELIMITER ;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_cgs_enrollment_bu $$
CREATE TRIGGER trg_cgs_enrollment_bu BEFORE UPDATE ON course_group_students FOR EACH ROW
BEGIN
  DECLARE v_course INT; DECLARE v_start DATE; DECLARE v_end DATE; DECLARE v_count INT;
  IF NEW.student_id<>OLD.student_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Regjistrimi nuk lëviz te një kursant tjetër.';
  END IF;
  IF NEW.group_id<>OLD.group_id THEN
    SELECT course_id,start_date,end_date INTO v_course,v_start,v_end FROM course_groups WHERE id=NEW.group_id FOR UPDATE;
    UPDATE group_enrollment_capacity SET members=members+1 WHERE group_id=NEW.group_id AND members<10;
    IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Grupi është plot (10 kursantë).'; END IF;
    UPDATE group_enrollment_capacity SET members=members-1 WHERE group_id=OLD.group_id;
    IF NOT EXISTS(SELECT 1 FROM student_course_plans e WHERE e.student_id=OLD.student_id AND e.group_id=OLD.group_id
                  AND e.course_id=v_course AND e.start_date=v_start AND e.end_date=v_end) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Zhvendosja kërkon kursin dhe datat identike.';
    END IF;
  END IF;
  SELECT MAX(end_date) INTO v_end FROM student_course_plans WHERE student_id=NEW.student_id AND group_id=OLD.group_id;
  IF NEW.exam_date IS NOT NULL AND NEW.exam_date<v_end THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Provimi nuk mund të jetë para mbarimit individual.';
  END IF;
END $$
DROP TRIGGER IF EXISTS trg_cgs_enrollment_au $$
CREATE TRIGGER trg_cgs_enrollment_au AFTER UPDATE ON course_group_students FOR EACH ROW
BEGIN
  IF NEW.group_id<>OLD.group_id THEN
    SET @qta_enrollment_sync=1;
    UPDATE student_course_plans SET group_id=NEW.group_id WHERE group_id=OLD.group_id AND student_id=OLD.student_id;
    UPDATE enrollment_module_scores SET group_id=NEW.group_id WHERE group_id=OLD.group_id AND student_id=OLD.student_id;
    SET @qta_enrollment_sync=NULL;
  END IF;
END $$
DROP TRIGGER IF EXISTS trg_scp_history_bd $$
CREATE TRIGGER trg_scp_history_bd BEFORE DELETE ON student_course_plans FOR EACH ROW
BEGIN
  IF OLD.start_date IS NOT NULL OR OLD.group_id IS NOT NULL OR EXISTS(SELECT 1 FROM enrollment_module_scores WHERE enrollment_id=OLD.id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Regjistrimi historik nuk fshihet; anuloje ose hiqe vetëm nga grupi.';
  END IF;
END $$
DELIMITER ;
UPDATE student_course_plans e JOIN course_group_students m ON m.group_id=e.group_id AND m.student_id=e.student_id
SET e.result_source=CASE WHEN EXISTS(SELECT 1 FROM enrollment_module_scores s WHERE s.enrollment_id=e.id) THEN 'modules'
                        WHEN e.manual_final_score IS NOT NULL THEN e.result_source
                        WHEN m.final_score IS NOT NULL THEN 'legacy' ELSE 'none' END,
    e.legacy_result=COALESCE(e.legacy_result,m.legacy_final_score,
      CASE WHEN NOT EXISTS(SELECT 1 FROM enrollment_module_scores s WHERE s.enrollment_id=e.id) AND e.manual_final_score IS NULL THEN m.final_score END)
WHERE e.result_source='none' AND (m.final_score IS NOT NULL OR m.legacy_final_score IS NOT NULL
  OR EXISTS(SELECT 1 FROM enrollment_module_scores s WHERE s.enrollment_id=e.id));

DELIMITER $$
DROP TRIGGER IF EXISTS trg_ems_bi $$
CREATE TRIGGER trg_ems_bi BEFORE INSERT ON enrollment_module_scores FOR EACH ROW
BEGIN
  DECLARE v_id INT; DECLARE v_group INT; DECLARE v_exam DATE; DECLARE v_end DATE;
  SELECT MAX(id),MAX(group_id),MAX(exam_date),MAX(end_date) INTO v_id,v_group,v_exam,v_end
  FROM student_course_plans WHERE student_id=NEW.student_id AND
    (id=NEW.enrollment_id OR (COALESCE(NEW.enrollment_id,0)=0 AND group_id=NEW.group_id));
  SET NEW.enrollment_id=v_id;
  IF v_id IS NULL OR NOT(NEW.group_id<=>v_group) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pikët nuk i përkasin këtij regjistrimi/anëtarësimi.';
  END IF;
  IF v_group IS NOT NULL THEN
    SELECT MAX(exam_date) INTO v_exam FROM course_group_students WHERE group_id=v_group AND student_id=NEW.student_id;
  END IF;
  IF v_exam IS NULL OR (v_end IS NOT NULL AND v_exam<v_end) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pikët kërkojnë provim në ose pas mbarimit të kursit.';
  END IF;
END $$
DROP TRIGGER IF EXISTS trg_ems_bu $$
CREATE TRIGGER trg_ems_bu BEFORE UPDATE ON enrollment_module_scores FOR EACH ROW
BEGIN
  DECLARE v_exam DATE; DECLARE v_end DATE;
  IF NEW.enrollment_id<>OLD.enrollment_id OR NEW.student_id<>OLD.student_id OR NEW.module_id<>OLD.module_id
     OR (NOT(NEW.group_id<=>OLD.group_id) AND COALESCE(@qta_enrollment_sync,0)<>1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pikët nuk lëvizin te një regjistrim ose modul tjetër.';
  END IF;
  SELECT exam_date,end_date INTO v_exam,v_end FROM student_course_plans WHERE id=NEW.enrollment_id;
  IF NEW.group_id IS NOT NULL THEN
    SELECT MAX(exam_date) INTO v_exam FROM course_group_students WHERE group_id=NEW.group_id AND student_id=NEW.student_id;
  END IF;
  IF COALESCE(@qta_enrollment_sync,0)<>1 AND (v_exam IS NULL OR v_exam<v_end) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pikët kërkojnë provim në ose pas mbarimit të kursit.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_scp_validate_group_bu $$
CREATE TRIGGER trg_scp_validate_group_bu BEFORE UPDATE ON student_course_plans FOR EACH ROW
BEGIN
  IF NEW.student_id<>OLD.student_id OR NEW.course_id<>OLD.course_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Identiteti i regjistrimit është i pandryshueshëm.';
  END IF;
  IF NEW.group_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM course_groups WHERE id=NEW.group_id AND course_id=NEW.course_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Grupi nuk i përket kursit të regjistrimit.';
  END IF;
  IF NEW.status='assigned' AND NEW.group_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Regjistrimi i caktuar kërkon grup.';
  END IF;
  IF NEW.group_id IS NOT NULL AND (NEW.status<>'assigned' OR NOT EXISTS(
    SELECT 1 FROM course_group_students WHERE student_id=NEW.student_id AND group_id=NEW.group_id)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Regjistrimi në grup kërkon anëtarësinë përkatëse.';
  END IF;
  IF NEW.group_id IS NOT NULL AND COALESCE(@qta_enrollment_sync,0)<>1
     AND COALESCE(@qta_course_change_group,0)<>NEW.group_id AND NOT EXISTS(
       SELECT 1 FROM course_groups WHERE id=NEW.group_id AND start_date=NEW.start_date AND end_date=NEW.end_date) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Periudha e regjistrimit nuk përputhet me grupin.';
  END IF;
  IF NEW.group_id IS NULL AND (NEW.result_source='manual' OR EXISTS(SELECT 1 FROM enrollment_module_scores WHERE enrollment_id=OLD.id))
     AND (NEW.exam_date IS NULL OR NEW.end_date IS NULL OR NEW.exam_date<NEW.end_date) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rezultati kërkon datën e provimit pas mbarimit të kursit.';
  END IF;
  IF OLD.group_id IS NOT NULL AND (NOT(NEW.start_date<=>OLD.start_date) OR NOT(NEW.end_date<=>OLD.end_date))
     AND COALESCE(@qta_enrollment_sync,0)<>1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Datat e kursantit në grup ndryshohen vetëm me pajtim të shprehur.';
  END IF;
  SET NEW.revision=OLD.revision+1;
END $$
DROP TRIGGER IF EXISTS trg_scp_details_bi $$
CREATE TRIGGER trg_scp_details_bi BEFORE INSERT ON student_course_plans FOR EACH ROW
BEGIN
  IF NEW.status='assigned' AND NEW.group_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Regjistrimi i caktuar kërkon grup.';
  END IF;
  IF NEW.result_source='manual' AND (NEW.manual_final_score IS NULL OR NEW.exam_date IS NULL OR NEW.end_date IS NULL OR NEW.exam_date<NEW.end_date) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rezultati manual kërkon periudhën dhe datën e provimit.';
  END IF;
  IF NEW.group_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM course_groups WHERE id=NEW.group_id AND course_id=NEW.course_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Grupi nuk i përket kursit të regjistrimit.';
  END IF;
  IF NEW.group_id IS NOT NULL AND (NEW.status<>'assigned' OR NOT EXISTS(
    SELECT 1 FROM course_group_students WHERE student_id=NEW.student_id AND group_id=NEW.group_id)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Regjistrimi në grup kërkon anëtarësinë përkatëse.';
  END IF;
  IF NEW.group_id IS NOT NULL AND COALESCE(@qta_course_change_group,0)<>NEW.group_id AND NOT EXISTS(
    SELECT 1 FROM course_groups WHERE id=NEW.group_id AND start_date=NEW.start_date AND end_date=NEW.end_date) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Periudha e regjistrimit nuk përputhet me grupin.';
  END IF;
END $$

-- A database caller cannot bypass reconciliation. The service supplies the review.
DROP TRIGGER IF EXISTS trg_cgs_limit_10 $$
CREATE TRIGGER trg_cgs_limit_10 BEFORE INSERT ON course_group_students FOR EACH ROW
BEGIN
  DECLARE v_group INT; DECLARE v_count INT;
  SELECT id INTO v_group FROM course_groups WHERE id=NEW.group_id FOR UPDATE;
  UPDATE group_enrollment_capacity SET members=members+1 WHERE group_id=NEW.group_id AND members<10;
  IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ky grup ka arritur kufirin prej 10 kursantësh.'; END IF;
END $$
DROP TRIGGER IF EXISTS trg_cgs_enrollment_bi $$
CREATE TRIGGER trg_cgs_enrollment_bi BEFORE INSERT ON course_group_students FOR EACH ROW
BEGIN
  DECLARE v_course INT; DECLARE v_start DATE; DECLARE v_end DATE; DECLARE v_id INT;
  DECLARE e_start DATE; DECLARE e_end DATE; DECLARE e_exam DATE; DECLARE e_manual DECIMAL(5,2); DECLARE e_source VARCHAR(16);
  SELECT course_id,start_date,end_date INTO v_course,v_start,v_end FROM course_groups WHERE id=NEW.group_id FOR UPDATE;
  IF EXISTS(SELECT 1 FROM course_group_students WHERE student_id=NEW.student_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ky regjistrim është tashmë në grup.';
  END IF;
  SELECT MAX(id),MAX(start_date),MAX(end_date),MAX(exam_date),MAX(manual_final_score),MAX(result_source)
  INTO v_id,e_start,e_end,e_exam,e_manual,e_source FROM student_course_plans WHERE student_id=NEW.student_id AND course_id=v_course;
  IF e_start IS NOT NULL AND (e_start<>v_start OR e_end<>v_end) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Datat e regjistrimit nuk përputhen me grupin. Zgjidh konfliktin te kursanti.';
  END IF;
  SET NEW.exam_date=COALESCE(NEW.exam_date,e_exam);
  IF NEW.exam_date IS NOT NULL AND NEW.exam_date<v_end THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Provimi nuk mund të jetë para mbarimit të kursit.';
  END IF;
  IF e_source='manual' AND NEW.exam_date IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rezultati manual kërkon datën e provimit.';
  END IF;
  IF v_id IS NOT NULL AND EXISTS(SELECT 1 FROM enrollment_module_scores WHERE enrollment_id=v_id) THEN
    IF NEW.exam_date IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pikët kërkojnë datën e provimit.'; END IF;
    IF EXISTS(SELECT 1 FROM enrollment_result_modules r WHERE r.enrollment_id=v_id AND NOT EXISTS(
      SELECT 1 FROM group_schedule_topics t WHERE t.group_id=NEW.group_id AND t.source_module_id=r.module_id
      AND t.module_title=r.title AND t.module_hours=r.hours AND t.module_seq=r.seq))
      AND EXISTS(SELECT 1 FROM course_groups WHERE id=NEW.group_id AND model='scheduled') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Kopja e moduleve nuk përputhet me grupin.';
    END IF;
  END IF;
  IF e_source='manual' THEN SET NEW.final_score=e_manual; END IF;
END $$
DROP TRIGGER IF EXISTS trg_cgs_after_insert_ai $$
CREATE TRIGGER trg_cgs_after_insert_ai AFTER INSERT ON course_group_students FOR EACH ROW
BEGIN
  DECLARE v_course INT; DECLARE v_start DATE; DECLARE v_end DATE; DECLARE v_id INT;
  SELECT course_id,start_date,end_date INTO v_course,v_start,v_end FROM course_groups WHERE id=NEW.group_id;
  INSERT INTO student_course_plans(student_id,course_id,status,group_id,start_date,end_date,assigned_at)
  VALUES(NEW.student_id,v_course,'assigned',NEW.group_id,v_start,v_end,NOW())
  ON DUPLICATE KEY UPDATE group_id=NEW.group_id,status='assigned',start_date=COALESCE(start_date,v_start),end_date=COALESCE(end_date,v_end),assigned_at=NOW();
  SELECT id INTO v_id FROM student_course_plans WHERE student_id=NEW.student_id AND course_id=v_course;
  INSERT IGNORE INTO enrollment_result_modules(enrollment_id,module_id,seq,title,hours)
  SELECT v_id,source_module_id,module_seq,MIN(module_title),MIN(module_hours) FROM group_schedule_topics
  WHERE group_id=NEW.group_id GROUP BY module_seq,source_module_id HAVING source_module_id IS NOT NULL;
  INSERT IGNORE INTO enrollment_result_modules(enrollment_id,module_id,seq,title,hours)
  SELECT v_id,m.id,ROW_NUMBER() OVER(ORDER BY m.position,m.id),m.title,m.hours FROM course_modules m
  JOIN course_groups g ON g.course_id=m.course_id AND g.id=NEW.group_id AND g.model='legacy';
  SET @qta_enrollment_sync=1;
  UPDATE enrollment_module_scores SET group_id=NEW.group_id WHERE enrollment_id=v_id;
  SET @qta_enrollment_sync=NULL;
  UPDATE student_course_plans SET exam_date=NULL WHERE id=v_id;
END $$

-- Detaching archives the exam and retains the same normalized score rows.
DROP TRIGGER IF EXISTS trg_cgs_results_bd $$
CREATE TRIGGER trg_cgs_results_bd BEFORE DELETE ON course_group_students FOR EACH ROW
BEGIN
  DECLARE v_id INT;
  UPDATE group_enrollment_capacity SET members=members-1 WHERE group_id=OLD.group_id;
  SELECT MAX(id) INTO v_id FROM student_course_plans WHERE group_id=OLD.group_id AND student_id=OLD.student_id;
  SET @qta_enrollment_sync=1;
  UPDATE student_course_plans SET exam_date=OLD.exam_date,legacy_result=COALESCE(legacy_result,OLD.legacy_final_score,
      CASE WHEN result_source IN('none','legacy') THEN OLD.final_score END),status='planned',group_id=NULL WHERE id=v_id;
  UPDATE enrollment_module_scores SET group_id=NULL WHERE enrollment_id=v_id;
  SET @qta_enrollment_sync=NULL;
END $$

DROP TRIGGER IF EXISTS trg_cg_enrollment_dates_bu $$
CREATE TRIGGER trg_cg_enrollment_dates_bu BEFORE UPDATE ON course_groups FOR EACH ROW
BEGIN
  IF NOT(NEW.exam_date<=>OLD.exam_date) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Data e provimit caktohet vetëm për kursantin.';
  END IF;
  IF (NEW.start_date<>OLD.start_date OR NEW.end_date<>OLD.end_date) AND EXISTS(
    SELECT 1 FROM student_course_plans e WHERE e.group_id=OLD.id AND e.start_date IS NOT NULL
      AND (e.start_date<>NEW.start_date OR e.end_date<>NEW.end_date)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ndryshimi i periudhës kërkon pajtimin e datave të kursantëve.';
  END IF;
  IF NEW.course_id<>OLD.course_id AND EXISTS(SELECT 1 FROM student_course_plans WHERE group_id=OLD.id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Kursi ka regjistrime persistente; zgjidh së pari regjistrimet e kursantëve.';
  END IF;
END $$
DROP TRIGGER IF EXISTS trg_cg_enrollment_bd $$
CREATE TRIGGER trg_cg_enrollment_bd BEFORE DELETE ON course_groups FOR EACH ROW
BEGIN
  -- FK cascades do not run child triggers. Detach explicitly to retain enrollment history.
  DELETE FROM course_group_students WHERE group_id=OLD.id;
END $$

-- InnoDB cascades do not fire child triggers. Explicit parent cleanup keeps
-- capacity and audit correct through existing student/person/user deletion paths.
DROP TRIGGER IF EXISTS trg_students_enrollment_bd $$
CREATE TRIGGER trg_students_enrollment_bd BEFORE DELETE ON students FOR EACH ROW
BEGIN
  DELETE FROM course_group_students WHERE student_id=OLD.id;
  DELETE FROM enrollment_module_scores WHERE student_id=OLD.id;
END $$
DROP TRIGGER IF EXISTS trg_persons_enrollment_bd $$
CREATE TRIGGER trg_persons_enrollment_bd BEFORE DELETE ON persons FOR EACH ROW
BEGIN
  DELETE FROM students WHERE person_id=OLD.id;
END $$
DROP TRIGGER IF EXISTS trg_users_enrollment_bd $$
CREATE TRIGGER trg_users_enrollment_bd BEFORE DELETE ON users FOR EACH ROW
BEGIN
  DELETE FROM students WHERE user_id=OLD.id;
END $$
DROP TRIGGER IF EXISTS trg_scp_enrollment_bd $$
CREATE TRIGGER trg_scp_enrollment_bd BEFORE DELETE ON student_course_plans FOR EACH ROW
BEGIN
  IF OLD.group_id IS NOT NULL AND EXISTS(SELECT 1 FROM course_group_students WHERE group_id=OLD.group_id AND student_id=OLD.student_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Hiqe kursantin nga grupi përpara fshirjes së regjistrimit.';
  END IF;
  DELETE FROM enrollment_module_scores WHERE enrollment_id=OLD.id;
END $$
DELIMITER ;
SET @qta_enrollment_backfill=NULL;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_audit_enrollment_details_ai $$
CREATE TRIGGER trg_audit_enrollment_details_ai AFTER INSERT ON student_course_plans FOR EACH ROW
BEGIN
CALL audit_capture('student_course_plans','INSERT',JSON_OBJECT('id',NEW.id),NULL,JSON_OBJECT('id', NEW.id, 'student_id', NEW.student_id, 'course_id', NEW.course_id, 'group_id', NEW.group_id, 'start_date', NEW.start_date, 'end_date', NEW.end_date, 'exam_date', NEW.exam_date, 'manual_final_score', NEW.manual_final_score, 'legacy_result', NEW.legacy_result, 'result_source', NEW.result_source, 'note', NEW.note));
INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'start_date',NULL,NEW.start_date);
INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'end_date',NULL,NEW.end_date);
INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'exam_date',NULL,NEW.exam_date);
INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'manual_final_score',NULL,NEW.manual_final_score);
INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'legacy_result',NULL,NEW.legacy_result);
INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'result_source',NULL,NEW.result_source);
INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'note',NULL,NEW.note);
END $$
DROP TRIGGER IF EXISTS trg_audit_enrollment_details_au $$
CREATE TRIGGER trg_audit_enrollment_details_au AFTER UPDATE ON student_course_plans FOR EACH ROW
BEGIN
IF NOT(OLD.start_date<=>NEW.start_date) OR NOT(OLD.end_date<=>NEW.end_date) OR NOT(OLD.exam_date<=>NEW.exam_date) OR NOT(OLD.manual_final_score<=>NEW.manual_final_score) OR NOT(OLD.legacy_result<=>NEW.legacy_result) OR NOT(OLD.result_source<=>NEW.result_source) OR NOT(OLD.note<=>NEW.note) THEN
CALL audit_capture('student_course_plans','UPDATE',JSON_OBJECT('id',NEW.id),JSON_OBJECT('id', OLD.id, 'student_id', OLD.student_id, 'course_id', OLD.course_id, 'group_id', OLD.group_id, 'start_date', OLD.start_date, 'end_date', OLD.end_date, 'exam_date', OLD.exam_date, 'manual_final_score', OLD.manual_final_score, 'legacy_result', OLD.legacy_result, 'result_source', OLD.result_source, 'note', OLD.note),JSON_OBJECT('id', NEW.id, 'student_id', NEW.student_id, 'course_id', NEW.course_id, 'group_id', NEW.group_id, 'start_date', NEW.start_date, 'end_date', NEW.end_date, 'exam_date', NEW.exam_date, 'manual_final_score', NEW.manual_final_score, 'legacy_result', NEW.legacy_result, 'result_source', NEW.result_source, 'note', NEW.note));
IF NOT(OLD.start_date<=>NEW.start_date) THEN INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'start_date',OLD.start_date,NEW.start_date); END IF;
IF NOT(OLD.end_date<=>NEW.end_date) THEN INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'end_date',OLD.end_date,NEW.end_date); END IF;
IF NOT(OLD.exam_date<=>NEW.exam_date) THEN INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'exam_date',OLD.exam_date,NEW.exam_date); END IF;
IF NOT(OLD.manual_final_score<=>NEW.manual_final_score) THEN INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'manual_final_score',OLD.manual_final_score,NEW.manual_final_score); END IF;
IF NOT(OLD.legacy_result<=>NEW.legacy_result) THEN INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'legacy_result',OLD.legacy_result,NEW.legacy_result); END IF;
IF NOT(OLD.result_source<=>NEW.result_source) THEN INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'result_source',OLD.result_source,NEW.result_source); END IF;
IF NOT(OLD.note<=>NEW.note) THEN INSERT INTO audit_event_fields(event_id,column_name,old_value,new_value) VALUES(@last_audit_event_id,'note',OLD.note,NEW.note); END IF;
END IF;
END $$
DELIMITER ;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_audit_ems_ai $$
CREATE TRIGGER trg_audit_ems_ai AFTER INSERT ON enrollment_module_scores
FOR EACH ROW
BEGIN
  CALL audit_capture('enrollment_module_scores','INSERT',
    JSON_OBJECT('enrollment_id', NEW.enrollment_id, 'group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id), NULL,
    JSON_OBJECT('enrollment_id', NEW.enrollment_id, 'group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id, 'score', NEW.score));
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
      JSON_OBJECT('enrollment_id', NEW.enrollment_id, 'group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id),
      JSON_OBJECT('enrollment_id', OLD.enrollment_id, 'group_id', OLD.group_id, 'student_id', OLD.student_id, 'module_id', OLD.module_id, 'score', OLD.score),
      JSON_OBJECT('enrollment_id', NEW.enrollment_id, 'group_id', NEW.group_id, 'student_id', NEW.student_id, 'module_id', NEW.module_id, 'score', NEW.score));
    SET @e := @last_audit_event_id;
    INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES (@e,'score',OLD.score,NEW.score);
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_ems_ad $$
CREATE TRIGGER trg_audit_ems_ad AFTER DELETE ON enrollment_module_scores
FOR EACH ROW
BEGIN
  CALL audit_capture('enrollment_module_scores','DELETE',
    JSON_OBJECT('enrollment_id', OLD.enrollment_id, 'group_id', OLD.group_id, 'student_id', OLD.student_id, 'module_id', OLD.module_id),
    JSON_OBJECT('enrollment_id', OLD.enrollment_id, 'group_id', OLD.group_id, 'student_id', OLD.student_id, 'module_id', OLD.module_id, 'score', OLD.score), NULL);
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'student_id',OLD.student_id,NULL),
    (@e,'module_id',OLD.module_id,NULL),(@e,'score',OLD.score,NULL);
END $$


DELIMITER ;
