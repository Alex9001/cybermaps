# Cybermaps Budgeted Site Briefing 0.2 Draft

> Experimental vendor proposal · disabled by default · no documented automatic
> consumers

## Purpose

`/llms-tldr.txt` is a bounded, deterministic publication of literal stored
WordPress content. It exists for an operator who wants one inspectable site
briefing with an explicit approximate token budget.

It is not part of the llms.txt proposal, an IETF standard, an MCP resource, an
Agent Skill, a search index, or an AI-generated summary. Cybermaps makes no claim
that model providers discover or consume it.

## Design constraints

The generator must:

1. use the same eligible-content inventory as other Cybermaps publications;
2. execute no shortcode, dynamic block, model, embedding, or remote service;
3. state its exact stable selection order;
4. account for every emitted byte, including the header;
5. include only complete entries;
6. report selected and omitted counts truthfully; and
7. stay within the configured approximate budget.

## Input inventory

`Cybermaps\Discovery\PublicationInventory` supplies published, eligible content
using the unified publication-eligibility decision. The inventory respects
configured AI post types, exclusions, redirects, off-resource canonicals,
Genesis/Mai settings, and supported SEO-plugin signals.

The generator does not inspect drafts, private resources, password-protected
resources, or attachments.

## Selection order

The stable order is:

1. configured pinned resource IDs, in the operator's order;
2. configured content-type order;
3. stored modified time descending; and
4. WordPress object ID ascending as the final tie-breaker.

For each resource the generator renders a complete candidate entry. If adding
that entry and the updated coverage header would exceed the budget, that entry
is omitted and later candidates are still considered.

There is no quality score, taxonomy cluster, entity extractor, semantic
deduplication, information-gain calculation, or inferred priority.

## Extraction

`Cybermaps\Content\VisibleTextExtractor` derives literal visible text from
stored post content. It strips markup and ignores non-rendered stored elements;
it does not execute shortcodes or server-rendered dynamic blocks.

Each briefing entry contains:

- title;
- public URL;
- WordPress content type;
- stored last-modified timestamp when valid; and
- a fixed-length literal extract.

The extract may be incomplete by design, but an entry is never cut midway by
the budgeter.

## Budget calculation

The estimator is intentionally simple and disclosed:

```text
estimated_tokens = ceil(number_of_UTF-8_bytes / 4)
```

The configured range is 1,000–200,000 estimated tokens; the default is 80,000.
This is not a tokenizer for any particular model. It is a repeatable planning
estimate.

Every calculation uses the complete candidate output:

- format metadata;
- selection and extraction disclosures;
- configured license assertion;
- coverage counts; and
- selected entries.

Therefore `token_estimate <= token_budget` for the emitted publication under the
documented estimator.

## Header

The publication states:

```text
Profile: Cybermaps Budgeted Site Briefing
Format-Version: 0.2-draft
Status: Experimental vendor proposal
Known-Automatic-Consumers: none documented
Selection-Method: ...
Content-Extraction: ...
Token-Estimate-Method: ...
Token-Budget: ...
Coverage: selected N of E eligible; omitted O; partial 0
```

`partial` is always zero in 0.2 because the generator includes or omits whole
entries.

A license value is labeled `License-Assertion` because it is supplied by the
site operator. Cybermaps does not verify ownership, legal scope, or
enforceability.

## Caching and delivery

Dynamic responses use a one-hour, generation-fenced WordPress cache with
separate language keys. Content and
relevant settings changes invalidate the discovery cache. With full static
publication enabled, the same generated body can be materialized at
`/llms-tldr.txt`; localized variants can also be materialized when translation
output is configured.

Physical delivery bypasses PHP. It therefore cannot appear in PHP-observed
crawler analytics and uses server-controlled response headers.

Generation examines at most 250 candidates across pinned and ordinary passes,
counting each candidate before SEO checks. `Candidate-Scan` reports examined
candidates separately from eligible entries, and flags truncation only when
another candidate remains beyond that limit. Builds share a durable ownership
lock and a cooperative 20-second work deadline; an unfinished build is a
retryable failure and never replaces a successful publication.

## Limitations

- The byte-to-token ratio is approximate and model-independent.
- Stored-text extraction cannot represent runtime output from dynamic blocks or
  shortcodes.
- The fixed extract can omit important context.
- Pinned IDs express operator priority, not objective importance.
- Stable modified-date ordering favors recent edits, not quality.
- No semantic duplicate detection occurs.
- No automatic consumer or downstream behavior is guaranteed.
- A static file remains stale until the next complete reconciliation.

## Appropriate use

An operator can inspect or deliver the briefing as one bounded snapshot alongside
the canonical pages and `/llms.txt`. Any client should treat each URL as the
authoritative source for full context.

The draft should remain opt-in until a real consumer contract exists. Future
format changes require a version increment, fixtures for exact budget behavior,
and migration documentation; marketing language is not a substitute for
interoperability evidence.
