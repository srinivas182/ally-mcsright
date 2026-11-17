# Forms 04: PDF to smart form (FB1)

Status: built in FB1. Tracker row B18.

## What the market leaders do

- **Lone Wolf Transactions (zipForm) and Dotloop** come with association forms already set up. A brokerage's
  own PDF (an in-house addendum or a disclosure) has to be set up by hand: you drag every box onto the page.
- **DocuSign** and **SkySlope Forms** detect fields on uploaded PDFs, but the fields are only boxes. They are not
  tied to the deal, so agents still type the price, address and names into every form.
- **Where they fall short:** detection misses fill-in lines on scanned forms. Field names come out as "Text1"
  or "Text2". And nothing links a field to the deal or to the right signer.

## What RightAlly does (parity plus)

On **Settings › Forms**, open a form and press **Find the fields for me**.

1. **Reads the PDF.** It reads the words on each page with their positions. A scanned form is read by OCR on
   our own servers; nothing goes to an outside service for this step.
2. **Finds the fields.** A run of four or more underscores is a fill-in line. "[ ]" or "☐" is a tick box.
3. **Names each field from the words next to it.** The name comes from the words just before the line, or the
   words under it (for example "Buyer's signature" under a signature line). For a tick box, the words after it
   are used.
4. **Links it to the deal and the signers.** Each field is linked using the same rules as imported association
   forms:
    - price, address and names fill themselves from the deal;
    - signature, initials and date-signed fields go to Buyer 1, Seller 1 and so on.
5. **Optional AI tidy-up** (2 credits). AI improves the names and links using the field labels only. Anything
   it suggests outside our short list of links and signers is ignored. If there are no credits, the rules-based
   result stands.
6. **Adds the fields for checking.** Found fields are added next to fields already placed, never on top of
   them, so you can run it again safely. Nothing is signed or sent from this screen; the agent checks every
   field in the editor first.

If a form has no fill-in lines, the screen says so in plain words.

### Who can use it

Only people who can manage forms. Agents get a 403.

### Easy for agents aged 50+

- One button.
- A plain explanation under it.
- Fields appear in the same visual editor people already use.
