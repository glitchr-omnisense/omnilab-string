# omnilab/string

## Installation

```sh
composer require omnilab/string
```

```php
use Omnilab\StringDb\StringSourceFactory;

$string = (new StringSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'caller_identity' => 'omnilab',    // optional: sent with every call, as STRING asks
    'species' => '9606',               // optional: the NCBI taxon names and accessions are looked up in
    'partners' => 10,                  // optional: how many partners entity() asks for (0: none)
    'required_score' => 400,           // optional: the lowest combined score, in thousandths
]);
```

The namespace is `Omnilab\StringDb`: `string` is a type in PHP, not a name for a class.

## The scheme

A protein is named in the core's `string` scheme (`Scheme::STRING`) by its STRING id, as STRING
writes it: the NCBI taxon, a dot, the protein's id in its genome (`9606.ENSP00000269305` for human
TP53, `511145.b0001` for an *E. coli* protein, `4932.YAL001C` for a yeast one). It is read from its
shape (a taxon of three digits or more, a dot, an id with a letter), from `string:` and from its page
(`https://string-db.org/network/9606.ENSP00000269305`).

## Which API

`https://string-db.org/api/json/` (STRING 12.5 on 2026-10-15; `version` gives it, with the stable
address `version-12-5.string-db.org` to pin it):

| Method | Calls |
|---|---|
| `entity()` | `get_string_ids?identifiers=...&species=...&limit=1` (the mapping: a STRING id, an accession or a name to the protein, its name, annotation, species), then `interaction_partners?identifiers=<STRING id>&species=...&limit=<partners>&required_score=...` unless `partners` is 0 |
| `partners()` | `interaction_partners` (and `get_string_ids` first for anything but a STRING id) |
| `network()` | `network?identifiers=<a>%0d<b>...&species=...&required_score=...` |
| `enrichment()` | `enrichment?identifiers=<a>%0d<b>...&species=...` |

Every call carries `caller_identity` (STRING's request). A STRING id says its species; an
accession or a name is looked up in the configured one (`9606`). An identifier of a base STRING
does not read (ChEBI, NCBI Gene, Wikidata, Reactome...) is refused before any call; a name STRING
maps to nothing is `null` (STRING answers an empty list). There is no `search()`:
`get_string_ids` maps a name to its best protein, it does not rank texts ("hemoglobin" gives one
protein, AHSP).

## What an entity holds

`id` (`string:9606.ENSP00000494175`), `label` (STRING's preferred name, `HBB`), `kind` `protein`,
`synonyms` (the protein's name, `Hemoglobin subunit beta`), `description` (STRING's annotation
after the name: UniProt's function; STRING cuts a long one, `…` then ends it), `url` (its page),
`image` (the network picture of STRING's API), and:

| Key | API | Value |
|---|---|---|
| `preferred_name` | preferredName | `HBB` |
| `protein_name` | the annotation's first part | `Hemoglobin subunit beta` |
| `species` | taxonName | `Homo sapiens` |
| `interaction` (one per partner, the best first) | interaction_partners' score | `0.999`, labelled `Interaction with HBA2` |

`identifiers`: its own, its Ensembl protein when the STRING id holds one (`ensembl:ENSP00000494175`),
and the UniProt accession it was asked by (STRING mapped it: the same protein). `related`:
`organism` (`ncbi.taxon:9606`), `interactor` (the partners' STRING ids, in the order of their
scores). The `Collector` follows none of them.

## Outside the contract

An interaction is two proteins and a score, not an entity: `partners()` and `network()` give
`Interaction` objects - `a`, `b` (STRING ids), `nameA`, `nameB`, `score` (0 to 1), `channels` (the
score of each evidence channel: `neighborhood`, `fusion`, `cooccurrence`, `coexpression`,
`experiments`, `databases`, `textmining`) and `evidence()` (the channels that support it, the
strongest first). `enrichment()` gives `Enrichment` objects - `category` (STRING's: `Process`,
`Function`, `Component`, `KEGG`, `RCTM`, `Pfam`, `InterPro`, `HPO`, `GWAS`, `DISEASES`,
`TISSUES`, `COMPARTMENTS`, `Keyword`, `PMID`...), `term`, `description`, `count`, `background`,
`pValue`, `fdr`, `proteins`, and `identifier()`, the term in a scheme of the core when it has one
(GO, InterPro, Pfam, HPO, EFO for a GWAS trait, Reactome: STRING writes `HSA-9927020`).

```php
foreach ($string->partners('9606.ENSP00000494175', 5) as $i) {
    printf("%s %s %.3f %s\n", $i->nameA, $i->nameB, $i->score, implode(', ', array_keys($i->evidence())));
}
```

```
HBB HBA2 0.999 experiments, coexpression, databases, textmining, cooccurrence
HBB HBZ 0.999 experiments, databases, textmining, coexpression, cooccurrence
HBB HBD 0.999 coexpression, databases, experiments, textmining, cooccurrence
HBB HBA1 0.998 coexpression, experiments, textmining, cooccurrence
HBB HBE1 0.995 databases, coexpression, experiments, textmining, cooccurrence
```

## The Collector: hemoglobin beta from UniProt

UniProt gives a protein by its accession; STRING reads accessions:

```php
$collector->collect('uniprot', 'P68871', 1, ['string']);
```

Against the real services on 2026-10-15: three calls (UniProt's entry, STRING's mapping and
partners) in 3.1 s, `sources` = uniprot, string; identifiers `uniprot:P68871`, `ncbi.gene:3043`,
`ensembl:ENSG00000244734` (UniProt's), `string:9606.ENSP00000494175`, `ensembl:ENSP00000494175`
(STRING's); UniProt's properties and STRING's (`interaction`, `preferred_name`); the ten partners in
`related('interactor')`.

## What the API does not give here

The aliases of a protein (STRING's downloads hold them), its sequence and homologs, the
interactions' evidence details (the publications, the experiments), the networks of several
species, the values-and-ranks enrichment, PPI enrichment p-values (`ppi_enrichment`), the
functional annotation of one protein (`functional_annotation`), and the images other than a
protein's network.

## Verified, and not

Against the real service on 2026-10-15 (STRING 12.5): hemoglobin beta by its UniProt accession,
its STRING id and its page; TP53 by its name (its annotation cut by STRING); a name STRING maps to
nothing (an empty list); the ten best partners of HBB (HBA2, HBZ, HBD, HBA1...) and of TP53 at
medium confidence; the network among HBB, HBA1 and HBA2 (three interactions); the enrichment of six
globin genes (185 terms, Pfam's Globin PF00042, Reactome's heme assimilation). The fixtures in
`Tests/Fixtures/` are its answers. Each call took 0.5 to 2 s.

Not verified: STRING's behaviour past one call a second (none was made); a POST for long lists
(GET is used: a few hundred names fit); species other than human.
