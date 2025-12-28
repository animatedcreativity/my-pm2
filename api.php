<?php

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Auth.php';

use PM2Manager\Database;
use PM2Manager\Auth;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = Database::getInstance()->getPdo();
$auth = new Auth();

$method = $_SERVER['REQUEST_METHOD'];
$request = $_GET['request'] ?? '';
$path = explode('/', trim($request, '/'));

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requireAuth() {
    global $auth;
    $user = $auth->getAuthUser();
    if (!$user) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
    return $user;
}

if ($path[0] === 'login' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $result = $auth->login($data['username'] ?? '', $data['password'] ?? '');
    
    if (!$result) {
        jsonResponse(['error' => 'Invalid credentials'], 401);
    }
    
    jsonResponse($result);
}

if ($path[0] === 'servers') {
    requireAuth();
    
    if ($method === 'GET' && count($path) === 1) {
        $stmt = $db->query("SELECT * FROM servers ORDER BY name");
        jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $uniqueKey = bin2hex(random_bytes(16));
        
        $stmt = $db->prepare("INSERT INTO servers (name, unique_key, host) VALUES (?, ?, ?)");
        $stmt->execute([$data['name'], $uniqueKey, $data['host'] ?? '']);
        
        jsonResponse([
            'id' => $db->lastInsertId(),
            'name' => $data['name'],
            'uniqueKey' => $uniqueKey,
            'host' => $data['host'] ?? ''
        ]);
    }
    
    if ($method === 'DELETE' && isset($path[1])) {
        $stmt = $db->prepare("DELETE FROM servers WHERE id = ?");
        $stmt->execute([$path[1]]);
        jsonResponse(['success' => true]);
    }
}

if ($path[0] === 'processes') {
    requireAuth();
    
    if ($method === 'GET' && count($path) === 1) {
        $stmt = $db->query("
            SELECT p.*, s.name as server_name, s.status as server_status
            FROM processes p
            JOIN servers s ON p.server_id = s.id
            ORDER BY s.name, p.name
        ");
        
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($processes as &$process) {
            $outputStmt = $db->prepare("
                SELECT message, timestamp 
                FROM logs 
                WHERE server_id = ? AND process_name = ? AND type = 'output'
                ORDER BY timestamp DESC, id DESC 
                LIMIT 1
            ");
            $outputStmt->execute([$process['server_id'], $process['name']]);
            $lastOutput = $outputStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastOutput) {
                $process['last_output_message'] = $lastOutput['message'];
                $process['last_output_timestamp'] = date('c', strtotime($lastOutput['timestamp']));
            }
            
            $errorStmt = $db->prepare("
                SELECT message, timestamp 
                FROM logs 
                WHERE server_id = ? AND process_name = ? AND type = 'error'
                AND timestamp > datetime('now', '-1 hour')
                ORDER BY timestamp DESC, id DESC 
                LIMIT 1
            ");
            $errorStmt->execute([$process['server_id'], $process['name']]);
            $lastError = $errorStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastError) {
                $process['last_error_message'] = $lastError['message'];
                $process['last_error_timestamp'] = date('c', strtotime($lastError['timestamp']));
            }
        }
        
        jsonResponse($processes);
    }
    
    if ($method === 'GET' && $path[1] === 'server' && isset($path[2])) {
        $stmt = $db->prepare("SELECT * FROM processes WHERE server_id = ?");
        $stmt->execute([$path[2]]);
        jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    if ($method === 'POST' && isset($path[1]) && in_array($path[2] ?? '', ['start', 'stop', 'restart', 'delete', 'create', 'edit'])) {
        $data = json_decode(file_get_contents('php://input'), true);
        $serverId = $path[1];
        $action = $path[2];
        
        $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$server) {
            jsonResponse(['error' => 'Server not found'], 404);
        }
        
        $command = [
            'action' => $action,
            'process' => $data['processName'],
            'timestamp' => time()
        ];
        
        if ($action === 'create' || $action === 'edit') {
            $command['cwd'] = $data['cwd'] ?? null;
            $command['script'] = $data['script'] ?? null;
            
            if ($action === 'edit' && isset($data['oldProcessName'])) {
                $command['oldProcess'] = $data['oldProcessName'];
            }
        }
        
        file_put_contents(__DIR__ . "/commands/{$server['unique_key']}.json", json_encode($command));
        
        jsonResponse(['success' => true]);
    }
}

if ($path[0] === 'logs') {
    requireAuth();
    
    if ($method === 'GET') {
        $serverId = $_GET['serverId'] ?? null;
        $processName = $_GET['processName'] ?? null;
        $limit = (int)($_GET['limit'] ?? 100);
        
        $query = "SELECT l.*, s.name as server_name FROM logs l JOIN servers s ON l.server_id = s.id";
        $conditions = [];
        $params = [];
        
        if ($serverId) {
            $conditions[] = "l.server_id = ?";
            $params[] = $serverId;
        }
        
        if ($processName) {
            $conditions[] = "l.process_name = ?";
            $params[] = $processName;
        }
        
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $query .= " ORDER BY l.timestamp DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as &$log) {
            if (isset($log['timestamp'])) {
                $log['timestamp'] = date('c', strtotime($log['timestamp']));
            }
        }
        
        jsonResponse($logs);
    }
}

if ($path[0] === 'agent') {
    $data = json_decode(file_get_contents('php://input'), true);
    $key = $data['key'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM servers WHERE unique_key = ?");
    $stmt->execute([$key]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$server) {
        jsonResponse(['error' => 'Invalid key'], 401);
    }
    
    $db->prepare("UPDATE servers SET status = ?, last_seen = CURRENT_TIMESTAMP WHERE id = ?")->execute(['online', $server['id']]);
    
    if ($data['type'] === 'processes') {
        $db->prepare("DELETE FROM processes WHERE server_id = ?")->execute([$server['id']]);
        
        $stmt = $db->prepare("
            INSERT INTO processes (server_id, pm_id, name, status, pid, cpu, memory, uptime, restarts, cwd, script)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($data['data'] as $process) {
            $stmt->execute([
                $server['id'],
                $process['pm_id'],
                $process['name'],
                $process['status'],
                $process['pid'] ?? null,
                $process['cpu'] ?? 0,
                $process['memory'] ?? 0,
                $process['uptime'] ?? 0,
                $process['restarts'] ?? 0,
                $process['cwd'] ?? null,
                $process['script'] ?? null
            ]);
        }
    }
    
    if ($data['type'] === 'logs') {
        $stmt = $db->prepare("INSERT INTO logs (server_id, process_name, type, message) VALUES (?, ?, ?, ?)");
        
        foreach ($data['data'] as $log) {
            $stmt->execute([$server['id'], $log['process'], $log['type'], $log['message']]);
            
            $cleanupStmt = $db->prepare("
                DELETE FROM logs 
                WHERE id IN (
                    SELECT id FROM logs 
                    WHERE server_id = ? AND process_name = ? 
                    ORDER BY timestamp DESC 
                    LIMIT -1 OFFSET 100
                )
            ");
            $cleanupStmt->execute([$server['id'], $log['process']]);
        }
    }
    
    $commandFile = __DIR__ . "/commands/{$key}.json";
    if (file_exists($commandFile)) {
        $command = json_decode(file_get_contents($commandFile), true);
        unlink($commandFile);
        jsonResponse(['command' => $command]);
    }
    
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Not found'], 404);
