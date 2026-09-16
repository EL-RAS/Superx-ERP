<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\JournalEntryLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BankReconciliation::with('account:id,code,name');

        if ($request->has('account_id')) {
            $query->where('account_id', $request->input('account_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $reconciliations = $query->orderBy('statement_date', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($reconciliations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', \Illuminate\Validation\Rule::exists('accounts', 'id')->where('business_id', $request->user()->business_id)],
            'statement_date' => 'required|date',
            'statement_balance' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $bookBalance = $this->calculateBookBalance($validated['account_id'], $validated['statement_date']);

        $validated['business_id'] = $request->user()->business_id;
        $validated['book_balance'] = $bookBalance;
        $validated['status'] = 'draft';

        $reconciliation = BankReconciliation::create($validated);

        $unreconciledLines = $this->getUnreconciledLines($validated['account_id'], $validated['statement_date']);
        foreach ($unreconciledLines as $line) {
            BankReconciliationLine::create([
                'business_id' => $request->user()->business_id,
                'bank_reconciliation_id' => $reconciliation->id,
                'journal_entry_line_id' => $line->id,
                'amount' => $line->debit - $line->credit,
                'description' => $line->description ?? $line->journalEntry->description,
                'reference' => $line->journalEntry->entry_number,
                'statement_date' => $line->journalEntry->date,
                'reconciled' => false,
            ]);
        }

        return response()->json($reconciliation->load('lines'), 201);
    }

    public function show(BankReconciliation $bankReconciliation): JsonResponse
    {
        $bankReconciliation->load('account:id,code,name');
        $bankReconciliation->load('lines.journalEntryLine.journalEntry:id,entry_number,date,description');
        $bankReconciliation->load('reconciler:id,name');

        return response()->json($bankReconciliation);
    }

    public function autoMatch(BankReconciliation $bankReconciliation): JsonResponse
    {
        if ($bankReconciliation->status !== 'draft') {
            return response()->json(['message' => 'Only draft reconciliations can be matched.'], 422);
        }

        $matched = 0;
        $lines = $bankReconciliation->lines()->where('reconciled', false)->get();

        foreach ($lines as $line) {
            if (abs($line->amount) > 0) {
                $line->update(['reconciled' => true, 'reconciled_at' => now()]);
                $matched++;
            }
        }

        return response()->json([
            'message' => "Auto-matched {$matched} line(s).",
            'matched' => $matched,
            'reconciliation' => $bankReconciliation->load('lines'),
        ]);
    }

    public function reconcileLine(BankReconciliation $bankReconciliation, BankReconciliationLine $line): JsonResponse
    {
        if ($bankReconciliation->status !== 'draft') {
            return response()->json(['message' => 'Only draft reconciliations can be updated.'], 422);
        }

        $line->update([
            'reconciled' => true,
            'reconciled_at' => now(),
        ]);

        return response()->json($line);
    }

    public function unreconcileLine(BankReconciliation $bankReconciliation, BankReconciliationLine $line): JsonResponse
    {
        if ($bankReconciliation->status !== 'draft') {
            return response()->json(['message' => 'Only draft reconciliations can be updated.'], 422);
        }

        $line->update([
            'reconciled' => false,
            'reconciled_at' => null,
        ]);

        return response()->json($line);
    }

    public function addLine(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        if ($bankReconciliation->status !== 'draft') {
            return response()->json(['message' => 'Only draft reconciliations can be updated.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric',
            'description' => 'required|string',
            'reference' => 'nullable|string',
            'statement_date' => 'required|date',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['bank_reconciliation_id'] = $bankReconciliation->id;
        $validated['reconciled'] = true;
        $validated['reconciled_at'] = now();

        $line = BankReconciliationLine::create($validated);

        return response()->json($line, 201);
    }

    public function importStatement(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        if ($bankReconciliation->status !== 'draft') {
            return response()->json(['message' => 'Only draft reconciliations can be updated.'], 422);
        }

        if ($request->hasFile('statement')) {
            $content = $request->file('statement')->get();
        } else {
            $validated = $request->validate(['content' => 'required|string']);
            $content = $validated['content'];
        }

        if (trim((string) $content) === '') {
            return response()->json(['message' => 'The statement is empty.'], 422);
        }

        $rows = $this->parseStatement((string) $content);
        if (empty($rows)) {
            return response()->json(['message' => 'No parseable rows found in the statement. Expected CSV columns: date, amount, description, reference.'], 422);
        }

        $created = 0;
        foreach ($rows as $row) {
            BankReconciliationLine::create([
                'business_id' => $request->user()->business_id,
                'bank_reconciliation_id' => $bankReconciliation->id,
                'journal_entry_line_id' => null,
                'amount' => $row['amount'],
                'description' => $row['description'],
                'reference' => $row['reference'] ?? null,
                'statement_date' => $row['date'],
                'reconciled' => false,
            ]);
            $created++;
        }

        return response()->json([
            'message' => "Imported {$created} bank statement line(s).",
            'imported' => $created,
            'reconciliation' => $bankReconciliation->load('lines'),
        ]);
    }

    private function parseStatement(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $lines = array_values(array_filter($lines, fn ($l) => trim((string) $l) !== ''));

        if (empty($lines)) {
            return [];
        }

        $delimiter = $this->detectDelimiter((string) $lines[0]);
        $rows = array_map(fn ($l) => str_getcsv((string) $l, $delimiter), $lines);

        $header = $this->normalizeHeader($rows[0] ?? []);
        $hasHeader = count(array_intersect(['date', 'amount', 'debit', 'credit'], $header)) > 0;
        $start = $hasHeader ? 1 : 0;

        if ($hasHeader) {
            $dateIdx = array_search('date', $header, true);
            $amountIdx = array_search('amount', $header, true);
            $debitIdx = array_search('debit', $header, true);
            $creditIdx = array_search('credit', $header, true);
            $descIdx = array_search('description', $header, true);
            $refIdx = array_search('reference', $header, true);
        } else {
            $dateIdx = 0;
            $amountIdx = 1;
            $debitIdx = false;
            $creditIdx = false;
            $descIdx = 2;
            $refIdx = 3;
        }

        $result = [];
        for ($i = $start; $i < count($rows); $i++) {
            $cells = array_values(array_map('trim', $rows[$i]));
            if (empty($cells) || count(array_filter($cells)) === 0) {
                continue;
            }

            if ($debitIdx !== false && $creditIdx !== false) {
                $debit = $this->toSignedAmount($cells[$debitIdx] ?? null) ?? 0;
                $credit = $this->toSignedAmount($cells[$creditIdx] ?? null) ?? 0;
                $amount = round($credit - $debit, 2);
            } else {
                $amount = $this->toSignedAmount($amountIdx !== false ? ($cells[$amountIdx] ?? null) : $this->findFirstNumeric($cells));
            }
            if ($amount === null) {
                continue;
            }

            $date = $this->extractDate($cells, $dateIdx);

            $description = $descIdx !== false ? ($cells[$descIdx] ?? '') : '';
            if ($description === '') {
                $skip = array_values(array_unique(array_filter([$dateIdx, $amountIdx, $debitIdx, $creditIdx, $refIdx], fn ($i) => $i !== false)));
                $parts = [];
                foreach ($cells as $idx => $val) {
                    if (in_array($idx, $skip, true) || $val === '') {
                        continue;
                    }
                    $parts[] = $val;
                }
                $description = implode(' ', $parts);
            }

            $result[] = [
                'date' => $date ?: now()->toDateString(),
                'amount' => $amount,
                'description' => $description !== '' ? $description : 'Bank statement entry',
                'reference' => $refIdx !== false ? ($cells[$refIdx] ?? null) : null,
            ];
        }

        return $result;
    }

    private function detectDelimiter(string $line): string
    {
        if (str_contains($line, ';')) {
            return ';';
        }
        if (str_contains($line, "\t")) {
            return "\t";
        }

        return ',';
    }

    private function normalizeHeader(array $cells): array
    {
        $map = [
            'date' => 'date',
            'transaction date' => 'date',
            'value date' => 'date',
            'ØªØ§Ø±ÙŠØ®' => 'date',
            'amount' => 'amount',
            'Ø§Ù„Ù…Ø¨Ù„Øº' => 'amount',
            'debit' => 'debit',
            'Ù…Ø¯ÙŠÙ†' => 'debit',
            'credit' => 'credit',
            'Ø¯Ø§Ø¦Ù†' => 'credit',
            'description' => 'description',
            'narration' => 'description',
            'particulars' => 'description',
            'details' => 'description',
            'Ø§Ù„Ø¨ÙŠØ§Ù†' => 'description',
            'reference' => 'reference',
            'ref' => 'reference',
            'Ø±Ù‚Ù…' => 'reference',
        ];

        return array_map(function ($cell) use ($map) {
            $v = strtolower(trim(preg_replace('/^[\xEF\xBB\xBF]+/', '', (string) $cell)));

            return $map[$v] ?? $v;
        }, $cells);
    }

    private function extractDate(array $cells, int|false $idx): ?string
    {
        $candidates = [];
        if ($idx !== false) {
            $candidates[] = $cells[$idx] ?? '';
        }
        $candidates = array_merge($candidates, $cells);

        foreach ($candidates as $cell) {
            $parsed = $this->parseDate((string) $cell);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function parseDate(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $v);
            if ($date && $date->format($format) === $v) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function toSignedAmount(?string $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $v = trim((string) $value);
        $negative = false;

        if (str_starts_with($v, '(') && str_ends_with($v, ')')) {
            $negative = true;
            $v = substr($v, 1, -1);
        }
        if (str_starts_with($v, '-')) {
            $negative = true;
            $v = substr($v, 1);
        }

        $v = str_replace([',', ' '], '', $v);
        if (! is_numeric($v)) {
            return null;
        }

        $amount = (float) $v;

        return $negative ? -$amount : $amount;
    }

    private function findFirstNumeric(array $cells): ?string
    {
        foreach ($cells as $cell) {
            if ($this->toSignedAmount((string) $cell) !== null) {
                return (string) $cell;
            }
        }

        return null;
    }

    public function close(BankReconciliation $bankReconciliation): JsonResponse
    {
        if ($bankReconciliation->status !== 'draft') {
            return response()->json(['message' => 'Reconciliation is already closed.'], 422);
        }

        $unreconciled = $bankReconciliation->lines()->where('reconciled', false)->count();
        if ($unreconciled > 0) {
            return response()->json([
                'message' => "There are {$unreconciled} unreconciled line(s).",
                'unreconciled_count' => $unreconciled,
            ], 422);
        }

        $diff = abs($bankReconciliation->statement_balance - $bankReconciliation->book_balance);
        if ($diff > 0.01) {
            return response()->json([
                'message' => 'Statement and book balances do not match.',
                'difference' => round($diff, 4),
            ], 422);
        }

        DB::transaction(function () use ($bankReconciliation) {
            $bankReconciliation->update([
                'status' => 'reconciled',
                'reconciled_at' => now(),
                'reconciled_by' => auth()->id(),
            ]);

            foreach ($bankReconciliation->lines()->where('reconciled', true)->get() as $line) {
                if ($line->journal_entry_line_id) {
                    $journalLine = $line->journalEntryLine;
                    if ($journalLine) {
                        $journalLine->update([
                            'description' => ($journalLine->description ?? '')." [Reconciled #{$bankReconciliation->id}]",
                        ]);
                    }
                }
            }
        });

        return response()->json($bankReconciliation);
    }

    private function calculateBookBalance(int $accountId, string $statementDate): float
    {
        $account = Account::findOrFail($accountId);

        $debit = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('is_posted', true)
                ->where('date', '<=', $statementDate))
            ->sum('debit');
        $credit = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('is_posted', true)
                ->where('date', '<=', $statementDate))
            ->sum('credit');

        return match ($account->type) {
            'asset', 'expense' => round($debit - $credit, 4),
            default => round($credit - $debit, 4),
        };
    }

    private function getUnreconciledLines(int $accountId, string $statementDate)
    {
        $reconciledLineIds = BankReconciliationLine::where('journal_entry_line_id', '!=', null)
            ->whereHas('bankReconciliation', fn ($q) => $q->where('status', 'reconciled'))
            ->pluck('journal_entry_line_id')
            ->toArray();

        return JournalEntryLine::where('account_id', $accountId)
            ->whereNotIn('id', $reconciledLineIds)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('is_posted', true)
                ->where('date', '<=', $statementDate))
            ->with('journalEntry:id,entry_number,date,description')
            ->orderBy('created_at', 'asc')
            ->get();
    }
}
