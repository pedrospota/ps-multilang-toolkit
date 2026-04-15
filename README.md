# PS Multilang Toolkit

Single-file WordPress plugin that injects a **header + footer + language switcher** on any page — fully configurable from `Settings → PS Multilang`. Built to work with **Polylang Free** (also patches its duplicate-slug bug).

Use it on any WordPress install where the AI/generator produces raw HTML page bodies and you need consistent site chrome wrapped around them without touching the theme.

---

## What it gives you

- **Sticky header** with brand (logo or auto-gradient icon), native WP nav menu, language switcher with flag emojis, and a configurable CTA button.
- **Footer** with tagline, two columns of links (from native WP menus), copyright row, optional contact email.
- **Language switcher** — reads directly from Polylang (`pll_the_languages`). Ships with flags for `en / es / es-mx / fr / de / it / pt / ca / ja / zh`.
- **Polylang Free duplicate-slug fix** — lets you have `/about/` and `/es/about/` both using slug `about` (Polylang Pro normally required for this).
- **Per-language menus** — assign one menu per language per location in `Appearance → Menus`; the plugin picks the right one automatically (`{location}___{lang}` format).
- **Trigger control** — either apply to *all* pages, or only to pages using a specific template slug (default `html`). Useful when you only want the chrome on AI-generated landing pages.
- **Hide theme chrome** — optional: hides `.site-header`, `.site-footer`, `.entry-header`, etc. so your theme doesn't fight the plugin layout.

Zero hardcoded links. Zero hardcoded strings beyond English defaults (every label is a Polylang-registered string you can translate in `Idiomas → Traducciones de cadenas`).

---

## Install

1. Download `ps-multilang-toolkit.php`.
2. Upload to `wp-content/plugins/ps-multilang-toolkit/ps-multilang-toolkit.php`.
3. Activate from `Plugins → Installed Plugins`.
4. Go to `Settings → PS Multilang` and configure colors / brand / CTA / tagline.
5. Go to `Appearance → Menus` and assign a menu to:
   - `PS Multilang — Header Menu`
   - `PS Multilang — Footer (Column 1)`
   - `PS Multilang — Footer (Column 2)`
6. If Polylang is active, repeat step 5 once per language (the plugin shows each location split as `{location}___{lang}`).
7. Translate the strings in `Idiomas → Traducciones de cadenas` (tagline, CTA label, column titles, copyright).

---

## Config reference (`Settings → PS Multilang`)

| Option | What it does |
|---|---|
| `enabled` | Master kill-switch |
| `hide_theme_chrome` | Hide the active theme's header/footer/article header via CSS |
| `apply_to_all_pages` | If on, inject on every page. If off, only pages using the `template_trigger` template |
| `template_trigger` | Page template slug that triggers injection (default `html`) |
| `enable_polylang_fix` | Turn the duplicate-slug patch on/off |
| `brand_name` / `logo_url` | Header + footer branding |
| `bg_color` / `text_color` / `accent_color` / `accent_color_2` / `muted_color` | Colors |
| `cta_label` / `cta_url` | Header CTA button (empty URL = hidden) |
| `tagline` / `product_col` / `legal_col` / `rights_label` / `contact_email` | Footer strings |

---

## Polylang duplicate-slug fix

Polylang **Pro** lets different languages share a slug (e.g. `/about/` in EN and `/es/about/` in ES both with slug `about`). Polylang **Free** doesn't — it auto-appends `-2`, `-es`, etc. on save.

This plugin hooks into `wp_unique_post_slug` to return the original slug when the only collision is in a different language, and hooks into `request` to route the URL to the correct post when WordPress can't disambiguate by itself.

Toggle with the `enable_polylang_fix` checkbox.

---

## Requirements

- WordPress 5.2+
- PHP 7.4+
- (Optional) Polylang Free or Pro for multilingual features

---

## License

GPL-2.0-or-later.
