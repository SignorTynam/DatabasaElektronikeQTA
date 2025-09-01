-- =========================================================
-- (OPSIONALE) Fshi DB ekzistuese dhe krijo nga e para
-- =========================================================
-- DROP DATABASE IF EXISTS qta_db;

-- =========================================================
-- Krijo DB dhe kalimi te skema
-- =========================================================
CREATE DATABASE IF NOT EXISTS qta_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE qta_db;

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
