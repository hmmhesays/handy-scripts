<?php

function mergeRecursiveVariadic(array $forbiddenPaths = [], ...$items) {
    $result = array_shift($items);

    foreach ($items as $item) {
        $result = mergeRecursive($result, $item, $forbiddenPaths, []);
    }

    return $result;
}

function mergeRecursive($a, $b, array $forbiddenPaths, array $currentPath) {

    // Preserve objects
    $isObjA = is_object($a);
    $isObjB = is_object($b);

    if ($isObjA) $a = clone $a;
    if ($isObjB) $b = clone $b;

    // Convert to arrays for merging
    $arrA = $isObjA ? get_object_vars($a) : (is_array($a) ? $a : null);
    $arrB = $isObjB ? get_object_vars($b) : (is_array($b) ? $b : null);

    // If both are arrays/objects → merge
    if (is_array($arrA) && is_array($arrB)) {

        // Flat numeric arrays → merge values
        $isFlatNumericA = array_keys($arrA) === range(0, count($arrA) - 1);
        $isFlatNumericB = array_keys($arrB) === range(0, count($arrB) - 1);

        if ($isFlatNumericA && $isFlatNumericB) {
            return array_merge($arrA, $arrB);
        }

        foreach ($arrB as $key => $value) {

            $newPath = [...$currentPath, $key];

            // Forbidden path → keep left-hand value
            if (pathIsForbidden($newPath, $forbiddenPaths)) {
                continue;
            }

            if (array_key_exists($key, $arrA)) {
                $arrA[$key] = mergeRecursive($arrA[$key], $value, $forbiddenPaths, $newPath);
            } else {
                $arrA[$key] = $value;
            }
        }

        // Restore object if needed
        if ($isObjA || $isObjB) {
            $obj = $isObjA ? $a : $b;
            foreach ($arrA as $k => $v) {
                $obj->$k = $v;
            }
            return $obj;
        }

        return $arrA;
    }

    // Fallback: keep left-hand value
    return $a;
}

function pathIsForbidden(array $path, array $forbiddenPaths): bool {
    foreach ($forbiddenPaths as $forbidden) {
        if ($path === $forbidden) {
            return true;
        }
    }
    return false;
}
