-- =========================================================
-- (OPSIONALE) Fshi DB ekzistuese dhe krijo nga e para
-- =========================================================
-- DROP DATABASE IF EXISTS qta_db;

-- =========================================================
-- Krijo DB dhe kalimi te skema
-- =========================================================
CREATE DATABASE IF NOT EXISTS qta_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Rekomandim: mënyra strikte
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- =========================================================
-- Tabela Roles
-- =========================================================
CREATE TABLE IF NOT EXISTS roles (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================================
-- Tabela Genders (domain table)
-- =========================================================
CREATE TABLE IF NOT EXISTS genders (
  id    TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code  CHAR(1)     NOT NULL UNIQUE,   -- 'M', 'F', 'N', 'U' (nëse zgjeron në të ardhmen)
  label VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed bazë për gjinitë
INSERT INTO genders (code, label) VALUES
  ('M','Mashkull'),
  ('F','Femër')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- =========================================================
-- Tabela Persons (të dhënat personale, unike sipas personal_number)
-- =========================================================
CREATE TABLE IF NOT EXISTS persons (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  personal_number  VARCHAR(100) NOT NULL UNIQUE,
  first_name       VARCHAR(100) NOT NULL,
  father_name      VARCHAR(100) NULL,
  last_name        VARCHAR(100) NOT NULL,
  birth_date       DATE NULL,
  birth_place      VARCHAR(150) NULL,
  phone            VARCHAR(50)  NULL,
  gender_id        TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_persons_gender FOREIGN KEY (gender_id) REFERENCES genders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_persons_name ON persons (last_name, first_name);

-- =========================================================
-- Tabela Users (llogaritë e aksesit)
-- =========================================================
CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  role_id    INT NOT NULL,
  person_id  INT NULL,                     -- NULL për llogari që s’kanë person (p.sh. agjenci pa personal_number)
  full_name  VARCHAR(200),
  email      VARCHAR(200) UNIQUE,          -- adminët/agjencitë mund të kenë email; studentët opsional
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role   FOREIGN KEY (role_id)   REFERENCES roles(id),
  CONSTRAINT fk_users_person FOREIGN KEY (person_id) REFERENCES persons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Një person ↔ një user (kur ka person_id). Lejon shumë NULL.
CREATE UNIQUE INDEX uq_users_person ON users(person_id);

CREATE INDEX idx_users_role ON users(role_id);

-- =========================================================
-- Tabela Credentials (fjalëkalimet)
-- =========================================================
CREATE TABLE IF NOT EXISTS credentials (
  user_id              INT PRIMARY KEY,
  password_hash        VARCHAR(255) NOT NULL,
  last_password_change DATETIME DEFAULT NULL,
  CONSTRAINT fk_credentials_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================================
-- Tabela Agencies (kompani/agjenci që kanë një user)
-- =========================================================
CREATE TABLE IF NOT EXISTS agencies (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNIQUE NOT NULL,           -- 1:1 me users
  nip_t        VARCHAR(100) NOT NULL UNIQUE,  -- NIPT/NIPT
  company_name VARCHAR(200),
  address      TEXT,
  phone        VARCHAR(50),
  CONSTRAINT fk_agencies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================================
-- Tabela Education Levels
-- =========================================================
CREATE TABLE IF NOT EXISTS education_levels (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  code  VARCHAR(20)  NOT NULL UNIQUE,   -- AU/AM/AL
  label VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed bazë për nivele arsimi
INSERT INTO education_levels (code, label) VALUES
('AU', 'Arsimi i ulët'),
('AM', 'Arsimi i mesëm'),
('AL', 'Arsimi i lartë')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- =========================================================
-- Tabela Students (regjistrimet; mund të ketë disa për të njëjtin person)
-- =========================================================
CREATE TABLE IF NOT EXISTS students (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  person_id           INT NOT NULL,
  user_id             INT NOT NULL,                 -- llogaria me të cilën hyn (zakonisht 1:1 me person)
  nr_amze             VARCHAR(100) NOT NULL UNIQUE, -- unike per regjistrim
  education_level_id  INT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_person  FOREIGN KEY (person_id)          REFERENCES persons(id) ON DELETE CASCADE,
  CONSTRAINT fk_students_user    FOREIGN KEY (user_id)            REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_students_edulvl  FOREIGN KEY (education_level_id) REFERENCES education_levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_students_person ON students(person_id);
CREATE INDEX idx_students_user   ON students(user_id);

-- =========================================================
-- Tabela Admins (nëse ke nevojë të ruash info shtesë për admin)
-- =========================================================
CREATE TABLE IF NOT EXISTS admins (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNIQUE NOT NULL,
  employee_code  VARCHAR(100) UNIQUE,
  CONSTRAINT fk_admins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================================
-- Tabela Courses (modulet/lëndët)
-- =========================================================
CREATE TABLE IF NOT EXISTS courses (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  code   VARCHAR(50)  NOT NULL UNIQUE,      -- p.sh. QTA-ALGO
  name   VARCHAR(200) NOT NULL,
  hours  SMALLINT UNSIGNED NOT NULL,        -- >=1
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_courses_name ON courses(name);

-- =========================================================
-- Tabela Course Groups (grupe kursesh me data)
-- =========================================================
CREATE TABLE IF NOT EXISTS course_groups (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  course_id  INT NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  exam_date  DATE NULL,                                     -- data testit për grupin
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cg_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT chk_cg_dates_order CHECK (end_date >= start_date),
  CONSTRAINT chk_cg_exam_after_end CHECK (exam_date IS NULL OR exam_date >= end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_course_groups_course ON course_groups(course_id);
CREATE INDEX idx_course_groups_dates  ON course_groups(start_date, end_date);

-- =========================================================
-- Tabela Course Group Students (anëtarët e grupit)
-- =========================================================
CREATE TABLE IF NOT EXISTS course_group_students (
  group_id     INT NOT NULL,
  student_id   INT NOT NULL,
  final_score  DECIMAL(5,2) NULL,
  PRIMARY KEY (group_id, student_id),
  CONSTRAINT fk_cgs_group   FOREIGN KEY (group_id)   REFERENCES course_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_cgs_student FOREIGN KEY (student_id) REFERENCES students(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_cgs_student ON course_group_students(student_id);

-- =========================================================
-- Tabela Agency Students (një student i caktuar maksimalisht në 1 agjenci)
-- =========================================================
CREATE TABLE IF NOT EXISTS agency_students (
  agency_id   INT NOT NULL,
  student_id  INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),  -- çdo student maksimumi në 1 agjenci
  KEY idx_agency_students_agency (agency_id),
  CONSTRAINT fk_agency_students_agency  FOREIGN KEY (agency_id)  REFERENCES agencies(id) ON DELETE CASCADE,
  CONSTRAINT fk_agency_students_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================================
-- Tabela Student QR Tokens (token unik për secilin student)
-- =========================================================
CREATE TABLE IF NOT EXISTS student_qr_tokens (
  student_id INT NOT NULL PRIMARY KEY,
  token      CHAR(32) NOT NULL UNIQUE,  -- p.sh. 32-hex nga random_bytes(16)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_qr_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================================
-- Seed për rolet bazë
-- =========================================================
INSERT INTO roles (name) VALUES ('administrator'), ('agjencia'), ('student')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- =========================================================
-- TRIGGER-a të validimit (MySQL 8+)
-- =========================================================
DELIMITER $$

/* ---------------------------------------------------------
   Kufizo madhësinë e grupit në 10 studentë
--------------------------------------------------------- */
CREATE TRIGGER trg_cgs_limit_10
BEFORE INSERT ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE cnt INT;
  SELECT COUNT(*) INTO cnt FROM course_group_students WHERE group_id = NEW.group_id;
  IF cnt >= 10 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Ky grup ka arritur kufirin prej 10 studentësh.';
  END IF;
END $$

/* ---------------------------------------------------------
   Nota lejohet vetëm nëse ekziston exam_date në course_groups
--------------------------------------------------------- */
CREATE TRIGGER trg_cgs_grade_requires_exam_ins
BEFORE INSERT ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE cg_exam DATE;
  IF NEW.final_score IS NOT NULL THEN
    SELECT exam_date INTO cg_exam FROM course_groups WHERE id = NEW.group_id;
    IF cg_exam IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Nuk mund të vendoset nota pa përcaktuar datën e testit të grupit.';
    END IF;
  END IF;
END $$

CREATE TRIGGER trg_cgs_grade_requires_exam_upd
BEFORE UPDATE ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE cg_exam DATE;
  IF NEW.final_score IS NOT NULL
     AND (OLD.final_score IS NULL OR NEW.final_score <> OLD.final_score) THEN
    SELECT exam_date INTO cg_exam FROM course_groups WHERE id = NEW.group_id;
    IF cg_exam IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Nuk mund të vendoset nota pa përcaktuar datën e testit të grupit.';
    END IF;
  END IF;
END $$

/* ---------------------------------------------------------
   Validim datash për course_groups
--------------------------------------------------------- */
CREATE TRIGGER trg_cg_dates_before_insert
BEFORE INSERT ON course_groups
FOR EACH ROW
BEGIN
  IF NEW.end_date < NEW.start_date THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Data e mbarimit duhet të jetë ≥ datës së fillimit.';
  END IF;
  IF NEW.exam_date IS NOT NULL AND NEW.exam_date < NEW.end_date THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Data e testit duhet të jetë ≥ datës së mbarimit.';
  END IF;
END $$

CREATE TRIGGER trg_cg_dates_before_update
BEFORE UPDATE ON course_groups
FOR EACH ROW
BEGIN
  IF NEW.end_date < NEW.start_date THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Data e mbarimit duhet të jetë ≥ datës së fillimit.';
  END IF;
  IF NEW.exam_date IS NOT NULL AND NEW.exam_date < NEW.end_date THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Data e testit duhet të jetë ≥ datës së mbarimit.';
  END IF;
END $$

DELIMITER ;


/* 1) Shto kolonën exam_date te course_group_students (nëse mungon) */
ALTER TABLE course_group_students
  ADD COLUMN exam_date DATE NULL;

/* 2) (Nëse ekziston) hiqe ose injoroje cg.exam_date (mund ta lësh edhe si default per grup nëse do) */
/* ALTER TABLE course_groups DROP COLUMN exam_date; */

/* 3) Triggers të rinj për të imponuar rregullat me exam per-student */
DELIMITER $$

/* Nota lejohet vetëm nëse ekziston exam_date PER-STUDENT dhe është ≥ end_date e grupit */
DROP TRIGGER IF EXISTS trg_cgs_grade_requires_exam_ins $$
CREATE TRIGGER trg_cgs_grade_requires_exam_ins
BEFORE INSERT ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE g_end DATE;
  IF NEW.final_score IS NOT NULL THEN
    IF NEW.exam_date IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nuk mund të vendoset notë pa përcaktuar datën e testit (student).';
    END IF;
    SELECT end_date INTO g_end FROM course_groups WHERE id = NEW.group_id;
    IF g_end IS NOT NULL AND NEW.exam_date < g_end THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data e testit (student) duhet të jetë ≥ datës së mbarimit të grupit.';
    END IF;
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_cgs_grade_requires_exam_upd $$
CREATE TRIGGER trg_cgs_grade_requires_exam_upd
BEFORE UPDATE ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE g_end DATE;
  /* 3a) Nëse ndryshohet exam_date, validoje ndaj end_date */
  IF NEW.exam_date IS NOT NULL AND (OLD.exam_date IS NULL OR NEW.exam_date <> OLD.exam_date) THEN
    SELECT end_date INTO g_end FROM course_groups WHERE id = NEW.group_id;
    IF g_end IS NOT NULL AND NEW.exam_date < g_end THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data e testit (student) duhet të jetë ≥ datës së mbarimit të grupit.';
    END IF;
  END IF;
  /* 3b) Nëse vendoset/ndryshohet nota, kërko exam_date per-student */
  IF NEW.final_score IS NOT NULL AND (OLD.final_score IS NULL OR NEW.final_score <> OLD.final_score) THEN
    IF NEW.exam_date IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nuk mund të vendoset notë pa përcaktuar datën e testit (student).';
    END IF;
  END IF;
END $$

DELIMITER ;

ALTER TABLE persons
  MODIFY personal_number VARCHAR(100) NULL,
  MODIFY first_name      VARCHAR(100) NULL,
  MODIFY last_name       VARCHAR(100) NULL;
-- (gender_id mund të mbetet NOT NULL; kodi e vendos një vlerë të vlefshme)

DROP TRIGGER IF EXISTS trg_cg_dates_before_update;
DELIMITER $$
CREATE TRIGGER trg_cg_dates_before_update
BEFORE UPDATE ON course_groups
FOR EACH ROW
BEGIN
  IF NEW.end_date < NEW.start_date THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data e mbarimit duhet të jetë ≥ datës së fillimit.';
  END IF;
  IF EXISTS (
    SELECT 1 FROM course_group_students
    WHERE group_id = NEW.id AND exam_date IS NOT NULL AND exam_date < NEW.end_date
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ka studentë me datë testi para datës së re të mbarimit të grupit.';
  END IF;
END$$
DELIMITER ;

INSERT INTO roles (name) VALUES ('editor')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- =========================================
--  AUDIT TABLES (MariaDB-compatible)
-- =========================================
CREATE TABLE IF NOT EXISTS audit_events (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  happened_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  action       ENUM('INSERT','UPDATE','DELETE') NOT NULL,
  table_name   VARCHAR(64) NOT NULL,
  row_pk       LONGTEXT NOT NULL,                   -- JSON string
  user_id      INT NULL,
  ip_address   VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  old_data     LONGTEXT NULL,                       -- JSON string
  new_data     LONGTEXT NULL,                       -- JSON string
  INDEX idx_audit_time (happened_at),
  INDEX idx_audit_table (table_name),
  INDEX idx_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS audit_event_fields (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id     BIGINT NOT NULL,
  column_name  VARCHAR(64) NOT NULL,
  old_value    TEXT NULL,
  new_value    TEXT NULL,
  CONSTRAINT fk_audit_fields_event FOREIGN KEY (event_id) REFERENCES audit_events(id) ON DELETE CASCADE,
  INDEX idx_audit_fields_event (event_id),
  INDEX idx_audit_fields_col (column_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =========================================
--  PROCEDURE: audit_capture (MariaDB-safe)
--  - NUK përdor JSON_TABLE
--  - Vendos @last_audit_event_id që e lexojnë trigger-at
-- =========================================
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

DELIMITER $$

/* ====================== users ====================== */
DROP TRIGGER IF EXISTS trg_audit_users_ai $$
CREATE TRIGGER trg_audit_users_ai AFTER INSERT ON users
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'users','INSERT',
    JSON_OBJECT('id', NEW.id),
    NULL,
    JSON_OBJECT('id', NEW.id, 'role_id', NEW.role_id, 'person_id', NEW.person_id,
                'full_name', NEW.full_name, 'email', NEW.email, 'created_at', NEW.created_at)
  );

  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'role_id',NULL,NEW.role_id),(@e,'person_id',NULL,NEW.person_id),
    (@e,'full_name',NULL,NEW.full_name),(@e,'email',NULL,NEW.email),(@e,'created_at',NULL,NEW.created_at);
END $$

DROP TRIGGER IF EXISTS trg_audit_users_au $$
CREATE TRIGGER trg_audit_users_au AFTER UPDATE ON users
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'users','UPDATE',
    JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'role_id', OLD.role_id, 'person_id', OLD.person_id,
                'full_name', OLD.full_name, 'email', OLD.email, 'created_at', OLD.created_at),
    JSON_OBJECT('id', NEW.id, 'role_id', NEW.role_id, 'person_id', NEW.person_id,
                'full_name', NEW.full_name, 'email', NEW.email, 'created_at', NEW.created_at)
  );

  SET @e := @last_audit_event_id;
  IF NOT (OLD.role_id   <=> NEW.role_id)   THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'role_id',   OLD.role_id,   NEW.role_id);   END IF;
  IF NOT (OLD.person_id <=> NEW.person_id) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'person_id', OLD.person_id, NEW.person_id); END IF;
  IF NOT (OLD.full_name <=> NEW.full_name) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'full_name', OLD.full_name, NEW.full_name); END IF;
  IF NOT (OLD.email     <=> NEW.email)     THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'email',     OLD.email,     NEW.email);     END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_users_ad $$
CREATE TRIGGER trg_audit_users_ad AFTER DELETE ON users
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'users','DELETE',
    JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'role_id', OLD.role_id, 'person_id', OLD.person_id,
                'full_name', OLD.full_name, 'email', OLD.email, 'created_at', OLD.created_at),
    NULL
  );

  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'role_id',OLD.role_id,NULL),(@e,'person_id',OLD.person_id,NULL),
    (@e,'full_name',OLD.full_name,NULL),(@e,'email',OLD.email,NULL),(@e,'created_at',OLD.created_at,NULL);
END $$

/* ====================== persons ====================== */
DROP TRIGGER IF EXISTS trg_audit_persons_ai $$
CREATE TRIGGER trg_audit_persons_ai AFTER INSERT ON persons
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'persons','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'personal_number', NEW.personal_number, 'first_name', NEW.first_name,
                'father_name', NEW.father_name, 'last_name', NEW.last_name, 'birth_date', NEW.birth_date,
                'birth_place', NEW.birth_place, 'phone', NEW.phone, 'gender_id', NEW.gender_id)
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'personal_number',NULL,NEW.personal_number),(@e,'first_name',NULL,NEW.first_name),
    (@e,'father_name',NULL,NEW.father_name),(@e,'last_name',NULL,NEW.last_name),(@e,'birth_date',NULL,NEW.birth_date),
    (@e,'birth_place',NULL,NEW.birth_place),(@e,'phone',NULL,NEW.phone),(@e,'gender_id',NULL,NEW.gender_id);
END $$

DROP TRIGGER IF EXISTS trg_audit_persons_au $$
CREATE TRIGGER trg_audit_persons_au AFTER UPDATE ON persons
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'persons','UPDATE', JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'personal_number', OLD.personal_number, 'first_name', OLD.first_name,
                'father_name', OLD.father_name, 'last_name', OLD.last_name, 'birth_date', OLD.birth_date,
                'birth_place', OLD.birth_place, 'phone', OLD.phone, 'gender_id', OLD.gender_id),
    JSON_OBJECT('id', NEW.id, 'personal_number', NEW.personal_number, 'first_name', NEW.first_name,
                'father_name', NEW.father_name, 'last_name', NEW.last_name, 'birth_date', NEW.birth_date,
                'birth_place', NEW.birth_place, 'phone', NEW.phone, 'gender_id', NEW.gender_id)
  );
  SET @e := @last_audit_event_id;
  IF NOT (OLD.personal_number <=> NEW.personal_number) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'personal_number', OLD.personal_number, NEW.personal_number); END IF;
  IF NOT (OLD.first_name      <=> NEW.first_name)      THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'first_name',      OLD.first_name,      NEW.first_name);      END IF;
  IF NOT (OLD.father_name     <=> NEW.father_name)     THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'father_name',     OLD.father_name,     NEW.father_name);     END IF;
  IF NOT (OLD.last_name       <=> NEW.last_name)       THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'last_name',       OLD.last_name,       NEW.last_name);       END IF;
  IF NOT (OLD.birth_date      <=> NEW.birth_date)      THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'birth_date',      OLD.birth_date,      NEW.birth_date);      END IF;
  IF NOT (OLD.birth_place     <=> NEW.birth_place)     THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'birth_place',     OLD.birth_place,     NEW.birth_place);     END IF;
  IF NOT (OLD.phone           <=> NEW.phone)           THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'phone',           OLD.phone,           NEW.phone);           END IF;
  IF NOT (OLD.gender_id       <=> NEW.gender_id)       THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'gender_id',       OLD.gender_id,       NEW.gender_id);       END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_persons_ad $$
