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
