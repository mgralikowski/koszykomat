---
topic: vision-API vendor for leaflet ingestion
researched_at: 2026-08-29
unblocks: F-03 (leaflet-vision-ingestion), S-04 (nightly-refreshed-real-data)
resolves: roadmap Open Question 3 (`top_blocker: external`)
status: recommendation — ready for /10x-new
recommendation: per-chain split — Lidl = PDF text layer (no vision), Biedronka = Gemini 3.5 Flash-Lite
prior_art: ../../../gazetki.py, ../../../ocr_mvp.py, ../../../docs/{RESEARCH,ARCHITECTURE,STACK}.md
verified_live: 2026-08-29 (all endpoints re-tested against current leaflets)
---

# Vision-API vendor for leaflet ingestion

> Research for roadmap items **F-03** and **S-04**, both blocked on Open Roadmap Question 3:
> *"Which vision-API vendor for leaflet ingestion, and is its parsing accuracy/cost acceptable?"*
>
> **This document supersedes desk research with empirical verification.** Prior art in
> `~/Projects/10x/` (a Playwright downloader, a Tesseract test, and three recon documents from
> 2026-06-02) already answered much of this. Every claim in that prior art was **re-tested live on
> 2026-08-29** against current leaflets; commands are in §10 so anything here can be re-run.

## 1. TL;DR — the question was the wrong shape

The roadmap asks *"which vision vendor?"* as a single global choice with `top_blocker: external`.
Verification shows three things that dissolve most of the problem:

1. **Lidl needs no vision at all.** Its leaflet is published as a **PDF with a real text layer**,
   reachable from a public JSON API. Product names, promo prices, regular prices, unit prices,
   purchase conditions, limits and dates come out of `pdftotext` **exactly** — no OCR, no model, no
   extraction risk, zero marginal cost. Verified today on the current 95-page leaflet.
2. **Only Biedronka needs a vision model.** Its API returns images and nothing else. That is the
   *entire* scope of the vendor decision — roughly half the corpus, ~53 pages/week.
3. **No browser worker is needed.** Both chains' discovery, metadata and assets are reachable with
   plain HTTP. This contradicts the existing `docs/STACK.md`, which specifies a separate
   Node + Playwright worker. Verified today for both chains (§6).

Net effect: the blocker shrinks from *"choose and trust a vision vendor for the whole product"* to
*"choose a vision model for one chain's images, on top of a Lidl path that is exact by construction."*
Vision spend lands **under $2/month** (§7). There is no external dependency and nothing to contract.

**F-03 is ready to plan.**

## 2. Prior art — what was already established

`~/Projects/10x/` contains work from 2026-06-02 that predates this project's `context/` structure:

| Artefact | What it is |
| --- | --- |
| `gazetki.py` | Playwright downloader — discovery + page images for both chains |
| `ocr_mvp.py` | Tesseract + regex extraction test (Polish) |
| `docs/RESEARCH.md` | Live recon of both chains' endpoints — the key document |
| `docs/ARCHITECTURE.md` | Driver architecture: Discover → Acquire → Parse, Strategy + fallback |
| `docs/STACK.md` | Target stack proposal — **now largely stale**, see §9 |
| `gazetki/` | 116 real leaflet pages: Lidl 112 (718×1200), Biedronka 7 (998×1624) |

`RESEARCH.md`'s central finding — *"to nie jest jeden problem, tylko per-sklep"* — is correct and is
the most valuable thing in the folder. This document confirms it and carries it into `context/`.

## 3. Verified: the two chains are different problems

| | Discovery | Content source | Product data |
| --- | --- | --- | --- |
| **Lidl** | anchors in server HTML — plain `curl` ✅ | JSON API → **PDF with text layer** ✅ | exact, from text — **no OCR** |
| **Biedronka** | anchors in server HTML — plain `curl` ✅ | JSON API → **page images only** | **vision required** |

Re-verified 2026-08-29 (prior art said Biedronka discovery needed a browser; it no longer does):

- `GET https://www.lidl.pl/c/nasze-gazetki/s10008614` → HTTP 200, **9 current flyer slugs** in static HTML.
- `GET https://endpoints.leaflets.schwarz/v4/flyer?flyer_identifier=<slug>&region_id=0` → HTTP 200,
  95 pages, `startDate` 2026-08-26 / `endDate` 2026-08-30, `pdfUrl` (32 MB), page `width` 1436 × `height` 2400.