CREATE TRIGGER trg_audit_persons_ad AFTER DELETE ON persons
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'persons','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'personal_number', OLD.personal_number, 'first_name', OLD.first_name,
                'father_name', OLD.father_name, 'last_name', OLD.last_name, 'birth_date', OLD.birth_date,
                'birth_place', OLD.birth_place, 'phone', OLD.phone, 'gender_id', OLD.gender_id),
    NULL
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'personal_number',OLD.personal_number,NULL),(@e,'first_name',OLD.first_name,NULL),
    (@e,'father_name',OLD.father_name,NULL),(@e,'last_name',OLD.last_name,NULL),(@e,'birth_date',OLD.birth_date,NULL),
    (@e,'birth_place',OLD.birth_place,NULL),(@e,'phone',OLD.phone,NULL),(@e,'gender_id',OLD.gender_id,NULL);
END $$

/* ====================== students ====================== */
DROP TRIGGER IF EXISTS trg_audit_students_ai $$
CREATE TRIGGER trg_audit_students_ai AFTER INSERT ON students
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'students','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'person_id', NEW.person_id, 'user_id', NEW.user_id,
                'nr_amze', NEW.nr_amze, 'education_level_id', NEW.education_level_id, 'created_at', NEW.created_at)
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'person_id',NULL,NEW.person_id),(@e,'user_id',NULL,NEW.user_id),
    (@e,'nr_amze',NULL,NEW.nr_amze),(@e,'education_level_id',NULL,NEW.education_level_id),(@e,'created_at',NULL,NEW.created_at);
