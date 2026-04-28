#!/bin/bash

if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
fi

echo "Starting PM2 Manager on port 3011..."
echo "Database: ${DB_TYPE:-SQLite} (${DB_HOST:-local}/${DB_NAME:-pm2-manager.db})"
echo "Dashboard: http://localhost:3011"
echo "Login: admin / admin-my-pm2"
echo ""

mkdir -p commands
chmod 777 commands

php -S localhost:3011
