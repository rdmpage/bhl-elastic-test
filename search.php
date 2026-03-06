<?php

require_once ('config.inc.php');
require_once ('elastic.php');
require_once ('catalogueoflife/match_name.php');

$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';

$buckets  = [];
$total    = 0;
$entity   = null;

if ($search_query !== '')
{
	// Entity lookup — check CoL before running the full-text search
	$matches = match_name($search_query);
	if (!empty($matches))
	{
		$entity = $matches[0];   // use first match; ignore homonyms for now
	}

	// Full-text search (always runs regardless of entity match)
	$query = [
		'size'  => 0,
		'query' => [
			'bool' => [
				'should' => [
					['match_phrase' => ['text' => ['query' => $search_query, 'slop' => 3, 'boost' => 3]]],
					['match'        => ['text' => ['query' => $search_query, 'minimum_should_match' => '75%']]],
				],
				'minimum_should_match' => 1,
			]
		],
		'aggs' => [
			'by_year' => [
				'terms' => [
					'field' => 'year',
					'size'  => 500,
					'order' => ['_key' => 'asc'],
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
							'size'    => 3,
							'_source' => ['id', 'itemid', 'name', 'volume', 'year'],
							'highlight' => [
								'pre_tags'  => ['<mark>'],
								'post_tags' => ['</mark>'],
								'fields'    => [
									'text' => ['fragment_size' => 200, 'number_of_fragments' => 2],
								],
							],
						],
					],
				],
			],
		],
	];

	$resp    = $elastic->send('POST', '_search', json_encode($query));
	$obj     = json_decode($resp);
	$total   = $obj->hits->total->value;
	$buckets = $obj->aggregations->by_item_id->buckets;
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
.kp-id a {
	color: #1a0dab;
	text-decoration: none;
}
.kp-id a:hover { text-decoration: underline; }

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
	<button type="submit">Search</button>
</form>

<?php if ($search_query !== ''): ?>

<div class="page-layout">

	<div class="results-column">

		<p class="result-count">
			<?= $total ?> page<?= $total !== 1 ? 's' : '' ?> matched
			across <?= count($buckets) ?> item<?= count($buckets) !== 1 ? 's' : '' ?>
		</p>

		<?php foreach ($buckets as $bucket):
			$top_hit   = $bucket->top_pages->hits->hits[0];
			$source    = $top_hit->_source;
			$thumb_id  = $top_hit->_id;
			$item_url  = 'https://www.biodiversitylibrary.org/item/' . $source->itemid;
			$thumb_url = 'https://www.biodiversitylibrary.org/pagethumb/' . $thumb_id;
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
					$page_url  = 'https://www.biodiversitylibrary.org/page/' . $hit->_id;
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

	</div><!-- .results-column -->

	<?php if ($entity): ?>
	<aside class="knowledge-panel">
		<p class="kp-type"><?= htmlspecialchars($entity->type) ?></p>
		<p class="kp-name"><?= htmlspecialchars($entity->name) ?></p>
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

</body>
</html>
