<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceExpense;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinanceProfitController extends Controller
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function expenses(Request $request)
    {
        $data = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'category' => ['nullable', 'string', 'max:100']]);
        $rows = FinanceExpense::query()->with('recorder:id,name')
            ->when(! empty($data['date_from']), fn ($q) => $q->whereDate('expense_date', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->whereDate('expense_date', '<=', $data['date_to']))
            ->when(! empty($data['category']), fn ($q) => $q->where('category', $data['category']))
            ->latest('expense_date')->latest('id')->paginate(min(max((int) $request->input('per_page', 100), 1), 100));
        collect($rows->items())->each(fn ($row) => $row->setAttribute('attachment_url', $row->attachment_path ? Storage::disk('public')->url($row->attachment_path) : null));
        return response()->json(['success' => true, 'message' => 'Finance expenses retrieved.', 'data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]]);
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate(['category' => ['required', 'string', 'max:100'], 'description' => ['required', 'string', 'max:500'], 'amount' => ['required', 'numeric', 'min:0.01'], 'expense_date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:255'], 'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']]);
        $path = $request->file('attachment')?->store('finance-expenses', 'public');
        try {
            $row = DB::transaction(function () use ($request, $data, $path) {
                $row = FinanceExpense::create(['expense_number' => 'EXP-TMP-'.Str::uuid(), 'category' => trim($data['category']), 'description' => trim($data['description']), 'amount' => round((float) $data['amount'], 2), 'expense_date' => $data['expense_date'], 'reference' => $data['reference'] ?? null, 'attachment_path' => $path, 'recorded_by' => $request->user()->id]);
                $row->update(['expense_number' => 'EXP-'.date('Ymd').'-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT)]);
                $this->auditLogger->log($request, $request->user()->id, 'FinanceExpense', $row->id, 'finance_expense_recorded', null, $row->toArray(), 'Finance expense recorded.');
                return $row;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }
        return response()->json(['success' => true, 'message' => 'Expense recorded.', 'data' => $row->load('recorder:id,name'), 'meta' => null], 201);
    }

    public function destroyExpense(Request $request, FinanceExpense $expense)
    {
        $before = $expense->toArray();
        DB::transaction(function () use ($request, $expense, $before) { $this->auditLogger->log($request, $request->user()->id, 'FinanceExpense', $expense->id, 'finance_expense_deleted', $before, null, 'Finance expense deleted.'); $expense->delete(); });
        if ($expense->attachment_path) Storage::disk('public')->delete($expense->attachment_path);
        return response()->json(['success' => true, 'message' => 'Expense deleted.', 'data' => null, 'meta' => null]);
    }

    public function profitReport(Request $request)
    {
        $data = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $payments = DB::table('payments')->whereIn('status', ['paid', 'refunded'])
            ->when(! empty($data['date_from']), fn ($q) => $q->whereDate('paid_at', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->whereDate('paid_at', '<=', $data['date_to']));
        $grossRevenue = (float) $payments->sum('amount');
        $refunds = (float) DB::table('refund_requests')->where('status', 'processed')
            ->when(! empty($data['date_from']), fn ($q) => $q->whereDate('processed_at', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->whereDate('processed_at', '<=', $data['date_to']))->sum('amount');
        $consumptionCost = (float) DB::table('department_stock_consumptions')->where('approval_status', 'approved')
            ->when(! empty($data['date_from']), fn ($q) => $q->whereDate('consumed_at', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->whereDate('consumed_at', '<=', $data['date_to']))->sum('total_cost');
        $otherExpenses = (float) DB::table('finance_expenses')
            ->when(! empty($data['date_from']), fn ($q) => $q->whereDate('expense_date', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->whereDate('expense_date', '<=', $data['date_to']))->sum('amount');
        $netRevenue = round($grossRevenue - $refunds, 2); $totalExpenses = round($consumptionCost + $otherExpenses, 2); $profit = round($netRevenue - $totalExpenses, 2);
        return response()->json(['success' => true, 'message' => 'Profit report retrieved.', 'data' => ['gross_revenue' => round($grossRevenue, 2), 'refunds' => round($refunds, 2), 'net_revenue' => $netRevenue, 'approved_consumption_cost' => round($consumptionCost, 2), 'other_expenses' => round($otherExpenses, 2), 'total_expenses' => $totalExpenses, 'net_profit' => $profit, 'profit_margin' => $netRevenue > 0 ? round(($profit / $netRevenue) * 100, 2) : 0], 'meta' => ['date_from' => $data['date_from'] ?? null, 'date_to' => $data['date_to'] ?? null]]);
    }
}
