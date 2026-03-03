<?php

require_once ('config.inc.php');
require_once ('elastic.php');

// Accept query from CLI arg or fall back to demo citation
$q = isset($argv[1])
	? $argv[1]
	: "Abeille de Perrin E (1894) Diagnoses de coléoptères réputés nouveaux. L'Échange. Revue Linnéenne 10(115): 91–94.";

// Use custom tags that are easy to locate without ambiguity in OCR text.
// number_of_fragments:0 returns the entire ocr_text with tags injected so
// we can derive exact character offsets by walking the string once.
$request = array(
	'query' => array(
		'match' => array(
			'ocr_text' => array(
				'query'                => $q,
				'minimum_should_match' => '75%',
			)
		)
	),
	'_source' => array(
		'item_id', 'year', 'volume', 'holding_institution', 'item_url', 'page_offsets'
	),
	'highlight' => array(
		'pre_tags'  => array('<HIT>'),
		'post_tags' => array('</HIT>'),
		'fields'    => array(
			'ocr_text' => array(
				'number_of_fragments' => 0,
			)
		)
	)
);

$response = $elastic->send('POST', '_search', json_encode($request));
$result   = json_decode($response);

$output = array(
	'total' => $result->hits->total->value,
	'query' => $q,
	'hits'  => array(),
);

foreach ($result->hits->hits as $hit)
{
	$source  = $hit->_source;

	$hit_out = array(
		'id'                  => $hit->_id,
		'item_id'             => $source->item_id,
		'year'                => $source->year,
		'volume'              => $source->volume,
		'holding_institution' => $source->holding_institution,
		'item_url'            => $source->item_url,
		'pages'               => array(),
	);

	if (!empty($hit->highlight->ocr_text))
	{
		// number_of_fragments:0 gives back the full text as a single element
		$highlighted = $hit->highlight->ocr_text[0];
		$page_hits   = pages_from_highlights($highlighted, $source->page_offsets);

		foreach ($page_hits as $page_id => $snippets)
		{
			$hit_out['pages'][] = array(
				'page_id'  => $page_id,
				'page_url' => 'https://www.biodiversitylibrary.org/page/' . $page_id,
				'snippets' => $snippets,
			);
		}
	}

	$output['hits'][] = $hit_out;
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";


// ---------------------------------------------------------------------------
// Walk the highlighted text once, tracking the running character offset in
// the original (tag-free) text.  For each <HIT>…</HIT> pair, binary-search
// page_offsets to find the containing page, then collect a short readable
// snippet centred on the hit (positions measured in the highlighted string
// so the tags are preserved in the excerpt).

function pages_from_highlights($highlighted, $page_offsets)
{
	$pre      = '<HIT>';
	$post     = '</HIT>';
	$pre_len  = strlen($pre);
	$post_len = strlen($post);
	$context  = 120;           // chars of context on each side of a hit
	$max_per_page = 5;         // cap snippets per page to keep output tidy

	$pages = array();  // page_id => [snippet, …]
	$pos   = 0;        // cursor in $highlighted
	$raw   = 0;        // corresponding offset in the original tag-free text
	$len   = strlen($highlighted);

	while ($pos < $len)
	{
		$tag_pos = strpos($highlighted, $pre, $pos);
		if ($tag_pos === false) break;

		// Advance raw offset by the plain text between pos and the tag
		$raw += $tag_pos - $pos;
		$pos  = $tag_pos + $pre_len;

		$end_tag = strpos($highlighted, $post, $pos);
		if ($end_tag === false) break;

		$match_text = substr($highlighted, $pos, $end_tag - $pos);
		$page_id    = find_page($page_offsets, $raw);

		if ($page_id !== null)
		{
			if (!isset($pages[$page_id]))
				$pages[$page_id] = array();

			if (count($pages[$page_id]) < $max_per_page)
			{
				// Build the snippet from the highlighted string so the
				// <HIT> tags are visible in the output
				$from    = max(0, $tag_pos - $context);
				$to      = min($len, $end_tag + $post_len + $context);
				$snippet = substr($highlighted, $from, $to - $from);

				// Normalise whitespace so OCR line-breaks don't break display
				$snippet = preg_replace('/\s+/', ' ', $snippet);

				$pages[$page_id][] = trim($snippet);
			}
		}

		$raw += strlen($match_text);
		$pos  = $end_tag + $post_len;
	}

	return $pages;
}


// ---------------------------------------------------------------------------
// Binary search: return the page_id whose [start, end] range contains
// $offset, or null if $offset falls in a separator gap between pages.

function find_page($page_offsets, $offset)
{
	$lo = 0;
	$hi = count($page_offsets) - 1;

	while ($lo <= $hi)
	{
		$mid  = intdiv($lo + $hi, 2);
		$page = $page_offsets[$mid];

		if      ($offset < $page->start) $hi = $mid - 1;
		elseif  ($offset > $page->end)   $lo = $mid + 1;
		else                             return $page->page_id;
	}

	return null;  // offset is in a separator gap
}

?>
