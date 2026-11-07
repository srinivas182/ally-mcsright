# Legacy business rules to preserve

Every rule below becomes an automated test in the Compensation & Revenue Share and Finance
modules before the new engine is accepted. Sources: `docs/BUSINESS_RULES.md`, `Business_rules.php`,
`Commission_model.php`, `Deduction_master_model.php` and the ledger tables in the legacy code.

## Plans

1. Two plan types: **Registration** (onboarding and monthly fees) and **Repurchase** (revenue share on closed deals).
2. Registration plans are never the default. Exactly one Repurchase plan is the default; setting a new default clears the old one; removing the only default is blocked.
3. Repurchase sub-types: **Revenue Split**, or **Flat Rate** with small / large / mega tiers.
4. Company Revenue % = 100 − Primary Agent %, derived and read-only.

## Revenue share

5. Commission basis is **Net Profit** or **Gross Commission Income (GCI)**.
6. Level percentages (up to 15 levels) must total 100% for Net Profit, or Company Revenue % for GCI, within 0.01 points.
7. Example: GCI with Primary Agent 90% → Company Revenue 10% → levels must total 10; 50 + 30 + 20 is invalid.
8. An Inactive or Blocked upline receives no revenue share; the amount is recorded as missed earning.
9. A missing or ineligible upline falls back to the root account under versioned per-brokerage settings (missing-upline policy, ineligible-upline policy, fallback cap).

## Capping

10. Capping Amount = company revenue an agent pays within the capping reset period; −1 means no cap.
11. Below the cap, the normal Primary Agent / Company split applies.
12. After the cap, the Primary Agent receives 100% and pays only the post-cap fee per transaction.
13. When the reset period ends, the cap total resets; credits and reversals are tracked per cap period.

## Deductions and fees

14. Fees and deductions come from a versioned catalogue per brokerage: stage, calculation type, charged-to, payable-to, counts toward cap, revenue-share eligible, effective dates.

## Ledger

15. Approving a deal creates an immutable calculation snapshot (versioned per deal) with allocations, revenue-share rows, entitlements, a balanced double-entry journal and wallet lines, in one database transaction.
16. Primary Agent commission is paid outside the wallet; revenue share and root fallback are credited to the wallet.
17. Reversals create new rows linked to the original; nothing is deleted.

## Known legacy gap

The legacy code dual-writes old `earning`, `capping` and `wallet.balance` rows alongside the newer
ledger. The new platform keeps only the ledger model; migration reconciles both and treats the
ledger as the source of truth only after figures match to the cent.

## KPIs

The legacy `kpi_registry.php` defines 40 KPIs (agent and admin). They are carried over into
Reports & KPIs.
