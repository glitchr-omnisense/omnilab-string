<?php

namespace Omnilab\StringDb;

use Omnilab\Model\Identifier;

/**
 * One term enriched in a set of proteins, as STRING's enrichment gives it:
 * its category (Process, Function, Component for GO; KEGG, RCTM for
 * Reactome, Pfam, InterPro, DISEASES, TISSUES, HPO, Keyword, PMID...),
 * its term and description, how many of the proteins it holds against
 * how many of the genome, the p-value and the false discovery rate, the
 * proteins' names.
 */
final readonly class Enrichment
{
    /**
     * @param list<string> $proteins the preferred names of the proteins the term holds
     */
    public function __construct(
        public string $category,
        public string $term,
        public ?string $description,
        public int $count,
        public int $background,
        public float $pValue,
        public float $fdr,
        public array $proteins = [],
    ) {
    }

    /** @param array<string, mixed> $row a row of enrichment */
    public static function fromRow(array $row): ?self
    {
        if (!isset($row['category'], $row['term']) || !is_numeric($row['p_value'] ?? null) || !is_numeric($row['fdr'] ?? null)) {
            return null;
        }

        return new self(
            (string) $row['category'],
            (string) $row['term'],
            \is_string($row['description'] ?? null) ? $row['description'] : null,
            (int) ($row['number_of_genes'] ?? 0),
            (int) ($row['number_of_genes_in_background'] ?? 0),
            (float) $row['p_value'],
            (float) $row['fdr'],
            array_values(array_map('strval', $row['preferredNames'] ?? $row['inputGenes'] ?? [])),
        );
    }

    /**
     * The term as an identifier of a scheme Omnilab knows: a GO term
     * (GO:0005344), an InterPro or Pfam entry, an HPO term, a GWAS trait
     * (EFO:0005845), a Reactome pathway (STRING writes HSA-9927020,
     * Reactome R-HSA-9927020); null for the others (KEGG's hsa05143, a
     * PubMed id, a COMPARTMENTS, TISSUES or DISEASES term...).
     */
    public function identifier(): ?Identifier
    {
        return match ($this->category) {
            'Process', 'Function', 'Component' => Identifier::parse($this->term),
            'InterPro', 'Pfam', 'HPO', 'GWAS' => Identifier::parse($this->term),
            'RCTM' => Identifier::parse('R-'.$this->term),
            default => null,
        };
    }
}
