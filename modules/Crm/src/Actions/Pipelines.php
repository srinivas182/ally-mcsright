<?php

declare(strict_types=1);

namespace Modules\Crm\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Crm\CrmAccess;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Events\ContactStageChanged;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\Stage;

/**
 * Buyer and seller pipelines (created automatically with sensible stages), moving contacts
 * between stages, and stage management by owners and staff.
 */
final class Pipelines
{
    private const DEFAULTS = [
        'buyer' => ['Buyers', ['New', 'Nurture', 'Actively searching', 'Showing', 'Offer made', 'Under contract', ['Closed', 'won'], ['Lost', 'lost']]],
        'seller' => ['Sellers', ['New', 'Appointment set', 'Listing signed', 'Active listing', 'Under contract', ['Closed', 'won'], ['Lost', 'lost']]],
    ];

    public function __construct(private readonly Timeline $timeline) {}

    /**
     * The brokerage's pipelines, creating the default buyer and seller pipelines the first time.
     *
     * @return Collection<int, Pipeline>
     */
    public function all(): Collection
    {
        if (! Pipeline::query()->exists()) {
            DB::transaction(function (): void {
                $sort = 0;
                foreach (self::DEFAULTS as $kind => [$name, $stages]) {
                    $pipeline = Pipeline::query()->create(['name' => $name, 'kind' => $kind, 'sort' => $sort++]);
                    foreach ($stages as $i => $stage) {
                        [$stageName, $outcome] = is_array($stage) ? $stage : [$stage, null];
                        Stage::query()->create(['pipeline_id' => $pipeline->id, 'name' => $stageName, 'outcome' => $outcome, 'sort' => $i]);
                    }
                }
            });
        }

        return Pipeline::query()->with('stages')->orderBy('sort')->get();
    }

    public function move(Contact $contact, Stage $stage, User $by): Contact
    {
        if ($contact->stage_id === $stage->id) {
            return $contact;
        }

        $pipeline = Pipeline::query()->findOrFail($stage->pipeline_id);
        $changes = ['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'stage_changed_at' => CarbonImmutable::now()];
        if ($stage->outcome === 'won') {
            $changes['lifecycle'] = Lifecycle::PastClient;
            $changes['closed_on'] = $contact->closed_on ?? CarbonImmutable::today();
            $changes['rhythm'] = $contact->rhythm ?? 'A';
        } elseif ($stage->outcome === null && $contact->lifecycle === Lifecycle::Lead && $stage->sort >= 2) {
            $changes['lifecycle'] = Lifecycle::Active;
        }

        $contact->update($changes);
        $this->timeline->system($contact, "Moved to {$pipeline->name}: {$stage->name}", $by->id);
        ContactStageChanged::dispatch($contact->tenant_id, $contact->id, $stage->id, $by->id);

        return $contact;
    }

    public function addStage(Pipeline $pipeline, string $name, User $by): Stage
    {
        $this->guardManager($by);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => 'Give the stage a short name.']);
        }

        // New stages go before the closed/lost stages.
        $firstOutcome = Stage::query()->where('pipeline_id', $pipeline->id)->whereNotNull('outcome')->min('sort');
        $sort = $firstOutcome !== null ? (int) $firstOutcome : (int) Stage::query()->where('pipeline_id', $pipeline->id)->max('sort') + 1;
        Stage::query()->where('pipeline_id', $pipeline->id)->where('sort', '>=', $sort)->increment('sort');

        return Stage::query()->create(['pipeline_id' => $pipeline->id, 'name' => $name, 'sort' => $sort]);
    }

    public function rename(Stage $stage, string $name, User $by): Stage
    {
        $this->guardManager($by);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => 'Give the stage a short name.']);
        }
        $stage->update(['name' => $name]);

        return $stage;
    }

    /**
     * @param  list<string>  $stageUlids  in the new order
     */
    public function reorder(Pipeline $pipeline, array $stageUlids, User $by): void
    {
        $this->guardManager($by);
        $stages = Stage::query()->where('pipeline_id', $pipeline->id)->get()->keyBy('ulid');
        if (count($stageUlids) !== $stages->count() || array_diff($stageUlids, $stages->keys()->all()) !== []) {
            throw ValidationException::withMessages(['stages' => 'Include every stage of this pipeline once.']);
        }

        DB::transaction(function () use ($stageUlids, $stages): void {
            foreach ($stageUlids as $i => $ulid) {
                $stages->get($ulid)?->update(['sort' => $i]);
            }
        });
    }

    /**
     * Deletes a stage, moving its contacts to another stage of the same pipeline first.
     */
    public function delete(Stage $stage, Stage $moveTo, User $by): void
    {
        $this->guardManager($by);
        if ($moveTo->pipeline_id !== $stage->pipeline_id || $moveTo->is($stage)) {
            throw ValidationException::withMessages(['move_to' => 'Choose another stage in the same pipeline for its contacts.']);
        }
        if ($stage->outcome !== null) {
            throw ValidationException::withMessages(['stage' => 'Closed and Lost stages cannot be deleted.']);
        }

        DB::transaction(function () use ($stage, $moveTo): void {
            Contact::query()->where('stage_id', $stage->id)->update(['stage_id' => $moveTo->id, 'stage_changed_at' => CarbonImmutable::now()]);
            $stage->delete();
        });
    }

    private function guardManager(User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['pipeline' => 'Only owners and staff can change pipelines.']);
        }
    }
}
