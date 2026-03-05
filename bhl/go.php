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

		foreach ($item->Pages as $page)
		{
			$text = isset($page->OcrText) ? $page->OcrText : '';

			if (strlen($text) === 0)
			{
				continue;
			}
			
			
			$doc = new stdclass;
			$doc->id = $page->PageID;
			$doc->itemid = $page->ItemID;
			$doc->text = $text;
			
			$doc->volume  = $item->Volume;
			$doc->year    = !empty($item->Year)    ? (int) $item->Year    : null;
			
			$elastic_doc = new stdClass;
			$elastic_doc->doc_as_upsert = true;
			$elastic_doc->doc = $doc;
	
			$response = $elastic->send('POST', '_update/' . urlencode($doc->id), json_encode($elastic_doc));
			echo $response . "\n";
		}

	}
}

?>
