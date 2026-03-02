<?php

// Populate

require_once(dirname(__FILE__) . '/config.inc.php');

$fetch_counter = 1;

//----------------------------------------------------------------------------------------
function get($url)
{
	$data = '';
	
	$ch = curl_init(); 
	curl_setopt ($ch, CURLOPT_URL, $url); 
	curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1); 
	curl_setopt ($ch, CURLOPT_FOLLOWLOCATION,	1); 
	curl_setopt ($ch, CURLOPT_HEADER,		  1);  
	
	// timeout (seconds)
	curl_setopt ($ch, CURLOPT_TIMEOUT, 120);

	curl_setopt ($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
	
	curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST,		  0);  
	curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER,		  0);  
	
	$curl_result = curl_exec ($ch); 
	
	if (curl_errno ($ch) != 0 )
	{
		echo "CURL error: ", curl_errno ($ch), " ", curl_error($ch);
	}
	else
	{
		$info = curl_getinfo($ch);
		
		// print_r($info);		
		 
		$header = substr($curl_result, 0, $info['header_size']);
		
		// echo $header;
		
		//exit();
		
		$data = substr($curl_result, $info['header_size']);
		
	}
	return $data;
}


//----------------------------------------------------------------------------------------
$items = array(
112039,
112042,
342936,
45575,
49381,
346076,
112048,
112922,
);

foreach ($items as $ItemID)
{
	$filename = 'item' . $ItemID . '.json';
	
	if (!file_exists($filename))
	{
		$parameters = array(
			'op' 		=> 'GetItemMetadata',
			'id'		=> $ItemID,
			'parts'		=> 't',
			'pages'		=> 't',
			'ocr'		=> 't',
			'apikey'	=> $config['bhl_key'],
			'format'	=> 'json'
		);
	
		$url = 'https://www.biodiversitylibrary.org/api3?' . http_build_query($parameters);

		$json = get($url);
		
		file_put_contents($filename, $json);
	
	}
	
	$json = file_get_contents($filename);
	
	echo $json;


}

?>


