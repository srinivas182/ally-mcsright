# Movewise MW3: the agent and broker program

Status: **Built** (sprint MW3). Follows [00-overview](00-overview.md) §6 and §9.

## 1. Who does what

| Who                    | Where                                             | What                                                                                                                                       |
| ---------------------- | ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Agent or owner         | Back office → **Movewise** (`/movewise`)          | Applies in a state, builds the profile card, signs the program terms, sets up packages                                                     |
| Owner                  | Same page                                         | Confirms which of their agents work with the brokerage; buys a broker package for confirmed agents                                         |
| HQ                     | HQ → **Movewise agents** (`/hq/movewise/program`) | Sets prices per state, reviews cards and discipline records, checks licences by hand when needed, pauses or removes agents, approves seats |
| Agent not on RightAlly | movewisehomes.com/**agents**                      | Asks for a free Movewise Agent seat                                                                                                        |

## 2. Going live: five checks

An agent is live in a state only when all five pass. `Agents::missing()` lists what is left in plain English. The same list shows on the agent's page and in HQ.

1. **Licence active in that state.**
    - Checked against state records through the Roster `LicenceVerifier` (ARELLO once signed).
    - The name must match the agent's last name.
    - Without a records service, the status is `manual` and HQ ticks "checked by hand".
    - Re-checked every 30 days by `movewise:recheck-licences` (daily at 04:41).
2. **Brokerage confirmed.**
    - The brokerage's owner confirms in RightAlly. An owner applying for themselves is confirmed automatically.
    - Seat agents' brokerages aren't on RightAlly, so HQ confirms by phone or email.
3. **Public discipline record checked by HQ.**
4. **Profile card approved by HQ.**
    - The card shows the name, a short introduction (Fair Housing screened; wording that describes people rather than service is refused), languages, years and up to 4 specialties. The headshot comes from Studio → Brand kit.
    - The card has no phone number: people reach agents only through the relay (MW5).
    - Changing an approved card sends it back for review.
5. **Program terms signed.** The version (`Agents::TERMS_VERSION`), time and IP are recorded. A new terms version asks again.

**When a check fails later:**

- If a check fails later (a lapsed licence, a changed card), the agent pauses with reason `checks`, and their packages pause too.
- Once the checks pass again, they resume automatically.
- A pause or removal by HQ needs a reason and is never undone automatically. HQ resumes it explicitly.

## 3. Pricing (HQ, per state)

HQ switches on each model in a state and sets its price:

- **Per connection:** a buyer price and a seller price, in credits.
- **Per appointment:** credits.
- **Share at close:** a percentage, at most 50%.

A model is offered only when it is both on and priced, so nothing is ever free by accident. Changes apply to new introductions only and are audited.

## 4. Packages

| Setting                  | Rule                                                                                                                         |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| State                    | A Movewise state. A package stays in its state.                                                                              |
| Areas                    | ZIP codes (5 digits, up to 200) and/or cities (up to 50); at least one                                                       |
| Price range              | Lowest and highest; highest blank means no limit                                                                             |
| Lead types and languages | Buyers, sellers or both; English, Spanish or both                                                                            |
| Monthly cap              | 1 to 5 while the only agent is in the **New** tier; up to 50 per agent on a broker package; up to 200 with a Preferred agent |
| Daily cap                | Optional, never above the monthly cap                                                                                        |
| Working hours            | Per weekday, start before end, Central or Mountain time (El Paso). The MW4 reply timer runs only in these hours.             |
| Pricing model            | One the state offers now                                                                                                     |
| Payment                  | Agent package: the agent's own credits. Broker package: the brokerage pool. Refunds go back to the same wallet (MW4).        |

**Going live** needs all of these:

- the state is live on Movewise;
- the model is still offered;
- at least one of the package's agents is live;
- the paying wallet holds at least one introduction's price for the package's lead types.

Agents never see the brokerage's packages. Owners see every package in their account.

## 5. The free Movewise Agent seat

1. The agent fills in the form on `/agents`. It works without JavaScript, checks that the request came from Movewise's own page, allows 5 requests an hour per IP, and checks the licence right away when state records are available.
2. HQ approves. RightAlly then:
    - creates a **Solo** account for the agent;
    - gives it the "Movewise Agent seat" package (`backoffice.basic`: contacts, tasks, inbox, calendar), free of charge;
    - emails the agent an owner invitation.
3. When they sign in and apply, the application starts from their request (state, licence, brokerage) and is marked as a seat.

HQ can't approve a request whose licence state records show as not active, not found or under another name.

## 6. Data

The program's tables are on the control plane, next to the other Movewise data:

- `movewise_agents`
- `movewise_packages`
- `movewise_package_agents`
- `movewise_seat_requests`
- `movewise_states.pricing`

Every decision is audited in the agent's account: applied, confirmed, reviewed, terms signed, paused, removed, package saved, activated or paused.

## 7. Not in MW3

The following come in later sprints:

- **MW4:** choosing the three agents, consent, charges and refunds.
- **MW6:** the scorecard and tier recalculation. Tiers stay **New** until then, so caps stay small, as the overview intends.
- **MW7:** public agent profile pages with reviews.

## 8. Tests

`tests/Feature/Movewise/MovewiseProgramTest.php` covers:

- **Applying:** the licence check (including a name mismatch), the Fair Housing refusal, the five checks, the owner's confirmation, HQ review, and an edited card going back for review.
- **Pricing:** HQ pricing rules.
- **Agent packages:** validation, the cap for New agents, hours, areas, the wallet and live checks, and a monthly re-check that pauses an agent and their package.
- **Broker packages:** owner only, confirmed agents only, paid from the pool, and hidden from agents.
- **Seats:** the seat flow from the public form through HQ approval to the application.
