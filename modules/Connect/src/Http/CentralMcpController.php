<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Connect\Mcp\Central;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Directory;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared AI assistant address (specs/connect/03, M2b): discovery, registration, "which brokerage?" by email,
 * and the hand-offs to and from the brokerage's own sign-in. See Modules\Connect\Mcp\Central.
 */
#[CrossTenantAccess('The shared AI assistant address sends each person to their own brokerage; it reads no brokerage data')]
final class CentralMcpController
{
    private const COOKIE = 'rally_brokerage';

    public function __construct(private readonly Central $central) {}

    public function authorizationServer(): JsonResponse
    {
        return response()->json(Central::authorizationServer());
    }

    public function protectedResource(): JsonResponse
    {
        return response()->json(Central::protectedResource());
    }

    public function register(Request $request): JsonResponse
    {
        return $this->central->register($request);
    }

    public function authorize(Request $request): View
    {
        try {
            $p = $this->central->begin($request->query());
        } catch (ValidationException $e) {
            return view('connect.mcp-find', ['error' => (string) collect($e->errors())->flatten()->first(), 'pending' => null, 'client' => null, 'remembered' => null, 'choices' => []]);
        }

        return view('connect.mcp-find', ['error' => null, 'pending' => $p['id'], 'client' => $p['client'], 'remembered' => self::remembered($request), 'choices' => []]);
    }

    /** "Your email": one brokerage → straight to its sign-in; several → choose; none → a plain message. */
    public function find(Request $request): Response
    {
        $data = $request->validate(['pending' => ['required', 'string', 'size:40'], 'email' => ['required', 'email', 'max:254']]);
        $p = Central::pending($data['pending']);
        $brokerages = Directory::brokeragesFor($data['email']);
        app(AuditLogger::class)->record('connect.mcp_central_find', metadata: ['found' => count($brokerages)], severity: 'info');
        if ($p === null || $brokerages === []) {
            return response()->view('connect.mcp-find', ['pending' => $p !== null ? $data['pending'] : null, 'client' => $p['name'] ?? null, 'remembered' => null, 'choices' => [],
                'error' => $p === null ? 'That took too long. Please start again from the AI app.' : 'We couldn\'t find a RightAlly account with that email. Check it, or ask your broker which email you sign in with.'], 422);
        }
        if (count($brokerages) === 1) {
            return $this->go($request, $data['pending'], $brokerages[0]);
        }
        $request->session()->put('mcp-central.choices.'.$data['pending'], array_map(fn (Tenant $t): string => $t->ulid, $brokerages));

        return response()->view('connect.mcp-find', ['pending' => $data['pending'], 'client' => $p['name'], 'remembered' => null, 'error' => null,
            'choices' => array_map(fn (Tenant $t): array => ['ulid' => $t->ulid, 'name' => $t->name], $brokerages)]);
    }

    /** A brokerage chosen from the list, or the one remembered on this device. */
    public function choose(Request $request): Response
    {
        $data = $request->validate(['pending' => ['required', 'string', 'size:40'], 'brokerage' => ['required', 'string', 'size:26']]);
        $allowed = (array) $request->session()->get('mcp-central.choices.'.$data['pending'], []);
        $remembered = self::remembered($request);
        abort_unless(in_array($data['brokerage'], $allowed, true) || ($remembered !== null && $remembered['ulid'] === $data['brokerage']), 403);
        $tenant = Tenant::query()->where('ulid', $data['brokerage'])->where('status', 'active')->firstOrFail();

        return $this->go($request, $data['pending'], $tenant);
    }

    public function back(Request $request): RedirectResponse
    {
        try {
            return redirect()->away($this->central->back($request));
        } catch (ValidationException $e) {
            abort(400, (string) collect($e->errors())->flatten()->first());
        }
    }

    public function token(Request $request): Response
    {
        return $this->central->token($request);
    }

    private function go(Request $request, string $pending, Tenant $tenant): Response
    {
        try {
            $to = $this->central->toBrokerage($pending, $tenant);
        } catch (ValidationException $e) {
            return response()->view('connect.mcp-find', ['pending' => null, 'client' => null, 'remembered' => null, 'choices' => [],
                'error' => (string) collect($e->errors())->flatten()->first()], 422);
        }

        return redirect()->away($to)->withCookie(cookie(self::COOKIE, $tenant->ulid, 60 * 24 * 365, '/', null, true, true, false, 'lax'));
    }

    /** @return array{ulid: string, name: string}|null */
    private static function remembered(Request $request): ?array
    {
        $cookie = $request->cookie(self::COOKIE);
        $ulid = is_string($cookie) ? $cookie : '';
        $t = strlen($ulid) === 26 ? Tenant::query()->where('ulid', $ulid)->where('status', 'active')->first() : null;

        return $t !== null ? ['ulid' => $t->ulid, 'name' => $t->name] : null;
    }
}
