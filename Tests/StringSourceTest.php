<?php

namespace Omnilab\StringDb\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\StringDb\Enrichment;
use Omnilab\StringDb\StringSource;
use Omnilab\StringDb\StringSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from string-db.org/api on 2026-10-15 (STRING 12.5):
 * the mapping of hemoglobin beta by its UniProt accession and by its
 * STRING id, of TP53 by its name, of a text nothing maps; the ten best
 * partners of HBB and of TP53 at medium confidence (400); the network
 * among HBB, HBA1 and HBA2; the enrichment of six globin genes.
 */
final class StringSourceTest extends TestCase
{
    /** @var list<string> the methods asked, with their queries */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): StringSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertStringStartsWith('/api/json/', $path);
            self::assertSame('omnilab', $query['caller_identity'] ?? null, 'the caller\'s identity, as STRING asks');
            unset($query['caller_identity']);
            $call = substr($path, \strlen('/api/json/')).'?'.urldecode(http_build_query($query));
            $this->calls[] = $call;
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                str_starts_with($call, 'get_string_ids?') => 'ids-'.(\in_array($query['identifiers'], ['P68871', '9606.ENSP00000494175', 'TP53'], true) ? $query['identifiers'] : 'notfound').'.json',
                str_starts_with($call, 'interaction_partners?') => 'partners-'.$query['identifiers'].'.json',
                str_starts_with($call, 'network?') => 'network-hbb.json',
                str_starts_with($call, 'enrichment?') => 'enrichment-hbb.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new StringSourceFactory($http))->create(['throttle' => 0] + $options);
        self::assertInstanceOf(StringSource::class, $source);

        return $source;
    }

    public function testHemoglobinBetaByItsAccessionAndItsPartners(): void
    {
        $protein = $this->source()->entity(Identifier::of(Scheme::UNIPROT, 'P68871'));

        self::assertSame('string:9606.ENSP00000494175', $protein->id->key());
        self::assertSame('HBB', $protein->label);
        self::assertSame(Domain::BIOLOGY, $protein->domain);
        self::assertSame('protein', $protein->kind);
        self::assertSame(['Hemoglobin subunit beta'], $protein->synonyms);
        self::assertStringStartsWith('Involved in oxygen transport from the lung', (string) $protein->description);
        self::assertSame('Homo sapiens', $protein->value('species'));
        self::assertSame('https://string-db.org/network/9606.ENSP00000494175', $protein->url);
        self::assertStringStartsWith('https://string-db.org/api/image/network?identifiers=9606.ENSP00000494175&species=9606', (string) $protein->image);
        self::assertSame(['string:9606.ENSP00000494175', 'ensembl:ENSP00000494175', 'uniprot:P68871'], $protein->identifiers()->keys(), 'its Ensembl protein, and the accession it was asked by');
        self::assertSame(['ncbi.taxon:9606'], $protein->related('organism')->keys());
        self::assertCount(10, $protein->related('interactor'));
        self::assertSame('string:9606.ENSP00000251595', (string) $protein->related('interactor')->all()[0], 'HBA2 first');
        self::assertSame('Interaction with HBA2', $protein->property('interaction')->label);
        self::assertSame(0.999, $protein->value('interaction'));
        self::assertSame(['HBA2', 'HBZ', 'HBD', 'HBA1', 'HBE1', 'HP', 'AHSP', 'HBG1', 'HBG2', 'HBM'], array_map(static fn ($p) => substr($p->label, \strlen('Interaction with ')), $protein->properties('interaction')));
        self::assertSame('string', $protein->property('interaction')->source);
        self::assertSame(['get_string_ids?identifiers=P68871&species=9606&limit=1', 'interaction_partners?identifiers=9606.ENSP00000494175&species=9606&limit=10&required_score=400'], $this->calls);
    }

    public function testByItsStringIdItsPageOrAName(): void
    {
        self::assertSame('HBB', $this->source()->entity('9606.ENSP00000494175')?->label);
        self::assertSame('HBB', $this->source()->entity('https://string-db.org/network/9606.ENSP00000494175')?->label);
        self::assertSame(['string:9606.ENSP00000494175', 'ensembl:ENSP00000494175'], $this->source()->entity('string:9606.ENSP00000494175')?->identifiers()->keys(), 'no UniProt accession when none was asked');

        $tp53 = $this->source()->entity('TP53');
        self::assertSame('string:9606.ENSP00000269305', (string) $tp53?->id);
        self::assertSame('Cellular tumor antigen p53', $tp53->value('protein_name'));
        self::assertStringEndsWith('…', (string) $tp53->description, 'STRING cuts a long annotation');
        self::assertSame('SIRT1', substr($tp53->property('interaction')->label, \strlen('Interaction with ')));
    }

    public function testWithoutPartnersOneCall(): void
    {
        $protein = $this->source(null, ['partners' => 0])->entity('9606.ENSP00000494175');

        self::assertCount(0, $protein->related('interactor'));
        self::assertNull($protein->property('interaction'));
        self::assertCount(1, $this->calls);
    }

    public function testNothingMappedIsNull(): void
    {
        self::assertNull($this->source()->entity('zzqqxx'));
        self::assertCount(1, $this->calls, 'no partners asked');
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach (['CHEBI:15422', Identifier::of(Scheme::NCBI_GENE, '3043'), 'Q60235', 'R-HSA-70171', ''] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s should be refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testPartnersWithTheirEvidence(): void
    {
        $partners = $this->source()->partners('9606.ENSP00000494175', 10);

        self::assertCount(10, $partners);
        self::assertSame('HBB', $partners[0]->nameA);
        self::assertSame('HBA2', $partners[0]->nameB);
        self::assertSame(0.999, $partners[0]->score);
        self::assertSame(['experiments' => 0.999, 'coexpression' => 0.978, 'databases' => 0.9, 'textmining' => 0.553, 'cooccurrence' => 0.064], $partners[0]->evidence());
        self::assertSame(['interaction_partners?identifiers=9606.ENSP00000494175&species=9606&limit=10&required_score=400'], $this->calls, 'a STRING id needs no mapping');
    }

    public function testTheNetworkAmongProteins(): void
    {
        $network = $this->source()->network(['HBB', 'HBA1', 'HBA2']);

        self::assertCount(3, $network);
        self::assertSame(['HBA2-HBA1', 'HBA2-HBB', 'HBA1-HBB'], array_map(static fn ($i) => $i->nameA.'-'.$i->nameB, $network));
        self::assertSame("network?identifiers=HBB\rHBA1\rHBA2&species=9606&required_score=400", $this->calls[0]);
    }

    public function testTheEnrichmentOfAGeneSet(): void
    {
        $terms = $this->source()->enrichment(['HBB', 'HBA1', 'HBA2', 'HBD', 'HBZ', 'ALAS2']);

        self::assertCount(185, $terms);
        $byCategory = [];
        foreach ($terms as $term) {
            $byCategory[$term->category] ??= $term;
        }
        self::assertSame('Erythrocyte development', $byCategory['Process']->description);
        self::assertSame('go:0048821', (string) $byCategory['Process']->identifier());
        self::assertSame(5, $byCategory['Process']->count);
        self::assertSame(6.3e-13, $byCategory['Process']->pValue);
        self::assertSame(7.76e-09, $byCategory['Process']->fdr);
        self::assertSame('pfam:PF00042', (string) $byCategory['Pfam']->identifier());
        self::assertSame('interpro:IPR000971', (string) $byCategory['InterPro']->identifier());
        self::assertSame('reactome:R-HSA-9927020', (string) $byCategory['RCTM']->identifier());
        self::assertSame('hp:0004840', (string) $byCategory['HPO']->identifier());
        self::assertSame('efo:0005845', (string) $byCategory['GWAS']->identifier());
        self::assertNull($byCategory['KEGG']->identifier());
        self::assertInstanceOf(Enrichment::class, $byCategory['DISEASES']);
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('{"Error":"species not found"}', ['http_code' => 400]))->entity('TP53');
            self::fail('a 400');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('TP53');
    }

    public function testTheFactory(): void
    {
        $factory = new StringSourceFactory(new MockHttpClient());

        self::assertSame('string', $factory->getName());
        self::assertSame([Capability::ENTITY, Capability::CROSS_REFERENCES], $factory->create()->capabilities());
        $this->expectException(NotSupportedException::class);
        $factory->create()->search(new Query('hemoglobin'));
    }
}
