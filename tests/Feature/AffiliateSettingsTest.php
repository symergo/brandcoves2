<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Filament\Pages\AffiliateSettings;
use App\Models\ConnectorSetting;
use App\Models\User;
use App\Services\Settings\AffiliateSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * bol's partner ids and credentials and the Amazon tags, editable in the admin.
 *
 * The values in Coolify stay the defaults. What this pins: a value saved here
 * reaches the links that earn on it, emptying a field goes back to Coolify, a
 * secret never leaves the server, and a changed bol credential takes effect at
 * once rather than when a cached login happens to expire.
 */
class AffiliateSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['email' => 'affiliate@example.test', 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function store(): AffiliateSettingsStore
    {
        $store = app(AffiliateSettingsStore::class);
        $store->flush();

        return $store;
    }

    #[Test]
    public function a_partner_id_saved_here_is_the_one_the_bol_link_earns_on(): void
    {
        config([
            'giftcoves.connectors.bol.partner_site_id.BE' => '25421',
            'giftcoves.connectors.bol.partner_site_id.NL' => '1005548',
        ]);

        $this->store()->put(['bol_partner_site_id_be' => '999001']);
        $this->store()->apply();

        // Both Belgian markets follow the country, and the Dutch one is untouched.
        $this->assertSame('999001', Market::BeNl->bolPartnerSiteId());
        $this->assertSame('999001', Market::BeFr->bolPartnerSiteId());
        $this->assertSame('1005548', Market::NlNl->bolPartnerSiteId());
    }

    #[Test]
    public function the_belgian_amazon_tag_serves_both_belgian_markets(): void
    {
        config(['giftcoves.amazon_search.markets.nl-nl.tag' => 'giftcoves-21']);

        $this->store()->put(['amazon_tag_be' => 'newtag05-21']);
        $this->store()->apply();

        // One storefront, one tag: it cannot be set for one of them alone.
        $this->assertSame('newtag05-21', config('giftcoves.amazon_search.markets.be-nl.tag'));
        $this->assertSame('newtag05-21', config('giftcoves.amazon_search.markets.be-fr.tag'));
        $this->assertSame('giftcoves-21', config('giftcoves.amazon_search.markets.nl-nl.tag'));
    }

    #[Test]
    public function emptying_a_field_goes_back_to_the_value_in_coolify(): void
    {
        // The undo for a mistake typed here.
        $this->store()->put(['amazon_tag_nl' => 'mistake-21']);
        $this->assertTrue($this->store()->isOverridden('amazon_tag_nl'));

        $this->assertSame(['amazon_tag_nl'], $this->store()->put(['amazon_tag_nl' => '']));

        $this->assertFalse($this->store()->isOverridden('amazon_tag_nl'));
        $this->assertSame(0, ConnectorSetting::query()->where('key', 'amazon_tag_nl')->count());
    }

    #[Test]
    public function only_allowlisted_keys_reach_the_config(): void
    {
        // A row in this table must not be able to overwrite any config value.
        ConnectorSetting::create([
            'source' => AffiliateSettingsStore::SOURCE,
            'key' => 'app.key',
            'encrypted_value' => 'stolen',
        ]);

        $before = config('app.key');
        $this->store()->apply();

        $this->assertSame($before, config('app.key'));
    }

    #[Test]
    public function the_page_never_renders_a_stored_secret(): void
    {
        $this->store()->put(['bol_client_secret' => 'bol-secret-value-9876']);
        $this->store()->apply();

        $response = $this->actingAs($this->admin())->get('/admin/affiliate-settings')->assertOk();

        // Not in the HTML, not in a value attribute, not in the Livewire payload.
        $response->assertDontSee('bol-secret-value-9876', escape: false);
        // The fingerprint answers "is the right one in there".
        $response->assertSee('9876', escape: false);
    }

    #[Test]
    public function the_page_shows_the_ids_in_full(): void
    {
        // Not secrets, and a wrong one fails silently: reading it back is the point.
        config(['giftcoves.connectors.bol.partner_site_id.NL' => '1005548']);

        $this->actingAs($this->admin())->get('/admin/affiliate-settings')
            ->assertOk()
            ->assertSee('1005548');
    }

    #[Test]
    public function saving_without_touching_a_secret_keeps_it(): void
    {
        /*
         * The secret field is empty on every load, so a save that only changes
         * an id submits an empty secret. Reading that as "clear it" would stop
         * bol answering the first time anybody corrected a partner id.
         */
        $this->store()->put(['bol_client_secret' => 'bol-original']);

        Livewire::actingAs($this->admin())
            ->test(AffiliateSettings::class)
            ->set('data.bol_partner_site_id_nl', '123456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('bol-original', $this->store()->override('bol_client_secret'));
        $this->assertSame('123456', $this->store()->override('bol_partner_site_id_nl'));
    }

    #[Test]
    public function a_new_bol_secret_takes_effect_at_once(): void
    {
        Cache::put('bc:bol:token', 'token-for-the-old-account', 240);
        Cache::forget('illuminate:queue:restart');

        Livewire::actingAs($this->admin())
            ->test(AffiliateSettings::class)
            ->set('data.bol_client_secret', 'bol-replacement')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('bol-replacement', $this->store()->override('bol_client_secret'));
        // The cached login belonged to the old credentials.
        $this->assertFalse(Cache::has('bc:bol:token'));
        // And the workers, which boot once, are told to restart.
        $this->assertTrue(Cache::has('illuminate:queue:restart'));
    }

    #[Test]
    public function a_stored_secret_can_be_handed_back_to_coolify(): void
    {
        $this->store()->put(['bol_client_id' => 'bol-client-here']);

        Livewire::actingAs($this->admin())
            ->test(AffiliateSettings::class)
            ->set('data.bol_client_id_reset', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->store()->isOverridden('bol_client_id'));
    }

    #[Test]
    public function an_untouched_save_restarts_nothing(): void
    {
        Cache::put('bc:bol:token', 'still-good', 240);
        Cache::forget('illuminate:queue:restart');

        Livewire::actingAs($this->admin())
            ->test(AffiliateSettings::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, ConnectorSetting::query()->where('source', AffiliateSettingsStore::SOURCE)->count());
        $this->assertSame('still-good', Cache::get('bc:bol:token'));
        $this->assertFalse(Cache::has('illuminate:queue:restart'));
    }

    #[Test]
    public function a_malformed_id_is_refused_before_it_reaches_a_link(): void
    {
        Livewire::actingAs($this->admin())
            ->test(AffiliateSettings::class)
            ->set('data.bol_partner_site_id_be', 'abc')
            ->set('data.amazon_tag_nl', 'has spaces')
            ->call('save')
            ->assertHasErrors(['data.bol_partner_site_id_be', 'data.amazon_tag_nl']);

        $this->assertSame(0, ConnectorSetting::query()->where('source', AffiliateSettingsStore::SOURCE)->count());
    }

    #[Test]
    public function secrets_are_encrypted_at_rest(): void
    {
        $this->store()->put(['bol_client_secret' => 'bol-plaintext-check']);

        $raw = DB::table('connector_settings')
            ->where('source', AffiliateSettingsStore::SOURCE)
            ->where('key', 'bol_client_secret')
            ->value('encrypted_value');

        $this->assertStringNotContainsString('bol-plaintext-check', (string) $raw);
    }
}
