# PM2 Manager

A beautiful, self-hosted PM2 process manager with centralized monitoring across multiple servers.

## Features

- 🎨 Modern, polished GUI
- 🔐 Simple authentication (admin / admin-my-pm2)
- 🖥️ Monitor multiple servers from one dashboard
- 🚀 Start, stop, restart, delete processes remotely
- 📊 Real-time CPU, memory, uptime stats
- 📝 Centralized log viewing
- 💾 SQLite database (no external dependencies)

## Installation

### Server (Control Panel)

1. Set environment variable for JWT secret:
```bash
export JWT_SECRET="your-random-secure-secret-key-here"
```

2. Create commands directory:
```bash
mkdir -p commands
chmod 777 commands
```

3. Start the server on port 3011:
```bash
php -S localhost:3011
```

4. Access the dashboard:
   - Open: http://localhost:3011
   - Login: admin / admin-my-pm2

### Agent (On Each Server)

1. Copy `agent.php` to your server

2. Make it executable:
```bash
chmod +x agent.php
```

3. Add a server in the GUI:
   - Go to "Servers" tab
   - Click "Add Server"
   - Enter server name and host
   - Copy the unique key shown

4. Run the agent:
```bash
./agent.php http://YOUR-CONTROL-SERVER:3011 YOUR-UNIQUE-KEY
```

5. Keep agent running with PM2:
```bash
pm2 start agent.php --name pm2-agent --interpreter php -- http://YOUR-CONTROL-SERVER:3011 YOUR-UNIQUE-KEY
pm2 save
```

## Usage

### Dashboard Views

- **Processes**: View all processes across all servers, control them remotely
- **Servers**: Manage connected servers, view connection status
- **Logs**: View recent logs from all processes

### Process Controls

- ▶️ Start: Start a stopped process
- ⏸️ Stop: Stop a running process
- 🔄 Restart: Restart a process
- 🗑️ Delete: Remove a process from PM2

## Default Credentials

- Username: `admin`
- Password: `admin-my-pm2`

## Requirements

- PHP 7.4 or higher
- SQLite PDO extension
- PM2 installed on monitored servers
- curl (for agent)

## Architecture

- **Frontend**: Single-page app with Tailwind CSS
- **Backend**: PHP REST API
- **Database**: SQLite
- **Communication**: Agent polls server every 5 seconds
- **Port**: 3011

## Security Notes

⚠️ **Important for Production:**

1. **Set JWT_SECRET environment variable** - Never use the default secret
2. **Change default password** - First login: `admin` / `admin-my-pm2`, then change it
3. **Use HTTPS** - Never expose over plain HTTP in production
4. **Firewall rules** - Restrict access to control panel port (3011)
5. **Secure agent keys** - Keep server unique keys private
6. **Use reverse proxy** - nginx/apache with proper security headers

**Quick security setup:**
```bash
export JWT_SECRET="$(openssl rand -base64 32)"
echo "JWT_SECRET=$JWT_SECRET" >> ~/.bashrc
```
