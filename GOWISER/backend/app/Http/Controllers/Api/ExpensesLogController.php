<?php

namespace App\Http\Controllers\Api;

use App\Events\ExpensesUpdated;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ExpensesCategory;
use App\Models\ExpensesLog;
use App\Services\GoogleDriveService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpensesLogController extends Controller
{
    private const RECEIPT_FOLDER = 'Expense Receipts';

    /**
     * Resolved on demand rather than constructor-injected. GoogleDriveService builds a
     * Google client in its constructor, so injecting it here would make every request
     * to this controller — including the read-only index the legacy ExpensesLog page
     * calls — depend on Drive credentials being present. Only uploads need it.
     */
    private function drive(): GoogleDriveService
    {
        return app(GoogleDriveService::class);
    }

    /**
     * Org rule used across this app: a user scoped to an organization sees that
     * organization's rows; a user with no organization sees the unscoped ones.
     */
    private function scopeToOrganization($query, $currentUser)
    {
        if (!$currentUser) {
            return $query;
        }

        return $currentUser->organization_id
            ? $query->where('organization_id', $currentUser->organization_id)
            : $query->whereNull('organization_id');
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('payee', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('invoice_no', 'like', "%{$search}%");
            });
        }

        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->expense_type);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        return $query;
    }

    /**
     * Kept byte-for-byte compatible with what the legacy ExpensesLog page already
     * consumes — new keys are added alongside, never in place of, the old ones.
     */
    private function format($record)
    {
        return [
            'id' => (string) $record->id,
            'expensesId' => (string) $record->id,
            'date' => $record->date ? Carbon::parse($record->date)->format('n/j/Y') : '',
            'dateRaw' => $record->date ? Carbon::parse($record->date)->format('Y-m-d') : '',
            'amount' => (float) $record->amount,
            'payee' => $record->payee ?? '',
            'category' => $record->category ?? '',
            'categoryId' => $record->category_id ? (int) $record->category_id : null,
            'expenseType' => $record->expense_type ?? ExpensesLog::TYPE_DAILY,
            'description' => $record->description ?? '',
            'invoiceNo' => $record->invoice_no ?? '',
            'referenceNo' => $record->reference_no ?? '',
            'provider' => $record->provider ?? '',
            'photo' => $record->photo,
            'processedBy' => $record->processed_by ?? '',
            'modifiedBy' => $record->modified_by ?? '',
            'modifiedDate' => $record->modified_date
                ? Carbon::parse($record->modified_date)->format('n/j/Y g:i:s A')
                : '',
            'userEmail' => $record->user_email ?? '',
            'receivedDate' => $record->received_date
                ? Carbon::parse($record->received_date)->format('n/j/Y')
                : '',
            'receivedDateRaw' => $record->received_date
                ? Carbon::parse($record->received_date)->format('Y-m-d')
                : '',
            'supplier' => $record->supplier ?? '',
            'location' => $record->location ?? '',
            'barangay' => $record->barangay ?? '',
            'city' => $record->city ?? 'All',
            'organization_id' => $record->organization_id ?? null,
        ];
    }

    private function currentUserEmail(Request $request)
    {
        if (auth()->check()) {
            return auth()->user()->email_address;
        }

        return $request->user_email
            ?? $request->modified_by
            ?? $request->modifiedBy
            ?? 'System';
    }

    private function uploadReceipt($file)
    {
        try {
            $drive = $this->drive();

            $folderId = $drive->findFolder(self::RECEIPT_FOLDER)
                ?? $drive->createFolder(self::RECEIPT_FOLDER);

            $extension = $file->getClientOriginalExtension();
            $fileName = 'receipt_' . time() . '_' . Str::random(6) . '.' . $extension;

            return $drive->uploadFile(
                $file,
                $folderId,
                $fileName,
                $file->getMimeType()
            );
        } catch (\Exception $e) {
            Log::error('Failed to upload expense receipt to Google Drive', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Resolves the category to store. expenses_logs keeps BOTH category_id and the
     * legacy free-text `category` string, because the old read-only page renders the
     * string and has no join. Writing both keeps the two views consistent.
     */
    private function resolveCategory(Request $request, $currentUser)
    {
        if (!$request->filled('category_id')) {
            return [null, $request->input('category')];
        }

        $category = ExpensesCategory::find($request->category_id);
        if (!$category) {
            throw new \Exception('Selected category does not exist.');
        }

        if ($currentUser && $currentUser->organization_id
            && $category->organization_id
            && $category->organization_id !== $currentUser->organization_id) {
            throw new \Exception('Selected category belongs to another organization.');
        }

        return [$category->id, $category->category_name];
    }

    private function validationRules($required = true)
    {
        $req = $required ? 'required' : 'sometimes|required';

        return [
            'date' => "{$req}|date",
            'amount' => "{$req}|numeric|min:0",
            'expense_type' => "{$req}|string|in:" . implode(',', ExpensesLog::TYPES),
            'category_id' => 'nullable|integer|exists:expenses_category,id',
            'category' => 'nullable|string|max:150',
            'payee' => 'nullable|string|max:300',
            'description' => 'nullable|string',
            'invoice_no' => 'nullable|string|max:300',
            'reference_no' => 'nullable|string|max:300',
            'provider' => 'nullable|string|max:150',
            'supplier' => 'nullable|string|max:150',
            'location' => 'nullable|string|max:150',
            'barangay' => 'nullable|string|max:150',
            'city' => 'nullable|string|max:300',
            'received_date' => 'nullable|date',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,pdf|max:10240',
        ];
    }

    public function index(Request $request)
    {
        try {
            $currentUser = auth()->user();

            $query = ExpensesLog::query();
            $this->scopeToOrganization($query, $currentUser);
            $this->applyFilters($query, $request);

            $query->orderBy('date', 'desc')->orderBy('modified_date', 'desc');

            $data = $query->get()->map(fn ($record) => $this->format($record));

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $currentUser = auth()->user();

            $query = ExpensesLog::query()->where('id', $id);
            $this->scopeToOrganization($query, $currentUser);

            $record = $query->firstOrFail();

            return response()->json(['status' => 'success', 'data' => $this->format($record)]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
        }
    }

    /**
     * Daily and monthly totals for the Expenses page summary cards. The type is a
     * bucket tag: a 'daily' expense counts toward today's card on the day it is
     * dated, a 'monthly' one counts toward the month it falls in. Nothing accrues
     * and nothing is generated.
     */
    public function summary(Request $request)
    {
        try {
            $currentUser = auth()->user();

            $today = Carbon::today();
            $monthStart = $today->copy()->startOfMonth();
            $monthEnd = $today->copy()->endOfMonth();

            $base = function () use ($currentUser, $request) {
                $query = ExpensesLog::query();
                $this->scopeToOrganization($query, $currentUser);
                if ($request->filled('category_id')) {
                    $query->where('category_id', $request->category_id);
                }
                return $query;
            };

            $dailyToday = (clone $base())->daily()->whereDate('date', $today)->sum('amount');
            $dailyThisMonth = (clone $base())->daily()
                ->whereBetween('date', [$monthStart, $monthEnd])->sum('amount');
            $monthlyThisMonth = (clone $base())->monthly()
                ->whereBetween('date', [$monthStart, $monthEnd])->sum('amount');

            $byCategory = (clone $base())
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->select(
                    DB::raw("COALESCE(NULLIF(category, ''), 'Uncategorized') as label"),
                    DB::raw('SUM(COALESCE(amount, 0)) as value')
                )
                ->groupBy('label')
                ->orderByDesc('value')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'daily_today' => (float) $dailyToday,
                    'daily_this_month' => (float) $dailyThisMonth,
                    'monthly_this_month' => (float) $monthlyThisMonth,
                    'total_this_month' => (float) ($dailyThisMonth + $monthlyThisMonth),
                    'count_today' => (clone $base())->whereDate('date', $today)->count(),
                    'count_this_month' => (clone $base())
                        ->whereBetween('date', [$monthStart, $monthEnd])->count(),
                    'by_category' => $byCategory,
                    'scope' => [
                        'today' => $today->format('Y-m-d'),
                        'month_start' => $monthStart->format('Y-m-d'),
                        'month_end' => $monthEnd->format('Y-m-d'),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->validationRules(true));

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $currentUser = auth()->user();
            $userEmail = $this->currentUserEmail($request);
            [$categoryId, $categoryName] = $this->resolveCategory($request, $currentUser);

            $photo = null;
            if ($request->hasFile('receipt')) {
                $photo = $this->uploadReceipt($request->file('receipt'));
            }

            $now = Carbon::now();

            $expense = new ExpensesLog();
            // varchar(50) PK — same UUID strategy InventoryLog uses.
            $expense->id = (string) Str::uuid();
            $expense->organization_id = $currentUser->organization_id ?? null;
            $expense->date = $request->date;
            $expense->amount = $request->amount;
            $expense->expense_type = $request->expense_type;
            $expense->category_id = $categoryId;
            $expense->category = $categoryName;
            $expense->payee = $request->payee;
            $expense->description = $request->description;
            $expense->invoice_no = $request->invoice_no;
            $expense->reference_no = $request->reference_no;
            $expense->provider = $request->provider;
            $expense->supplier = $request->supplier;
            $expense->location = $request->location;
            $expense->barangay = $request->barangay;
            $expense->city = $request->city;
            $expense->received_date = $request->received_date;
            $expense->photo = $photo;
            $expense->processed_by = $userEmail;
            $expense->modified_by = $userEmail;
            $expense->modified_date = $now;
            $expense->user_email = $userEmail;
            $expense->created_at = $now;
            $expense->save();

            ActivityLog::log(
                'Expense Created',
                "New {$expense->expense_type} expense recorded: {$expense->category} — {$expense->amount}",
                'info',
                [
                    'resource_type' => 'ExpensesLog',
                    'resource_id' => $expense->id,
                    'additional_data' => $expense->toArray(),
                    'organization_id' => $expense->organization_id,
                ]
            );

            event(new ExpensesUpdated([
                'action' => 'created',
                'expense_id' => $expense->id,
                'expense_type' => $expense->expense_type,
            ]));

            return response()->json([
                'status' => 'success',
                'message' => 'Expense recorded successfully',
                'data' => $this->format($expense),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error recording expense: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), $this->validationRules(false));

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $currentUser = auth()->user();

            $query = ExpensesLog::query()->where('id', $id);
            $this->scopeToOrganization($query, $currentUser);
            $expense = $query->first();

            if (!$expense) {
                return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
            }

            $userEmail = $this->currentUserEmail($request);

            if ($request->has('category_id') || $request->has('category')) {
                [$categoryId, $categoryName] = $this->resolveCategory($request, $currentUser);
                $expense->category_id = $categoryId;
                $expense->category = $categoryName;
            }

            if ($request->hasFile('receipt')) {
                $expense->photo = $this->uploadReceipt($request->file('receipt'));
            }

            foreach ([
                'date', 'amount', 'expense_type', 'payee', 'description', 'invoice_no',
                'reference_no', 'provider', 'supplier', 'location', 'barangay', 'city',
                'received_date',
            ] as $field) {
                if ($request->has($field)) {
                    $expense->{$field} = $request->input($field);
                }
            }

            $expense->modified_by = $userEmail;
            $expense->modified_date = Carbon::now();
            $expense->save();

            ActivityLog::log(
                'Expense Updated',
                "Expense updated: {$expense->category} — {$expense->amount} (ID: {$id})",
                'info',
                [
                    'resource_type' => 'ExpensesLog',
                    'resource_id' => $expense->id,
                    'additional_data' => $expense->toArray(),
                    'organization_id' => $expense->organization_id,
                ]
            );

            event(new ExpensesUpdated([
                'action' => 'updated',
                'expense_id' => $expense->id,
                'expense_type' => $expense->expense_type,
            ]));

            return response()->json([
                'status' => 'success',
                'message' => 'Expense updated successfully',
                'data' => $this->format($expense),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating expense: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $currentUser = auth()->user();

            $query = ExpensesLog::query()->where('id', $id);
            $this->scopeToOrganization($query, $currentUser);
            $expense = $query->first();

            if (!$expense) {
                return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
            }

            $expenseData = $expense->toArray();
            $label = $expense->category . ' — ' . $expense->amount;
            $organizationId = $expense->organization_id;

            // Soft delete: the row stays recoverable.
            $expense->delete();

            ActivityLog::log(
                'Expense Deleted',
                "Expense deleted: {$label} (ID: {$id})",
                'warning',
                [
                    'resource_type' => 'ExpensesLog',
                    'resource_id' => $id,
                    'additional_data' => $expenseData,
                    'organization_id' => $organizationId,
                ]
            );

            event(new ExpensesUpdated(['action' => 'deleted', 'expense_id' => $id]));

            return response()->json([
                'status' => 'success',
                'message' => 'Expense deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error deleting expense: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Streams the filtered list as CSV. Streamed rather than built in memory so a
     * large date range does not have to fit in a single string.
     */
    public function export(Request $request)
    {
        $currentUser = auth()->user();

        $query = ExpensesLog::query();
        $this->scopeToOrganization($query, $currentUser);
        $this->applyFilters($query, $request);
        $query->orderBy('date', 'desc')->orderBy('modified_date', 'desc');

        $filename = 'expenses_' . Carbon::now()->format('Y-m-d') . '.csv';

        return new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID', 'Date', 'Type', 'Category', 'Payee', 'Amount', 'Description',
                'Invoice No', 'Reference No', 'Provider', 'Supplier', 'Received Date',
                'Location', 'Barangay', 'City', 'Processed By', 'Modified By', 'Modified Date',
            ]);

            // lazy(), not chunkById(): chunkById paginates on `id > lastId` but leaves the
            // date ordering above as the primary sort, so rows with a lower UUID than the
            // last row of a page get skipped. lazy() uses offset paging and honours the
            // declared order, which is what a date-sorted export needs.
            foreach ($query->lazy(500) as $r) {
                fputcsv($handle, [
                    $r->id,
                    $r->date ? Carbon::parse($r->date)->format('Y-m-d') : '',
                    strtoupper($r->expense_type ?? ''),
                    $r->category,
                    $r->payee,
                    $r->amount,
                    $r->description,
                    $r->invoice_no,
                    $r->reference_no,
                    $r->provider,
                    $r->supplier,
                    $r->received_date ? Carbon::parse($r->received_date)->format('Y-m-d') : '',
                    $r->location,
                    $r->barangay,
                    $r->city,
                    $r->processed_by,
                    $r->modified_by,
                    $r->modified_date ? Carbon::parse($r->modified_date)->format('Y-m-d H:i:s') : '',
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }
}
