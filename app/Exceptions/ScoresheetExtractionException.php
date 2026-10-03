<?php

namespace App\Exceptions;

use RuntimeException;

class ScoresheetExtractionException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'AI scoresheet reading is not configured yet. '
            .'Set the provider and key in Settings under API, then try again. '
            .'Excel and CSV uploads work without any configuration.'
        );
    }

    public static function providerFailed(string $provider, string $reason): self
    {
        return new self("The {$provider} service could not read this scoresheet: {$reason}");
    }

    public static function unreadableResponse(string $provider): self
    {
        return new self("The {$provider} service returned a response we could not understand. Please try again or enter the scores by hand.");
    }
}
