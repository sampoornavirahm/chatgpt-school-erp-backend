<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\SchoolLicenseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('license:backfill-trials')]
#[Description('Issue signed Starter trials to legacy trial schools without a current license.')]
class BackfillTrialLicenses extends Command
{
    public function handle(SchoolLicenseService $licenses): int
    {
        $backfilled = 0;
        $schools = School::query()->where('license_status', 'trial')->whereNull('current_license_id')->orderBy('id')->get();

        foreach ($schools as $school) {
            $issued = DB::transaction(function () use ($school, $licenses): bool {
                $lockedSchool = School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
                if ($lockedSchool->license_status !== 'trial' || $lockedSchool->current_license_id !== null) {
                    return false;
                }

                $license = $licenses->startTrial($lockedSchool);
                DB::table('relay_audit_logs')->insert([
                    'school_id' => $lockedSchool->id,
                    'actor_user_id' => null,
                    'action' => 'legacy_trial_license_backfilled',
                    'target_type' => 'license',
                    'target_id' => $license->id,
                    'details' => json_encode(['plan' => 'starter'], JSON_THROW_ON_ERROR),
                    'ip_address' => null,
                    'created_at' => now(),
                ]);

                return true;
            });
            $backfilled += (int) $issued;
        }

        $this->info("Issued {$backfilled} signed Starter trial license(s).");

        return self::SUCCESS;
    }
}
