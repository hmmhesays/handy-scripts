<?php
$input = <<<EOT
Node          : garbage_toys@noop.toys2.us.east.noop.com
md5           : qDV5nTkwLwJnj8Giiu63bQ
Version       : 6ddabbd - 19
Memory Usage  : 212.56MB
Processes     : 2532
Ports         : 27
Zone          : pluto (local)
Broker        : amqp://XXX.YYY.20.77:5672
Globals       : remote (1) local (1)
Node Info     : amqp: {"connections":{"amqp://####:####@XXX.YYY.20.77:5672":{"channel_count":291,"zone":"local"},"amqp://####:####@XXX.YYY.20.123:5672":{"channel_count":36,"zone":"mars"}},"pools":{"kz_amqp_pool":"150/0/0 (ready)"}}
Whtoys        : blackhole(25d2h34m33s)   callflow(25d2h34m32s)    cdr(25d2h34m32s)         conference(25d2h34m32s)
                crossbar(25d2h34m32s)    fax(25d2h34m31s)         hangups(25d2h34m21s)     konami(25d2h34m21s)
                media_mgr(25d2h34m21s)   milliwatt(25d2h34m21s)   omnipresence(25d2h34m21s)pivot(25d2h34m21s)
                pusher(25d2h34m21s)      registrar(25d2h34m21s)   reorder(25d2h34m21s)     stepswitch(25d2h34m20s)
                sysconf(25d2h34m33s)     teletype(25d2h34m20s)    trunkstore(25d2h34m13s)  webhooks(25d2h34m13s)

Node          : awesome@noop.awesome1.wss.mars.noop.com
Version       : 5.5.2
Memory Usage  : 50.00MB
Zone          : pluto (local)
Broker        : amqp://XXX.YYY.20.77:5672
Whtoys        : awesome(169d9h24m55s)
Roles         : Dispatcher Presence Proxy Registrar
EOT;

// Function to parse the Whtoys into key-value pairs
function parseWhtoys($whtoysString) {
    $whtoys = [];
    preg_match_all('/([a-zA-Z0-9_]+)\(([\d]+d[\dhms]+)\)/', $whtoysString, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $whtoys[$match[1]] = $match[2];
    }
    return $whtoys;
}

// Main function to parse the input
function parseInput($input) {
    $lines = explode("\n", $input);
    $result = [];
    $currentNode = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (strpos($line, 'Node') === 0) {
            if (!empty($currentNode)) {
                $result[] = $currentNode;
            }
            $currentNode = ['Node' => trim(explode(':', $line, 2)[1])];
        } elseif (strpos($line, 'md5') === 0 || strpos($line, 'Version') === 0 || strpos($line, 'Memory Usage') === 0 ||
                  strpos($line, 'Processes') === 0 || strpos($line, 'Ports') === 0 || strpos($line, 'Zone') === 0 ||
                  strpos($line, 'Broker') === 0 || strpos($line, 'Globals') === 0 || strpos($line, 'Roles') === 0) {
            list($key, $value) = explode(':', $line, 2);
            $currentNode[trim($key)] = trim($value);
        } elseif (strpos($line, 'Node Info') === 0) {
            list($key, $jsonValue) = explode(':', $line, 2);
            $amqpJson = substr(trim($jsonValue), 5);  // Remove the 'amqp: ' part
            $currentNode['Node Info'] = json_decode($amqpJson, true);
        } elseif (strpos($line, 'Whtoys') === 0) {
            list($key, $whtoysString) = explode(':', $line, 2);
            $currentNode['Whtoys'] = parseWhtoys(trim($whtoysString));
        }
    }
    if (!empty($currentNode)) {
        $result[] = $currentNode;
    }
    return $result;
}

// Parse the input and convert to JSON
$parsedData = parseInput($input);
echo json_encode($parsedData, JSON_PRETTY_PRINT);
?>
