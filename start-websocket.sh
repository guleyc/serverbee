#!/bin/bash
# ServerBee WebSocket Server Starter

echo "🐝 Starting ServerBee WebSocket Server..."

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed"
    exit 1
fi

# Check if SSH2 extension is available
php -m | grep -q ssh2
if [ $? -ne 0 ]; then
    echo "⚠️  SSH2 extension not found. Installing..."
    
    # Try to install SSH2 extension
    if command -v apt-get &> /dev/null; then
        sudo apt-get update && sudo apt-get install -y php-ssh2
    elif command -v yum &> /dev/null; then
        sudo yum install -y php-ssh2
    else
        echo "❌ Please install php-ssh2 extension manually"
        exit 1
    fi
fi

# Make script executable
chmod +x start-websocket.sh

# Check if Composer is available
if command -v composer &> /dev/null; then
    echo "📦 Installing ReactPHP dependencies..."
    composer require ratchet/pawl react/socket react/http --no-interaction
fi

# Start WebSocket server
echo "🚀 Starting WebSocket server on localhost:8080"
php websocket/server.php

echo "✅ WebSocket server started!"