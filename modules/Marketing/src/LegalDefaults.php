<?php

declare(strict_types=1);

namespace Modules\Marketing;

/**
 * SITE1: RightAlly's default privacy policy and terms of use, in plain English and Spanish. They are shown at /privacy
 * and /terms (and /es/...) until HQ edits them in HQ › RightAlly website, which then shows HQ's version. They are
 * starting points written for a US real estate software service and must be reviewed by counsel before launch.
 */
final class LegalDefaults
{
    public const SLUGS = ['privacy', 'terms'];

    public const UPDATED = '2026-11-21';

    /**
     * The page for a language and address, as website blocks; null for any other address.
     *
     * @return array{title: string, description: string, blocks: list<array<string, mixed>>}|null
     */
    public static function page(string $locale, string $slug): ?array
    {
        $es = $locale === 'es';
        $pages = $es ? self::spanish() : self::english();
        if (! isset($pages[$slug])) {
            return null;
        }
        [$title, $description, $intro, $sections] = $pages[$slug];
        $blocks = [['type' => 'hero', 'heading' => $title, 'text' => $intro, 'cta_label' => '', 'cta_href' => '', 'second_label' => '', 'second_href' => '']];
        foreach ($sections as [$heading, $body]) {
            $blocks[] = ['type' => 'text', 'heading' => $heading, 'body' => $body];
        }

        return ['title' => $title.' | RightAlly', 'description' => $description, 'blocks' => $blocks];
    }

