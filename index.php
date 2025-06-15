<?php
session_start();
require_once 'config/config.php';
require_once 'classes/Database.php';
require_once 'classes/SSHConnection.php';

$db = new Database();
$servers = $db->fetchAll("SELECT * FROM servers ORDER BY name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Server Monitoring Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐝</text></svg>">
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <h1 class="logo">
                    <span class="bee-icon">🐝</span>
                    ServerBee
                    <span class="version">v<?php echo APP_VERSION; ?></span>
                </h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="addServer()">
                        <span class="icon">➕</span>
                        Add Server
                    </button>
                    <button class="btn btn-secondary" onclick="refreshAll()">
                        <span class="icon">🔄</span>
                        Refresh All
                    </button>
                </div>
            </div>
        </header>

        <main class="main-content">
            <?php if (empty($servers)): ?>
            <div class="empty-state">
                <div class="empty-icon">🐝</div>
                <h2>Welcome to ServerBee!</h2>
                <p>No servers added yet. Add your first server to start monitoring.</p>
                <button class="btn btn-primary btn-large" onclick="addServer()">
                    Add Your First Server
                </button>
            </div>
            <?php else: ?>
            <div class="servers-grid" id="serversGrid">
                <?php foreach ($servers as $server): ?>
                <div class="server-card" data-server-id="<?php echo $server['id']; ?>">
                    <div class="server-header">
                        <div class="server-info">
                            <h3 class="server-name"><?php echo htmlspecialchars($server['name']); ?></h3>
                            <span class="server-host"><?php echo htmlspecialchars($server['host']); ?></span>
                        </div>
                        <div class="server-status status-<?php echo $server['status']; ?>">
                            <span class="status-dot"></span>
                            <?php echo ucfirst($server['status']); ?>
                        </div>
                    </div>
                    
                    <div class="server-metrics">
                        <div class="metric">
                            <div class="metric-label">CPU</div>
                            <div class="metric-value">
                                <span class="value" id="cpu-<?php echo $server['id']; ?>">--</span>%
                            </div>
                            <div class="metric-bar">
                                <div class="metric-progress" id="cpu-bar-<?php echo $server['id']; ?>"></div>
                            </div>
                        </div>
                        
                        <div class="metric">
                            <div class="metric-label">Memory</div>
                            <div class="metric-value">
                                <span class="value" id="memory-<?php echo $server['id']; ?>">--</span>%
                            </div>
                            <div class="metric-bar">
                                <div class="metric-progress" id="memory-bar-<?php echo $server['id']; ?>"></div>
                            </div>
                        </div>
                        
                        <div class="metric">
                            <div class="metric-label">Disk</div>
                            <div class="metric-value">
                                <span class="value" id="disk-<?php echo $server['id']; ?>">--</span>%
                            </div>
                            <div class="metric-bar">
                                <div class="metric-progress" id="disk-bar-<?php echo $server['id']; ?>"></div>
                            </div>
                        </div>
                        
                        <div class="metric">
                            <div class="metric-label">Load Avg</div>
                            <div class="metric-value">
                                <span class="value" id="load-<?php echo $server['id']; ?>">--</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="server-actions">
                        <button class="btn btn-small" onclick="openTerminal(<?php echo $server['id']; ?>)">
                            Terminal
                        </button>
                        <button class="btn btn-small" onclick="viewDetails(<?php echo $server['id']; ?>)">
                            Details
                        </button>
                        <button class="btn btn-small btn-danger" onclick="editServer(<?php echo $server['id']; ?>)">
                            Edit
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modals -->
    <div id="addServerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Server</h3>
                <button class="modal-close" onclick="closeModal('addServerModal')">&times;</button>
            </div>
            <form id="addServerForm">
                <div class="form-group">
                    <label for="serverName">Server Name</label>
                    <input type="text" id="serverName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="serverHost">Host/IP Address</label>
                    <input type="text" id="serverHost" name="host" required>
                </div>
                <div class="form-group">
                    <label for="serverPort">Port</label>
                    <input type="number" id="serverPort" name="port" value="22" required>
                </div>
                <div class="form-group">
                    <label for="serverUsername">Username</label>
                    <input type="text" id="serverUsername" name="username" required>
                </div>
                <div class="form-group">
                    <label for="serverPassword">Password</label>
                    <input type="password" id="serverPassword" name="password">
                </div>
                <div class="form-group">
                    <label for="serverDescription">Description (Optional)</label>
                    <textarea id="serverDescription" name="description"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addServerModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Server</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>