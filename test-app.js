setInterval(() => {
    console.log(`[${new Date().toISOString()}] Test app is running - Hello from PM2 Manager!`);
    console.error(`[${new Date().toISOString()}] This is an error log example`);
}, 3000);

console.log('Test PM2 application started!');
