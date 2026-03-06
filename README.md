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

## Entities

A BHL page may contain mentions of entities such as taxonomic names, people, and places. For taxonomic names we will already have a list of names that BHL has found on the page. We map these names to the Catalogue of Life (CoL), retrieving a unique identifier for the name, and also an array of identifiers corresponding to the path from the root (“Biota”) to the taxon with that name. This path will enable us to query by higher taxa (e.g., all items about frogs).

### CoL database

The Catalogue of Life database can be downloaded and imported into SQLite. The key table is `nameusage` which lists names, their CoL identifier, and the parent of each taxon.

### Creating taxon paths

ChatGPT suggested the following approach to add taxon paths to the SQLite database. Firstly create a table:

```
DROP TABLE IF EXISTS taxon_paths;

CREATE TABLE taxon_paths (
  taxonID   TEXT PRIMARY KEY,
  path_json TEXT NOT NULL
);
```

Then we create the paths:

```
INSERT OR REPLACE INTO taxon_paths(taxonID, path_json)
WITH RECURSIVE down(taxonID, parentID, path_json) AS (
  SELECT taxonID, parentID, json_array(taxonID)
  FROM nameusage
  WHERE parentID IS NULL
  UNION ALL
  SELECT c.taxonID, c.parentID, json_insert(down.path_json, '$[#]', c.taxonID)
  FROM nameusage c
  JOIN down ON c.parentID = down.taxonID
)
SELECT taxonID, path_json
FROM down;
```

A query to match a name is:

```
SELECT taxonID, scientificName, path_json FROM nameusage INNER JOIN taxon_paths USING(taxonID) WHERE scientificName="Sheldonia wolkbergensis";
```

## Geotagging


## Example queries

`Abeille de Perrin E (1894) Diagnoses de coléoptères réputés nouveaux. L’Échange. Revue Linnéenne 10(115): 91–94.`

### BHL seems to miss obvious hits

`Notes synonymiques sur divers Dasytides`


Item 342936 has a page (100) with the text `Notes synonymiques sur divers Dasytides` [64720438](https://www.biodiversitylibrary.org/page/64720438) and a “search inside” find this string.

However a full-text search on BHL does not find this item https://www.biodiversitylibrary.org/search?searchTerm=Notes+synonymiques+sur+divers+Dasytides&stype=F#/titles