END $$

DROP TRIGGER IF EXISTS trg_audit_students_au $$
CREATE TRIGGER trg_audit_students_au AFTER UPDATE ON students
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'students','UPDATE', JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'person_id', OLD.person_id, 'user_id', OLD.user_id,
                'nr_amze', OLD.nr_amze, 'education_level_id', OLD.education_level_id, 'created_at', OLD.created_at),
    JSON_OBJECT('id', NEW.id, 'person_id', NEW.person_id, 'user_id', NEW.user_id,
                'nr_amze', NEW.nr_amze, 'education_level_id', NEW.education_level_id, 'created_at', NEW.created_at)
  );
  SET @e := @last_audit_event_id;
  IF NOT (OLD.person_id          <=> NEW.person_id)          THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'person_id',          OLD.person_id,          NEW.person_id);          END IF;
  IF NOT (OLD.user_id            <=> NEW.user_id)            THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'user_id',            OLD.user_id,            NEW.user_id);            END IF;
  IF NOT (OLD.nr_amze            <=> NEW.nr_amze)            THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'nr_amze',            OLD.nr_amze,            NEW.nr_amze);            END IF;
  IF NOT (OLD.education_level_id <=> NEW.education_level_id) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'education_level_id', OLD.education_level_id, NEW.education_level_id); END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_students_ad $$
