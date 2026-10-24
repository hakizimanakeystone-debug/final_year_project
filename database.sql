CREATE DATABASE IF NOT EXISTS `final_year_project`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `final_year_project`;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('HOD','LECTURER') NOT NULL DEFAULT 'LECTURER',
  department VARCHAR(150) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reg_number VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  phone_number VARCHAR(30) NOT NULL,
  department VARCHAR(150) NOT NULL,
  class_level VARCHAR(50) NOT NULL DEFAULT 'Year 1',
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS marks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  lecturer_id INT NOT NULL,
  academic_year VARCHAR(10) NOT NULL,
  semester TINYINT NOT NULL,
  subject_code VARCHAR(40) NOT NULL,
  subject_name VARCHAR(150) NOT NULL,
  marks DECIMAL(5,2) NOT NULL,
  status ENUM('PENDING','PUBLISHED') NOT NULL DEFAULT 'PENDING',
  approved_by INT NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_lecturer FOREIGN KEY (lecturer_id) REFERENCES users(id),
  CONSTRAINT fk_marks_approver FOREIGN KEY (approved_by) REFERENCES users(id),
  CONSTRAINT chk_marks_range CHECK (marks >= 0 AND marks <= 100),
  INDEX idx_marks_lookup (student_id, academic_year, semester, status)
);

CREATE TABLE IF NOT EXISTS notification_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  mark_batch VARCHAR(30) NOT NULL,
  phone_number VARCHAR(30) NOT NULL,
  message_text TEXT NOT NULL,
  delivery_status VARCHAR(30) NOT NULL DEFAULT 'NOT_CONFIGURED',
  provider_response TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Login accounts are created in index.php on first run with these demo credentials:
-- HOD: hod / Hod@12345
-- ICT lecturer: ict_lecturer / Lec@12345
