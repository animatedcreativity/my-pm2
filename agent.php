#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if ($argc < 3) {
    echo "Usage: php agent.php <server_url> <unique_key>\n";
    echo "Example: php agent.php http://localhost:3011 abc123def456\n";
    exit(1);
}

$serverUrl = rtrim($argv[1], '/');
$uniqueKey = $argv[2];

echo "PM2 Agent starting...\n";
echo "Server: $serverUrl\n";
echo "Key: $uniqueKey\n\n";

function getPM2List() {
    $output = shell_exec('pm2 jlist 2>&1');
    if (!$output) return [];
    
    $processes = json_decode($output, true);
    if (!is_array($processes)) return [];
    
    $result = [];
    foreach ($processes as $proc) {
        $execPath = $proc['pm2_env']['pm_exec_path'] ?? '';
        $script = $execPath;
        
        if (in_array($execPath, ['/bin/bash', '/bin/sh', '/usr/bin/bash', '/usr/bin/sh'])) {
            $args = $proc['pm2_env']['args'] ?? [];
            if (count($args) >= 2 && $args[0] === '-c') {
                $script = implode(' ', array_slice($args, 1));
            }
        }
        
        $result[] = [
            'pm_id' => $proc['pm_id'] ?? 0,
            'name' => $proc['name'] ?? 'unknown',
            'status' => $proc['pm2_env']['status'] ?? 'unknown',
            'pid' => $proc['pid'] ?? 0,
            'cpu' => $proc['monit']['cpu'] ?? 0,
            'memory' => $proc['monit']['memory'] ?? 0,
            'uptime' => isset($proc['pm2_env']['pm_uptime']) ? (time() - ($proc['pm2_env']['pm_uptime'] / 1000)) : 0,
            'restarts' => $proc['pm2_env']['restart_time'] ?? 0,
            'cwd' => $proc['pm2_env']['pm_cwd'] ?? null,
            'script' => $script
        ];
    }
    
    return $result;
}

function getPM2Logs($processName = null) {
    $logPath = getenv('HOME') . '/.pm2/logs';
    if (!is_dir($logPath)) return [];
    
    $processList = getPM2List();
    $processNameMap = [];
    foreach ($processList as $proc) {
        $logFileName = str_replace(' ', '-', $proc['name']);
        $processNameMap[$logFileName] = $proc['name'];
    }
    
    $logs = [];
    $files = glob("$logPath/*.log");
    
    foreach (array_slice($files, -10) as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) continue;
        
        $processFile = basename($file);
        preg_match('/^(.+?)-(out|error)\.log$/', $processFile, $matches);
        if (!$matches) continue;
        
        $logFileBaseName = $matches[1];
        $process = $processNameMap[$logFileBaseName] ?? $logFileBaseName;
        $type = $matches[2] === 'error' ? 'error' : 'output';
        
        foreach (array_slice($lines, -10) as $line) {
            if (trim($line) === '') continue;
            $logs[] = [
                'process' => $process,
                'type' => $type,
                'message' => $line
            ];
        }
    }
    
    return array_slice($logs, -100);
}

function sendToServer($url, $key, $data) {
    $ch = curl_init("$url/api.php?request=agent");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array_merge(['key' => $key], $data)));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    
    return null;
}

function executeCommand($cmd) {
    $action = $cmd['action'];
    $process = $cmd['process'];
    
    echo "[" . date('Y-m-d H:i:s') . "] Executing: $action $process\n";
    
    switch ($action) {
        case 'start':
            shell_exec("pm2 start " . escapeshellarg($process) . " 2>&1");
            break;
        case 'stop':
            shell_exec("pm2 stop " . escapeshellarg($process) . " 2>&1");
            break;
        case 'restart':
            shell_exec("pm2 restart " . escapeshellarg($process) . " 2>&1");
            break;
        case 'delete':
            shell_exec("pm2 delete " . escapeshellarg($process) . " 2>&1");
            break;
        case 'create':
            $script = escapeshellarg($cmd['script'] ?? '');
            $cwd = $cmd['cwd'] ?? getcwd();
            if ($cwd && strpos($cwd, '~') === 0) {
                $cwd = getenv('HOME') . substr($cwd, 1);
            }
            $cwdArg = $cwd ? "--cwd " . escapeshellarg($cwd) : '';
            shell_exec("pm2 start $script --name " . escapeshellarg($process) . " $cwdArg 2>&1");
            break;
        case 'edit':
            $oldProcess = $cmd['oldProcess'] ?? $process;
            shell_exec("pm2 delete " . escapeshellarg($oldProcess) . " 2>&1");
            sleep(1);
            $script = escapeshellarg($cmd['script'] ?? '');
            $cwd = $cmd['cwd'] ?? getcwd();
            if ($cwd && strpos($cwd, '~') === 0) {
                $cwd = getenv('HOME') . substr($cwd, 1);
            }
            $cwdArg = $cwd ? "--cwd " . escapeshellarg($cwd) : '';
            shell_exec("pm2 start $script --name " . escapeshellarg($process) . " $cwdArg 2>&1");
            break;
    }
    
    sleep(1);
}

$lastLogCheck = 0;

while (true) {
    try {
        $processes = getPM2List();
        
        $response = sendToServer($serverUrl, $uniqueKey, [
            'type' => 'processes',
            'data' => $processes
        ]);
        
        if (time() - $lastLogCheck > 30) {
            $logs = getPM2Logs();
            if (!empty($logs)) {
                sendToServer($serverUrl, $uniqueKey, [
                    'type' => 'logs',
                    'data' => $logs
                ]);
            }
            $lastLogCheck = time();
        }
        
        if ($response && isset($response['command'])) {
            $cmd = $response['command'];
            executeCommand($cmd);
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] Sent " . count($processes) . " processes\n";
        
    } catch (Exception $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
    }
    
    sleep(5);
}