- `GET https://www.biedronka.pl/pl/gazetki` → HTTP 200, **13 leaflet anchors** in static HTML.
- Leaflet UUID is present in the leaflet page's static HTML; `GET
  https://leaflet-api.prod.biedronka.cloud/api/leaflets/<uuid>?ctx=web` → HTTP 200, 53 pages,
  104 image URLs, `images_desktop` / `images_mobile` / `format` **only** — no PDF, no product fields.
- A full Biedronka page image is **1146×1800 PNG**, ~2.6 MB.

## 4. Lidl — the PDF text layer carries all four FR-007 mechanics

This is the finding that most changes F-03's shape. `pdftotext -layout` on the current leaflet yields
**15,559 words across 95 pages**. Sample (page 3), verbatim:

```
Taniej o  23%      4,59  Cena za 1 opakowanie
                   5,99  Cena za 1 opakowanie
Olej rzepakowy, 1 l
```

Mechanic-phrase frequency across the whole leaflet, and how each maps onto `App\Enums\PromoType`:

| Phrase | Hits | Maps to |
| --- | --- | --- |
| `cena poza promocją` | 100 | `regular_price` |
| `Najniższa cena` (z 30 dni przed obniżką) | 55 | omnibus price — legally mandated, useful cross-check |
| `przy zakupie <n>` | 94 | `required_quantity` |
| `taniej` / `-NN%` | 44 | `Simple` |
| `kupon` + `Lidl Plus` | 54 / 39 | `LoyaltyCard` |
| `gratis` | 25 | `OnePlusOne` |
| `za grosz` | 8 | `SecondForFixed` (`second_item_price = 0.01`) |
| `1+1` / `2+1` | 1 / 2 | `OnePlusOne` / n-th-free variant |

Real extracted conditions, showing the parameters are present, not merely the label:

```
* cena za 1 opak. przy zakupie 2 opak. lub wielokrotności 2 opak.
  Limit: 4 opak. na paragon.
  Cena przed obniżką/cena poza promocją: 17,99/opak., 1 kg = 35,98

* Trzeci, najtańszy za grosz.
  Limit: 2 opak. za grosz na paragon.
  2+1
```

Two observations worth carrying into F-03's plan:

- **The PRD's `second_for_fixed` is narrower than reality.** Lidl prints *"Trzeci, najtańszy za grosz"*
  (third item, cheapest one for a grosz) and *"8 w cenie 4"* — not just *"drugi za grosz"*. F-01's
  `required_quantity` column already accommodates n=3; the enum's *name* implies n=2. Worth a look
  during F-03 research, not a schema change now.
- **A caveat on the text layer:** `pdftotext` emits `Syntax Error: insufficient arguments for Marked
  Content` warnings on this file. Text extraction is unaffected (15,559 words came out clean), but the
  parser must not treat stderr output as failure.

**Conclusion: Lidl is a `PdfTextParser`, not a vision problem.** Extraction is deterministic and
`confidence = 1.0`. Half the product's data carries no hallucination risk whatsoever.

## 5. Biedronka — vision is the only path, and Tesseract is already ruled out

`ocr_mvp.py` was run against real pages and the outputs are on disk. They demonstrate the failure mode
precisely. From `gazetki/lidl/<flyer>/ocr/page_01.txt`:

```
2+1
za grosz
...
4009
1892699 * cena przed obniżką
69 99 E  Kiełbasa śląsk Ę
169
```

Tesseract read the *promo labels* (`2+1`, `za grosz`) but turned the **prices into garbage**:
`1892699` is a mangled `18,92`/`26,99`; `4009` is `40,09` or `4,00 9`; `69 99 E` is `69,99`. The
derived `products.json` contains **9 entries for 3 pages, none of them an actual product with a
price** — every row is a date range or a stray unit price.

This is exactly the failure the PRD's guardrail forbids: not a crash, but **confident, well-formed,
wrong numbers**. `RESEARCH.md` names the cause correctly — large prices and promo badges are rendered
as **stylised graphics, not text**, so there is no glyph for OCR to read.

