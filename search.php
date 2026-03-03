<?php

require_once ('config.inc.php');
require_once ('elastic.php');


$query_json = '{
  "size": 0,
  "query": {
    "bool": {
      "should": [
        {
          "match_phrase": {
            "text": {
              "query": "<QUERY>",
              "slop": 3,
              "boost": 3
            }
          }
        },
        {
          "match": {
            "text": {
              "query": "<QUERY>",
              "minimum_should_match": "75%"
            }
          }
        }
      ],
      "minimum_should_match": 1
    }
  },
  "aggs": {
    "by_item_id": {
      "terms": {
        "field": "itemid",
        "size": 20,
        "order": {
          "max_score.value": "desc"
        }
      },
      "aggs": {
        "max_score": {
          "max": {
            "script": {
              "lang": "painless",
              "source": "_score"
            }
          }
        },
        "top_pages": {
          "top_hits": {
            "size": 3,
            "_source": ["id", "itemid", "volume", "year"],
            "highlight": {
              "pre_tags": ["<mark>"],
              "post_tags": ["</mark>"],
              "fields": {
                "text": {
                  "fragment_size": 200,
                  "number_of_fragments": 2
                }
              }
            }
          }
        }
      }
    }
  }
}';


$search_query = 'Notes synonymiques sur divers Dasytides';

$query_json = str_replace('<QUERY>', $search_query, $query_json);

$resp = $elastic->send('POST', '_search?pretty', $post_data = $query_json);

$obj = json_decode($resp);

//print_r($obj);

echo '<html>';
echo '<head>';
echo '<style>
body {
	font-family:sans-serif;
	padding:2em;
}
mark {
	font-weight:bold;
	background: none;
}
</style>';
echo '</head>';
echo '<body>';

echo '<h1>' . $search_query . '</h1>';

echo '<ul>';

foreach ($obj->aggregations->by_item_id->buckets as $bucket)
{
	echo '<li>';

	echo '<img height="100" src="https://www.biodiversitylibrary.org/pagethumb/' . $bucket->top_pages->hits->hits[0]->_id . '">';
	
	echo  $bucket->key . " " . $bucket->top_pages->hits->hits[0]->_source->volume . " <b>" . $bucket->max_score->value . "</b>";
	
	echo '<ul>';
	foreach ($bucket->top_pages->hits->hits as $hit)
	{
		echo '<li>';
		
		echo '<ul>';
		foreach ($hit->highlight->text as $highlight)
		{
			echo '<li>' . $highlight . '</li>';
		}
		echo '</ul>';
		
		
		echo '</li>';
	
	}
	echo '</ul>';
	
	
	echo '</li>';
}


echo '</ul>';
echo '</body>';
echo '</html>';


?>
