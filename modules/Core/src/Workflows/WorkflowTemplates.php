<?php

declare(strict_types=1);

namespace Modules\Core\Workflows;

/**
 * Ready-made automations (specs/core/06, W1). Added switched off, so the brokerage checks them first. Only those whose
 * trigger and actions exist in this installation are offered.
 */
final class WorkflowTemplates
{
    /**
     * @return array<string, array{name: string, description: string, trigger: string, conditions: list<array{fact: string, op: string, value?: mixed}>, steps: list<array{type: string, params: array<string, mixed>}>}>
     */
    public static function all(): array
    {
        return [
            'under_contract_tc' => ['name' => 'Under contract: tasks for the file', 'description' => 'When a deal goes under contract, the agent gets the first tasks and the transaction coordinators are told.',
                'trigger' => 'deal.under_contract', 'conditions' => [], 'steps' => [
                    ['type' => 'create_task', 'params' => ['title' => 'Order the home inspection', 'due_days' => 2, 'assign' => 'owner']],
                    ['type' => 'create_task', 'params' => ['title' => 'Send the contract to the lender and title', 'due_days' => 1, 'assign' => 'owner']],
                    ['type' => 'notify', 'params' => ['to' => 'owners', 'message' => 'A deal just went under contract']],
                ]],
            'closed_anniversary' => ['name' => 'Closed: thank-you and review request', 'description' => 'When a deal closes, the client gets the past-client tag and the agent a reminder to ask for a review a week later.',
                'trigger' => 'deal.closed', 'conditions' => [], 'steps' => [
                    ['type' => 'add_tag', 'params' => ['tag' => 'Past client']],
                    ['type' => 'wait', 'params' => ['days' => 7, 'hours' => 0]],
                    ['type' => 'create_task', 'params' => ['title' => 'Ask for a review and a referral', 'due_days' => 0, 'assign' => 'owner']],
                ]],
            'big_deal_approval' => ['name' => 'Deals over $1M: broker approval', 'description' => 'A deal over $1M going under contract waits for the managing broker\'s approval, then the agent is told.',
                'trigger' => 'deal.under_contract', 'conditions' => [['fact' => 'price', 'op' => 'over', 'value' => 1000000]], 'steps' => [
                    ['type' => 'approval', 'params' => ['question' => 'Approve this deal over $1M', 'needed' => 1, 'permission' => 'transactions.cda.approve']],
                    ['type' => 'notify', 'params' => ['to' => 'owner', 'message' => 'Your broker approved the deal']],
                ]],
            'licence_expiring' => ['name' => 'Licence expiring in 30 days', 'description' => 'The agent and the owners are told, and the agent gets a task to renew.',
                'trigger' => 'credential.expiring', 'conditions' => [['fact' => 'days_left', 'op' => 'under', 'value' => 31]], 'steps' => [
                    ['type' => 'notify', 'params' => ['to' => 'owner', 'message' => 'Your licence or insurance expires soon: renew it now']],
                    ['type' => 'notify', 'params' => ['to' => 'owners', 'message' => 'An agent\'s licence or insurance expires soon']],
                ]],
            'new_agent_welcome' => ['name' => 'New agent onboarding', 'description' => 'When onboarding starts, the owners are told and the agent is reminded after three days if they are not done.',
                'trigger' => 'agent.onboarding_started', 'conditions' => [], 'steps' => [
                    ['type' => 'notify', 'params' => ['to' => 'owners', 'message' => 'A new agent started onboarding']],
                    ['type' => 'wait', 'params' => ['days' => 3, 'hours' => 0]],
                    ['type' => 'notify', 'params' => ['to' => 'owner', 'message' => 'Finish your onboarding steps in RightAlly']],
                ]],
            'new_lead_plan' => ['name' => 'New website lead: follow-up and a call task', 'description' => 'New contacts from the website get a call task today.',
                'trigger' => 'contact.created', 'conditions' => [['fact' => 'source', 'op' => 'is', 'value' => 'website']], 'steps' => [
                    ['type' => 'create_task', 'params' => ['title' => 'Call the new website lead', 'due_days' => 0, 'assign' => 'owner']],
                ]],
        ];
    }
}
