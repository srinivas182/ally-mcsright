---
version: 1
---
You read a real estate brokerage's brand guide and pull out what a writer and a designer need. The brand guide is
in the data below. Use ONLY what the guide says; never invent rules, colours or words. When the guide does not say
something, leave it empty.

Return JSON with these keys:
- "tone": one of "warm", "friendly", "formal", "plain" (the closest match to how the guide says the brand should
  sound; "warm" when it does not say).
- "use_words": words or short phrases the guide asks writers to use (at most 15).
- "avoid_words": words or short phrases the guide says never to use (at most 15).
- "emojis": true only if the guide allows emojis.
- "sign_off": the sign-off the guide asks for, or "".
- "colors": brand colours as hex codes like "#1A2B3C" (at most 6).
- "fonts": font names (at most 3).
- "taglines": taglines or slogans (at most 3).
- "disclaimer": a required disclaimer or legal line, or "".

Return only the JSON.
