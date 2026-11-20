<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards;

/**
 * Tables kept in the control plane (DS2). In production they live in a different database from the cells, so a
 * brokerage's export, restore or move never touches them; on one-database setups (local, tests) they share the cell's
 * database and are left out by name. An architecture test keeps this list in step with the migrations.
 */
final class ControlTables
{
    public const ALL = ['ads_boost_forms', 'api_partner_apps', 'billing_addons', 'billing_agent_plans', 'billing_collections', 'billing_commission_tiers', 'billing_gateway_events', 'billing_packages',
        'billing_partner_statements', 'billing_price_notices', 'billing_settings', 'billing_tenant_subscriptions', 'brokerage_exports', 'brokerage_keys', 'brokerage_moves',
        'brokerage_restores', 'canva_returns', 'connect_routes', 'content_bookings', 'content_desks', 'content_drafts', 'content_index_stats', 'cost_catalog', 'feature_switches', 'credit_packs', 'credit_rate_card', 'credit_settings', 'crm_inbound_addresses', 'csp_reports', 'watchtower_checks', 'watchtower_downtimes', 'watchtower_heartbeats', 'watchtower_incident_brokerages', 'watchtower_incident_days', 'watchtower_incidents', 'watchtower_settings', 'watchtower_timings', 'go_live_plans',
        'health_checks', 'help_videos', 'hq_app_requests', 'hq_connect_apps', 'hq_messaging', 'hq_messaging_tenants', 'hq_oauth_clients', 'hq_site_branding', 'hq_site_branding_tenants',
        'identity_directory', 'licence_records', 'marketing_page_versions', 'marketing_pages', 'marketing_posts', 'mcp_central_clients', 'mcp_central_links', 'mls_authorizations', 'movewise_ad_settings', 'movewise_ad_spend', 'movewise_agents', 'movewise_chats',
        'movewise_connections', 'movewise_invoices', 'movewise_package_agents', 'movewise_packages', 'movewise_partners', 'movewise_people', 'movewise_phone_codes',
        'movewise_relay_numbers', 'movewise_saved_homes', 'movewise_saved_searches', 'movewise_seat_requests', 'movewise_sessions', 'movewise_settlements', 'movewise_shows',
        'movewise_sign_ins', 'network_brokerages', 'network_profiles', 'network_referrals', 'outside_services', 'movewise_states', 'movewise_status_events', 'outreach_texting_registrations', 'pay_instant_limits', 'pay_rail_platform', 'pay_rail_refs', 'signups',
        'sms_threads', 'studio_music', 'studio_packages', 'support_access', 'support_access_requests', 'support_tickets', 'support_visits', 'tenant_addons', 'tenant_seats', 'usage_calls', 'usage_tenant_policies'];
}
