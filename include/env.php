<?php
// ============================================================
// env.php — Zero-dependency .env loader + accessor
// Shared by db.php (database credentials) and mailer.php (SMTP
// credentials) so every secret is read from the SAME .env file
// instead of being hardcoded in source.
//
// The real .env is never committed; .env.example documents the
// keys. Values already present in the process environment (set
// by the web server, Docker, CI, ...) always win over the file,
// so a deployment can override any key without editing .env.
//
// This file is deliberately dependency-free: it must stay cheap
// enough for db.php to include on every request, because db.php
// runs before anything else that needs configuration.
// ============================================================

/**
 * load_env()
 * Parses a .env file into the process environment. Blank lines and
 * lines starting with '#' are ignored; the first '=' splits the
 * key from the value; matching surrounding single or double quotes
 * are stripped. Missing file = no-op, so the caller never has to
 * check: every env() default is written to work without one.
 *
 * @param string $path Absolute path to the .env file.
 * @return void
 */
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if ($key === '') {
            continue;
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $value;
        }
    }
}

/**
 * env()
 * Reads one configuration value. Prefers the real process
 * environment, then the parsed .env values, then the given
 * default — so a missing key never breaks the app.
 *
 * @param string $key     Variable name, e.g. 'DB_HOST'.
 * @param string $default Value returned when the key is unset or empty.
 * @return string
 */
function env(string $key, string $default = ''): string
{
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    return $_ENV[$key] ?? $default;
}

// Load the project's .env as soon as this file is included, so no
// caller can forget to. A missing .env is fine (see load_env).
// This file lives in include/, so the .env it loads sits one level up
// in the project root — the same place a host writes it.
load_env(__DIR__ . '/../.env');
