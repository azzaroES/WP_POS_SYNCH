const fs = require('fs');
const path = require('path');
const SftpClient = require('ssh2-sftp-client');
require('dotenv').config({ path: path.join(__dirname, '../.env') }); // Look for .env in parent or local folder

/**
 * Standalone Plugin Deployment Script
 * Usage: 
 *   node deploy.js
 */

async function connectSftp() {
    const sftp = new SftpClient();
    try {
        await sftp.connect({
            host: process.env.DEPLOY_SSH_HOST,
            port: process.env.DEPLOY_SSH_PORT,
            username: process.env.DEPLOY_SSH_USER,
            password: process.env.DEPLOY_SSH_PASS
        });
        return sftp;
    } catch (err) {
        console.error("SFTP Connection Failed:", err.message);
        throw err;
    }
}

async function deployPlugin(sftp) {
    console.log("--- Deploying WP_POS_SYNCH Plugin to Production ---");
    
    // Remote path structure
    const remotePluginsDir = path.posix.dirname(process.env.DEPLOY_WOO_PLUGIN_PATH);
    const targetDir = path.posix.join('./', remotePluginsDir, 'WP_POS_SYNCH');

    console.log(`Target Directory: ${targetDir}`);

    const exists = await sftp.exists(targetDir);
    if (!exists) {
        console.log(`Creating directory ${targetDir}...`);
        await sftp.mkdir(targetDir, true);
    }

    // Upload core files
    const filesToUpload = [
        'pos-woo-rules-sync.php',
        'README.md'
    ];

    for (const file of filesToUpload) {
        const localPath = path.join(__dirname, file);
        const remotePath = path.posix.join(targetDir, file);
        if (fs.existsSync(localPath)) {
            console.log(`Uploading ${file}...`);
            await sftp.fastPut(localPath, remotePath);
        }
    }

    // Upload src directory
    const localSrc = path.join(__dirname, 'src');
    const remoteSrc = path.posix.join(targetDir, 'src');
    console.log(`Uploading ${localSrc} folder to ${remoteSrc}...`);
    await sftp.uploadDir(localSrc, remoteSrc);

    console.log("\nPlugin deployment successful!");
}

async function main() {
    // Basic check for credentials
    if (!process.env.DEPLOY_SSH_HOST) {
        console.error("Error: DEPLOY_SSH_HOST not found in environment. Ensure .env is present in this folder or parent.");
        process.exit(1);
    }

    const sftp = await connectSftp();

    try {
        await deployPlugin(sftp);
    } catch (err) {
        console.error("Deployment failed:", err.message);
        process.exit(1);
    } finally {
        await sftp.end();
    }
}

main();
