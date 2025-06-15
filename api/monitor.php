<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/SSHConnection.php';

$db = new Database();
$serverId = isset($_GET['server_id']) ? intval($_GET['server_id']) : null;

if (!$serverId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Server ID is required'
    ]);
    exit;
}

try {
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
    
    // Try to connect and get system info
    if ($ssh->connect()) {
        $systemInfo = $ssh->getSystemInfo();
        
        if ($systemInfo) {
            // Save monitoring data to database
            $monitoringData = array_merge($systemInfo, [
                'server_id' => $serverId,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $db->insert('monitoring_data', $monitoringData);
            
            // Update server status
            $db->update('servers', [
                'status' => 'online',
                'last_check' => date('Y-m-d H:i:s')
            ], 'id = ?', [$serverId]);
            
            // Format response data
            $responseData = [
                'server_id' => $serverId,
                'server_name' => $server['name'],
                'host' => $server['host'],
                'status' => 'online',
                'last_check' => date('Y-m-d H:i:s'),
                'cpu_usage' => $systemInfo['cpu_usage'] ?? 0,
                'memory_total' => $systemInfo['memory_total'] ?? 0,
                'memory_used' => $systemInfo['memory_used'] ?? 0,
                'memory_available' => $systemInfo['memory_available'] ?? 0,
                'disk_total' => $systemInfo['disk_total'] ?? 0,
                'disk_used' => $systemInfo['disk_used'] ?? 0,
                'disk_free' => $systemInfo['disk_free'] ?? 0,
                'load_average' => $systemInfo['load_average'] ?? '0.00 0.00 0.00',
                'uptime' => $systemInfo['uptime'] ?? 0,
                'network_rx' => $systemInfo['network_rx'] ?? 0,
                'network_tx' => $systemInfo['network_tx'] ?? 0
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $responseData,
                'timestamp' => date('c')
            ]);
            
        } else {
            throw new Exception('Failed to retrieve system information');
        }
        
    } else {
        // Update server status to offline
        $db->update('servers', [
            'status' => 'offline',
            'last_check' => date('Y-m-d H:i:s')
        ], 'id = ?', [$serverId]);
        
        echo json_encode([
            'success' => false,
            'message' => 'Failed to connect to server',
            'server_id' => $serverId,
            'status' => 'offline'
        ]);
    }
    
} catch (Exception $e) {
    // Update server status to offline on error
    $db->update('servers', [
        'status' => 'offline',
        'last_check' => date('Y-m-d H:i:s')
    ], 'id = ?', [$serverId]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Monitoring error: ' . $e->getMessage(),
        'server_id' => $serverId,
        'status' => 'offline'
    ]);
}
?>