<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

/**
 * Every word of the Health Check in English and Spanish (specs/billing/06). A test fails if a key is missing in either
 * language. Product names (Follow Up Boss, dotloop…) stay as they are.
 */
final class HealthCopy
{
    public const LANGS = ['en', 'es'];

    private const EN = [
        'skip' => 'Skip to the main content', 'section' => 'Brokerage Health Check',
        'footer1' => 'RightAlly is one platform for brokerages: CRM, websites, deals and compliance, e-signature, commissions and agent payouts.',
        'footer2' => 'Your answers are used only to write your report. We do not sell or share them. Reports are deleted after 180 days, or sooner if you delete yours.',
        'meta_title' => 'Free Brokerage Health Check | RightAlly',
        'meta_desc' => 'Free three-minute health check for real estate brokerages: tool costs, speed to leads, file compliance, agent payouts and a website check for Google and AI answers. No sales call.',
        'h1' => 'Free Brokerage Health Check',
        'lead' => 'Three minutes. No card, no sales call. See what your tools really cost per agent, what to fix first, how your website does on Google and AI answers, and what RightAlly would look like in your colours.',
        'deleted' => 'Your report has been deleted.',
        'p1t' => 'Plain-English scores', 'p1d' => 'Six areas, each with one thing to fix.', 'p2t' => 'Honest savings', 'p2d' => 'Only what RightAlly really replaces comes off.',
        'p3t' => 'Website check', 'p3d' => 'Ready for Google and AI answers?', 'p4t' => 'Your brand', 'p4d' => 'See RightAlly in your colours, at once.',
        'check_these' => 'Please check these:', 's1' => '1. About your brokerage', 'brokerage' => 'Brokerage name', 'agents' => 'Number of agents', 'your_name' => 'Your name',
        'your_email' => 'Your email (we send the report here)', 'state' => 'State (two letters, optional)', 's2' => '2. How you work today', 'who_q' => 'Who pays for agents\' software?',
        's3' => '3. The software you pay for', 's3_help' => 'Add what you pay each month. Tick "per agent" if the price is for each agent. Leave rows empty if you don\'t need them.',
        'tool_n' => 'Tool :n', 'choose' => 'Choose…', 'monthly_cost' => 'Monthly cost ($)', 'per_agent' => 'per agent', 'other_name' => 'If you chose "Something else", what is it? (optional)',
        's4' => '4. Your website and brand (optional)', 'website' => 'Website address', 'website_ok' => 'I manage this website, and RightAlly may check its public home page.',
        'brand_color' => 'Brand colour', 'logo' => 'Logo (PNG, JPG or WebP, up to 1 MB)', 's5' => '5. Your report',
        'contact_ok' => 'I\'d like someone from RightAlly to call or email me about my report.', 'contact_help' => 'Without this tick we only email you the link to your report.',
        'submit' => 'See my report', 'faq_title' => 'Questions', 'other_lang' => 'En español', 'other_lang_href' => '/health-check/es',
        'faq1q' => 'What is the RightAlly Brokerage Health Check?', 'faq1a' => 'A free three-minute check for real estate brokerages. You answer a few questions and list the software you pay for. You get a report with a score for each area, what to fix first, a check of your website for Google and AI answers, a savings comparison and a preview of RightAlly in your colours.',
        'faq2q' => 'Is it free? Do I have to talk to sales?', 'faq2a' => 'It is free, with no card and no sales call. Your report opens as soon as you finish. We only call you if you tick the box asking us to.',
        'faq3q' => 'What does the website check look at?', 'faq3a' => 'Only your public home page and three small files (robots.txt, sitemap.xml and llms.txt). It checks security, phones, search descriptions, real estate business markup, an FAQ that AI assistants can quote, home search, Spanish pages, the Equal Housing Opportunity notice, a privacy policy and contact details. It does not download pictures or other pages.',
        'faq4q' => 'How is the savings figure worked out?', 'faq4a' => 'From the prices you enter. Tools RightAlly fully replaces come off; tools it only partly covers, and things like MLS dues and paid leads, stay in the total, so the figure is never more than RightAlly can deliver. If RightAlly would cost you more, the report says so.',
        'faq5q' => 'Who sees my answers?', 'faq5a' => 'You, through a private link we email you, and the RightAlly team. We do not sell or share them. You can delete your report at any time, and it is deleted automatically after 180 days.',
        // Questions and answers
        'q.lead_reply' => 'How fast does a new online lead usually hear from your brokerage?', 'a.lead_reply.minutes' => 'Within 5 minutes', 'a.lead_reply.hour' => 'Within an hour',
        'a.lead_reply.day' => 'The same day', 'a.lead_reply.later' => 'Later than that', 'a.lead_reply.unknown' => 'I don\'t know',
        'q.file_check' => 'When are deal files checked for missing signatures and documents?', 'a.file_check.as_uploaded' => 'As each document comes in', 'a.file_check.before_closing' => 'Before closing',
        'a.file_check.after_closing' => 'After closing', 'a.file_check.rarely' => 'Rarely, or when there is a problem',
        'q.payout_days' => 'After closing, how long until agents are paid?', 'a.payout_days.same_day' => 'The same day', 'a.payout_days.few_days' => 'Within 2–3 days',
        'a.payout_days.week' => 'About a week', 'a.payout_days.longer' => 'Longer',
        'q.past_clients' => 'Do your agents keep in touch with past clients from one shared system?', 'a.past_clients.yes' => 'Yes, all of them', 'a.past_clients.some' => 'Some agents do',
        'a.past_clients.no' => 'No, each agent does their own thing',
        'w.brokerage' => 'The brokerage pays for agents\' software', 'w.agents' => 'Agents pay for their own software',
        // Areas
        'area.tools' => 'Tools and cost', 'area.tools.none' => 'You did not list any tools.', 'area.tools.found' => ':count tool(s) at about :cost per agent a month.',
        'area.tools.fix' => 'One sign-in for CRM, websites, deals, e-signature and commissions: fewer bills and less for agents to learn.',
        'area.lead_reply' => 'Speed to new leads', 'area.lead_reply.why' => 'Leads that wait go to the next agent who answers.',
        'area.lead_reply.fix' => 'RightAlly texts every new lead within a minute, day and night, and hands it to the right agent.',
        'area.file_check' => 'Deal file compliance', 'area.file_check.why' => 'Problems found after closing are the costly ones.',
        'area.file_check.fix' => 'Deal-IQ checks every document as it is uploaded: missing signatures, missing addenda, mismatched amounts.',
        'area.payout_days' => 'Paying agents', 'area.payout_days.why' => 'Slow payouts are one of the top reasons agents leave.',
        'area.payout_days.fix' => 'RightAlly works out each split and pays agents by bank transfer, the same or next day.',
        'area.past_clients' => 'Past clients and repeat business', 'area.past_clients.why' => 'Most repeat and referral business comes from past clients.',
        'area.past_clients.fix' => 'Every agent gets "Who to call today", with past-client reminders and anniversary tasks set up for them.',
        'area.website' => 'Website', 'area.website.none' => 'Not checked: no website address was given, or the check could not reach it.', 'area.website.found' => 'Your home page passed checks worth :score%.',
        'area.website.fix' => 'RightAlly websites come ready for Google and for AI answers, in English and Spanish.', 'you_said' => 'You said: :answer.',
        'st.good' => 'Good', 'st.fix' => 'Worth fixing', 'st.urgent' => 'Fix first', 'st.not_checked' => 'Not checked',
        // Report
        'r.title' => ':name: Health Check', 'r.hello' => 'Hello :name. Here is how your brokerage of :agents agent(s) does today, and the first thing to fix in each area.',
        'r.overall' => 'Overall score :score out of 100', 'r.scores' => 'Your scores', 'r.with' => 'With RightAlly:', 'r.website' => 'Website check', 'r.no_site' => 'You did not give a website address.',
        'r.checking' => 'Checking :url… this page refreshes by itself.', 'r.could_not' => 'We could not check :url: :message', 'r.passed' => ':url passed checks worth :score%. Things to fix are listed first.',
        'r.check' => 'Check', 'r.result' => 'Result', 'r.todo' => 'What to do', 'r.yes' => '✓ Yes', 'r.no' => '✗ No', 'r.savings' => 'Savings comparison',
        'r.nothing' => 'You did not list any software costs, so there is nothing to compare yet.', 'r.pay_now' => 'Today you pay about :total a month (:per per agent).',
        'r.software' => 'Software', 'r.monthly' => 'Monthly', 'r.with_ra' => 'With RightAlly', 'r.replaced' => 'Replaced.', 'r.partly' => 'Partly covered, kept in the total.', 'r.kept' => 'Kept.',
        'r.prices' => 'RightAlly prices are being updated; we\'ll email you the comparison.', 'r.after' => 'After switching', 'r.package' => 'RightAlly package', 'r.package_col' => 'Package',
        'r.agent_plans' => 'Agent plans', 'r.kept_tools' => 'Kept tools', 'r.new_total' => 'New monthly total', 'r.difference' => 'Difference', 'r.save' => 'Save :amount', 'r.costs_more' => 'Costs :amount more',
        'r.best' => 'Best fit', 'r.save_line' => 'With :package you would save about :month a month, or :year a year, and your agents sign in to one place.',
        'r.no_save_line' => 'RightAlly would not lower your software bill. The value would come from the fixes above: faster replies to leads, cleaner files and faster payouts.',
        'r.agents_pay' => 'Your agents would pay for their own RightAlly plan (:plan, :price a month at list price). Your brokerage earns about :earn a month from each agent\'s plan. That is not counted in the savings above.',
        'r.brokerage_pays' => 'Agent plans are counted at list price (:plan, :price a month per agent).', 'r.preview' => 'RightAlly in your colours',
        'r.preview_p' => 'See the screen your agents would open each morning, with your name, colour and logo.', 'r.see_preview' => 'See my preview', 'r.see_preview_other' => 'Ver en español',
        'r.start' => 'Ready to switch?', 'r.start_p' => 'Public prices, no setup fee, live the same day, with a 30-day go-live plan.', 'r.start_btn' => 'See prices and sign up',
        'r.report' => 'Your report', 'r.report_p' => 'This page has its own private link, which we emailed you. It works for 180 days.', 'r.delete' => 'Delete my report',
        'r.website_failed' => 'We could not check the site.',
        // Mail
        'm.subject' => 'Your Brokerage Health Check: :name', 'm.hello' => 'Hello :name,',
        'm.line1' => 'Your Health Check report for :name is ready: scores, what to fix first, the website check, the savings comparison and a preview of RightAlly in your colours.',
        'm.action' => 'Open my report', 'm.line2' => 'The link is private to you and works for 180 days. You can delete the report from the report page at any time.',
        'm.line3' => 'If you did not ask for this, ignore this email; nothing else will be sent.',
    ];

