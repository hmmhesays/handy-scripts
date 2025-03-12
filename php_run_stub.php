<?php

function usage()
{
    echo "Usage: php script.php [options]\n";
    echo "Options:\n";
    echo "  -l <address>   Set the listen address\n";
    echo "  -f <file>      Set the file path\n";
    echo "  -e <file>      Load environment variables from a file\n";
    echo "  -h             Show this help message\n\n";
    echo "Environment Variables (also checked if set globally):\n";
    echo "  LISTEN   - Address to listen on\n";
    echo "  FILE     - Path to the file\n";
    exit(0);
}

// Default values
$listen = getenv('LISTEN') ?: "";
$file = getenv('FILE') ?: "";

// Load environment file if available
$envFile = ".env";
if (file_exists($envFile)) {
    $envVars = parse_ini_file($envFile);
    if ($envVars !== false) {
        $listen = $envVars['LISTEN'] ?? $listen;
        $file = $envVars['FILE'] ?? $file;
    }
}

// Parse command-line arguments
$options = getopt("l:f:e:h");
if (isset($options['h'])) {
    usage();
}

if (isset($options['e']) && file_exists($options['e'])) {
    $envVars = parse_ini_file($options['e']);
    if ($envVars !== false) {
        $listen = $envVars['LISTEN'] ?? $listen;
        $file = $envVars['FILE'] ?? $file;
    }
} elseif (isset($options['e'])) {
    echo "Environment file not found: {$options['e']}\n";
    exit(1);
}

$listen = $options['l'] ?? $listen;
$file = $options['f'] ?? $file;

// Print the parsed values
echo "Listen: $listen\n";
echo "File: $file\n";

