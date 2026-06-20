<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    public function show(Request $request): BusinessResource
    {
        return new BusinessResource(
            $request->user()->business->load(['locations', 'activeSubscription.plan'])
        );
    }

    public function update(Request $request): BusinessResource
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name'                       => 'sometimes|string|max:255',
            'phone'                      => 'nullable|string|max:30',
            'currency'                   => 'sometimes|string|max:10',
            'timezone'                   => 'sometimes|string',
            'date_format'                => 'sometimes|string',
            'cuit'                       => 'nullable|string|max:20|unique:businesses,cuit,' . $business->id,
            'razon_social'               => 'nullable|string|max:255',
            'condicion_iva'              => 'nullable|string|max:50',
            'afip_punto_venta'           => 'nullable|string|max:10',
            'afip_produccion'            => 'nullable|boolean',
            'financial_year_start_month' => 'nullable|string|size:2',
        ]);

        $business->update($data);

        return new BusinessResource($business->load(['locations', 'activeSubscription.plan']));
    }
}
