#!/usr/bin/php
<?php

$hosts = [
    'user@host1.example.com',
    'user@host2.example.com',
    'user@host3.example.com',
];

$procs = [];
$streams = [];

foreach ($hosts as $host) {
    $descriptors = [
        0 => ["pipe", "r"],  // stdin
        1 => ["pipe", "w"],  // stdout
        2 => ["pipe", "w"],  // stderr
    ];

    $cmd = "ssh -o BatchMode=yes -tt $host";
    $proc = proc_open($cmd, $descriptors, $pipes);

    if (is_resource($proc)) {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $procs[] = $proc;
        $streams[] = [
            'host' => $host,
            'in'   => $pipes[0],
            'out'  => $pipes[1],
            'err'  => $pipes[2],
            'buffer' => '',
        ];
        echo "Connected to $host\n";
    } else {
        echo "Failed to connect to $host\n";
    }
}

echo "Enter commands to send to all servers. Type 'exit' to quit.\n";

while (true) {
    echo "> ";
    $cmd = trim(fgets(STDIN));
    if ($cmd === 'exit') break;

    foreach ($streams as &$stream) {
        fwrite($stream['in'], "$cmd\n");
    }

    // Short wait to allow output to start accumulating
    usleep(200000);

    foreach ($streams as &$stream) {
        foreach (['out', 'err'] as $type) {
            $content = stream_get_contents($stream[$type]);
            if ($content !== false && strlen($content)) {
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    if (strlen(trim($line)) > 0) {
                        $prefix = $type === 'out' ? '' : '[STDERR] ';
                        echo "[{$stream['host']}] $prefix$line\n";
                    }
                }
            }
        }
    }
}

foreach ($streams as $stream) {
    fclose($stream['in']);
    fclose($stream['out']);
    fclose($stream['err']);
}
foreach ($procs as $proc) {
    proc_close($proc);
}

echo "All connections closed.\n";
