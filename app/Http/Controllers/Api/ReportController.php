<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function salesSummary(Request $request): JsonResponse
    {
        $from = $request->date('from', 'Y-m-d') ?? now()->startOfMonth();
        $to   = $request->date('to', 'Y-m-d') ?? now()->endOfMonth();

        $summary = Sale::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('
                COUNT(*) as total_sales,
                SUM(total) as revenue,
                SUM(discount) as total_discounts,
                AVG(total) as avg_sale
            ')
            ->first();

        $byDay = Sale::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as sales, SUM(total) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'period'  => ['from' => $from, 'to' => $to],
            'summary' => $summary,
            'by_day'  => $byDay,
        ]);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 10);
        $from  = $request->date('from') ?? now()->startOfMonth();
        $to    = $request->date('to') ?? now()->endOfMonth();

        $products = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereBetween('sales.created_at', [$from, $to])
            ->where('sales.status', '!=', 'cancelled')
            ->selectRaw('
                products.id,
                products.name,
                products.sku,
                SUM(sale_items.quantity) as units_sold,
                SUM(sale_items.subtotal) as revenue
            ')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $products]);
    }

    public function stockAlerts(): JsonResponse
    {
        $products = Product::whereColumn('stock', '<=', 'stock_min')
            ->where('active', true)
            ->select('id', 'name', 'sku', 'stock', 'stock_min', 'unit')
            ->orderBy('stock')
            ->get();

        return response()->json(['data' => $products, 'total' => $products->count()]);
    }

    public function customerStats(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 10);

        $top = Customer::withCount('sales')
            ->withSum('sales', 'total')
            ->orderByDesc('sales_sum_total')
            ->limit($limit)
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $top]);
    }
}
