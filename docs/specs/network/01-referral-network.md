# Agent referral network (AR1)

**Status:** built in AR1. Trust Score in AR2; AI networking assistant in AR3.

## The problem

Agents send clients who move away to agents they barely know, found through a Facebook group or a franchise
directory. They chase updates by text, and the referral fee is often forgotten. Franchise networks (for example
large franchise referral systems) work only inside one brand; referral companies (the "referral agent"
businesses) take 25–35% of the fee themselves.

## What RightAlly does

- Any RightAlly brokerage can switch the network on (**Settings › Referral network**) and set its usual fee
  (default **25%**, flagged for review).
- Agents and brokers make a **network profile**: the cities or ZIP codes they work in, buyers or sellers or both,
  languages, licence state and number, a few words, and (only if they choose) a phone number for a call button
  and a public page.
- The broker can mark a profile's licence as **checked**. Changing the licence number clears the check.
- **Find an agent** by city or ZIP, side and language. Agents with a higher Trust Score (AR2) and checked licences
  come first.
- **Send a referral**: side, area, price range, timing, language, what the client needs, the fee, and the client's
  name and email or phone. The sending agent must tick that the client agreed to their details being passed on.
- The receiving agent gets an email and has **72 hours** to answer. Until they accept they see only the client's
  needs, never who the client is.
- **Accepting** records the referral agreement in plain words ("X agrees to pay 25% of the commission they earn
  on this client's buyer side to Y brokerage, brokerage to brokerage, at closing"), who agreed and when, and adds
  the client to the receiving agent's contacts.
- **Declining**, or not answering in 72 hours, withdraws the referral and **deletes the client's details**. The
  sender is told and can choose another agent.
- The receiving agent keeps the sender up to date: under contract (optionally on one of their deals, which adds
  the outgoing referral fee to that deal's commission), closed (with the fee owed), or didn't work out.
- Fees are paid **brokerage to brokerage, outside RightAlly**. The receiving side marks "fee sent"; the sending side
  confirms "fee received".

## Privacy and safety

- Profiles never show email addresses; phones only when the agent shares them.
- Client details are encrypted and visible only to the sender and, after accepting, the receiver.
- Emails about a referral never contain the client's details.
- RightAlly takes **no share** of referral fees and moves no money. Placement fees are not offered.
- Nothing is sent in bulk; every referral is one agent to one agent.

## Public pages (SEO and AEO)

Agents who choose "public" get `https://app.rightally.io/agents/{name}`: indexable, canonical, with a
`RealEstateAgent` in JSON-LD (areas served, languages, brokerage), and the Trust Score once it exists.

## For review

- The default referral fee (25%) and the 72-hour answer window.
- The agreement wording should be checked by an attorney before launch; some states require referral agreements in
  a set form or require that fees go only between licensed brokerages (they do here).
