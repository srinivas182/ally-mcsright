# Usability test kit: brokers and agents aged 50+ (SX7)

Run this before launch and after any big change to the screens agents use every day. It takes one person to run it,
five or six people to test, and about 45 minutes per person.

## Who to invite

- Five or six real estate agents or brokers, **aged 50 or over**, who are not technical. Include at least one who uses
  only a phone, and at least one who wears reading glasses.
- Not RightAlly staff, family or friends of the team, or anyone who has used RightAlly before.
- A thank-you of $75–$100 (a gift card) is usual. _(Default, flagged for review: check your local rules on incentives
  and record what you paid.)_

## Before the session

1. Use a **staging** brokerage, never real client data.
2. Sign the tester in with a test account whose two-step sign-in is already set up, or give them a passkey phone.
3. Turn on **Practice with made-up clients** (Help → Practice) so the tester has people to work with.
4. Put the screen at the tester's normal text size. Do not change it for them; note whether they change it.
5. Read the consent script below and get a signature. Record the screen and voice only if they agree.

### Consent script (read aloud)

> "Thank you for helping. We are testing RightAlly, not you: if something is hard, that is our problem to fix. Please
> think out loud as you go. You can stop at any time, and you will still get the thank-you. With your permission we will
> record the screen and your voice; the recording is used only by the RightAlly team to improve the product, and is
> deleted within 90 days. Is that OK?"

## The tasks (read each one aloud; do not show them where to click)

| #   | Task                                                                                   | Done when                                           | Time limit |
| --- | -------------------------------------------------------------------------------------- | --------------------------------------------------- | ---------- |
| 1   | "Sign in. Your code is on this phone."                                                 | They reach the home page                            | 3 min      |
| 2   | "Who should you call first today, and why?"                                            | They name the person and the reason from Today's 10 | 2 min      |
| 3   | "Call Rosa. Pretend she answered. Note that you talked."                               | "We talked" is logged                               | 3 min      |
| 4   | "Add a new person: Maria Lopez, 512-555-0133. She wants to sell in the spring."        | The person exists with the note                     | 4 min      |
| 5   | "Without typing, ask RightAlly to remind you to call Maria next Tuesday." (microphone) | The to-do exists for Tuesday                        | 4 min      |
| 6   | "You are stuck on this page. Get help."                                                | They open Help and choose any option                | 2 min      |
| 7   | "Print a one-page guide for adding a person."                                          | The cheat sheet opens to print                      | 2 min      |
| 8   | "Make the writing on screen bigger."                                                   | Text size is set to Large or Largest                | 2 min      |
| 9   | "You lost your phone. What would you do to get back in?" (on the sign-in page)         | They find "Lost your phone?"                        | 3 min      |
| 10  | "Open this morning's email and log a call from it without signing in."                 | The call is logged from the list page               | 4 min      |

## What to write down (one sheet per tester)

For each task, mark: **Done alone / Done with a hint / Not done**, the time taken, and anything they said.

- Words they did not understand (write the exact word).
- Buttons they could not find, could not read, or tapped by mistake.
- Places they hesitated for more than 10 seconds.
- Anything that worried them ("Did that just send something?").

At the end, ask:

1. "On a scale of 1 to 10, how easy was that?"
2. "What was the most confusing moment?"
3. "Would you use this every day? What would stop you?"

## Pass mark

- Every task is **done alone** by at least 4 of the 6 testers.
- Average ease of 8 or more.
- No tester is worried that something was sent to a client by mistake.

Anything below the pass mark becomes a fix in the next sprint. Any confusing word goes into the plain-words list in
`tests/Architecture/PlainWordsAndSizesTest.php`, so it cannot come back.

## After the session

- Delete recordings within 90 days.
- Remove the testers' practice people (Help → Remove my practice people).
- Write a one-page summary: what passed, the top five problems, and the fixes planned.
