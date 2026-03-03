# Exploring Elasticsearch and BHL

This repository explores indexing and searching BHL page susing Elasticsearch.

## Indexing and search design

### Indexing strategy: page-level documents

Each Elasticsearch document represents a single BHL page, not an entire item. The alternative — one document per item with all page OCR concatenated — was rejected because:

- A single item can have hundreds or thousands of pages, producing very large documents that are slow to highlight and expensive to score
- Page-level indexing gives exact attribution of matches to individual pages without any post-processing
- Highlights can be returned directly per page without offset bookkeeping

Fields indexed per document:

| Field | Type | Notes |
|-------|------|-------|
| `id` | `long` | BHL PageID, used as the ES document `_id` |
| `itemid` | `keyword` | BHL ItemID; keyword (not text) because it is only used for aggregation, not full-text search |
| `text` | `text` | Full OCR text of the page, analysed with the `folding` analyser (see below) |
| `volume` | `keyword` | Volume string from the BHL item metadata |
| `year` | `integer` | Start year of the item |

Pages with no OCR text are skipped at index time.

### Custom analyser: ASCII folding

The `text` field uses a custom analyser named `folding`:

```json
{
  "tokenizer": "standard",
  "filter": ["lowercase", "asciifolding"]
}
```

**Why:** BHL holds multilingual content — French, Latin, German, etc. — and OCR frequently drops or corrupts diacritics (`Échange` → `Echange`, `réputés` → `reputes`). Without folding, a query containing accented characters fails to match OCR text that lost those accents, and vice versa. The `asciifolding` filter normalises both the indexed text and the query terms at search time, so diacritic differences are invisible to the scorer.

`index_options: offsets` is also set on `text` so the unified highlighter can mark up results efficiently without re-analysing the stored text.

### Query design

Queries use a `bool` with two `should` clauses, both targeting `text`:

```json
{
  "bool": {
    "should": [
      { "match_phrase": { "text": { "query": "…", "slop": 3, "boost": 3 } } },
      { "match":        { "text": { "query": "…", "minimum_should_match": "75%" } } }
    ],
    "minimum_should_match": 1
  }
}
```

**`match_phrase` (boost 3):** Rewards pages where the query terms appear as a contiguous sequence. This fires strongly when a full citation or taxon name is present verbatim, and is the right signal for high-confidence matches. `slop: 3` allows up to three word transpositions, accommodating OCR line-break artefacts where a word is split or reordered across lines.

**`match` (minimum_should_match 75%):** Catches pages that contain most of the query terms but not as a phrase — useful for longer citation strings where the OCR has degraded the exact sequence. The 75% threshold was chosen to exclude noise from short common words that appear throughout the corpus (the original 60% threshold produced too many false positives).

**`minimum_should_match: 1`** on the outer `bool` means at least one clause must match; in practice the `match` clause is always the weaker constraint, so this just enforces that at least 75% of terms are present.

A document matching both clauses scores higher than one matching only `match`, which is correct: a page that contains both the phrase and the individual terms almost certainly contains the target text.

### Result aggregation

Results are aggregated by `itemid` so a client can display search hits grouped by item:

```json
{
  "terms": { "field": "itemid", "size": 20, "order": { "max_score.value": "desc" } },
  "aggs": {
    "max_score": { "max": { "script": { "lang": "painless", "source": "_score" } } },
    "top_pages": { "top_hits": { "size": 3, … } }
  }
}
```

**`max_score`** takes the highest `_score` of any page in each item bucket and uses it to rank items. This means an item with one very strong match ranks above an item with many weaker matches — which is the desired behaviour for citation and taxon-name searches.

**`top_pages`** is a `top_hits` sub-aggregation that returns the top 3 scoring pages per item, each with their highlighted fragments. This gives the client everything needed to render grouped results (thumbnail, snippets, page links) in a single query with no second round-trip.

The top-level `size` is set to `0` because the flat `hits` list is redundant — the aggregation is the result set.

## Example queries

`Abeille de Perrin E (1894) Diagnoses de coléoptères réputés nouveaux. L’Échange. Revue Linnéenne 10(115): 91–94.`

### BHL seems to miss obvious hits

`Notes synonymiques sur divers Dasytides`


Item 342936 has a page (100) with the text `Notes synonymiques sur divers Dasytides` [64720438](https://www.biodiversitylibrary.org/page/64720438) and a “search inside” find this string.

However a full-text search on BHL does not find this item https://www.biodiversitylibrary.org/search?searchTerm=Notes+synonymiques+sur+divers+Dasytides&stype=F#/titles



