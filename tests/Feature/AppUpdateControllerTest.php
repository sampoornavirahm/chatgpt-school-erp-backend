<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppUpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_release_policy_does_not_prompt_for_updates(): void
    {
        DB::table('app_update_policies')->where('app', 'parent')->where('platform', 'android')->update([
            'latest_version' => '1.2.0',
            'minimum_version' => '1.0.0',
            'download_url' => 'https://downloads.example.test/parents.apk',
        ]);

        $this->getJson('/api/v1/app-updates?app=parent&platform=android&version=0.1.0')
            ->assertOk()->assertJsonPath('updateAvailable', false)->assertJsonPath('required', false);
    }

    public function test_operator_can_make_a_release_optional_or_mandatory_for_an_app_platform(): void
    {
        DB::table('app_update_policies')->where('app', 'parent')->where('platform', 'android')->update([
            'latest_version' => '1.2.0',
            'minimum_version' => '1.0.0',
            'download_url' => 'https://downloads.example.test/parents.apk',
            'release_notes' => 'Security and reliability improvements.',
            'enabled' => true,
        ]);

        $this->getJson('/api/v1/app-updates?app=parent&platform=android&version=0.9.0')
            ->assertOk()->assertJsonPath('updateAvailable', true)->assertJsonPath('required', true)
            ->assertJsonPath('latestVersion', '1.2.0');
        $this->getJson('/api/v1/app-updates?app=parent&platform=android&version=1.0.0')
            ->assertOk()->assertJsonPath('updateAvailable', true)->assertJsonPath('required', false);
        $this->getJson('/api/v1/app-updates?app=parent&platform=android&version=1.2.0')
            ->assertOk()->assertJsonPath('updateAvailable', false);
    }
}
