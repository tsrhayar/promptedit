# PromptEdit Image Studio: brand guide

A small, practical charte graphique for this app. Every value below exists as code:
CSS variables in `resources/css/app.css`, Tailwind names in `tailwind.config.js`.
**Rule #1: never hard-code a color in a page. Use the token (`bg-primary`, `text-success`, …).**

## 1. Name and logo

- **Name:** PromptEdit, product label *Image studio*.
- **Tagline:** *AI images, priced to the cent.*
- **Mark:** a rounded violet square with a video frame (4 corners) around a spark.
  The frame says "video editing tools" (PromptEdit's business), the spark says "AI generation".
  - Component: `resources/js/components/AppLogoIcon.vue` (uses `fill-primary` / `stroke-primary-foreground`, so it follows dark mode).
  - Favicon: `public/favicon.svg`.
  - Minimum size 16 px. Keep at least ¼ of its width as empty space around it. Do not recolor, stretch or add effects.

## 2. Colors

Stored as HSL channels (`--primary: 256.8 80.6% 57.5%`) so Tailwind opacity works: `bg-primary/10`.
All text pairs pass WCAG AA (4.5:1) in both themes.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `primary` | `#6C3BEA` (on white 6.1:1) | `#A78BFA` | Main action (one per screen), selected state, focus ring, links |
| `primary-foreground` | `#FFFFFF` | `#1A1033` | Text on primary |
| `background` | `#FFFFFF` | `#0F0E15` | Page |
| `card` | `#FFFFFF` | `#16141F` | Cards, panels |
| `foreground` | `#16141F` | `#F3F2F8` | Main text |
| `muted` | `#F5F4F8` | `#1D1B28` | Subtle backgrounds (image placeholders, table headers) |
| `muted-foreground` | `#615D73` (6.3:1) | `#A29EB6` | Secondary text, hints |
| `border` / `input` | `#E6E4EC` / `#DAD7E3` | `#2A2738` / `#34304A` | Lines, field borders |
| `secondary` / `accent` | `#F0ECFE` | `#241E3D` | Hover, soft violet surfaces |
| `success` | `#047857` | `#34D399` | Done, money saved/refunded, profit |
| `warning` | `#B45309` | `#FBBF24` | Attention, configuration problems |
| `destructive` | `#DC2626` | `#F87171` | Errors, failed generations, mismatches |
| `info` | `#2563EB` | `#60A5FA` | In progress, live updates |

Semantic colors are used **soft**: `bg-success/10 text-success ring-success/25`. Solid fills are only for buttons.

Dark mode: supported by the starter kit (`.dark` class, Settings → Appearance). Every token has a dark value.
The only fixed colors are on the always-dark brand panel of the login/register pages.

## 3. Typography

- **Font:** [Inter](https://fonts.bunny.net/family/inter) 400 / 500 / 600 / 700, loaded from Bunny Fonts in `resources/views/app.blade.php`. Fallback: system UI font.
- **Scale:** page title `text-2xl font-semibold tracking-tight` · section title `text-sm font-medium` (or `font-semibold`) · body `text-sm` · hints `text-xs text-muted-foreground`.
- **Money and numbers:** always `tabular-nums` (the `.money` class) so digits line up.
- **Code / test tags:** `font-mono`.

## 4. Spacing, radius, shadow

- **Spacing:** Tailwind 4 px grid. Cards `p-4` (mobile) / `p-5` (desktop); gap between cards `gap-4`–`gap-6`; page padding `p-4 md:p-6`; max width `max-w-6xl`/`max-w-7xl`.
- **Radius:** `--radius: 0.625rem` (10 px) → `rounded-lg` (fields, buttons, segments), `rounded-xl` (14 px, cards), `rounded-full` (badges, pills).
- **Shadows:** `shadow-card` (cards, very subtle) and `shadow-elevated` (the one important floating panel: the price summary). Nothing heavier.
- **Motion:** short color transitions only; `animate-spin` for "working", `motion-safe:animate-shimmer` for image placeholders (disabled when the user prefers reduced motion).

## 5. Components

**Buttons** (`components/ui/button`)
- `default` (violet) = the main action of the screen, one only (e.g. *Generate image*).
- `outline` = secondary (Buy $5, Try again, Add reference image). `ghost` = icon buttons and presets.
- Loading: spinner icon + verb in -ing (*Submitting…*), button disabled.
- Disabled: always say **why** right under the button (e.g. *Describe the image you want to continue.*).

**Inputs**
- Every field has a visible `<Label>` (or `sr-only` when the context is obvious) and a `focus-visible` ring in `ring` color.
- Errors: red border + message under the field (`InputError`), checked instantly when possible (URLs).
- Choices with few options use the **segmented control** (`components/SegmentedControl.vue`): real radio buttons, keyboard arrows work.

**Cards:** `rounded-xl border bg-card shadow-card`, one topic per card.

**Status badges** (`components/StatusBadge.vue`, meanings in `lib/generationStatus.ts`)

| Status | Label | Tone | Icon |
| --- | --- | --- | --- |
| pending | Sending | neutral | Send |
| queued | Queued | neutral | Clock |
| processing | Generating | info | Spinner (animated) |
| succeeded | Done | success | Check |
| failed | Failed | danger | X |
| rejected | Not sent | danger | Ban |

Color is never the only signal: each badge has an icon and a word.

**Money display**
- Amounts come from the server as exact decimal strings (or integer nanodollars) and are **only formatted** in the browser (`formatUsd`, `formatNano`), never recalculated.
- Summaries: 4 decimals (`$0.0707`). Exact value (9 decimals) in a tooltip or `title`, and in full in the admin report.
- An estimate is always a range `$min – $max`, with *Never more than $max*.
- Say what is **known now** (image size, quality, reference images) vs **known when it's done** (prompt and reasoning tokens).
- Failed = `$0.00` in green + *Nothing was charged.*
- Show the effect on the balance: *Your credits → Reserved while generating → Available after*.

## 6. Voice

Short, plain English, second person. Say what happened, what it cost, and what to do next.
Good: *The image provider is busy. Nothing was charged, please try again in a moment.*
Avoid jargon in user screens (keep "tokens", "webhook" for details and the admin report).
