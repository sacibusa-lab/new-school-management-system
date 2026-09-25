<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    protected $fillable = ['key', 'name', 'body', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Replace {placeholders} in the body.
     *
     * Accepts both `{first_name}` and `{{ first_name }}` so an office clerk
     * editing the text cannot get it wrong by adding spaces.
     *
     * @param  array<string,string|int|float|null>  $values
     */
    public function render(array $values): string
    {
        return static::renderText($this->body, $values);
    }

    /**
     * Replace `{placeholders}` in any text — shared with one-off broadcasts so a
     * hand-typed message fills in exactly like a saved template.
     *
     * `strtr` always prefers the longest matching key, which is what stops
     * `{key}` from eating the middle of `{{ key }}`.
     *
     * @param  array<string,string|int|float|null>  $values
     */
    public static function renderText(string $text, array $values): string
    {
        $replacements = [];

        foreach ($values as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
            $replacements['{{ ' . $key . ' }}'] = (string) $value;
            $replacements['{{' . $key . '}}'] = (string) $value;
        }

        return trim(strtr($text, $replacements));
    }

    /** Placeholders the template currently uses that we were not given a value for. */
    public function unresolvedPlaceholders(): array
    {
        preg_match_all('/\{\{?\s*([a-z0-9_]+)\s*\}?\}/i', $this->body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    public static function find(string $key): ?self
    {
        return static::query()->where('key', $key)->where('is_active', true)->first();
    }
}
