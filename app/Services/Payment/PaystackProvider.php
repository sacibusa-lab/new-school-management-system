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

    /**
     * The banks Paystack is known to issue virtual account numbers through.
     *
     * A stand-in for the list read in getVirtualAccountBanks, used only while the school
     * has not given us a key to ask with. It is deliberately not a list of Nigerian
     * banks: only some of them will open a dedicated account for a child.
     */
    protected const VIRTUAL_ACCOUNT_BANKS = [
        'wema-bank' => 'Wema Bank',
        'titan-paystack' => 'Paystack-Titan',
        '9psb' => '9 Payment Service Bank',
    ];

    public function __construct()
    {
        $this->secretKey = (string) (Setting::get('paystack_secret_key') ?: config('services.paystack.secret_key', ''));
    }

    /** Whether the school has given us a key to talk to Paystack with. */
    public function isConfigured(): bool
    {
        return filled($this->secretKey);
    }

    /**
     * Whether a webhook really came from Paystack.
     *
     * The signature is an HMAC of the raw request body under the account's secret
     * key, so nothing else can produce it. Comparing with hash_equals rather than
     * `===` is the point: a plain comparison leaks where the strings first differ.
     */
    public function signatureIsValid(string $signature, string $body): bool
    {
        if ($signature === '' || $this->secretKey === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $body, $this->secretKey), $signature);
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

    /**
     * Every bank in the country, which is the list the school picks its OWN account from.
     *
     * Not the same question as getVirtualAccountBanks below: only some of these will open
     * a dedicated account for a child.
     */
    public function getBanks(): array
    {
        try {
            $banks = cache()->get('paystack_banks');

            if (is_array($banks) && $banks !== []) {
                return $banks;
            }

            // Ten seconds, because this is read while a page is being drawn.
            $response = Http::withToken($this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/bank", ['currency' => 'NGN']);

            $banks = $response->successful() ? ($response->json('data') ?? []) : [];

            // Only a real list is kept. `remember` would have cached the empty answer a
            // failed call gives, which takes the bank list off the page for a whole day
            // because Paystack had one bad minute.
            if ($banks !== []) {
                cache()->put('paystack_banks', $banks, 86400);
            }

            return $banks;
        } catch (\Exception $e) {
            Log::error('Paystack Get Banks Exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The banks Paystack will issue a virtual account number through, as slug => name.
     *
     * A different question from getBanks above, which is every bank in the country and is
     * what the school picks its own account from. Only some of those banks will open a
     * dedicated account for a child, and Paystack is the one that knows which — hence the
     * separate endpoint. Letting the office type a bank name into a box instead is how
     * every number the hub tries to open comes back refused.
     *
     * @return array<string,string>
     */
    public function getVirtualAccountBanks(): array
    {
        if (! $this->isConfigured()) {
            return self::VIRTUAL_ACCOUNT_BANKS;
        }

        $providers = cache()->remember('paystack_dva_providers', 86400, function (): array {
            try {
                // Five seconds, not the thirty this would otherwise wait: the list is read
                // while a page is being drawn, and a settings page that hangs is a settings
                // page the office reports as broken.
                $response = Http::withToken($this->secretKey)
                    ->timeout(5)
                    ->get("{$this->baseUrl}/dedicated_account/available_providers");

                return $response->successful() ? ($response->json()['data'] ?? []) : [];
            } catch (\Exception $e) {
                // Paystack being unreachable must not take the settings page with it, so
                // this falls back to the banks below rather than throwing.
                Log::warning('Paystack Virtual Account Providers Unavailable', ['error' => $e->getMessage()]);

                return [];
            }
        });

        $banks = collect($providers)
            ->filter(fn (array $provider) => filled($provider['provider_slug'] ?? null))
            ->mapWithKeys(fn (array $provider) => [
                $provider['provider_slug'] => $provider['bank_name'] ?? $provider['provider_slug'],
            ])
            ->all();

        return $banks === [] ? self::VIRTUAL_ACCOUNT_BANKS : $banks;
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        try {
            // Ten seconds, not the thirty this would otherwise wait. The answer is wanted
            // while somebody is looking at the page — either they are typing an account
            // number and being told whose it is, or they have pressed Save — and half a
            // minute of nothing is a page the office decides is broken.
            $response = Http::withToken($this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/bank/resolve", [
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                ]);

            $name = $response->json('data.account_name');

            // Paystack answers 200 with `status: false` as well as failing outright, so
            // what says whether this worked is whether a name came back — not the code.
            if ($response->successful() && filled($name)) {
                return [
                    'status' => true,
                    'account_name' => $name,
                    'account_number' => $response->json('data.account_number', $accountNumber),
                ];
            }

            return [
                'status' => false,
                // The bank's own sentence where it gave one. "Could not resolve account
                // name" is more use to the office than anything written here, and this
                // sentence is now read out on the page rather than only logged.
                'message' => $response->json('message') ?: 'Could not resolve account details',
            ];
        } catch (\Exception $e) {
            Log::warning('Paystack Account Resolution Unavailable', ['error' => $e->getMessage()]);

            // Said rather than thrown, because an unreachable bank is not the office's
            // mistake and is certainly not something to put a stack trace in front of.
            return [
                'status' => false,
                'message' => 'The bank could not be reached just now. Try again.',
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
                // Which bank issues the number depends on how the school's Paystack
                // account is set up, so it is a setting rather than the one bank this
                // was first written against — get it wrong and Paystack refuses every
                // account number the office tries to open.
                'preferred_bank' => (string) (Setting::get('paystack_dva_bank') ?: 'wema-bank'),
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
