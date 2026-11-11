<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Crm\Actions\MergeContacts;
use Modules\Crm\Consent;
use Modules\Crm\ContactImporter;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Source;
use Modules\Crm\Jobs\ImportContacts;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactImport;

/**
 * CSV import, duplicates and merge, and consent recording.
 */
final class DataController
{
    public function __construct(private readonly ContactVisibility $visibility) {}

    public function upload(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $path = $file->store('crm-imports', 'local');
        abort_if(! is_string($path), 422);
        $headers = ContactImporter::headers(Storage::disk('local')->path($path));
        if ($headers === []) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => 'That file has no header row. Save it as CSV with column names in the first row.']);
        }

        $import = ContactImport::query()->create([
            'user_id' => $user->id, 'file_path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'status' => 'uploaded', 'headers' => $headers,
        ]);

        return to_route('crm.imports.show', $import);
    }

    public function show(Request $request, ContactImport $import): Response
    {
        $user = self::user($request);
        abort_unless($import->user_id === $user->id, 404);

        $preview = [];
        if ($import->status === 'uploaded') {
            foreach (ContactImporter::rows(Storage::disk('local')->path($import->file_path)) as $row) {
                $preview[] = $row;
                if (count($preview) === 5) {
                    break;
                }
            }
        }

        return Inertia::render('crm/contacts/import', [
            'import' => [
                'ulid' => $import->ulid, 'name' => $import->original_name, 'status' => $import->status, 'headers' => $import->headers,
                'rows' => $import->rows, 'created' => $import->created_count, 'duplicates' => $import->duplicates,
                'error_count' => $import->error_count, 'errors' => $import->errors ?? [],
            ],
            'guess' => ContactImporter::guess($import->headers),
            'preview' => $preview,
            'fields' => ContactImporter::FIELDS,
            'sources' => array_map(fn (Source $s): string => $s->value, Source::cases()),
            'isManager' => $user->isKind(UserKind::Owner, UserKind::Staff),
        ]);
    }

    public function run(Request $request, ContactImport $import): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($import->user_id === $user->id && $import->status === 'uploaded', 404);
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', Rule::in(ContactImporter::FIELDS)],
            'source' => ['nullable', Rule::enum(Source::class)],
            'tag' => ['nullable', 'string', 'max:40'],
            'origin' => ['nullable', Rule::in(['personal', 'company'])],
        ]);

        $mapping = array_filter($data['mapping']);
        $fields = array_values($mapping);
        if (! array_intersect(['email', 'phone'], $fields) || ! array_intersect(['first_name', 'last_name', 'full_name', 'company_name'], $fields)) {
            return back()->withErrors(['mapping' => 'Match a name column and an email or phone column.']);
        }

        $import->update(['mapping' => $mapping, 'options' => array_filter(['source' => $data['source'] ?? 'import', 'tag' => $data['tag'] ?? null, 'origin' => $data['origin'] ?? null]), 'status' => 'running']);
        ImportContacts::dispatch($import->id);

        return to_route('crm.imports.show', $import);
    }

    public function duplicates(Request $request, MergeContacts $merge): Response
    {
        $user = self::user($request);

        return Inertia::render('crm/contacts/duplicates', [
            'pairs' => $merge->candidates($user)->map(fn (array $p): array => [
                'reason' => $p['reason'],
                'keep' => ['ulid' => $p['keep']->ulid, 'name' => $p['keep']->name, 'email' => $p['keep']->primary_email, 'phone' => $p['keep']->primary_phone],
                'duplicate' => ['ulid' => $p['duplicate']->ulid, 'name' => $p['duplicate']->name, 'email' => $p['duplicate']->primary_email, 'phone' => $p['duplicate']->primary_phone],
            ]),
        ]);
    }

    public function merge(Request $request, MergeContacts $merge): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['keep' => ['required', 'string', 'size:26'], 'duplicate' => ['required', 'string', 'size:26']]);
        $keep = Contact::query()->where('ulid', $data['keep'])->first();
        $duplicate = Contact::query()->where('ulid', $data['duplicate'])->first();
        abort_if($keep === null || $duplicate === null || ! $this->visibility->canSee($user, $keep) || ! $this->visibility->canSee($user, $duplicate), 404);

        $merge->merge($keep, $duplicate, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Merged into {$keep->name}."]);

        return back();
    }

    public function consent(Request $request, Contact $contact, Consent $consent): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($this->visibility->canSee($user, $contact), 404);
        $data = $request->validate([
            'action' => ['required', Rule::in(['sms_opt_in', 'sms_opt_out', 'email_opt_in', 'email_opt_out', 'dnc_on', 'dnc_off'])],
            'method' => ['nullable', Rule::in(['web_form', 'signed', 'verbal'])],
            'wording' => ['nullable', 'string', 'max:2000'],
        ]);

        match ((string) $data['action']) {
            'sms_opt_in' => $consent->optInSms($contact, $data['method'] ?? '', $data['wording'] ?? '', $user, $request),
            'sms_opt_out' => $consent->optOutSms($contact, 'agent', $user),
            'email_opt_in' => $consent->setEmail($contact, true, $data['method'] ?? 'verbal', $user),
            'email_opt_out' => $consent->setEmail($contact, false, 'agent', $user),
            'dnc_on' => $consent->setDoNotCall($contact, true, $user),
            'dnc_off' => $consent->setDoNotCall($contact, false, $user),
            default => abort(422),
        };

        return back();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function consentHistory(Contact $contact): array
    {
        /** @var list<array<string, mixed>> */
        return ConsentEvent::query()->where('contact_id', $contact->id)->orderByDesc('occurred_at')->limit(20)->get()
            ->map(fn (ConsentEvent $e): array => ['channel' => $e->channel, 'action' => $e->action, 'method' => $e->method, 'occurred_at' => $e->occurred_at->toIso8601String()])
            ->all();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->kind !== UserKind::HqStaff, 403);

        return $user;
    }
}
