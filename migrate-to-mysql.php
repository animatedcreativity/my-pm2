<?php

$sqliteDb = __DIR__ . '/pm2-manager.db';
$mysqlHost = '127.0.0.1';
$mysqlUser = 'root';
$mysqlPass = '123456';
$mysqlDb = 'pm2_manager';

try {
    echo "Connecting to SQLite...\n";
    $sqlite = new PDO('sqlite:' . $sqliteDb);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connecting to MySQL...\n";
    $mysql = new PDO("mysql:host=$mysqlHost", $mysqlUser, $mysqlPass);
    $mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Creating database...\n";
    $mysql->exec("CREATE DATABASE IF NOT EXISTS `$mysqlDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysql->exec("USE `$mysqlDb`");
    
    echo "Creating tables...\n";
    $mysql->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS servers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            unique_key VARCHAR(255) UNIQUE NOT NULL,
            host VARCHAR(255),
            last_seen DATETIME,
            status VARCHAR(50) DEFAULT 'offline',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS processes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            server_id INT NOT NULL,
            pm_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(50),
            pid INT,
            cpu DECIMAL(5,2),
            memory BIGINT,
            uptime BIGINT,
            restarts INT,
            script TEXT,
            cwd TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
            INDEX idx_processes_server (server_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            server_id INT NOT NULL,
            process_name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
            INDEX idx_logs_server (server_id),
            INDEX idx_logs_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    echo "Migrating users...\n";
    $users = $sqlite->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $mysql->prepare("INSERT INTO users (id, username, password, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE username=VALUES(username)");
    foreach ($users as $user) {
        $stmt->execute([$user['id'], $user['username'], $user['password'], $user['created_at']]);
    }
    echo "Migrated " . count($users) . " users\n";
    
    echo "Migrating servers...\n";
    $servers = $sqlite->query("SELECT * FROM servers")->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $mysql->prepare("INSERT INTO servers (id, name, unique_key, host, last_seen, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name)");
    foreach ($servers as $server) {
        $stmt->execute([$server['id'], $server['name'], $server['unique_key'], $server['host'], $server['last_seen'], $server['status'], $server['created_at']]);
    }
    echo "Migrated " . count($servers) . " servers\n";
    
    echo "Migrating processes...\n";
    $processes = $sqlite->query("SELECT * FROM processes")->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $mysql->prepare("INSERT INTO processes (id, server_id, pm_id, name, status, pid, cpu, memory, uptime, restarts, last_updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status)");
    foreach ($processes as $process) {
        $stmt->execute([
            $process['id'], $process['server_id'], $process['pm_id'], $process['name'], 
            $process['status'], $process['pid'], $process['cpu'], $process['memory'], 
            $process['uptime'], $process['restarts'], $process['last_updated']
        ]);
    }
    echo "Migrated " . count($processes) . " processes\n";
    
    echo "Migrating logs...\n";
    $logs = $sqlite->query("SELECT * FROM logs")->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $mysql->prepare("INSERT INTO logs (id, server_id, process_name, type, message, timestamp) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE message=VALUES(message)");
    foreach ($logs as $log) {
        $stmt->execute([$log['id'], $log['server_id'], $log['process_name'], $log['type'], $log['message'], $log['timestamp']]);
    }
    echo "Migrated " . count($logs) . " logs\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "Database: $mysqlDb\n";
    echo "Total users: " . count($users) . "\n";
    echo "Total servers: " . count($servers) . "\n";
    echo "Total processes: " . count($processes) . "\n";
    echo "Total logs: " . count($logs) . "\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