    private const ES = [
        'skip' => 'Ir al contenido principal', 'section' => 'Chequeo de su correduría',
        'footer1' => 'RightAlly es una sola plataforma para corredurías: CRM, sitios web, transacciones y cumplimiento, firma electrónica, comisiones y pagos a agentes.',
        'footer2' => 'Sus respuestas solo se usan para preparar su informe. No las vendemos ni las compartimos. Los informes se borran a los 180 días, o antes si usted borra el suyo.',
        'meta_title' => 'Chequeo gratuito de su correduría | RightAlly',
        'meta_desc' => 'Chequeo gratuito de tres minutos para corredurías de bienes raíces: costo de herramientas, rapidez con los prospectos, cumplimiento de expedientes, pagos a agentes y revisión del sitio web para Google y respuestas de IA. Sin llamada de ventas.',
        'h1' => 'Chequeo gratuito de su correduría',
        'lead' => 'Tres minutos. Sin tarjeta y sin llamada de ventas. Vea cuánto le cuestan de verdad sus herramientas por agente, qué arreglar primero, cómo le va a su sitio web en Google y en las respuestas de IA, y cómo se vería RightAlly con sus colores.',
        'deleted' => 'Su informe fue borrado.',
        'p1t' => 'Puntajes claros', 'p1d' => 'Seis áreas, cada una con una cosa para arreglar.', 'p2t' => 'Ahorros honestos', 'p2d' => 'Solo se descuenta lo que RightAlly reemplaza de verdad.',
        'p3t' => 'Revisión del sitio web', 'p3d' => '¿Listo para Google y para las respuestas de IA?', 'p4t' => 'Su marca', 'p4d' => 'Vea RightAlly con sus colores, al instante.',
        'check_these' => 'Revise lo siguiente:', 's1' => '1. Sobre su correduría', 'brokerage' => 'Nombre de la correduría', 'agents' => 'Número de agentes', 'your_name' => 'Su nombre',
        'your_email' => 'Su correo (le enviamos el informe aquí)', 'state' => 'Estado (dos letras, opcional)', 's2' => '2. Cómo trabajan hoy', 'who_q' => '¿Quién paga el software de los agentes?',
        's3' => '3. El software que pagan', 's3_help' => 'Anote lo que pagan cada mes. Marque "por agente" si el precio es por cada agente. Deje vacías las filas que no necesite.',
        'tool_n' => 'Herramienta :n', 'choose' => 'Elija…', 'monthly_cost' => 'Costo mensual ($)', 'per_agent' => 'por agente', 'other_name' => 'Si eligió "Otra cosa", ¿qué es? (opcional)',
        's4' => '4. Su sitio web y su marca (opcional)', 'website' => 'Dirección del sitio web', 'website_ok' => 'Yo administro este sitio web, y RightAlly puede revisar su página principal pública.',
        'brand_color' => 'Color de la marca', 'logo' => 'Logotipo (PNG, JPG o WebP, hasta 1 MB)', 's5' => '5. Su informe',
        'contact_ok' => 'Quiero que alguien de RightAlly me llame o me escriba sobre mi informe.', 'contact_help' => 'Sin esta marca, solo le enviamos por correo el enlace a su informe.',
        'submit' => 'Ver mi informe', 'faq_title' => 'Preguntas', 'other_lang' => 'In English', 'other_lang_href' => '/health-check',
        'faq1q' => '¿Qué es el chequeo de correduría de RightAlly?', 'faq1a' => 'Un chequeo gratuito de tres minutos para corredurías de bienes raíces. Responde unas preguntas y anota el software que paga. Recibe un informe con un puntaje por área, qué arreglar primero, una revisión de su sitio web para Google y respuestas de IA, una comparación de ahorros y una vista previa de RightAlly con sus colores.',
        'faq2q' => '¿Es gratis? ¿Tengo que hablar con ventas?', 'faq2a' => 'Es gratis, sin tarjeta y sin llamada de ventas. Su informe se abre en cuanto termina. Solo le llamamos si marca la casilla pidiéndolo.',
        'faq3q' => '¿Qué revisa el chequeo del sitio web?', 'faq3a' => 'Solo su página principal pública y tres archivos pequeños (robots.txt, sitemap.xml y llms.txt). Revisa seguridad, teléfonos, descripciones para buscadores, marcado de negocio inmobiliario, preguntas frecuentes que los asistentes de IA pueden citar, búsqueda de casas, páginas en español, el aviso de Igualdad de Oportunidades en la Vivienda, la política de privacidad y los datos de contacto. No descarga fotos ni otras páginas.',
        'faq4q' => '¿Cómo se calcula el ahorro?', 'faq4a' => 'Con los precios que usted anota. Se descuentan las herramientas que RightAlly reemplaza por completo; las que cubre solo en parte, y cosas como las cuotas del MLS y los prospectos pagados, siguen en el total, así que la cifra nunca es mayor de lo que RightAlly puede cumplir. Si RightAlly le costara más, el informe lo dice.',
        'faq5q' => '¿Quién ve mis respuestas?', 'faq5a' => 'Usted, con un enlace privado que le enviamos por correo, y el equipo de RightAlly. No las vendemos ni las compartimos. Puede borrar su informe cuando quiera, y se borra solo a los 180 días.',
        'q.lead_reply' => '¿Qué tan rápido recibe respuesta un prospecto nuevo de internet?', 'a.lead_reply.minutes' => 'En menos de 5 minutos', 'a.lead_reply.hour' => 'En menos de una hora',
        'a.lead_reply.day' => 'El mismo día', 'a.lead_reply.later' => 'Más tarde', 'a.lead_reply.unknown' => 'No lo sé',
        'q.file_check' => '¿Cuándo se revisan los expedientes por firmas o documentos que faltan?', 'a.file_check.as_uploaded' => 'Cuando llega cada documento', 'a.file_check.before_closing' => 'Antes del cierre',
        'a.file_check.after_closing' => 'Después del cierre', 'a.file_check.rarely' => 'Casi nunca, o cuando hay un problema',
        'q.payout_days' => 'Después del cierre, ¿cuánto tardan en pagar a los agentes?', 'a.payout_days.same_day' => 'El mismo día', 'a.payout_days.few_days' => 'En 2 o 3 días',
        'a.payout_days.week' => 'Cerca de una semana', 'a.payout_days.longer' => 'Más',
        'q.past_clients' => '¿Sus agentes mantienen contacto con clientes anteriores desde un mismo sistema?', 'a.past_clients.yes' => 'Sí, todos', 'a.past_clients.some' => 'Algunos agentes',
        'a.past_clients.no' => 'No, cada agente lo hace a su manera',
        'w.brokerage' => 'La correduría paga el software de los agentes', 'w.agents' => 'Los agentes pagan su propio software',
        'area.tools' => 'Herramientas y costo', 'area.tools.none' => 'No anotó ninguna herramienta.', 'area.tools.found' => ':count herramienta(s), cerca de :cost por agente al mes.',
        'area.tools.fix' => 'Un solo acceso para CRM, sitios web, transacciones, firma electrónica y comisiones: menos facturas y menos que aprender para los agentes.',
        'area.lead_reply' => 'Rapidez con prospectos nuevos', 'area.lead_reply.why' => 'Los prospectos que esperan se van con el siguiente agente que contesta.',
        'area.lead_reply.fix' => 'RightAlly le escribe a cada prospecto nuevo en menos de un minuto, de día y de noche, y lo pasa al agente indicado.',
        'area.file_check' => 'Cumplimiento de expedientes', 'area.file_check.why' => 'Los problemas que aparecen después del cierre son los que cuestan caro.',
        'area.file_check.fix' => 'Deal-IQ revisa cada documento al subirlo: firmas que faltan, anexos que faltan, montos que no coinciden.',
        'area.payout_days' => 'Pagos a agentes', 'area.payout_days.why' => 'Los pagos lentos son una de las principales razones por las que se van los agentes.',
        'area.payout_days.fix' => 'RightAlly calcula cada reparto y paga a los agentes por transferencia bancaria, el mismo día o el siguiente.',
        'area.past_clients' => 'Clientes anteriores y negocio repetido', 'area.past_clients.why' => 'La mayor parte del negocio repetido y de referidos viene de clientes anteriores.',
        'area.past_clients.fix' => 'Cada agente tiene "A quién llamar hoy", con recordatorios de clientes anteriores y tareas de aniversario ya preparadas.',
        'area.website' => 'Sitio web', 'area.website.none' => 'Sin revisar: no se dio una dirección o la revisión no pudo llegar al sitio.', 'area.website.found' => 'Su página principal pasó revisiones que valen :score%.',
        'area.website.fix' => 'Los sitios de RightAlly vienen listos para Google y para las respuestas de IA, en inglés y en español.', 'you_said' => 'Usted dijo: :answer.',
        'st.good' => 'Bien', 'st.fix' => 'Vale la pena arreglar', 'st.urgent' => 'Arreglar primero', 'st.not_checked' => 'Sin revisar',
        'r.title' => ':name: chequeo', 'r.hello' => 'Hola, :name. Así está hoy su correduría de :agents agente(s), y lo primero que conviene arreglar en cada área.',
        'r.overall' => 'Puntaje general :score de 100', 'r.scores' => 'Sus puntajes', 'r.with' => 'Con RightAlly:', 'r.website' => 'Revisión del sitio web', 'r.no_site' => 'No dio una dirección de sitio web.',
        'r.checking' => 'Revisando :url… esta página se actualiza sola.', 'r.could_not' => 'No pudimos revisar :url: :message', 'r.passed' => ':url pasó revisiones que valen :score%. Lo que hay que arreglar aparece primero.',
        'r.check' => 'Revisión', 'r.result' => 'Resultado', 'r.todo' => 'Qué hacer', 'r.yes' => '✓ Sí', 'r.no' => '✗ No', 'r.savings' => 'Comparación de ahorros',
        'r.nothing' => 'No anotó costos de software, así que todavía no hay nada que comparar.', 'r.pay_now' => 'Hoy paga cerca de :total al mes (:per por agente).',
        'r.software' => 'Software', 'r.monthly' => 'Mensual', 'r.with_ra' => 'Con RightAlly', 'r.replaced' => 'Se reemplaza.', 'r.partly' => 'Cubierto en parte; sigue en el total.', 'r.kept' => 'Se mantiene.',
        'r.prices' => 'Estamos actualizando los precios de RightAlly; le enviaremos la comparación por correo.', 'r.after' => 'Después del cambio', 'r.package' => 'Paquete de RightAlly', 'r.package_col' => 'Paquete',
        'r.agent_plans' => 'Planes de agentes', 'r.kept_tools' => 'Herramientas que se mantienen', 'r.new_total' => 'Nuevo total mensual', 'r.difference' => 'Diferencia', 'r.save' => 'Ahorra :amount', 'r.costs_more' => 'Cuesta :amount más',
        'r.best' => 'Mejor opción', 'r.save_line' => 'Con :package ahorraría cerca de :month al mes, o :year al año, y sus agentes entran a un solo lugar.',
        'r.no_save_line' => 'RightAlly no bajaría su factura de software. El valor vendría de lo que se arregla arriba: respuestas más rápidas a los prospectos, expedientes más limpios y pagos más rápidos.',
        'r.agents_pay' => 'Sus agentes pagarían su propio plan de RightAlly (:plan, :price al mes a precio de lista). Su correduría gana cerca de :earn al mes por el plan de cada agente. Eso no se cuenta en el ahorro de arriba.',
        'r.brokerage_pays' => 'Los planes de agentes se cuentan a precio de lista (:plan, :price al mes por agente).', 'r.preview' => 'RightAlly con sus colores',
        'r.preview_p' => 'Vea la pantalla que sus agentes abrirían cada mañana, con su nombre, color y logotipo.', 'r.see_preview' => 'Ver mi vista previa', 'r.see_preview_other' => 'View in English',
        'r.start' => '¿Listo para cambiarse?', 'r.start_p' => 'Precios públicos, sin costo de instalación, en línea el mismo día, con un plan de arranque de 30 días.', 'r.start_btn' => 'Ver precios y registrarse',
        'r.report' => 'Su informe', 'r.report_p' => 'Esta página tiene su propio enlace privado, que le enviamos por correo. Funciona durante 180 días.', 'r.delete' => 'Borrar mi informe',
        'r.website_failed' => 'No pudimos revisar el sitio.',
        'm.subject' => 'Su chequeo de correduría: :name', 'm.hello' => 'Hola, :name:',
        'm.line1' => 'Su informe del chequeo de :name está listo: puntajes, qué arreglar primero, la revisión del sitio web, la comparación de ahorros y una vista previa de RightAlly con sus colores.',
        'm.action' => 'Abrir mi informe', 'm.line2' => 'El enlace es privado y funciona durante 180 días. Puede borrar el informe desde su página cuando quiera.',
        'm.line3' => 'Si usted no lo pidió, ignore este correo; no se enviará nada más.',
    ];

