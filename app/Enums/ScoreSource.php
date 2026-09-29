<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Where a score physically came from — kept for auditability. */
enum ScoreSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case Spreadsheet = 'spreadsheet';
    case AiVision = 'ai_vision';
    case Ocr = 'ocr';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Typed by hand',
            self::Spreadsheet => 'Excel / CSV upload',
            self::AiVision => 'Read by AI from scan',
            self::Ocr => 'Read by OCR',
            self::Api => 'External system',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Manual => 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20',
            self::Spreadsheet => 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 ring-sky-600/20 dark:ring-sky-400/20',
            self::AiVision => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-600/20',
            self::Ocr => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            self::Api => 'bg-teal-50 text-teal-700 ring-teal-600/20',
        };
    }

    /** Machine-read scores should be human-verified before they count. */
    public function needsVerification(): bool
    {
        return $this !== self::Manual;
    }
}
