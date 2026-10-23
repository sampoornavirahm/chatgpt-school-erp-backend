<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppUpdateController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app' => ['required', 'in:desktop,parent,teacher'],
            'platform' => ['required', 'in:windows,macos,android,ios'],
            'version' => ['required', 'regex:/^\\d+\\.\\d+\\.\\d+(?:[-+][A-Za-z0-9.-]+)?$/', 'max:32'],
        ]);
        $policy = DB::table('app_update_policies')->where('app', $data['app'])->where('platform', $data['platform'])->first();
        if ($policy === null || ! $policy->enabled || $policy->download_url === '') {
            return response()->json(['updateAvailable' => false, 'required' => false]);
        }
        $required = version_compare($data['version'], $policy->minimum_version, '<');
        $available = version_compare($data['version'], $policy->latest_version, '<');

        return response()->json([
            'updateAvailable' => $available,
            'required' => $required,
            'latestVersion' => $policy->latest_version,
            'minimumVersion' => $policy->minimum_version,
            'downloadUrl' => $policy->download_url,
            'releaseNotes' => $policy->release_notes,
        ]);
    }
}
