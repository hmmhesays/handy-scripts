<?php

namespace Utils;

class JSONTools
{
    /**
     * Merge multiple JSON-like structures (arrays/objects)
     * while preserving objects and respecting forbidden paths.
     *
     * @param array $forbiddenPaths Array of paths, e.g. [["Account","User","Device","sip","password"]]
     * @param mixed ...$items       JSON-like structures to merge
     * @return mixed
     */
    public static function mergeRecursiveVariadic(array $forbiddenPaths = [], ...$items)
    {
        $result = array_shift($items);

        foreach ($items as $item) {
            $result = self::mergeRecursive($result, $item, $forbiddenPaths, []);
        }

        return $result;
    }


    /**
     * Internal recursive merge function.
     *
     * @param mixed $a
     * @param mixed $b
     * @param array $forbiddenPaths
     * @param array $currentPath
     * @return mixed
     */
    private static function mergeRecursive($a, $b, array $forbiddenPaths, array $currentPath)
    {
        // Preserve objects
        $isObjA = is_object($a);
        $isObjB = is_object($b);

        if ($isObjA) $a = clone $a;
        if ($isObjB) $b = clone $b;

        // Convert to arrays for merging
        $arrA = $isObjA ? get_object_vars($a) : (is_array($a) ? $a : null);
        $arrB = $isObjB ? get_object_vars($b) : (is_array($b) ? $b : null);

        /**
         * FIX: Preserve empty objects {}
         * If $b was an object with no properties, get_object_vars() returns []
         * We must preserve the fact that it was an object.
         */
        if ($isObjB && empty($arrB)) {
            // If left side is also an object, keep left-hand object
            if ($isObjA) {
                return $a;
            }

            // Otherwise return an empty object
            return (object)[];
        }

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
                if (self::pathIsForbidden($newPath, $forbiddenPaths)) {
                    continue;
                }

                if (array_key_exists($key, $arrA)) {
                    $arrA[$key] = self::mergeRecursive($arrA[$key], $value, $forbiddenPaths, $newPath);
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


    /**
     * Check if a given path matches any forbidden path.
     *
     * @param array $path
     * @param array $forbiddenPaths
     * @return bool
     */
    private static function pathIsForbidden(array $path, array $forbiddenPaths): bool
    {
        foreach ($forbiddenPaths as $forbidden) {
            if ($path === $forbidden) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extracts the value for a given key from a JSON string using fast string scanning.
     *
     * This method avoids json_decode() and regular expressions for maximum performance,
     * making it suitable for predictable, flat JSON where the key/value format is stable.
     * It works by locating the key with strpos(), skipping whitespace, and capturing the
     * next quoted string as the value.
     *
     * Performance:
     * - Extremely fast: uses only native string functions (strpos, substr).
     * - No JSON parsing overhead and no regex engine invocation.
     *
     * Limitations:
     * - Assumes the JSON is well‑formed and predictable.
     * - Only supports simple string values (e.g., "value"), not numbers, objects, arrays,
     *   or strings containing escaped quotes.
     * - Returns the first occurrence of the key; does not distinguish nesting levels.
     * - Does not validate JSON structure and may misinterpret keys inside string literals.
     * - Not suitable for complex or untrusted JSON input.
     *
     * @param string $json The raw JSON string to search.
     * @param string $key  The key whose string value should be extracted.
     * @return string|null The extracted value, or null if the key is not found.
     */
    public static function fastJsonExtractValue(string $json, string $key)
    {
        $needle = '"' . $key . '"';
        $pos = strpos($json, $needle);
        if ($pos === false) return null;

        // Find colon after the key
        $pos = strpos($json, ':', $pos);
        if ($pos === false) return null;

        // Skip whitespace after colon
        while (isset($json[++$pos]) && ctype_space($json[$pos])) {
        }

        // Expect a quote
        if ($json[$pos] !== '"') return null;
        $start = $pos + 1;

        // Find closing quote
        $end = strpos($json, '"', $start);
        if ($end === false) return null;

        return substr($json, $start, $end - $start);
    }
}