    /**
     * The same pages for marketing:starter, so HQ can edit them like any other page.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: list<array<string, mixed>>, 5: bool}>
     */
    public static function starterPages(): array
    {
        $out = [];
        foreach (['en', 'es'] as $locale) {
            foreach (self::SLUGS as $slug) {
                $p = self::page($locale, $slug);
                if ($p !== null) {
                    $out[] = [$locale, $slug, $p['title'], $p['description'], $p['blocks'], true];
                }
            }
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string}>}> */
    private static function english(): array
    {
        $updated = 'Last updated '.date('F j, Y', (int) strtotime(self::UPDATED)).'.';

        return [
            'privacy' => ['Privacy policy', 'How RightAlly collects, uses, shares and protects personal information, and the choices you have.',
                $updated.' This policy explains what personal information RightAlly collects, why, who we share it with and the choices you have.', [
                    ['Who we are', "RightAlly provides software for real estate brokerages and agents in the United States: websites, CRM, transactions and e-signature, commissions, accounting and AI assistance. In this policy, \"RightAlly\", \"we\" and \"us\" mean the company that runs rightally.app and the RightAlly service.\n\nWhen a brokerage or agent uses RightAlly to keep information about their own clients and leads, the brokerage or agent decides how that information is used and we process it for them. Questions about that information should go to the brokerage or agent first."],
                    ['What we collect', "Account details: name, email, phone, brokerage, license numbers and sign-in records.\n\nBilling details: what you buy and when. Card details are handled by our payment provider; we do not store full card numbers.\n\nContent you add: contacts, notes, deals, documents, messages, listings and website content.\n\nUse of the service: pages viewed, features used, device and browser type, IP address and error logs, kept to run, secure and improve the service.\n\nWebsite visitors: when someone uses a brokerage website on RightAlly, the brokerage may collect what they type in forms and the homes they look at."],
                    ['How we use it', 'To provide and secure the service, to bill for it, to answer support requests, to send service notices, to prevent fraud and abuse, to meet legal duties, and to improve RightAlly. We use AI features to help you work; your content is not used to train public AI models.'],
                    ['Texts, calls and email', 'RightAlly only sends automated texts to people who gave written consent, only between 8am and 9pm in their time zone, and anyone can reply STOP to stop. Marketing emails include an unsubscribe link. Brokerages and agents are responsible for the consent of the people they contact.'],
                    ['Who we share it with', "Service providers who help us run RightAlly (hosting, email, texting, payments, maps, AI and support tools), under contracts that limit their use of the information. MLS and data partners when you connect them. Authorities when the law requires it. A buyer of our business, under this policy.\n\nWe do not sell personal information, and a brokerage's leads are never sold or shared with another brokerage."],
                    ['How we protect it', "Each brokerage's data is kept separate. Data is encrypted in transit and sensitive fields are encrypted at rest. Sign-in uses a second step, and staff access is limited, logged and can require the brokerage's permission."],
                    ['How long we keep it', 'For as long as the account is open and as needed afterwards to meet legal, tax and record-keeping duties (for example, real estate transaction records). Owners can export their data at any time and ask us to delete it when they leave.'],
                    ['Your choices and rights', 'You can see, correct, export or delete your information, and opt out of marketing. Residents of California and other states with privacy laws have the rights those laws give, including to know, delete, correct and opt out of sale or sharing (we do not sell or share for targeted advertising). We honor Global Privacy Control signals. To make a request, use the privacy form on the website you used or email us; we will verify the request and answer within the time the law sets.'],
                    ['Children', 'RightAlly is for adults doing business. We do not knowingly collect information from children under 13.'],
                    ['Changes and contact', 'We will post any changes here and, for important changes, tell account owners by email. Questions: contact RightAlly support from your account or through the contact details on rightally.app.'],
                ]],
            'terms' => ['Terms of use', 'The terms for using rightally.app and the RightAlly service.',
                $updated.' These terms apply when you visit rightally.app or use the RightAlly service. By signing up or using the service you agree to them.', [
                    ['The service', 'RightAlly is software for real estate brokerages and agents. We may improve, change or retire features; we will tell account owners ahead of changes that take away something they pay for.'],
                    ['Accounts', 'You must give accurate details, keep your sign-in safe and use two-step sign-in where it is required. The brokerage owner controls the brokerage account and who can use it, and is responsible for what users do in it.'],
                    ['Payment', 'Prices are shown on the pricing page. Plans renew each month or year until cancelled. Extras such as additional MLS boards, texts and AI credits are billed as shown in the app. Taxes may apply. If a payment fails we may limit the account after a grace period. You can cancel from the billing page; you keep access to the end of the period you paid for. Fees already paid are not refunded unless the law requires it.'],
                    ['Your content and data', 'You own your content. You give us permission to host, process and show it only to provide the service to you. You are responsible for having the right to the content you add, including the consent of the people you text, call or email. Owners can export their data at any time.'],
                    ['MLS and listing data', "Listing data comes from MLS boards under their rules. A brokerage must be a member in good standing and sign each board's agreement before its listings go live. Required notices are shown automatically and cannot be removed. Sample listings are clearly marked and are not real homes for sale."],
                    ['Fair use', 'You agree not to break the law, send spam or messages without consent, discriminate in violation of fair housing laws, try to get into other accounts, copy or resell the service, or overload or attack it.'],
                    ['AI features', 'AI suggestions can be wrong. Check them before you rely on them or send them to anyone. You are responsible for what you send and sign.'],
                    ['Our responsibility', 'The service is provided as it is. We work to keep it available and secure but do not promise it will be error-free or always available. To the extent the law allows, RightAlly is not liable for indirect or lost-profit damages, and our total liability is limited to the fees you paid in the 12 months before the claim.'],
                    ['Ending the account', 'You can cancel at any time. We may suspend or close an account that breaks these terms or the law, telling the owner where we can. Before closing, owners can export their data.'],
                    ['General', 'These terms are governed by the laws of the State of Florida, United States, without regard to its conflict-of-laws rules. If part of the terms cannot be enforced, the rest still applies. We will post changes here and tell account owners about important ones.'],
                ]],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string}>}> */
    private static function spanish(): array
    {
        $updated = 'Última actualización: '.self::UPDATED.'.';

        return [
            'privacy' => ['Política de privacidad', 'Cómo RightAlly recopila, usa, comparte y protege la información personal, y sus opciones.',
                $updated.' Esta política explica qué información personal recopila RightAlly, para qué, con quién la compartimos y qué opciones tiene usted.', [
                    ['Quiénes somos', 'RightAlly ofrece software para corredurías y agentes de bienes raíces en los Estados Unidos. Cuando una correduría o un agente guarda en RightAlly datos de sus propios clientes, la correduría o el agente decide cómo se usan y nosotros los procesamos por su cuenta.'],
                    ['Qué recopilamos', 'Datos de la cuenta (nombre, correo, teléfono, correduría, licencia), datos de facturación (el pago con tarjeta lo procesa nuestro proveedor), el contenido que usted agrega y datos de uso del servicio (páginas, funciones, dispositivo, dirección IP y registros de errores).'],
                    ['Cómo lo usamos', 'Para prestar y proteger el servicio, cobrarlo, dar soporte, enviar avisos, prevenir fraudes, cumplir la ley y mejorar RightAlly. Su contenido no se usa para entrenar modelos públicos de IA.'],
                    ['Mensajes de texto, llamadas y correo', 'Solo enviamos textos automáticos a personas que dieron su consentimiento por escrito, entre las 8am y las 9pm de su zona horaria, y cualquiera puede responder STOP para dejar de recibirlos.'],
                    ['Con quién lo compartimos', 'Con proveedores que nos ayudan a operar el servicio, con los MLS y socios de datos que usted conecte, y con autoridades cuando la ley lo exige. No vendemos información personal y los prospectos de una correduría nunca se venden ni se comparten con otra.'],
                    ['Sus derechos', 'Puede ver, corregir, exportar o borrar su información y dejar de recibir publicidad. Respetamos las señales de Global Privacy Control. Para hacer una solicitud, use el formulario de privacidad del sitio o escríbanos.'],
                    ['Cambios y contacto', 'Publicaremos los cambios aquí y avisaremos a los dueños de cuentas sobre los importantes. Preguntas: contacte al soporte de RightAlly.'],
                ]],
            'terms' => ['Términos de uso', 'Los términos para usar rightally.app y el servicio RightAlly.',
                $updated.' Estos términos se aplican cuando usted visita rightally.app o usa RightAlly. Al registrarse o usar el servicio, los acepta.', [
                    ['El servicio', 'RightAlly es software para corredurías y agentes de bienes raíces. Podemos mejorar o cambiar funciones y avisaremos antes de quitar algo que usted paga.'],
                    ['Cuentas y pagos', 'Usted debe dar datos correctos y proteger su acceso. Los planes se renuevan cada mes o año hasta que los cancele; los extras se cobran como se muestra en la aplicación. Puede cancelar desde la página de facturación.'],
                    ['Su contenido', 'Usted es dueño de su contenido y puede exportarlo cuando quiera. Usted es responsable del consentimiento de las personas a quienes escribe o llama.'],
                    ['Datos del MLS', 'Los listados vienen de los MLS según sus reglas. Los avisos obligatorios se muestran automáticamente y no se pueden quitar. Los listados de muestra están marcados y no son casas reales en venta.'],
                    ['Uso correcto', 'No se permite violar la ley, enviar mensajes sin consentimiento, discriminar en contra de las leyes de vivienda justa ni atacar el servicio.'],
                    ['Responsabilidad', 'El servicio se ofrece tal como está. En la medida que la ley lo permita, la responsabilidad total de RightAlly se limita a lo que usted pagó en los 12 meses anteriores al reclamo. Rigen las leyes del Estado de Florida, EE. UU.'],
                ]],
        ];
    }
}
