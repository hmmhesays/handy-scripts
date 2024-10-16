<?php


class JSONToolsLib {

    /**
 * Validate a JSON object against a schema.
 *
 * @param mixed $jsonData The JSON data to validate.
 * @param array $schema The schema to validate against.
 * @return array An array of validation errors, empty if valid.
 */
static public function validateJsonSchema($jsonData, $schema) {
    $errors = [];

    foreach ($schema as $key => $requirements) {
        if (!isset($jsonData->$key)) {
            if (isset($requirements['required']) && $requirements['required']) {
                $errors[] = "Missing required field: $key";
            }
            continue;
        }

        $value = $jsonData->$key;

        // Type validation
        if (isset($requirements['type'])) {
            $typeValid = false;

            switch ($requirements['type']) {
                case 'string':
                    $typeValid = is_string($value);
                    break;
                case 'integer':
                    $typeValid = is_int($value);
                    break;
                case 'number':
                    $typeValid = is_numeric($value);
                    break;
                case 'boolean':
                    $typeValid = is_bool($value);
                    break;
                case 'object':
                    $typeValid = is_object($value);
                    break;
                case 'array':
                    $typeValid = is_array($value);
                    break;
                case 'null':
                    $typeValid = is_null($value);
                    break;
                default:
                    $errors[] = "Invalid type specified for field $key";
            }

            if (!$typeValid) {
                $errors[] = "Field $key should be of type " . $requirements['type'];
            }
        }

        // Enum validation
        if (isset($requirements['enum']) && is_array($requirements['enum'])) {
            if (!in_array($value, $requirements['enum'])) {
                $errors[] = "Field $key must be one of " . implode(', ', $requirements['enum']);
            }
        }

        // Minimum length validation for strings
        if (isset($requirements['minLength']) && is_string($value)) {
            if (strlen($value) < $requirements['minLength']) {
                $errors[] = "Field $key must be at least " . $requirements['minLength'] . " characters long";
            }
        }

        // Maximum length validation for strings
        if (isset($requirements['maxLength']) && is_string($value)) {
            if (strlen($value) > $requirements['maxLength']) {
                $errors[] = "Field $key must be no more than " . $requirements['maxLength'] . " characters long";
            }
        }

        // Minimum value validation for numbers
        if (isset($requirements['minimum']) && is_numeric($value)) {
            if ($value < $requirements['minimum']) {
                $errors[] = "Field $key must be at least " . $requirements['minimum'];
            }
        }

        // Maximum value validation for numbers
        if (isset($requirements['maximum']) && is_numeric($value)) {
            if ($value > $requirements['maximum']) {
                $errors[] = "Field $key must be no more than " . $requirements['maximum'];
            }
        }

        // Array validation
        if ($requirements['type'] === 'array' && isset($requirements['items']) && is_array($value)) {
            foreach ($value as $index => $item) {
                $nestedErrors = self::validateJsonSchema((object) [$index => $item], ['index' => $requirements['items']]);
                foreach ($nestedErrors as $error) {
                    $errors[] = "$key[$index]: " . str_replace('index', 'item', $error);
                }
            }
        }

        // Nested object validation
        if ($requirements['type'] === 'object' && isset($requirements['properties']) && is_object($value)) {
            $nestedErrors = self::validateJsonSchema($value, $requirements['properties']);
            foreach ($nestedErrors as $error) {
                $errors[] = "$key: $error";
            }
        }
    }

    return $errors;
}

}
