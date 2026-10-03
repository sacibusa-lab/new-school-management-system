<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Services\Payment\PaystackProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The accounts the school is paid into.
 *
 * As against the dedicated account each child is given, these belong to the school:
 * the fees account, the PTA account, the boarding account. They are what gets printed
 * on a letter and read out over the telephone, which is why the number is checked
 * against the bank before it is saved — a mistyped account number is money that never
 * arrives, and nobody finds out until a parent produces a teller slip.
 */
class BankAccountController extends Controller
{
    public function __construct(
        private readonly PaystackProvider $paystack,
    ) {}

    public function index(): View
    {
        $this->authorize('fees.manage');

        return view('admin.business.bank-accounts', [
            'accounts' => BankAccount::query()
                ->orderByDesc('is_primary')
                ->orderBy('label')
                ->get(),
            'banks' => $this->paystack->isConfigured() ? $this->paystack->getBanks() : [],
            'paystackReady' => $this->paystack->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'bank_code' => ['nullable', 'string', 'max:20', 'required_without:bank_name'],
            'bank_name' => ['nullable', 'string', 'max:120', 'required_without:bank_code'],
            'account_number' => ['required', 'string', 'digits:10', 'unique:bank_accounts,account_number'],
            'account_name' => ['nullable', 'string', 'max:160'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $bankName = $this->bankName($validated['bank_code'] ?? null, $validated['bank_name'] ?? null);
        $accountName = $validated['account_name'] ?? null;

        // Where the bank can be asked, its answer is the one kept: what the bank calls
        // the account is the only version of the name that cannot be somebody's typing.
        if ($this->paystack->isConfigured() && filled($validated['bank_code'] ?? null)) {
            $resolved = $this->paystack->resolveAccountNumber($validated['account_number'], $validated['bank_code']);

            if (empty($resolved['status'])) {
                return back()->withErrors([
                    'account_number' => $resolved['message'] ?? 'That account number could not be checked with the bank.',
                ])->withInput();
            }

            $accountName = $resolved['account_name'];
        }

        if (blank($accountName)) {
            return back()->withErrors([
                'account_name' => 'Enter the name the account is held in. It can only be checked with the bank when a Paystack key is set.',
            ])->withInput();
        }

        $account = BankAccount::create([
            'label' => $validated['label'],
            'bank_name' => $bankName,
            'bank_code' => $validated['bank_code'] ?? null,
            'account_number' => $validated['account_number'],
            'account_name' => $accountName,
            'is_primary' => $request->boolean('is_primary'),
            'is_active' => true,
        ]);

        $this->settlePrimary($account, $request->boolean('is_primary'));

        return back()->with('status', "{$account->label()} saved.");
    }

    public function update(Request $request, BankAccount $account): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $account->update([
            'label' => $validated['label'],
            'is_primary' => $request->boolean('is_primary'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->settlePrimary($account, $request->boolean('is_primary'));

        return back()->with('status', 'Bank account updated.');
    }

    public function destroy(BankAccount $account): RedirectResponse
    {
        $this->authorize('fees.manage');

        $account->delete();

        return back()->with('status', 'Bank account removed.');
    }

    /**
     * The bank's name. Where a code was chosen from Paystack's list, the name comes
     * from the same list, so the two can never disagree with each other.
     */
    protected function bankName(?string $code, ?string $name): string
    {
        if (blank($code)) {
            return (string) $name;
        }

        $bank = collect($this->paystack->getBanks())->firstWhere('code', $code);

        return (string) ($bank['name'] ?? $name ?? $code);
    }

    /**
     * There is one primary account: marking a second demotes the first, because a
     * letter that names two accounts as the one to pay into names none.
     */
    protected function settlePrimary(BankAccount $account, bool $isPrimary): void
    {
        if (! $isPrimary) {
            return;
        }

        BankAccount::query()->whereKeyNot($account->id)->update(['is_primary' => false]);
    }
}
