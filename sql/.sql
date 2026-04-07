CREATE DATABASE IF NOT EXISTS attendance_system;
USE attendance_system;

-- 1. CAMPUS
CREATE TABLE campus (
  campus_id INT AUTO_INCREMENT PRIMARY KEY,
  campus_name VARCHAR(100) NOT NULL,
  campus_code VARCHAR(20) NOT NULL UNIQUE,
  address VARCHAR(255),
  city VARCHAR(100),
  country VARCHAR(100) DEFAULT 'Somalia',
  phone_number VARCHAR(20),
  email VARCHAR(100),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. FACULTIES
CREATE TABLE faculties (
  faculty_id INT AUTO_INCREMENT PRIMARY KEY,
  campus_id INT NOT NULL,
  faculty_name VARCHAR(100) NOT NULL,
  faculty_code VARCHAR(20) NOT NULL UNIQUE,
  dean_name VARCHAR(100),
  phone_number VARCHAR(20),
  email VARCHAR(100),
  office_address VARCHAR(255),
  profile_photo_path VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (campus_id) REFERENCES campus(campus_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. FACULTY-CAMPUS
CREATE TABLE faculty_campus (
  id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT NOT NULL,
  campus_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (campus_id) REFERENCES campus(campus_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. DEPARTMENTS
CREATE TABLE departments (
  department_id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT NOT NULL,
  department_name VARCHAR(100) NOT NULL,
  department_code VARCHAR(20) NOT NULL UNIQUE,
  head_of_department VARCHAR(100),
  phone_number VARCHAR(20),
  email VARCHAR(100),
  office_location VARCHAR(255),
  profile_photo_path VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ROOMS
CREATE TABLE rooms (
  room_id INT AUTO_INCREMENT PRIMARY KEY,
  campus_id INT,
  faculty_id INT,
  department_id INT,
  building_name VARCHAR(100),
  floor_no VARCHAR(10),
  room_name VARCHAR(100) NOT NULL,
  room_code VARCHAR(20) NOT NULL UNIQUE,
  capacity INT DEFAULT 0,
  room_type ENUM('Lecture','Lab','Seminar','Office','Online') DEFAULT 'Lecture',
  description VARCHAR(255),
  status ENUM('available','maintenance','inactive') DEFAULT 'available',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (campus_id) REFERENCES campus(campus_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(department_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. TEACHERS
CREATE TABLE teachers (
  teacher_id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_uuid CHAR(36) NOT NULL UNIQUE,
  teacher_name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  phone_number VARCHAR(20),
  gender ENUM('male','female') DEFAULT 'male',
  qualification VARCHAR(100),
  position_title VARCHAR(100),
  profile_photo_path VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. STUDENTS
CREATE TABLE students (
  student_id INT AUTO_INCREMENT PRIMARY KEY,
  student_uuid CHAR(36) NOT NULL UNIQUE,
  campus_id INT,
  faculty_id INT,
  department_id INT,
  reg_no VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  gender ENUM('male','female') DEFAULT 'male',
  dob DATE,
  phone_number VARCHAR(20),
  email VARCHAR(100) UNIQUE,
  address VARCHAR(255),
  photo_path VARCHAR(255),
  section_id INT,
  semester_id INT,
  guardian_name VARCHAR(100),
  guardian_phone VARCHAR(20),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (campus_id) REFERENCES campus(campus_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(department_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. PARENTS
CREATE TABLE parents (
  parent_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  gender ENUM('male','female') DEFAULT 'male',
  phone VARCHAR(20) UNIQUE,
  email VARCHAR(100),
  address VARCHAR(255),
  occupation VARCHAR(100),
  photo_path VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. PARENT_STUDENT
CREATE TABLE parent_student (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NOT NULL,
  student_id INT NOT NULL,
  relation_type ENUM('father','mother','guardian','other') DEFAULT 'guardian',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES parents(parent_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE faculty_campus (
    id INT PRIMARY KEY AUTO_INCREMENT,
    faculty_id INT NOT NULL,
    campus_id INT NOT NULL,
    assigned_date DATE NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE CASCADE,
    FOREIGN KEY (campus_id) REFERENCES campus(campus_id) ON DELETE CASCADE,
    UNIQUE KEY unique_faculty_campus (faculty_id, campus_id)
);

CREATE INDEX idx_faculty_campus_faculty ON faculty_campus(faculty_id);
CREATE INDEX idx_faculty_campus_campus ON faculty_campus(campus_id);
CREATE INDEX idx_faculty_campus_status ON faculty_campus(status);
-- 10. USERS
CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  user_uuid CHAR(36) NOT NULL UNIQUE,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(100) UNIQUE,
  phone_number VARCHAR(20),
  profile_photo_path VARCHAR(255),
  password VARCHAR(255) NOT NULL,
  role ENUM('super_admin','campus_admin','faculty_admin','department_admin','teacher','student','parent','auditor') NOT NULL,
  linked_id INT,
  linked_table ENUM('campus','faculty','department','teacher','student','parent','auditor'),
  last_login DATETIME,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. ACADEMIC_YEAR
CREATE TABLE academic_year (
  academic_year_id INT AUTO_INCREMENT PRIMARY KEY,
  year_name VARCHAR(20) NOT NULL UNIQUE,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. ACADEMIC_TERM
CREATE TABLE academic_term (
  academic_term_id INT AUTO_INCREMENT PRIMARY KEY,
  academic_year_id INT NOT NULL,
  term_name ENUM('A','B') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (academic_year_id) REFERENCES academic_year(academic_year_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. SEMESTER
CREATE TABLE semester (
  semester_id INT AUTO_INCREMENT PRIMARY KEY,
  semester_name VARCHAR(50) UNIQUE NOT NULL,
  description VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. CLASS_SECTION
CREATE TABLE class_section (
  section_id INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT,
  section_name VARCHAR(50) NOT NULL,
  capacity INT DEFAULT 0,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(department_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  UNIQUE KEY unique_section (department_id, section_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. SUBJECT
CREATE TABLE subject (
  subject_id INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT,
  semester_id INT,
  subject_name VARCHAR(100) NOT NULL,
  subject_code VARCHAR(20) UNIQUE NOT NULL,
  credit_hours INT DEFAULT 3,
  description VARCHAR(255),
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(department_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (semester_id) REFERENCES semester(semester_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. STUDENT_ENROLL
CREATE TABLE student_enroll (
  enroll_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  section_id INT,
  semester_id INT,
  academic_term_id INT NOT NULL,
  status ENUM('active','dropped','completed') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_enroll (student_id, subject_id, academic_term_id),
  FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subject(subject_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES class_section(section_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (semester_id) REFERENCES semester(semester_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (academic_term_id) REFERENCES academic_term(academic_term_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. ROOM_ALLOCATION
CREATE TABLE room_allocation (
  allocation_id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  academic_term_id INT NOT NULL,
  department_id INT,
  allocated_to ENUM('Class','Exam','Maintenance') DEFAULT 'Class',
  start_date DATE NOT NULL,
  end_date DATE,
  remarks VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_room (room_id, academic_term_id, allocated_to, start_date),
  FOREIGN KEY (room_id) REFERENCES rooms(room_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (academic_term_id) REFERENCES academic_term(academic_term_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(department_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. TIMETABLE
DROP TABLE IF EXISTS timetable;

CREATE TABLE timetable (
  timetable_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  section_id INT NOT NULL,
  teacher_id INT,
  room_id INT,
  academic_term_id INT NOT NULL,
  day_of_week ENUM('Sun','Mon','Tue','Wed','Thu','Fri','Sat') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  status ENUM('active','inactive','cancelled') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_timetable_subject FOREIGN KEY (subject_id)
    REFERENCES subject(subject_id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_timetable_section FOREIGN KEY (section_id)
    REFERENCES class_section(section_id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_timetable_teacher FOREIGN KEY (teacher_id)
    REFERENCES teachers(teacher_id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  CONSTRAINT fk_timetable_room FOREIGN KEY (room_id)
    REFERENCES rooms(room_id)
    ON UPDATE CASCADE ON DELETE SET NULL,

  CONSTRAINT fk_timetable_term FOREIGN KEY (academic_term_id)
    REFERENCES academic_term(academic_term_id)
    ON UPDATE CASCADE ON DELETE CASCADE,

  INDEX idx_timetable_day (day_of_week),
  UNIQUE KEY unique_schedule (section_id, day_of_week, start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. ATTENDANCE
CREATE TABLE attendance (
  attendance_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  section_id INT,
  teacher_id INT,
  room_id INT,
  academic_term_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent','late','excused') DEFAULT 'present',
  remarks VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_attendance (student_id, subject_id, attendance_date),
  FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subject(subject_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES class_section(section_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(room_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  FOREIGN KEY (academic_term_id) REFERENCES academic_term(academic_term_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. ATTENDANCE_AUDIT
CREATE TABLE attendance_audit (
  audit_id INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT NOT NULL,
  changed_by INT,
  old_status ENUM('present','absent','late','excused'),
  new_status ENUM('present','absent','late','excused'),
  reason VARCHAR(255),
  change_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (attendance_id) REFERENCES attendance(attendance_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. AUDIT_LOG
CREATE TABLE audit_log (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  action_type VARCHAR(50) NOT NULL,
  description TEXT NOT NULL,
  ip_address VARCHAR(45),
  user_agent VARCHAR(255),
  action_time DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. ANNOUNCEMENT
CREATE TABLE announcement (
  announcement_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  image_path VARCHAR(255),
  created_by INT,
  target_role ENUM('all','student','teacher','parent','admin') DEFAULT 'all',
  status ENUM('active','inactive') DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE attendance 
ADD COLUMN locked TINYINT(1) DEFAULT 0 AFTER status,
ADD COLUMN locked_by INT NULL AFTER locked,
ADD COLUMN locked_at DATETIME NULL AFTER locked_by,
ADD FOREIGN KEY (locked_by) REFERENCES users(user_id) 
  ON UPDATE CASCADE ON DELETE SET NULL;
CREATE TABLE programs (
    program_id INT AUTO_INCREMENT PRIMARY KEY,
    
    campus_id INT NOT NULL,
    faculty_id INT NULL,
    department_id INT NULL,
    
    program_name VARCHAR(100) NOT NULL,
    program_code VARCHAR(20) NOT NULL,
    
    duration_years INT NOT NULL DEFAULT 4,
    description TEXT NULL,
    
    status ENUM('active', 'inactive') DEFAULT 'active',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    CONSTRAINT fk_program_campus 
        FOREIGN KEY (campus_id) REFERENCES campus(campus_id)
        ON DELETE CASCADE,
        
    CONSTRAINT fk_program_faculty 
        FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id)
        ON DELETE SET NULL,
        
    CONSTRAINT fk_program_department 
        FOREIGN KEY (department_id) REFERENCES departments(department_id)
        ON DELETE SET NULL,
        
    -- Prevent duplicate program in same campus
    UNIQUE KEY unique_program_per_campus (program_name, campus_id),
    UNIQUE KEY unique_code_per_campus (program_code, campus_id)
);
-- ✅ SUCCESS: 23tables created successfully for attendance_system
