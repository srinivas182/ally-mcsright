<?php

declare(strict_types=1);

namespace Modules\Marketing\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\LegalDefaults;
use Modules\Marketing\Website;

/**
 * Adds RightAlly's starter website pages in English and Spanish (SG1, specs/marketing/01): home, features, pricing,
 * for agents and security are published; the comparison page is left as a draft because facts about other companies
 * must be checked by a person before they go live. Pages that already exist are never touched.
 */
final class StarterContent extends Command
{
    protected $signature = 'marketing:starter {--refresh : Save the latest starter content as a new draft of pages that already exist (HQ reviews and publishes)}';

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
            $existing = $site->page($locale, $slug);
            if ($existing !== null) {
                if ($this->option('refresh')) { // MK1: a new draft beside what is live; nothing changes until HQ publishes it
                    $site->saveDraft((string) $existing->ulid, $locale, $slug, $title, $description, $blocks, $by);
                    $added++;
                }

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

        return array_merge([
            ['en', '', 'RightAlly: run your whole brokerage in one place', 'Website, CRM, deals, e-sign, commissions, accounting and AI help for US brokerages and agents, in one place, on one bill.', [
                ['type' => 'hero', 'heading' => 'Run your whole real estate business in one place', 'text' => 'Your website, CRM, deals and e-sign, commissions, accounting and AI help, on one bill, easy enough for anyone, and set up for you.',
                    'cta_label' => 'Choose a package', 'cta_href' => '/pricing', 'second_label' => 'Free Health Check', 'second_href' => '/health-check'],
                ['type' => 'doors', 'heading' => 'Which one are you?', 'broker_heading' => 'I run a brokerage', 'broker_text' => 'One system for your agents, deals, compliance and money, with nothing to stitch together.', 'broker_href' => '/for-brokers',
                    'agent_heading' => 'I\'m an agent', 'agent_text' => 'Know who to call today, follow up in a tap, and build your own name.', 'agent_href' => '/for-agents'],
                ['type' => 'answer', 'question' => 'What is RightAlly?', 'answer' => 'RightAlly is one system for US real estate brokerages and agents: website and leads, CRM and follow-up, deals and e-signatures, commissions and payouts, accounting, training and AI help. It replaces separate tools with one login and one bill, and RightAlly moves your people, deals and files over for you.'],
                ['type' => 'modules', 'heading' => 'Everything in one place', 'audience' => 'brokerage'],
                ['type' => 'savings', 'heading' => 'What do you pay today?', 'text' => 'Add up the separate tools you pay for now and see it next to one RightAlly bill.', 'audience' => 'brokerage'],
                ['type' => 'steps', 'heading' => 'We move you', 'items' => [
                    ['title' => 'Choose a package', 'text' => 'Pay online; your account is ready straight away.'],
                    ['title' => 'Send us your spreadsheets', 'text' => 'Your people, agents and deals, as they are. We set them up for you.'],
                    ['title' => 'Start in days', 'text' => 'Your website goes live with ready-made content, and a checklist shows what is left.'],
                ]],
                ['type' => 'features', 'heading' => 'Easy for everyone', 'items' => [
                    ['title' => 'Today\'s 10', 'text' => 'Who to call today and why, with big Call and Text buttons.'],
                    ['title' => 'Talk instead of type', 'text' => 'A microphone on every page: say what you need.'],
                    ['title' => 'Help on every page', 'text' => 'A 1-minute video, a printable cheat sheet, or ask us to call you back.'],
                    ['title' => 'Practice first', 'text' => 'Try everything on made-up clients before using it with real ones.'],
                ]],
                ['type' => 'features', 'heading' => 'Your data stays yours', 'items' => [
                    ['title' => 'Kept apart', 'text' => 'Each brokerage\'s data is separate, and an agent\'s private contacts stay theirs.'],
                    ['title' => 'Safe sign-in', 'text' => 'Fingerprint or face sign-in (passkeys) and a second step for everyone.'],
                    ['title' => 'Every change recorded', 'text' => 'A tamper-evident record of who did what, which you can export.'],
                    ['title' => 'Never sold', 'text' => 'Your leads and your clients\' details are never sold or shared.'],
                ]],
                ['type' => 'pricing', 'heading' => 'Public prices', 'note' => 'Prices in US dollars, monthly or yearly. Taxes may apply.', 'audience' => 'brokerage'],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => 'Do I need a demo before signing up?', 'a' => 'No. Prices are public and you can sign up online. We set up your people and deals for you.'],
                    ['q' => 'Can agents use it on their phone?', 'a' => 'Yes. RightAlly works in the phone\'s browser and can be added to the home screen like an app.'],
                    ['q' => 'Is it hard to learn?', 'a' => 'No. There is a simple view with big buttons, a microphone on every page, help on every page and practice clients to try things on.'],
                    ['q' => 'Is it available in Spanish?', 'a' => 'Yes. Menus, the simple view and client pages work in English and Spanish.'],
                ]],
                $startEn,
            ], true],
            ['en', 'for-brokers', 'RightAlly for brokerages: one system, one bill', 'For broker owners: agents, deals, compliance, commissions, accounting and your website in one system, set up for you.', [
                ['type' => 'hero', 'heading' => 'One system for your whole brokerage', 'text' => 'Agents, deals and compliance, commissions and payouts, accounting, recruiting and your website, on one bill. We move you in.',
                    'cta_label' => 'Choose a package', 'cta_href' => '/pricing', 'second_label' => 'Free Health Check', 'second_href' => '/health-check'],
                ['type' => 'answer', 'question' => 'Why do brokerages choose RightAlly?', 'answer' => 'Because it replaces many separate tools with one system, one login and one bill; it is easy for agents of every age; client data is kept safe and apart; and RightAlly moves your people, agents and deals over for you.'],
                ['type' => 'modules', 'heading' => 'What your brokerage gets', 'audience' => 'brokerage'],
                ['type' => 'savings', 'heading' => 'What do you pay today?', 'text' => 'Add up what your brokerage pays for separate tools now.', 'audience' => 'brokerage'],
                ['type' => 'pricing', 'heading' => 'Brokerage packages', 'note' => 'Prices in US dollars, monthly or yearly. Need more texts or an extra module later? Add it on its own.', 'audience' => 'brokerage'],
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
                ['type' => 'pricing', 'heading' => 'For brokerages', 'note' => 'Prices are in US dollars. Taxes may apply.'],
                ['type' => 'seats', 'heading' => 'For agents', 'note' => 'Monthly, in US dollars. The brokerage chooses whether it pays for its agents or each agent pays for their own seat.'],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => 'Can agents pay for their own plan?', 'a' => 'Yes. The brokerage can pay for everyone, or agents can pay RightAlly for their own plan.'],
                    ['q' => 'What are credits?', 'a' => 'Some AI and messaging features use credits, so you only pay for what you use. Every package includes some.'],
                ]],
                $startEn,
            ], true],
            ['es', 'pricing', 'Precios de RightAlly: públicos, mensuales o anuales', 'Precios de RightAlly para corredurías, completos. Regístrese en línea, pague mensual o anualmente y cancele cuando quiera.', [
                ['type' => 'hero', 'heading' => 'Precios simples y públicos', 'text' => 'No necesita una demostración para saber cuánto cuesta.', 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => ''],
                ['type' => 'pricing', 'heading' => 'Para corredurías', 'note' => 'Precios en dólares estadounidenses. Pueden aplicarse impuestos.'],
                ['type' => 'seats', 'heading' => 'Para agentes', 'note' => 'Mensual, en dólares estadounidenses. La correduría decide si paga por sus agentes o si cada agente paga su propio puesto.'],
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
                ['type' => 'modules', 'heading' => 'What you get', 'audience' => 'agent'],
                ['type' => 'savings', 'heading' => 'What do you pay today?', 'text' => 'Add up the tools you pay for yourself now.', 'audience' => 'agent'],
                ['type' => 'pricing', 'heading' => 'Plans for agents', 'note' => 'Prices in US dollars, monthly or yearly.', 'audience' => 'agent'],
                ['type' => 'answer', 'question' => 'Can a solo agent use RightAlly?', 'answer' => 'Yes. Solo agents can sign up for their own plan, use the CRM, follow-up, website and deal tools on their phone, and keep their contacts if they move brokerages. Brokerages can also give every agent access as part of their package.'],
                $startEn,
            ], true],
            ['en', 'security', 'RightAlly security and privacy', 'How RightAlly protects brokerage and client data: separate databases, encryption, two-step sign-in, audit trails and consent rules.', [
                ['type' => 'hero', 'heading' => 'Your data stays yours', 'text' => 'Each brokerage\'s data is kept apart, encrypted, and never sold.', 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => ''],
                ['type' => 'features', 'heading' => 'How we protect it', 'items' => [
                    ['title' => 'Safe sign-in', 'text' => 'Fingerprint or face sign-in (passkeys), and a second step for everyone.'],
                    ['title' => 'Audit trail', 'text' => 'Who did what and when is recorded and can be exported.'],
                    ['title' => 'Support asks first', 'text' => 'Brokerages can require RightAlly support to ask before viewing their account, and see every visit.'],
                    ['title' => 'Texting consent', 'text' => 'Only people who agreed can be texted, between 8am and 9pm their time.'],
                ]],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => 'Can I export all my data?', 'a' => 'Yes. The owner can download everything the brokerage has in RightAlly at any time.'],
                ]],
                $startEn,
            ], true],
        ], self::spanishPages($startEs), LegalDefaults::starterPages());
    }

    /**
     * SITE3: the Spanish twins of the brokerage, agent and security pages. They use the same addresses as the English
     * pages (/es/for-brokers and /for-brokers), like pricing does, so each pair is linked for search engines (hreflang)
     * and the language selector lands on the same page in the other language.
     *
     * @param  array<string, mixed>  $startEs
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: list<array<string, mixed>>, 5: bool}>
     */
    private static function spanishPages(array $startEs): array
    {
        return [
            ['es', 'for-brokers', 'RightAlly para corredurías: un sistema, una factura', 'Para dueños de corredurías: agentes, operaciones, cumplimiento, comisiones, contabilidad y su sitio web en un solo sistema.', [
                ['type' => 'hero', 'heading' => 'Un sistema para toda su correduría', 'text' => 'Agentes, operaciones y cumplimiento, comisiones y pagos, contabilidad, reclutamiento y su sitio web, en una sola factura. Nosotros hacemos la mudanza.',
                    'cta_label' => 'Elegir un paquete', 'cta_href' => '/es/pricing', 'second_label' => 'Chequeo gratuito', 'second_href' => '/health-check'],
                ['type' => 'answer', 'question' => '¿Por qué las corredurías eligen RightAlly?', 'answer' => 'Porque reemplaza muchas herramientas separadas con un solo sistema, un solo inicio de sesión y una sola factura; es fácil para agentes de todas las edades; los datos de los clientes se mantienen seguros y separados; y RightAlly traslada a su gente, sus agentes y sus operaciones por usted.'],
                ['type' => 'modules', 'heading' => 'Lo que recibe su correduría', 'audience' => 'brokerage'],
                ['type' => 'savings', 'heading' => '¿Cuánto paga hoy?', 'text' => 'Sume lo que su correduría paga hoy por herramientas separadas.', 'audience' => 'brokerage'],
                ['type' => 'pricing', 'heading' => 'Paquetes para corredurías', 'note' => 'Precios en dólares estadounidenses, mensuales o anuales. ¿Necesita más mensajes de texto o un módulo adicional? Agréguelo por separado.', 'audience' => 'brokerage'],
                $startEs,
            ], true],
            ['es', 'for-agents', 'RightAlly para agentes: su negocio en su teléfono', 'Para agentes: contactos, seguimiento, operaciones y comisiones en su teléfono, con IA que redacta y le recuerda.', [
                ['type' => 'hero', 'heading' => 'Su negocio, en su teléfono', 'text' => 'Vea a quién llamar hoy, haga seguimiento con un toque, siga sus operaciones y sepa cuánto le van a pagar.', 'cta_label' => 'Empezar', 'cta_href' => '/start',
                    'second_label' => '', 'second_href' => ''],
                ['type' => 'modules', 'heading' => 'Lo que recibe', 'audience' => 'agent'],
                ['type' => 'savings', 'heading' => '¿Cuánto paga hoy?', 'text' => 'Sume las herramientas que hoy paga usted mismo.', 'audience' => 'agent'],
                ['type' => 'pricing', 'heading' => 'Planes para agentes', 'note' => 'Precios en dólares estadounidenses, mensuales o anuales.', 'audience' => 'agent'],
                ['type' => 'answer', 'question' => '¿Un agente independiente puede usar RightAlly?', 'answer' => 'Sí. Los agentes independientes pueden registrarse en su propio plan, usar el CRM, el seguimiento, el sitio web y las herramientas de operaciones en su teléfono, y conservar sus contactos si cambian de correduría. Las corredurías también pueden dar acceso a todos sus agentes como parte de su paquete.'],
                $startEs,
            ], true],
            ['es', 'security', 'Seguridad y privacidad en RightAlly', 'Cómo RightAlly protege sus datos: bases de datos separadas, cifrado, inicio de sesión en dos pasos, registros de auditoría y consentimiento.', [
                ['type' => 'hero', 'heading' => 'Sus datos son suyos', 'text' => 'Los datos de cada correduría se mantienen separados, cifrados y nunca se venden.', 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => ''],
                ['type' => 'features', 'heading' => 'Cómo los protegemos', 'items' => [
                    ['title' => 'Inicio de sesión seguro', 'text' => 'Inicio de sesión con huella o rostro (llaves de acceso) y un segundo paso para todos.'],
                    ['title' => 'Registro de auditoría', 'text' => 'Se registra quién hizo qué y cuándo, y se puede exportar.'],
                    ['title' => 'Soporte pide permiso', 'text' => 'Las corredurías pueden exigir que el soporte de RightAlly pida permiso antes de ver su cuenta, y ver cada visita.'],
                    ['title' => 'Consentimiento para textos', 'text' => 'Solo se envían textos a quienes aceptaron, entre las 8 a. m. y las 9 p. m. de su hora local.'],
                ]],
                ['type' => 'faq', 'heading' => '', 'items' => [
                    ['q' => '¿Puedo exportar todos mis datos?', 'a' => 'Sí. El dueño puede descargar en cualquier momento todo lo que la correduría tiene en RightAlly.'],
                ]],
                $startEs,
            ], true],
        ];
    }
}