CREATE TRIGGER trg_audit_students_ad AFTER DELETE ON students
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'students','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'person_id', OLD.person_id, 'user_id', OLD.user_id,
                'nr_amze', OLD.nr_amze, 'education_level_id', OLD.education_level_id, 'created_at', OLD.created_at),
    NULL
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'person_id',OLD.person_id,NULL),(@e,'user_id',OLD.user_id,NULL),
    (@e,'nr_amze',OLD.nr_amze,NULL),(@e,'education_level_id',OLD.education_level_id,NULL),(@e,'created_at',OLD.created_at,NULL);
END $$

/* ====================== agencies ====================== */
DROP TRIGGER IF EXISTS trg_audit_agencies_ai $$
CREATE TRIGGER trg_audit_agencies_ai AFTER INSERT ON agencies
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'agencies','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'user_id', NEW.user_id, 'nip_t', NEW.nip_t,
                'company_name', NEW.company_name, 'address', NEW.address, 'phone', NEW.phone)
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'user_id',NULL,NEW.user_id),(@e,'nip_t',NULL,NEW.nip_t),
    (@e,'company_name',NULL,NEW.company_name),(@e,'address',NULL,NEW.address),(@e,'phone',NULL,NEW.phone);
END $$

DROP TRIGGER IF EXISTS trg_audit_agencies_au $$
CREATE TRIGGER trg_audit_agencies_au AFTER UPDATE ON agencies
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'agencies','UPDATE', JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'user_id', OLD.user_id, 'nip_t', OLD.nip_t,
                'company_name', OLD.company_name, 'address', OLD.address, 'phone', OLD.phone),
    JSON_OBJECT('id', NEW.id, 'user_id', NEW.user_id, 'nip_t', NEW.nip_t,
                'company_name', NEW.company_name, 'address', NEW.address, 'phone', NEW.phone)
  );
  SET @e := @last_audit_event_id;
  IF NOT (OLD.user_id      <=> NEW.user_id)      THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'user_id',      OLD.user_id,      NEW.user_id);      END IF;
  IF NOT (OLD.nip_t        <=> NEW.nip_t)        THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'nip_t',        OLD.nip_t,        NEW.nip_t);        END IF;
  IF NOT (OLD.company_name <=> NEW.company_name) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'company_name', OLD.company_name, NEW.company_name); END IF;
  IF NOT (OLD.address      <=> NEW.address)      THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'address',      OLD.address,      NEW.address);      END IF;
  IF NOT (OLD.phone        <=> NEW.phone)        THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'phone',        OLD.phone,        NEW.phone);        END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_agencies_ad $$
