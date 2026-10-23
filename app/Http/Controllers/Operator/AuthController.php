<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        if (Auth::check() && Auth::user()?->role === 'platform_admin') {
            return redirect()->route('operator.schools.index');
        }

        return view('operator.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        if (! Auth::attempt(['email' => strtolower($data['email']), 'password' => $data['password'], 'role' => 'platform_admin', 'school_id' => null])) {
            return back()->withErrors(['email' => 'These operator credentials were not accepted.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('operator.schools.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('operator.login');
    }

    public function bootstrapForm(): View|RedirectResponse
    {
        if (DB::table('platform_bootstrap_state')->where('id', 1)->value('consumed_at') !== null
            || empty(config('school_erp.platform_bootstrap_token'))) {
            abort(404);
        }

        return view('operator.bootstrap');
    }

    public function bootstrap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bootstrap_token' => ['required', 'string', 'max:256'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:16', 'max:128'],
        ]);
        $expected = (string) config('school_erp.platform_bootstrap_token');
        if ($expected === '' || ! hash_equals($expected, $data['bootstrap_token'])) {
            return back()->withErrors(['bootstrap_token' => 'The one-time setup token was not accepted.'])->onlyInput('email');
        }

        $created = DB::transaction(function () use ($data): bool {
            $state = DB::table('platform_bootstrap_state')->where('id', 1)->lockForUpdate()->first();
            if ($state === null || $state->consumed_at !== null || User::query()->where('role', 'platform_admin')->exists()) {
                return false;
            }
            User::query()->create([
                'school_id' => null,
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => 'platform_admin',
                'is_active' => true,
            ]);
            DB::table('platform_bootstrap_state')->where('id', 1)->update(['consumed_at' => now()]);

            return true;
        });

        if (! $created) {
            abort(404);
        }

        return redirect()->route('operator.login')->with('status', 'Operator account created. Remove the bootstrap token from the server environment before signing in.');
    }
}
