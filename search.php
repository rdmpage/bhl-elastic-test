<?php

require_once ('config.inc.php');
require_once ('elastic.php');
require_once ('catalogueoflife/match_name.php');

$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';

$mode           = isset($_GET['parts']) ? 'parts' : 'all';
$buckets        = [];
$part_buckets   = [];
$part_meta      = [];     // partid => part _source, fetched via _mget
$total          = 0;
$entity         = null;
$entity_prefix  = null;   // e.g. 'taxonname'
$entity_value   = null;   // e.g. 'Sheldonia'
$chart_data     = [];     // label => page count for the time histogram
$chart_max      = 1;      // highest count in $chart_data (for scaling bar heights)

// ── Parse optional prefix syntax: "taxonname:Sheldonia" ──────────────────────
if ($search_query !== '' && preg_match('/^(\w+):(.+)$/i', $search_query, $m))
{
	$entity_prefix = strtolower($m[1]);
	$entity_value  = trim($m[2]);
}

// Supported entity prefixes; unknown prefixes fall through to full-text search
$known_prefixes = ['taxonname'];
$is_entity_search = in_array($entity_prefix, $known_prefixes);

if ($search_query !== '')
{
	// ── Knowledge panel lookup ───────────────────────────────────────────────
	// Use extracted value for prefixed searches, full query otherwise
	$col_lookup = $is_entity_search ? $entity_value : $search_query;
	$matches    = match_name($col_lookup);
	if (!empty($matches))
	{
		$entity = $matches[0];   // first match; homonyms ignored for now
	}

	// ── Build ES query ───────────────────────────────────────────────────────
	if ($is_entity_search && $entity_prefix === 'taxonname')
	{
		// Exact match on the indexed entity name; highlight_query finds the
		// term in the OCR text so snippets still show context
		$es_query        = ['term' => ['entities.name.keyword' => $entity_value]];
		$highlight_query = ['match_phrase' => ['text' => ['query' => $entity_value, 'slop' => 3]]];
	}
	else
	{
		// Standard full-text search
		$es_query = [
			'bool' => [
				'should' => [
					['match_phrase' => ['text' => ['query' => $search_query, 'slop' => 3, 'boost' => 3]]],
					['match'        => ['text' => ['query' => $search_query, 'minimum_should_match' => '75%']]],
				],
				'minimum_should_match' => 1,
			],
		];
		$highlight_query = null;
	}

	$highlight = [
		'pre_tags'  => ['<mark>'],
		'post_tags' => ['</mark>'],
		'fields'    => ['text' => ['fragment_size' => 200, 'number_of_fragments' => 2]],
	];
	if ($highlight_query)
	{
		$highlight['highlight_query'] = $highlight_query;
	}

	$query = [
		'size'  => 0,
		// Always restrict to page documents so part docs don't pollute results
		'query' => [
			'bool' => [
				'must'   => $es_query,
				'filter' => ['term' => ['type' => 'page']],
			],
		],
		'aggs'  => [
			'by_year' => [
				'terms' => [
					'field' => 'year',
					'size'  => 500,
					'order' => ['_key' => 'asc'],
				],
			],
			// Global agg ignores the query — gives us the full DB year range
			// so zero-hit years can be shown on the chart x-axis
			'year_range' => [
				'global' => new stdClass(),
				'aggs'   => [
					'min_year' => ['min' => ['field' => 'year']],
					'max_year' => ['max' => ['field' => 'year']],
				],
			],
			'by_item_id' => [
				'terms' => [
					'field' => 'itemid',
					'size'  => 20,
					'order' => ['max_score.value' => 'desc'],
				],
				'aggs' => [
					'max_score' => [
						'max' => ['script' => ['lang' => 'painless', 'source' => '_score']],
					],
					'top_pages' => [
						'top_hits' => [
							'size'      => 3,
							'_source'   => ['id', 'itemid', 'name', 'volume', 'year'],
							'highlight' => $highlight,
						],
					],
				],
			],
			// Same pattern as by_item_id but buckets on partid — only pages
			// with a partid field contribute, so items-only pages are excluded
			'by_partid' => [
				'terms' => [
					'field' => 'partid',
					'size'  => 20,
					'order' => ['max_score.value' => 'desc'],
				],
				'aggs' => [
					'max_score' => [
						'max' => ['script' => ['lang' => 'painless', 'source' => '_score']],
					],
					'top_pages' => [
						'top_hits' => [
							'size'      => 2,
							'_source'   => ['id', 'partid', 'itemid', 'year'],
							'highlight' => $highlight,
						],
					],
				],
			],
		],
	];

	$resp    = $elastic->send('POST', '_search', json_encode($query));
	$obj     = json_decode($resp);
	$total   = $obj->hits->total->value;
	$buckets      = $obj->aggregations->by_item_id->buckets;
	$part_buckets = $obj->aggregations->by_partid->buckets ?? [];

	// ── Fetch part metadata via _mget (parts mode only) ──────────────────────
	// Bucket keys are the partid values (e.g. "part_789"); use them directly
	// as document IDs since that is how go.php stored the part documents.
	if ($mode === 'parts' && !empty($part_buckets))
	{
		$part_ids  = array_map(fn($b) => $b->key, $part_buckets);
		$mget_resp = $elastic->send('POST', '_mget', json_encode(['ids' => $part_ids]));
		$mget_obj  = json_decode($mget_resp);
		foreach ($mget_obj->docs as $doc)
		{
			if ($doc->found)
			{
				$part_meta[$doc->_id] = $doc->_source;
			}
		}
	}

	// ── Build year/decade chart data ─────────────────────────────────────────
	// Use the global min/max so the x-axis covers the whole DB, not just hits
	$year_range  = $obj->aggregations->year_range ?? null;
	$db_min_year = $year_range ? (int)$year_range->min_year->value : null;
	$db_max_year = $year_range ? (int)$year_range->max_year->value : null;

	$year_buckets = $obj->aggregations->by_year->buckets ?? [];

	if ($db_min_year !== null && $db_max_year !== null)
	{
		$span = $db_max_year - $db_min_year;

		if ($span > 50)
		{
			// Aggregate hit counts by decade from the query results
			$decade_hits = [];
			foreach ($year_buckets as $yb)
			{
				$d = (int)(floor((int)$yb->key / 10) * 10);
				$decade_hits[$d] = ($decade_hits[$d] ?? 0) + $yb->doc_count;
			}

			// Fill every decade in the DB range, zero where no hits
			$first = (int)(floor($db_min_year / 10) * 10);
			$last  = (int)(floor($db_max_year  / 10) * 10);
			for ($d = $first; $d <= $last; $d += 10)
			{
				$chart_data[$d . 's'] = $decade_hits[$d] ?? 0;
			}
		}
		else
		{
			// Index hit counts by year
			$year_hits = [];
			foreach ($year_buckets as $yb)
			{
				$year_hits[(int)$yb->key] = $yb->doc_count;
			}

			// Fill every year in the DB range, zero where no hits
			for ($y = $db_min_year; $y <= $db_max_year; $y++)
			{
				$chart_data[(string)$y] = $year_hits[$y] ?? 0;
			}
		}

		$chart_max = !empty($chart_data) ? max($chart_data) : 1;
		if ($chart_max < 1) $chart_max = 1;
	}
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BHL Search<?= $search_query ? ' – ' . htmlspecialchars($search_query) : '' ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
	font-family: Arial, sans-serif;
	font-size: 14px;
	color: #202124;
	padding: 24px 32px;
}

