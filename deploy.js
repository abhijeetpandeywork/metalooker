/**
 * Automated Production SSH/SFTP Deployment & Git Push Script
 * Targets Hostinger shared hosting environment for metalooker.digitalrubix.site
 */

const { Client } = require('ssh2');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const config = {
    host: '147.93.23.184',
    port: 65002,
    username: 'u406313474',
    password: 'Gaurav@20221'
};

const localDir = __dirname;

// List of relative local files to upload
const filesToDeploy = [
    'composer.json',
    '.htaccess',
    '.env',
    'cron/sync_all.php',
    'db/migrations/001_create_tables.sql',
    'db/migrations/002_seed_admin.sql',
    'public_html/index.php',
    'public_html/login.php',
    'public_html/logout.php',
    'public_html/dashboard.php',
    'public_html/oauth_callback.php',
    'public_html/admin/index.php',
    'public_html/admin/clients.php',
    'public_html/admin/client_edit.php',
    'public_html/admin/team.php',
    'public_html/admin/settings.php',
    'public_html/admin/sync_status.php',
    'public_html/api/sync.php',
    'public_html/api/batch_sync.php',
    'public_html/api/test_meta_app.php',
    'public_html/api/test_live_meta.php',
    'public_html/api/dashboard_data.php',
    'public_html/api/export_csv.php',
    'public_html/api/export_pdf.php',
    'public_html/assets/css/style.css',
    'public_html/assets/js/dashboard.js',
    'public_html/assets/logos/digital_rubix_logo.svg',
    'public_html/includes/.htaccess',
    'public_html/includes/config.php',
    'public_html/includes/db.php',
    'public_html/includes/auth.php',
    'public_html/includes/meta_api.php',
    'public_html/includes/token_manager.php',
    'public_html/includes/helpers.php'
];

/**
 * Maps local relative file path to remote destination relative to target web root.
 * If file starts with public_html/, strips that prefix so index.php goes to root of public_html.
 */
function getRemoteRelativePath(localRelativePath) {
    if (localRelativePath.startsWith('public_html/')) {
        return localRelativePath.substring('public_html/'.length);
    }
    return localRelativePath;
}

const conn = new Client();

console.log(' Connecting to Hostinger SSH server at ' + config.host + ':' + config.port + '...');

conn.on('ready', () => {
    console.log('✅ SSH Connection Established Successfully.');

    conn.sftp((err, sftp) => {
        if (err) {
            console.error('SFTP Connection Error:', err);
            conn.end();
            return;
        }

        const candidatePaths = [
            'domains/metalooker.digitalrubix.site/public_html',
            'public_html/metalooker.digitalrubix.site',
            'domains/metalooker.digitalrubix.site',
            'public_html'
        ];

        findTargetDir(sftp, candidatePaths, (targetDir) => {
            console.log('🚀 Target Remote Web Root Resolved: ' + targetDir);

            ensureRemoteDirs(sftp, targetDir, filesToDeploy, () => {
                uploadFiles(sftp, targetDir, filesToDeploy, () => {
                    console.log('🎉 Production Upload Completed Successfully!');
                    gitPushUpdates(() => {
                        console.log('🌐 Live Production Site: http://metalooker.digitalrubix.site');
                        conn.end();
                    });
                });
            });
        });
    });
}).connect(config);

function findTargetDir(sftp, candidates, callback) {
    let index = 0;
    function checkNext() {
        if (index >= candidates.length) {
            console.log('Fallback to default path: public_html');
            return callback('public_html');
        }
        const currentPath = candidates[index++];
        sftp.readdir(currentPath, (err) => {
            if (!err) {
                console.log('Found target directory:', currentPath);
                return callback(currentPath);
            }
            checkNext();
        });
    }
    checkNext();
}

function ensureRemoteDirs(sftp, remoteTarget, files, callback) {
    const dirs = new Set();
    files.forEach(f => {
        const remoteRel = getRemoteRelativePath(f);
        const parts = path.dirname(remoteRel).split(/[/\\]/);
        let current = '';
        parts.forEach(part => {
            if (part && part !== '.') {
                current = current ? current + '/' + part : part;
                dirs.add(current);
            }
        });
    });

    const sortedDirs = Array.from(dirs).sort((a, b) => a.length - b.length);
    let index = 0;

    function createNextDir() {
        if (index >= sortedDirs.length) {
            return callback();
        }
        const dirPath = remoteTarget + '/' + sortedDirs[index++];
        sftp.mkdir(dirPath, { mode: 0o755 }, (err) => {
            createNextDir();
        });
    }

    createNextDir();
}

function uploadFiles(sftp, remoteTarget, files, callback) {
    let index = 0;

    function uploadNext() {
        if (index >= files.length) {
            return callback();
        }
        const file = files[index++];
        const localPath = path.join(localDir, file);
        const remoteRel = getRemoteRelativePath(file);
        const remotePath = remoteTarget + '/' + remoteRel.replace(/\\/g, '/');

        if (!fs.existsSync(localPath)) {
            console.warn(`⚠️ Warning: Local file missing: ${localPath}`);
            uploadNext();
            return;
        }

        sftp.fastPut(localPath, remotePath, (err) => {
            if (err) {
                console.error(`❌ Failed uploading ${file}:`, err.message);
            } else {
                console.log(` Output: ${file} -> ${remotePath}`);
            }
            uploadNext();
        });
    }

    uploadNext();
}

function gitPushUpdates(callback) {
    console.log(' Syncing Git repository with GitHub remote...');
    try {
        execSync('git add .', { stdio: 'inherit' });
        try {
            execSync('git commit -m "Auto-deploy update: sync codebase & knowledge logs"', { stdio: 'inherit' });
        } catch (e) {
            console.log('No new changes to commit.');
        }
        execSync('git push origin main', { stdio: 'inherit' });
        console.log('✅ GitHub Push Completed Successfully.');
    } catch (e) {
        console.warn('⚠️ Git push warning:', e.message);
    }
    callback();
}
