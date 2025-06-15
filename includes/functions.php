<?php
// ServerBee - Common Functions

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

function formatUptime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    if ($days > 0) {
        return "{$days}d {$hours}h {$minutes}m";
    } elseif ($hours > 0) {
        return "{$hours}h {$minutes}m";
    } else {
        return "{$minutes}m";
    }
}

function getStatusColor($status) {
    switch ($status) {
        case 'online':
            return '#10b981';
        case 'offline':
            return '#ef4444';
        default:
            return '#6b7280';
    }
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateSecretKey($length = 32) {
    return bin2hex(random_bytes($length));
}

function logActivity($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    $logFile = __DIR__ . '/../logs/app.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

function isValidIP($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function isValidPort($port) {
    return is_numeric($port) && $port >= 1 && $port <= 65535;
}

function checkSSHExtension() {
    if (!extension_loaded('ssh2')) {
        throw new Exception('SSH2 extension is not installed. Please install php-ssh2 extension.');
    }
}

function validateServerData($data) {
    $errors = [];
    
    if (empty($data['name'])) {
        $errors[] = 'Server name is required';
    }
    
    if (empty($data['host'])) {
        $errors[] = 'Host is required';
    } elseif (!isValidIP($data['host']) && !filter_var($data['host'], FILTER_VALIDATE_DOMAIN)) {
        $errors[] = 'Invalid host or IP address';
    }
    
    if (isset($data['port']) && !isValidPort($data['port'])) {
        $errors[] = 'Invalid port number';
    }
    
    if (empty($data['username'])) {
        $errors[] = 'Username is required';
    }
    
    return $errors;
}
?>