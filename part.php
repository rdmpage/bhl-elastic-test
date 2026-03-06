<?php

require_once(dirname(__FILE__) . '/config.inc.php');
require_once(dirname(__FILE__) . '/elastic.php');

$id     = isset($_GET['id'])     ? trim($_GET['id'])     : '';
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csl';

// ── Validate inputs ───────────────────────────────────────────────────────────

if ($id === '' || !preg_match('/^part_\d+$/', $id) || !in_array($format, ['ris', 'bibtex', 'csl']))
{
	http_response_code(400);
	header('Content-Type: text/plain; charset=utf-8');
	exit('Bad request: id must be part_NNN and format must be ris, bibtex, or csl');
}

// ── Fetch the part document from Elasticsearch ────────────────────────────────

$resp = $elastic->send('GET', '_doc/' . urlencode($id));
$obj  = json_decode($resp);

if (!$obj || empty($obj->found))
{
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	exit('Part not found: ' . htmlspecialchars($id));
}

$source = $obj->_source;
$csl    = $source->csl ?? null;

if (!$csl)
{
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	exit('No CSL data for part: ' . htmlspecialchars($id));
}

// Safe base filename (e.g. "part_789")
$filename_base = preg_replace('/[^a-z0-9_-]/i', '_', $id);

// ── Output in requested format ────────────────────────────────────────────────

switch ($format)
{
	case 'csl':
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename_base . '.json"');
		echo json_encode($csl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		break;

	case 'bibtex':
		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename_base . '.bib"');
		echo csl_to_bibtex($csl, $id);
		break;

	case 'ris':
		header('Content-Type: application/x-research-info-systems; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename_base . '.ris"');
		echo csl_to_ris($csl);
		break;
}


// ── CSL → BibTeX ──────────────────────────────────────────────────────────────

function csl_to_bibtex($csl, $id)
{
	$type_map = [
		'article-journal'  => 'article',
		'chapter'          => 'incollection',
		'book'             => 'book',
		'paper-conference' => 'inproceedings',
	];
	$csl_type = $csl->type ?? 'article-journal';
	$bib_type = $type_map[$csl_type] ?? 'misc';

	// Citation key: strip the "part_" prefix
	$key = preg_replace('/^part_/', '', $id);

	$fields = [];

	// Authors
	if (!empty($csl->author))
	{
		$names = [];
		foreach ($csl->author as $a)
		{
			$family = $a->family ?? '';
			$given  = $a->given  ?? '';
			$names[] = $family && $given ? $family . ', ' . $given : ($family ?: $given);
		}
		$fields['author'] = implode(' and ', $names);
	}

	if (!empty($csl->title))               $fields['title']   = $csl->title;
	if (!empty($csl->{'container-title'})) $fields['journal'] = $csl->{'container-title'};
	if (!empty($csl->volume))             $fields['volume']  = $csl->volume;
	if (!empty($csl->issue))              $fields['number']  = $csl->issue;

	// Pages: BibTeX convention uses "--" for ranges
	if (!empty($csl->page))
	{
		$fields['pages'] = str_replace(['-', '–'], '--', $csl->page);
	}

	// Year from CSL issued date-parts
	if (!empty($csl->issued->{'date-parts'}[0][0]))
	{
		$fields['year'] = (string) $csl->issued->{'date-parts'}[0][0];
	}

	if (!empty($csl->DOI)) $fields['doi'] = $csl->DOI;
	if (!empty($csl->URL)) $fields['url'] = $csl->URL;

	// Assemble the entry
	$out = '@' . $bib_type . '{' . $key . ',' . "\n";
	foreach ($fields as $k => $v)
	{
		$out .= '  ' . $k . ' = {' . $v . '},' . "\n";
	}
	$out .= '}' . "\n";

	return $out;
}


// ── CSL → RIS ─────────────────────────────────────────────────────────────────
// RIS spec: each tag is 2 chars, two spaces, " - ", value, CRLF
// https://en.wikipedia.org/wiki/RIS_(file_format)

function csl_to_ris($csl)
{
	$type_map = [
		'article-journal'  => 'JOUR',
		'chapter'          => 'CHAP',
		'book'             => 'BOOK',
		'paper-conference' => 'CONF',
	];
	$csl_type = $csl->type ?? 'article-journal';
	$ris_type = $type_map[$csl_type] ?? 'GEN';

	$lines = [];
	$lines[] = 'TY  - ' . $ris_type;

	// Authors — one AU tag per author
	if (!empty($csl->author))
	{
		foreach ($csl->author as $a)
		{
			$family = $a->family ?? '';
			$given  = $a->given  ?? '';
			$name   = $family && $given ? $family . ', ' . $given : ($family ?: $given);
			if ($name !== '') $lines[] = 'AU  - ' . $name;
		}
	}

	if (!empty($csl->title))               $lines[] = 'TI  - ' . $csl->title;
	if (!empty($csl->{'container-title'})) $lines[] = 'JO  - ' . $csl->{'container-title'};
	if (!empty($csl->volume))             $lines[] = 'VL  - ' . $csl->volume;
	if (!empty($csl->issue))              $lines[] = 'IS  - ' . $csl->issue;

	// Pages: split on hyphen / en-dash
	if (!empty($csl->page))
	{
		$parts = preg_split('/[-–]/', $csl->page, 2);
		$lines[] = 'SP  - ' . trim($parts[0]);
		if (!empty($parts[1])) $lines[] = 'EP  - ' . trim($parts[1]);
	}

	// Year
	if (!empty($csl->issued->{'date-parts'}[0][0]))
	{
		$lines[] = 'PY  - ' . $csl->issued->{'date-parts'}[0][0];
	}

	if (!empty($csl->DOI)) $lines[] = 'DO  - ' . $csl->DOI;
	if (!empty($csl->URL)) $lines[] = 'UR  - ' . $csl->URL;

	$lines[] = 'ER  - ';

	// RIS spec requires CRLF line endings
	return implode("\r\n", $lines) . "\r\n";
}

?>
