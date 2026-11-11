<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\MergeContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactImport;
use Modules\Crm\Models\Task;

beforeEach(function (): void {
    Storage::fake('local');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    $this->other = User::factory()->create();
    $this->contacts = app(ManageContacts::class);
});

function csvUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('contacts.csv', $content);
}

it('imports a CSV: matches columns, skips duplicates, reports problems and never imports consent', function (): void {
    $this->contacts->create(['first_name' => 'Existing', 'email' => 'dup@x.test'], $this->other);
    $csv = "\xEF\xBB\xBFFirst Name,Last Name,E-mail,Mobile,Tags,City\n"
        ."Ann,Lee,ann@x.test,(601) 555-0101,\"VIP, buyer\",Jackson\n"
        ."Dup,Person,dup@x.test,,,\n"
        ."No,Contact,,,,\n"
        .",,,,,\n"
        ."Bob,Ray,,601.555.0102,,Madison\n";

    $response = $this->actingAs($this->agent)->post('/contacts/imports', ['file' => csvUpload($csv)]);
    $import = ContactImport::query()->sole();
    $response->assertRedirect("/contact-imports/{$import->ulid}");

    $this->actingAs($this->agent)->get("/contact-imports/{$import->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('guess.First Name', 'first_name')->where('guess.E-mail', 'email')->where('guess.Mobile', 'phone')
        ->where('guess.Tags', 'tags')->where('guess.City', 'city')->where('preview.0.First Name', 'Ann'));

    $this->actingAs($this->agent)->post("/contact-imports/{$import->ulid}/run", [
        'mapping' => ['First Name' => 'first_name', 'Last Name' => 'last_name', 'E-mail' => 'email', 'Mobile' => 'phone', 'Tags' => 'tags', 'City' => 'city'],
        'source' => 'import', 'tag' => 'Spring import',
    ])->assertRedirect();

    $import->refresh();
    expect($import->status)->toBe('done')->and($import->rows)->toBe(4)->and($import->created_count)->toBe(2)
        ->and($import->duplicates)->toBe(1)->and($import->error_count)->toBe(1)->and($import->errors[0]['row'])->toBe(4);

    $ann = Contact::query()->where('first_name', 'Ann')->sole();
    expect($ann->origin->value)->toBe('personal')->and($ann->owner_user_id)->toBe($this->agent->id)
        ->and($ann->city)->toBe('Jackson')->and($ann->primary_phone)->toBe('+16015550101')
        ->and($ann->tags()->pluck('name')->sortBy(fn ($t) => mb_strtolower($t))->values()->all())->toBe(['buyer', 'Spring import', 'VIP'])
        ->and($ann->sms_opt_in)->toBeFalse()->and($ann->email_opt_in)->toBeFalse();
    Storage::disk('local')->assertMissing($import->file_path);
});

it('splits full names, reads Windows-1252 files, and insists on a name and an email or phone column', function (): void {
    $csv = "Name,Phone\n".mb_convert_encoding('José María López', 'Windows-1252', 'UTF-8').",6015550199\n";
    $this->actingAs($this->agent)->post('/contacts/imports', ['file' => csvUpload($csv)]);
    $import = ContactImport::query()->sole();

    $this->actingAs($this->agent)->post("/contact-imports/{$import->ulid}/run", ['mapping' => ['Name' => 'full_name']])->assertSessionHasErrors('mapping');
    $this->actingAs($this->agent)->post("/contact-imports/{$import->ulid}/run", ['mapping' => ['Name' => 'full_name', 'Phone' => 'phone']]);

    $contact = Contact::query()->sole();
    expect($contact->first_name)->toBe('José María')->and($contact->last_name)->toBe('López');
});

it('keeps imports private to the person who uploaded them', function (): void {
    $this->actingAs($this->agent)->post('/contacts/imports', ['file' => csvUpload("Name,Email\nA,a@x.test\n")]);
    $import = ContactImport::query()->sole();

    $this->actingAs($this->other)->get("/contact-imports/{$import->ulid}")->assertNotFound();
    $this->actingAs($this->other)->post("/contact-imports/{$import->ulid}/run", ['mapping' => ['Name' => 'full_name', 'Email' => 'email']])->assertNotFound();
});

