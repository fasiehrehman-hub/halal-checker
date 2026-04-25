# Halal Product Checker — Refactor Drop-In Kit

This folder contains **ready-to-paste** replacement files that upgrade your
Laravel app from a single-intent bot to a **multi-intent, grounded,
multilingual** assistant.

What you get, per your brief:

- Understands every user intent generically (English, Urdu, Arabic, mixed, broken).
- **Multi-intent:** "Is Oreo halal? What about Pepsi and Lay's?" → 3 sub-lookups, one answer.
- **No hallucination:** Gemini is told to use ONLY the products the DB returned.
- **"Show me more products like this"** → new `find_similar_by_category` tool.
- **"Give me chocolate products"** → category search with friendly fallback.
- Gentle errors — never "No match found".
- Origin normalization (USA/US/America → United States, etc.).

---

## File Map — WHERE TO PASTE

Copy each file from `_refactor/` → the **same relative path** in your project:

| Refactor file | Paste to (your project) | Change |
|---|---|---|
| `_refactor/app/Services/IntentResolverService.php`     | `app/Services/IntentResolverService.php`     | **Replace whole file** |
| `_refactor/app/Services/ProductAssistantService.php`   | `app/Services/ProductAssistantService.php`   | **Replace whole file** |
| `_refactor/app/Services/FinalAnswerService.php`        | `app/Services/FinalAnswerService.php`        | **NEW — create** |
| `_refactor/app/Services/ProductLookupService.php`      | `app/Services/ProductLookupService.php`      | **Replace whole file** (adds `findSimilarByCategory`) |
| `_refactor/app/Services/ResponseFormatterService.php`  | `app/Services/ResponseFormatterService.php`  | **Replace whole file** |
| `_refactor/app/Http/Controllers/ChatController.php`    | `app/Http/Controllers/ChatController.php`    | Replace (identical behavior, included for cleanliness) |

Files **NOT touched** (keep your existing versions — no changes needed):

- `app/Services/GeminiClient.php`
- `app/Services/GeminiImageResolverService.php`
- `app/Services/ProductReasoningService.php` *(kept as safety-net fallback only)*
- `app/Models/Product.php`
- `routes/web.php`
- `config/services.php`
- Blade views / JS / CSS

---

## How It Works (end-to-end)

```
User message (+ optional image)
      │
      ▼
┌─────────────────────────────────────────────┐
│ ChatController::send                        │
│   normalizes message, pulls session history │
└─────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────┐
│ ProductAssistantService::handle             │
│ 1. If image → GeminiImageResolverService    │
│ 2. IntentResolverService::resolve           │
│      → { intents: [ …sub-intents… ],        │
│          user_language, wants_similar… }    │
│ 3. For each sub-intent:                     │
│      ProductLookupService::executeTool(...) │
│      + search recovery (relax filters)      │
│      + image recovery (if image)            │
│      + similar-by-category fallback         │
│ 4. FinalAnswerService::buildReply           │
│      → Gemini grounded single reply         │
│    (falls back to ProductReasoningService)  │
│ 5. ResponseFormatterService::format         │
└─────────────────────────────────────────────┘
      │
      ▼
{ reply, data: { status, message, products, ingredient_explanation, meta:{ per_intent, is_multi_intent, user_language, ... } } }
```

---

## After Pasting — Do This ONCE

```bash
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
```

That's it. No DB migration, no new env variable, no composer changes. Laravel's
auto-discovery will inject `FinalAnswerService` automatically via type-hinted
constructor injection.

Required env vars (same as before):

```
GEMINI_API_KEY=...
GEMINI_MODEL=gemini-2.5-flash
GEMINI_VISION_MODEL=gemini-2.5-flash
GEMINI_TIMEOUT=45
```

---

## Smoke Tests (try these in the chat)

1. **Direct:** `Oreo`
2. **Origin:** `chocolate of USA`   → origin normalized to `united states`
3. **Recommendation:** `suggest drinks for a party`
4. **Filter:** `snacks without alcohol`
5. **Multi-intent:** `Is Oreo halal? What about Pepsi and Lay's?` → 3 blocks in one reply
6. **Broken English:** `me want usa chocolate no alcohol`
7. **Urdu:** `mujhe halal chocolate dikhao`  → reply in Urdu
8. **Image + "more like this":** upload product image, then send `show me more products like this`
9. **Barcode alone:** send `8964001234567`
10. **Ingredient:** `what is E471?`
11. **Follow-up:** `its ingredients` after a product was shown

---

## Key Design Decisions — Quick Reference

### Why `IntentResolverService` returns `intents` as an array
One message → many answers. The Gemini prompt gives explicit examples
("Is Oreo halal? What about Pepsi and Lay's?") so the model is consistent.
Heuristic fallback splits on `?`, `and`, `what about`, `also`, `plus`, `&`.

### Why a separate `FinalAnswerService`
Splitting *lookup* from *writing* kills two birds:
1. Grounds the reply on **only** the DB rows we retrieved → no invented products.
2. Picks the user's language → no hardcoded English.

### Why `find_similar_by_category` is a tool, not a flag
Clean separation. Either the user's message (or image + "more like this") wants
similarity, or it doesn't. When it does, we use the image's category/brand as
the seed — exactly what the brief asks for.

### Why `ProductReasoningService` is kept
It's a deterministic, non-LLM fallback. If the Gemini call for the final reply
ever fails (network hiccup, quota, etc.), the reasoning service still produces a
reasonable reply from templates. Zero regression risk.

### Origin normalization
Both IntentResolver (canonical `"united states"`) and ProductLookup (internal
`"usa"`) agree on the same canonical keys through `normalizeOrigin()` and
`expandOriginAliases()`.

---

## Rollback

Everything is pasted over whole files, so rollback = restore from git:

```bash
git checkout -- app/Services app/Http/Controllers
# then delete the new file:
rm app/Services/FinalAnswerService.php
```

---

## Notes

- `FinalAnswerService` uses `temperature: 0.4` to allow friendly phrasing but
  keep answers grounded.
- Gemini call retries are handled by your existing `GeminiClient`.
- Session history (last 12 messages) is already captured by `ChatController`
  and forwarded to `IntentResolverService` for follow-up detection.
- `data.meta.per_intent` in the response tells the frontend how each sub-intent
  resolved, in case you later want to show "Oreo ✓ / Pepsi ✗ / Lay's ✓" chips.
