# Win cards (RF1)

A "Just sold" or "Just closed" page on the brokerage website for a closed deal, shared to Facebook, LinkedIn, X or
email in one tap.

## Rules

- Only for **closed** deals, and only by the deal's agent or a reviewer.
- The agent must tick **"My client agreed to me sharing this."** Who agreed and when is saved and audited.
- The client's name is **never** shown. The page shows the street and city, the month it closed, which side the
  agent represented, the agent's name and the brokerage.
- The price shows only if the agent ticks **Show the price**.
- The message is checked for Fair Housing wording before it is saved; flagged words must be reworded.
- The card can be hidden at any time; a hidden card's page returns "not found" and leaves the sitemap.
- The brokerage website must be live (win cards live there).

## Search and answer engines

Each card has its own page at `/wins/{id}` on the brokerage website with a canonical link, Open Graph tags, an
`Article` in JSON-LD (author: the agent; publisher: the brokerage) and a breadcrumb, and is listed in the sitemap.

## Where

- Deal page › **Share this win** (closed deals).
- Views are counted on the card and shown to the agent.
