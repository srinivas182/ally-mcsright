<?php

declare(strict_types=1);

namespace Modules\Crm\Jobs;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactImporter;
use Modules\Crm\Models\ContactImport;
use Throwable;

/**
 * Imports the mapped CSV in the background. Duplicates (same email or phone) are skipped; rows
 * without a name and an email or phone are reported. Consent is never imported.
 */
final class ImportContacts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public readonly int $importId) {}

    public function handle(ManageContacts $contacts, Tags $tags, Timeline $timeline): void
    {
        $import = ContactImport::query()->findOrFail($this->importId);
        $user = User::query()->findOrFail($import->user_id);
        $import->update(['status' => 'running', 'started_at' => CarbonImmutable::now()]);

        $mapping = $import->mapping ?? [];
        $options = $import->options ?? [];
        $counts = ['rows' => 0, 'created' => 0, 'duplicates' => 0, 'errors' => 0];
        $errors = [];

        try {
            foreach (ContactImporter::rows(Storage::disk('local')->path($import->file_path)) as $number => $row) {
                $counts['rows']++;
                $data = ContactImporter::map($row, $mapping);

                try {
                    $contact = $contacts->create([
                        'first_name' => $data['first_name'] ?? '', 'last_name' => $data['last_name'] ?? '',
                        'company_name' => $data['company_name'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                        'source' => $options['source'] ?? 'import',
                        'origin' => $user->isKind(UserKind::Owner, UserKind::Staff) ? ($options['origin'] ?? 'company') : 'personal',
                    ], $user);

                    $contact->forceFill(array_filter([
                        'address_line1' => $data['address_line1'] ?? null, 'city' => $data['city'] ?? null,
                        'state' => isset($data['state']) ? mb_strtoupper(mb_substr($data['state'], 0, 2)) : null,
                        'zip' => isset($data['zip']) ? mb_substr($data['zip'], 0, 10) : null,
                        'birthday' => ContactImporter::date($data['birthday'] ?? null),
                        'import_id' => $import->id,
                    ], fn ($v) => $v !== null && $v !== ''))->save();

                    // History from the old CRM: the note lands on the timeline at the last contact date.
                    $lastContact = ContactImporter::date($data['last_contact'] ?? null);
                    $when = $lastContact !== null && CarbonImmutable::parse($lastContact)->isPast() ? $lastContact.' 12:00:00' : null;
                    if (isset($data['notes'])) {
                        $timeline->log($contact, ['type' => 'note', 'body' => 'Imported note: '.mb_substr($data['notes'], 0, 9000), 'occurred_at' => $when], $user);
                    } elseif ($when !== null) {
                        $contact->newQuery()->whereKey($contact->id)->update(['last_activity_at' => $when]);
                    }

                    foreach (array_filter(array_map('trim', explode(',', ($options['tag'] ?? '').','.($data['tags'] ?? '')))) as $tag) {
                        $tags->attach($contact, $tag);
                    }
                    $counts['created']++;
                } catch (ValidationException $e) {
                    $errors_ = $e->errors();
                    if (isset($errors_['duplicate'])) {
                        $counts['duplicates']++;
                    } else {
                        $counts['errors']++;
                        if (count($errors) < 200) {
                            $errors[] = ['row' => $number, 'reason' => (string) collect($errors_)->flatten()->first()];
                        }
                    }
                }
            }

            $import->update(['status' => 'done', 'rows' => $counts['rows'], 'created_count' => $counts['created'], 'duplicates' => $counts['duplicates'],
                'error_count' => $counts['errors'], 'errors' => $errors, 'finished_at' => CarbonImmutable::now()]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'errors' => [['row' => 0, 'reason' => 'The file could not be read. Save it as CSV (UTF-8) and try again.']], 'finished_at' => CarbonImmutable::now()]);

            throw $e;
        } finally {
            Storage::disk('local')->delete($import->file_path);
        }
    }
}
