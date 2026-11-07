# Legacy schema mapping

Every legacy table, the new module that owns its concept, and whether its data migrates for existing customers. The new schema is designed from the domain, not copied; see `docs/architecture/architecture-v2.md` section 7.

| Legacy table                          | Columns | New owner module                                                             | Migrate data                                        |
| ------------------------------------- | ------- | ---------------------------------------------------------------------------- | --------------------------------------------------- |
| `admin`                               | 20      | Platform Core: identity, staff, roles                                        | Users and staff only (passwords not migrated as-is) |
| `admin_expense`                       | 5       | Finance: brokerage expenses                                                  | Yes                                                 |
| `admin_theme`                         | 7       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `agent_cap_entry`                     | 10      | Compensation & Revenue Share                                                 | Yes                                                 |
| `agent_cap_period`                    | 12      | Compensation & Revenue Share                                                 | Yes                                                 |
| `aggrements`                          | 3       | Agent Onboarding & Workflows                                                 | Yes (documents copied to new storage)               |
| `ally_news`                           | 5       | Communication & Chat                                                         | Recent history only                                 |
| `api_keys`                            | 8       | Platform Core: API and integrations                                          | No (keys reissued)                                  |
| `audit_logs`                          | 7       | Platform Core: settings, offices, audit                                      | Settings and offices yes; logs archived             |
| `binarydata`                          | 11      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `blocked_users`                       | 4       | Platform Core: identity, staff, roles                                        | Yes                                                 |
| `campaign_events`                     | 11      | Marketing Automation                                                         | Templates yes, history no                           |
| `campaigns`                           | 6       | Marketing Automation                                                         | Templates yes, history no                           |
| `capping`                             | 9       | Compensation & Revenue Share                                                 | Yes                                                 |
| `category`                            | 8       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `chat_attachments`                    | 5       | Communication & Chat                                                         | Recent history only                                 |
| `chat_conversations`                  | 6       | Communication & Chat                                                         | Recent history only                                 |
| `chat_messages`                       | 11      | Communication & Chat                                                         | Recent history only                                 |
| `commission_allocation`               | 13      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `commission_calculation`              | 25      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `company_goals`                       | 5       | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `crm_note`                            | 5       | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `customer_details`                    | 20      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `customer_invoices`                   | 27      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `customer_invoices_status`            | 17      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `daily_goals`                         | 4       | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `dashboard_kpi_activity`              | 5       | Reports & KPIs                                                               | KPI definitions only                                |
| `dashboard_kpi_definition`            | 15      | Reports & KPIs                                                               | KPI definitions only                                |
| `deduction_master`                    | 20      | Compensation & Revenue Share                                                 | Yes                                                 |
| `deduction_master_version`            | 19      | Compensation & Revenue Share                                                 | Yes                                                 |
| `deductions`                          | 12      | Compensation & Revenue Share                                                 | Yes                                                 |
| `dummy`                               | 14      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `earning`                             | 22      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `earning_carry_forward`               | 11      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `earning_entitlement`                 | 23      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `earning_payout`                      | 15      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `earning_payout_allocation`           | 4       | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `epin`                                | 19      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `events`                              | 11      | Communication & Chat                                                         | Recent history only                                 |
| `failed_recurring_payment_history`    | 11      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `faq`                                 | 5       | Support & Help Centre / Training                                             | Open tickets and articles                           |
| `feedback`                            | 7       | Support & Help Centre / Training                                             | Open tickets and articles                           |
| `financial_journal`                   | 14      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `financial_journal_line`              | 10      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `frontend_settings`                   | 3       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `gamification`                        | 13      | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `gamification_rewards`                | 7       | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `inbox`                               | 16      | Communication & Chat                                                         | Recent history only                                 |
| `invoice`                             | 34      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `knowledge`                           | 6       | Support & Help Centre / Training                                             | Open tickets and articles                           |
| `knowledge_ai_jobs`                   | 12      | Support & Help Centre / Training                                             | Open tickets and articles                           |
| `level`                               | 25      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `level_details`                       | 43      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `level_sponsor`                       | 22      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `level_upgrade`                       | 12      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `level_wise_income`                   | 16      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `live_meeting`                        | 9       | Communication & Chat                                                         | Recent history only                                 |
| `logs`                                | 6       | Platform Core: settings, offices, audit                                      | Settings and offices yes; logs archived             |
| `marketing_notifications`             | 9       | Communication & Chat                                                         | Recent history only                                 |
| `mcs_properties_amminities`           | 3       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_properties_landmarks`            | 3       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_properties_media`                | 7       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_property_company`                | 12      | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_realty_properties`               | 33      | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_realty_properties_amenities`     | 6       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_realty_properties_areas`         | 6       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_realty_properties_categories`    | 6       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_realty_properties_landmarks`     | 6       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `mcs_realty_properties_neighbourhood` | 6       | Listing Marketing / IDX (manual listings)                                    | Review per customer                                 |
| `member`                              | 126     | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `member_agreement_acceptances`        | 10      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `member_core`                         | 19      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `member_network`                      | 17      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `member_profile`                      | 56      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `member_profile_temp`                 | 42      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `member_temp`                         | 95      | Compensation & Revenue Share (agents, sponsor tree) + Platform Core profiles | Yes                                                 |
| `member_totalcount`                   | 2       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `messages`                            | 6       | Communication & Chat                                                         | Recent history only                                 |
| `migrations`                          | 1       | Platform Core: settings, offices, audit                                      | Settings and offices yes; logs archived             |
| `missed_earning`                      | 21      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `my_purchase`                         | 8       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `my_service`                          | 14      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `my_subscriptions`                    | 13      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `news`                                | 4       | Communication & Chat                                                         | Recent history only                                 |
| `notification_settings`               | 8       | Platform Core: settings, offices, audit                                      | Settings and offices yes; logs archived             |
| `notifications`                       | 9       | Communication & Chat                                                         | Recent history only                                 |
| `offices`                             | 11      | Platform Core: settings, offices, audit                                      | Settings and offices yes; logs archived             |
| `onboarding_transactions`             | 10      | Transactions (deals) and Onboarding payments                                 | Yes (tmp_orders no)                                 |
| `order_deductions`                    | 22      | Transactions (deals) and Onboarding payments                                 | Yes (tmp_orders no)                                 |
| `orders`                              | 55      | Transactions (deals) and Onboarding payments                                 | Yes (tmp_orders no)                                 |
| `other_wallet`                        | 4       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `package_history`                     | 13      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `payout`                              | 19      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `pending_wallet`                      | 5       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `plans`                               | 121     | Compensation & Revenue Share                                                 | Yes                                                 |
| `post_capping`                        | 4       | Compensation & Revenue Share                                                 | Yes                                                 |
| `promotions`                          | 4       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `pv`                                  | 10      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `rank_system`                         | 20      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `recharge_entry`                      | 11      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `recurring_monthly_deductions`        | 10      | Compensation & Revenue Share                                                 | Yes                                                 |
| `recurring_payment_history`           | 12      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `register_types`                      | 2       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `reseller_business`                   | 5       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `reseller_packages`                   | 5       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `reseller_payment_setting`            | 3       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `revenue_share_allocation`            | 21      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `revenue_share_global_settings`       | 14      | Compensation & Revenue Share                                                 | Yes                                                 |
| `reward_setting`                      | 17      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `rewards`                             | 8       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `salary`                              | 6       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `sample`                              | 1       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `services`                            | 7       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `settings`                            | 3       | Platform Core: settings, offices, audit                                      | Settings and offices yes; logs archived             |
| `shipping_address`                    | 18      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `staff_designation`                   | 5       | Platform Core: roles                                                         | Yes (mapped to roles)                               |
| `staffs`                              | 15      | Platform Core: identity, staff, roles                                        | Users and staff only (passwords not migrated as-is) |
| `survey`                              | 6       | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `survey_user`                         | 5       | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `target_reach_income`                 | 32      | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `tasks`                               | 10      | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `tax_report`                          | 10      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `tbl_rightally_internal_api_nonces`   | 4       | Platform Core: identity (SSO)                                                | No (short-lived nonces)                             |
| `tbl_rightally_sso_handoff_tokens`    | 9       | Platform Core: identity (SSO)                                                | No (short-lived tokens)                             |
| `themes_setting`                      | 6       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `ticket`                              | 9       | Support & Help Centre / Training                                             | Open tickets and articles                           |
| `ticket_reply`                        | 4       | Support & Help Centre / Training                                             | Open tickets and articles                           |
| `tickets`                             | 9       | Support & Help Centre / Training                                             | Open tickets and articles                           |
| `tmp_orders`                          | 18      | Transactions (deals) and Onboarding payments                                 | Yes (tmp_orders no)                                 |
| `todo_categories`                     | 4       | CRM, Reports & KPIs, engagement                                              | Tasks and notes yes; others review                  |
| `traffic_analytics`                   | 7       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `transaction`                         | 34      | Transactions (deals) and Onboarding payments                                 | Yes (tmp_orders no)                                 |
| `transaction_approval_history`        | 11      | Transactions (deals) and Onboarding payments                                 | Yes (tmp_orders no)                                 |
| `transaction_capping`                 | 5       | Compensation & Revenue Share                                                 | Yes                                                 |
| `transfer_balance_records`            | 6       | Dropped (legacy MLM, e-commerce or unused)                                   | No                                                  |
| `uploaded_documents`                  | 7       | Agent Onboarding & Workflows                                                 | Yes (documents copied to new storage)               |
| `user_cards`                          | 11      | Finance: agent billing and subscriptions                                     | Yes (card data stays in Stripe; tokens only)        |
| `user_dashboard_kpi_pref`             | 11      | Reports & KPIs                                                               | KPI definitions only                                |
| `user_dashboard_order`                | 7       | Reports & KPIs                                                               | KPI definitions only                                |
| `wallet`                              | 4       | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `wallet_transaction`                  | 15      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `withdraw_request`                    | 14      | Finance, Payouts & Billing (ledger)                                          | Yes, reconciled to the cent                         |
| `workflow_actions`                    | 8       | Agent Onboarding & Workflows                                                 | Yes (documents copied to new storage)               |
| `workflow_triggers`                   | 7       | Agent Onboarding & Workflows                                                 | Yes (documents copied to new storage)               |
| `workflow_versions`                   | 8       | Agent Onboarding & Workflows                                                 | Yes (documents copied to new storage)               |
| `zoom_meeting`                        | 4       | Communication & Chat                                                         | Recent history only                                 |
