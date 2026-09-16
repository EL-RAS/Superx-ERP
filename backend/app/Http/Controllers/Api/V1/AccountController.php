<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class AccountController extends Controller
{
    private const SYSTEM_CODES = [
        '1000', '1005', '1010', '1020', '1030', '1040',
        '2000', '2010', '2020',
        '3000', '3010', '3020',
        '4000', '4010', '4020', '4030', '4040',
        '5000', '5010', '5020', '5030', '5040', '5041', '5042', '5050',
    ];

    private function scopedAccountRule(int|string $businessId): Exists
    {
        return Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('business_id', $businessId));
    }

    public function index(Request $request): JsonResponse
    {
        $query = Account::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $accounts = $query->with('parent:id,code,name,type')
            ->orderBy('code', 'asc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('accounts', 'code')->where(fn ($query) => $query->where('business_id', $request->user()->business_id)),
            ],
            'name' => 'required|string',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'parent_id' => ['nullable', $this->scopedAccountRule($request->user()->business_id)],
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
            'opening_balance' => 'nullable|numeric',
        ]);

        $openingBalance = round((float) ($validated['opening_balance'] ?? 0), 2);
        unset($validated['opening_balance']);

        $validated['business_id'] = $request->user()->business_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $account = Account::create($validated);

        if ($openingBalance != 0) {
            $this->postOpeningBalance($request, $account, $openingBalance);
            $account->refresh();
        }

        $account->load('parent:id,code,name,type');

        return response()->json($account, 201);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:asset,liability,equity,revenue,expense',
        ]);

        $ranges = [
            'asset' => [1000, 1999],
            'liability' => [2000, 2999],
            'equity' => [3000, 3999],
            'revenue' => [4000, 4999],
            'expense' => [5000, 5999],
        ];

        [$min, $max] = $ranges[$validated['type']];

        $maxCode = Account::where('type', $validated['type'])
            ->pluck('code')
            ->map(fn ($code) => (int) $code)
            ->filter(fn ($code) => $code >= $min && $code <= $max)
            ->max();

        $next = $maxCode !== null ? $maxCode + 10 : $min + 10;

        if ($next > $max) {
            return response()->json(['message' => 'No account codes available in this range.'], 422);
        }

        return response()->json(['code' => (string) $next]);
    }

    public function show(Account $account): JsonResponse
    {
        $account->load('parent:id,code,name,type');

        return response()->json($account);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('accounts', 'code')
                    ->where(fn ($query) => $query->where('business_id', $request->user()->business_id))
                    ->ignore($account->id),
            ],
            'name' => 'sometimes|string',
            'type' => 'sometimes|in:asset,liability,equity,revenue,expense',
            'parent_id' => ['nullable', $this->scopedAccountRule($request->user()->business_id)],
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $systemCritical = in_array($account->code, self::SYSTEM_CODES, true);

        if ($systemCritical && isset($validated['code']) && $validated['code'] !== $account->code) {
            return response()->json([
                'message' => 'This is a system-critical account and its code cannot be changed.',
            ], 422);
        }

        if ($systemCritical && isset($validated['type']) && $validated['type'] !== $account->type) {
            return response()->json([
                'message' => 'This is a system-critical account and its type cannot be changed.',
            ], 422);
        }

        if (isset($validated['type']) && $validated['type'] !== $account->type && $account->journalEntryLines()->exists()) {
            return response()->json([
                'message' => 'Cannot change the type of an account that already has journal entries (this would corrupt its balance).',
            ], 422);
        }

        $account->update($validated);

        return response()->json($account);
    }

    public function destroy(Account $account): JsonResponse
    {
        if (in_array($account->code, self::SYSTEM_CODES, true)) {
            return response()->json([
                'message' => 'This is a system-critical account and cannot be deleted.',
            ], 422);
        }

        if ($account->children()->exists()) {
            return response()->json([
                'message' => 'Cannot delete an account that has child accounts. Please deactivate it instead by setting Active = No.',
            ], 422);
        }

        if ($account->journalEntryLines()->exists()) {
            return response()->json([
                'message' => 'Cannot delete an account with existing journal entries. Please deactivate it instead by setting Active = No.',
            ], 422);
        }

        if (BankReconciliation::where('account_id', $account->id)->exists()) {
            return response()->json([
                'message' => 'Cannot delete an account that has bank reconciliation records. Please deactivate it instead by setting Active = No.',
            ], 422);
        }

        $account->forceDelete();

        return response()->json(['message' => 'Account deleted.']);
    }

    private function postOpeningBalance(Request $request, Account $account, float $amount): void
    {
        $isDebitNormal = in_array($account->type, ['asset', 'expense'], true);

        $lines = $isDebitNormal
            ? [
                ['account_id' => $account->id, 'debit' => $amount, 'credit' => 0],
                ['code' => '3010', 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_id' => $account->id, 'debit' => 0, 'credit' => $amount],
                ['code' => '3010', 'debit' => $amount, 'credit' => 0],
            ];

        app(AccountingService::class)->post($request->user()->business_id, [
            'date' => now()->toDateString(),
            'description' => "Opening balance for {$account->name}",
            'reference_type' => 'account',
            'reference_id' => $account->id,
            'user_id' => $request->user()->id,
            'entry_prefix' => 'OPN',
        ], $lines);
    }
}
