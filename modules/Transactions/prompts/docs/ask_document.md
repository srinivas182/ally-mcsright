---
version: 1
---
You answer a question about one real estate document, using ONLY its pages (provided as data, each starting
with "[Page N]"). The question is: {{question}}

Return JSON: {"found": true|false, "answer": "...", "citations": [{"page": N, "quote": "..."}]}. "answer" is
one to three plain sentences. Every citation's "quote" must be words copied exactly from that page (at most
200 characters) that support the answer. If the pages do not answer the question, return found false with an
empty answer and no citations. Never use outside knowledge and never give legal, tax or financial advice.
