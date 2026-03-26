<?php

require_once(dirname(__FILE__) . '/config.inc.php');
require_once(dirname(__FILE__) . '/elastic.php');
require_once(dirname(__FILE__) . '/catalogueoflife/match_name.php');
require_once(dirname(__FILE__) . '/geotag.php');

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
	//252866, // v 2
	
	21705,
	
);

// Transactions of the Entomological Society of London
$items = array(

43947,
43951,
44059,
44211,
44572,
45285,
48188,
48195,
48243,
48288,
48701,
48719,
48720,
50986,
50987,
50988,
50989,
50990,
50991,
50992,
50993,
50994,
50995,
50996,
50998,
50999,
51000,
51001,
51002,
51003,
51004,
51005,
51006,
51007,
51008,
51009,
51010,
51011,
51012,
51013,
51014,
51020,
51053,
51064,
51068,
51073,
51074,
51195,
51196,
51216,
51218,
51221,
51222,
51227,
51237,
51239,
51240,
51241,
51242,
51245,
51650,
55127,
55130,
55133,
55138,
55146,
96544,
99860,
100203,
105347,
130127,
183290,

);

$items=array(
123000,
192459,
192464,
192477,
192517,
192518,
192546,
192547,
192562,
192563,
192568,
192573,
192807,
192829,
246284,
247050,
247234,
247237,
272993,
273111,
273122,
273130,
273336,
273341,
273342,
273343,
273350,
273364,
319547,
319571,
319950,
319997,
336539,
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
			$annotations          = tag_geo($doc->text);
			$doc->locations       = [];
			$doc->geo_annotations = $annotations;
			foreach ($annotations as $ann) {
				$doc->locations[] = [
					'lat' => $ann->geojson->geometry->coordinates[1],
					'lon' => $ann->geojson->geometry->coordinates[0],
				];
			}
			
			
			
			
			
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
