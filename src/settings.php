<?php
return [
    'settings' => [
        'upload_directory' => __DIR__ . '/../public/uploads/ceklis', // upload directory
        'upload_directory_bukti' => __DIR__ . '/../public/uploads/bukti', // upload directory bukti
        'upload_server_directory' => __DIR__ . '/../public/uploads/server', // upload directory
        'displayErrorDetails' => true, // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        // Renderer settings
        'renderer' => [
            'template_path' => __DIR__ . '/../templates/',
        ],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
            'level' => \Monolog\Logger::DEBUG,
        ],
        // Database Settings
        'db' => [
            'host' => '10.242.65.3',
            'user' => 'root',
            'pass' => 'P@ssw0rd123',
            'dbname' => 'dcim',
            'driver' => 'mysql'
            // 'host' => '127.0.0.1',
            // 'user' => 'root',
            // 'pass' => '',
            // 'dbname' => 'db_buku_kunjungan',
            // 'driver' => 'mysql'
        ],
    ],
];
