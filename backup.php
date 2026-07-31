<?php
// Database Backup Download module for IEMS (Restricted to Super Admins only)
require_once 'config.php';
require_role(['super_admin']);

// Log the backup request action
log_activity("Database Backup Initiated & Downloaded");

try {
    // 1. Get all tables in the database
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    $sql_dump = "-- IEMS Database Backup SQL Dump\n";
    $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql_dump .= "-- Database: `" . DB_NAME . "`\n";
    $sql_dump .= "-- --------------------------------------------------------\n\n";
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    
    // 2. Loop tables to generate CREATE and INSERT queries
    foreach ($tables as $table) {
        $sql_dump .= "--\n-- Table structure for table `$table`\n--\n\n";
        
        // Fetch CREATE TABLE statement
        $create_stmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $sql_dump .= $create_stmt['Create Table'] . ";\n\n";
        
        $sql_dump .= "--\n-- Dumping data for table `$table`\n--\n\n";
        
        // Fetch table rows
        $rows_stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($rows) > 0) {
            foreach ($rows as $row) {
                $keys = array_keys($row);
                $escaped_keys = array_map(function($k) { return "`$k`"; }, $keys);
                
                $values = array_values($row);
                $escaped_values = array_map(function($v) use ($pdo) {
                    if ($v === null) {
                        return 'NULL';
                    }
                    return $pdo->quote($v);
                }, $values);
                
                $sql_dump .= "INSERT INTO `$table` (" . implode(', ', $escaped_keys) . ") VALUES (" . implode(', ', $escaped_values) . ");\n";
            }
        } else {
            $sql_dump .= "-- (Table is empty)\n";
        }
        $sql_dump .= "\n-- --------------------------------------------------------\n\n";
    }
    
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    
    // 3. Clear output buffer and force download
    if (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename=' . DB_NAME . '_backup_' . date('Y-m-d_H-i-s') . '.sql');
    header('Content-Length: ' . strlen($sql_dump));
    
    echo $sql_dump;
    exit;
    
} catch (PDOException $e) {
    set_flash_message('error', 'Failed to generate database backup: ' . $e->getMessage());
    header("Location: dashboard.php");
    exit;
}
?>
