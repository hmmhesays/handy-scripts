<?php

$src = 'http://admin:password@localhost:5984';
$dst = 'http://admin:password@remotehost:5984';
$maxWorkers = 5;
$overwrite = false;

// Parse CLI args for --overwrite
foreach ($argv as $arg) {
    if ($arg === '--overwrite') {
        $overwrite = true;
    }
}

function couch_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function couch_put($url, $json) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function url_encode_docid($id) {
    if (str_starts_with($id, '_design/')) {
        return '_design/' . rawurlencode(substr($id, 8));
    }
    return rawurlencode($id);
}

function doc_exists($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

// Get list of databases
$dbs_raw = couch_get("$src/_all_dbs");
$dbs = json_decode($dbs_raw, true);
if (!is_array($dbs)) {
    fwrite(STDERR, "Failed to get database list or invalid response.\n");
    exit(1);
}

$children = 0;

foreach ($dbs as $db) {
    $dbEnc = rawurlencode($db);
    echo "🔄 Processing database: $db\n";

    // Ensure destination DB exists
    $check = curl_init("$dst/$dbEnc");
    curl_setopt_array($check, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    curl_exec($check);
    $code = curl_getinfo($check, CURLINFO_HTTP_CODE);
    curl_close($check);
    if ($code !== 200) {
        echo "🆕 Creating database: $db\n";
        couch_put("$dst/$dbEnc", '');
    }

    // Get all document IDs
    $docs_raw = couch_get("$src/$dbEnc/_all_docs?include_docs=false");
    $docs = json_decode($docs_raw, true);
    if (!isset($docs['rows']) || !is_array($docs['rows'])) {
        fwrite(STDERR, "Failed to get docs for $db or invalid response.\n");
        continue;
    }

    foreach ($docs['rows'] as $row) {
        while ($children >= $maxWorkers) {
            pcntl_wait($status);
            $children--;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "❌ Failed to fork process\n");
            exit(1);
        } elseif ($pid === 0) {
            // Child process
            global $dst, $dbEnc, $overwrite, $src;

            $docId = $row['id'];
            $docIdEnc = url_encode_docid($docId);

            $dstDocUrl = "$dst/$dbEnc/$docIdEnc";

            // If not overwriting and doc exists, skip
            if (!$overwrite && doc_exists($dstDocUrl)) {
                echo "⏭ Skipped existing doc: $db/$docId\n";
                exit(0);
            }

            // Fetch source document with attachments
            $doc_raw = couch_get("$src/$dbEnc/$docIdEnc?attachments=true");
            $doc = json_decode($doc_raw, true);
            if (!isset($doc['_id'])) {
                fwrite(STDERR, "❌ Failed to fetch: $db/$docId\n");
                exit(1);
            }

            // Remove source _rev to avoid conflicts
            unset($doc['_rev']);

            // If overwriting and doc exists, get current _rev from destination
            if ($overwrite && doc_exists($dstDocUrl)) {
                $dstDocRaw = couch_get($dstDocUrl);
                $dstDoc = json_decode($dstDocRaw, true);
                if (isset($dstDoc['_rev'])) {
                    $doc['_rev'] = $dstDoc['_rev'];
                }
            }

            $clean_json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // PUT to destination
            couch_put($dstDocUrl, $clean_json);
            echo "✔ Copied: $db/$docId\n";
            exit(0);
        } else {
            // Parent process
            $children++;
        }
    }

    // Wait for all child processes for this DB to finish
    while ($children > 0) {
        pcntl_wait($status);
        $children--;
    }
}

echo "✅ All databases processed.\n";
