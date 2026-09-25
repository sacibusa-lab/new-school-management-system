<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\Sms\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one queued text message.
 *
 * Only needed for large broadcasts; day-to-day messages (registration,
 * admission, resit) are sent inline so they work without a queue worker.
 */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $smsLogId,
    ) {
    }

    public function handle(SmsService $sms): void
    {
        $log = SmsLog::find($this->smsLogId);

        if (! $log || $log->sent_at !== null) {
            return;
        }

        $sms->deliver($log);
    }
}