Two fairness caveats that do **not** rescue Tesseract: the test ran on Lidl images at **718×1200**,
about half the source resolution (the API reports 1436×2400), and on the one chain that never needed
OCR in the first place. Higher-resolution input would improve the *body* text — but the prices are
graphics at any resolution. `ARCHITECTURE.md` already records `TesseractParser` as consciously
rejected. That stands.

**So Biedronka needs a vision model**, and that — ~53 pages/week of 1146×1800 images — is the whole
vendor decision.

## 6. No Playwright worker required (corrects `STACK.md`)

`STACK.md` recommends a separate **Node + Playwright worker**, reasoning that JS-rendered discovery
(*"zwłaszcza Biedronka"*) puts it out of PHP's reach. That was true in June; it is not true today.
Verified above: both chains' leaflet lists, Biedronka's leaflet UUID, Lidl's flyer API, the PDF, and
Biedronka's image URLs are **all reachable with plain HTTP**.

This matters more than it looks, because a Playwright worker was the single worst fit for this
project's infrastructure. `infrastructure.md` describes a shared DirectAdmin VPS with no container
runtime and an open risk that *"a runaway leaflet-ingestion job … can starve PHP-FPM — and now the
MySQL server too — for everything on the machine."* Chromium on that box would have been a serious
liability. Dropping it means **F-03 is pure Laravel**: `Http::get()`, `smalot/pdfparser` (or the
`poppler` binary), one queued job, one vision call per Biedronka page.

Keep the browser path in mind only as a documented fallback if either chain re-renders its listing
client-side. Prefer the chains' **own** endpoints over aggregators (Blix, gazetki.pl), whose terms
generally prohibit automated collection — the leaflets are public marketing material, the
aggregators' databases are not.

## 7. Cost — vision applies to Biedronka only

**Volume** (this also answers roadmap Open Question 2, *data volume*):

- Lidl: ~95 pages/week → **$0 vision cost** (PDF text).
- Biedronka: ~53 pages/week in the main food leaflet → ~230 pages/month.
  Note Biedronka runs **13 concurrent leaflets** (`home`, `festiwal nabiału`, `codziennie niskie
  ceny`, …); MVP should ingest the main food leaflet only, or costs multiply by the number ingested.
- Tokens per Biedronka page: 1146×1800 tiles to 6 × 768² tiles ≈ **1,550 input tokens**; ~3,000 output
  tokens for ~25 offers of JSON.

| Option | Price (per MTok in/out) | Per page | **Per month** | Per year |
| --- | --- | --- | --- | --- |
| **Gemini 3.5 Flash-Lite (recommended)** | $0.30 / $2.50 | $0.0080 | **$1.83** | $22 |
| Gemini 3.5 Flash-Lite — batch | 50% off | $0.0040 | **$0.92** | $11 |
| GPT-5.6 Luna | $0.20 / $1.20 | $0.0039 | **$0.90** | $11 |
| Claude Haiku 4.5 | $1.00 / $5.00 | $0.0166 | **$3.81** | $46 |
| Claude Sonnet 5 | $2.00 / $10.00 | $0.0331 | **$7.61** | $91 |
| Gemini 3.7 Flash (to 2026-12-31) | $0.75 / $3.75 | $0.0124 | **$2.86** | $34 |
| Mistral OCR 4.1 + annotations | €4.38 / 1,000 pages | €0.0044 | **€1.01** | €12 |

**Cost is not a discriminator** — the whole field is $1–8/month. Choose on accuracy and auditability.

**One real cost lever:** FR-009 specifies a *nightly* refresh but leaflets are *weekly*. Re-ingesting
every night multiplies spend ~7× for nothing. Hash the leaflet (or compare the API's leaflet id and
validity window) and skip when unchanged — on a normal night the job makes **zero** API calls, which
also caps the runaway-job risk in the infrastructure register.

## 8. Vendor recommendation for the Biedronka path

**Gemini 3.5 Flash-Lite** ($0.30/$2.50), called via **Prism PHP** (`prism-php/prism`) behind a
`Parser` interface:

- Gemini 3 Flash currently ranks **#1 on OCR Arena** (ELO 1798, 87.5% win rate over Mistral OCR v3);
  Gemini 3.5 Flash tops Roboflow's Vision Evals across 67 prompts including document understanding and
  **spatial reasoning** — which is the hard part here: binding a `1+1 GRATIS` starburst to the correct
  product tile on a dense page.
