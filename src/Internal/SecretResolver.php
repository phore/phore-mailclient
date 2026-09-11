<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

use InvalidArgumentException;
use RuntimeException;

/** @internal */
final class SecretResolver
{
    public static function validateName(string $name): void
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/', $name)) {
            throw new InvalidArgumentException('Secret name must start with a letter or underscore and contain only letters, digits, underscores, dots or hyphens.');
        }
    }

    public static function resolve(string $name, string $directory = '/var/run/secrets'): string
    {
        self::validateName($name);
        $value = getenv($name);
        if ($value === false) {
            if ($directory === '' || str_contains($directory, '://')) { throw new InvalidArgumentException('Secrets directory must be a local directory.'); }
            $path = rtrim($directory, '/') . '/' . $name;
            if (!is_file($path)) { throw new RuntimeException('Password secret is missing or is not a readable file.'); }
            $value = @file_get_contents($path);
            if ($value === false) { throw new RuntimeException('Cannot read password secret file.'); }
            // Permit one newline from tools such as echo; preserve all other whitespace.
            if (str_ends_with($value, "\r\n")) { $value = substr($value, 0, -2); }
            elseif (str_ends_with($value, "\n")) { $value = substr($value, 0, -1); }
        }
        // An explicitly empty environment value must not silently use another credential.
        if ($value === '') { throw new RuntimeException('Password secret is empty.'); }
        return $value;
    }
}
