# 19 · IDX coverage, connectors and MLS onboarding (IDX1–IDX10)

Agreed with Srini on 21 Nov 2026 from the brokers' documents (Repliers & Spark workflow, Super Admin & broker
onboarding, connector blueprint, multi-MLS PRD), adjusted to RightAlly.

## Decisions

- **Launch states:** Alabama, Florida, Louisiana, Mississippi, Texas (all Texas boards; HAR first). HQ can open any state.
- **Connectors:** Repliers (main for Texas and Florida boards it carries) and Spark API (main for MLS United). MLS Grid,
  Trestle, Bridge and direct RESO stay available as backups. Keys are set in HQ › Outside services like other
  connectors (write-only, Off/Test/Live). Brokers and agents never see connector names.
- **One board = one winning connector = one signed agreement.** HQ can move one brokerage to another connector.
- **Price:** $499 includes one board. Each further board (any state) adds that board's fee: our connector cost plus the
  connector's margin (default 30%), or the fee HQ sets. Defaults for HQ to review.
- **Repliers:** copy listings into RightAlly if Repliers allows it (one search path, same MLS rules, duplicates and AI);
  otherwise a live-search engine with a short cache, behind the same search.
- **Disclaimers are read-only for brokerages:** the MLS logo, disclaimer and attribution come from the board's IDX
  display template, set by HQ only; brokerages can never edit or remove them.

## Build order

| Sprint | What                                                                                                                                                                                                    |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| IDX1   | States (all 50, launch 5 open), MLS board directory (main/backup connector, our cost, client fee, IDX template), connectors (status, margin)                                                            |
| IDX2   | Spark connector (RESO replication endpoint), tested with Spark's test key                                                                                                                               |
| IDX3   | Repliers connector, tested with Repliers' test key                                                                                                                                                      |
| IDX4   | Per-board status (demo → awaiting signature → sent → MLS review → live / needs fixing / cancelled), HQ approval queue, per-brokerage connector override, broker-supplied Spark keys; approval reminders |
| IDX5   | Demo listings on every new site (brokerage can switch off), each board going live on its own                                                                                                            |
| IDX6   | IDX agreement per board: template, signing order, send-to and CC/BCC, keep a copy, site check first; nightly compliance watch                                                                           |
| IDX7   | Sign-up board picker by state, $499 with one board plus further boards, add or remove boards later                                                                                                      |
| IDX8   | "My board isn't listed" requests; agents inheriting the brokerage's boards                                                                                                                              |
| IDX9   | Automatic area pages (city, neighbourhood, school) from live listings                                                                                                                                   |
| IDX10  | Plain-English home search through Ailly; instant new-listing alerts (opt-in, 8am–9pm)                                                                                                                   |
