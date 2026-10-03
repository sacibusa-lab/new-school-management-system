<?php

namespace App\Services\Payment;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The live Paystack account.
 *
 * Lifted from the school's own fees site rather than rewritten. The calls below are
 * Paystack's documented API and are worth keeping recognisable — rewritten to a
 * house style they would only be harder to hold against the documentation when one
 * of them starts returning something unexpected.
 *
 * What did change is where the key comes from: the school sets it on Settings → API,
 * with `config('services.paystack.*')` as the fallback for an installation that was
 * configured through the environment. It used to be the environment only, which is
 * the same dead end the AI keys were in.
 */
class PaystackProvider implements PaymentGatewayInterface
{
    protected string $baseUrl = 'https://api.paystack.co';

    protected string $secretKey;

    public function __construct()
    {
        $this->secretKey = (string) (Setting::get('paystack_secret_key') ?: config('services.paystack.secret_key', ''));
    }

    /** Whether the school has given us a key to talk to Paystack with. */
    public function isConfigured(): bool
    {
        return filled($this->secretKey);
    }

    public function initiateTransaction(array $data): array
    {
        try {
            $formattedAmount = $data['amount'] * 100; // Paystack expects Kobo

            $response = Http::withToken($this->secretKey)->post("{$this->baseUrl}/transaction/initialize", [
                'email' => $data['email'],
                'amount' => $formattedAmount,
                'reference' => $data['reference'],
                'callback_url' => $data['callback_url'],
                'metadata' => $data['metadata'] ?? [],
                'channels' => ['card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'],
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                return [
                    'status' => true,
                    'checkout_url' => $responseData['data']['authorization_url'],
                    'message' => 'Transaction initialized',
                ];
            }

            Log::error('Paystack Initialization Error', ['response' => $response->body()]);

            return [
                'status' => false,
                'checkout_url' => null,
                'message' => $response->json()['message'] ?? 'Initialization failed',
            ];

        } catch (\Exception $e) {
            Log::error('Paystack Initialization Exception', ['error' => $e->getMessage()]);

            return [
                'status' => false,
                'checkout_url' => null,
                'message' => 'Service unavailable',
            ];
        }
    }

    public function verifyTransaction(string $reference): array
    {
        try {
            $response = Http::withToken($this->secretKey)->get("{$this->baseUrl}/transaction/verify/{$reference}");

            if ($response->successful()) {
                $responseData = $response->json();

                if ($responseData['data']['status'] === 'success') {
                    return [
                        'status' => true,
                        'data' => $responseData['data'],
                        'message' => 'Verification successful',
                    ];
                }

                return [
                    'status' => false,
                    'data' => $responseData['data'],
                    'message' => 'Transaction not successful',
                ];
            }

            return [
                'status' => false,
                'data' => [],
                'message' => 'Verification failed',
            ];

        } catch (\Exception $e) {
            Log::error('Paystack Verification Exception', ['error' => $e->getMessage()]);

            return [
                'status' => false,
                'data' => [],
                'message' => 'Service unavailable',
            ];
        }
    }

    public function getBanks(): array
    {
        try {
            // Using cache to avoid frequent API calls for static data
            return cache()->remember('paystack_banks', 86400, function () { // Cache for 24 hours
                $response = Http::withToken($this->secretKey)->get("{$this->baseUrl}/bank", [
                    'currency' => 'NGN',
                ]);

                if ($response->successful()) {
                    return $response->json()['data'] ?? [];
                }

                return [];
            });
        } catch (\Exception $e) {
            Log::error('Paystack Get Banks Exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        try {
            $response = Http::withToken($this->secretKey)->get("{$this->baseUrl}/bank/resolve", [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            if ($response->successful()) {
                return [
                    'status' => true,
                    'account_name' => $response->json()['data']['account_name'],
                    'account_number' => $response->json()['data']['account_number'],
                ];
            }

            return [
                'status' => false,
                'message' => 'Could not resolve account details',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Service error: '.$e->getMessage(),
            ];
        }
    }

    public function createSubAccount(array $data): array
    {
        try {
            // Data should contain: business_name, settlement_bank (code), account_number, percentage_charge
            $response = Http::withToken($this->secretKey)->post("{$this->baseUrl}/subaccount", [
                'business_name' => $data['business_name'],
                'settlement_bank' => $data['settlement_bank'],
                'account_number' => $data['account_number'],
                'percentage_charge' => $data['percentage_charge'] ?? 0, // Default 0 for now
                'description' => $data['description'] ?? 'School Fee Subaccount',
            ]);

            if ($response->successful()) {
                return [
                    'status' => true,
                    'subaccount_code' => $response->json()['data']['subaccount_code'],
                    'data' => $response->json()['data'],
                ];
            }

            Log::error('Paystack Subaccount Creation Failed', ['response' => $response->body()]);

            return [
                'status' => false,
                'message' => $response->json()['message'] ?? 'Failed to create subaccount',
            ];

        } catch (\Exception $e) {
            Log::error('Paystack Subaccount Exception', ['error' => $e->getMessage()]);

            return [
                'status' => false,
                'message' => 'Service error',
            ];
        }
    }

    public function createCustomer(array $data): array
    {
        try {
            $response = Http::withToken($this->secretKey)->post("{$this->baseUrl}/customer", [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
            ]);

            if ($response->successful()) {
                return [
                    'status' => true,
                    'customer_code' => $response->json()['data']['customer_code'],
                    'data' => $response->json()['data'],
                ];
            }

            Log::error('Paystack Customer Creation Failed', ['response' => $response->body()]);

            return [
                'status' => false,
                'message' => $response->json()['message'] ?? 'Failed to create customer',
            ];
        } catch (\Exception $e) {
            Log::error('Paystack Customer Exception', ['error' => $e->getMessage()]);

            return ['status' => false, 'message' => 'Service error'];
        }
    }

    public function createDedicatedAccount(string $customerCode, ?string $splitCode = null): array
    {
        try {
            $payload = [
                'customer' => $customerCode,
                'preferred_bank' => 'wema-bank', // Common default for DVA
            ];

            if ($splitCode) {
                $payload['split_code'] = $splitCode;
            }

            $response = Http::withToken($this->secretKey)->post("{$this->baseUrl}/dedicated_account", $payload);

            if ($response->successful()) {
                $data = $response->json()['data'];

                return [
                    'status' => true,
                    'bank_name' => $data['bank']['name'],
                    'account_number' => $data['account_number'],
                    'account_name' => $data['account_name'],
                    'account_slug' => $data['bank']['slug'] ?? null,
                    'data' => $data,
                ];
            }

            Log::error('Paystack DVA Creation Failed', ['response' => $response->body()]);

            return [
                'status' => false,
                'message' => $response->json()['message'] ?? 'Failed to create dedicated account',
            ];
        } catch (\Exception $e) {
            Log::error('Paystack DVA Exception', ['error' => $e->getMessage()]);

            return ['status' => false, 'message' => 'Service error'];
        }
    }
}
