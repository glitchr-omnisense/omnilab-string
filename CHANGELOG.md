# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab STRING: a protein by its STRING id (9606.ENSP00000269305), UniProt accession, Ensembl
  protein or name - its preferred name, protein name, annotation, species and Ensembl protein - its
  interaction partners as related things with their combined scores, the network among proteins with
  each evidence channel's score, and their functional enrichment (GO, KEGG, Reactome, Pfam,
  InterPro, diseases), through STRING's API, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/string` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
