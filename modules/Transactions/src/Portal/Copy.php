<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use Carbon\CarbonImmutable;

/**
 * Every word the deal portal and its emails show clients and the other side, in English and Spanish
 * (specs/ai/02, §22). One place, so the two languages never drift apart; the page receives the dictionary.
 */
final class Copy
{
    public const LOCALES = ['en' => 'English', 'es' => 'Español'];

    private const EN = [
        'title' => 'Your home',
        'signin_title' => 'See your deal',
        'signin_intro' => 'Enter the email address your agent has for you. We will email you a sign-in link; there is no password.',
        'signin_email' => 'Your email',
        'signin_send' => 'Email me a link',
        'signin_sent' => 'If that email is on a deal, a sign-in link is on its way. It works for 30 minutes.',
        'signin_expired' => 'That sign-in link has been used or has expired. Ask for a new one below.',
        'pick_deal' => 'Choose a deal',
        'sign_out' => 'Sign out',
        'fraud' => 'Wire fraud warning: we will never email or text you wiring instructions, and they never change by email. Before sending any money, call your agent or the title company on a phone number you already know.',
        'progress' => 'Where things are',
        'next' => 'What happens next',
        'nothing_next' => 'Nothing needed from you right now.',
        'dates' => 'Key dates',
        'no_dates' => 'No dates yet.',
        'done' => 'Done',
        'today' => 'Today',
        'upload_for' => 'Upload: :what',
        'upload' => 'Upload',
        'upload_any' => 'Send your agent a document',
        'uploaded' => 'Thank you. Your agent has it.',
        'documents' => 'Your documents',
        'no_documents' => 'Your agent has not shared any documents yet.',
        'ask' => 'Ask a question',
        'ask_hint' => 'For example: When is my inspection? What is an option period?',
        'ask_send' => 'Ask',
        'ask_agent' => 'Ask my agent instead',
        'sent_to_agent' => 'Your agent has your question and will reply by email.',
        'from' => 'From: :sources',
        'agent_replied' => 'Your agent replied',
        'your_agent' => 'Your agent',
        'settings' => 'Language and text messages',
        'language' => 'Language',
        'phone' => 'Mobile number',
        'sms_consent' => 'Text me updates about this deal (between 8am and 9pm). Message and data rates may apply. Reply STOP to stop.',
        'save' => 'Save',
        'saved' => 'Saved.',
        'checkin_title' => 'How did we do?',
        'checkin_intro' => 'Your home is closed. Tell your agent how it went.',
        'rating' => 'Your rating (1 to 5)',
        'comment' => 'Anything you would like to say',
        'may_quote' => 'My agent may share my words as a review',
        'thanks' => 'Thank you for telling us.',
        'stage.contract' => 'Offer accepted',
        'stage.inspection' => 'Inspections',
        'stage.financing' => 'Loan and appraisal',
        'stage.closing' => 'Getting ready to close',
        'stage.closed' => 'Closed',
        'stage_note.contract' => 'Your contract is signed. The clock has started on the deadlines below.',
        'stage_note.inspection' => 'This is the time for inspections and any repair requests.',
        'stage_note.financing' => 'The lender is working on the loan and the appraisal.',
        'stage_note.closing' => 'Final paperwork, the walk-through and closing day are close.',
        'stage_note.closed' => 'Congratulations, it is done.',
        'mail.invite_subject' => 'Follow your home at :street',
        'mail.invite_intro' => ':agent has set up a page where you can follow :street: where things are, key dates, documents and questions.',
        'mail.link_subject' => 'Your sign-in link',
        'mail.link_intro' => 'Here is your sign-in link for :street. It works once.',
        'mail.open' => 'Open my deal',
        'mail.milestone_subject' => ':street: :stage',
        'mail.weekly_subject' => 'This week on :street',
        'mail.weekly_intro' => 'Here is where things are this week.',
        'mail.coming_up' => 'Coming up:',
        'mail.waiting_on_you' => 'Waiting on you:',
        'mail.reply_subject' => 'Your agent replied about :street',
        'mail.checkin_subject' => 'How did your move go?',
        'mail.question_routed' => 'For anything about money or wiring, your agent will answer you directly.',
        'concierge.money' => 'Questions about money, payments or wiring go straight to your agent, who will reply to you directly. Never send money based on an email; call first.',
        'concierge.legal' => 'That needs your agent (or an attorney). I have passed your question to your agent.',
        'concierge.unknown' => 'I am not sure from what I can see, so I have passed your question to your agent.',
        'source.dates' => 'key dates',
        'source.progress' => 'progress',
        'source.requests' => 'what is waiting on you',
        'source.documents' => 'shared documents',
        'source.glossary' => 'real estate terms',
        'source.deal' => 'the deal details',
        'verify_title' => 'Confirm it is you',
        'verify_intro' => 'For your security, type the email address this link was sent to. We will send you a 6-digit code.',
        'verify_email' => 'Email address',
        'verify_send_email' => 'Email me a code',
        'verify_send_sms' => 'Text a code to :phone',
        'verify_wrong_email' => 'That is not the address this link was sent to.',
        'code_title' => 'Enter your code',
        'code_intro' => 'We sent a 6-digit code to :where. It works for 10 minutes.',
        'code_label' => '6-digit code',
        'code_remember' => 'Remember this device for 30 days',
        'code_submit' => 'Sign in',
        'code_wrong' => 'That code is not right. Tries left: :left.',
        'code_expired' => 'That code has expired. Ask for a new one.',
        'code_locked' => 'Too many wrong tries. For your security this link no longer works; ask for a new one.',
        'code_again' => 'Send a new code',
        'notme_title' => 'Sign out everywhere?',
        'notme_intro' => 'This signs out every device on this deal and tells your agent. You can sign in again with a new link.',
        'notme_confirm' => 'Sign out everywhere',
        'notme_done' => 'Done. Every device is signed out and your agent has been told.',
        'mail.code_subject' => 'Your sign-in code: :code',
        'mail.code_intro' => 'Your sign-in code for :street is :code. It works for 10 minutes. If you did not ask for it, ignore this email: nobody can sign in without the code.',
        'mail.new_device_subject' => 'New sign-in to your deal',
        'mail.new_device_intro' => 'Someone signed in to :street on :device at :time. If this was you, there is nothing to do. If not, open the link below to sign out everywhere.',
        'mail.not_me' => 'This was not me',
        'sms.code' => ':code is your sign-in code for :street. It works for 10 minutes. Never share it.',
        'offers' => 'The offers',
        'offers_note' => 'Buyers\' names are not shown. Your agent will talk you through the choice; nothing is decided here.',
        'offers_price' => 'Price',
        'offers_net' => 'Net to you',
        'offers_financing' => 'Financing',
        'offers_closing' => 'Closing',
        'offers_likely' => 'Likely to close',
        'grade.Strong' => 'High',
        'grade.Fair' => 'Medium',
        'grade.Weaker' => 'Lower',
        'repairs' => 'Repair request',
        'repairs_note' => 'What your agent plans to ask the seller for after the inspection.',
        'repairs_repair' => 'Repair',
        'repairs_credit' => 'Credit',
        'mail.calendar_subject' => 'Key dates for :street, in your calendar',
        'mail.calendar_intro' => 'Add the key dates for this home to your calendar (Google, Outlook or Apple). They update by themselves when dates change.',
        'mail.calendar_add' => 'Add to my calendar',
        'calendar' => 'Add these dates to my calendar',
    ];

