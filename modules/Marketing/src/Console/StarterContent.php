<?php

declare(strict_types=1);

namespace Modules\Marketing\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\Website;

/**
 * Adds RightAlly's starter website pages in English and Spanish (SG1, specs/marketing/01): home, features, pricing,
 * for agents and security are published; the comparison page is left as a draft because facts about other companies
 * must be checked by a person before they go live. Pages that already exist are never touched.
 */
final class StarterContent extends Command
{
    protected $signature = 'marketing:starter';

    protected $description = "Add RightAlly's starter website pages (existing pages are left alone)";

    public function handle(Website $site, TenantContext $tenants): int
    {
        $hq = Tenant::query()->where('type', 'hq')->first();
        $by = $hq !== null ? $tenants->runAs($hq, fn () => User::query()->where('kind', UserKind::HqStaff)->orderBy('id')->first()) : null;
        if (! $by instanceof User) {
            $this->error('Create an HQ staff account first (tenants:bootstrap-hq).');

            return self::FAILURE;
        }
        $added = 0;
        foreach (self::pages() as [$locale, $slug, $title, $description, $blocks, $publish]) {
            if ($site->page($locale, $slug) !== null) {
                continue;
            }
            $ulid = $site->saveDraft(null, $locale, $slug, $title, $description, $blocks, $by);
            if ($publish) {
                $site->publish($ulid, $by);
            }
            $added++;
        }
        $this->info("{$added} page(s) added.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: list<array<string, mixed>>, 5: bool}>
     */
    public static function pages(): array
    {
        $startEn = ['type' => 'cta', 'heading' => 'Ready when you are', 'text' => 'Sign up online in a few minutes. Your brokerage is created the moment your first payment goes through.',
            'label' => 'Start now', 'href' => '/start'];
        $startEs = ['type' => 'cta', 'heading' => 'Listo cuando usted lo esté', 'text' => 'Regístrese en línea en pocos minutos. Su correduría se crea en cuanto se procesa el primer pago.',
            'label' => 'Empezar', 'href' => '/start'];

        return [
            ['en', '', 'RightAlly: the all-in-one platform for real estate brokerages', 'Website, CRM, transactions, compliance, commissions, accounting and AI assistants for US brokerages, in one place, with public prices.', [
                ['type' => 'hero', 'heading' => 'Everything your brokerage runs on, in one place', 'text' => 'Website and IDX, CRM and lead follow-up, deals and compliance, commissions and payouts, accounting, recruiting and training, with AI assistants that do the busywork.',
                    'cta_label' => 'Start now', 'cta_href' => '/start', 'second_label' => 'Free Health Check', 'second_href' => '/health-check'],
                ['type' => 'answer', 'question' => 'What is RightAlly?', 'answer' => 'RightAlly is an all-in-one platform for US real estate brokerages. It replaces separate website, CRM, transaction, compliance, commission and accounting tools with one system that agents and staff can learn quickly, with public prices and online signup.'],
                ['type' => 'features', 'heading' => 'What you get', 'items' => [
                    ['title' => 'Grow', 'text' => 'Your website with IDX search, lead capture, AI follow-up, marketing and a referral network with other brokerages.'],
                    ['title' => 'Run', 'text' => 'Deals, documents and e-signing, deadlines and state rules, with an AI transaction coordinator.'],
                    ['title' => 'Money', 'text' => 'Commission plans, caps and revenue share, agent payouts, 1099s and brokerage accounting.'],
                    ['title' => 'Protect', 'text' => 'Fair Housing checks, texting consent, audit trails and access controls built in.'],
                ]],
                ['type' => 'steps', 'heading' => 'How switching works', 'items' => [
                    ['title' => 'Sign up online', 'text' => 'Choose a package and pay; your brokerage is created straight away.'],
                    ['title' => 'Bring your old files', 'text' => 'People, agents and deals come over from spreadsheets and the apps you use now.'],
                    ['title' => 'Go live in 30 days', 'text' => 'A plan that ticks itself off, with a named coach.'],
                ]],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => 'Do I need a demo before signing up?', 'a' => 'No. Prices are public and you can sign up online. A coach helps you go live.'],
                    ['q' => 'Can agents use it on their phone?', 'a' => 'Yes. RightAlly works in the phone\'s browser and can be added to the home screen like an app.'],
                    ['q' => 'Is it available in Spanish?', 'a' => 'Yes. Agents can choose Spanish for the menus, search and the simple view, and client pages are in English and Spanish.'],
                ]],
                $startEn,
            ], true],
            ['es', '', 'RightAlly: la plataforma todo en uno para corredurías', 'Sitio web, CRM, operaciones, cumplimiento, comisiones, contabilidad y asistentes de IA para corredurías en EE. UU., en un solo lugar.', [
                ['type' => 'hero', 'heading' => 'Todo lo que su correduría necesita, en un solo lugar', 'text' => 'Sitio web e IDX, CRM y seguimiento de prospectos, operaciones y cumplimiento, comisiones y pagos, contabilidad, reclutamiento y capacitación, con asistentes de IA.',
                    'cta_label' => 'Empezar', 'cta_href' => '/start', 'second_label' => 'Chequeo gratuito', 'second_href' => '/health-check'],
                ['type' => 'answer', 'question' => '¿Qué es RightAlly?', 'answer' => 'RightAlly es una plataforma todo en uno para corredurías de bienes raíces en Estados Unidos. Reemplaza herramientas separadas de sitio web, CRM, operaciones, cumplimiento, comisiones y contabilidad con un solo sistema fácil de aprender, con precios públicos y registro en línea.'],
                ['type' => 'features', 'heading' => 'Lo que incluye', 'items' => [
                    ['title' => 'Crecer', 'text' => 'Su sitio web con búsqueda IDX, captación de prospectos, seguimiento con IA, marketing y una red de referidos.'],
                    ['title' => 'Operar', 'text' => 'Operaciones, documentos y firma electrónica, plazos y reglas estatales, con un coordinador de transacciones con IA.'],
                    ['title' => 'Dinero', 'text' => 'Planes de comisión, topes y participación de ingresos, pagos a agentes, formularios 1099 y contabilidad.'],
                    ['title' => 'Proteger', 'text' => 'Revisiones de vivienda justa, consentimiento para mensajes de texto, registros de auditoría y control de accesos.'],
                ]],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => '¿Necesito una demostración antes de registrarme?', 'a' => 'No. Los precios son públicos y puede registrarse en línea. Un asesor le ayuda a empezar.'],
                    ['q' => '¿Los agentes pueden usarlo en el teléfono?', 'a' => 'Sí. RightAlly funciona en el navegador del teléfono y se puede agregar a la pantalla de inicio como una aplicación.'],
                ]],
                $startEs,
            ], true],
            ['en', 'pricing', 'RightAlly prices: public, monthly or yearly', 'RightAlly prices for brokerages, shown in full. Sign up online, pay monthly or yearly, and cancel any time.', [
                ['type' => 'hero', 'heading' => 'Simple, public prices', 'text' => 'No demo needed to see what it costs. Pay monthly or yearly.', 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => ''],
                ['type' => 'pricing', 'heading' => '', 'note' => 'Prices are in US dollars. Taxes may apply.'],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => 'Can agents pay for their own plan?', 'a' => 'Yes. The brokerage can pay for everyone, or agents can pay RightAlly for their own plan.'],
                    ['q' => 'What are credits?', 'a' => 'Some AI and messaging features use credits, so you only pay for what you use. Every package includes some.'],
                ]],
                $startEn,
            ], true],
            ['es', 'pricing', 'Precios de RightAlly: públicos, mensuales o anuales', 'Precios de RightAlly para corredurías, completos. Regístrese en línea, pague mensual o anualmente y cancele cuando quiera.', [
                ['type' => 'hero', 'heading' => 'Precios simples y públicos', 'text' => 'No necesita una demostración para saber cuánto cuesta.', 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => ''],
                ['type' => 'pricing', 'heading' => '', 'note' => 'Precios en dólares estadounidenses. Pueden aplicarse impuestos.'],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => '¿Los agentes pueden pagar su propio plan?', 'a' => 'Sí. La correduría puede pagar por todos, o cada agente puede pagar su propio plan.'],
                ]],
                $startEs,
            ], true],
            ['en', 'compare', 'RightAlly compared with other brokerage platforms', 'How RightAlly compares with other brokerage software on prices, signup, switching and built-in compliance.', [
                ['type' => 'hero', 'heading' => 'How RightAlly compares', 'text' => 'A fair side-by-side view. Check each fact before publishing.', 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => ''],
                ['type' => 'compare', 'heading' => 'At a glance', 'columns' => ['RightAlly', 'Typical brokerage suite'], 'checked_on' => 'To be checked', 'rows' => [
                    ['label' => 'Public prices', 'values' => ['Yes', 'Often after a demo']],
                    ['label' => 'Online signup', 'values' => ['Yes', 'Sales call first']],
                    ['label' => 'Website, CRM, deals and accounting in one', 'values' => ['Yes', 'Often separate tools']],
                ], 'note' => 'Draft: a person must check every fact about other companies, with sources and the date, before this page is published.'],
                $startEn,
            ], false],
            ['en', 'for-agents', 'RightAlly for agents: your business on your phone', 'For agents: contacts, follow-up, deals and commissions on your phone, with AI that drafts and reminds, in English or Spanish.', [
                ['type' => 'hero', 'heading' => 'Your business, on your phone', 'text' => 'See who to call today, follow up in a tap, track your deals and know what you will be paid.', 'cta_label' => 'Start now', 'cta_href' => '/start',
                    'second_label' => '', 'second_href' => ''],
                ['type' => 'answer', 'question' => 'Can a solo agent use RightAlly?', 'answer' => 'Yes. Solo agents can sign up for their own plan, use the CRM, follow-up, website and deal tools on their phone, and keep their contacts if they move brokerages. Brokerages can also give every agent access as part of their package.'],
                $startEn,
            ], true],
            ['en', 'security', 'RightAlly security and privacy', 'How RightAlly protects brokerage and client data: separate databases, encryption, two-step sign-in, audit trails and consent rules.', [
                ['type' => 'hero', 'heading' => 'Your data stays yours', 'text' => 'Each brokerage\'s data is kept apart, encrypted, and never sold.', 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => ''],
                ['type' => 'features', 'heading' => 'How we protect it', 'items' => [
                    ['title' => 'Two-step sign-in', 'text' => 'Every account can use an authenticator app, and owners can require it.'],
                    ['title' => 'Audit trail', 'text' => 'Who did what and when is recorded and can be exported.'],
                    ['title' => 'Support asks first', 'text' => 'Brokerages can require RightAlly support to ask before viewing their account, and see every visit.'],
                    ['title' => 'Texting consent', 'text' => 'Only people who agreed can be texted, between 8am and 9pm their time.'],
                ]],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => 'Can I export all my data?', 'a' => 'Yes. The owner can download everything the brokerage has in RightAlly at any time.'],
                ]],
                $startEn,
            ], true],
        ];
    }
}
