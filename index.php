<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PM2 Manager</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect x='0' y='5' width='100' height='38' rx='6' fill='%233b82f6'/><circle cx='20' cy='24' r='5' fill='white'/><circle cx='40' cy='24' r='5' fill='white'/><rect x='0' y='57' width='100' height='38' rx='6' fill='%233b82f6'/><circle cx='20' cy='76' r='5' fill='white'/><circle cx='40' cy='76' r='5' fill='white'/></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .status-online { color: #10b981; }
        .status-offline { color: #ef4444; }
        .status-stopping { color: #f59e0b; }
        .process-card { transition: all 0.3s ease; }
        .process-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="bg-gray-50">
    <div id="app"></div>
    
    <script>
        const API_URL = 'http://localhost:3011/api.php?request=';
        let token = localStorage.getItem('pm2_token');
        let currentView = 'processes';
        let servers = [];
        let processes = [];
        let logs = [];
        let pollingInterval = null;
        let lastUpdate = null;
        let selectedProcess = null;
        let processLogs = [];
        let showProcessForm = false;
        let processFormData = { serverId: null, serverName: '', isEdit: false, name: '', oldName: '', command: '', cwd: '' };
        let showAgentCommand = false;
        let agentCommandData = {};

        function setToken(newToken) {
            token = newToken;
            localStorage.setItem('pm2_token', newToken);
        }

        function clearToken() {
            token = null;
            localStorage.removeItem('pm2_token');
        }

        async function api(endpoint, options = {}) {
            const headers = { 'Content-Type': 'application/json' };
            if (token) headers['Authorization'] = `Bearer ${token}`;

            const response = await fetch(`${API_URL}${endpoint}`, {
                ...options,
                headers: { ...headers, ...options.headers }
            });

            if (response.status === 401) {
                clearToken();
                render();
                return null;
            }

            return response.json();
        }

        async function login(username, password) {
            const result = await api('login', {
                method: 'POST',
                body: JSON.stringify({ username, password })
            });

            if (result && result.token) {
                setToken(result.token);
                startPolling();
                await loadData();
                render();
            } else {
                alert('Invalid credentials');
            }
        }

        function logout() {
            clearToken();
            stopPolling();
            render();
        }

        async function loadData() {
            const [serversData, processesData, logsData] = await Promise.all([
                api('servers'),
                api('processes'),
                api('logs&limit=50')
            ]);

            if (serversData) servers = serversData;
            if (processesData) processes = processesData;
            if (logsData) logs = logsData;
            
            if (selectedProcess) {
                await loadProcessLogs(selectedProcess);
            }
        }
        
        async function loadProcessLogs(processName) {
            const data = await api(`logs&processName=${encodeURIComponent(processName)}&limit=100`);
            if (data) {
                processLogs = data;
                render();
            }
        }
        
        function viewProcessLogs(processName) {
            selectedProcess = processName;
            loadProcessLogs(processName);
        }
        
        function closeProcessLogs() {
            selectedProcess = null;
            processLogs = [];
            render();
        }
        
        function showAddProcessForm(serverId, serverName) {
            processFormData = { serverId, serverName, isEdit: false, name: '', command: '', cwd: '' };
            showProcessForm = true;
            render();
        }
        
        function showEditProcessForm(serverId, serverName, processName, currentScript, currentCwd) {
            processFormData = { 
                serverId, 
                serverName, 
                isEdit: true, 
                name: processName,
                oldName: processName, 
                command: currentScript || '', 
                cwd: currentCwd || '' 
            };
            showProcessForm = true;
            render();
        }
        
        function closeProcessForm() {
            showProcessForm = false;
            processFormData = { serverId: null, serverName: '', isEdit: false, name: '', oldName: '', command: '', cwd: '' };
            render();
        }
        
        async function submitProcessForm() {
            if (!processFormData.name || !processFormData.command) {
                alert('Name and Command are required');
                return;
            }
            
            try {
                const action = processFormData.isEdit ? 'edit' : 'create';
                const payload = {
                    processName: processFormData.name,
                    script: processFormData.command,
                    cwd: processFormData.cwd || null
                };
                
                if (processFormData.isEdit && processFormData.oldName !== processFormData.name) {
                    payload.oldProcessName = processFormData.oldName;
                }
                
                const result = await api(`processes/${processFormData.serverId}/${action}`, {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                
                closeProcessForm();
                await loadData();
                render();
            } catch (error) {
                console.error('Error creating/editing process:', error);
                alert('Failed to ' + (processFormData.isEdit ? 'update' : 'create') + ' process: ' + error.message);
            }
        }

        function startPolling() {
            stopPolling();
            loadData().then(() => render());
            pollingInterval = setInterval(async () => {
                await loadData();
                lastUpdate = new Date();
                if (!showProcessForm) {
                    render();
                }
            }, 2000);
        }

        function stopPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }

        async function addServer(name, host) {
            const result = await api('servers', {
                method: 'POST',
                body: JSON.stringify({ name, host })
            });

            if (result) {
                agentCommandData = {
                    serverName: name,
                    uniqueKey: result.uniqueKey,
                    command: `./agent.php ${window.location.origin} ${result.uniqueKey}`
                };
                showAgentCommand = true;
                await loadData();
                render();
            }
        }

        async function deleteServer(id) {
            if (!confirm('Are you sure you want to delete this server?')) return;
            
            await api(`servers/${id}`, { method: 'DELETE' });
            await loadData();
            render();
        }

        async function controlProcess(serverId, processName, action) {
            await api(`processes/${serverId}/${action}`, {
                method: 'POST',
                body: JSON.stringify({ processName })
            });

            await loadData();
            render();
        }

        function formatBytes(bytes) {
            if (!bytes) return '0 B';
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return `${(bytes / Math.pow(1024, i)).toFixed(2)} ${sizes[i]}`;
        }

        function formatUptime(seconds) {
            if (!seconds) return '0s';
            const days = Math.floor(seconds / 86400);
            const hours = Math.floor((seconds % 86400) / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            
            if (days > 0) return `${days}d ${hours}h`;
            if (hours > 0) return `${hours}h ${minutes}m`;
            return `${minutes}m`;
        }

        function renderLogin() {
            return `
                <div class="min-h-screen flex items-center justify-center">
                    <div class="bg-white p-8 rounded-xl shadow-lg w-96">
                        <h1 class="text-3xl font-bold mb-6 text-center text-gray-800">
                            <i class="fas fa-server mr-2"></i>PM2 Manager
                        </h1>
                        <form onsubmit="event.preventDefault(); login(this.username.value, this.password.value);">
                            <div class="mb-4">
                                <label class="block text-gray-700 mb-2">Username</label>
                                <input type="text" name="username"
                                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="mb-6">
                                <label class="block text-gray-700 mb-2">Password</label>
                                <input type="password" name="password"
                                    class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <button type="submit" 
                                class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition">
                                Login
                            </button>
                        </form>
                    </div>
                </div>
            `;
        }

        function renderNav() {
            return `
                <nav class="bg-white shadow-sm border-b">
                    <div class="container mx-auto px-6 py-4">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-bold text-gray-800">
                                    <i class="fas fa-server mr-2 text-blue-600"></i>PM2 Manager
                                </h1>
                                <span class="text-xs text-gray-500 flex items-center gap-1">
                                    <i class="fas fa-circle text-green-500 animate-pulse"></i>
                                    Live ${lastUpdate ? '• Updated ' + lastUpdate.toLocaleTimeString() : ''}
                                </span>
                            </div>
                            <div class="flex gap-4 items-center">
                                <button onclick="currentView = 'processes'; render();" 
                                    class="px-4 py-2 rounded-lg ${currentView === 'processes' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'}">
                                    <i class="fas fa-list mr-2"></i>Processes
                                </button>
                                <button onclick="currentView = 'servers'; render();" 
                                    class="px-4 py-2 rounded-lg ${currentView === 'servers' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'}">
                                    <i class="fas fa-server mr-2"></i>Servers
                                </button>
                                <button onclick="currentView = 'logs'; render();" 
                                    class="px-4 py-2 rounded-lg ${currentView === 'logs' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100'}">
                                    <i class="fas fa-file-alt mr-2"></i>Logs
                                </button>
                                <button onclick="logout();" 
                                    class="px-4 py-2 text-red-600 hover:bg-red-50 rounded-lg">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                                </button>
                            </div>
                        </div>
                    </div>
                </nav>
            `;
        }

        function renderProcesses() {
            const groupedByServer = {};
            processes.forEach(p => {
                if (!groupedByServer[p.server_name]) {
                    groupedByServer[p.server_name] = {
                        processes: [],
                        status: p.server_status,
                        serverId: p.server_id
                    };
                }
                groupedByServer[p.server_name].processes.push(p);
            });

            return `
                <div class="container mx-auto px-6 py-8">
                    <h2 class="text-3xl font-bold mb-6 text-gray-800">All Processes</h2>
                    ${Object.entries(groupedByServer).map(([serverName, data]) => `
                        <div class="mb-8">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center">
                                    <h3 class="text-xl font-semibold text-gray-700 mr-3">${serverName}</h3>
                                    <span class="status-${data.status}">
                                        <i class="fas fa-circle text-xs"></i> ${data.status}
                                    </span>
                                </div>
                                ${data.status === 'online' ? `
                                    <button onclick="showAddProcessForm(${data.serverId}, '${serverName}')" 
                                        class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 text-sm">
                                        <i class="fas fa-plus mr-2"></i>Add Process
                                    </button>
                                ` : ''}
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                ${data.processes.map(p => `
                                    <div class="process-card bg-white rounded-lg shadow p-6">
                                        <div class="flex justify-between items-start mb-4">
                                            <div>
                                                <h4 class="font-semibold text-lg text-gray-800">${p.name}</h4>
                                                <span class="text-sm status-${p.status === 'online' ? 'online' : 'offline'}">
                                                    <i class="fas fa-circle text-xs"></i> ${p.status}
                                                </span>
                                            </div>
                                            <span class="text-sm text-gray-500">ID: ${p.pm_id}</span>
                                        </div>
                                        <div class="space-y-2 text-sm text-gray-600 mb-4">
                                            <div><i class="fas fa-microchip w-4"></i> CPU: ${p.cpu}%</div>
                                            <div><i class="fas fa-memory w-4"></i> Memory: ${formatBytes(p.memory)}</div>
                                            <div><i class="fas fa-clock w-4"></i> Uptime: ${formatUptime(p.uptime)}</div>
                                            <div><i class="fas fa-redo w-4"></i> Restarts: ${p.restarts}</div>
                                        </div>
                                        ${p.last_output_message ? `
                                            <div class="mb-2 p-3 bg-gray-50 rounded border-l-4 border-blue-500">
                                                <div class="flex items-start justify-between gap-2 mb-1">
                                                    <span class="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700">
                                                        output
                                                    </span>
                                                    <div class="text-xs text-gray-500">
                                                        ${p.last_output_timestamp ? new Date(p.last_output_timestamp).toLocaleString() : ''}
                                                    </div>
                                                </div>
                                                <div class="text-xs font-mono text-gray-700 break-words" title="${p.last_output_message}">
                                                    ${p.last_output_message.length > 200 ? p.last_output_message.substring(0, 200) + '...' : p.last_output_message}
                                                </div>
                                            </div>
                                        ` : ''}
                                        ${p.last_error_message ? `
                                            <div class="mb-4 p-3 bg-gray-50 rounded border-l-4 border-gray-400">
                                                <div class="flex items-start justify-between gap-2 mb-1">
                                                    <span class="px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-700">
                                                        stderr
                                                    </span>
                                                    <div class="text-xs text-gray-500">
                                                        ${p.last_error_timestamp ? new Date(p.last_error_timestamp).toLocaleString() : ''}
                                                    </div>
                                                </div>
                                                <div class="text-xs font-mono text-gray-700 break-words" title="${p.last_error_message}">
                                                    ${p.last_error_message.length > 200 ? p.last_error_message.substring(0, 200) + '...' : p.last_error_message}
                                                </div>
                                            </div>
                                        ` : ''}
                                        ${data.status === 'online' ? `
                                            <div class="flex gap-2">
                                                <button onclick="controlProcess(${data.serverId}, '${p.name}', 'restart')"
                                                    class="flex-1 bg-blue-500 text-white px-3 py-2 rounded hover:bg-blue-600 text-sm">
                                                    <i class="fas fa-redo"></i> Restart
                                                </button>
                                                ${p.status === 'online' ? `
                                                    <button onclick="controlProcess(${data.serverId}, '${p.name}', 'stop')"
                                                        class="flex-1 bg-orange-500 text-white px-3 py-2 rounded hover:bg-orange-600 text-sm">
                                                        <i class="fas fa-stop"></i> Stop
                                                    </button>
                                                ` : `
                                                    <button onclick="controlProcess(${data.serverId}, '${p.name}', 'start')"
                                                        class="flex-1 bg-green-500 text-white px-3 py-2 rounded hover:bg-green-600 text-sm">
                                                        <i class="fas fa-play"></i> Start
                                                    </button>
                                                `}
                                                <button onclick="if(confirm('Delete ${p.name}?')) controlProcess(${data.serverId}, '${p.name}', 'delete')"
                                                    class="bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600 text-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            <div class="flex gap-2 mt-2">
                                                <button onclick="viewProcessLogs('${p.name}')"
                                                    class="flex-1 bg-gray-600 text-white px-3 py-2 rounded hover:bg-gray-700 text-sm">
                                                    <i class="fas fa-file-alt"></i> Logs
                                                </button>
                                                <button 
                                                    data-server-id="${data.serverId}"
                                                    data-server-name="${btoa(serverName)}"
                                                    data-process-name="${btoa(p.name)}"
                                                    data-script="${btoa(p.script || '')}"
                                                    data-cwd="${btoa(p.cwd || '')}"
                                                    onclick="showEditProcessForm(
                                                        parseInt(this.dataset.serverId), 
                                                        atob(this.dataset.serverName), 
                                                        atob(this.dataset.processName), 
                                                        atob(this.dataset.script), 
                                                        atob(this.dataset.cwd)
                                                    )"
                                                    class="flex-1 bg-purple-600 text-white px-3 py-2 rounded hover:bg-purple-700 text-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                            </div>
                                        ` : '<p class="text-sm text-gray-500 text-center">Server offline</p>'}
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `).join('')}
                    ${processes.length === 0 ? '<p class="text-center text-gray-500 py-12">No processes found. Add a server and connect an agent.</p>' : ''}
                </div>
            `;
        }

        function renderServers() {
            return `
                <div class="container mx-auto px-6 py-8">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-3xl font-bold text-gray-800">Servers</h2>
                        <button onclick="showAddServerModal()" 
                            class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i>Add Server
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        ${servers.map(s => `
                            <div class="bg-white rounded-lg shadow p-6">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h3 class="text-xl font-semibold text-gray-800">${s.name}</h3>
                                        <p class="text-sm text-gray-500">${s.host || 'No host specified'}</p>
                                    </div>
                                    <span class="status-${s.status}">
                                        <i class="fas fa-circle text-xs"></i> ${s.status}
                                    </span>
                                </div>
                                <div class="space-y-2 text-sm text-gray-600 mb-4">
                                    <div><strong>Key:</strong> <code class="bg-gray-100 px-2 py-1 rounded text-xs">${s.unique_key}</code></div>
                                    <div><strong>Last seen:</strong> ${s.last_seen ? new Date(s.last_seen).toLocaleString() : 'Never'}</div>
                                </div>
                                <div class="mb-3 p-3 bg-gray-900 rounded">
                                    <code class="text-green-400 text-xs font-mono break-all">./agent.php ${window.location.origin} ${s.unique_key}</code>
                                </div>
                                <div class="flex gap-2">
                                    <button onclick="copyServerCommand(event, '${s.unique_key}')" 
                                        class="flex-1 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                                        <i class="fas fa-copy mr-2"></i>Copy Command
                                    </button>
                                    <button onclick="deleteServer(${s.id})" 
                                        class="flex-1 bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600 text-sm">
                                        <i class="fas fa-trash mr-2"></i>Delete
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        function renderLogs() {
            return `
                <div class="container mx-auto px-6 py-8">
                    <h2 class="text-3xl font-bold mb-6 text-gray-800">Recent Logs</h2>
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50 border-b">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Server</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Process</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    ${logs.map(l => `
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 text-sm text-gray-500">${new Date(l.timestamp).toLocaleTimeString()}</td>
                                            <td class="px-6 py-4 text-sm text-gray-900">${l.server_name}</td>
                                            <td class="px-6 py-4 text-sm text-gray-900">${l.process_name}</td>
                                            <td class="px-6 py-4 text-sm">
                                                <span class="px-2 py-1 rounded text-xs ${l.type === 'error' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'}">
                                                    ${l.type}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600">${l.message}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        ${logs.length === 0 ? '<p class="text-center text-gray-500 py-8">No logs yet</p>' : ''}
                    </div>
                </div>
            `;
        }

        function showAddServerModal() {
            const name = prompt('Enter server name:');
            if (!name) return;
            const host = prompt('Enter server host (optional):') || '';
            addServer(name, host);
        }

        function closeAgentCommandModal() {
            showAgentCommand = false;
            agentCommandData = {};
            render();
        }

        function copyAgentCommand() {
            const command = agentCommandData.command;
            navigator.clipboard.writeText(command).then(() => {
                const btn = event.target.closest('button');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check mr-2"></i>Copied!';
                btn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                btn.classList.add('bg-green-600');
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('bg-green-600');
                    btn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                }, 2000);
            }).catch(err => {
                alert('Failed to copy: ' + err);
            });
        }

        function copyServerCommand(event, uniqueKey) {
            const command = `./agent.php ${window.location.origin} ${uniqueKey}`;
            navigator.clipboard.writeText(command).then(() => {
                const btn = event.target.closest('button');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check mr-2"></i>Copied!';
                btn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                btn.classList.add('bg-green-600');
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('bg-green-600');
                    btn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                }, 2000);
            }).catch(err => {
                alert('Failed to copy: ' + err);
            });
        }

        function renderAgentCommandModal() {
            if (!showAgentCommand) return '';
            
            return `
                <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" onclick="if(event.target === this) closeAgentCommandModal()">
                    <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-2xl mx-4">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-check-circle text-green-500 mr-2"></i>Server Added Successfully!
                            </h3>
                            <button onclick="closeAgentCommandModal()" class="text-gray-500 hover:text-gray-700">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        
                        <div class="mb-4">
                            <p class="text-gray-600 mb-2">Server <strong>${agentCommandData.serverName}</strong> has been created.</p>
                            <p class="text-gray-600">Copy and run this command on your server to connect the agent:</p>
                        </div>
                        
                        <div class="bg-gray-900 rounded-lg p-4 mb-4">
                            <code class="text-green-400 text-sm font-mono break-all">${agentCommandData.command}</code>
                        </div>
                        
                        <div class="flex gap-3">
                            <button onclick="copyAgentCommand()" 
                                class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                                <i class="fas fa-copy mr-2"></i>Copy Command
                            </button>
                            <button onclick="closeAgentCommandModal()" 
                                class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition">
                                Close
                            </button>
                        </div>
                        
                        <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                            <p class="text-sm text-gray-700">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Keep agent running with PM2:</strong>
                            </p>
                            <code class="block mt-2 text-xs bg-white p-2 rounded border text-gray-800">pm2 start agent.php --name pm2-agent --interpreter php -- ${agentCommandData.command.replace('./agent.php ', '')}</code>
                        </div>
                    </div>
                </div>
            `;
        }

        function renderProcessFormModal() {
            if (!showProcessForm) return '';
            
            return `
                <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" onclick="closeProcessForm()">
                    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl" onclick="event.stopPropagation()">
                        <div class="flex justify-between items-center p-6 border-b">
                            <h2 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-${processFormData.isEdit ? 'edit' : 'plus-circle'} mr-2 text-blue-600"></i>
                                ${processFormData.isEdit ? 'Edit' : 'Add'} Process - ${processFormData.serverName}
                            </h2>
                            <button onclick="closeProcessForm()" class="text-gray-500 hover:text-gray-700">
                                <i class="fas fa-times text-2xl"></i>
                            </button>
                        </div>
                        <div class="p-6">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-tag mr-2"></i>Process Name *
                                    </label>
                                    <input type="text" 
                                        value="${processFormData.name}"
                                        oninput="processFormData.name = this.value"
                                        class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="my-app">
                                    ${processFormData.isEdit ? '<p class="text-xs text-gray-500 mt-1">Renaming will delete the old process and create a new one</p>' : ''}
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-terminal mr-2"></i>Command *
                                    </label>
                                    <input type="text" 
                                        value="${processFormData.command}"
                                        oninput="processFormData.command = this.value"
                                        class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="node app.js or npm start">
                                    <p class="text-xs text-gray-500 mt-1">The script or command to execute</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-folder mr-2"></i>Working Directory (optional)
                                    </label>
                                    <input type="text" 
                                        value="${processFormData.cwd}"
                                        oninput="processFormData.cwd = this.value"
                                        class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="/path/to/project or leave empty for agent's current directory">
                                    <p class="text-xs text-gray-500 mt-1">Absolute path where the process should run</p>
                                </div>
                            </div>
                            <div class="flex gap-3 mt-6">
                                <button onclick="submitProcessForm()" 
                                    class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-medium">
                                    <i class="fas fa-${processFormData.isEdit ? 'save' : 'plus'} mr-2"></i>
                                    ${processFormData.isEdit ? 'Update' : 'Create'} Process
                                </button>
                                <button onclick="closeProcessForm()" 
                                    class="px-6 py-3 border rounded-lg hover:bg-gray-100">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function renderProcessLogsModal() {
            if (!selectedProcess) return '';
            
            return `
                <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" onclick="closeProcessLogs()">
                    <div class="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] overflow-hidden" onclick="event.stopPropagation()">
                        <div class="flex justify-between items-center p-6 border-b">
                            <h2 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-file-alt mr-2 text-blue-600"></i>Logs: ${selectedProcess}
                            </h2>
                            <button onclick="closeProcessLogs()" class="text-gray-500 hover:text-gray-700">
                                <i class="fas fa-times text-2xl"></i>
                            </button>
                        </div>
                        <div class="p-6 overflow-y-auto" style="max-height: calc(90vh - 120px);">
                            <div class="bg-gray-900 text-gray-100 p-4 rounded font-mono text-sm space-y-1">
                                ${processLogs.length > 0 ? processLogs.map(log => `
                                    <div class="flex gap-3 hover:bg-gray-800 px-2 py-1 rounded">
                                        <span class="text-gray-500 text-xs">${new Date(log.timestamp).toLocaleString()}</span>
                                        <span class="px-2 py-0.5 rounded text-xs ${log.type === 'error' ? 'bg-red-900 text-red-200' : 'bg-blue-900 text-blue-200'}">
                                            ${log.type}
                                        </span>
                                        <span class="flex-1 ${log.type === 'error' ? 'text-red-300' : 'text-gray-300'}">${log.message}</span>
                                    </div>
                                `).join('') : '<p class="text-gray-400 text-center py-8">No logs available for this process</p>'}
                            </div>
                            <div class="mt-4 text-sm text-gray-600">
                                <i class="fas fa-info-circle mr-2"></i>Showing latest 100 log entries (auto-refreshes every 2 seconds)
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function render() {
            const app = document.getElementById('app');
            
            if (!token) {
                app.innerHTML = renderLogin();
                return;
            }

            let content = '';
            if (currentView === 'processes') content = renderProcesses();
            else if (currentView === 'servers') content = renderServers();
            else if (currentView === 'logs') content = renderLogs();

            app.innerHTML = renderNav() + content + renderProcessFormModal() + renderProcessLogsModal() + renderAgentCommandModal();
        }

        if (token) {
            loadData().then(() => {
                render();
                startPolling();
            });
        } else {
            render();
        }
    </script>
</body>
</html>
