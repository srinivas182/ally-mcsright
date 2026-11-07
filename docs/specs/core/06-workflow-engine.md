# 06 — Workflow engine (Team A, Sprints 9–10)

## Purpose

A versioned, editable engine for step-by-step processes. Agent onboarding (register → documents
and addendum → agreement → payment → approval → welcome) is the first workflow; transfers,
offboarding, policy acknowledgement, transaction checklists and DocIQ automations reuse it.

## Concepts

| Concept    | Meaning                                                                    |
| ---------- | -------------------------------------------------------------------------- |
| Definition | A named workflow, e.g. "Agent onboarding"                                  |
| Version    | An immutable, published list of steps; editing creates a new draft version |
| Instance   | One run of a version for a subject (e.g. one joining agent)                |
| Step run   | One step within an instance, with its status, inputs and outputs           |

Running instances always finish on the version they started with.

## Data model (cell)

### `workflow_definitions`

`id, ulid, tenant_id, key (unique per tenant), name, module_key, description, template_key
nullable, status (active, archived), created_by, timestamps`.

### `workflow_versions`

`id, ulid, tenant_id, definition_id, version (int), status (draft, published, superseded),
steps (json, validated against the step schema), triggers (json), published_by, published_at,
timestamps`.

### `workflow_instances`

`id, ulid, tenant_id, version_id, subject_type, subject_id, status (in_progress, waiting,
completed, cancelled, failed), current_step_key, context (json), started_by, started_at,
completed_at, cancelled_reason, resume_token_hash, timestamps`.

### `workflow_step_runs`

`id, ulid, tenant_id, instance_id, step_key, type, status (pending, active, waiting, completed,
skipped, rejected, failed), assignee_user_id nullable, due_at nullable, input (json),
output (json), attempts, error (text), started_at, completed_at`.

## Step types (v1)

| Type              | What it does                                                                                            | Completes when                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `form`            | Renders a form from a JSON schema (fields, validation, help text, conditional fields)                   | Valid submission; server-side validation against the same schema                        |
| `document_upload` | Requires listed document types (e.g. licence, W-9, E&O certificate)                                     | All required documents uploaded and scanned clean                                       |
| `agreement`       | Shows a versioned agreement and records acceptance with typed name, timestamp, IP and agreement version | Accepted (full e-signature via `SignatureProvider` interface once Forms & e-Sign ships) |
| `payment`         | Takes a payment through billing (Stripe PaymentIntent) with an idempotency key                          | Payment succeeded                                                                       |
| `approval`        | Assigns to holders of a permission; approve or reject with comment                                      | Approved; rejection returns to a configured step with the comment shown                 |
| `notification`    | Sends email and in-app messages from a template with context variables                                  | Sent                                                                                    |
| `wait`            | Waits for a duration or until a date in the context                                                     | Time reached                                                                            |
| `condition`       | Branches to a step based on an expression on context values                                             | Immediately                                                                             |
| `task`            | Manual task for a role or user with a checklist                                                         | Marked done                                                                             |

Expressions use a safe, sandboxed evaluator (no code execution): comparisons, `and`/`or`,
`in`, presence checks.

## Triggers

`manual` (button or API), `event` (e.g. `InvitationAccepted` with `kind = agent`), `schedule`
(cron expression). Event triggers are idempotent per subject.

## Running

- A step becomes active when the previous one completes (or a condition routes to it).
- Steps for the joining user (form, upload, agreement, payment) are shown on a guided page; they
  can leave and resume from a secure link (resume token, 30-day expiry, refreshed on use).
- Due dates and reminders: optional per step (e.g. remind after 2 days, escalate after 5).
- Failures (e.g. payment provider error) retry with backoff up to 3 times, then mark the step
  failed and alert the workflow owner; admins can retry or skip with a reason.
- Every transition is audited and emits an event.

## Agent onboarding template

Copied into every brokerage at provisioning (editable):

1. **Register** (form): name, email, phone, licence number, licence state, MLS ID, address.
2. **Documents and addendum** (document upload): licence copy, W-9 (electronic W-9 arrives with
   Sprint 16–17), E&O certificate; addendum acknowledgement.
3. **Agreement** (agreement): brokerage independent contractor agreement, current version.
4. **Payment** (payment): onboarding fee from the agent's plan (skipped when zero).
5. **Approval** (approval): office manager or owner.
6. **Welcome** (notification): welcome email, login link, next steps.

Completion activates the agent's user account and emits `AgentOnboarded` for other modules
(compensation plan assignment, MLS onboarding checklist).

## Builder (v1)

- Workflow list with status, current version and running instance counts.
- Editor: ordered list of steps (add, reorder by drag, configure, delete), branch configuration on
  condition steps, trigger settings. Form step uses a visual field editor that writes the JSON
  schema. (A free-form canvas is out of scope for v1.)
- Validate → shows errors (unreachable steps, missing configuration).
- Preview as the joining user.
- Publish with a change note; version history with diff.

## Instance monitor

List with filters (definition, status, current step, overdue), instance detail timeline (each
step, who, when, inputs/outputs with sensitive values masked), actions: retry step, skip step
(reason), reassign, cancel (reason).

## Permissions

`core.workflows.view`, `core.workflows.manage` (edit and publish), `core.workflows.run`
(start manually), `core.workflows.instances.manage` (retry, skip, cancel).

## Events

`WorkflowVersionPublished`, `WorkflowStarted`, `WorkflowStepActivated`, `WorkflowStepCompleted`,
`WorkflowStepRejected`, `WorkflowStepFailed`, `WorkflowCompleted`, `WorkflowCancelled`.

## Acceptance criteria

1. Publishing creates an immutable version; running instances keep their version.
2. Each v1 step type works as specified, with server-side validation of form input.
3. The onboarding template runs end to end in a test brokerage: register, upload, accept,
   pay (Stripe test mode), approve, welcome; the agent becomes active.
4. A joining user can leave and resume from the emailed link.
5. Rejection returns to the configured step with the comment visible.
6. Failed steps retry with backoff, then alert; admins can retry or skip with a reason.
7. Every transition is audited; instances are tenant-isolated.
