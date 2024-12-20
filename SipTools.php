<?php

class SIPTools {
static function parseSipUri($sipUri) {
    $pattern = '/^sip:(?:([^:@]+)(?::([^@]+))?@)?([^:;?]+)(?::(\d+))?(;[^?]*)?(\?.*)?$/';

    if (preg_match($pattern, $sipUri, $matches)) {
        $user = $matches[1] ?? null;
        $password = $matches[2] ?? null;
        $host = $matches[3] ?? null;
        $port = $matches[4] ?? null;
        $params = isset($matches[5]) ? self::parseParams($matches[5]) : [];
        $headers = isset($matches[6]) ? self::parseHeaders($matches[6]) : [];

        return [
            'user' => $user,
            'password' => $password,
            'host' => $host,
            'port' => $port,
            'parameters' => $params,
            'headers' => $headers,
        ];
    }

    return null; // Return null if the SIP URI doesn't match the pattern
}

static function parseParams($paramsString) {
    $params = [];
    $paramsString = ltrim($paramsString, ';'); // Remove leading semicolon
    $pairs = explode(';', $paramsString);

    foreach ($pairs as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
        $params[$key] = $value;
    }

    return $params;
}

static function parseHeaders($headersString) {
    $headers = [];
    $headersString = ltrim($headersString, '?'); // Remove leading question mark
    $pairs = explode('&', $headersString);

    foreach ($pairs as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
        $headers[$key] = $value;
    }

    return $headers;
}
}