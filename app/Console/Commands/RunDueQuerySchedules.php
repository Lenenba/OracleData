<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledQuery;
use App\Models\QuerySchedule;
use App\Services\NextRunCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('schedules:run-due')]
#[Description('Dispatch les requêtes planifiées dont l’échéance est atteinte')]
class RunDueQuerySchedules extends Command
{
    public function handle(NextRunCalculator $calculator): int
    {
        $dispatched = 0;

        QuerySchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->chunkById(100, function (Collection $schedules) use ($calculator, &$dispatched): void {
                /** @var Collection<int, QuerySchedule> $schedules */
                foreach ($schedules as $schedule) {
                    // Advance the due time before dispatch so the next tick does
                    // not re-select this schedule while its job is still queued.
                    $schedule->forceFill([
                        'next_run_at' => $calculator->next($schedule, now()),
                    ])->save();

                    RunScheduledQuery::dispatch($schedule);
                    $dispatched++;
                }
            });

        $this->info("{$dispatched} planification(s) dispatchée(s).");

        return self::SUCCESS;
    }
}