- **Native bounding boxes** (`box_2d`, normalised 0–1000) — the single most valuable feature for the
  guardrail, because every extracted price can keep the crop it came from and a human spot-check
  becomes a 10-second job.
- **JSON-schema structured output** (`response_schema`) — the extraction contract becomes a schema,
  so malformed output is a transport error rather than silent bad data.
- **Batch API at 50% off**, and a weekly leaflet with a known validity start date tolerates a 24 h
  batch turnaround provided ingestion runs the day before validity begins.
- **EEA data terms are better than commonly reported.** Google's Gemini API terms state: *"If you're in
  the European Economic Area, Switzerland, or the United Kingdom, the terms under 'How Google uses Your
  Data' in 'Paid Services' apply to all Services, including Google AI Studio and unpaid quota."*
  Several widely-cited blog posts claim EU users cannot use the free tier at all — they are wrong. A
  free-quota spike is legitimate; production should still use paid quota for rate limits, not data terms.

**Runner-up — Mistral OCR 4.1** (€3.50/1k pages, €4.38 annotated): EU vendor, flat pricing, paragraph
bounding boxes **plus block-level confidence scores**, self-hosting available. Choose it if EU
sovereignty becomes a requirement. Risk: its annotation stage runs on a small model
(`mistral-small-2603`), and promo semantics is where a small model is most likely to be confidently wrong.

**Also worth a slot in the bake-off — Claude Haiku 4.5.** `STACK.md` already assumed Claude here, and
`RESEARCH.md` notes Claude vision successfully extracted full structure from the same Biedronka images
that defeated Tesseract. That is the only *positive* vision result on this corpus so far — it deserves
to be measured rather than dropped.

**Rejected — self-hosted VLMs** (dots.ocr, PaddleOCR-VL, Qwen2.5-VL): competitive on benchmarks,
impossible on a shared GPU-less VPS. See §6.

**Rejected — classic OCR** (Tesseract, Textract, Document AI): empirically disproven on this corpus (§5).

## 9. Guardrail: validation, not vendor confidence

The failure mode is confident well-formed wrong JSON — as `products.json` demonstrates on real data.
The obvious defences do not work:

- On DocILE (55-field invoice benchmark, frontier LLMs fail **26%** of fields), mean log-probability
  detects errors at only **0.705 ROC AUC**, degrading to an all-positive classifier at any usable
  threshold. Model-verbalised confidence: **0.692**.
- **Self-consistency over five samples reaches 0.744 AUC at 5× the cost.** When a model is confidently
  wrong, all five samples agree.

Asking the model how sure it is, or asking repeatedly, is not a trust mechanism. **Deterministic
post-extraction validation is** — and this domain is unusually well-suited to it:

1. **`PromoType::parameterContract()`** — already shipped in F-01. Reject any row whose parameters
   contradict its mechanic. A hallucinated `one_plus_one` carrying a `promo_price` and no
   `required_quantity` is structurally impossible and is caught for free.
2. **Mechanic invariants** — `one_plus_one` ⇒ `required_quantity ≥ 2`, `second_item_price = 0.00`;
   `second_for_fixed` ⇒ `0 < second_item_price` and small; `simple`/`loyalty_card` ⇒
   `promo_price < regular_price`.
3. **Omnibus cross-check (Lidl only, free)** — the mandated *"Najniższa cena z 30 dni przed obniżką"*
   appears 55× in the text layer and is an independent witness to `regular_price`.
4. **Cross-leaflet plausibility** — compare against the same `network_product`'s previous price; flag
   swings beyond ±60%.
5. **Bounding-box retention** — store `box_2d` per Biedronka row so any flag resolves to a crop.
6. **Fail closed** — a failing row is stored as `needs_review`, never as priced data, and the basket
   verdict returns **"brak danych"** for that product. That is the PRD guardrail implemented as a
   data-integrity rule rather than a hopeful prompt.

### Concrete schema gap in F-01

`ARCHITECTURE.md`'s `Product` value object carries `confidence: float` (*"1.0 = z danych
strukturalnych, <1 = OCR/vision"*) and `source: str` (*"nazwa drivera, dla audytu"*). **F-01's shipped
`price_entries` table has neither** — provenance exists only at the leaflet level
(`leaflets.source_type` / `source_reference`, `database/migrations/2026_07_25_120004_*`).

