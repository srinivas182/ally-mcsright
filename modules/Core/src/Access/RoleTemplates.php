<?php

declare(strict_types=1);

namespace Modules\Core\Access;

/**
 * Ready-made real estate roles (specs/core/03, addition 1). Seeded once per brokerage; later changes to
 * these definitions never overwrite a brokerage's edited roles. Scope "tenant" = everyone.
 */
final class RoleTemplates
{
    /**
     * @return array<string, array{name: string, description: string, locked?: bool, permissions: array<string, string|null>}>
     */
    public static function all(): array
    {
        return [
            'owner' => ['name' => 'Owner', 'description' => 'Everything in the brokerage. Cannot be changed.', 'locked' => true, 'permissions' => []],
            'managing_broker' => ['name' => 'Managing broker', 'description' => 'Runs an office: people, contacts and deals in their office, compliance approvals.', 'permissions' => [
                'core.users.view' => null, 'core.users.invite' => null, 'core.audit.view' => null,
                'crm.contacts.view' => 'office', 'crm.contacts.manage' => 'office', 'crm.contacts.reassign' => 'office',
                'compensation.plans.view' => null, 'finance.amounts.view' => null,
            ]],
            'office_admin' => ['name' => 'Office admin', 'description' => 'Day-to-day office work. Sees deals but not commission amounts or bank details.', 'permissions' => [
                'core.users.view' => null, 'core.users.invite' => null,
                'crm.contacts.view' => 'office', 'crm.contacts.manage' => 'office', 'crm.contacts.reassign' => 'office', 'crm.import' => null,
            ]],
            'compliance_reviewer' => ['name' => 'Compliance reviewer', 'description' => 'Reviews files and the audit log; cannot change money.', 'permissions' => [
                'core.audit.view' => null, 'crm.contacts.view' => 'tenant',
            ]],
            'transaction_coordinator' => ['name' => 'Transaction coordinator', 'description' => 'Works deals and contacts for the agents they support.', 'permissions' => [
                'crm.contacts.view' => 'team', 'crm.contacts.manage' => 'team',
            ]],
            'team_lead' => ['name' => 'Team lead', 'description' => 'Sees and assigns their team\'s company contacts.', 'permissions' => [
                'crm.contacts.view' => 'team', 'crm.contacts.manage' => 'team', 'crm.contacts.reassign' => 'team',
            ]],
            'agent' => ['name' => 'Agent', 'description' => 'Their own contacts and deals.', 'permissions' => [
                'crm.contacts.view' => 'own', 'crm.contacts.manage' => 'own',
            ]],
            'assistant' => ['name' => 'Assistant', 'description' => 'Works the contacts of the agent they assist.', 'permissions' => [
                'crm.contacts.view' => 'own', 'crm.contacts.manage' => 'own',
            ]],
            'bookkeeper' => ['name' => 'Bookkeeper (read-only)', 'description' => 'Reads the books and exports them. Cannot post, close periods or change payouts.', 'permissions' => [
                'finance.ledger.view' => null, 'finance.ledger.export' => null, 'finance.amounts.view' => null, 'compensation.plans.view' => null,
            ]],
        ];
    }
}
