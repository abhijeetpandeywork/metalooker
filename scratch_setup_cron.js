const { Client } = require('ssh2');

const config = {
    host: '147.93.23.184',
    port: 65002,
    username: 'u406313474',
    password: 'Gaurav@20221'
};

const conn = new Client();

conn.on('ready', () => {
    console.log('✅ SSH Connected.');

    const cmd = '/usr/bin/php /home/u406313474/domains/metalooker.digitalrubix.site/public_html/cron/sync_all.php';
    conn.exec(cmd, (err, stream) => {
        let out = '';
        stream.on('data', d => out += d.toString());
        stream.stderr.on('data', d => out += d.toString());
        stream.on('close', () => {
            console.log('--- PHP Cron Execution Test ---');
            console.log(out || '(No output)');
            console.log('-------------------------------');
            conn.end();
        });
    });
}).connect(config);
