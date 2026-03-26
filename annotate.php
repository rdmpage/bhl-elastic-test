<?php

error_reporting(E_ALL);

//----------------------------------------------------------------------------------------
// text is being annotated, highlight is bit being tagged, last_pos is last position 
// we tagged in this text, offset is offset with respect to larger document
function annotation_selector($text, $highlight, &$last_pos, $offset = 0)
{
	$flanking_length = 32;
	
	$selectors = array();
	
	// position
	$start = mb_strpos($text, $highlight, $last_pos, mb_detect_encoding($text));
	$length = mb_strlen($highlight, mb_detect_encoding($highlight));
	$end = $start + $length - 1;
	
	$selector = new stdclass;
	$selector->type = 'TextPositionSelector';
	$selector->start = (Integer)$start;
	$selector->end = (Integer)$end;
	
	$selectors[] = $selector; 
	
	// text loc
	$selector = new stdclass;
	$selector->type = 'TextQuoteSelector';
	$selector->exact = $highlight;
	
	$pre_length = min($start, $flanking_length);
	$pre_start = $start - $pre_length;	
	$selector->prefix = mb_substr($text, $pre_start, $pre_length, mb_detect_encoding($text)); 
	
	$post_length = 	min(mb_strlen($text, mb_detect_encoding($text)) - $end, $flanking_length);					
	$selector->suffix = mb_substr($text, $end + 1, $post_length, mb_detect_encoding($text));
	
	$selectors[] = $selector; 
			
	$last_pos = $end;
	
	return $selectors;
}

?>
