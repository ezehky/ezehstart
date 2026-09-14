<?php

namespace App\Console\Commands;

use App\Enums\StatusEmailCampaign;
use App\Models\EmailCampaign;
use App\Services\EmailCampaignService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class SendEmailCampaignsCommand extends Command
{
    protected $signature = 'email:send-campaigns
                            {--campaign=* : Start sending these campaign ids, ignoring the scheduled date}
                            {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Start campaigns whose scheduled date has arrived, and work through every campaign already sending';

    public function handle(EmailCampaignService $campaigns): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $started = $this->dueCampaigns()->each(function (EmailCampaign $campaign) use ($campaigns, $dryRun) {
            if ($dryRun) {
                $this->line(sprintf('  <fg=yellow>would start</> · %s', $campaign->name));

                return;
            }

            try {
                $campaigns->startSending($campaign);

                $this->line(sprintf('  <fg=green>started</> · %s', $campaign->name));
            } catch (\Throwable $exception) {
                Log::channel('ezeh')->error('Scheduled campaign failed to start', [
                    'email_campaign_id' => $campaign->id,
                    'message' => $exception->getMessage(),
                ]);

                $this->line(sprintf('  <fg=red>failed</> · %s · %s', $campaign->name, $exception->getMessage()));
            }
        })->count();

        if ($dryRun) {
            $this->info("Would start {$started} campaign(s).");

            return self::SUCCESS;
        }

        $sendingCount = EmailCampaign::query()->where('status', StatusEmailCampaign::SENDING)->count();

        try {
            // One call works through every SENDING campaign's next chunk — see
            // EmailCampaignService::processDueBatch() — so this runs once per tick,
            // not once per campaign.
            $campaigns->processDueBatch();
        } catch (\Throwable $exception) {
            Log::channel('ezeh')->error('Campaign batch failed to process', ['message' => $exception->getMessage()]);

            $this->line(sprintf('  <fg=red>failed</> · %s', $exception->getMessage()));
        }

        $this->info(sprintf('Started %d campaign(s), processed %d campaign(s) sending.', $started, $sendingCount));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, EmailCampaign>
     */
    protected function dueCampaigns(): Collection
    {
        $ids = (array) $this->option('campaign');

        return EmailCampaign::query()
            ->when(
                $ids,
                fn ($query) => $query->where('status', StatusEmailCampaign::SCHEDULED)->whereIn('id', $ids),
                fn ($query) => $query->dueForSending(),
            )
            ->get();
    }
}
