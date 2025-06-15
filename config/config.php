<?php
// ServerBee Configuration File
define('APP_NAME', 'ServerBee');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/serverbee');

// Database Configuration
define('DB_TYPE', 'sqlite'); // sqlite or mysql
define('DB_HOST', 'localhost');
define('DB_NAME', 'serverbee');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_FILE', __DIR__ . '/../database/serverbee.db');

// Security
define('SECRET_KEY', 'your-secret-key-here-change-this');
define('SESSION_TIMEOUT', 3600); // 1 hour

// SSH Configuration
define('SSH_TIMEOUT', 30);
define('SSH_PORT', 22);

// WebSocket Configuration
define('WS_HOST', 'localhost');
define('WS_PORT', 8080);

// Timezone
date_default_timezone_set('UTC');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>