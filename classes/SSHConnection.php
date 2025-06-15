<?php
class SSHConnection {
    private $connection;
    private $host;
    private $port;
    private $username;
    private $password;
    private $privateKey;
    
    public function __construct($host, $username, $password = null, $privateKey = null, $port = 22) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->privateKey = $privateKey;
    }
    
    public function connect() {
        try {
            $this->connection = ssh2_connect($this->host, $this->port);
            
            if (!$this->connection) {
                throw new Exception("Could not connect to {$this->host}:{$this->port}");
            }
            
            // SSH Key authentication
            if ($this->privateKey) {
                if (!ssh2_auth_pubkey_file($this->connection, $this->username, 
                    $this->privateKey . '.pub', $this->privateKey)) {
                    throw new Exception("SSH key authentication failed");
                }
            }
            // Password authentication
            elseif ($this->password) {
                if (!ssh2_auth_password($this->connection, $this->username, $this->password)) {
                    throw new Exception("SSH password authentication failed");
                }
            } else {
                throw new Exception("No authentication method provided");
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("SSH Connection Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function executeCommand($command) {
        if (!$this->connection) {
            return false;
        }
        
        $stream = ssh2_exec($this->connection, $command);
        
        if (!$stream) {
            return false;
        }
        
        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        return $output;
    }
    
    public function getSystemInfo() {
        $data = [];
        
        // CPU Usage
        $cpuCmd = "grep 'cpu ' /proc/stat | awk '{usage=($2+$4)*100/($2+$3+$4)} END {print usage}'";
        $data['cpu_usage'] = floatval($this->executeCommand($cpuCmd));
        
        // Memory Info
        $memInfo = $this->executeCommand("cat /proc/meminfo");
        if ($memInfo) {
            preg_match('/MemTotal:\s+(\d+)/', $memInfo, $memTotal);
            preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $memAvailable);
            
            $data['memory_total'] = isset($memTotal[1]) ? intval($memTotal[1]) * 1024 : 0;
            $data['memory_available'] = isset($memAvailable[1]) ? intval($memAvailable[1]) * 1024 : 0;
            $data['memory_used'] = $data['memory_total'] - $data['memory_available'];
        }
        
        // Disk Usage
        $diskInfo = $this->executeCommand("df -B 1 / | tail -1");
        if ($diskInfo) {
            $diskParts = preg_split('/\s+/', trim($diskInfo));
            if (count($diskParts) >= 4) {
                $data['disk_total'] = intval($diskParts[1]);
                $data['disk_used'] = intval($diskParts[2]);
                $data['disk_free'] = intval($diskParts[3]);
            }
        }
        
        // Load Average
        $loadAvg = $this->executeCommand("cat /proc/loadavg");
        if ($loadAvg) {
            $data['load_average'] = trim($loadAvg);
        }
        
        // Uptime
        $uptime = $this->executeCommand("cat /proc/uptime");
        if ($uptime) {
            $uptimeParts = explode(' ', trim($uptime));
            $data['uptime'] = intval(floatval($uptimeParts[0]));
        }
        
        // Network Stats
        $netStats = $this->executeCommand("cat /proc/net/dev | grep -E 'eth0|ens|enp' | head -1");
        if ($netStats) {
            $netParts = preg_split('/\s+/', trim($netStats));
            if (count($netParts) >= 10) {
                $data['network_rx'] = intval($netParts[1]);
                $data['network_tx'] = intval($netParts[9]);
            }
        }
        
        return $data;
    }
    
    public function testConnection() {
        $result = $this->connect();
        if ($result) {
            $this->disconnect();
        }
        return $result;
    }
    
    public function disconnect() {
        if ($this->connection) {
            ssh2_disconnect($this->connection);
            $this->connection = null;
        }
    }
    
    public function __destruct() {
        $this->disconnect();
    }
}
?>