    /** Website checks: key => [en label, en fix, es label, es fix] */
    private const CHECKS = [
        'https' => ['Opens on a secure https:// address', 'Serve the site over HTTPS; browsers mark other sites "Not secure".', 'Abre en una dirección segura https://', 'Sirva el sitio con HTTPS; los navegadores marcan los demás como "No seguro".'],
        'speed' => ['Home page answers in under 2.5 seconds', 'Slow pages lose visitors and rank lower.', 'La página principal responde en menos de 2,5 segundos', 'Las páginas lentas pierden visitantes y salen más abajo en Google.'],
        'mobile' => ['Set up for phones', 'Add a mobile layout; most home buyers search on their phone.', 'Preparado para celulares', 'Agregue un diseño para celular; la mayoría de los compradores buscan desde el teléfono.'],
        'title' => ['Has a clear page title', 'Give the home page a title of 10–70 characters naming the brokerage and area.', 'Tiene un título claro', 'Ponga a la página principal un título de 10 a 70 caracteres con la correduría y la zona.'],
        'description' => ['Has a search description', 'Add a description of 50–170 characters; Google shows it under your name.', 'Tiene una descripción para buscadores', 'Agregue una descripción de 50 a 170 caracteres; Google la muestra debajo de su nombre.'],
        'heading' => ['Has a main heading', 'Add one main heading saying who you are and where you work.', 'Tiene un encabezado principal', 'Agregue un encabezado principal que diga quiénes son y dónde trabajan.'],
        'language' => ['Says which language it is in', 'Mark the page language so screen readers and search engines read it correctly.', 'Indica en qué idioma está', 'Marque el idioma de la página para que los lectores de pantalla y los buscadores la lean bien.'],
        'schema' => ['Tells search engines it is a real estate business', 'Add RealEstateAgent or Organization markup so Google and AI answers know who you are.', 'Indica a los buscadores que es un negocio inmobiliario', 'Agregue el marcado RealEstateAgent u Organization para que Google y las respuestas de IA sepan quiénes son.'],
        'faq' => ['Answers common questions in a way AI tools can quote', 'Add a short FAQ with FAQPage markup; AI answers quote pages like this.', 'Responde preguntas frecuentes de forma que la IA las pueda citar', 'Agregue unas preguntas frecuentes con marcado FAQPage; las respuestas de IA citan páginas así.'],
        'llms' => ['Has an llms.txt guide for AI assistants', 'Publish /llms.txt so AI assistants can find your key pages.', 'Tiene una guía llms.txt para asistentes de IA', 'Publique /llms.txt para que los asistentes de IA encuentren sus páginas clave.'],
        'sitemap' => ['Has a sitemap', 'Publish /sitemap.xml so new listings and pages are found quickly.', 'Tiene un mapa del sitio', 'Publique /sitemap.xml para que las propiedades y páginas nuevas se encuentren rápido.'],
        'robots' => ['Lets search engines in', 'Your robots.txt blocks all search engines; remove "Disallow: /".', 'Deja entrar a los buscadores', 'Su robots.txt bloquea a todos los buscadores; quite "Disallow: /".'],
        'search' => ['Visitors can search for homes', 'Add MLS home search; it is the main reason buyers visit.', 'Los visitantes pueden buscar casas', 'Agregue la búsqueda de casas del MLS; es la razón principal por la que entran los compradores.'],
        'spanish' => ['Has Spanish pages', 'Add Spanish pages to reach Spanish-speaking buyers and sellers.', 'Tiene páginas en español', 'Agregue páginas en español para llegar a compradores y vendedores hispanohablantes.'],
        'fair_housing' => ['Shows the Equal Housing Opportunity notice', 'Show the Equal Housing Opportunity logo or wording on every page.', 'Muestra el aviso de Igualdad de Oportunidades en la Vivienda', 'Muestre el logotipo o el texto de Igualdad de Oportunidades en la Vivienda en todas las páginas.'],
        'privacy' => ['Links to a privacy policy', 'Link to a privacy policy, especially if the site collects leads.', 'Enlaza a una política de privacidad', 'Enlace a una política de privacidad, sobre todo si el sitio recibe prospectos.'],
        'images' => ['Pictures have descriptions', 'Describe images (alt text) for visitors who use screen readers.', 'Las imágenes tienen descripción', 'Describa las imágenes (texto alternativo) para quienes usan lectores de pantalla.'],
        'contact' => ['Easy to get in touch', 'Show a phone number, email or contact form on the home page.', 'Es fácil ponerse en contacto', 'Muestre un teléfono, un correo o un formulario de contacto en la página principal.'],
    ];

