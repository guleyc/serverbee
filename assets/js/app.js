// ServerBee - Frontend JavaScript
class ServerBee {
    constructor() {
        this.servers = new Map();
        this.updateInterval = null;
        this.websocket = null;
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadServers();
        this.startAutoRefresh();
        this.connectWebSocket();
    }

    bindEvents() {
        // Form submissions
        const addServerForm = document.getElementById('addServerForm');
        if (addServerForm) {
            addServerForm.addEventListener('submit', (e) => this.handleAddServer(e));
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeAllModals();
            }
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                this.refreshAll();
            }
        });

        // Click outside modal to close
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                this.closeModal(e.target.id);
            }
        });
    }

    async loadServers() {
        try {
            const response = await fetch('api/servers.php');
            const data = await response.json();
            
            if (data.success) {
                data.servers.forEach(server => {
                    this.servers.set(server.id, server);
                });
                this.refreshServerData();
            }
        } catch (error) {
            console.error('Failed to load servers:', error);
            this.showNotification('Failed to load servers', 'error');
        }
    }

    async refreshServerData() {
        const serverCards = document.querySelectorAll('.server-card');
        
        for (const card of serverCards) {
            const serverId = parseInt(card.dataset.serverId);
            this.updateServerCard(serverId);
        }
    }

    async updateServerCard(serverId) {
        try {
            const response = await fetch(`api/monitor.php?server_id=${serverId}`);
            const data = await response.json();
            
            if (data.success) {
                this.updateServerUI(serverId, data.data);
            } else {
                this.updateServerStatus(serverId, 'offline');
            }
        } catch (error) {
            console.error(`Failed to update server ${serverId}:`, error);
            this.updateServerStatus(serverId, 'offline');
        }
    }

    updateServerUI(serverId, data) {
        // Update CPU
        const cpuElement = document.getElementById(`cpu-${serverId}`);
        const cpuBar = document.getElementById(`cpu-bar-${serverId}`);
        if (cpuElement && data.cpu_usage !== undefined) {
            const cpuValue = Math.round(data.cpu_usage);
            cpuElement.textContent = cpuValue;
            if (cpuBar) {
                cpuBar.style.width = `${cpuValue}%`;
                cpuBar.style.background = this.getMetricColor(cpuValue);
            }
        }

        // Update Memory
        const memoryElement = document.getElementById(`memory-${serverId}`);
        const memoryBar = document.getElementById(`memory-bar-${serverId}`);
        if (memoryElement && data.memory_total && data.memory_used) {
            const memoryPercent = Math.round((data.memory_used / data.memory_total) * 100);
            memoryElement.textContent = memoryPercent;
            if (memoryBar) {
                memoryBar.style.width = `${memoryPercent}%`;
                memoryBar.style.background = this.getMetricColor(memoryPercent);
            }
        }

        // Update Disk
        const diskElement = document.getElementById(`disk-${serverId}`);
        const diskBar = document.getElementById(`disk-bar-${serverId}`);
        if (diskElement && data.disk_total && data.disk_used) {
            const diskPercent = Math.round((data.disk_used / data.disk_total) * 100);
            diskElement.textContent = diskPercent;
            if (diskBar) {
                diskBar.style.width = `${diskPercent}%`;
                diskBar.style.background = this.getMetricColor(diskPercent);
            }
        }

        // Update Load Average
        const loadElement = document.getElementById(`load-${serverId}`);
        if (loadElement && data.load_average) {
            const loadParts = data.load_average.split(' ');
            loadElement.textContent = loadParts[0] || '--';
        }

        // Update server status
        this.updateServerStatus(serverId, 'online');
    }

    updateServerStatus(serverId, status) {
        const serverCard = document.querySelector(`[data-server-id="${serverId}"]`);
        if (serverCard) {
            const statusElement = serverCard.querySelector('.server-status');
            if (statusElement) {
                statusElement.className = `server-status status-${status}`;
                statusElement.innerHTML = `
                    <span class="status-dot"></span>
                    ${status.charAt(0).toUpperCase() + status.slice(1)}
                `;
            }
        }
    }

    getMetricColor(percentage) {
        if (percentage < 50) return '#10b981'; // Green
        if (percentage < 80) return '#f59e0b'; // Yellow
        return '#ef4444'; // Red
    }

    async handleAddServer(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const serverData = Object.fromEntries(formData);
        
        try {
            const response = await fetch('api/servers.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(serverData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showNotification('Server added successfully', 'success');
                this.closeModal('addServerModal');
                e.target.reset();
                location.reload(); // Simple reload for now
            } else {
                this.showNotification(result.message || 'Failed to add server', 'error');
            }
        } catch (error) {
            console.error('Error adding server:', error);
            this.showNotification('Failed to add server', 'error');
        }
    }

    startAutoRefresh() {
        this.updateInterval = setInterval(() => {
            this.refreshServerData();
        }, 30000); // Update every 30 seconds
    }

    stopAutoRefresh() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
    }

    connectWebSocket() {
        try {
            this.websocket = new WebSocket(`ws://localhost:8080`);
            
            this.websocket.onopen = () => {
                console.log('WebSocket connected');
            };
            
            this.websocket.onmessage = (event) => {
                const data = JSON.parse(event.data);
                if (data.type === 'server_update') {
                    this.updateServerUI(data.server_id, data.data);
                }
            };
            
            this.websocket.onclose = () => {
                console.log('WebSocket disconnected');
                // Attempt to reconnect after 5 seconds
                setTimeout(() => this.connectWebSocket(), 5000);
            };
            
            this.websocket.onerror = (error) => {
                console.error('WebSocket error:', error);
            };
        } catch (error) {
            console.error('Failed to connect WebSocket:', error);
        }
    }

    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <span>${message}</span>
            <button onclick="this.parentElement.remove()">&times;</button>
        `;
        
        // Add styles if not already present
        if (!document.querySelector('#notification-styles')) {
            const styles = document.createElement('style');
            styles.id = 'notification-styles';
            styles.textContent = `
                .notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 1rem 1.5rem;
                    border-radius: 8px;
                    color: white;
                    font-weight: 500;
                    z-index: 1001;
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                    animation: slideInRight 0.3s ease;
                }
                .notification-success { background: #10b981; }
                .notification-error { background: #ef4444; }
                .notification-info { background: #3b82f6; }
                .notification button {
                    background: none;
                    border: none;
                    color: white;
                    font-size: 1.2rem;
                    cursor: pointer;
                    padding: 0;
                }
                @keyframes slideInRight {
                    from { transform: translateX(100%); }
                    to { transform: translateX(0); }
                }
            `;
            document.head.appendChild(styles);
        }
        
        document.body.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }

    // Modal functions
    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    closeAllModals() {
        const modals = document.querySelectorAll('.modal.active');
        modals.forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
}

// Global functions for HTML onclick events
function addServer() {
    serverBee.openModal('addServerModal');
}

function refreshAll() {
    serverBee.refreshServerData();
    serverBee.showNotification('Refreshing all servers...', 'info');
}

function openTerminal(serverId) {
    // TODO: Implement terminal modal
    serverBee.showNotification('Terminal feature coming soon!', 'info');
}

function viewDetails(serverId) {
    // TODO: Implement server details modal
    serverBee.showNotification('Server details coming soon!', 'info');
}

function editServer(serverId) {
    // TODO: Implement edit server modal
    serverBee.showNotification('Edit server feature coming soon!', 'info');
}

function closeModal(modalId) {
    serverBee.closeModal(modalId);
}

// Initialize ServerBee when DOM is loaded
let serverBee;
document.addEventListener('DOMContentLoaded', () => {
    serverBee = new ServerBee();
});

// Handle page visibility change
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        serverBee.stopAutoRefresh();
    } else {
        serverBee.startAutoRefresh();
    }
});