<?php

require_once ('config.inc.php');
require_once ('elastic.php');

// Drop existing index so we start fresh with the correct mapping.
$response = $elastic->send('DELETE', '');
echo "DELETE index: $response\n";

// Mapping notes:
//
// ocr_text uses term_vector "with_positions_offsets" so the highlighter can
// use stored term vectors rather than re-analysing the field at query time.
// This bypasses the max_analyzed_offset limit (default 1M chars) which items
// with many pages will exceed. It also enables the _termvectors API to return
// exact character offsets for matched terms — used client-side to resolve
// which pages contain each hit.
//
// page_offsets is stored as raw JSON but not indexed ("enabled": false).
// It is retrieved alongside each hit and used client-side to map hit
// character offsets back to page IDs via binary search.

$mapping = array(
	'settings' => array(
		'index' => array(
			// Raised as a safety net for any plain highlight calls that do
			// not go via term vectors. 10M chars covers the largest BHL items.
			'highlight' => array(
				'max_analyzed_offset' => 10000000,
			),
		),
	),
	'mappings' => array(
		'properties' => array(
			'id'                  => array('type' => 'keyword'),
			'item_id'             => array('type' => 'integer'),
			'title_id'            => array('type' => 'integer'),
			'volume'              => array('type' => 'keyword'),
			'year'                => array('type' => 'integer'),
			'end_year'            => array('type' => 'integer'),
			'language'            => array('type' => 'keyword'),
			'holding_institution' => array('type' => 'keyword'),
			'copyright_status'    => array('type' => 'keyword'),
			'item_url'            => array('type' => 'keyword'),
			'ocr_text'            => array(
				'type'        => 'text',
				'term_vector' => 'with_positions_offsets',
			),
			'page_offsets'        => array(
				'type'    => 'object',
				'enabled' => false,
			),
		),
	),
);

$response = $elastic->send('PUT', '', json_encode($mapping));
echo "PUT index: $response\n";

?>
