<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take "we are a comparison site" out of the copy bank.
 *
 * Brandcoves is a product and brand discovery service that links to the shops
 * selling what it shows. The about and terms pages say so now; the FAQ answer on
 * every search and brand page still said the opposite, in four languages.
 *
 * The language files are the shipped source of that copy, but they are not what
 * renders. `bc:seed-copy` imported them into `copy_templates` once and then
 * deliberately never touches a slot again, so an editor's work survives a
 * re-run. That is the right rule and it means a corrected language file reaches
 * exactly nobody. Hence a data migration.
 *
 * **A phrase swap, not a row replacement.** Only the sentence that misdescribes
 * the service changes; anything an editor wrote around it stays exactly as they
 * left it. Overwriting whole bodies would silently discard their work, which is
 * the thing `bc:seed-copy` refuses to do.
 *
 * Idempotent: after the first run nothing matches.
 */
return new class extends Migration
{
    /**
     * Old phrase => new phrase. Substrings, deliberately short, so an edited row
     * keeps its edits.
     *
     * @var array<string, string>
     */
    private const PHRASES = [
        'We are a comparison site, not a retailer:' => 'We are a discovery site, not a retailer:',
        'We compare rather than sell:' => 'We list rather than sell:',
        'Wij zijn een vergelijkingssite, geen winkel:' => 'Wij zijn een ontdekkingssite, geen winkel:',
        'Wij vergelijken en verkopen niet:' => 'Wij tonen en verkopen niet:',
        'Nous sommes un comparateur, pas un marchand :' => 'Nous sommes un site de découverte, pas un marchand :',
        'Nous comparons, nous ne vendons pas :' => 'Nous présentons, nous ne vendons pas :',
        'Somos un comparador, no una tienda:' => 'Somos un sitio de descubrimiento, no una tienda:',
        'Comparamos, no vendemos:' => 'Mostramos, no vendemos:',
    ];

    public function up(): void
    {
        $this->swap(self::PHRASES);
    }

    public function down(): void
    {
        $this->swap(array_flip(self::PHRASES));
    }

    /** @param array<string, string> $phrases */
    private function swap(array $phrases): void
    {
        foreach ($phrases as $from => $to) {
            DB::table('copy_templates')
                ->where('body', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $from).'%')
                ->update(['body' => DB::raw('replace(body, '.$this->quote($from).', '.$this->quote($to).')')]);
        }
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