That gap matters precisely because the two chains now have **different trust levels**: a Lidl row is
exact text, a Biedronka row is a model's reading. F-03 should add to `price_entries`:

- `source` (string) — which driver produced the row;
- `confidence` (decimal, nullable) — 1.0 for PDF text, model-derived otherwise;
- `needs_review` (boolean, default false) — set by the validation gate;
- optionally `source_box` (json, nullable) — the `box_2d` crop reference.

The verdict query then excludes `needs_review` rows, and the report can honestly show *how* each price
was obtained — which is the FR-008 transparency argument extended from product pairing to provenance.

## 10. Proposed F-03 phase 1 — reproduce and measure (~1–2 h, ~$1)

The Lidl path needs no spike; it needs implementing. The spike is only for Biedronka:

1. **Build the gold set.** Take 5 real Biedronka pages covering all four mechanics, including one
   *"za grosz"*. Hand-label every offer. **This artefact outlives the spike** — it becomes the
   ingestion regression fixture and complements the four mandatory promo tests in `CLAUDE.md`.
2. **Run three candidates** through one schema via Prism: `gemini-3.5-flash-lite`, `claude-haiku-4.5`,
   `mistral-ocr-4.1`.
3. **Score separately**: price accuracy (decides whether a verdict can be trusted), mechanic
   classification accuracy (the product wedge), offer recall (a missed offer is survivable — "brak
   danych"; a wrong one is not).
4. **Decide against a rule written before results are seen:**
   - **≥98% price / ≥95% mechanic** → adopt, ship F-03.
   - **90–98% price** → adopt with the §9 gate mandatory and a `needs_review` queue; expect a visible
     "brak danych" rate on Biedronka.
   - **<90% on all three** → ship **Lidl-only** real data (which is exact) and keep Biedronka on the
     seed until resolved. The product still works: the verdict says "brak danych" for Biedronka.

That last branch is the real safety net, and it only exists because of the per-chain split. **S-04 is
shippable even if Biedronka vision underperforms** — the PRD's guardrail was designed to permit exactly
this. The roadmap treats F-03 accuracy as pass/fail for the whole stream; it is not.

### Verification commands (re-runnable)

```bash
# Lidl: discovery → API → PDF → text
curl -s 'https://www.lidl.pl/c/nasze-gazetki/s10008614' | grep -oE '/l/pl/gazetki/[a-z0-9-]+/ar/[0-9]+' | sort -u
curl -s -H 'Accept: application/json' \
  'https://endpoints.leaflets.schwarz/v4/flyer?flyer_identifier=<slug>&region_id=0' | jq '.flyer.pdfUrl, .flyer.startDate, .flyer.endDate, (.flyer.pages|length)'
pdftotext -layout leaflet.pdf - | grep -iE 'za grosz|przy zakupie|cena poza promocją'

# Biedronka: discovery → uuid → images
curl -s 'https://www.biedronka.pl/pl/gazetki' | grep -oE '/pl/press,id,[a-z0-9]+,title,[a-z0-9-]+' | sort -u
curl -s 'https://www.biedronka.pl/pl/press,id,<id>,title,<slug>' | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | sort -u
curl -s 'https://leaflet-api.prod.biedronka.cloud/api/leaflets/<uuid>?ctx=web' | jq 'keys, (.images_desktop|length)'
```

## 11. `docs/STACK.md` is stale — do not let F-03 inherit it

`STACK.md` (2026-06-02) predates this project's foundation docs and conflicts with them on most points.
It should be treated as historical, not as input:

| `STACK.md` says | Current reality |
| --- | --- |
| Laravel 11, PHP 8.3+ | Laravel 13, **PHP 8.5** (`CLAUDE.md`, `composer.json`) |
| **PostgreSQL** (JSONB, PL full-text) | **MySQL 8.0** on the VPS (`tech-stack.md`, hard constraint) |
| Breeze, email+password, "Socialite later" | **OAuth-only**, Socialite shipped (F-02 done); email+password is a PRD non-goal |
| **Redis + Horizon** | Database queues; no Redis on the DirectAdmin box |
| **Filament** admin panel | Admin panel is a PRD non-goal (CLI/jobs only) |
| **Node + Playwright worker** | Not needed — plain HTTP suffices (§6, verified) |
| Docker / Laravel Forge hosting | DirectAdmin VPS, nginx + PHP-FPM + cron (`infrastructure.md`) |
| Livewire 3 + PWA | Blade + Tailwind 4; PWA not in scope |

`ARCHITECTURE.md`, by contrast, **has aged well**. Its Discover → Acquire → Parse split with
per-shop driver lists and fallback chains maps cleanly onto the per-chain reality confirmed here, and
it already anticipated `Lidl: Parse=PdfText` / `Biedronka: Parse=Vision`. Its `Product` value object
also anticipated the `confidence` / `source` fields F-01's schema is missing (§9). It is the right
starting point for F-03's design; the PHP mapping it sketches (interfaces + tagged DI services +
`first_success` loop) is directly usable.