CREATE TRIGGER trg_audit_agencies_ad AFTER DELETE ON agencies
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'agencies','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'user_id', OLD.user_id, 'nip_t', OLD.nip_t,
                'company_name', OLD.company_name, 'address', OLD.address, 'phone', OLD.phone),
    NULL
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'user_id',OLD.user_id,NULL),(@e,'nip_t',OLD.nip_t,NULL),
    (@e,'company_name',OLD.company_name,NULL),(@e,'address',OLD.address,NULL),(@e,'phone',OLD.phone,NULL);
END $$

/* ====================== courses ====================== */
DROP TRIGGER IF EXISTS trg_audit_courses_ai $$
CREATE TRIGGER trg_audit_courses_ai AFTER INSERT ON courses
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'courses','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'code', NEW.code, 'name', NEW.name, 'hours', NEW.hours, 'created_at', NEW.created_at)
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'code',NULL,NEW.code),(@e,'name',NULL,NEW.name),
    (@e,'hours',NULL,NEW.hours),(@e,'created_at',NULL,NEW.created_at);
END $$

DROP TRIGGER IF EXISTS trg_audit_courses_au $$
CREATE TRIGGER trg_audit_courses_au AFTER UPDATE ON courses
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'courses','UPDATE', JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'code', OLD.code, 'name', OLD.name, 'hours', OLD.hours, 'created_at', OLD.created_at),
    JSON_OBJECT('id', NEW.id, 'code', NEW.code, 'name', NEW.name, 'hours', NEW.hours, 'created_at', NEW.created_at)
  );
  SET @e := @last_audit_event_id;
  IF NOT (OLD.code  <=> NEW.code)  THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'code',  OLD.code,  NEW.code);  END IF;
  IF NOT (OLD.name  <=> NEW.name)  THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'name',  OLD.name,  NEW.name);  END IF;
  IF NOT (OLD.hours <=> NEW.hours) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'hours', OLD.hours, NEW.hours); END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_courses_ad $$
CREATE TRIGGER trg_audit_courses_ad AFTER DELETE ON courses
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'courses','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'code', OLD.code, 'name', OLD.name, 'hours', OLD.hours, 'created_at', OLD.created_at),
    NULL
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'code',OLD.code,NULL),(@e,'name',OLD.name,NULL),
    (@e,'hours',OLD.hours,NULL),(@e,'created_at',OLD.created_at,NULL);
END $$

/* ====================== course_groups ====================== */
DROP TRIGGER IF EXISTS trg_audit_cg_ai $$
CREATE TRIGGER trg_audit_cg_ai AFTER INSERT ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_groups','INSERT', JSON_OBJECT('id', NEW.id), NULL,
    JSON_OBJECT('id', NEW.id, 'course_id', NEW.course_id, 'start_date', NEW.start_date,
                'end_date', NEW.end_date, 'exam_date', NEW.exam_date, 'created_at', NEW.created_at)
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'course_id',NULL,NEW.course_id),(@e,'start_date',NULL,NEW.start_date),
    (@e,'end_date',NULL,NEW.end_date),(@e,'exam_date',NULL,NEW.exam_date),(@e,'created_at',NULL,NEW.created_at);