/* ── Search bar ── */
.search-bar {
	display: flex;
	gap: 8px;
	margin-bottom: 20px;
	max-width: 860px;
}
.search-bar input[type=text] {
	flex: 1;
	padding: 10px 16px;
	font-size: 16px;
	border: 1px solid #dfe1e5;
	border-radius: 24px;
	outline: none;
}
.search-bar input[type=text]:focus {
	border-color: #4285f4;
	box-shadow: 0 1px 6px rgba(66,133,244,.3);
}
.search-bar button {
	padding: 10px 20px;
	background: #f8f9fa;
	border: 1px solid #dfe1e5;
	border-radius: 24px;
	font-size: 14px;
	cursor: pointer;
	color: #3c4043;
}
.search-bar button:hover { background: #e8eaed; }

/* ── Page layout: results + optional knowledge panel ── */
.page-layout {
	display: flex;
	gap: 32px;
	align-items: flex-start;
}
.results-column {
	flex: 1;
	min-width: 0;
	max-width: 620px;
}

/* ── Result count ── */
.result-count {
	font-size: 13px;
	color: #70757a;
	margin-bottom: 20px;
}

/* ── Individual result card ── */
.result {
	display: flex;
	gap: 20px;
	margin-bottom: 32px;
}
.result-thumb {
	flex: 0 0 80px;
}
.result-thumb a img {
	width: 80px;
	display: block;
	border: 1px solid #e0e0e0;
}
.result-body {
	flex: 1;
}
.result-title {
	font-size: 18px;
	margin-bottom: 2px;
}
.result-title a {
	color: #1a0dab;
	text-decoration: none;
}
.result-title a:hover { text-decoration: underline; }
.result-meta {
	font-size: 13px;
	color: #70757a;
	margin-bottom: 10px;
}

/* ── Snippet boxes ── */
.snippet {
	border: 1px solid #e0e0e0;
	border-radius: 4px;
	padding: 10px 14px;
	margin-bottom: 8px;
}
.snippet-label {
	font-size: 11px;
	font-weight: bold;
	letter-spacing: .05em;
	color: #70757a;
	margin-bottom: 6px;
	text-transform: uppercase;
}
.snippet-label a {
	color: #1a0dab;
	text-decoration: none;
	font-weight: normal;
	font-size: 12px;
	letter-spacing: 0;
	text-transform: none;
}
.snippet-label a:hover { text-decoration: underline; }
.snippet-text {
	font-size: 13px;
	line-height: 1.6;
	color: #3c4043;
}
mark {
	background: none;
	font-weight: bold;
	color: #202124;
}

/* ── Knowledge panel ── */
.knowledge-panel {
	flex: 0 0 280px;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	padding: 20px;
	background: #fff;
}
.kp-type {
	font-size: 11px;
	font-weight: bold;
	letter-spacing: .08em;
	text-transform: uppercase;
	color: #70757a;
	margin-bottom: 6px;
}
.kp-name {
	font-size: 22px;
	font-weight: bold;
	color: #202124;
	margin-bottom: 12px;
	font-style: italic;
}
.kp-id {
	font-size: 12px;
	color: #70757a;
}
.kp-name a {
	color: inherit;
	text-decoration: none;
}
.kp-name a:hover { text-decoration: underline; }
.kp-id a {
	color: #1a0dab;
	text-decoration: none;
}
.kp-id a:hover { text-decoration: underline; }

/* ── Cite button (parts mode) ── */
.cite-btn {
	background: none;
	border: none;
	color: #1a0dab;
	font-size: 12px;
	cursor: pointer;
	padding: 2px 0;
	text-decoration: underline;
	font-family: inherit;
	display: inline;
}
.cite-btn:hover { color: #1558d6; }

/* ── Cite dialog ── */
.cite-dialog {
	border: none;
	border-radius: 8px;
	box-shadow: 0 4px 24px rgba(0,0,0,.25);
	padding: 0;
	max-width: 560px;
	width: 90vw;
	position: fixed;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
}
.cite-dialog::backdrop { background: rgba(0,0,0,.4); }
.cite-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px 12px;
	border-bottom: 1px solid #e0e0e0;
}
.cite-title {
	font-size: 16px;
	font-weight: 600;
	color: #202124;
	margin: 0;
}
.cite-close {
	background: none;
	border: none;
	font-size: 18px;
	cursor: pointer;
	color: #70757a;
	padding: 2px 4px;
	font-family: inherit;
	line-height: 1;
}
.cite-close:hover { color: #202124; }
.cite-body {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-height: 60vh;
	overflow-y: auto;
}
.cite-format-label {
	font-size: 11px;
	font-weight: bold;
	letter-spacing: .05em;
	text-transform: uppercase;
	color: #70757a;
	display: block;
	margin-bottom: 6px;
}
.cite-format-text {
	font-size: 13px;
	line-height: 1.5;
	color: #3c4043;
	background: #f8f9fa;
	border: 1px solid #e0e0e0;
	border-radius: 4px;
	padding: 10px 12px;
	margin: 0;
	white-space: pre-wrap;
	word-break: break-word;
	font-family: inherit;
}
.cite-footer {
	padding: 12px 20px 16px;
	border-top: 1px solid #e0e0e0;
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}
.cite-dl-label { font-size: 12px; color: #70757a; }
.cite-dl-link  { font-size: 12px; color: #1a0dab; text-decoration: none; }
.cite-dl-link:hover { text-decoration: underline; }

/* ── Year/decade histogram ── */
.year-chart {
	margin-bottom: 24px;
	overflow-x: auto;
}
.chart-bars {
	display: flex;
	align-items: flex-end;
	gap: 3px;
	height: 80px;
	border-bottom: 1px solid #dadce0;
}
.bar-col {
	flex: 1;
	min-width: 14px;
	height: 100%;
	display: flex;
	flex-direction: column;
	justify-content: flex-end;
	cursor: default;
}
.bar-col .bar {
	width: 100%;
	background: #4285f4;
	border-radius: 2px 2px 0 0;
}
.bar-col:hover .bar { background: #1a73e8; }
.chart-labels {
	display: flex;
	gap: 3px;
	padding-top: 3px;
}
.chart-labels .bar-label {
	flex: 1;
	min-width: 14px;
	font-size: 9px;
	color: #70757a;
	text-align: center;
	white-space: nowrap;
	overflow: hidden;
}

/* ── Mode tabs (All / Parts) ── */
.mode-tabs {
	display: flex;
	gap: 0;
	margin-bottom: 16px;
	border-bottom: 1px solid #e0e0e0;
}
.mode-tabs .tab {
	padding: 8px 16px;
	font-size: 13px;
	color: #70757a;
	text-decoration: none;
	border-bottom: 3px solid transparent;
	margin-bottom: -1px;
}
.mode-tabs .tab:hover {
	color: #1a0dab;
	border-bottom-color: #dadce0;
}
.mode-tabs .tab.active {
	color: #1a73e8;
	border-bottom-color: #1a73e8;
	font-weight: 500;
}

/* ── Mobile: panel stacks above results ── */
@media (max-width: 768px) {
	body { padding: 16px; }
	.page-layout { flex-direction: column; }
	.knowledge-panel {
		order: -1;
		flex: none;
		width: 100%;
	}
	.results-column { max-width: 100%; }
}
</style>
</head>
<body>

<form class="search-bar" method="get" action="">
	<input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search BHL…" autofocus>
	<?php if ($mode === 'parts'): ?>
	<input type="hidden" name="parts" value="">
	<?php endif ?>
	<button type="submit">Search</button>
</form>

<?php if ($search_query !== ''): ?>

<nav class="mode-tabs">
	<a href="?q=<?= urlencode($search_query) ?>"
	   class="tab<?= $mode === 'all'   ? ' active' : '' ?>">All</a>
	<a href="?q=<?= urlencode($search_query) ?>&amp;parts"
	   class="tab<?= $mode === 'parts' ? ' active' : '' ?>">Parts</a>
</nav>

<p class="result-count">
	<?php if ($mode === 'parts'): ?>
		<?= $total ?> page<?= $total !== 1 ? 's' : '' ?> matched
		across <?= count($part_buckets) ?> part<?= count($part_buckets) !== 1 ? 's' : '' ?>
	<?php elseif ($is_entity_search): ?>
		<?= $total ?> page<?= $total !== 1 ? 's' : '' ?> mentioning
		<em><?= htmlspecialchars($entity_value) ?></em>
		across <?= count($buckets) ?> item<?= count($buckets) !== 1 ? 's' : '' ?>
	<?php else: ?>
		<?= $total ?> page<?= $total !== 1 ? 's' : '' ?> matched
		across <?= count($buckets) ?> item<?= count($buckets) !== 1 ? 's' : '' ?>
	<?php endif ?>
</p>

<div class="page-layout">

	<div class="results-column">

		<?php if (!empty($chart_data)): ?>
		<div class="year-chart">
			<div class="chart-bars">
				<?php foreach ($chart_data as $label => $count):
					// Non-zero bars get at least 2% so a single-page year is visible;
					// zero-count columns get no bar at all
					$pct = $count > 0 ? max(2, round($count / $chart_max * 100)) : 0;
					$tip = $label . ': ' . $count . ' page' . ($count !== 1 ? 's' : '');
				?>
				<div class="bar-col" title="<?= htmlspecialchars($tip) ?>">
					<div class="bar" style="height:<?= $pct ?>%"></div>
				</div>
				<?php endforeach ?>
			</div>
			<div class="chart-labels">
				<?php foreach ($chart_data as $label => $count): ?>
				<div class="bar-label"><?= htmlspecialchars($label) ?></div>
				<?php endforeach ?>
			</div>
		</div>
		<?php endif ?>

		<?php if ($mode === 'all'): ?>

		<?php foreach ($buckets as $bucket):
			$top_hit   = $bucket->top_pages->hits->hits[0];
			$source    = $top_hit->_source;
			$thumb_id  = $top_hit->_id;
			$item_url  = 'https://www.biodiversitylibrary.org/item/' . str_replace('item_', '', $source->itemid);
			$thumb_url = 'https://www.biodiversitylibrary.org/pagethumb/' . str_replace('page_', '', $thumb_id);
			$title     = $source->volume ?: 'Item ' . $source->itemid;
			$year      = $source->year;
		?>
		<div class="result">

			<div class="result-thumb">
				<a href="<?= $item_url ?>" target="_blank">
					<img src="<?= $thumb_url ?>" alt="Thumbnail">
				</a>
			</div>

			<div class="result-body">
				<h2 class="result-title">
					<a href="<?= $item_url ?>" target="_blank"><?= htmlspecialchars($title) ?></a>
				</h2>
				<p class="result-meta"><?= $year ?> &middot; Biodiversity Heritage Library</p>

				<?php foreach ($bucket->top_pages->hits->hits as $hit):
					$page_url  = 'https://www.biodiversitylibrary.org/page/' . str_replace('page_', '', $hit->_id);
					$page_name = !empty($hit->_source->name) ? ' ' . htmlspecialchars($hit->_source->name) : '';
				?>
				<div class="snippet">
					<p class="snippet-label">
						Found on page<?= $page_name ?> &ndash;
						<a href="<?= $page_url ?>" target="_blank">View page</a>
					</p>
					<?php foreach ($hit->highlight->text as $fragment): ?>
					<p class="snippet-text">&hellip;<?= $fragment ?>&hellip;</p>
					<?php endforeach ?>
				</div>
				<?php endforeach ?>

			</div>

		</div>
		<?php endforeach ?>

		<?php elseif ($mode === 'parts'): ?>

		<?php foreach ($part_buckets as $bucket):
			$part      = $part_meta[$bucket->key] ?? null;
			$part_url  = 'https://www.biodiversitylibrary.org/part/' . str_replace('part_', '', $bucket->key);
			$title     = $part ? ($part->name ?? 'Part ' . $bucket->key) : 'Part ' . $bucket->key;

			// Authors from CSL author array
			$authors = '';
			if ($part && !empty($part->csl->author))
			{
				$names = [];
				foreach ($part->csl->author as $a)
				{
					$n = trim(($a->family ?? '') . ($a->given ? ', ' . $a->given : ''));
					if ($n) $names[] = $n;
				}
				$authors = implode('; ', $names);
			}

			// Container title (journal / book)
			$container = $part->csl->{'container-title'} ?? '';

			// Year: prefer CSL issued date, fall back to page year
			$first_hit = $bucket->top_pages->hits->hits[0];
			$year = '';
			if (!empty($part->csl->issued->{'date-parts'}[0][0]))
			{
				$year = $part->csl->issued->{'date-parts'}[0][0];
			}
			elseif (!empty($first_hit->_source->year))
			{
				$year = $first_hit->_source->year;
			}

			// Thumbnail: prefer part's own thumbnail page, fall back to first matching page
			if ($part && !empty($part->thumbnail))
			{
				$thumb_url = 'https://www.biodiversitylibrary.org/pagethumb/' . $part->thumbnail;
			}
			else
			{
				$thumb_url = 'https://www.biodiversitylibrary.org/pagethumb/' . str_replace('page_', '', $first_hit->_id);
			}

			// Build meta line from non-empty pieces
			$meta_parts = array_filter([$authors, $year, $container], fn($s) => $s !== '');
		?>
		<div class="result">

			<div class="result-thumb">
				<a href="<?= $part_url ?>" target="_blank">
					<img src="<?= $thumb_url ?>" alt="Thumbnail">
				</a>
			</div>

			<div class="result-body">
				<h2 class="result-title">
					<a href="<?= $part_url ?>" target="_blank"><?= htmlspecialchars($title) ?></a>
				</h2>
				<?php if ($meta_parts): ?>
				<p class="result-meta"><?= implode(' &middot; ', array_map('htmlspecialchars', $meta_parts)) ?></p>
				<?php endif ?>

				<?php if ($part): ?>
				<button class="cite-btn"
				        onclick="document.getElementById('cite-<?= htmlspecialchars($bucket->key) ?>').showModal()">Cite</button>
				<?php endif ?>

				<?php foreach ($bucket->top_pages->hits->hits as $hit):
					$page_url  = 'https://www.biodiversitylibrary.org/page/' . str_replace('page_', '', $hit->_id);
					$page_name = !empty($hit->_source->name) ? ' ' . htmlspecialchars($hit->_source->name) : '';
				?>
				<div class="snippet">
					<p class="snippet-label">
						Found on page<?= $page_name ?> &ndash;
						<a href="<?= $page_url ?>" target="_blank">View page</a>
					</p>
					<?php foreach ($hit->highlight->text as $fragment): ?>
					<p class="snippet-text">&hellip;<?= $fragment ?>&hellip;</p>
					<?php endforeach ?>
				</div>
				<?php endforeach ?>

			</div>

		</div>

		<?php if ($part): ?>
		<dialog id="cite-<?= htmlspecialchars($bucket->key) ?>" class="cite-dialog"
		        data-csl="<?= htmlspecialchars(json_encode($part->csl)) ?>">
			<div class="cite-header">
				<h3 class="cite-title">Cite this article</h3>
				<button class="cite-close" onclick="this.closest('dialog').close()" aria-label="Close">&#x2715;</button>
			</div>
			<div class="cite-body">
				<div class="cite-format">
					<span class="cite-format-label">APA</span>
					<p class="cite-format-text cite-apa"></p>
				</div>
				<div class="cite-format">
					<span class="cite-format-label">BibTeX</span>
					<pre class="cite-format-text cite-bibtex"></pre>
				</div>
				<div class="cite-format">
					<span class="cite-format-label">CSL-JSON</span>
					<pre class="cite-format-text"><?= htmlspecialchars(json_encode($part->csl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
				</div>
			</div>
			<div class="cite-footer">
				<span class="cite-dl-label">Download:</span>
				<a class="cite-dl-link" href="#">RIS</a>
				<a class="cite-dl-link" href="#">BibTeX</a>
				<a class="cite-dl-link" href="#">CSL-JSON</a>
			</div>
		</dialog>
		<?php endif ?>

		<?php endforeach ?>

		<?php endif ?>

	</div><!-- .results-column -->

	<?php if ($entity): ?>
	<aside class="knowledge-panel">
		<p class="kp-type"><?= htmlspecialchars($entity->type) ?></p>
		<p class="kp-name">
			<a href="?q=taxonname:<?= urlencode($entity->name) ?>">
				<?= htmlspecialchars($entity->name) ?>
			</a>
		</p>
		<p class="kp-id">
			Catalogue of Life:
			<a href="https://www.catalogueoflife.org/data/taxon/<?= urlencode($entity->id) ?>" target="_blank">
				<?= htmlspecialchars($entity->id) ?>
			</a>
		</p>
	</aside>
	<?php endif ?>

</div><!-- .page-layout -->

<?php endif ?>

<script>
// Close any <dialog> when the user clicks its backdrop (outside the box)
document.querySelectorAll('.cite-dialog').forEach(function(d) {
	d.addEventListener('click', function(e) { if (e.target === d) d.close(); });
});
</script>
</body>
</html>
