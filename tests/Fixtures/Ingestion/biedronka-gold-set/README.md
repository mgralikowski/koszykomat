# Biedronka gold set

Hand-labelled ground truth for measuring how accurately a vision model reads Biedronka's leaflet,
per `context/research/vision.md` §10. It is also meant to outlive the measurement as the ingestion
regression fixture — `VisionOfferMappingTest` replays `model-response.json` through the offer
mapping with no network and no images.

## Status: NOT YET VERIFIED

`labels.json` is currently **pre-filled with the model's own reading** (2026-08-30) and carries
`"verified": false`. In that state it is a starting point for a human, not ground truth. Measuring a
model against its own output measures nothing, so `leaflets:measure-vision` refuses to score until
the flag is flipped.

To verify: open each page image beside `labels.json`, correct every field against what is printed,
add the offers the model missed, delete the ones it invented, then set `complete: true` on each page
and `verified: true` with your name and the date.

## Which leaflet

| | |
| --- | --- |
| Slug | `codziennie-niskie-ceny-p-oferta-od-27-08` |
| Validity | 2026-08-27 – 2026-09-02 |
| Pages in leaflet | 53 |
| Pages in the gold set | 3, 4, 5, 7, 9 |

This is Biedronka's **main food leaflet**, and picking it is less obvious than it sounds. The chain
publishes ~13 concurrent leaflets; `home-od-DD-MM` is Biedronka Home (solar lamps, garden tools) and
`bts-oferta-…` is a back-to-school catalogue. The food one is the ~53-page
`codziennie-niskie-ceny-…`, which is the page count `vision.md` §3 measured.

## Why the images are not committed

Five pages at ~2.6 MB are ~13 MB, and a fixture that heavy earns its place only if it is the only
way to re-run the measurement. It is not: the pages are reachable from the chain's own API, and the
durable half of this artefact — the labels and one captured model response — is a few kilobytes.

Re-fetch the pages with:

```bash
ddev artisan leaflets:ingest biedronka --dry-run   # downloads to storage/app/leaflets/biedronka/<uuid>/
```

Note that leaflets rotate weekly. Once `codziennie-niskie-ceny-p-oferta-od-27-08` is gone from the
chain's listing its pages cannot be re-fetched, so a verified gold set should be treated as the
durable artefact and this leaflet's pages as disposable.

## Mechanic coverage

`vision.md` §10 asks for all four original mechanics including one "za grosz". On these five pages
the model found `one_plus_one`, `loyalty_card` and `second_for_fixed` (the "za grosz" family), but no
`simple` and no `conditional_unit_price`. Whether that reflects the leaflet or the model's recall is
itself one of the things the measurement answers — the model reported only 1–3 offers per page,
where `vision.md` §7 estimated ~25. **Offer recall is the metric to watch here**, and it is the one
§10 calls survivable: a missed offer becomes "brak danych", a wrong one becomes a false verdict.

If verification shows these pages genuinely lack a mechanic, add a sixth page rather than pretending
the coverage is complete.
