<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds visual-identity fields to fantasy_teams for the new mascot-driven UI:
 *   - mascot:        e.g. "Mustangs" — animal/figure name (last word of the team name)
 *   - display_name:  e.g. "Mariental Mustangs" — themed name shown on cards
 *   - icon_slug:     e.g. "horse-head" — references /team-icons/{slug}.svg in the FE
 *   - accent_color:  e.g. "orange" — drives multiplier text colour + selection glow
 *
 * Backfill is dynamic: we infer the mascot from the team's existing name
 * (assumes alliterative "{Place} {Mascot}" pattern) and map it to an icon
 * and accent through a small dictionary. Names that don't fit the pattern,
 * or use a mascot we don't recognise, end up with NULL columns — the FE
 * falls back to a generic "Stars" badge in that case.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('fantasy_teams', function (Blueprint $table) {
            $table->string('mascot', 64)->nullable()->after('name');
            $table->string('display_name', 128)->nullable()->after('mascot');
            $table->string('icon_slug', 64)->nullable()->after('display_name');
            $table->string('accent_color', 32)->nullable()->after('icon_slug');
        });

        $teams = DB::table('fantasy_teams')->select('id', 'name')->get();
        foreach ($teams as $team) {
            $info = $this->inferFromName($team->name);
            if (!$info) continue;

            DB::table('fantasy_teams')->where('id', $team->id)->update([
                'mascot' => $info['mascot'],
                'display_name' => $team->name, // existing names are already themed
                'icon_slug' => $info['icon_slug'],
                'accent_color' => $info['accent_color'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('fantasy_teams', function (Blueprint $table) {
            $table->dropColumn(['mascot', 'display_name', 'icon_slug', 'accent_color']);
        });
    }

    /**
     * Pull the mascot keyword (last word) from a team name and look up its
     * icon/accent. Returns null if the mascot isn't in the dictionary so the
     * row stays unbackfilled rather than getting wrong data.
     */
    private function inferFromName(string $name): ?array
    {
        $parts = preg_split('/\s+/', trim($name));
        if (!$parts || count($parts) < 2) return null;
        $mascot = end($parts);

        $info = $this->mascotMap()[$mascot] ?? null;
        if (!$info) return null;

        return ['mascot' => $mascot] + $info;
    }

    /**
     * mascot keyword -> [icon_slug, accent_color]. Keys are the bare last
     * word as it appears in the team name (case-sensitive on purpose — names
     * are stored Title-Cased in the DB).
     *
     * Many slugs reuse the same SVG (eagle.svg covers all raptor variants,
     * panther.svg covers all big cats, etc). New SVGs can be added in
     * client/public/team-icons/ and mapped here without a migration.
     */
    private function mascotMap(): array
    {
        return [
            // Big cats — all use panther.svg
            'Panthers'    => ['icon_slug' => 'panther',    'accent_color' => 'violet'],
            'Leopards'    => ['icon_slug' => 'panther',    'accent_color' => 'violet'],
            'Cheetahs'    => ['icon_slug' => 'panther',    'accent_color' => 'amber'],
            'Pumas'       => ['icon_slug' => 'panther',    'accent_color' => 'violet'],
            'Ocelots'     => ['icon_slug' => 'panther',    'accent_color' => 'violet'],
            'Tigers'      => ['icon_slug' => 'tiger',      'accent_color' => 'amber'],
            'Lions'       => ['icon_slug' => 'lion',       'accent_color' => 'gold'],

            // Birds — all use eagle.svg
            'Eagles'      => ['icon_slug' => 'eagle',      'accent_color' => 'gold'],
            'Hawks'       => ['icon_slug' => 'eagle',      'accent_color' => 'amber'],
            'Ospreys'     => ['icon_slug' => 'eagle',      'accent_color' => 'rose'],
            'Raptors'     => ['icon_slug' => 'eagle',      'accent_color' => 'rose'],
            'Griffins'    => ['icon_slug' => 'eagle',      'accent_color' => 'gold'],
            'Herons'      => ['icon_slug' => 'eagle',      'accent_color' => 'sky'],
            'Kites'       => ['icon_slug' => 'eagle',      'accent_color' => 'cyan'],
            'Kestrels'    => ['icon_slug' => 'eagle',      'accent_color' => 'rose'],
            'Kingfishers' => ['icon_slug' => 'eagle',      'accent_color' => 'cyan'],
            'Ravens'      => ['icon_slug' => 'eagle',      'accent_color' => 'violet'],
            'Owls'        => ['icon_slug' => 'owl',        'accent_color' => 'indigo'],
            'Ostriches'   => ['icon_slug' => 'ostrich',    'accent_color' => 'amber'],

            // Horses
            'Mustangs'    => ['icon_slug' => 'horse-head', 'accent_color' => 'orange'],
            'Stallions'   => ['icon_slug' => 'horse-head', 'accent_color' => 'orange'],
            'Unicorns'    => ['icon_slug' => 'horse-head', 'accent_color' => 'fuchsia'],

            // Bovines
            'Bulls'       => ['icon_slug' => 'bull',       'accent_color' => 'orange'],
            'Oxen'        => ['icon_slug' => 'buffalo',    'accent_color' => 'orange'],
            'Buffaloes'   => ['icon_slug' => 'buffalo',    'accent_color' => 'orange'],
            'Buffalos'    => ['icon_slug' => 'buffalo',    'accent_color' => 'orange'],

            // Antelope family
            'Antelopes'   => ['icon_slug' => 'antelope',   'accent_color' => 'amber'],
            'Gazelles'    => ['icon_slug' => 'antelope',   'accent_color' => 'amber'],
            'Kudus'       => ['icon_slug' => 'kudu',       'accent_color' => 'amber'],
            'Oryx'        => ['icon_slug' => 'oryx',       'accent_color' => 'amber'],

            // Aquatic
            'Sharks'      => ['icon_slug' => 'shark',      'accent_color' => 'sky'],
            'Whales'      => ['icon_slug' => 'whale',      'accent_color' => 'cyan'],
            'Orcas'       => ['icon_slug' => 'whale',      'accent_color' => 'sky'],
            'Dolphins'    => ['icon_slug' => 'whale',      'accent_color' => 'sky'],
            'Lobsters'    => ['icon_slug' => 'shark',      'accent_color' => 'rose'],

            // Reptiles
            'Cobras'      => ['icon_slug' => 'cobra',      'accent_color' => 'emerald'],
            'Mambas'      => ['icon_slug' => 'cobra',      'accent_color' => 'emerald'],
            'Crocs'       => ['icon_slug' => 'crocodile',  'accent_color' => 'lime'],
            'Crocodiles'  => ['icon_slug' => 'crocodile',  'accent_color' => 'lime'],
            'Geckos'      => ['icon_slug' => 'gecko',      'accent_color' => 'lime'],

            // Megafauna
            'Rhinos'      => ['icon_slug' => 'rhino',      'accent_color' => 'slate'],
            'Elephants'   => ['icon_slug' => 'elephant',   'accent_color' => 'slate'],

            // Canines
            'Wolves'      => ['icon_slug' => 'wolf',       'accent_color' => 'slate'],

            // Mythical / fantasy
            'Dragons'     => ['icon_slug' => 'dragon',     'accent_color' => 'fuchsia'],

            // Warriors / heraldic — use knight.svg
            'Knights'     => ['icon_slug' => 'knight',     'accent_color' => 'gold'],
            'Warriors'    => ['icon_slug' => 'knight',     'accent_color' => 'rose'],
            'Raiders'     => ['icon_slug' => 'knight',     'accent_color' => 'rose'],
            'Avengers'    => ['icon_slug' => 'knight',     'accent_color' => 'rose'],
            'Outlaws'     => ['icon_slug' => 'knight',     'accent_color' => 'cyan'],
            'Outlanders'  => ['icon_slug' => 'knight',     'accent_color' => 'violet'],
            'Nomads'      => ['icon_slug' => 'knight',     'accent_color' => 'amber'],
            'Kings'       => ['icon_slug' => 'crown',      'accent_color' => 'gold'],
            'Giants'      => ['icon_slug' => 'knight',     'accent_color' => 'slate'],

            // Otters / smaller mammals
            'Otters'      => ['icon_slug' => 'otter',      'accent_color' => 'cyan'],

            // Celestial / abstract — re-routed off `lightning.svg` (kept in
            // /public/team-icons/ but unused by any team for now).
            'Stars'       => ['icon_slug' => 'crown',      'accent_color' => 'gold'],
            'Comets'      => ['icon_slug' => 'mountain',   'accent_color' => 'cyan'],
            'Flames'      => ['icon_slug' => 'dragon',     'accent_color' => 'orange'],
            'Arrows'      => ['icon_slug' => 'eagle',      'accent_color' => 'rose'],
            'Aces'        => ['icon_slug' => 'crown',      'accent_color' => 'gold'],
            'Oaks'        => ['icon_slug' => 'mountain',   'accent_color' => 'amber'],
            'Scorpions'   => ['icon_slug' => 'crocodile',  'accent_color' => 'rose'],
            'United'      => ['icon_slug' => 'knight',     'accent_color' => 'sky'],
        ];
    }
};