END $$

DROP TRIGGER IF EXISTS trg_audit_cg_au $$
CREATE TRIGGER trg_audit_cg_au AFTER UPDATE ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_groups','UPDATE', JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'course_id', OLD.course_id, 'start_date', OLD.start_date,
                'end_date', OLD.end_date, 'exam_date', OLD.exam_date, 'created_at', OLD.created_at),
    JSON_OBJECT('id', NEW.id, 'course_id', NEW.course_id, 'start_date', NEW.start_date,
                'end_date', NEW.end_date, 'exam_date', NEW.exam_date, 'created_at', NEW.created_at)
  );
  SET @e := @last_audit_event_id;
  IF NOT (OLD.course_id  <=> NEW.course_id)  THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'course_id',  OLD.course_id,  NEW.course_id);  END IF;
  IF NOT (OLD.start_date <=> NEW.start_date) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'start_date', OLD.start_date, NEW.start_date); END IF;
  IF NOT (OLD.end_date   <=> NEW.end_date)   THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'end_date',   OLD.end_date,   NEW.end_date);   END IF;
  IF NOT (OLD.exam_date  <=> NEW.exam_date)  THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'exam_date',  OLD.exam_date,  NEW.exam_date);  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_cg_ad $$
CREATE TRIGGER trg_audit_cg_ad AFTER DELETE ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_groups','DELETE', JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'course_id', OLD.course_id, 'start_date', OLD.start_date,
                'end_date', OLD.end_date, 'exam_date', OLD.exam_date, 'created_at', OLD.created_at),
    NULL
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'course_id',OLD.course_id,NULL),(@e,'start_date',OLD.start_date,NULL),
    (@e,'end_date',OLD.end_date,NULL),(@e,'exam_date',OLD.exam_date,NULL),(@e,'created_at',OLD.created_at,NULL);
END $$

/* ====================== course_group_students ====================== */
DROP TRIGGER IF EXISTS trg_audit_cgs_ai $$
CREATE TRIGGER trg_audit_cgs_ai AFTER INSERT ON course_group_students
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_group_students','INSERT',
    JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id),
    NULL,
    JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id,
                'final_score', NEW.final_score, 'exam_date', NEW.exam_date)
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',NULL,NEW.group_id),(@e,'student_id',NULL,NEW.student_id),
    (@e,'final_score',NULL,NEW.final_score),(@e,'exam_date',NULL,NEW.exam_date);
END $$

DROP TRIGGER IF EXISTS trg_audit_cgs_au $$
CREATE TRIGGER trg_audit_cgs_au AFTER UPDATE ON course_group_students
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_group_students','UPDATE',
    JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id),
    JSON_OBJECT('group_id', OLD.group_id, 'student_id', OLD.student_id,
                'final_score', OLD.final_score, 'exam_date', OLD.exam_date),
    JSON_OBJECT('group_id', NEW.group_id, 'student_id', NEW.student_id,
                'final_score', NEW.final_score, 'exam_date', NEW.exam_date)
  );
  SET @e := @last_audit_event_id;
  IF NOT (OLD.final_score <=> NEW.final_score) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'final_score', OLD.final_score, NEW.final_score); END IF;
  IF NOT (OLD.exam_date  <=> NEW.exam_date)  THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'exam_date',  OLD.exam_date,  NEW.exam_date);  END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_cgs_ad $$
CREATE TRIGGER trg_audit_cgs_ad AFTER DELETE ON course_group_students
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_group_students','DELETE',
    JSON_OBJECT('group_id', OLD.group_id, 'student_id', OLD.student_id),
    JSON_OBJECT('group_id', OLD.group_id, 'student_id', OLD.student_id,
                'final_score', OLD.final_score, 'exam_date', OLD.exam_date),
    NULL
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'group_id',OLD.group_id,NULL),(@e,'student_id',OLD.student_id,NULL),
    (@e,'final_score',OLD.final_score,NULL),(@e,'exam_date',OLD.exam_date,NULL);
END $$

DELIMITER ;

ALTER TABLE course_groups
  ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0
  AFTER end_date;

DELIMITER $$

/* Fshij trigger-at ekzistues për course_groups */
DROP TRIGGER IF EXISTS trg_audit_cg_ai $$
DROP TRIGGER IF EXISTS trg_audit_cg_au $$
DROP TRIGGER IF EXISTS trg_audit_cg_ad $$

/* INSERT */
CREATE TRIGGER trg_audit_cg_ai
AFTER INSERT ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_groups','INSERT',
    JSON_OBJECT('id', NEW.id),
    NULL,
    JSON_OBJECT(
      'id', NEW.id,
      'course_id', NEW.course_id,
      'start_date', NEW.start_date,
      'end_date', NEW.end_date,
      'is_completed', NEW.is_completed,
      'exam_date', NEW.exam_date,
      'created_at', NEW.created_at
    )
  );
  SET @e := @last_audit_event_id;

  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),
    (@e,'course_id',NULL,NEW.course_id),
    (@e,'start_date',NULL,NEW.start_date),
    (@e,'end_date',NULL,NEW.end_date),
    (@e,'is_completed',NULL,NEW.is_completed),
    (@e,'exam_date',NULL,NEW.exam_date),
    (@e,'created_at',NULL,NEW.created_at);
END $$

