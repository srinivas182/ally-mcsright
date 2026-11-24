<?php

declare(strict_types=1);

namespace Modules\Core\Navigation;

/**
 * The menu in Spanish (PW1, specs/core/17): every brokerage menu group and item, so people who chose Spanish see the
 * whole menu, the phone bar and search in Spanish, not only the simple view. A test keeps this list complete. The HQ
 * console stays in English.
 */
final class NavWords
{
    public const GROUPS = [
        'Overview' => 'Inicio', 'Grow' => 'Crecer', 'Run' => 'Operar', 'Finance' => 'Finanzas', 'Protect' => 'Proteger', 'Know' => 'Aprender',
        'Brokerage' => 'Correduría', 'People' => 'Personas', 'Compensation' => 'Compensación', 'Settings' => 'Ajustes',
        // UX1: the brokerage's main menus.
        'Home' => 'Inicio', 'Leads and clients' => 'Prospectos y clientes', 'Listings' => 'Propiedades', 'Marketing' => 'Mercadeo', 'Deals' => 'Operaciones',
        'Money' => 'Dinero', 'Reports' => 'Informes', 'Team' => 'Equipo',
        // MN3: the main menus.
        'Dashboard' => 'Panel', 'CRM' => 'CRM', 'Website & IDX' => 'Sitio web e IDX', 'Agents' => 'Agentes', 'Commissions' => 'Comisiones', 'My commissions' => 'Mis comisiones',
        'Accounting' => 'Contabilidad', 'Training' => 'Capacitación',
        // MN2
        'Transactions' => 'Transacciones', 'Website' => 'Sitio web', 'Brokerage setup' => 'Configuración de la correduría', 'Compliance' => 'Cumplimiento',
        'Getting started' => 'Primeros pasos', 'My account' => 'Mi cuenta',
    ];

    /** MN3: section labels inside a main menu. */
    public const SECTIONS = ['Money in and out' => 'Dinero que entra y sale', 'Books' => 'Libros contables', 'Trust, tax and audit' => 'Fideicomiso, impuestos y auditoría',
        'Brokerage' => 'Correduría', 'Billing' => 'Facturación', 'Compliance' => 'Cumplimiento', 'Apps' => 'Aplicaciones', 'Getting started' => 'Primeros pasos', 'My account' => 'Mi cuenta', 'More' => 'Más'];

