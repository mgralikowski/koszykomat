---
change_id: save-and-revisit-basket
title: Save and revisit a basket
status: implementing
created: 2026-08-30
updated: 2026-08-30
archived_at: null
---

## Notes

Roadmap S-03 (PRD FR-005 + NFR basket privacy). A logged-in user names and saves the basket
they built in S-02, sees their saved baskets on a list page, and loads one back to re-compare it
against whatever price data is current.

- S-02 deliberately left the basket in the session and deferred the schema here — this change
  owns `saved_baskets` / `saved_basket_items`.
- The load-bearing requirement is ownership: a saved basket must never be readable, loadable or
  deletable by another account. Scoping is enforced at the query, and a foreign id is a 404.
- Re-comparison is free: `BasketComparator::compare()` already resolves prices through
  `PriceEntry::usableOn()` at call time, so a saved basket re-prices itself with no snapshot.
