# Login page art

Drop brand images here and the login page will pick them up automatically.

## Slots

- `hero.jpg` · `hero.png` · `hero.webp` — full-bleed background on the left panel
  (first match wins)
- `logo.png` · `logo.svg` — brand logo rendered in the top-left of the hero
  (falls back to the "RareFolio" wordmark if absent)
- `tiles/*.{jpg,jpeg,png,webp}` — art tile mosaic under the wordmark.
  Drop 6 images for the classic 3×2 grid. More than 6 are ignored.

Nothing needs to be added for the gate to work — without any images you get
an elegant gold-tinted gradient fallback.
