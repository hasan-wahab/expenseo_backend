<?php

namespace App\Game\Support;

use Illuminate\Support\Facades\Log;

class GameLogger
{
    public static function info(string $tag, string $message, array $context = []): void
    {
        self::write('INFO', $tag, $message, $context);
    }

    public static function error(string $tag, string $message, array $context = []): void
    {
        self::write('ERROR', $tag, $message, $context);
    }

    public static function warning(string $tag, string $message, array $context = []): void
    {
        self::write('WARN', $tag, $message, $context);
    }

    protected static function write(string $level, string $tag, string $message, array $context = []): void
    {
        $time = date('H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $line = "[$time] [$tag] $message$contextStr";

        // 1. Write to Laravel log file
        if ($level === 'ERROR') {
            Log::error("[$tag] $message", $context);
        } elseif ($level === 'WARN') {
            Log::warning("[$tag] $message", $context);
        } else {
            Log::info("[$tag] $message", $context);
        }

        // 2. Write to terminal console output
        $color = match ($level) {
            'ERROR' => "\033[31m", // Red
            'WARN'  => "\033[33m", // Yellow
            default => "\033[36m", // Cyan
        };
        $reset = "\033[0m";

        @file_put_contents('php://stdout', "{$color}{$line}{$reset}\n");
    }
}

