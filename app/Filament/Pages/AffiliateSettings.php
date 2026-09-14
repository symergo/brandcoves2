<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Connectors\Bol\BolConnector;
use App\Services\Settings\AffiliateSettingsStore;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * bol's partner ids and API credentials, and the Amazon Associates tags.
 *
 * ## Why this is a screen
 *
 * All six were environment variables in Coolify, so changing a partner id or
 * rotating bol's secret was a redeploy, done by whoever had Coolify open. They
 * stay there as the defaults; this screen overrides a value only when one is
 * typed, and says per field which one is in effect. See AffiliateSettingsStore.
 *
 * ## Two kinds of field
 *
 * **The ids are shown in full.** They are not secrets — they appear in every
 * outbound link — and a wrong one fails silently, so being able to read it back
 * is the point. An empty field means "use Coolify's value", which is also how a
 * mistake is undone.
 *
 * **The credentials are never shown.** Always empty on load, and an empty
 * submit means "keep it", as on the AI settings page, so a save that only
 * changes an id cannot blank the secret. A separate switch takes a stored
 * credential away again, since emptying the field cannot mean both things.
 *
 * ## A change takes effect at once
 *
 * Saving clears bol's cached login when its credentials changed, and tells the
 * queue workers to restart after their current job, since a worker boots once
 * and would otherwise keep the old values. The page itself reloads, so what it
 * shows is what the next request will use.
 */
class AffiliateSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Shop and affiliate keys';

    protected static ?string $title = 'Shop and affiliate settings';

    protected string $view = 'filament.pages.affiliate-settings';

    /** Shown in full, and emptied to fall back to Coolify. */
    private const IDS = ['bol_partner_site_id_be', 'bol_partner_site_id_nl', 'amazon_tag_nl', 'amazon_tag_be'];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $store = app(AffiliateSettingsStore::class);

        $fill = [];

        foreach (self::IDS as $key) {
            // The override only. When Coolify's value stands the field is
            // empty and shows that value as its placeholder.
            $fill[$key] = $store->override($key);
        }

        foreach (AffiliateSettingsStore::SECRETS as $key) {
            // Deliberately absent. See the class docblock.
            $fill[$key] = null;
            $fill[$key.'_reset'] = false;
        }

        $this->form->fill($fill);
    }

    public function form(Schema $schema): Schema
    {
        $store = app(AffiliateSettingsStore::class);

        return $schema
            ->components([
                Section::make('bol')
                    ->description('The partner site ids earn the commission on every bol link. The client id and secret let the site ask bol for products and prices.')
                    ->schema([
                        $this->idField($store, 'bol_partner_site_id_be', 'Partner site id, Belgium')
                            ->regex('/^\d{1,12}$/')
                            ->validationMessages(['regex' => 'A bol partner site id is digits only.']),

                        $this->idField($store, 'bol_partner_site_id_nl', 'Partner site id, Netherlands')
                            ->regex('/^\d{1,12}$/')
                            ->validationMessages(['regex' => 'A bol partner site id is digits only.']),

                        $this->secretField($store, 'bol_client_id', 'API client id'),
                        $this->secretField($store, 'bol_client_secret', 'API client secret'),

                        $this->resetField($store, 'bol_client_id', 'Use the client id in Coolify again'),
                        $this->resetField($store, 'bol_client_secret', 'Use the client secret in Coolify again'),
                    ])
                    ->columns(2),

                Section::make('Amazon')
                    ->description('The Associates tag goes into the "search on Amazon" link, one per storefront. Amazon issues a tag per marketplace, so a tag from one storefront tracks nothing on another.')
                    ->schema([
                        $this->idField($store, 'amazon_tag_nl', 'Associates tag, amazon.nl')
                            ->regex('/^[A-Za-z0-9][A-Za-z0-9-]{1,62}$/')
                            ->validationMessages(['regex' => 'An Associates tag is letters, digits and hyphens, like giftcoves-21.']),

                        $this->idField($store, 'amazon_tag_be', 'Associates tag, amazon.com.be')
                            ->regex('/^[A-Za-z0-9][A-Za-z0-9-]{1,62}$/')
                            ->validationMessages(['regex' => 'An Associates tag is letters, digits and hyphens, like giftcoves-21.'])
                            ->helperText(fn () => $this->idHelp($store, 'amazon_tag_be').' Serves both Belgian markets: Amazon runs them on one storefront.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    private function idField(AffiliateSettingsStore $store, string $key, string $label): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->maxLength(64)
            ->placeholder(fn () => $store->current($key) ?? 'Not set')
            ->helperText(fn () => $this->idHelp($store, $key));
    }

    private function idHelp(AffiliateSettingsStore $store, string $key): string
    {
        if ($store->isOverridden($key)) {
            return 'Set here. Empty the field to use the value in Coolify again.';
        }

        return $store->current($key) === null
            ? 'Not set anywhere, so these links earn nothing.'
            : 'From Coolify. Type a value to override it here.';
    }

    private function secretField(AffiliateSettingsStore $store, string $key, string $label): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->password()
            ->revealable(false)
            ->autocomplete('new-password')
            ->maxLength(255)
            ->placeholder('Leave empty to keep the current value')
            // Never populated from storage, so the value cannot leave the
            // server in a response.
            ->dehydrated(fn (?string $state) => filled($state))
            ->helperText(function () use ($store, $key): string {
                $fingerprint = $store->fingerprint($key);

                if ($fingerprint === null) {
                    return 'Not set. bol cannot be asked for anything without it.';
                }

                return 'Currently '.$fingerprint.($store->isOverridden($key) ? ', set here.' : ', from Coolify.');
            });
    }

    private function resetField(AffiliateSettingsStore $store, string $key, string $label): Toggle
    {
        return Toggle::make($key.'_reset')
            ->label($label)
            // Only when there is something here to take away.
            ->visible(fn () => $store->isOverridden($key));
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $store = app(AffiliateSettingsStore::class);

        $values = [];

        foreach (self::IDS as $key) {
            // Empty means "use Coolify's value", which the store does by
            // deleting the row.
            $values[$key] = $state[$key] ?? null;
        }

        foreach (AffiliateSettingsStore::SECRETS as $key) {
            if (filled($state[$key] ?? null)) {
                $values[$key] = (string) $state[$key];
            } elseif ((bool) ($state[$key.'_reset'] ?? false)) {
                $values[$key] = null;
            }
            // Otherwise untouched: an empty secret field means keep it.
        }

        $changed = $store->put($values);

        if (array_intersect($changed, AffiliateSettingsStore::BOL_CREDENTIALS) !== []) {
            // The cached login belongs to the old credentials. Without this a
            // wrong new secret only shows itself when that token expires.
            BolConnector::forgetAccessToken();
        }

        if ($changed !== []) {
            // Workers boot once. This asks each to finish its current job and
            // restart, so bol calls from the queue use what was just saved.
            Artisan::call('queue:restart');
        }

        Notification::make()
            ->title($changed === [] ? 'Nothing changed' : 'Saved')
            ->body($changed === []
                ? 'Every value is as it was.'
                : 'In effect from the next request. The queue workers restart after their current job.')
            ->success()
            ->send();

        // A fresh request, so the fields show what the next request will use:
        // this one booted before the save, and a value that fell back to Coolify
        // is not in its config any more.
        $this->redirect(static::getUrl());
    }
}