/* UPDATE */
CREATE TRIGGER trg_audit_cg_au
AFTER UPDATE ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_groups','UPDATE',
    JSON_OBJECT('id', NEW.id),
    JSON_OBJECT(
      'id', OLD.id,
      'course_id', OLD.course_id,
      'start_date', OLD.start_date,
      'end_date', OLD.end_date,
      'is_completed', OLD.is_completed,
      'exam_date', OLD.exam_date,
      'created_at', OLD.created_at
    ),
    JSON_OBJECT(
      'id', NEW.id,
      'course_id', NEW.course_id,
      'start_date', NEW.start_date,
      'end_date', NEW.end_date,
      'is_completed', NEW.is_completed,
      'exam_date', NEW.exam_date,
      'created_at', NEW.created_at
    )
  );
  SET @e := @last_audit_event_id;

  IF NOT (OLD.course_id    <=> NEW.course_id)    THEN
    INSERT INTO audit_event_fields VALUES (NULL,@e,'course_id',    OLD.course_id,    NEW.course_id);
  END IF;
  IF NOT (OLD.start_date   <=> NEW.start_date)   THEN
    INSERT INTO audit_event_fields VALUES (NULL,@e,'start_date',   OLD.start_date,   NEW.start_date);
  END IF;
  IF NOT (OLD.end_date     <=> NEW.end_date)     THEN
    INSERT INTO audit_event_fields VALUES (NULL,@e,'end_date',     OLD.end_date,     NEW.end_date);
  END IF;
  IF NOT (OLD.is_completed <=> NEW.is_completed) THEN
    INSERT INTO audit_event_fields VALUES (NULL,@e,'is_completed', OLD.is_completed, NEW.is_completed);
  END IF;
  IF NOT (OLD.exam_date    <=> NEW.exam_date)    THEN
    INSERT INTO audit_event_fields VALUES (NULL,@e,'exam_date',    OLD.exam_date,    NEW.exam_date);
  END IF;
END $$

/* DELETE */
CREATE TRIGGER trg_audit_cg_ad
AFTER DELETE ON course_groups
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'course_groups','DELETE',
    JSON_OBJECT('id', OLD.id),
    JSON_OBJECT(
      'id', OLD.id,
      'course_id', OLD.course_id,
      'start_date', OLD.start_date,
      'end_date', OLD.end_date,
      'is_completed', OLD.is_completed,
      'exam_date', OLD.exam_date,
      'created_at', OLD.created_at
    ),
    NULL
  );
  SET @e := @last_audit_event_id;

  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),
    (@e,'course_id',OLD.course_id,NULL),
    (@e,'start_date',OLD.start_date,NULL),
    (@e,'end_date',OLD.end_date,NULL),
    (@e,'is_completed',OLD.is_completed,NULL),
    (@e,'exam_date',OLD.exam_date,NULL),
    (@e,'created_at',OLD.created_at,NULL);
END $$

DELIMITER ;

-- =========================================
-- STUDENT_COURSE_PLANS: Zgjedhje moduli pa grup
-- =========================================
CREATE TABLE IF NOT EXISTS student_course_plans (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_id    INT NOT NULL,
  course_id     INT NOT NULL,
  -- status i thjeshtë; mjafton 'planned' -> 'assigned' -> 'cancelled'/'completed'
  status        ENUM('planned','assigned','cancelled','completed') NOT NULL DEFAULT 'planned',
  group_id      INT NULL,                    -- vendoset kur e cakton në një grup
  selected_by   INT NULL,                    -- kush e regjistroi (users.id)
  selected_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_at   TIMESTAMP NULL,
  note          VARCHAR(255) NULL,

  CONSTRAINT fk_scp_student  FOREIGN KEY (student_id) REFERENCES students(id)      ON DELETE CASCADE,
  CONSTRAINT fk_scp_course   FOREIGN KEY (course_id) REFERENCES courses(id)        ON DELETE CASCADE,
  CONSTRAINT fk_scp_group    FOREIGN KEY (group_id)  REFERENCES course_groups(id)  ON DELETE SET NULL,
  CONSTRAINT fk_scp_user     FOREIGN KEY (selected_by) REFERENCES users(id)        ON DELETE SET NULL,

  -- një student s’mund të ketë dy rreshta për të njëjtin modul
  UNIQUE KEY uq_scp_student_course (student_id, course_id),
  KEY idx_scp_status (status),
  KEY idx_scp_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DELIMITER $$

DROP TRIGGER IF EXISTS trg_scp_validate_group_bu $$
CREATE TRIGGER trg_scp_validate_group_bu
BEFORE UPDATE ON student_course_plans
FOR EACH ROW
BEGIN
  IF NEW.group_id IS NOT NULL THEN
    IF NOT EXISTS (
      SELECT 1
      FROM course_groups cg
      WHERE cg.id = NEW.group_id
        AND cg.course_id = NEW.course_id
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'group_id nuk i përket course_id të kësaj zgjedhjeje.';
    END IF;
  END IF;

  -- status 'assigned' kërkon group_id
  IF NEW.status = 'assigned' AND NEW.group_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Status=assigned kërkon group_id.';
  END IF;
END $$

DELIMITER ;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_cgs_after_insert_ai $$
CREATE TRIGGER trg_cgs_after_insert_ai
AFTER INSERT ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE v_course_id INT;
  SELECT course_id INTO v_course_id FROM course_groups WHERE id = NEW.group_id;

  -- nëse ka plan për (student, modul), e lidhim dhe e kalojmë në assigned
  IF EXISTS (SELECT 1
             FROM student_course_plans scp
             WHERE scp.student_id = NEW.student_id AND scp.course_id = v_course_id)
  THEN
    UPDATE student_course_plans
    SET group_id = NEW.group_id,
        status   = 'assigned',
        assigned_at = NOW()
    WHERE student_id = NEW.student_id
      AND course_id  = v_course_id;
  ELSE
    -- nëse s’ka plan, e krijojmë automatikisht si 'assigned'
    INSERT INTO student_course_plans (student_id, course_id, status, group_id, assigned_at)
    VALUES (NEW.student_id, v_course_id, 'assigned', NEW.group_id, NOW());
  END IF;
END $$

DELIMITER ;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_audit_scp_ai $$
CREATE TRIGGER trg_audit_scp_ai
AFTER INSERT ON student_course_plans
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'student_course_plans','INSERT',
    JSON_OBJECT('id', NEW.id),
    NULL,
    JSON_OBJECT('id', NEW.id, 'student_id', NEW.student_id, 'course_id', NEW.course_id,
                'status', NEW.status, 'group_id', NEW.group_id, 'selected_by', NEW.selected_by,
                'selected_at', NEW.selected_at, 'assigned_at', NEW.assigned_at, 'note', NEW.note)
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',NULL,NEW.id),(@e,'student_id',NULL,NEW.student_id),(@e,'course_id',NULL,NEW.course_id),
    (@e,'status',NULL,NEW.status),(@e,'group_id',NULL,NEW.group_id),
    (@e,'selected_by',NULL,NEW.selected_by),(@e,'selected_at',NULL,NEW.selected_at),
    (@e,'assigned_at',NULL,NEW.assigned_at),(@e,'note',NULL,NEW.note);
END $$

DROP TRIGGER IF EXISTS trg_audit_scp_au $$
CREATE TRIGGER trg_audit_scp_au
AFTER UPDATE ON student_course_plans
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'student_course_plans','UPDATE',
    JSON_OBJECT('id', NEW.id),
    JSON_OBJECT('id', OLD.id, 'student_id', OLD.student_id, 'course_id', OLD.course_id,
                'status', OLD.status, 'group_id', OLD.group_id, 'selected_by', OLD.selected_by,
                'selected_at', OLD.selected_at, 'assigned_at', OLD.assigned_at, 'note', OLD.note),
    JSON_OBJECT('id', NEW.id, 'student_id', NEW.student_id, 'course_id', NEW.course_id,
                'status', NEW.status, 'group_id', NEW.group_id, 'selected_by', NEW.selected_by,
                'selected_at', NEW.selected_at, 'assigned_at', NEW.assigned_at, 'note', NEW.note)
  );
  SET @e := @last_audit_event_id;
  IF NOT (OLD.student_id <=> NEW.student_id) THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'student_id', OLD.student_id, NEW.student_id); END IF;
  IF NOT (OLD.course_id  <=> NEW.course_id)  THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'course_id',  OLD.course_id,  NEW.course_id);  END IF;
  IF NOT (OLD.status     <=> NEW.status)     THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'status',     OLD.status,     NEW.status);     END IF;
  IF NOT (OLD.group_id   <=> NEW.group_id)   THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'group_id',   OLD.group_id,   NEW.group_id);   END IF;
  IF NOT (OLD.selected_by<=> NEW.selected_by)THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'selected_by',OLD.selected_by,NEW.selected_by);END IF;
  IF NOT (OLD.assigned_at<=> NEW.assigned_at)THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'assigned_at',OLD.assigned_at,NEW.assigned_at);END IF;
  IF NOT (OLD.note       <=> NEW.note)       THEN INSERT INTO audit_event_fields VALUES (NULL,@e,'note',       OLD.note,       NEW.note);       END IF;
