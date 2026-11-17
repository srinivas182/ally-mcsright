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
                'transactions.review' => null, 'transactions.cda.approve' => null, 'roster.manage' => null, 'core.access.review' => null, 'insights.view' => 'office', 'recruiting.manage' => null,
            ]],
            'office_admin' => ['name' => 'Office admin', 'description' => 'Day-to-day office work. Sees deals but not commission amounts or bank details.', 'permissions' => [
                'core.users.view' => null, 'core.users.invite' => null,
                'crm.contacts.view' => 'office', 'crm.contacts.manage' => 'office', 'crm.contacts.reassign' => 'office', 'crm.import' => null,
                'transactions.review' => null, 'roster.manage' => null, 'forms.manage' => null,
            ]],
            'compliance_reviewer' => ['name' => 'Compliance reviewer', 'description' => 'Reviews files and the audit log; cannot change money.', 'permissions' => [
                'core.audit.view' => null, 'crm.contacts.view' => 'tenant', 'transactions.review' => null, 'transactions.export' => null,
            ]],
            'transaction_coordinator' => ['name' => 'Transaction coordinator', 'description' => 'Works every deal file and the contacts of the agents they support. Does not see commission.', 'permissions' => [
                'crm.contacts.view' => 'team', 'crm.contacts.manage' => 'team', 'transactions.review' => null,
            ]],
            'team_lead' => ['name' => 'Team lead', 'description' => 'Sees and assigns their team\'s company contacts.', 'permissions' => [
                'crm.contacts.view' => 'team', 'crm.contacts.manage' => 'team', 'crm.contacts.reassign' => 'team', 'insights.view' => 'team',
            ]],
            'agent' => ['name' => 'Agent', 'description' => 'Their own contacts and deals.', 'permissions' => [
                'crm.contacts.view' => 'own', 'crm.contacts.manage' => 'own',
            ]],
            'assistant' => ['name' => 'Assistant', 'description' => 'Works the contacts of the agent they assist.', 'permissions' => [
                'crm.contacts.view' => 'own', 'crm.contacts.manage' => 'own',
            ]],
            'isa' => ['name' => 'ISA (inside sales)', 'description' => 'Works and hands over new company leads for everyone.', 'permissions' => [
                'crm.contacts.view' => 'tenant', 'crm.contacts.manage' => 'tenant', 'crm.contacts.reassign' => 'tenant',
            ]],
            'marketing_manager' => ['name' => 'Marketing manager', 'description' => 'Runs the brokerage website, Ally Studio, social posts and listing ads. No money or people access.', 'permissions' => [
                'marketing.manage' => null, 'sites.manage' => null, 'crm.contacts.view' => 'tenant',
            ]],
            'recruiter' => ['name' => 'Recruiter', 'description' => 'Invites new agents and keeps the roster up to date.', 'permissions' => [
                'core.users.view' => null, 'core.users.invite' => null, 'roster.manage' => null, 'recruiting.manage' => null,
            ]],
            'lender_partner' => ['name' => 'Lender partner', 'description' => 'An outside partner: only the contacts shared with them. Never money, deals or bank details.', 'permissions' => [
                'crm.contacts.view' => 'own',
            ]],
            'accountant' => ['name' => 'Accountant', 'description' => 'Keeps the books: records money received, bills agents, posts adjustments and closes periods. Cannot change bank details.', 'permissions' => [
                'finance.ledger.view' => null, 'finance.ledger.export' => null, 'finance.ledger.adjust' => null, 'finance.ledger.approve' => null, 'finance.periods.close' => null,
                'finance.amounts.view' => null, 'finance.receipts.record' => null, 'finance.billing.manage' => null, 'compensation.plans.view' => null,
            ]],
            'bookkeeper' => ['name' => 'Bookkeeper (read-only)', 'description' => 'Reads the books and exports them. Cannot post, close periods or change payouts.', 'permissions' => [
                'finance.ledger.view' => null, 'finance.ledger.export' => null, 'finance.amounts.view' => null, 'compensation.plans.view' => null,
            ]],
        ];
    }
}