Suggested follow-up: move `docs/RESEARCH.md` and `docs/ARCHITECTURE.md` into `context/` as durable
inputs to F-03, and mark `docs/STACK.md` superseded by `tech-stack.md` + `infrastructure.md`.

## 12. Sources

Verified live 2026-08-29 (see §10 for commands): `lidl.pl`, `endpoints.leaflets.schwarz`,
`assets.leaflets.schwarz`, `biedronka.pl`, `leaflet-api.prod.biedronka.cloud`, `images.biedronka.cloud`.

Vendor pricing and terms — all checked 2026-08-29:

- [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing)
- [Gemini API terms](https://ai.google.dev/gemini-api/terms) — the EEA data clause quoted in §8
- [Gemini image understanding](https://ai.google.dev/gemini-api/docs/image-understanding) — tiling, bounding boxes
- [Claude API pricing](https://platform.claude.com/docs/en/about-claude/pricing)
- [OpenAI API pricing](https://developers.openai.com/api/docs/pricing)
- [Mistral OCR 4.1](https://docs.mistral.ai/models/ocr-4-1) · [OCR 4 announcement](https://mistral.ai/news/ocr-4/)
- [Prism PHP](https://prismphp.com/) · [prism-php/prism](https://github.com/prism-php/prism)

Benchmarks (ranking signal only — none measure Polish leaflets) — checked 2026-08-29:

- [OCR Arena — Mistral OCR v3 vs Gemini 3 Flash](https://www.ocrarena.ai/compare/mistral-ocr-v3/gemini-3-flash)
- [Roboflow — Gemini 3.5 Flash for vision](https://blog.roboflow.com/use-gemini-3-5-flash-vision/)
- [OmniDocBench rankings 2026](https://ofox.ai/blog/best-ai-model-for-ocr-2026/)
- [The Definitive Guide to OCR in 2026](https://slavadubrov.github.io/blog/2026/03/04/ocr-guide/)

Validation research (basis of §9) — checked 2026-08-29:

- [Beyond Logprobs: A Multi-Signal Confidence Engine for LLM-Based Document Field Extraction](https://arxiv.org/pdf/2606.24420) — DocILE AUC figures
- [Invoice Information Extraction: Methods and Performance Evaluation](https://arxiv.org/pdf/2510.15727)

Internal prior art: `~/Projects/10x/docs/{RESEARCH,ARCHITECTURE,STACK}.md`, `gazetki.py`,
`ocr_mvp.py`, `gazetki/**/ocr/{page_*.txt,products.json}` (2026-06-02).

## 13. Roadmap impact

- **Open Question 3** resolves: *"per-chain — Lidl PDF text (no vendor needed), Biedronka
  Gemini 3.5 Flash-Lite, accuracy measured in F-03 phase 1."*
- **`top_blocker: external` no longer holds.** There is no external dependency: no contract, no
  waitlist, no third party. Frontmatter should change.
- **F-03** `blocked` → `proposed`, ready for `/10x-new`. Its scope grows slightly (two parser drivers
  instead of one) but its *risk* drops sharply — half the corpus becomes exact.
- **S-04** stays blocked on F-03 only, as an ordinary prerequisite. It also gains a partial-ship path:
  Lidl-only real data if Biedronka vision underperforms.
- **F-03's plan** should include the §9 `price_entries` provenance columns and the §9 validation gate
  as explicit constraints.
- **Open Question 2** (data volume) answered: Lidl ~95 pages/week (text), Biedronka ~53 pages/week
  (images) for the main leaflet, 13 concurrent Biedronka leaflets if scope expands.
- **Stream C's note** — *"Blocked by the external dependency (vision-API vendor)"* — is obsolete.
