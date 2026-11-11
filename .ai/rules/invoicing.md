---
paths:
  - 'app/Support/Invoicing/**'
---

# Invoicing

## A provider only receives values from its own closed lists
The app stores what the user wrote (a unit such as `un`, `cx`). Each driver translates to the provider vocabulary in its `Mapper`, and back when importing: `Mapper::unit()` sends `unit`, `hour`, ... and `other` for anything unknown. Never pass free text where the provider takes a fixed list; add the translation and a test with the refused value.
