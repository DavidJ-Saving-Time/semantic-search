You will receive one or more article blocks in markdown, already normalized. Each block includes:

* A title line in the form `### <Title>`
* An `ID: <NNN>` line immediately under the title (unique within this file)
* Fields like `First page:`, `Summary:`, `Places:`, `Anchors:`, `Topics:`, `Genre:`, and `(p. X)`
* Blocks are separated by a line with three hyphens `---`
* There may be paging markers like `<<PAGE N>>` — ignore these completely.
* Summaries are in modern English; do not analyze period diction. Base context/bias on events/topics, not wording.

Use the exact ID value (the line immediately under the title).

Grounding metadata (treat as facts):
Newspaper: {{NEWSPAPER}}
Issue\_Date: {{ISSUE_DATE}}
Place: London, United Kingdom

Do not repeat the title, summary, or any other fields from the article.

OUTPUT FORMAT — MARKDOWN ONLY
No JSON/YAML/HTML, no code blocks. For each article, output exactly this block. Separate articles with a line containing three hyphens `---`.

ID: <ID>
Historical Context: 2–4 concise sentences placing the topic in its mid-19th-century setting, using the issue date/place/newspaper as anchors.
Bias and Silences: 2–3 concise sentences on establishment bias or propaganda cues, notable omissions, and missing perspectives relative to this newspaper and date/place.

CHECK:
Introduced\_Anchors:

* <Name> (<Year>) – why relevant (≤12 words)
* <Name> (<Year>) – why relevant (≤12 words)
  Window\_OK: \<YES/NO>   # YES only if all years ∈ \[1846–1850] or clearly justified
  DATE\_CONFLICT: \<explain if NO, else NONE>

Context rules:

* You MAY introduce 0–2 specific named anchors (events/statutes/people) to situate the article.
* Each anchor must be directly relevant and within \[1846–1859]. If an anchor outside this window is essential context (e.g., a pivotal precursor), include it sparingly, give the year, and justify in the “why relevant” line; `Window_OK` may still be `YES` if clearly justified.
* After each anchor, include its year in parentheses, e.g., `Encumbered Estates Act (1849)`.
* If you are not ≥80% sure of an anchor’s year, do not use it.
* If the article text implies a later chronology (e.g., figures/events from 1851–1852), set `DATE_CONFLICT` with a one-sentence explanation and do not introduce those as anchors.

Footer rules:

* If you introduce fewer than two anchors, replace the unused line(s) with `Introduced_Anchors: NONE` (keep the two-line structure so parsing is stable).
* Keep British spelling.
* If you cannot add value for a section, write `Insufficient data.`
* If an article is missing an ID line, output `ID: MISSING` for that block.
