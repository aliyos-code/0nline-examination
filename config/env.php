<?php
/**
 * Environment loader (server-side only).
 *
 * Loads variables from a project-root .env file (if present) without
 * overwriting real environment variables. Secrets such as the OpenAI
 * API key must live in .env or in the real environment — never in
 * frontend code, HTML, or JavaScript.
 *
 * Usage: $key = env('OPENAI_API_KEY', '');
 */
if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        return $default;
    }
}

if (!function_exists('load_env_file')) {
    function load_env_file($path = null)
    {
        if ($path === null) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        }
        if (!is_file($path)) {
            return false;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
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
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '' && getenv($k) === false) {
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
        return true;
    }
}

load_env_file();
