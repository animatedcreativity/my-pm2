#!/bin/bash

echo "Starting PM2 Manager on port 3011..."
echo "Dashboard: http://localhost:3011"
echo "Login: admin / admin-my-pm2"
echo ""

mkdir -p commands
chmod 777 commands

php -S localhost:3011
