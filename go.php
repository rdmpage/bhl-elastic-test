<?php

require_once(dirname(__FILE__) . '/config.inc.php');
require_once(dirname(__FILE__) . '/elastic.php');
require_once(dirname(__FILE__) . '/catalogueoflife/match_name.php');

//----------------------------------------------------------------------------------------
function get($url)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,            $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($ch, CURLOPT_HEADER,         1);
	curl_setopt($ch, CURLOPT_TIMEOUT,        120);
	curl_setopt($ch, CURLOPT_COOKIEJAR,      'cookie.txt');
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

	$curl_result = curl_exec($ch);

	if (curl_errno($ch) != 0)
	{
		echo "CURL error: ", curl_errno($ch), " ", curl_error($ch);
		return '';
	}

	$info = curl_getinfo($ch);
	return substr($curl_result, $info['header_size']);
}

//----------------------------------------------------------------------------------------

$items = array(
	//262715,
	//253723,
	
	63496,
	
	/*
	90481,
	339465,
	81069,
	329078,
	*/
	
	252866,
	
);

foreach ($items as $ItemID)
{
	$json = get('http://localhost/bhl-light/api.php?item=' . $ItemID);
	$item = json_decode($json);

	if (!$item)
	{
		echo "Failed to fetch item $ItemID\n";
		continue;
	}

	foreach ($item->hasPart as $part)
	{
		if ($part->additionalType !== 'Page') continue;

		$PageID = str_replace('page/', '', $part->{'@id'});

		$doc         = new stdClass;
		$doc->id     = $PageID;
		$doc->itemid = $ItemID;
		$doc->volume = $item->name;
		$doc->year   = !empty($item->datePublished) ? (int) $item->datePublished : null;
		$doc->name   = !empty($part->name) ? $part->name : '[' . $part->position . ']';
		$doc->text   = get('http://localhost/bhl-light/pagetext/' . $PageID);

		// entities
		$names    = json_decode(get('http://localhost/bhl-light/api.php?page=' . $PageID . '&names'));
		$entities = [];
		if (!empty($names) && is_array($names))
		{
			foreach ($names as $name_string)
			{
				// Assume that name must be in Catalogue of Life to be real
				$matches = match_name($name_string);				
				if (count($matches) > 0)
				{
					// ignore homonyms for now
					$entity = $matches[0];
					$entities[] = $entity;
				
				}
			
				/*
				$entity       = new stdClass;
				$entity->type = 'TaxonName';
				$entity->name = $name_string;
				$entities[]   = $entity;
				*/
			}
		}
		$doc->entities = $entities;

		// geo

		// store document
		$elastic_doc                = new stdClass;
		$elastic_doc->doc_as_upsert = true;
		$elastic_doc->doc           = $doc;

		$response = $elastic->send('POST', '_update/' . urlencode($doc->id), json_encode($elastic_doc));
		echo $response . "\n";
	}
}

?>
