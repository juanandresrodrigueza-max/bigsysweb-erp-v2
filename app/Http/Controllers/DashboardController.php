<?php
namespace App\Http\Controllers;
use App\Models\{Contact, Sale, Product, Purchase};
use Inertia\Inertia;
use Illuminate\Support\Carbon;
class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            "ventas_hoy"    => Sale::whereDate("created_at", Carbon::today())->sum("total") ?? 0,
            "compras_mes"   => Purchase::where("created_at",">=",Carbon::now()->startOfMonth())->sum("total") ?? 0,
            "clientes"      => Contact::where("type","customer")->count(),
            "alertas_stock" => Product::whereColumn("stock","<=","stock_alert_quantity")->count(),
        ];
        return Inertia::render("Dashboard", ["stats" => $stats]);
    }
}