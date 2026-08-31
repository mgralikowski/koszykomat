---
change_id: testing-verdict-correctness
title: Test rollout phase 1 — verdict correctness on real leaflet shapes
status: implementing
created: 2026-08-30
updated: 2026-08-31
archived_at: null
---

## Notes

Open a change folder for rollout Phase 1 of context/foundation/test-plan.md: "Verdict correctness on real shapes".
Risks covered: #1 (a promo mechanic is mispriced on a leaflet shape the hand-seed never contained, and the verdict names the wrong chain — High/High), #6 (test fixtures encode a shape production cannot hold, so a green suite proves nothing — Medium/High).
Risk response intent:
- #1: prove that for a basket and a known real-leaflet offer shape, the computed total equals what a shopper actually pays at the till, forced overbuy included.
- #6: prove that a default factory row is a shape production can hold — chain, leaflet and price agree — and that a test fails if it is not.
After creating the folder, follow the downstream continuation rule.
