<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AppUpdateController extends Controller
{
    public function index(): View
    {
        return view('operator.app-updates', ['policies' => DB::table('app_update_policies')->orderBy('app')->orderBy('platform')->get()]);
    }

    public function update(Request $request, int $policy): RedirectResponse
    {
        $data = $request->validate([
            'latest_version' => ['required', 'regex:/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/', 'max:32'],
            'minimum_version' => ['required', 'regex:/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/', 'max:32'],
            'download_url' => ['required_if:enabled,1', 'nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        if (version_compare($data['minimum_version'], $data['latest_version'], '>')) {
            return back()->withErrors(['minimum_version' => 'The minimum version cannot be newer than the latest version.']);
        }
        DB::table('app_update_policies')->where('id', $policy)->update([
            'latest_version' => $data['latest_version'],
            'minimum_version' => $data['minimum_version'],
            'download_url' => $data['download_url'] ?? '',
            'release_notes' => $data['release_notes'] ?? null,
            'enabled' => $request->boolean('enabled'),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Update policy saved. Apps will show the configured update prompt at their next check.');
    }
}
