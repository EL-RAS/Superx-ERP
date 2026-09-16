<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class JournalEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = JournalEntry::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        if ($request->has('is_posted')) {
            $query->where('is_posted', $request->boolean('is_posted'));
        }

        if ($request->has('account_id')) {
            $query->whereHas('lines', fn ($q) => $q->where('account_id', $request->input('account_id')));
        }

        $entries = $query->withSum(['lines as total_debit' => fn ($q) => $q->where('debit', '>', 0)], 'debit')
            ->withSum(['lines as total_credit' => fn ($q) => $q->where('credit', '>', 0)], 'credit')
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_number' => 'nullable|string',
            'date' => 'required|date',
            'description' => 'required|string',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'is_posted' => 'nullable|boolean',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => ['required', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('business_id', $request->user()->business_id))],
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string',
        ]);

        $totalDebit = collect($validated['lines'])->sum('debit');
        $totalCredit = collect($validated['lines'])->sum('credit');

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            return response()->json([
                'message' => 'Total debits must equal total credits.',
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ], 422);
        }

        if ($validated['is_posted'] ?? false) {
            $this->assertFiscalYearOpen($request->user()->business_id, $validated['date']);
        }

        return DB::transaction(function () use ($request, $validated) {
            $entry = JournalEntry::create([
                'business_id' => $request->user()->business_id,
                'entry_number' => $validated['entry_number'] ?? 'JE-'.strtoupper(Str::random(8)),
                'date' => $validated['date'],
                'description' => $validated['description'],
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'user_id' => $request->user()->id,
                'is_posted' => $validated['is_posted'] ?? false,
            ]);

            foreach ($validated['lines'] as $line) {
                JournalEntryLine::create([
                    'business_id' => $request->user()->business_id,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            if ($entry->is_posted) {
                $this->updateAccountBalances($entry);
            }

            $entry->load('lines.account:id,code,name,type');

            return response()->json($entry, 201);
        });
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        $journalEntry->load('lines.account:id,code,name,type');

        return response()->json($journalEntry);
    }

    public function update(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->is_posted) {
            return response()->json(['message' => 'Cannot edit a posted journal entry.'], 422);
        }

        $validated = $request->validate([
            'entry_number' => 'sometimes|string',
            'date' => 'sometimes|date',
            'description' => 'sometimes|string',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'is_posted' => 'nullable|boolean',
            'lines' => 'nullable|array|min:2',
            'lines.*.account_id' => ['required_with:lines', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('business_id', $request->user()->business_id))],
            'lines.*.debit' => 'required_with:lines|numeric|min:0',
            'lines.*.credit' => 'required_with:lines|numeric|min:0',
            'lines.*.description' => 'nullable|string',
        ]);

        if (isset($validated['lines'])) {
            $totalDebit = collect($validated['lines'])->sum('debit');
            $totalCredit = collect($validated['lines'])->sum('credit');

            if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                return response()->json([
                    'message' => 'Total debits must equal total credits.',
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                ], 422);
            }
        }

        if (($validated['is_posted'] ?? $journalEntry->is_posted) === true) {
            $this->assertFiscalYearOpen(
                $journalEntry->business_id,
                $validated['date'] ?? $journalEntry->date->toDateString()
            );
        }

        return DB::transaction(function () use ($validated, $journalEntry) {
            $entryFields = collect($validated)->except('lines')->filter()->toArray();
            if (! empty($entryFields)) {
                $journalEntry->update($entryFields);
            }

            if (isset($validated['lines'])) {
                $journalEntry->lines()->delete();

                foreach ($validated['lines'] as $line) {
                    JournalEntryLine::create([
                        'business_id' => $journalEntry->business_id,
                        'journal_entry_id' => $journalEntry->id,
                        'account_id' => $line['account_id'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'description' => $line['description'] ?? null,
                    ]);
                }
            }

            $journalEntry->load('lines.account:id,code,name,type');

            return response()->json($journalEntry);
        });
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->is_posted) {
            return response()->json(['message' => 'Cannot delete a posted journal entry.'], 422);
        }

        $journalEntry->lines()->delete();
        $journalEntry->delete();

        return response()->json(['message' => 'Journal entry deleted.']);
    }

    public function post(JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->is_posted) {
            return response()->json(['message' => 'Journal entry is already posted.'], 422);
        }

        $this->assertFiscalYearOpen($journalEntry->business_id, $journalEntry->date->toDateString());

        DB::transaction(function () use ($journalEntry) {
            $journalEntry->update(['is_posted' => true]);
            $this->updateAccountBalances($journalEntry);
        });

        $journalEntry->load('lines.account:id,code,name,type');

        return response()->json($journalEntry);
    }

    public function reverse(JournalEntry $journalEntry): JsonResponse
    {
        if (! $journalEntry->is_posted) {
            return response()->json(['message' => 'Only posted entries can be reversed.'], 422);
        }

        $this->assertFiscalYearOpen($journalEntry->business_id, now()->toDateString());

        $reversed = DB::transaction(function () use ($journalEntry) {
            $reversal = JournalEntry::create([
                'business_id' => $journalEntry->business_id,
                'entry_number' => 'REV-'.strtoupper(Str::random(8)),
                'date' => now()->toDateString(),
                'description' => "Reversal of {$journalEntry->entry_number}: {$journalEntry->description}",
                'reference_type' => 'journal_entry',
                'reference_id' => $journalEntry->id,
                'user_id' => $journalEntry->user_id,
                'is_posted' => true,
            ]);

            foreach ($journalEntry->lines as $line) {
                JournalEntryLine::create([
                    'business_id' => $journalEntry->business_id,
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => "Reversal of line: {$line->description}",
                ]);
            }

            $this->updateAccountBalances($reversal);

            return $reversal;
        });

        $reversed->load('lines.account:id,code,name,type');

        return response()->json($reversed, 201);
    }

    private function assertFiscalYearOpen(string $businessId, string $date): void
    {
        $closed = FiscalYear::where('business_id', $businessId)
            ->where('is_closed', true)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();

        if ($closed) {
            abort(422, 'Cannot post a journal entry in a closed fiscal year.');
        }
    }

    private function updateAccountBalances(JournalEntry $entry): void
    {
        app(AccountingService::class)->updateAccountBalances($entry);
    }
}
