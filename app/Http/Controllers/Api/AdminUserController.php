<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LicenseTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function store(Request $request, LicenseTokenService $tokens): JsonResponse
    {
        abort_unless($request->user()->role === 'school_admin', 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9 .()-]{7,20}$/'],
            'password' => ['nullable', 'string', 'min:12', 'max:128'],
            'role' => ['required', 'in:teacher,parent'],
        ]);

        if ($data['role'] === 'teacher') {
            $school = $request->user()->school;
            $license = $school?->currentLicense;
            $teacherLimit = $school?->active_teacher_limit;
            if ($license !== null) {
                $verification = $tokens->verify($license->signed_token);
                $claims = $verification['claims'] ?? [];
                if (! ($verification['valid'] ?? false) || in_array($license->status, ['revoked', 'superseded'], true)) {
                    $teacherLimit = 0;
                } elseif (array_key_exists('teachers', $claims['limits'] ?? [])) {
                    $teacherLimit = $claims['limits']['teachers'];
                }
            }
            $activeTeacherCount = User::query()->where('school_id', $request->user()->school_id)
                ->where('role', 'teacher')->where('is_active', true)->count();
            if ($teacherLimit !== null && $activeTeacherCount >= $teacherLimit) {
                throw ValidationException::withMessages([
                    'role' => ['The active teacher limit for this license has been reached.'],
                ]);
            }
        }

        $user = User::query()->create([
            'school_id' => $request->user()->school_id,
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => preg_replace('/\\D+/', '', $data['phone']),
            'password' => $data['password'] ?? Str::random(64),
            'role' => $data['role'],
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ], 201);
    }
}
