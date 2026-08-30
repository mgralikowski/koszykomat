<?php

use App\Ingestion\Drivers\Biedronka\BiedronkaApiImageAcquirer;
use App\Ingestion\Drivers\Biedronka\BiedronkaHtmlDiscoverer;
use App\Ingestion\Drivers\Biedronka\VisionParser;
use App\Ingestion\Drivers\Lidl\LidlApiPdfAcquirer;
use App\Ingestion\Drivers\Lidl\LidlHtmlDiscoverer;
use App\Ingestion\Drivers\Lidl\PdfTextParser;

return [

    /*
    |--------------------------------------------------------------------------
    | Asset retention
    |--------------------------------------------------------------------------
    |
    | How long downloaded leaflets stay on disk before AssetStore prunes them.
    | A weekly cycle is roughly 170 MB, so two months settles near 1.5 GB — on
    | the same volume MySQL writes to, which is why this is bounded at all.
    |
    */

    'retention_months' => 2,

    /*
    |--------------------------------------------------------------------------
    | Vision model
    |--------------------------------------------------------------------------
    |
    | Only Biedronka needs one: its API returns page images and nothing else,
    | while Lidl publishes a PDF with a real text layer. The credential is read
    | by Prism from GEMINI_API_KEY via config/prism.php — there is deliberately
    | no entry in config/services.php, because Prism would not read it.
    |
    */

    'vision' => [
        'provider' => env('LEAFLET_VISION_PROVIDER', 'gemini'),
        'model' => env('LEAFLET_VISION_MODEL', 'gemini-3.5-flash-lite'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chains
    |--------------------------------------------------------------------------
    |
    | Each stage is an ordered list of driver classes, tried until one returns a
    | usable result. A chain can therefore grow a fallback (a scraper behind an
    | API reader) without the ingestion engine learning anything new. Emptying a
    | chain's `parsers` list is also how a chain gets switched off — which is the
    | escape hatch if vision accuracy on Biedronka turns out too low to trust.
    |
    */

    'chains' => [

        'lidl' => [
            'discovery_url' => 'https://www.lidl.pl/c/nasze-gazetki/s10008614',
            'flyer_api' => 'https://endpoints.leaflets.schwarz/v4/flyer',
            'region_id' => 0,
            // Weekly food leaflets only. Lidl also publishes themed catalogues (alcohol, school
            // supplies) under the same listing; ingesting all ten cost 152 MB of disk per run and
            // contributed nothing a grocery basket can use.
            'leaflet_slug_pattern' => '/^gazetka-wazna-od-.*-gazetka-/i',
            'discoverers' => [LidlHtmlDiscoverer::class],
            'acquirers' => [LidlApiPdfAcquirer::class],
            'parsers' => [PdfTextParser::class],
        ],

        'biedronka' => [
            'discovery_url' => 'https://www.biedronka.pl/pl/gazetki',
            'leaflet_api' => 'https://leaflet-api.prod.biedronka.cloud/api/leaflets',
            // The main food leaflet only, and only by its exact slug shape. Biedronka publishes
            // ~13 concurrent leaflets and a loose match picks the wrong one every time: /oferta/
            // matched a July back-to-school catalogue, and "home-od-DD-MM" turned out to be
            // Biedronka Home — solar lamps and garden tools, not groceries. The weekly food
            // leaflet is "codziennie-niskie-ceny-…-od-DD-MM" and is the only one with ~53 pages,
            // which is the figure context/research/vision.md §3 measured. Most recent wins.
            'leaflet_title_pattern' => '/^codziennie-niskie-ceny-.*-od-(\\d{2})-(\\d{2})$/',
            // Biedronka's leaflet API returns images and nothing else — no dates. The start date
            // lives in the slug and the leaflets run weekly, so the window is derived from the
            // source rather than assumed from today's date.
            'validity_days' => 7,
            'max_pages' => 60,
            'discoverers' => [BiedronkaHtmlDiscoverer::class],
            'acquirers' => [BiedronkaApiImageAcquirer::class],
            'parsers' => [VisionParser::class],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Pairing map — the bridge from a leaflet offer to the catalogue
    |--------------------------------------------------------------------------
    |
    | `network_products.product_id` is NOT NULL: every chain listing must point at
    | a canonical, chain-neutral product, and that pairing is what the whole
    | verdict rests on. The PRD puts advanced matching out of scope ("tylko proste
    | odpowiedniki z jawnym oznaczeniem różnic"), and automatic matching on Polish
    | leaflet names cannot work anyway — Lidl sells Pilos where Biedronka sells
    | Łowicz, so name similarity would produce either no pairs or wrong ones. A
    | wrong pair is a false verdict, which is the failure FR-008 exists to prevent.
    |
    | So pairing is declared, never inferred. An offer whose name matches no
    | pattern here is skipped: the catalogue grows exactly as fast as this map is
    | filled in, and never by guessing.
    |
    | A pattern MUST pin the size when the canonical product declares one. Without
    | it "/kawa ziarnista/" matched a 500 g WOSEBA against the canonical "Kawa
    | ziarnista 1 kg" and the verdict would have compared half a kilo to a kilo —
    | the false-pairing failure FR-008 exists to prevent, arriving through a lazy
    | regex rather than through a matching heuristic.
    |
    | Patterns key on the product category and its defining attribute (fat content,
    | grind, weight) rather than on a brand: leaflet brands rotate weekly — this
    | week Lidl sells Mleko UHT 3,2% Łączka where last week it was Pilos — so a
    | brand-keyed pattern silently stops matching after seven days.
    |
    | `brand` is deliberately NOT declared here. FR-008 requires the report to show
    | what was actually paired, and a hardcoded brand would keep claiming "Pilos"
    | long after the leaflet moved on. The raw leaflet name is stored verbatim on
    | the listing and carries the brand honestly; `size_label` stays because it is
    | a property of the canonical product, not of this week's offer.
    |
    */

    'pairing' => [

        'mleko-32-1l' => [
            'name' => 'Mleko 3,2% 1 l',
            'chains' => [
                'lidl' => ['patterns' => ['/mleko\\s+uht\\s+3,2%/iu'], 'size_label' => '1 l'],
                'biedronka' => ['patterns' => ['/mleko\\s+uht\\s+3[,.]2/iu', '/mleko\\s+świeże\\s+3[,.]2/iu'], 'size_label' => '1 l'],
            ],
        ],

        'maslo-extra-200g' => [
            'name' => 'Masło extra 200 g',
            'chains' => [
                'lidl' => ['patterns' => ['/masło\\s+z\\s+polskiej\\s+mleczarni/iu'], 'size_label' => '200 g'],
                'biedronka' => ['patterns' => ['/masło\\s+(?:extra|osełka|polskie)/iu'], 'size_label' => '200 g'],
            ],
        ],

        'kawa-ziarnista-1kg' => [
            'name' => 'Kawa ziarnista 1 kg',
            'chains' => [
                'lidl' => ['patterns' => ['/kawa\\s+ziarnista.{0,45}\\b1\\s*kg\\b/isu'], 'size_label' => '1 kg'],
                'biedronka' => ['patterns' => ['/kawa\\s+ziarnista.{0,45}\\b1\\s*kg\\b/isu'], 'size_label' => '1 kg'],
            ],
        ],

        'czekolada-mleczna-100g' => [
            'name' => 'Czekolada mleczna 100 g',
            'chains' => [
                'lidl' => ['patterns' => ['/czekolada\\s+mleczna/iu'], 'size_label' => '100 g'],
                'biedronka' => ['patterns' => ['/czekolada\\s+mleczna/iu'], 'size_label' => '100 g'],
            ],
        ],

        // Declared for one chain only, on purpose. The catalogue is chain-neutral, so a product
        // with a single listing is a legitimate row that simply cannot be compared yet — the
        // whole-basket rule reports "brak danych" for it until the other chain's equivalent is
        // declared here too. Inventing a counterpart to make the basket comparable is exactly the
        // false pairing FR-008 forbids: Lidl's "Chleb typu włoskiego 500 g" is not Biedronka's
        // "Chleb orkiszowy 410 g", however convenient a comparison would be.

        'cukier-bialy-1kg' => [
            'name' => 'Cukier biały 1 kg',
            'chains' => [
                'biedronka' => ['patterns' => ['/cukier\\s+biały.{0,20}\\b1\\s*kg\\b/isu'], 'size_label' => '1 kg'],
            ],
        ],

        'olej-rzepakowy-2l' => [
            'name' => 'Olej rzepakowy 2 l',
            'chains' => [
                'biedronka' => ['patterns' => ['/olej\\s+rzepakowy.{0,25}\\b2\\s*l\\b/isu'], 'size_label' => '2 l'],
            ],
        ],

        'sok-100-1l' => [
            'name' => 'Sok 100% 1 l',
            'chains' => [
                'biedronka' => ['patterns' => ['/sok\\s+100%.{0,25}\\b1\\s*l\\b/isu'], 'size_label' => '1 l'],
            ],
        ],

        'woda-zrodlana-6x175l' => [
            'name' => 'Woda źródlana 6 × 1,75 l',
            'chains' => [
                'biedronka' => ['patterns' => ['/woda\\s+źródlana.{0,30}6\\s*x\\s*1,75/isu'], 'size_label' => '6 × 1,75 l'],
            ],
        ],

    ],
];
