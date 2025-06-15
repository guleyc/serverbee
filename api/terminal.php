<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/SSHConnection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$serverId = isset($input['server_id']) ? intval($input['server_id']) : null;
$command = isset($input['command']) ? trim($input['command']) : null;

if (!$serverId || !$command) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Server ID and command are required'
    ]);
    exit;
}

// Security: Block dangerous commands
$dangerousCommands = [
    'rm -rf', 'mkfs', 'dd if=', 'fdisk', 'shutdown', 'reboot', 'halt',
    'passwd', 'userdel', 'groupdel', 'crontab -r', 'history -c'
];

foreach ($dangerousCommands as $dangerous) {
    if (stripos($command, $dangerous) !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'Command blocked for security reasons',
            'output' => "Error: Command '$dangerous' is not allowed for security reasons."
        ]);
        exit;
    }
}

try {
    $db = new Database();
    
    // Get server information
    $server = $db->fetchOne("SELECT * FROM servers WHERE id = ?", [$serverId]);
    
    if (!$server) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Server not found'
        ]);
        exit;
    }
    
    // Create SSH connection
    $ssh = new SSHConnection(
        $server['host'],
        $server['username'],
        $server['password'],
        null,
        $server['port']
    );
    
    if ($ssh->connect()) {
        $output = $ssh->executeCommand($command);
        
        if ($output !== false) {
            echo json_encode([
                'success' => true,
                'command' => $command,
                'output' => $output,
                'timestamp' => date('c')
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to execute command',
                'command' => $command
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to connect to server'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terminal error: ' . $e->getMessage()
    ]);
}
?>