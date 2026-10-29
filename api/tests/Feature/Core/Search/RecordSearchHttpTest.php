<?php

namespace Tests\Feature\Core\Search;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\Search\SearchServiceProvider;
use App\Core\Search\SearchTerm;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LAY-01, TEN-01, RBAC-04: the record search endpoint's contract: signed-in
 * only, a 2 to 100 character query, escaped patterns, case and accent
 * insensitive matching, at most 5 results per type, and a rate limit.
 */
class RecordSearchHttpTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/search?q=acme')->assertUnauthorized();
    }

    public function test_a_query_must_be_two_to_one_hundred_characters(): void
    {
        $headers = $this->headersFor();

        $this->getJson('/api/v1/search?q=a', $headers)->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/search?q=%20a%20', $headers)->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/search', $headers)->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/search?q='.str_repeat('a', 101), $headers)->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/search?q=ab&limit=6', $headers)->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->getJson('/api/v1/search?q=ab&limit=0', $headers)->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->getJson('/api/v1/search?q=ab', $headers)->assertOk()->assertJsonPath('meta.partial', false);
        $this->getJson('/api/v1/search?q='.str_repeat('a', 100), $headers)->assertOk();
    }

    public function test_it_is_rate_limited_per_user(): void
    {
        $headers = $this->headersFor();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/search?q=ab', $headers)->assertOk();
        }

        $this->getJson('/api/v1/search?q=ab', $headers)->assertStatus(429);
        $this->assertSame('record-search', SearchServiceProvider::LIMITER);
    }

    public function test_matching_ignores_case_and_accents_and_escapes_patterns(): void
    {
        $this->inTenant(function () {
            Party::create(['kind' => 'person', 'name' => 'Éloïse Müller-Ndungu', 'roles' => ['customer']]);
            Party::create(['kind' => 'person', 'name' => '100% Cotton Ltd', 'roles' => ['supplier']]);
            Party::create(['kind' => 'person', 'name' => 'Plain Name', 'roles' => ['customer']]);
        });
        $headers = $this->headersFor();
        $names = fn (string $q, string $type = 'customer') => collect($this->getJson('/api/v1/search?'.http_build_query(['q' => $q]), $headers)->assertOk()->json('data'))
            ->firstWhere('type', $type)['results'] ?? [];

        foreach (['eloise muller', 'ELOISE', 'éloïse müller-ndungu', 'Eloise  Muller', 'ndungu'] as $query) {
            $this->assertSame(['Éloïse Müller-Ndungu'], array_column($names($query), 'title'), "[{$query}] should find the accented name");
        }

        // Accents typed in the query match plain letters stored.
        $this->assertSame(['Plain Name'], array_column($names('plàin'), 'title'));

        // LIKE and regex wildcards are literal: they match nothing but themselves.
        $this->assertSame([], $names('%%'));
        $this->assertSame([], $names('__'));
        $this->assertSame([], $names('.*'));
        $this->assertSame([], $names('(('));
        $this->assertSame(['100% Cotton Ltd'], array_column($names('100%', 'supplier'), 'title'));
    }

    public function test_results_are_grouped_by_type_with_at_most_five_each(): void
    {
        $this->inTenant(function () {
            foreach (range(1, 8) as $n) {
                Party::create(['kind' => 'person', 'name' => "Wanjiru {$n}", 'roles' => ['customer']]);
            }

            $each = Uom::query()->where('code', 'EA')->first() ?? Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            foreach (range(1, 7) as $n) {
                Item::create(['code' => "WAN-{$n}", 'name' => "Wanjiru item {$n}", 'type' => 'stock', 'base_uom_id' => $each->id]);
            }
        });

        $data = $this->getJson('/api/v1/search?q=wanjiru', $this->headersFor())->assertOk()->json('data');

        $this->assertSame(['customer', 'item'], array_column($data, 'type'));
        $this->assertCount(5, $data[0]['results']);
        $this->assertCount(5, $data[1]['results']);

        $limited = $this->getJson('/api/v1/search?q=wanjiru&limit=2', $this->headersFor())->assertOk()->json('data');
        $this->assertCount(2, $limited[0]['results']);
    }

    public function test_the_term_builds_escaped_patterns(): void
    {
        $term = new SearchTerm('  50%_off\\  now ');

        $this->assertSame('50%_off\\ now', $term->text);
        $this->assertSame('%50\\%\\_off\\\\ now%', $term->contains());
        $this->assertSame('50\\%\\_off\\\\ now%', $term->prefix());
        $this->assertSame('[eèéêëēĕėęěEÈÉÊËĒĔĖĘĚ]\\.t', (new SearchTerm('é.t'))->regex());
        $this->assertSame('0712345678', (new SearchTerm('0712 345-678'))->digits());
        $this->assertTrue((new SearchTerm('+254 712 345 678'))->looksLikePhone());
        $this->assertFalse((new SearchTerm('Room 12'))->looksLikePhone());
        $this->assertTrue((new SearchTerm('a@b.co'))->looksLikeEmail());
    }
}
