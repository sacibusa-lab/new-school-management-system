<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value platform configuration (fixed amounts, prefixes, branding, cutoffs).
 *
 * Reads are memoised per request; writes clear the memo.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'label'];

    /** @var array<string, mixed> */
    protected static array $memo = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            $row = static::query()->where('key', $key)->first();
            self::$memo[$key] = $row ? static::cast($row->value, $row->type) : $default;
        }

        return self::$memo[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, array $meta = []): void
    {
        $row = static::query()->firstOrNew(['key' => $key]);
        $row->type = $meta['type'] ?? static::inferType($value);
        $row->group = $meta['group'] ?? $row->group ?? 'general';
        $row->label = $meta['label'] ?? $row->label;
        $row->value = is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        $row->save();

        unset(self::$memo[$key]);
    }

    public static function forget(string $key): void
    {
        unset(self::$memo[$key]);
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    protected static function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    protected static function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
