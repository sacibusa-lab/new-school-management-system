<?php

namespace App\Services\Payment;

interface PaymentGatewayInterface
{
    /**
     * Initiate a payment transaction.
     *
     * @param  array  $data  ['email', 'amount', 'reference', 'callback_url', 'metadata']
     * @return array ['status' => bool, 'checkout_url' => string, 'message' => string]
     */
    public function initiateTransaction(array $data): array;

    /**
     * Verify a payment transaction.
     *
     * @return array ['status' => bool, 'data' => array, 'message' => string]
     */
    public function verifyTransaction(string $reference): array;
}
