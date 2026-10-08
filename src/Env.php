<?php
declare(strict_types=1);

namespace Festkasse;

final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$vars[trim($key)] = trim($value, " \t\"'");
        }
    }

    /**
     * Values from config/config.local.php — same keys as .env, and they win over it. Exists for
     * hosts where the server-side settings are kept in a PHP file rather than a dotfile.
     * @param array<string, scalar|null> $values
     */
    public static function override(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value !== null) {
                self::$vars[(string) $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$vars[$key] ?? getenv($key) ?: $default;
    }
}
