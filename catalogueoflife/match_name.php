<?php

require_once (dirname(__FILE__) . '/sqlite.php');

function match_name($name)
{
	$result = [];

	$sql = 'SELECT * FROM nameusage INNER JOIN taxon_paths USING(taxonID)
	WHERE scientificName="' . $name . '"';
	
	$data = db_get($sql);
	
	foreach ($data as $row)
	{
		$name = new stdclass;
		
		$name->id   = $row->taxonID;
		$name->type = 'TaxonName';
		$name->name = $row->scientificName;
		$name->path = json_decode($row->path_json);
		
		$result[] = $name;
	}
	
	return $result;	
}

// test
if (0)
{
	$names = match_name('Sheldonia wolkbergensis');	
	print_r($names);
}

?>
