<?php

namespace App\Console\Commands;

use App\Models\AutomationCampaign;
use App\Models\AutomationStep;
use App\Services\AutomationStepRunner;
use Illuminate\Console\Command;

/**
 * Fires any automation step whose scheduled_for has arrived. Runs every
 * minute (routes/console.php, withoutOverlapping). Each step generates its
 * content and hands off to the existing publish pipeline. When a campaign's
 * last step has run, the campaign is marked completed.
 */
class RunDueAutomationSteps extends Command
{
    protected $signature = 'automation:run-due {--limit=10 : Max steps to run this tick}';
    protected $description = 'Generate and publish due automation-campaign steps';

    public function handle(AutomationStepRunner $runner): int
    {
        $due = AutomationStep::where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->whereHas('campaign', fn ($q) => $q->where('status', 'active'))
            ->orderBy('scheduled_for')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $step) {
            $this->info("Running step {$step->id} ({$step->content_type}) for campaign {$step->automation_campaign_id}");
            $runner->run($step);
        }

        // Mark campaigns whose steps are all past pending as completed.
        $activeIds = $due->pluck('automation_campaign_id')->unique();
        foreach ($activeIds as $id) {
            $campaign = AutomationCampaign::find($id);
            if ($campaign && ! $campaign->steps()->where('status', 'pending')->exists()) {
                $campaign->update(['status' => 'completed']);
            }
        }

        $this->info("Processed {$due->count()} step(s).");
        return self::SUCCESS;
    }
}
