<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmActivity;
use Illuminate\Http\Request;

class CrmLeadController extends Controller
{
    public function index(Request $request)
    {
        $leads = CrmLead::with(['contact','assignedUser'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->assigned_to, fn($q) => $q->where('assigned_to', $request->assigned_to))
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(25);

        return response()->json($leads);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'contact_id' => 'nullable|exists:contacts,id',
            'assigned_to' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'status' => 'in:new,contacted,qualified,proposal,negotiation,won,lost',
            'source' => 'nullable|string',
            'value' => 'numeric|min:0',
            'probability' => 'integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $lead = CrmLead::create($data);
        return response()->json($lead->load(['contact','assignedUser']), 201);
    }

    public function show(CrmLead $crmLead)
    {
        return response()->json($crmLead->load(['contact','assignedUser','activities.user']));
    }

    public function update(Request $request, CrmLead $crmLead)
    {
        $data = $request->validate([
            'contact_id' => 'nullable|exists:contacts,id',
            'assigned_to' => 'nullable|exists:users,id',
            'title' => 'string|max:255',
            'status' => 'in:new,contacted,qualified,proposal,negotiation,won,lost',
            'source' => 'nullable|string',
            'value' => 'numeric|min:0',
            'probability' => 'integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'lost_reason' => 'nullable|string',
        ]);

        $crmLead->update($data);
        return response()->json($crmLead->fresh(['contact','assignedUser']));
    }

    public function destroy(CrmLead $crmLead)
    {
        $crmLead->delete();
        return response()->json(null, 204);
    }

    public function addActivity(Request $request, CrmLead $crmLead)
    {
        $data = $request->validate([
            'type' => 'required|in:call,email,meeting,note,task,whatsapp',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $activity = $crmLead->activities()->create([
            ...$data,
            'business_id' => auth()->user()->business_id,
            'user_id' => auth()->id(),
        ]);

        return response()->json($activity, 201);
    }

    public function completeActivity(CrmActivity $crmActivity)
    {
        $crmActivity->update(['completed_at' => now()]);
        return response()->json($crmActivity);
    }

    public function pipeline()
    {
        $stages = ['new','contacted','qualified','proposal','negotiation','won','lost'];
        $data = [];
        foreach ($stages as $stage) {
            $leads = CrmLead::where('status', $stage)->with('contact')->get();
            $data[$stage] = [
                'count' => $leads->count(),
                'total_value' => $leads->sum('value'),
                'leads' => $leads,
            ];
        }
        return response()->json($data);
    }
}
