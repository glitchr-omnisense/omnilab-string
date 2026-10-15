# omnilab/string

STRING for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a protein by its STRING id
(`9606.ENSP00000269305`, TP53), a UniProt accession, an Ensembl protein or a name in a species, with
its preferred name, protein name, annotation and species, its Ensembl protein as a cross-reference,
and its interaction partners as related things - each partner's combined score a property; the
network among several proteins, and their functional enrichment (GO, KEGG, Reactome, Pfam,
InterPro, diseases, tissues...). Through STRING's API, no key.

```php
$string = (new StringSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$protein = $string->entity(Identifier::of(Scheme::UNIPROT, 'P68871'));   // or '9606.ENSP00000494175', 'HBB'
$protein->label;                              // HBB
$protein->value('protein_name');              // Hemoglobin subunit beta
$protein->identifiers()->keys();              // string:9606.ENSP00000494175, ensembl:ENSP00000494175, uniprot:P68871
$protein->related('interactor')->keys();      // string:9606.ENSP00000251595 (HBA2), ... (10)
$protein->property('interaction');            // Interaction with HBA2: 0.999
$string->partners('9606.ENSP00000494175', 5); // Interaction objects: names, score, evidence channels
$string->network(['HBB', 'HBA1', 'HBA2']);    // the three interactions among them
$string->enrichment(['HBB', 'HBA1', 'HBA2', 'HBD', 'HBZ', 'ALAS2']);   // 185 terms, FDR < 5 %
```

```yaml
omnilab:
    sources:
        string:
            factory: string
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                caller_identity: 'omnilab'     # sent with every call, as STRING asks
                species: '9606'                # the NCBI taxon a name or an accession is looked up in
                partners: 10                   # entity()'s partners (0: none, one call)
                required_score: 400            # 400 medium, 700 high, 900 highest confidence
```

A protein is named in the core's `string` scheme. The `Collector` reaches STRING from any source
that gives a UniProt accession or an Ensembl protein (`$collector->collect('uniprot', 'P68871')`).

## Terms of use

STRING's data are under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)
([string-db.org/cgi/access](https://string-db.org/cgi/access)); cite Szklarczyk D. et al., "The
STRING database in 2025: protein networks with directionality of regulation", Nucleic Acids Res.
2025, [10.1093/nar/gkae1113](https://doi.org/10.1093/nar/gkae1113). STRING asks for one call a
second at most and for the caller's identity: the source waits 1 s between two calls and sends
`caller_identity`.

License: MIT.
