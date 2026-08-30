# Vision accuracy measurement — Biedronka

Run per `context/research/vision.md` §10, against the hand-checked gold set in
`tests/Fixtures/Ingestion/biedronka-gold-set/`.

| | |
| --- | --- |
| Model | `gemini-3.5-flash-lite` |
| Date | 2026-08-30 |
| Leaflet | `codziennie-niskie-ceny-p-oferta-od-27-08` (53 pages, valid 2026-08-27 – 2026-09-02) |
| Pages scored | 3, 4, 5, 7, 9 |
| Labelled offers | 11 |
| Command | `ddev artisan leaflets:measure-vision` |

## Scores

| Metric | Score | Basis |
| --- | --- | --- |
| Price accuracy | **90.9%** | 10 / 11 matched offers |
| Mechanic accuracy | 100.0% | 11 / 11 matched offers |
| Offer recall | 100.0% | 11 / 11 labelled offers found |

## Branch that fired

The rule, quoted from `vision.md` §10 and fixed before any result was seen:

- ≥98% price and ≥95% mechanic → adopt; Biedronka ships on real data.
- **90–98% price → adopt with the §9 validation gate mandatory, and expect a visible "brak danych" rate on Biedronka.**
- <90% price → escalate to `claude-haiku-4.5` and `mistral-ocr-4.1`; if none clears 90%, empty Biedronka's parser list and ship Lidl only.

Price accuracy of 90.9% lands in the middle band, so **the second branch fires**: Biedronka is adopted, the validation gate stays mandatory, and some share of its rows will surface as "brak danych" rather than as prices.

No configuration change follows from this. The gate has been mandatory since phase 1 — every candidate row passes `App\Ingestion\Validation\PriceEntryGate` before it is written, and a flagged row is invisible to every verdict through `PriceEntry::usableOn()`. The branch prescribes exactly what is already in place, which is the outcome to hope for from a rule written in advance.

## How much these numbers are worth

**Recall of 100% means nothing here, and mechanic accuracy of 100% means little.** The gold set was pre-filled with this same model's first reading and then corrected by hand; the corrections covered purchase quantities on pages 3 and 4 and the Lavazza size, not the full set of offers on each page. So the labels contain, by construction, the offers the model found — and cannot contain the ones it missed. Recall measured this way is a tautology.

The figure with real content is **price accuracy**, and only because the second read disagreed with the first on one offer out of eleven. That disagreement is itself the finding: the model is not deterministic across runs on the same page, so a single measurement is a sample, not a constant.

What this measurement does **not** establish:

- **True recall.** During phase 2 the model reported 1–3 offers per page where `vision.md` §7 estimated ~25. If that estimate is right, real recall is far below 100% and most of the leaflet is simply not being read. A gold set built by hand from the page images — rather than seeded from the model — would show this immediately.
- **Coverage of all five mechanics.** These pages produced `one_plus_one`, `loyalty_card` and `second_for_fixed`, but no `simple` and no `conditional_unit_price`. `vision.md` §10 asked for all four original mechanics; three of four is what the sample gave.
- **Whether prices are right in absolute terms**, as opposed to consistent with a label the model itself proposed.

## What to do before trusting this further

1. Rebuild the gold set from the page images alone, without the model's reading as a starting point, and re-run. That is the only way the recall figure becomes meaningful.
2. Score `claude-haiku-4.5` on the same set — `vision.md` §8 notes it is the only model with a prior positive result on this corpus, and the comparison costs one command with `--model`.
3. Re-run after a leaflet rotation. One leaflet is one sample, and the non-determinism seen here suggests the variance is not negligible.

Until then, treat the adopt-with-gate branch as provisional: it is the honest reading of the numbers available, taken with the knowledge that the numbers flatter.