    /** Spanish notes for the tools (English is in Tools). */
    private const TOOL_NOTES_ES = [
        'follow_up_boss' => 'El CRM de RightAlly, con sus contactos y notas traídos.', 'boldtrail' => 'El CRM y los sitios IDX de RightAlly.', 'lofty' => 'El CRM y los sitios IDX de RightAlly.',
        'sierra' => 'El CRM y los sitios IDX de RightAlly.', 'real_geeks' => 'El CRM y los sitios IDX de RightAlly.',
        'cinc' => 'RightAlly cubre el CRM; mantenga la compra de prospectos hasta que esté contento con los anuncios de RightAlly.',
        'website' => 'Sitios IDX de RightAlly para la correduría y cada agente.', 'dotloop' => 'Transacciones, listas de documentos y revisión Deal-IQ de RightAlly.',
        'skyslope' => 'Transacciones, listas de documentos y revisión Deal-IQ de RightAlly.', 'docusign' => 'La firma electrónica de RightAlly está incluida.',
        'lone_wolf' => 'Planes de comisión, CDA y pagos a agentes de RightAlly.', 'brokermint' => 'Planes de comisión, CDA y pagos a agentes de RightAlly.',
        'canva' => 'Ally Studio hace volantes y publicaciones con su marca; algunos equipos mantienen Canva para otras cosas.',
        'email_marketing' => 'RightAlly envía correos de seguimiento y alertas; las campañas completas vienen pronto.',
        'isa' => 'RightAlly responde por texto a los prospectos nuevos en menos de un minuto; las llamadas con IA vienen pronto.',
        'dialer' => 'RightAlly tiene llamada con un clic; el marcador automático viene pronto.', 'showings' => 'Manténgalo por ahora; las visitas en RightAlly están planeadas.',
        'recruiting' => 'Manténgalo por ahora; el reclutamiento en RightAlly está planeado.', 'accounting' => 'Mantenga su software contable; RightAlly le envía los asientos de comisiones.',
        'paid_leads' => 'Siga comprando prospectos si le funcionan; RightAlly le muestra cuáles sí.', 'mls' => 'Las cuotas siguen igual.',
        'other' => 'Se cuenta como algo que se mantiene, para no exagerar el ahorro.',
    ];

