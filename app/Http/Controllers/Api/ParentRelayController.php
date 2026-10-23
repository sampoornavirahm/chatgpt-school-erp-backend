<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LicenseTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParentRelayController extends Controller
{
    public function __construct(private LicenseTokenService $licenseTokens) {}

    public function children(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $children = DB::table('relay_parent_links as links')
            ->join('relay_snapshots as snapshots', function ($join): void {
                $join->on('snapshots.school_id', '=', 'links.school_id')
                    ->on('snapshots.student_public_id', '=', 'links.student_public_id');
            })
            ->where('links.school_id', $request->user()->school_id)
            ->where('links.user_id', $request->user()->id)
            ->where('snapshots.is_deleted', false)
            ->orderBy('links.student_public_id')
            ->get(['links.student_public_id', 'snapshots.revision', 'snapshots.payload', 'snapshots.projected_at'])
            ->map(fn (object $row): array => [
                'studentPublicId' => $row->student_public_id,
                'revision' => (int) $row->revision,
                'snapshot' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
                'projectedAt' => $row->projected_at,
            ])->all();

        return response()->json([
            'children' => $children,
            'parentFeatures' => $this->parentFeatures($request),
            'syncedAt' => now()->toISOString(),
        ]);
    }

    /** @return array{courses: bool, transport: bool, ai: bool} */
    private function parentFeatures(Request $request): array
    {
        $school = $request->user()->school;
        $license = $school?->currentLicense;
        $verification = $license === null
            ? ['valid' => false]
            : $this->licenseTokens->verify($license->signed_token);
        $claims = $verification['claims'] ?? [];
        $flags = is_array($claims['feature_flags'] ?? null) ? $claims['feature_flags'] : [];
        $enabled = ($verification['valid'] ?? false)
            && ($verification['state'] ?? null) === 'active'
            && in_array($license?->status, ['active', 'trial'], true)
            && ! (bool) $school?->service_on_hold;

        return [
            'courses' => $enabled && in_array('parent.courses', $flags, true),
            'transport' => $enabled && in_array('parent.transport', $flags, true),
            'ai' => $enabled && in_array('parent.ai', $flags, true),
        ];
    }

    public function showChild(Request $request, string $student): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $row = DB::table('relay_parent_links as links')
            ->join('relay_snapshots as snapshots', function ($join): void {
                $join->on('snapshots.school_id', '=', 'links.school_id')
                    ->on('snapshots.student_public_id', '=', 'links.student_public_id');
            })
            ->where('links.school_id', $request->user()->school_id)
            ->where('links.user_id', $request->user()->id)
            ->where('links.student_public_id', $student)
            ->where('snapshots.is_deleted', false)
            ->first(['snapshots.revision', 'snapshots.payload', 'snapshots.projected_at']);
        abort_if($row === null, 404);

        return response()->json([
            'studentPublicId' => $student,
            'revision' => (int) $row->revision,
            'snapshot' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
            'projectedAt' => $row->projected_at,
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'student_public_id' => ['required', 'uuid'],
            'operation' => ['required', 'in:parent_request.submit'],
            'payload.request_type' => ['required', 'in:leave,profile_change,document,other'],
            'payload.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'payload.ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:payload.starts_on'],
            'payload.message' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $user = $request->user();
        $linked = DB::table('relay_parent_links')->where('school_id', $user->school_id)
            ->where('user_id', $user->id)->where('student_public_id', $data['student_public_id'])->exists();
        abort_unless($linked, 404);

        $command = DB::table('relay_parent_commands')->where('school_id', $user->school_id)
            ->where('operation_id', strtolower($data['operation_id']))->first();
        if ($command !== null) {
            abort_unless((int) $command->user_id === (int) $user->id, 409);

            return response()->json(['operationId' => $command->operation_id, 'status' => $command->status], 200);
        }

        DB::table('relay_parent_commands')->insert([
            'school_id' => $user->school_id,
            'operation_id' => strtolower($data['operation_id']),
            'user_id' => $user->id,
            'student_public_id' => $data['student_public_id'],
            'operation' => $data['operation'],
            'payload' => json_encode($data['payload'], JSON_THROW_ON_ERROR),
            'status' => 'queued',
            'created_at' => now(),
        ]);

        return response()->json(['operationId' => strtolower($data['operation_id']), 'status' => 'queued'], 202);
    }

    public function commands(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $commands = DB::table('relay_parent_commands')->where('school_id', $request->user()->school_id)
            ->where('user_id', $request->user()->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn (object $command): array => [
                'operationId' => $command->operation_id,
                'studentPublicId' => $command->student_public_id,
                'status' => $command->status,
                'message' => $command->result_message,
                'createdAt' => $command->created_at,
                'completedAt' => $command->completed_at,
            ])->all();

        return response()->json(['commands' => $commands]);
    }
}