    private const ES = [
        'title' => 'Su casa',
        'signin_title' => 'Vea su transacción',
        'signin_intro' => 'Escriba el correo electrónico que tiene su agente. Le enviaremos un enlace para entrar; no hace falta contraseña.',
        'signin_email' => 'Su correo electrónico',
        'signin_send' => 'Enviarme un enlace',
        'signin_sent' => 'Si ese correo está en una transacción, le llegará un enlace para entrar. Funciona durante 30 minutos.',
        'signin_expired' => 'Ese enlace ya se usó o venció. Pida uno nuevo abajo.',
        'pick_deal' => 'Elija una transacción',
        'sign_out' => 'Salir',
        'fraud' => 'Aviso de fraude: nunca le enviaremos instrucciones de transferencia por correo ni por mensaje de texto, y nunca cambian por correo. Antes de enviar dinero, llame a su agente o a la compañía de título a un número que ya conozca.',
        'progress' => 'En qué va todo',
        'next' => 'Lo que sigue',
        'nothing_next' => 'Por ahora no necesitamos nada de usted.',
        'dates' => 'Fechas clave',
        'no_dates' => 'Todavía no hay fechas.',
        'done' => 'Listo',
        'today' => 'Hoy',
        'upload_for' => 'Subir: :what',
        'upload' => 'Subir',
        'upload_any' => 'Enviar un documento a su agente',
        'uploaded' => 'Gracias. Su agente ya lo tiene.',
        'documents' => 'Sus documentos',
        'no_documents' => 'Su agente todavía no ha compartido documentos.',
        'ask' => 'Haga una pregunta',
        'ask_hint' => 'Por ejemplo: ¿Cuándo es mi inspección? ¿Qué es el periodo de opción?',
        'ask_send' => 'Preguntar',
        'ask_agent' => 'Mejor preguntarle a mi agente',
        'sent_to_agent' => 'Su agente recibió su pregunta y le responderá por correo.',
        'from' => 'Fuente: :sources',
        'agent_replied' => 'Su agente respondió',
        'your_agent' => 'Su agente',
        'settings' => 'Idioma y mensajes de texto',
        'language' => 'Idioma',
        'phone' => 'Número de celular',
        'sms_consent' => 'Envíenme mensajes de texto sobre esta transacción (entre 8am y 9pm). Pueden aplicar tarifas de mensajes y datos. Responda STOP para dejar de recibirlos.',
        'save' => 'Guardar',
        'saved' => 'Guardado.',
        'checkin_title' => '¿Cómo lo hicimos?',
        'checkin_intro' => 'Su casa ya cerró. Cuéntele a su agente cómo le fue.',
        'rating' => 'Su calificación (1 a 5)',
        'comment' => 'Lo que quiera decirnos',
        'may_quote' => 'Mi agente puede compartir mis palabras como reseña',
        'thanks' => 'Gracias por contarnos.',
        'stage.contract' => 'Oferta aceptada',
        'stage.inspection' => 'Inspecciones',
        'stage.financing' => 'Préstamo y avalúo',
        'stage.closing' => 'Preparando el cierre',
        'stage.closed' => 'Cerrada',
        'stage_note.contract' => 'Su contrato está firmado. Ya corren los plazos de abajo.',
        'stage_note.inspection' => 'Es el momento de las inspecciones y de pedir reparaciones.',
        'stage_note.financing' => 'El prestamista está trabajando en el préstamo y el avalúo.',
        'stage_note.closing' => 'Se acercan los últimos documentos, la revisión final y el día del cierre.',
        'stage_note.closed' => 'Felicidades, ya está hecho.',
        'mail.invite_subject' => 'Siga su casa en :street',
        'mail.invite_intro' => ':agent preparó una página donde puede seguir :street: en qué va todo, fechas clave, documentos y preguntas.',
        'mail.link_subject' => 'Su enlace para entrar',
        'mail.link_intro' => 'Este es su enlace para entrar a :street. Funciona una sola vez.',
        'mail.open' => 'Abrir mi transacción',
        'mail.milestone_subject' => ':street: :stage',
        'mail.weekly_subject' => 'Esta semana en :street',
        'mail.weekly_intro' => 'Así van las cosas esta semana.',
        'mail.coming_up' => 'Lo que viene:',
        'mail.waiting_on_you' => 'Pendiente de usted:',
        'mail.reply_subject' => 'Su agente respondió sobre :street',
        'mail.checkin_subject' => '¿Cómo le fue con su mudanza?',
        'mail.question_routed' => 'Para todo lo relacionado con dinero o transferencias, su agente le responderá directamente.',
        'concierge.money' => 'Las preguntas sobre dinero, pagos o transferencias van directo a su agente, quien le responderá. Nunca envíe dinero por lo que diga un correo; llame primero.',
        'concierge.legal' => 'Eso lo debe ver su agente (o un abogado). Le pasé su pregunta a su agente.',
        'concierge.unknown' => 'No estoy seguro con lo que puedo ver, así que le pasé su pregunta a su agente.',
        'source.dates' => 'fechas clave',
        'source.progress' => 'el avance',
        'source.requests' => 'lo pendiente de usted',
        'source.documents' => 'documentos compartidos',
        'source.glossary' => 'términos de bienes raíces',
        'source.deal' => 'los datos de la transacción',
        'verify_title' => 'Confirme que es usted',
        'verify_intro' => 'Por su seguridad, escriba el correo al que se envió este enlace. Le enviaremos un código de 6 dígitos.',
        'verify_email' => 'Correo electrónico',
        'verify_send_email' => 'Enviarme un código por correo',
        'verify_send_sms' => 'Enviar un código por mensaje a :phone',
        'verify_wrong_email' => 'Ese no es el correo al que se envió este enlace.',
        'code_title' => 'Escriba su código',
        'code_intro' => 'Enviamos un código de 6 dígitos a :where. Funciona durante 10 minutos.',
        'code_label' => 'Código de 6 dígitos',
        'code_remember' => 'Recordar este dispositivo por 30 días',
        'code_submit' => 'Entrar',
        'code_wrong' => 'Ese código no es correcto. Intentos restantes: :left.',
        'code_expired' => 'Ese código venció. Pida uno nuevo.',
        'code_locked' => 'Demasiados intentos incorrectos. Por su seguridad este enlace ya no funciona; pida uno nuevo.',
        'code_again' => 'Enviar un código nuevo',
        'notme_title' => '¿Cerrar la sesión en todas partes?',
        'notme_intro' => 'Esto cierra la sesión en todos los dispositivos de esta transacción y le avisa a su agente. Puede volver a entrar con un enlace nuevo.',
        'notme_confirm' => 'Cerrar la sesión en todas partes',
        'notme_done' => 'Listo. Se cerró la sesión en todos los dispositivos y se le avisó a su agente.',
        'mail.code_subject' => 'Su código para entrar: :code',
        'mail.code_intro' => 'Su código para entrar a :street es :code. Funciona durante 10 minutos. Si usted no lo pidió, ignore este correo: nadie puede entrar sin el código.',
        'mail.new_device_subject' => 'Nuevo inicio de sesión en su transacción',
        'mail.new_device_intro' => 'Alguien entró a :street desde :device el :time. Si fue usted, no tiene que hacer nada. Si no, abra el enlace de abajo para cerrar la sesión en todas partes.',
        'mail.not_me' => 'No fui yo',
        'sms.code' => ':code es su código para entrar a :street. Funciona 10 minutos. No lo comparta.',
        'offers' => 'Las ofertas',
        'offers_note' => 'No se muestran los nombres de los compradores. Su agente le explicará las opciones; aquí no se decide nada.',
        'offers_price' => 'Precio',
        'offers_net' => 'Le queda a usted',
        'offers_financing' => 'Financiamiento',
        'offers_closing' => 'Cierre',
        'offers_likely' => 'Probabilidad de cerrar',
        'grade.Strong' => 'Alta',
        'grade.Fair' => 'Media',
        'grade.Weaker' => 'Baja',
        'repairs' => 'Solicitud de reparaciones',
        'repairs_note' => 'Lo que su agente planea pedirle al vendedor después de la inspección.',
        'repairs_repair' => 'Reparación',
        'repairs_credit' => 'Crédito',
        'mail.calendar_subject' => 'Fechas clave de :street, en su calendario',
        'mail.calendar_intro' => 'Agregue las fechas clave de esta casa a su calendario (Google, Outlook o Apple). Se actualizan solas cuando cambian.',
        'mail.calendar_add' => 'Agregar a mi calendario',
        'calendar' => 'Agregar estas fechas a mi calendario',
    ];