it('finds and merges duplicates, keeping everything and the stricter consent', function (): void {
    $keep = $this->contacts->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@x.test', 'phone' => '6015550100'], $this->agent);
    $dup = $this->contacts->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '6015550111'], $this->agent);
    $dup->forceFill(['city' => 'Jackson'])->save();
    app(Consent::class)->optInSms($keep->fresh(), 'signed', Consent::DEFAULT_SMS_WORDING, $this->agent);
    app(Consent::class)->setDoNotCall($dup, true, $this->agent);
    app(Timeline::class)->log($dup, ['type' => 'note', 'body' => 'From the duplicate'], $this->agent);
    app(Tasks::class)->create($dup, ['title' => 'Call Pat', 'due' => 'tomorrow'], $this->agent);
    app(Tags::class)->attach($dup, 'investor');

    $this->actingAs($this->agent)->get('/contacts/duplicates')->assertInertia(fn (Assert $page) => $page
        ->where('pairs.0.reason', 'same name')->where('pairs.0.keep.ulid', $keep->ulid));

    $this->actingAs($this->agent)->post('/contacts/merge', ['keep' => $keep->ulid, 'duplicate' => $dup->ulid])->assertSessionHasNoErrors();

    $kept = $keep->fresh();
    expect($kept->primary_email)->toBe('pat@x.test')->and(collect($kept->phones)->pluck('e164')->all())->toBe(['+16015550100', '+16015550111'])
        ->and($kept->city)->toBe('Jackson')->and($kept->do_not_call)->toBeTrue()
        ->and($kept->activities ?? Activity::query()->where('contact_id', $kept->id)->where('body', 'From the duplicate')->exists())->toBeTruthy()
        ->and(Task::query()->where('contact_id', $kept->id)->count())->toBe(1)
        ->and($kept->tags()->pluck('name')->all())->toBe(['investor'])
        ->and(Contact::query()->find($dup->id))->toBeNull()
        ->and(Contact::withTrashed()->find($dup->id)?->merged_into_id)->toBe($keep->id)
        ->and(ConsentEvent::query()->where('contact_id', $keep->id)->count())->toBe(2);
});

it('lets only owners and staff merge a personal and a company contact, and never others\' contacts', function (): void {
    $personal = $this->contacts->create(['first_name' => 'Kim', 'email' => 'kim@x.test'], $this->agent);
    $company = $this->contacts->create(['first_name' => 'Kim', 'email' => 'kim2@x.test'], $this->owner);
    $this->contacts->assignOwner($company, $this->agent, $this->owner);
    $hidden = $this->contacts->create(['first_name' => 'Kim', 'email' => 'kim3@x.test'], $this->other);

    expect(fn () => app(MergeContacts::class)->merge($personal, $company->fresh(), $this->agent))->toThrow(ValidationException::class);
    $this->actingAs($this->agent)->post('/contacts/merge', ['keep' => $personal->ulid, 'duplicate' => $hidden->ulid])->assertNotFound();
});

it('records consent as evidence; automated texts need written consent and stop at an opt-out', function (): void {
    $consent = app(Consent::class);
    $contact = $this->contacts->create(['first_name' => 'Ava', 'phone' => '6015550123'], $this->agent);

    expect(fn () => $consent->optInSms($contact, 'verbal', ''))->toThrow(ValidationException::class);

    $consent->optInSms($contact, 'verbal', Consent::DEFAULT_SMS_WORDING, $this->agent);
    expect($consent->canText($contact))->toBeTrue()->and($consent->canAutoText($contact))->toBeFalse();

    $this->travel(1)->minutes();
    $this->actingAs($this->agent)->post("/contacts/{$contact->ulid}/consent", ['action' => 'sms_opt_in', 'method' => 'signed', 'wording' => Consent::DEFAULT_SMS_WORDING]);
    expect($consent->canAutoText($contact->fresh()))->toBeTrue();

    $this->travel(1)->minutes();
    $this->actingAs($this->agent)->post("/contacts/{$contact->ulid}/consent", ['action' => 'sms_opt_out']);
    expect($consent->canText($contact->fresh()))->toBeFalse()->and($consent->canAutoText($contact->fresh()))->toBeFalse();

    $this->actingAs($this->agent)->post("/contacts/{$contact->ulid}/consent", ['action' => 'dnc_on']);
    expect($consent->canCall($contact->fresh()))->toBeFalse()
        ->and(ConsentEvent::query()->where('contact_id', $contact->id)->count())->toBe(4);

    $event = ConsentEvent::query()->first();
    expect(fn () => $event?->update(['action' => 'opt_out']))->toThrow(LogicException::class)
        ->and(fn () => $event?->delete())->toThrow(LogicException::class);

    $this->actingAs($this->other)->post("/contacts/{$contact->ulid}/consent", ['action' => 'dnc_off'])->assertNotFound();
});
