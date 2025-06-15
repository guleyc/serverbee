<?php
// ServerBee WebSocket Server for Real-time Updates
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SSHConnection.php';

// Check if ReactPHP is available (composer install required)
if (!class_exists('React\Socket\Server')) {
    echo "Installing ReactPHP WebSocket server dependencies...\n";
    echo "Run: composer require ratchet/pawl react/socket react/http\n";
    echo "\nFor now, using simple WebSocket implementation.\n\n";
    
    // Use simple WebSocket server implementation
    require_once 'simple-websocket-server.php';
    exit;
}

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;

class ServerBeeWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $db;
    protected $loop;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->db = new Database();
        echo "ServerBee WebSocket Server started on port " . WS_PORT . "\n";
    }
    
    public function setLoop($loop) {
        $this->loop = $loop;
        $this->startMonitoringLoop();
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
        
        // Send initial server list to new client
        $this->sendServerList($conn);
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!$data) {
            return;
        }
        
        switch ($data['type']) {
            case 'subscribe_server':
                $this->handleSubscribeServer($from, $data);
                break;
            case 'execute_command':
                $this->handleExecuteCommand($from, $data);
                break;
            case 'get_servers':
                $this->sendServerList($from);
                break;
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
    
    private function startMonitoringLoop() {
        // Monitor all servers every 30 seconds
        $this->loop->addPeriodicTimer(30, function() {
            $this->monitorAllServers();
        });
        
        // Send heartbeat every 10 seconds
        $this->loop->addPeriodicTimer(10, function() {
            $this->sendHeartbeat();
        });
    }
    
    private function monitorAllServers() {
        try {
            $servers = $this->db->fetchAll("SELECT * FROM servers WHERE status != 'offline'");
            
            foreach ($servers as $server) {
                $this->monitorServer($server);
            }
        } catch (Exception $e) {
            echo "Monitoring error: " . $e->getMessage() . "\n";
        }
    }
    
    private function monitorServer($server) {
        try {
            $ssh = new SSHConnection(
                $server['host'],
                $server['username'],
                $server['password'],
                null,
                $server['port']
            );
            
            if ($ssh->connect()) {
                $systemInfo = $ssh->getSystemInfo();
                
                if ($systemInfo) {
                    // Save to database
                    $monitoringData = array_merge($systemInfo, [
                        'server_id' => $server['id'],
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                    
                    $this->db->insert('monitoring_data', $monitoringData);
                    
                    // Update server status
                    $this->db->update('servers', [
                        'status' => 'online',
                        'last_check' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$server['id']]);
                    
                    // Send real-time update to all clients
                    $this->broadcastServerUpdate($server['id'], $systemInfo, 'online');
                } else {
                    $this->handleServerOffline($server);
                }
            } else {
                $this->handleServerOffline($server);
            }
        } catch (Exception $e) {
            echo "Server {$server['name']} monitoring error: " . $e->getMessage() . "\n";
            $this->handleServerOffline($server);
        }
    }
    
    private function handleServerOffline($server) {
        $this->db->update('servers', [
            'status' => 'offline',
            'last_check' => date('Y-m-d H:i:s')
        ], 'id = ?', [$server['id']]);
        
        $this->broadcastServerUpdate($server['id'], [], 'offline');
    }
    
    private function broadcastServerUpdate($serverId, $systemInfo, $status) {
        $message = json_encode([
            'type' => 'server_update',
            'server_id' => $serverId,
            'status' => $status,
            'data' => $systemInfo,
            'timestamp' => date('c')
        ]);
        
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }
    
    private function sendServerList($conn) {
        try {
            $servers = $this->db->fetchAll("
                SELECT id, name, host, port, status, last_check 
                FROM servers 
                ORDER BY name ASC
            ");
            
            $message = json_encode([
                'type' => 'server_list',
                'servers' => $servers,
                'timestamp' => date('c')
            ]);
            
            $conn->send($message);
        } catch (Exception $e) {
            echo "Error sending server list: " . $e->getMessage() . "\n";
        }
    }
    
    private function sendHeartbeat() {
        $message = json_encode([
            'type' => 'heartbeat',
            'timestamp' => date('c'),
            'server_time' => time()
        ]);
        
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }
    
    private function handleSubscribeServer($conn, $data) {
        $serverId = $data['server_id'] ?? null;
        
        if ($serverId) {
            // Get latest monitoring data for this server
            $latestData = $this->db->fetchOne("
                SELECT * FROM monitoring_data 
                WHERE server_id = ? 
                ORDER BY timestamp DESC 
                LIMIT 1
            ", [$serverId]);
            
            if ($latestData) {
                $message = json_encode([
                    'type' => 'server_data',
                    'server_id' => $serverId,
                    'data' => $latestData,
                    'timestamp' => date('c')
                ]);
                
                $conn->send($message);
            }
        }
    }
    
    private function handleExecuteCommand($conn, $data) {
        $serverId = $data['server_id'] ?? null;
        $command = $data['command'] ?? null;
        
        if (!$serverId || !$command) {
            return;
        }
        
        try {
            $server = $this->db->fetchOne("SELECT * FROM servers WHERE id = ?", [$serverId]);
            
            if (!$server) {
                return;
            }
            
            $ssh = new SSHConnection(
                $server['host'],
                $server['username'],
                $server['password'],
                null,
                $server['port']
            );
            
            if ($ssh->connect()) {
                $output = $ssh->executeCommand($command);
                
                $message = json_encode([
                    'type' => 'command_result',
                    'server_id' => $serverId,
                    'command' => $command,
                    'output' => $output,
                    'success' => true,
                    'timestamp' => date('c')
                ]);
                
                $conn->send($message);
            }
        } catch (Exception $e) {
            $message = json_encode([
                'type' => 'command_result',
                'server_id' => $serverId,
                'command' => $command,
                'error' => $e->getMessage(),
                'success' => false,
                'timestamp' => date('c')
            ]);
            
            $conn->send($message);
        }
    }
}

// Create and start WebSocket server
try {
    $loop = Loop::get();
    $webSocketServer = new ServerBeeWebSocket();
    $webSocketServer->setLoop($loop);
    
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($webSocketServer)
        ),
        WS_PORT,
        WS_HOST
    );
    
    echo "ServerBee WebSocket Server running on " . WS_HOST . ":" . WS_PORT . "\n";
    echo "Press Ctrl+C to stop the server\n";
    
    $server->run();
    
} catch (Exception $e) {
    echo "Failed to start WebSocket server: " . $e->getMessage() . "\n";
    echo "Falling back to simple WebSocket server...\n";
    require_once 'simple-websocket-server.php';
}
?>