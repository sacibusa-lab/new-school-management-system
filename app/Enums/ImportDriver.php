<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ImportDriver: string
{
    use HasOptions;

    case Spreadsheet = 'spreadsheet';
    case AiVision = 'ai_vision';
    case Ocr = 'ocr';
    case ManualGrid = 'manual_grid';

    public function label(): string
    {
        return match ($this) {
            self::Spreadsheet => 'Excel / CSV',
            self::AiVision => 'AI vision',
            self::Ocr => 'OCR',
            self::ManualGrid => 'Manual entry',
        };
    }

    public function scoreSource(): ScoreSource
    {
        return match ($this) {
            self::Spreadsheet => ScoreSource::Spreadsheet,
            self::AiVision => ScoreSource::AiVision,
            self::Ocr => ScoreSource::Ocr,
            self::ManualGrid => ScoreSource::Manual,
        };
    }

    /** Drivers that read a scanned/photographed paper sheet. */
    public function isImageBased(): bool
    {
        return in_array($this, [self::AiVision, self::Ocr], true);
    }
}
