@props(['status'])

@php
    /** @var \App\Enums\ApplicantStatus|\App\Enums\ExamStatus|\App\Enums\AdmissionDecisionStatus|\App\Enums\InvoiceStatus|\App\Enums\StudentStatus|\App\Enums\ResultStatus|\App\Enums\ScoreImportStatus|\App\Enums\ScoreImportRowStatus|\App\Enums\PaymentStatus|\App\Enums\SmsStatus $status */
@endphp

<span {{ $attributes->merge(['class' => 'badge ' . $status->badge()]) }}>
    {{ $status->label() }}
</span>
