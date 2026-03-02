<?php

error_reporting(E_ALL);

// $Id: //

/**
 * @file config.php
 *
 * Global configuration variables (may be added to by other modules).
 *
 */

global $config;

$local = true;


if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

$config['bhl_key'] =  getenv('BHL_APIKEY');


if ($local)
{
	$config['elastic_options'] = array(
			'index' 	=> 'bhltest',
			'protocol' 	=> 'http',
			'host' 		=> '127.0.0.1',
			'port' 		=> 9200
			);
}
else
{			
	$config['elastic_options'] = array(
			'index' 	=> 'bhltest',
			'protocol' 	=> 'http',
			'host' 		=> '65.108.58.109',
			'port' 		=> 80,
			'user' 		=> getenv('ELASTIC_USERNAME'),
			'password' 	=> getenv('ELASTIC_PASSWORD'),			
			);			
}

?>
