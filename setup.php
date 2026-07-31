<?php
// Database setup script for Income & Expense Management System (IEMS)

// Define database connection credentials
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'income_expense_erp';

try {
    // 1. Establish connection to MySQL server (without selecting DB)
    $dsn = "mysql:host=$host;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "Connected to MySQL server successfully.<br>";
    
    // 2. Create the Database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `$dbname` created or already exists.<br>";
    
    // 3. Select the Database
    $pdo->exec("USE `$dbname`");
    echo "Database `$dbname` selected.<br>";
    
    // 4. Create users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `full_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(150) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('super_admin', 'admin', 'staff') NOT NULL DEFAULT 'staff',
        `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Table `users` created or already exists.<br>";
    
    // 5. Create bank_accounts table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bank_accounts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_name` VARCHAR(100) NOT NULL,
        `account_number` VARCHAR(50) NOT NULL,
        `bank_name` VARCHAR(100) NOT NULL,
        `ifsc_code` VARCHAR(20) DEFAULT NULL,
        `branch_name` VARCHAR(100) DEFAULT NULL,
        `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `current_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Table `bank_accounts` created or already exists.<br>";
    
    // 6. Create income table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `income` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_id` INT NOT NULL,
        `title` VARCHAR(150) NOT NULL,
        `category` VARCHAR(100) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `payment_method` ENUM('cash', 'bank', 'upi', 'card') NOT NULL DEFAULT 'cash',
        `reference_no` VARCHAR(100) DEFAULT NULL,
        `description` TEXT DEFAULT NULL,
        `attachment` VARCHAR(255) DEFAULT NULL,
        `income_date` DATE NOT NULL,
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Table `income` created or already exists.<br>";
    
    // 7. Create expenses table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `expenses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_id` INT NOT NULL,
        `title` VARCHAR(150) NOT NULL,
        `category` VARCHAR(100) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `payment_method` ENUM('cash', 'bank', 'upi', 'card') NOT NULL DEFAULT 'cash',
        `reference_no` VARCHAR(100) DEFAULT NULL,
        `description` TEXT DEFAULT NULL,
        `attachment` VARCHAR(255) DEFAULT NULL,
        `expense_date` DATE NOT NULL,
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Table `expenses` created or already exists.<br>";
    
    // 8. Create transfers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `transfers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `from_account` INT NOT NULL,
        `to_account` INT NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `transfer_date` DATE NOT NULL,
        `remarks` TEXT DEFAULT NULL,
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`from_account`) REFERENCES `bank_accounts`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        FOREIGN KEY (`to_account`) REFERENCES `bank_accounts`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Table `transfers` created or already exists.<br>";
    
    // 9. Create activity_logs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT DEFAULT NULL,
        `action` VARCHAR(255) NOT NULL,
        `ip_address` VARCHAR(50) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Table `activity_logs` created or already exists.<br>";
    
    // 10. Seed Default Admin User if not exists
    $adminEmail = 'admin@demo.com';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$adminEmail]);
    if ($stmt->rowCount() === 0) {
        $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->execute(['Super Admin', $adminEmail, $adminPass, 'super_admin', 'active']);
        echo "Default admin user seeded successfully: admin@demo.com / admin123<br>";
        
        // Log the seeding
        $adminId = $pdo->lastInsertId();
        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, ip_address) VALUES (?, ?, ?)");
        $logStmt->execute([$adminId, 'System Setup: Default admin seeded', '127.0.0.1']);
    } else {
        echo "Default admin user already exists.<br>";
    }
    
    echo "<h3>System setup completed successfully! You can now delete setup.php or keep it for resetting.</h3>";
    
} catch (PDOException $e) {
    die("Database Setup Failed: " . $e->getMessage());
}
?>