END $$

DROP TRIGGER IF EXISTS trg_audit_scp_ad $$
CREATE TRIGGER trg_audit_scp_ad
AFTER DELETE ON student_course_plans
FOR EACH ROW
BEGIN
  CALL audit_capture(
    'student_course_plans','DELETE',
    JSON_OBJECT('id', OLD.id),
    JSON_OBJECT('id', OLD.id, 'student_id', OLD.student_id, 'course_id', OLD.course_id,
                'status', OLD.status, 'group_id', OLD.group_id, 'selected_by', OLD.selected_by,
                'selected_at', OLD.selected_at, 'assigned_at', OLD.assigned_at, 'note', OLD.note),
    NULL
  );
  SET @e := @last_audit_event_id;
  INSERT INTO audit_event_fields (event_id, column_name, old_value, new_value) VALUES
    (@e,'id',OLD.id,NULL),(@e,'student_id',OLD.student_id,NULL),(@e,'course_id',OLD.course_id,NULL),
    (@e,'status',OLD.status,NULL),(@e,'group_id',OLD.group_id,NULL),
    (@e,'selected_by',OLD.selected_by,NULL),(@e,'selected_at',OLD.selected_at,NULL),
    (@e,'assigned_at',OLD.assigned_at,NULL),(@e,'note',OLD.note,NULL);
END $$

DELIMITER ;


DROP TABLE IF EXISTS person_qr_tokens;

CREATE TABLE person_qr_tokens (
  person_id INT         NOT NULL,
  token     CHAR(32)    NOT NULL,                       -- bin2hex(random_bytes(16)) => 32 hex
  created_at TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id),
  UNIQUE KEY uq_pqt_token (token),
  CONSTRAINT fk_pqt_person
    FOREIGN KEY (person_id) REFERENCES persons(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- b) Token 32-hex nga MD5(UUID() || RAND() || NOW() || person_id)
INSERT INTO person_qr_tokens (person_id, token, created_at)
SELECT p.id, LOWER(MD5(CONCAT(UUID(), RAND(), NOW(), p.id))), NOW()
FROM persons p
LEFT JOIN person_qr_tokens q ON q.person_id = p.id
WHERE q.person_id IS NULL;

INSERT IGNORE INTO person_qr_tokens (person_id, token, created_at)
SELECT
  s.person_id,
  SUBSTRING_INDEX(GROUP_CONCAT(t.token ORDER BY t.created_at ASC SEPARATOR ','), ',', 1) AS token,
  MIN(t.created_at) AS created_at
FROM student_qr_tokens t
JOIN students s ON s.id = t.student_id
GROUP BY s.person_id;
