<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Booking::with(['contact','service','assignedUser'])
                ->when($request->date, fn($q) => $q->forDate($request->date))
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->when($request->assigned_to, fn($q) => $q->where('assigned_to', $request->assigned_to))
                ->orderBy('starts_at')
                ->paginate(50)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => 'nullable|exists:contacts,id',
            'service_id' => 'nullable|exists:products,id',
            'assigned_to' => 'nullable|exists:users,id',
            'location_id' => 'nullable|exists:business_locations,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'status' => 'in:pending,confirmed,cancelled,completed,no_show',
            'price' => 'numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $booking = Booking::create($data);
        return response()->json($booking->load(['contact','service','assignedUser']), 201);
    }

    public function show(Booking $booking)
    {
        return response()->json($booking->load(['contact','service','assignedUser']));
    }

    public function update(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'contact_id' => 'nullable|exists:contacts,id',
            'service_id' => 'nullable|exists:products,id',
            'assigned_to' => 'nullable|exists:users,id',
            'starts_at' => 'date',
            'ends_at' => 'date|after:starts_at',
            'status' => 'in:pending,confirmed,cancelled,completed,no_show',
            'price' => 'numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $booking->update($data);
        return response()->json($booking->fresh(['contact','service','assignedUser']));
    }

    public function destroy(Booking $booking)
    {
        $booking->delete();
        return response()->json(null, 204);
    }

    public function calendar(Request $request)
    {
        $start = $request->input('start', now()->startOfMonth());
        $end   = $request->input('end', now()->endOfMonth());

        $bookings = Booking::with(['contact','service','assignedUser'])
            ->whereBetween('starts_at', [$start, $end])
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('starts_at')
            ->get();

        return response()->json($bookings);
    }
}
