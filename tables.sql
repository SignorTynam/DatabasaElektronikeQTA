CREATE DATABASE IF NOT EXISTS qta_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE qta_db;

-- Roles
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Users (header i përbashkët)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  full_name VARCHAR(200),
  email VARCHAR(200) UNIQUE, -- përdoret nga administratorët (studentët/agjencitë mund të kenë NULL)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Credentials
CREATE TABLE IF NOT EXISTS credentials (
  user_id INT PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL,
  last_password_change DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Agencies
CREATE TABLE IF NOT EXISTS agencies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNIQUE NOT NULL,
  nip_t VARCHAR(100) NOT NULL UNIQUE,
  company_name VARCHAR(200),
  address TEXT,
  phone VARCHAR(50),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Education Levels (3NF për "arsimi")
CREATE TABLE IF NOT EXISTS education_levels (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  code  VARCHAR(20)  NOT NULL UNIQUE,   -- AU/AM/AL
  label VARCHAR(100) NOT NULL UNIQUE    -- "Arsimi i ulët/mesëm/lartë"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Students (me fushat e kërkuara)
CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,           -- ruhet për menaxhim DB
  user_id INT UNIQUE NOT NULL,
  first_name        VARCHAR(100) NOT NULL,     -- Emër
  father_name       VARCHAR(100) NULL,         -- Atësi
  last_name         VARCHAR(100) NOT NULL,     -- Mbiemër
  birth_date        DATE NULL,                 -- Datëlindje
  birth_place       VARCHAR(150) NULL,         -- Vendilindje
  nr_amze           VARCHAR(100) NOT NULL UNIQUE,   -- Nr. amzë (unik)
  personal_number   VARCHAR(100) NOT NULL UNIQUE,   -- ID e kartës (unik)
  education_level_id INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (education_level_id) REFERENCES education_levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Admins
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNIQUE NOT NULL,
  employee_code VARCHAR(100) UNIQUE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rolet default
INSERT IGNORE INTO roles (name) VALUES ('administrator'), ('agjencia'), ('student');

-- Seed për education_levels sipas kërkesës
INSERT IGNORE INTO education_levels (code, label) VALUES
('AU', 'Arsimi i ulët'),
('AM', 'Arsimi i mesëm'),
('AL', 'Arsimi i lartë');

-- Indeks për kërkim të shpejtë sipas emrit
CREATE INDEX idx_students_name ON students (last_name, first_name);

-- Modulet (3NF: entitet i vetëm, atribute atomike, pa varësi transitive)
CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code  VARCHAR(50)  NOT NULL UNIQUE,      -- KOD (unik, p.sh. QTA-ALGO)
  name  VARCHAR(200) NOT NULL,             -- EMËR
  hours SMALLINT UNSIGNED NOT NULL,        -- ORE (>=1)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Kërkime më të shpejta sipas emrit
CREATE INDEX idx_courses_name ON courses (name);

USE qta_db;

CREATE TABLE IF NOT EXISTS course_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  exam_date  DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_course_groups_course
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT chk_dates_order
    CHECK (end_date >= start_date),
  CONSTRAINT chk_exam_after_end
    CHECK (exam_date IS NULL OR exam_date >= end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_course_groups_course ON course_groups(course_id);
CREATE INDEX idx_course_groups_dates  ON course_groups(start_date, end_date);

CREATE TABLE IF NOT EXISTS course_group_students (
  group_id   INT NOT NULL,
  student_id INT NOT NULL,
  final_score DECIMAL(5,2) NULL,
  PRIMARY KEY (group_id, student_id),
  CONSTRAINT fk_cgs_group   FOREIGN KEY (group_id) REFERENCES course_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_cgs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX idx_cgs_student ON course_group_students(student_id);

DELIMITER $$

CREATE TRIGGER trg_cgs_before_insert
BEFORE INSERT ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE cnt INT;
  SELECT COUNT(*) INTO cnt FROM course_group_students WHERE group_id = NEW.group_id;
  IF cnt >= 10 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ky grup ka arritur kufirin prej 10 studentësh.';
  END IF;
END $$

CREATE TRIGGER trg_cgs_before_insert_grade
BEFORE INSERT ON course_group_students
FOR EACH ROW
BEGIN
  IF NEW.final_score IS NOT NULL THEN
    IF (SELECT exam_date FROM course_groups WHERE id = NEW.group_id) IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nuk mund të vendoset nota pa përcaktuar datën e testit të grupit.';
    END IF;
  END IF;
END $$

CREATE TRIGGER trg_cgs_before_update_grade
BEFORE UPDATE ON course_group_students
FOR EACH ROW
BEGIN
  IF NEW.final_score IS NOT NULL AND (OLD.final_score IS NULL OR NEW.final_score <> OLD.final_score) THEN
    IF (SELECT exam_date FROM course_groups WHERE id = NEW.group_id) IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nuk mund të vendoset nota pa përcaktuar datën e testit të grupit.';
    END IF;
  END IF;
END $$

CREATE TRIGGER trg_cg_before_update_dates
BEFORE UPDATE ON course_groups
FOR EACH ROW
BEGIN
  IF NEW.end_date < NEW.start_date THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data e mbarimit duhet të jetë ≥ datës së fillimit.';
  END IF;
  IF NEW.exam_date IS NOT NULL AND NEW.exam_date < NEW.end_date THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data e testit duhet të jetë ≥ datës së mbarimit.';
  END IF;
END $$

DELIMITER ;


ALTER TABLE students
  MODIFY first_name       VARCHAR(100) NULL,
  MODIFY father_name      VARCHAR(100) NULL,
  MODIFY last_name        VARCHAR(100) NULL,
  MODIFY personal_number  VARCHAR(100) NULL;

USE qta_db;

-- Studentët i përkasin një (ose asnjë) agjencie në një moment (One-to-Many)
CREATE TABLE IF NOT EXISTS agency_students (
  agency_id  INT NOT NULL,
  student_id INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),                    -- çdo student maksimumi në 1 agjenci
  KEY idx_agency_students_agency (agency_id),
  CONSTRAINT fk_agency_students_agency  FOREIGN KEY (agency_id)  REFERENCES agencies(id) ON DELETE CASCADE,
  CONSTRAINT fk_agency_students_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

USE qta_db;

CREATE TABLE IF NOT EXISTS student_qr_tokens (
  student_id INT NOT NULL PRIMARY KEY,
  token      CHAR(32) NOT NULL UNIQUE,  -- p.sh. 32-hex nga random_bytes(16)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_qr_student FOREIGN KEY (student_id)
    REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

USE qta_db;

DELIMITER $$

/* Hiq trigger-at e vjetër që mbështeteshin te cg.exam_date (nëse ekzistojnë) */
DROP TRIGGER IF EXISTS trg_cgs_before_insert_grade $$
DROP TRIGGER IF EXISTS trg_cgs_before_update_grade $$

/* Për të qenë idempotent, hiq edhe versionet e mëparshme të këtyre dy triggers nëse i provove më parë */
DROP TRIGGER IF EXISTS trg_cgs_examdate_before_insert $$
DROP TRIGGER IF EXISTS trg_cgs_examdate_before_update $$

/* INSERT: validon exam_date ≥ end_date të grupit dhe kërkon exam_date kur vendoset notë */
CREATE TRIGGER trg_cgs_examdate_before_insert
BEFORE INSERT ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE ed DATE;

  /* Nëse po vendoset exam_date, sigurohu që është ≥ end_date e grupit */
  IF NEW.exam_date IS NOT NULL THEN
    SELECT end_date INTO ed FROM course_groups WHERE id = NEW.group_id;
    IF ed IS NOT NULL AND NEW.exam_date < ed THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Data e testit duhet të jetë ≥ datës së mbarimit të grupit.';
    END IF;
  END IF;

  /* Nëse po vendoset notë, kërko exam_date per-student */
  IF NEW.final_score IS NOT NULL AND NEW.exam_date IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Nuk mund të vendoset nota pa datën e testit për studentin.';
  END IF;
END $$

/* UPDATE: kur ndryshon exam_date → kontrollo ≥ end_date; kur vendoset notë → kërko exam_date */
CREATE TRIGGER trg_cgs_examdate_before_update
BEFORE UPDATE ON course_group_students
FOR EACH ROW
BEGIN
  DECLARE ed2 DATE;

  /* Kur ndryshon exam_date, verifiko kundrejt end_date të grupit */
  IF (OLD.exam_date IS NULL AND NEW.exam_date IS NOT NULL)
     OR (OLD.exam_date IS NOT NULL AND NEW.exam_date <> OLD.exam_date) THEN
    SELECT end_date INTO ed2 FROM course_groups WHERE id = NEW.group_id;
    IF ed2 IS NOT NULL AND NEW.exam_date < ed2 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Data e testit duhet të jetë ≥ datës së mbarimit të grupit.';
    END IF;
  END IF;

  /* Nota → kërkon exam_date të vendosur */
  IF NEW.final_score IS NOT NULL
     AND (OLD.final_score IS NULL OR NEW.final_score <> OLD.final_score)
     AND NEW.exam_date IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Nuk mund të vendoset nota pa datën e testit për studentin.';
  END IF;
END $$

DELIMITER ;

USE qta_db;

-- 1) Domain table për gjininë (3NF)
CREATE TABLE IF NOT EXISTS genders (
  id    TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code  CHAR(1)      NOT NULL UNIQUE,      -- 'M', 'F', 'N', 'U'
  label VARCHAR(50)  NOT NULL UNIQUE       -- 'Mashkull', 'Femër', ...
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO genders (code, label) VALUES
  ('M','Mashkull'),
  ('F','Femër')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- 2) Shto kolonën referuese në students (pa default fillimisht)
ALTER TABLE students
  ADD COLUMN gender_id TINYINT UNSIGNED NULL,
  ADD CONSTRAINT fk_students_gender FOREIGN KEY (gender_id) REFERENCES genders(id);

-- 3) Vendos default-in: Mashkull
SET @male := (SELECT id FROM genders WHERE code='M');

-- Për studentët ekzistues pa vlerë: cakto Mashkull
UPDATE students SET gender_id = @male WHERE gender_id IS NULL;

-- Tani bëje NOT NULL me default Mashkull
ALTER TABLE students
  MODIFY gender_id TINYINT UNSIGNED NOT NULL DEFAULT @male;

-- (opsionale) indeks
CREATE INDEX idx_students_gender ON students(gender_id);
