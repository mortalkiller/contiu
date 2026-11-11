---
paths:
  - 'app/Filament/**'
---

# Filament

## Forms react in the browser, not through Livewire
A field is never `live()` just to show, hide or recalculate something from other fields: every `live()` change is a request and a re-render. Use `visibleJs()` / `hiddenJs()` for visibility, `afterStateUpdatedJs()` to fill other fields, and a `JsComponent` (Filament 5.11+) with a renderer in `resources/js/filament/schemas/components/` for previews and totals. The renderer reads the form with `utilities.$get()` inside `Alpine.effect()`, so it redraws as the user types with no request. Keep `live()` only when the server has to answer (options that depend on another field, settings saved on change). Reference: `FiscalDocumentDraftForm` and `fiscal-document-totals.js`.

## A field hidden in the browser still exists on the server
`visibleJs()` / `hiddenJs()` only hide: the field is still validated and saved. Pair them with `->dehydrated(fn (Get $get) => <the same condition>)->validatedWhenNotDehydrated(false)`, and never trust the hidden value when building the data that is saved.

## Data for the browser travels encoded, never as raw text
Never interpolate user text into a `*Js()` expression or a JS string. Pass data through `rendererConfiguration()` or `Js::from()`. Values in `extraAttributes()` are not HTML-escaped by Filament (a double quote ends the attribute), so encode them with `rawurlencode(json_encode(...))` and read them with `decodeURIComponent`.
