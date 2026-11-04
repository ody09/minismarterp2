<?php

namespace App\Support;

use Dotenv\Dotenv;
use InvalidArgumentException;
use RuntimeException;

class EnvironmentFile
{
    public function __construct(private readonly string $path) {}

    public function update(array $values): void
    {
        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read .env.');
        }
        $encoded = $values;
        foreach ($values as $key => $value) {
            if (! preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Environment settings must use single-line values.');
            }
            // Laravel coerces reserved words and strips one matching quote pair.
            if (preg_match('/\A(?:true|false|null|empty|\((?:true|false|null|empty)\))\z/i', $value)
                || preg_match('/\A([\'"]).*\1\z/', $value)) {
                $value = '"'.$value.'"';
            }
            $encoded[$key] = $value;
            $line = $key.'="'.strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']).'"';
            $found = false;
            $contents = preg_replace_callback('/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=.*$/m',
                function () use (&$found, $line) {
                    $replacement = $found ? '' : $line;
                    $found = true;

                    return $replacement;
                }, $contents);
            if (! $found) {
                $contents = rtrim($contents, "\r\n")."\n".$line."\n";
            }
        }
        // Validate before replacing anything, including unusual pre-existing .env syntax.
        $parsed = Dotenv::parse($contents);
        foreach ($encoded as $key => $value) {
            if (($parsed[$key] ?? null) !== $value) {
                throw new RuntimeException('Cannot safely encode .env settings.');
            }
        }
        $temporary = tempnam(dirname($this->path), '.erp-env-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary .env.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Cannot replace .env. Check directory permissions.');
            }
            // Windows scanners can briefly keep the destination file open.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                if (@rename($temporary, $this->path)) {
                    return;
                }
                usleep(50000);
            }
            throw new RuntimeException('Cannot replace .env. Check directory permissions.');
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
