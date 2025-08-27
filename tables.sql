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