    public static function locale(?string $locale): string
    {
        return array_key_exists((string) $locale, self::LOCALES) ? (string) $locale : 'en';
    }

    /**
     * @param  array<string, string>  $replace
     */
    public static function t(string $locale, string $key, array $replace = []): string
    {
        $text = (self::locale($locale) === 'es' ? self::ES : self::EN)[$key] ?? self::EN[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $text = str_replace(':'.$k, $v, $text);
        }

        return $text;
    }

    /**
     * The whole dictionary, for the page.
     *
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        return self::locale($locale) === 'es' ? self::ES + self::EN : self::EN;
    }

    /**
     * Both languages have every key (checked by the tests).
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        return array_merge(array_diff(array_keys(self::EN), array_keys(self::ES)), array_diff(array_keys(self::ES), array_keys(self::EN)));
    }

    /** The timeline's own date names in Spanish; other names (typed by the agent) are shown as written. */
    private const LABELS_ES = [
        'closing' => 'Cierre', 'closing date' => 'Cierre', 'earnest money due' => 'Entrega del depósito de garantía', 'attorney review ends' => 'Termina la revisión del abogado',
        'option period ends' => 'Termina el periodo de opción', 'inspection deadline' => 'Fecha límite de inspección', 'financing deadline' => 'Fecha límite del financiamiento',
        'appraisal deadline' => 'Fecha límite del avalúo', 'final walk-through' => 'Revisión final de la casa',
    ];

    public static function label(string $locale, string $label): string
    {
        return self::locale($locale) === 'es' ? (self::LABELS_ES[mb_strtolower(trim($label))] ?? $label) : $label;
    }

    /** A date in the client's language, e.g. "Friday, July 24" or "viernes, 24 de julio". */
    public static function date(string $locale, string $ymd): string
    {
        $d = CarbonImmutable::parse($ymd);
        if (self::locale($locale) !== 'es') {
            return $d->format('l, F j');
        }
        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $days[(int) $d->format('w')].', '.$d->format('j').' de '.$months[(int) $d->format('n') - 1];
    }
}
