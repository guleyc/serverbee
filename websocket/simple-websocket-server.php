<?php
// Simple WebSocket Server Implementation (without ReactPHP)
// This is a fallback when ReactPHP is not available

class SimpleWebSocketServer {
    private $socket;
    private $clients = [];
    private $db;
    
    public function __construct($host = 'localhost', $port = 8080) {
        $this->db = new Database();
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, $host, $port);
        socket_listen($this->socket);
        
        echo "Simple WebSocket Server started on {$host}:{$port}\n";
        echo "Note: For better performance, install ReactPHP: composer require ratchet/pawl\n";
        echo "Press Ctrl+C to stop\n";
    }
    
    public function run() {
        while (true) {
            $changed = array_merge([$this->socket], $this->clients);
            $write = $except = null;
            
            socket_select($changed, $write, $except, 1);
            
            if (in_array($this->socket, $changed)) {
                $newSocket = socket_accept($this->socket);
                $this->clients[] = $newSocket;
                $this->performHandshake($newSocket);
                echo "New client connected\n";
                
                $key = array_search($this->socket, $changed);
                unset($changed[$key]);
            }
            
            foreach ($changed as $client) {
                $data = @socket_read($client, 1024, PHP_NORMAL_READ);
                
                if ($data === false || $data === '') {
                    $this->disconnect($client);
                    continue;
                }
                
                $decodedData = $this->decode($data);
                if ($decodedData) {
                    $this->handleMessage($client, $decodedData);
                }
            }
            
            // Monitor servers every 30 seconds
            static $lastMonitor = 0;
            if (time() - $lastMonitor > 30) {
                $this->monitorServers();
                $lastMonitor = time();
            }
            
            // Send heartbeat every 10 seconds
            static $lastHeartbeat = 0;
            if (time() - $lastHeartbeat > 10) {
                $this->sendHeartbeat();
                $lastHeartbeat = time();
            }
        }
    }
    
    private function performHandshake($client) {
        $request = socket_read($client, 5000);
        
        preg_match('#Sec-WebSocket-Key: (.*)\r\n#', $request, $matches);
        $key = base64_encode(pack('H*', sha1($matches[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        
        $headers = "HTTP/1.1 101 Switching Protocols\r\n";
        $headers .= "Upgrade: websocket\r\n";
        $headers .= "Connection: Upgrade\r\n";
        $headers .= "Sec-WebSocket-Accept: $key\r\n\r\n";
        
        socket_write($client, $headers, strlen($headers));
    }
    
    private function decode($data) {
        $length = ord($data[1]) & 127;
        
        if ($length == 126) {
            $masks = substr($data, 4, 4);
            $data = substr($data, 8);
        } elseif ($length == 127) {
            $masks = substr($data, 10, 4);
            $data = substr($data, 14);
        } else {
            $masks = substr($data, 2, 4);
            $data = substr($data, 6);
        }
        
        $text = '';
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        
        return $text;
    }
    
    private function encode($text) {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);
        
        if ($length <= 125) {
            $header = pack('CC', $b1, $length);
        } elseif ($length > 125 && $length < 65536) {
            $header = pack('CCn', $b1, 126, $length);
        } elseif ($length >= 65536) {
            $header = pack('CCNN', $b1, 127, $length);
        }
        
        return $header . $text;
    }
    
    private function handleMessage($client, $message) {
        $data = json_decode($message, true);
        
        if (!$data) return;
        
        switch ($data['type']) {
            case 'get_servers':
                $this->sendServerList($client);
                break;
            case 'subscribe_server':
                $this->sendServerData($client, $data['server_id']);
                break;
        }
    }
    
    private function sendServerList($client) {
        $servers = $this->db->fetchAll("
            SELECT id, name, host, status, last_check 
            FROM servers 
            ORDER BY name ASC
        ");
        
        $message = json_encode([
            'type' => 'server_list',
            'servers' => $servers,
            'timestamp' => date('c')
        ]);
        
        socket_write($client, $this->encode($message));
    }
    
    private function sendServerData($client, $serverId) {
        $data = $this->db->fetchOne("
            SELECT * FROM monitoring_data 
            WHERE server_id = ? 
            ORDER BY timestamp DESC 
            LIMIT 1
        ", [$serverId]);
        
        if ($data) {
            $message = json_encode([
                'type' => 'server_data',
                'server_id' => $serverId,
                'data' => $data,
                'timestamp' => date('c')
            ]);
            
            socket_write($client, $this->encode($message));
        }
    }
    
    private function monitorServers() {
        $servers = $this->db->fetchAll("SELECT * FROM servers");
        
        foreach ($servers as $server) {
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
                        
                        // Broadcast update
                        $this->broadcast([
                            'type' => 'server_update',
                            'server_id' => $server['id'],
                            'data' => $systemInfo,
                            'status' => 'online',
                            'timestamp' => date('c')
                        ]);
                    }
                } else {
                    // Server offline
                    $this->db->update('servers', [
                        'status' => 'offline',
                        'last_check' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$server['id']]);
                    
                    $this->broadcast([
                        'type' => 'server_update',
                        'server_id' => $server['id'],
                        'status' => 'offline',
                        'timestamp' => date('c')
                    ]);
                }
            } catch (Exception $e) {
                echo "Error monitoring server {$server['name']}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    private function sendHeartbeat() {
        $this->broadcast([
            'type' => 'heartbeat',
            'timestamp' => date('c'),
            'clients' => count($this->clients)
        ]);
    }
    
    private function broadcast($data) {
        $message = $this->encode(json_encode($data));
        
        foreach ($this->clients as $client) {
            @socket_write($client, $message);
        }
    }
    
    private function disconnect($client) {
        $key = array_search($client, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
            socket_close($client);
            echo "Client disconnected\n";
        }
    }
}

// Start simple WebSocket server
try {
    $server = new SimpleWebSocketServer(WS_HOST, WS_PORT);
    $server->run();
} catch (Exception $e) {
    echo "WebSocket server error: " . $e->getMessage() . "\n";
}
?>