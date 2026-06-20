<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contacts = Contact::query()
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('document', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->paginate(20);

        return response()->json($contacts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'          => 'required|in:customer,supplier,both',
            'name'          => 'required|string|max:255',
            'email'         => 'nullable|email',
            'phone'         => 'nullable|string|max:30',
            'mobile'        => 'nullable|string|max:30',
            'document_type' => 'nullable|string|max:20',
            'document'      => 'nullable|string|max:30',
            'cuit'          => 'nullable|string|max:20',
            'condicion_iva' => 'nullable|string|max:50',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string|max:100',
            'province'      => 'nullable|string|max:100',
            'postal_code'   => 'nullable|string|max:20',
            'credit_limit'  => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string',
        ]);

        $contact = Contact::create($data);

        return response()->json($contact, 201);
    }

    public function show(Contact $contact): JsonResponse
    {
        return response()->json($contact);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'email'         => 'nullable|email',
            'phone'         => 'nullable|string|max:30',
            'cuit'          => 'nullable|string|max:20',
            'condicion_iva' => 'nullable|string|max:50',
            'address'       => 'nullable|string',
            'credit_limit'  => 'nullable|numeric|min:0',
            'is_active'     => 'sometimes|boolean',
        ]);

        $contact->update($data);

        return response()->json($contact);
    }

    public function destroy(Contact $contact): Response
    {
        $contact->delete();
        return response()->noContent();
    }
}
