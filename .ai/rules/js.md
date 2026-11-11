---
paths:
  - 'resources/js/**'
---

# Js

## Browser previews of money mirror one PHP class, in integer cents
Amounts worked out in the browser use BigInt cents through the helpers in `resources/js/finance-preview.js` (`moneyCents`, `scaledInteger`, `moneyFormatter`), never floats. Each preview mirrors one PHP class, which stays the truth when saving. Both sides are held to the same cases file in `tests/JavaScript/*.cases.json`, read by a node test and by a Pest test that also runs the node test. A new entry point goes in `vite.config.js` and needs `npm run build` before the tests.
