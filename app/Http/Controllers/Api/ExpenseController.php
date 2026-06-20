<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $expenses = Expense::with(['category', 'contact'])
            ->when($request->category_id, fn($q) => $q->where('expense_category_id', $request->category_id))
            ->when($request->from, fn($q) => $q->whereDate('expense_date', '>=', $request->from))
            ->when($request->to, fn($q) => $q->whereDate('expense_date', '<=', $request->to))
            ->latest('expense_date')
            ->paginate(20);

        return response()->json($expenses);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'contact_id'          => 'nullable|exists:contacts,id',
            'reference'           => 'nullable|string|max:100',
            'amount'              => 'required|numeric|min:0',
            'expense_date'        => 'required|date',
            'notes'               => 'nullable|string',
        ]);

        $expense = Expense::create(array_merge($data, ['user_id' => $request->user()->id]));

        return response()->json($expense->load('category'), 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        return response()->json($expense->load(['category', 'contact', 'user']));
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'amount'              => 'sometimes|numeric|min:0',
            'expense_date'        => 'sometimes|date',
            'notes'               => 'nullable|string',
        ]);

        $expense->update($data);
        return response()->json($expense->load('category'));
    }

    public function destroy(Expense $expense): Response
    {
        $expense->delete();
        return response()->noContent();
    }
}