    public const ES = [
        // MN3
        'All transactions' => 'Todas las transacciones', 'Compliance review' => 'Revisión de cumplimiento', 'Checklists' => 'Listas de verificación', 'Forms and e-sign' => 'Formularios y firma',
        'Campaigns' => 'Campañas', 'Movewise leads' => 'Prospectos de Movewise', 'Database health' => 'Salud de la base de datos', 'MLS search' => 'Búsqueda MLS', 'My listings' => 'Mis propiedades',
        'Licences and onboarding' => 'Licencias e incorporación', 'Retention' => 'Retención', 'Training' => 'Capacitación', 'Commission plans' => 'Planes de comisión',
        'Website assistant' => 'Asistente del sitio web', 'MLS / IDX' => 'MLS / IDX', 'Referral network settings' => 'Ajustes de la red de referidos', 'Plans and fees' => 'Planes y cargos',
        'My transactions' => 'Mis transacciones',
        // MN2
        'Website builder' => 'Creador de sitio web', 'Blog' => 'Blog', 'Photos and videos' => 'Fotos y videos', 'Communities' => 'Comunidades',
        'Transaction settings' => 'Ajustes de transacciones', 'Listings board' => 'Tablero de propiedades', 'Money coming in' => 'Dinero por cobrar',
        'Playbooks' => 'Guías de pasos', 'Vendors' => 'Proveedores', 'Compliance checklists' => 'Listas de cumplimiento', 'Bring over old files' => 'Traer archivos anteriores',
        "Today's 10" => 'Los 10 de hoy', 'AIlly assistant' => 'Asistente AIlly', 'Access review' => 'Revisión de accesos', 'Adjustments' => 'Ajustes contables',
        'Ads' => 'Anuncios', 'Agent activity' => 'Actividad de agentes', 'Agent billing' => 'Cobros a agentes', 'Agent database' => 'Base de agentes',
        'Agent plans' => 'Planes para agentes', 'Ally Studio' => 'Ally Studio', 'Appointments' => 'Citas', 'Approvals' => 'Aprobaciones', 'Apps' => 'Aplicaciones',
        'Audit log' => 'Registro de auditoría', 'Audit pack' => 'Paquete de auditoría', 'Automations' => 'Automatizaciones', 'Bank reconciliation' => 'Conciliación bancaria',
        'Billing' => 'Facturación', 'Bills' => 'Facturas por pagar', 'Brand voice' => 'Voz de la marca', 'AI blog writer' => 'Redactor de blog con IA', 'Books (QuickBooks, Xero)' => 'Contabilidad (QuickBooks, Xero)', 'Bring your old files' => 'Traiga sus archivos',
        'Business planner' => 'Plan de negocio', 'Buyer demand' => 'Demanda de compradores', 'CMA and estimates' => 'CMA y estimaciones', 'Chart of accounts' => 'Plan de cuentas',
        'Contacts' => 'Contactos', 'Credits' => 'Créditos', 'Custom fields' => 'Campos personalizados', 'Dashboard' => 'Panel', 'Database' => 'Base de datos', 'Deals' => 'Operaciones',
        'Directory' => 'Directorio', 'Fair Housing' => 'Vivienda justa', 'Features and seats' => 'Funciones y licencias', 'Fees' => 'Cargos', 'Forms' => 'Formularios',
        'Funnels' => 'Embudos', 'Game plan' => 'Plan de juego', 'Guided setup' => 'Configuración guiada', 'Help' => 'Ayuda', 'Inbox' => 'Bandeja de entrada',
        'Invoices' => 'Facturas', 'Journals' => 'Asientos', 'Keeping agents' => 'Retener agentes', 'Leads' => 'Prospectos', 'Learn' => 'Aprender', 'List-IQ' => 'List-IQ',
        'Licences' => 'Licencias', 'Listings' => 'Propiedades', 'MLS' => 'MLS', 'Marketing check' => 'Revisión de marketing', 'Money received' => 'Dinero recibido', 'Month-end close' => 'Cierre de mes',
        'Movewise' => 'Movewise', 'My assistants' => 'Mis asistentes', 'My availability' => 'Mi disponibilidad', 'My licences and onboarding' => 'Mis licencias e incorporación', 'My money' => 'Mi dinero',
        'My numbers' => 'Mis números', 'My plan' => 'Mi plan', 'My website' => 'Mi sitio web', 'Numbers' => 'Números', 'Offices and teams' => 'Oficinas y equipos',
        'Outreach' => 'Contacto masivo', 'Outside services' => 'Servicios externos', 'Partner earnings' => 'Ganancias de socio', 'Payouts' => 'Pagos', 'People' => 'Personas',
        'Plans' => 'Planes', 'Policies' => 'Políticas', 'Privacy requests' => 'Solicitudes de privacidad', 'Postcards' => 'Postales', 'Profit and loss' => 'Pérdidas y ganancias', 'Profitability' => 'Rentabilidad',
        'Prospector' => 'Buscador de vendedores', 'Ready-made library' => 'Biblioteca lista', 'Recruiting' => 'Reclutamiento', 'Refer a brokerage' => 'Recomendar una correduría',
        'Refer an agent' => 'Recomendar un agente', 'Referral network' => 'Red de referidos', 'Reports' => 'Informes', 'Revenue share' => 'Participación de ingresos',
        'Review queue' => 'Cola de revisión', 'Reviews' => 'Reseñas', 'RightAlly credits' => 'Créditos RightAlly', 'Risk' => 'Riesgo', 'Roles' => 'Funciones',
        'Roster and compliance' => 'Plantilla y cumplimiento', 'Simulator' => 'Simulador', 'Ranks' => 'Rangos', 'Pipeline' => 'Embudo de ventas', 'Network tree' => 'Árbol de la red', 'My network' => 'Mi red', 'Social posts' => 'Redes sociales', 'Support access' => 'Acceso de soporte',
        'Switch to RightAlly' => 'Cambiar a RightAlly', 'Tasks' => 'Tareas', 'Tax (1099)' => 'Impuestos (1099)', 'Team chat' => 'Chat del equipo', 'Today' => 'Hoy',
        'Transactions' => 'Transacciones', 'Trust reconciliation' => 'Conciliación de fideicomiso', 'Website' => 'Sitio web',
    ];

    /**
     * The menu in the person's language (English stays as it is).
     *
     * @param  list<array{key: string, label: string, items: list<array{title: string, href: string, icon: string, mobile: bool, section?: string}>}>  $menu
     * @return list<array{key: string, label: string, items: list<array{title: string, href: string, icon: string, mobile: bool, section?: string}>}>
     */
    public static function translate(array $menu, string $locale): array
    {
        if ($locale !== 'es') {
            return $menu;
        }

        return array_map(fn (array $group): array => ['label' => self::GROUPS[$group['label']] ?? $group['label'],
            'items' => array_map(fn (array $i): array => ['title' => self::ES[$i['title']] ?? $i['title']] + (isset($i['section']) ? ['section' => self::SECTIONS[$i['section']] ?? $i['section']] : []) + $i,
                $group['items'])] + $group, $menu);
    }
}
