<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ParentSupportTicketController extends Controller
{
    private const CATEGORIES = [
        'login_access',
        'child_linking',
        'record_visibility',
        'school_fees',
        'platform_payments',
        'backup_restore',
        'ads',
        'purchases',
        'technical_issue',
        'other',
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);

        $parent = $request->user();
        $phone = preg_replace('/\D+/', '', (string) $parent->phone);
        $tickets = DB::table('parent_support_tickets as tickets')
            ->leftJoin('schools', 'schools.id', '=', 'tickets.school_id')
            ->where(function ($query) use ($parent, $phone): void {
                $query->whereIn('tickets.user_id', $this->familyAccountIds($parent));
                if ($parent->email !== null && $phone !== '') {
                    $query->orWhere(function ($publicQuery) use ($parent, $phone): void {
                        $publicQuery->whereNull('tickets.user_id')
                            ->where('tickets.requester_email', strtolower(trim($parent->email)))
                            ->where('tickets.requester_phone', $phone);
                    });
                }
            })
            ->orderByDesc('tickets.id')
            ->limit(50)
            ->get([
                'tickets.reference', 'tickets.school_id', 'schools.name as school_name',
                'tickets.student_public_id', 'tickets.category', 'tickets.subject',
                'tickets.message', 'tickets.status', 'tickets.escalation_target',
                'tickets.response_note', 'tickets.created_at', 'tickets.updated_at',
            ])
            ->map(fn (object $ticket): array => [
                'reference' => $ticket->reference,
                'schoolId' => $ticket->school_id,
                'schoolName' => $ticket->school_name,
                'studentPublicId' => $ticket->student_public_id,
                'category' => $ticket->category,
                'subject' => $ticket->subject,
                'message' => $ticket->message,
                'status' => $ticket->status,
                'escalationTarget' => $ticket->escalation_target,
                'response' => $ticket->response_note,
                'createdAt' => $ticket->created_at,
                'updatedAt' => $ticket->updated_at,
            ])->all();

        return response()->json(['tickets' => $tickets]);
    }

    public function storePublicLoginRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:8', 'max:24'],
            'school' => ['nullable', 'string', 'max:160'],
            'subject' => ['required', 'string', 'min:4', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        $operationId = strtolower($data['operation_id']);
        $reference = 'SUP-'.strtoupper(substr(str_replace('-', '', $operationId), 0, 16));
        $email = strtolower(trim($data['email']));
        $phone = preg_replace('/\D+/', '', $data['phone']);
        abort_unless(strlen($phone) >= 8 && strlen($phone) <= 15, 422, 'Enter a valid phone number.');

        $inserted = DB::table('parent_support_tickets')->insertOrIgnore([
            'reference' => $reference,
            'school_id' => null,
            'user_id' => null,
            'operation_id' => $operationId,
            'requester_name' => trim($data['name']),
            'requester_email' => $email,
            'requester_phone' => $phone,
            'requester_school' => trim((string) ($data['school'] ?? '')) ?: null,
            'student_public_id' => null,
            'category' => 'login_access',
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'status' => 'open',
            'escalation_target' => 'platform',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticket = DB::table('parent_support_tickets')->where('operation_id', $operationId)->first();
        abort_if($ticket === null, 409, 'This support request could not be recorded. Please submit it again.');
        $sameRequest = $ticket->user_id === null
            && $ticket->category === 'login_access'
            && $ticket->requester_name === trim($data['name'])
            && $ticket->requester_email === $email
            && $ticket->requester_phone === $phone
            && $ticket->requester_school === (trim((string) ($data['school'] ?? '')) ?: null)
            && $ticket->subject === trim($data['subject'])
            && $ticket->message === trim($data['message']);
        abort_unless($sameRequest, 409, 'This request identifier was already used for different details.');

        if ($inserted === 1) {
            DB::table('parent_support_ticket_events')->insert([
                'ticket_id' => $ticket->id,
                'actor_user_id' => null,
                'event_type' => 'submitted',
                'to_status' => 'open',
                'escalation_target' => 'platform',
                'created_at' => now(),
            ]);
            DB::table('relay_audit_logs')->insert([
                'school_id' => null,
                'actor_user_id' => null,
                'action' => 'parent_support_ticket_submitted',
                'target_type' => 'parent_support_ticket',
                'target_id' => $reference,
                'details' => json_encode(['category' => 'login_access'], JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'reference' => $ticket->reference,
            'status' => $ticket->status,
            'escalationTarget' => $ticket->escalation_target,
        ], $inserted === 1 ? 201 : 200);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'student_public_id' => ['nullable', 'uuid'],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'subject' => ['required', 'string', 'min:4', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        $user = $request->user();
        $schoolId = $user->school_id;
        $ticketUserId = $user->id;
        abort_if($schoolId === null, 403);

        if ($data['student_public_id'] !== null) {
            $owners = DB::table('relay_parent_links')
                ->whereIn('user_id', $this->familyAccountIds($user))
                ->where('student_public_id', $data['student_public_id'])
                ->limit(2)
                ->get(['school_id', 'user_id']);
            abort_if($owners->isEmpty(), 404, 'This child is not linked to your account.');
            abort_if($owners->count() !== 1, 409, 'This child link is ambiguous. Contact the school office.');
            $schoolId = $owners->first()->school_id;
            $ticketUserId = $owners->first()->user_id;
        }

        $operationId = strtolower($data['operation_id']);
        $schoolCategories = ['child_linking', 'record_visibility', 'school_fees', 'backup_restore'];
        $escalationTarget = in_array($data['category'], $schoolCategories, true) ? 'school' : 'platform';
        $reference = 'SUP-'.strtoupper(substr(str_replace('-', '', $operationId), 0, 16));

        $inserted = DB::table('parent_support_tickets')->insertOrIgnore([
            'reference' => $reference,
            'school_id' => $schoolId,
            'user_id' => $ticketUserId,
            'operation_id' => $operationId,
            'student_public_id' => $data['student_public_id'],
            'requester_name' => $user->name,
            'requester_email' => $user->email,
            'requester_phone' => preg_replace('/\D+/', '', (string) $user->phone),
            'category' => $data['category'],
            'subject' => trim($data['subject']),
            'message' => trim($data['message']),
            'status' => 'open',
            'escalation_target' => $escalationTarget,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticket = DB::table('parent_support_tickets')
            ->where('school_id', $schoolId)
            ->where('operation_id', $operationId)
            ->first();
        abort_if($ticket === null, 409, 'This support request could not be recorded. Please submit it again.');

        $sameRequest = (int) $ticket->user_id === (int) $ticketUserId
            && $ticket->student_public_id === ($data['student_public_id'] ?? null)
            && $ticket->category === $data['category']
            && $ticket->subject === trim($data['subject'])
            && $ticket->message === trim($data['message']);
        abort_unless($sameRequest, 409, 'This request identifier was already used for different details.');

        if ($inserted === 1) {
            DB::table('parent_support_ticket_events')->insert([
                'ticket_id' => $ticket->id,
                'actor_user_id' => $user->id,
                'event_type' => 'submitted',
                'to_status' => 'open',
                'escalation_target' => $escalationTarget,
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'reference' => $ticket->reference,
            'status' => $ticket->status,
            'escalationTarget' => $ticket->escalation_target,
        ], $inserted === 1 ? 201 : 200);
    }

    /** @return list<int> */
    private function familyAccountIds(User $current): array
    {
        if ($current->google_subject === null || $current->email === null || $current->phone === null) {
            return [(int) $current->id];
        }

        return User::query()
            ->where('role', 'parent')
            ->where('is_active', true)
            ->where('email', $current->email)
            ->where('phone', $current->phone)
            ->where('google_subject', $current->google_subject)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
