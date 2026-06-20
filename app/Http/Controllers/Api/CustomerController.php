<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            Customer::query()->filter(request())->paginate(20)
        );
    }

    public function store(Request $request): CustomerResource
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:customers',
            'phone'    => 'nullable|string|max:30',
            'document' => 'nullable|string|max:30|unique:customers',
            'address'  => 'nullable|string',
        ]);

        return new CustomerResource(Customer::create($data));
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load('sales'));
    }

    public function update(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => 'sometimes|email|unique:customers,email,' . $customer->id,
            'phone'    => 'nullable|string|max:30',
            'document' => 'nullable|string|max:30|unique:customers,document,' . $customer->id,
            'address'  => 'nullable|string',
            'active'   => 'sometimes|boolean',
        ]);

        $customer->update($data);
        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer): Response
    {
        $customer->delete();
        return response()->noContent();
    }
}
