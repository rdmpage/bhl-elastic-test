<?php

require_once ('config.inc.php');
require_once ('elastic.php');


$basedir = './';

$files = scandir($basedir);

// debugging
//$files = array('item112039.json');

foreach ($files as $filename)
{
	if (preg_match('/\.json$/', $filename, $m))
	{
		$json = file_get_contents($basedir . '/' . $filename);

		$obj = json_decode($json);

		if (empty($obj->Result))
		{
			echo "No result in $filename\n";
			continue;
		}

		$item = $obj->Result[0];

		// Build concatenated OCR text and page offset map.
		// The separator is added between pages so offsets are exact.
		// Pages with no OCR text are skipped (they can never contain a hit).
		$ocr_text    = '';
		$page_offsets = array();
		$separator   = "\n\n";
		$first       = true;

		foreach ($item->Pages as $page)
		{
			$text = isset($page->OcrText) ? $page->OcrText : '';

			if (strlen($text) === 0)
			{
				continue;
			}

			if (!$first)
			{
				$ocr_text .= $separator;
			}

			$start = strlen($ocr_text);
			$ocr_text .= $text;
			$end = strlen($ocr_text) - 1;

			$page_offsets[] = array(
				'page_id' => $page->PageID,
				'start'   => $start,
				'end'     => $end,
			);

			$first = false;
		}

		// Build the ES document
		$doc = new stdClass;
		$doc->id                  = str_replace('.json', '', $filename);
		$doc->item_id             = (int) $item->ItemID;
		$doc->title_id            = (int) $item->TitleID;
		$doc->volume              = $item->Volume;
		$doc->year                = !empty($item->Year)    ? (int) $item->Year    : null;
		$doc->end_year            = !empty($item->EndYear) ? (int) $item->EndYear : null;
		$doc->language            = $item->Language;
		$doc->holding_institution = $item->HoldingInstitution;
		$doc->copyright_status    = $item->CopyrightStatus;
		$doc->item_url            = $item->ItemUrl;
		$doc->ocr_text            = $ocr_text;
		$doc->page_offsets        = $page_offsets;

		$elastic_doc = new stdClass;
		$elastic_doc->doc_as_upsert = true;
		$elastic_doc->doc = $doc;

		$response = $elastic->send('POST', '_update/' . urlencode($doc->id), json_encode($elastic_doc));
		echo $response . "\n";
	}
}

?>
