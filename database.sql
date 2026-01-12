-- Create database
CREATE DATABASE IF NOT EXISTS dtc_lab;
USE dtc_lab;

-- 1. patients table
CREATE TABLE IF NOT EXISTS patients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_code VARCHAR(20) UNIQUE NOT NULL,
    cnic VARCHAR(15) UNIQUE AFTER patient_code,
    full_name VARCHAR(200) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    age INT,
    gender ENUM('Male', 'Female', 'Other'),
    address TEXT,
    registered_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_visit_date TIMESTAMP NULL,
    INDEX idx_phone (phone),
    INDEX idx_cnic (cnic);
    INDEX idx_patient_code (patient_code)
);

-- 2. tests table
CREATE TABLE IF NOT EXISTS tests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    test_code VARCHAR(50) UNIQUE NOT NULL,
    test_name VARCHAR(200) NOT NULL,
    category VARCHAR(100),
    sample_type VARCHAR(100),
    fasting_required BOOLEAN DEFAULT FALSE,
    turnaround_hours INT DEFAULT 24,
    is_active BOOLEAN DEFAULT TRUE,
    instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. test_parameters table
CREATE TABLE IF NOT EXISTS test_parameters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    test_id INT NOT NULL,
    parameter_name VARCHAR(200) NOT NULL,
    parameter_code VARCHAR(50) NOT NULL,
    unit VARCHAR(50),
    normal_min DECIMAL(10,2),
    normal_max DECIMAL(10,2),
    male_min DECIMAL(10,2),
    male_max DECIMAL(10,2),
    female_min DECIMAL(10,2),
    female_max DECIMAL(10,2),
    child_min DECIMAL(10,2),
    child_max DECIMAL(10,2),
    adult_min DECIMAL(10,2),
    adult_max DECIMAL(10,2),
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
    INDEX idx_test_id (test_id)
);

-- 4. orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    patient_id INT NOT NULL,
    order_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    referred_by VARCHAR(200),
    clinical_notes TEXT,
    status ENUM('pending', 'sample-collected', 'processing', 
                'completed', 'cancelled') DEFAULT 'pending',
    result_ready_date DATETIME NULL,
    report_path VARCHAR(500),
    collected_by INT,
    processed_by INT,
    verified_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    INDEX idx_order_number (order_number),
    INDEX idx_status (status),
    INDEX idx_order_date (order_date)
);

-- 5. order_tests table
CREATE TABLE IF NOT EXISTS order_tests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    test_id INT NOT NULL,
    sample_id VARCHAR(100) UNIQUE,
    sample_collected_at DATETIME,
    sample_type VARCHAR(100),
    status ENUM('pending', 'sample-collected', 'processing', 
                'results-entered', 'verified', 'completed') DEFAULT 'pending',
    priority ENUM('normal', 'urgent') DEFAULT 'normal',
    notes TEXT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (test_id) REFERENCES tests(id),
    INDEX idx_sample_id (sample_id),
    INDEX idx_status (status)
);

-- 6. test_results table
CREATE TABLE IF NOT EXISTS test_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_test_id INT NOT NULL,
    parameter_id INT NOT NULL,
    result_value VARCHAR(500) NOT NULL,
    result_unit VARCHAR(50),
    flag ENUM('Normal', 'Low', 'High', 'Abnormal') DEFAULT 'Normal',
    reference_range VARCHAR(100),
    notes TEXT,
    entered_by INT NOT NULL,
    entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_by INT,
    verified_at DATETIME,
    FOREIGN KEY (order_test_id) REFERENCES order_tests(id) ON DELETE CASCADE,
    FOREIGN KEY (parameter_id) REFERENCES test_parameters(id),
    UNIQUE KEY uk_order_parameter (order_test_id, parameter_id),
    INDEX idx_order_test (order_test_id)
);

-- 7. staff table
CREATE TABLE IF NOT EXISTS staff (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    role ENUM('receptionist', 'technician', 'manager') NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. reports table
CREATE TABLE IF NOT EXISTS reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    report_number VARCHAR(50) UNIQUE NOT NULL,
    report_path VARCHAR(500) NOT NULL,
    generated_by INT NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_by INT,
    delivered_at DATETIME,
    delivery_method ENUM('hardcopy', 'email', 'whatsapp') DEFAULT 'hardcopy',
    acknowledgement_receipt BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_report_number (report_number)
);

-- Insert sample staff accounts
INSERT INTO staff (username, full_name, role, password_hash) VALUES
('reception', 'Receptionist User', 'receptionist', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password
('tech1', 'Lab Technician 1', 'technician', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password
('manager', 'Lab Manager', 'manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); -- password

-- Insert common tests
INSERT INTO tests (test_code, test_name, category, sample_type, fasting_required, turnaround_hours) VALUES
('CBC', 'Complete Blood Count', 'Hematology', 'Blood', FALSE, 2),
('LFT', 'Liver Function Test', 'Biochemistry', 'Blood', TRUE, 24),
('RFT', 'Renal Function Test', 'Biochemistry', 'Blood', TRUE, 24),
('LIPID', 'Lipid Profile', 'Biochemistry', 'Blood', TRUE, 24),
('URINE', 'Urine Routine', 'Urine', 'Urine', FALSE, 2),
('GLUCOSE', 'Blood Glucose', 'Biochemistry', 'Blood', TRUE, 2),
('HbA1c', 'Glycated Hemoglobin', 'Biochemistry', 'Blood', FALSE, 24);

-- Insert test parameters for CBC
INSERT INTO test_parameters (test_id, parameter_name, parameter_code, unit, normal_min, normal_max, 
                           male_min, male_max, female_min, female_max) VALUES
(1, 'Hemoglobin', 'HB', 'g/dL', 12.0, 16.0, 13.5, 17.5, 12.0, 16.0),
(1, 'White Blood Cells', 'WBC', '10^9/L', 4.0, 11.0, 4.0, 11.0, 4.0, 11.0),
(1, 'Platelets', 'PLT', '10^9/L', 150, 450, 150, 450, 150, 450),
(1, 'Hematocrit', 'HCT', '%', 36, 46, 40, 54, 36, 46),
(1, 'Red Blood Cells', 'RBC', '10^12/L', 4.0, 5.5, 4.5, 6.0, 4.0, 5.5);

-- Insert test parameters for LFT
INSERT INTO test_parameters (test_id, parameter_name, parameter_code, unit, normal_min, normal_max) VALUES
(2, 'ALT (SGPT)', 'ALT', 'U/L', 7, 56),
(2, 'AST (SGOT)', 'AST', 'U/L', 5, 40),
(2, 'Alkaline Phosphatase', 'ALP', 'U/L', 44, 147),
(2, 'Total Bilirubin', 'TBIL', 'mg/dL', 0.3, 1.2),
(2, 'Direct Bilirubin', 'DBIL', 'mg/dL', 0.0, 0.3);