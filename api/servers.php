<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/SSHConnection.php';

$db = new Database();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetServers($db);
        break;
    case 'POST':
        handleAddServer($db);
        break;
    case 'PUT':
        handleUpdateServer($db);
        break;
    case 'DELETE':
        handleDeleteServer($db);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

function handleGetServers($db) {
    try {
        $servers = $db->fetchAll("
            SELECT id, name, host, port, username, description, status, last_check, created_at 
            FROM servers 
            ORDER BY name ASC
        ");
        
        echo json_encode([
            'success' => true,
            'servers' => $servers,
            'count' => count($servers)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch servers: ' . $e->getMessage()
        ]);
    }
}

function handleAddServer($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['name', 'host', 'username'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Field '{$field}' is required"
                ]);
                return;
            }
        }
        
        // Sanitize input
        $serverData = [
            'name' => trim($input['name']),
            'host' => trim($input['host']),
            'port' => isset($input['port']) ? intval($input['port']) : 22,
            'username' => trim($input['username']),
            'password' => isset($input['password']) ? $input['password'] : null,
            'description' => isset($input['description']) ? trim($input['description']) : null,
            'status' => 'unknown',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Test connection before saving
        $ssh = new SSHConnection(
            $serverData['host'],
            $serverData['username'],
            $serverData['password'],
            null,
            $serverData['port']
        );
        
        if ($ssh->testConnection()) {
            $serverData['status'] = 'online';
            $serverData['last_check'] = date('Y-m-d H:i:s');
        } else {
            $serverData['status'] = 'offline';
        }
        
        // Insert server
        $success = $db->insert('servers', $serverData);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Server added successfully',
                'status' => $serverData['status']
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to add server to database'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to add server: ' . $e->getMessage()
        ]);
    }
}

function handleUpdateServer($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $serverId = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        if (!$serverId) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Server ID is required'
            ]);
            return;
        }
        
        // Check if server exists
        $existingServer = $db->fetchOne("SELECT * FROM servers WHERE id = ?", [$serverId]);
        if (!$existingServer) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Server not found'
            ]);
            return;
        }
        
        // Prepare update data
        $updateData = [
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $allowedFields = ['name', 'host', 'port', 'username', 'password', 'description'];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }
        
        // Update server
        $success = $db->update('servers', $updateData, 'id = ?', [$serverId]);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Server updated successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update server'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update server: ' . $e->getMessage()
        ]);
    }
}

function handleDeleteServer($db) {
    try {
        $serverId = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        if (!$serverId) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Server ID is required'
            ]);
            return;
        }
        
        // Check if server exists
        $existingServer = $db->fetchOne("SELECT * FROM servers WHERE id = ?", [$serverId]);
        if (!$existingServer) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Server not found'
            ]);
            return;
        }
        
        // Delete server (this will also delete monitoring data due to foreign key cascade)
        $success = $db->delete('servers', 'id = ?', [$serverId]);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Server deleted successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete server'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete server: ' . $e->getMessage()
        ]);
    }
}
?>