<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Typed reads from decoded Telegram updates and conversation state, which are untyped JSON arrays.
 */
final class Payload
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string ...$path): ?string
    {
        $value = self::get($data, $path);

        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            default => null,
        };
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string ...$path): ?int
    {
        $value = self::get($data, $path);

        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && is_numeric($value) => (int) $value,
            default => null,
        };
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function array(array $data, string ...$path): array
    {
        return self::stringKeys(self::get($data, $path)) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function stringKeys(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * Chat of a message or of the message behind a callback button, 0 if there is none.
     *
     * @param array<mixed> $update
     */
    public static function chatId(array $update): int
    {
        return self::int($update, 'message', 'chat', 'id')
            ?? self::int($update, 'callback_query', 'message', 'chat', 'id')
            ?? 0;
    }

    /**
     * @param array<mixed> $update
     */
    public static function text(array $update): string
    {
        return self::string($update, 'message', 'text') ?? '';
    }

    /**
     * @param array<mixed> $update
     */
    public static function callbackData(array $update): ?string
    {
        return self::string($update, 'callback_query', 'data');
    }

    /**
     * @param array<mixed> $data
     * @param array<int|string, string> $path
     */
    private static function get(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
