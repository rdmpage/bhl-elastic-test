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
	
	//63496,
	
	/*
	90481,
	339465,
	81069,
	329078,
	*/
	
	//253723, // v 1
	252866, // v 2
	
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

	// index pages
	foreach ($item->hasPart as $part)
	{
		// index the page
		if ($part->additionalType == 'Page')
		{	
			$PageID = str_replace('page/', '', $part->{'@id'});
	
			$doc         = new stdClass;
			$doc->id     = 'page_' . $PageID;
			$doc->type   = 'page';
			
			// things page is a part of
			$doc->itemid = 'item_' . $ItemID;
			
			if (isset($part->isPartOf))
			{
				$doc->partid = [];
				
				foreach ($part->isPartOf as $partid)
				{
					$doc->partid[] = str_replace('part/', 'part_', $partid);
				}
			}
			
			// item-level metadata
			$doc->volume = $item->name;
			$doc->year   = !empty($item->datePublished) ? (int) $item->datePublished : null;
			
			// page-level data
			$doc->name   = !empty($part->name) ? $part->name : '[' . $part->position . ']';
			$doc->text   = get('http://localhost/bhl-light/pagetext/' . $PageID);
			
			// page type(s)
			if (isset($part->keywords))
			{
				$doc->tags = $part->keywords;
			}
			
			// entities associated with this page, such as taxonomic names
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
			
			// debug
			//print_r($doc);
	
			// store document
			$elastic_doc                = new stdClass;
			$elastic_doc->doc_as_upsert = true;
			$elastic_doc->doc           = $doc;
	
			$response = $elastic->send('POST', '_update/' . urlencode($doc->id), json_encode($elastic_doc));
			echo $response . "\n";
		}
	}
	
	// index any parts
	$json = get('http://localhost/bhl-light/api.php?item=' . $ItemID . '&parts');
	$parts = json_decode($json);
	
	foreach ($parts as $part)
	{
		//print_r($part);
		
		$doc         = new stdClass;
		$doc->id     = str_replace('part/', 'part_', $part->{'@id'});
		$doc->type   = 'part';
		
		foreach ($part as $k => $v)
		{
			switch ($k)
			{
				case 'csl':
				case 'name':
				case 'provider':
					$doc->{$k} = $part->{$k};
					break;
					
				case 'thumbnailUrl':
					$doc->thumbnail = str_replace('pagethumb/', '', $part->{$k});
					break;
			
				default:
					break;
			}
		}
		
		// print_r($doc);		
		
		// store document
		$elastic_doc                = new stdClass;
		$elastic_doc->doc_as_upsert = true;
		$elastic_doc->doc           = $doc;

		$response = $elastic->send('POST', '_update/' . urlencode($doc->id), json_encode($elastic_doc));
		echo $response . "\n";		
	}
	
}

?>
