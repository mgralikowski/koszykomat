---
change_id: basket-builder-comparison-report
title: Basket builder + full comparison report
status: archived
created: 2026-08-29
updated: 2026-08-29
archived_at: 2026-08-29T20:04:51Z
---

## Notes

<!-- Free-form notes for this change: links, ad-hoc context, decisions that don't belong in research/frame/plan. -->

- Roadmap item **S-02** (`context/foundation/roadmap.md`). Prerequisites S-01 and F-02 are both implemented; neither is archived yet.
- Reuses the S-01 pricing engine unchanged — no file under `app/Pricing/` is modified by this change.
- The basket lives in the session on purpose: the `baskets` schema is **S-03's** to design.
