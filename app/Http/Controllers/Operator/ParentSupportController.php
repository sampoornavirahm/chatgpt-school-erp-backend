<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ParentSupportController extends Controller
{
    public function index(): View
    {
        $tickets = DB::table('parent_support_tickets as tickets')
            ->leftJoin('schools', 'schools.id', '=', 'tickets.school_id')
            ->leftJoin('users as parents', 'parents.id', '=', 'tickets.user_id')
            ->leftJoin('users as assignees', 'assignees.id', '=', 'tickets.assigned_to')
            ->orderByRaw("CASE tickets.status WHEN 'open' THEN 0 WHEN 'escalated' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'waiting_on_parent' THEN 3 ELSE 4 END")
            ->orderByDesc('tickets.updated_at')
            ->limit(200)
            ->get([
                'tickets.*', 'schools.name as school_name', 'parents.name as parent_name',
                'parents.email as parent_email', 'parents.phone as parent_phone',
                'tickets.requester_name', 'tickets.requester_email', 'tickets.requester_phone',
                'tickets.requester_school',
                'assignees.name as assignee_name',
            ]);

        return view('operator.support', compact('tickets'));
    }

    public function update(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,waiting_on_parent,escalated,resolved,closed'],
            'escalation_target' => ['required', 'in:school,platform'],
            'response_note' => ['nullable', 'string', 'max:3000'],
        ]);

        abort_unless(DB::table('parent_support_tickets')->where('id', $ticket)->exists(), 404);
        DB::transaction(function () use ($request, $ticket, $data): void {
            $current = DB::table('parent_support_tickets')->where('id', $ticket)->lockForUpdate()->first();
            abort_if($current === null, 404);
            $newNote = trim((string) ($data['response_note'] ?? ''));
            $noteChanged = $newNote !== (string) ($current->response_note ?? '');
            $changed = $current->status !== $data['status']
                || $current->escalation_target !== $data['escalation_target']
                || $noteChanged;
            if (! $changed) {
                return;
            }

            DB::table('parent_support_tickets')->where('id', $ticket)->update([
                'status' => $data['status'],
                'escalation_target' => $data['escalation_target'],
                'response_note' => $newNote === '' ? null : $newNote,
                'assigned_to' => $request->user()->id,
                'responded_at' => $noteChanged ? now() : $current->responded_at,
                'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true)
                    ? ($current->resolved_at ?? now())
                    : null,
                'updated_at' => now(),
            ]);

            DB::table('parent_support_ticket_events')->insert([
                'ticket_id' => $ticket,
                'actor_user_id' => $request->user()->id,
                'event_type' => 'operator_update',
                'from_status' => $current->status,
                'to_status' => $data['status'],
                'escalation_target' => $data['escalation_target'],
                'note' => $newNote === '' ? null : $newNote,
                'created_at' => now(),
            ]);

            DB::table('relay_audit_logs')->insert([
                'school_id' => $current->school_id,
                'actor_user_id' => $request->user()->id,
                'action' => 'parent_support_ticket_updated',
                'target_type' => 'parent_support_ticket',
                'target_id' => $current->reference,
                'details' => json_encode([
                    'fromStatus' => $current->status,
                    'toStatus' => $data['status'],
                    'escalationTarget' => $data['escalation_target'],
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Support ticket updated. The parent will see the status and response after refreshing the app.');
    }
}
