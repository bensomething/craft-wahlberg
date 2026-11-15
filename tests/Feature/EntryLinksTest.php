<?php

declare(strict_types=1);

namespace bensomething\wahlberg\tests\Feature;

use bensomething\wahlberg\tests\TestCase;
use CraftCms\Cms\Database\Factories\EntryFactory;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/** The `@` menu's entry search. */
final class EntryLinksTest extends TestCase
{
    public function test_it_finds_entries_by_title_and_links_them_by_reference(): void
    {
        $match = EntryFactory::new()->title('Getting [started]')->create();
        EntryFactory::new()->title('Something else')->create();

        $siteId = Sites::getPrimarySite()->id;

        $response = $this->actingAs($this->admin())
            ->getJson(route('wahlberg.entries', ['site' => $siteId, 'query' => '@start']))
            ->assertOk();

        $this->assertSame([[
            'label' => 'Getting [started]',
            'value' => sprintf('[Getting \[started\]]({entry:%d@%d:url})', $match->id, $siteId),
            'keywords' => $response->json('0.keywords'),
            'data' => ['hint' => $response->json('0.data.hint')],
        ]], $response->json());
    }

    public function test_recently_edited_entries_come_first(): void
    {
        $old = EntryFactory::new()->title('Old')->create();
        $edited = EntryFactory::new()->title('Edited')->create();
        $new = EntryFactory::new()->title('New')->create();

        // Post dates say New, Edited, Old. Edits say otherwise
        DB::table('entries')->where('id', $old->id)->update(['postDate' => now()->subYears(3)]);
        DB::table('entries')->where('id', $edited->id)->update(['postDate' => now()->subYears(2)]);
        DB::table('entries')->where('id', $new->id)->update(['postDate' => now()->subYear()]);
        DB::table('elements')->where('id', $edited->id)->update(['dateUpdated' => now()]);
        DB::table('elements')->where('id', $new->id)->update(['dateUpdated' => now()->subMonth()]);
        DB::table('elements')->where('id', $old->id)->update(['dateUpdated' => now()->subMonths(2)]);

        $this->actingAs($this->admin())
            ->getJson(route('wahlberg.entries', ['query' => '@']))
            ->assertOk()
            ->assertJsonPath('*.label', ['Edited', 'New', 'Old']);
    }

    public function test_entries_without_a_url_are_left_out(): void
    {
        EntryFactory::new()->title('Linkable')->create();
        $unlinkable = EntryFactory::new()->title('No URL')->create();
        DB::table('elements_sites')->where('elementId', $unlinkable->id)->update(['uri' => null]);

        $this->actingAs($this->admin())
            ->getJson(route('wahlberg.entries', ['query' => '@']))
            ->assertOk()
            ->assertJsonPath('*.label', ['Linkable']);
    }

    public function test_an_empty_query_lists_entries_up_to_the_limit(): void
    {
        EntryFactory::new()->count(3)->create();

        $this->actingAs($this->admin())
            ->getJson(route('wahlberg.entries', ['query' => '@', 'limit' => 2]))
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->get(route('wahlberg.entries', ['query' => '@']))->assertRedirect();
    }

    /**
     * The one Craft's install makes, as the auth model a real request carries rather
     * than the user element. Solo allows no more users, so the factory can't add one.
     */
    private function admin(): Authenticatable
    {
        $user = User::find()->admin()->status(null)->one();
        $this->assertInstanceOf(User::class, $user);

        $model = auth()->getProvider()->retrieveById($user->id);
        $this->assertInstanceOf(Authenticatable::class, $model);

        return $model;
    }
}
