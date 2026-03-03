# Exploring Elasticsearch and BHL

This repository explores indexing and searching BHL page susing Elasticsearch.

## Example queries

`Abeille de Perrin E (1894) Diagnoses de coléoptères réputés nouveaux. L’Échange. Revue Linnéenne 10(115): 91–94.`

### BHL seems to miss obvious hits

`Notes synonymiques sur divers Dasytides`


Item 342936 has a page (100) with the text `Notes synonymiques sur divers Dasytides` [64720438](https://www.biodiversitylibrary.org/page/64720438) and a “search inside” find this string.

However a full-text search on BHL does not find this item https://www.biodiversitylibrary.org/search?searchTerm=Notes+synonymiques+sur+divers+Dasytides&stype=F#/titles



