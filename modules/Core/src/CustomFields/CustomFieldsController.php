<?php

declare(strict_types=1);

namespace Modules\Core\CustomFields;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Access\Fields;

/** Settings → Custom fields (specs/core/10, N1). */
final class CustomFieldsController
{
    public function __construct(private readonly CustomFields $fields) {}

    public function index(Request $request): Response
    {
        self::manager($request);

        return Inertia::render('settings/custom-fields', [
            'entities' => CustomFields::ENTITIES,
            'types' => CustomFields::TYPES,
            'groups' => array_map(fn (array $g): string => $g[1], Fields::HIDEABLE),
            'fields' => array_map(fn (string $e): array => $this->fields->definitions($e, withArchived: true), array_combine(array_keys(CustomFields::ENTITIES), array_keys(CustomFields::ENTITIES))),
        ]);
    }

    public function save(Request $request, string $entity, ?string $field = null): RedirectResponse
    {
        $user = self::manager($request);
        $data = $request->validate(['label' => ['required', 'string', 'max:80'], 'type' => ['required', 'string'], 'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['nullable', 'string', 'max:60'], 'required_to_close' => ['nullable', 'boolean'], 'hidden_from' => ['nullable', 'string'], 'help' => ['nullable', 'string', 'max:200']]);
        $this->fields->define($entity, [
            'label' => $data['label'], 'type' => $data['type'], 'options' => array_values(array_map('strval', array_filter((array) ($data['options'] ?? []), 'is_string'))),
            'required_to_close' => (bool) ($data['required_to_close'] ?? false), 'hidden_from' => $data['hidden_from'] ?? null, 'help' => $data['help'] ?? null,
        ], $user, $field);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Field saved.']);

        return back();
    }

    public function archive(Request $request, string $entity, string $field): RedirectResponse
    {
        $user = self::manager($request);
        $this->fields->archive($entity, $field, $user, ! $request->boolean('restore'));

        return back();
    }

    private static function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && CustomFields::manages($user), 403);

        return $user;
    }
}
