<?php

namespace App\Console\Commands;

use App\Actions\Bonuses\PublishBonusCampaignTransitions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bonuses:advance-campaigns')]
#[Description('Publish cache changes for due bonus campaign transitions')]
class AdvanceBonusCampaigns extends Command
{
    public function handle(PublishBonusCampaignTransitions $transitions): int
    {
        $this->info('Transitions published: '.$transitions->handle());

        return self::SUCCESS;
    }
}
