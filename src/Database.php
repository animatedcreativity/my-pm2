<?php

namespace PM2Manager;

use PDO;

class Database {
    private static $instance = null;
    private $pdo;
    private $dbType;

    private function __construct() {
        $this->dbType = getenv('DB_TYPE') ?: 'sqlite';
        
        if ($this->dbType === 'mysql') {
            $host = getenv('DB_HOST') ?: 'localhost';
            $name = getenv('DB_NAME') ?: 'pm2_manager';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';
            
            $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
            $this->pdo = new PDO($dsn, $user, $pass);
        } else {
            $dbPath = __DIR__ . '/../pm2-manager.db';
            $this->pdo = new PDO('sqlite:' . $dbPath);
        }
        
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initDatabase();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    private function initDatabase() {
        if ($this->dbType === 'mysql') {
            $this->pdo->exec("
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
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    password TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS servers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    unique_key TEXT UNIQUE NOT NULL,
                    host TEXT,
                    last_seen DATETIME,
                    status TEXT DEFAULT 'offline',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS processes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    server_id INTEGER NOT NULL,
                    pm_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    status TEXT,
                    pid INTEGER,
                    cpu REAL,
                    memory REAL,
                    uptime INTEGER,
                    restarts INTEGER,
                    script TEXT,
                    cwd TEXT,
                    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
                );

                CREATE TABLE IF NOT EXISTS logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    server_id INTEGER NOT NULL,
                    process_name TEXT NOT NULL,
                    type TEXT NOT NULL,
                    message TEXT NOT NULL,
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
                );

                CREATE INDEX IF NOT EXISTS idx_processes_server ON processes(server_id);
                CREATE INDEX IF NOT EXISTS idx_logs_server ON logs(server_id);
                CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp);
            ");
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute(['admin']);
        
        if ($stmt->fetchColumn() == 0) {
            $hashedPassword = password_hash('admin-my-pm2', PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->execute(['admin', $hashedPassword]);
            error_log('Default admin user created (admin / admin-my-pm2)');
        }
    }
}