    public static function lang(?string $lang): string
    {
        return $lang === 'es' ? 'es' : 'en';
    }

    /** @param  array<string, string|int>  $with */
    public static function t(string $lang, string $key, array $with = []): string
    {
        $text = (self::lang($lang) === 'es' ? self::ES : self::EN)[$key] ?? self::EN[$key] ?? $key;
        foreach ($with as $k => $v) {
            $text = str_replace(':'.$k, (string) $v, $text);
        }

        return $text;
    }

    /** @return array{label: string, fix: string} */
    public static function check(string $lang, string $key): array
    {
        $c = self::CHECKS[$key] ?? [$key, '', $key, ''];

        return self::lang($lang) === 'es' ? ['label' => $c[2], 'fix' => $c[3]] : ['label' => $c[0], 'fix' => $c[1]];
    }

    public static function toolNote(string $lang, string $key): string
    {
        return self::lang($lang) === 'es' ? (self::TOOL_NOTES_ES[$key] ?? '') : (Tools::find($key)['note'] ?? '');
    }

    /**
     * Keys missing in either language (checked by the tests).
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        return [
            ...array_diff(array_keys(self::EN), array_keys(self::ES)), ...array_diff(array_keys(self::ES), array_keys(self::EN)),
            ...array_diff(array_keys(Tools::all()), array_keys(self::TOOL_NOTES_ES)), ...array_diff(array_keys(WebsiteScan::CHECKS), array_keys(self::CHECKS)),
        ];
    }
}
