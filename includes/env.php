<?php
// ============================================================
// includes/env.php — NEW FILE
// Loads /swl/.env into environment variables.
// No composer/vendor package needed — plain PHP parser.
// ============================================================

function loadEnv($path) {
    if (!file_exists($path)) {
        die("Missing .env file. Copy .env.example to .env and fill in your real values.");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);

        // Strip surrounding quotes if present
        if (strlen($value) > 1 && (
            ($value[0] === '"'  && substr($value, -1) === '"') ||
            ($value[0] === "'"  && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// env() helper with a default fallback, so config.php reads cleanly
function env($key, $default = null) {
    $value = getenv($key);
    return $value === false ? $default : $value;
}
?>
