<?php

namespace PM2Manager;

use PDO;

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dbPath = __DIR__ . '/../pm2-manager.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
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
