<?php
// Configuration
$nodes = [
    'couchdb2' => [
        'url' => 'http://admin:password@10.0.0.45:5984',
        'last_seq_file' => '/tmp/couchdb2_last_seq.txt'
    ],
    'couchdb3' => [
        'url' => 'http://admin:password@10.0.0.46:5984',
        'last_seq_file' => '/tmp/couchdb3_last_seq.txt'
    ]
];

// Loop through each node, check _global_changes
foreach ($nodes as $name => &$node) {
    $since = @file_get_contents($node['last_seq_file']) ?: '0';
    $url = $node['url'] . '/_global_changes?since=' . urlencode($since) . '&include_docs=true&limit=100';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR => true
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        echo "Failed to connect to $name: " . curl_error($ch) . "\n";
        curl_close($ch);
        continue;
    }
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!isset($data['results'])) {
        echo "Invalid response from $name\n";
        continue;
    }

    // Store last_seq for next poll
    if (isset($data['last_seq'])) {
        file_put_contents($node['last_seq_file'], $data['last_seq']);
    }

    // Collect changed DBs
    $changed_dbs = [];
    foreach ($data['results'] as $change) {
        if (isset($change['id'])) {
            $db = explode(':', $change['id'])[0]; // dbname:docid
            $changed_dbs[$db] = true;
        }
    }

    $node['changed_dbs'] = array_keys($changed_dbs);
}

// Determine direction and initiate replication
function replicate($source, $target, $db, $source_url, $target_url) {
    $replication_payload = json_encode([
        'source' => "$source_url/$db",
        'target' => "$target_url/$db",
        'create_target' => true
    ]);

    $replicate_url = "$target_url/_replicate";
    $ch = curl_init($replicate_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $replication_payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        echo "Replication $source → $target for $db failed: " . curl_error($ch) . "\n";
    } else {
        echo "Replication $source → $target for $db initiated.\n";
    }
    curl_close($ch);
}

// Sync changed DBs
foreach ($nodes as $name => $node) {
    $other_name = $name === 'couchdb2' ? 'couchdb3' : 'couchdb2';
    foreach ($node['changed_dbs'] as $db) {
        // Start replication from this node to the other
        replicate($name, $other_name, $db, $node['url'], $nodes[$other_name]['url']);
    }
}
