<?php

namespace App\Console\Commands;

use App\Ingestion\Asset;
use App\Ingestion\Contracts\Acquirer;
use App\Ingestion\Contracts\Discoverer;
use App\Ingestion\Drivers\Biedronka\VisionParser;
use App\Ingestion\Offer;
use Illuminate\Console\Command;

/**
 * Scores a vision model against the hand-labelled gold set (context/research/vision.md §10).
 *
 * Three figures, reported separately because they carry different consequences:
 *
 *  - **price accuracy** decides whether a verdict can be trusted at all;
 *  - **mechanic accuracy** is the product wedge — reading "1+1" as a flat discount misprices the
 *    basket even when every number is right;
 *  - **offer recall** is the survivable one: a missed offer becomes "brak danych", which the
 *    guardrail is built for, while a wrong one becomes a false verdict, which it is not.
 *
 * Writes nothing to price_entries. The point is to decide whether ingestion may be trusted, not to
 * ingest.
 */
class MeasureVisionAccuracy extends Command
{
    protected $signature = 'leaflets:measure-vision
                            {--model= : Override the configured model, to score an escalation candidate}';

    protected $description = 'Score a vision model against the hand-labelled Biedronka gold set';

    public function handle(VisionParser $parser): int
    {
        $gold = $this->gold();

        if ($gold === null) {
            return self::FAILURE;
        }

        if ($model = $this->option('model')) {
            config()->set('leaflets.vision.model', $model);
        }

        $this->info('Model: '.config('leaflets.vision.model'));
        $this->info('Gold set: '.$gold['leaflet'].' — pages '.implode(', ', array_keys($gold['pages'])));

        $assets = $this->assetsFor(array_keys($gold['pages']));

        if ($assets === []) {
            $this->error('No page images on disk. Fetch them first: ddev artisan leaflets:ingest biedronka --dry-run');

            return self::FAILURE;
        }

        [$priceHits, $priceTotal, $mechanicHits, $mechanicTotal, $found, $expected] = $this->score($parser, $assets, $gold);

        $this->newLine();
        $this->table(
            ['metric', 'score', 'basis'],
            [
                ['price accuracy', $this->pct($priceHits, $priceTotal), "{$priceHits}/{$priceTotal} matched offers"],
                ['mechanic accuracy', $this->pct($mechanicHits, $mechanicTotal), "{$mechanicHits}/{$mechanicTotal} matched offers"],
                ['offer recall', $this->pct($found, $expected), "{$found}/{$expected} labelled offers found"],
            ],
        );

        $this->newLine();
        $this->comment('Decision rule (context/research/vision.md §10, written before any results):');
        $this->line('  >=98% price and >=95% mechanic  → adopt; Biedronka ships on real data.');
        $this->line('  90-98% price                    → adopt with the validation gate mandatory; expect a visible "brak danych" rate.');
        $this->line('  <90% price                      → escalate to claude-haiku-4.5 and mistral-ocr-4.1; if none clears 90%,');
        $this->line('                                    empty Biedronka\'s parser list in config/leaflets.php and ship Lidl only.');
        $this->newLine();
        $this->comment('Record the model, the date, the three scores and the branch that fired in');
        $this->comment('context/changes/leaflet-vision-ingestion/measurement.md — before deciding, not after.');

        return self::SUCCESS;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int}
     */
    private function score(VisionParser $parser, array $assets, array $gold): array
    {
        $priceHits = $priceTotal = $mechanicHits = $mechanicTotal = $found = $expected = 0;

        foreach ($assets as $page => $asset) {
            $labelled = $gold['pages'][(string) $page]['offers'] ?? [];
            $expected += count($labelled);

            $read = $parser->parse([$asset]);
            $this->line(sprintf('  page %-3s labelled=%-3s read=%-3s', $page, count($labelled), count($read)));

            foreach ($labelled as $truth) {
                $match = $this->bestMatch($truth['name'] ?? '', $read);

                if ($match === null) {
                    continue;
                }

                $found++;

                $priceTotal++;
                if ($this->sameMoney($truth['regular_price'] ?? null, $match->regularPrice)
                    && $this->sameMoney($truth['promo_price'] ?? null, $match->promoPrice)) {
                    $priceHits++;
                }

                $mechanicTotal++;
                if (($truth['promo_type'] ?? null) === $match->promoType->value) {
                    $mechanicHits++;
                }
            }
        }

        return [$priceHits, $priceTotal, $mechanicHits, $mechanicTotal, $found, $expected];
    }

    /**
     * Pair a labelled offer with what the model read, by name.
     *
     * Names never match character for character — the model normalises whitespace and truncates —
     * so pairing is on a normalised prefix. A pairing that fails counts as a missed offer, which is
     * the conservative reading: it lowers recall rather than silently inflating price accuracy.
     *
     * @param  list<Offer>  $read
     */
    private function bestMatch(string $name, array $read): ?Offer
    {
        $needle = $this->normalise($name);

        if ($needle === '') {
            return null;
        }

        foreach ($read as $offer) {
            $candidate = $this->normalise($offer->rawName);

            if ($candidate !== '' && (str_starts_with($candidate, mb_substr($needle, 0, 18)) || str_starts_with($needle, mb_substr($candidate, 0, 18)))) {
                return $offer;
            }
        }

        return null;
    }

    private function normalise(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? '');
    }

    private function sameMoney(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((float) str_replace(',', '.', $a) - (float) $b) < 0.005;
    }

    private function pct(int $hits, int $total): string
    {
        return $total === 0 ? 'n/a' : sprintf('%.1f%%', 100 * $hits / $total);
    }

    /**
     * @param  list<string>  $pages
     * @return array<int, Asset>
     */
    private function assetsFor(array $pages): array
    {
        $config = config('leaflets.chains.biedronka');

        /** @var Discoverer $discoverer */
        $discoverer = app($config['discoverers'][0]);
        $flyer = $discoverer->discover()[0] ?? null;

        if ($flyer === null) {
            return [];
        }

        /** @var Acquirer $acquirer */
        $acquirer = app($config['acquirers'][0]);

        $wanted = array_map('intval', $pages);
        $assets = [];

        foreach ($acquirer->acquire($flyer) as $asset) {
            if (in_array($asset->pageNumber, $wanted, true)) {
                $assets[$asset->pageNumber] = $asset;
            }
        }

        return $assets;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gold(): ?array
    {
        $path = base_path('tests/Fixtures/Ingestion/biedronka-gold-set/labels.json');

        if (! is_file($path)) {
            $this->error('No gold set at '.$path);

            return null;
        }

        $gold = json_decode((string) file_get_contents($path), true);

        // The whole value of a gold set is that a human, not a model, decided what is true. Scoring
        // against the model's own pre-filled reading would report near-perfect accuracy and mean
        // nothing, so this refuses rather than producing a number that flatters.
        if (($gold['verified'] ?? false) !== true) {
            $this->error('The gold set is still marked "verified": false — it holds the model\'s own reading.');
            $this->line('Check every offer against the page images, correct them, then set "verified": true.');
            $this->line('See tests/Fixtures/Ingestion/biedronka-gold-set/README.md.');

            return null;
        }

        return $gold;
    }
}
