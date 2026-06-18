# Menuosaur

Menuosaur is a WordPress plugin for building frontend menu shortcodes from a cached Square catalog.

It reads Square regular categories, items, item variations, prices, descriptions, dietary preferences, allergens, custom attributes, and selected menu item images. WordPress admins can build named menu shortcodes, choose regular categories, search for individual products, drag selected items into the desired order, and control which parts of each item render on the page.

## Features

- Square Catalog API sync using WordPress HTTP functions.
- Manual sync plus hourly WordPress cron refresh.
- Local catalog cache for categories, items, variations, prices, descriptions, dietary preferences, allergens, custom attributes, and selected menu image objects.
- Queued WordPress Media Library image caching for Square images.
- Per-menu image source choices: original Square URL, WordPress thumbnail, WordPress medium, or WordPress large.
- Per-menu image aspect-ratio choices that crop Square images into stable frames, including custom ratios.
- Multi-category menu shortcodes.
- Regular-category browsing plus direct product search for adding individual items.
- Drag-and-drop selected item ordering in the admin builder.
- Optional custom heading text for any menu.
- Optional text below the menu heading, with total-cost placeholders and a percentage discount for bundles.
- Optional per-item quantities, rendered before item names and included in total-cost placeholders.
- Copy buttons for saved shortcode actions.
- Symbol key shortcode for dietary/allergen symbols used by menus on the current page.
- Global option to display cheaper variations first.
- Global option to remove currency symbols from rendered prices.
- Configurable WordPress admin sidebar menu label.
- Per-menu display controls for item name, dietary/allergen symbols, image, description, prices, and selected custom attributes.
- Theme-neutral frontend markup for easy styling.

## Installation

1. Copy this plugin folder into `wp-content/plugins/menuosaur`.
2. Activate **Menuosaur** in WordPress admin.
3. Go to **Menuosaur > Settings**.
4. Add your Square access token, environment, API version, and optional location ID.
5. Use **Test Square Connection** to verify the token.
6. Go to **Menuosaur > Catalog Sync** and run **Sync Now**.

## Creating A Menu

1. Go to **Menuosaur > Menus**.
2. Create a shortcode and give it a name.
3. Select one or more Square categories.
4. Choose whether to show a heading. Add custom heading text if you want to override the Square category name.
5. Add optional text below the heading. Use `{total}` or `{menu_total}` to show the selected-variation total after discount, and `{subtotal}` or `{menu_subtotal}` to show the pre-discount total.
6. Select the items to include.
7. Select the variations for each item.
8. Configure the displayed content, item quantities, and image aspect ratio.
9. Save the shortcode.

Use the shortcode on a page or post:

```text
[menuosaur id="your-shortcode-slug"]
```

To show a key for the dietary/allergen symbols used by Menuosaur menus on the same page:

```text
[menuosaur_symbol_key]
```

Hide the default title:

```text
[menuosaur_symbol_key show_title="0"]
```

You can also target specific menus explicitly:

```text
[menuosaur_symbol_key ids="wine-red,wine-white-n-rose" title="Dietary Key"]
```

## Frontend Markup

Menuosaur keeps the frontend output deliberately light so the theme can handle presentation.

Common classes:

```html
<div class="menuosaur-menu" data-menuosaur-item-count="4">
  <h4 class="menuosaur-category-heading">Category</h4>
  <div class="menuosaur-menu-note">
    <p>September Release<br>Expected Cost: $214.20</p>
  </div>
  <div class="menuosaur-item">
    <div class="menuosaur-item-image">...</div>
    <p class="menuosaur-item-name"><span class="menuosaur-item-quantity">4×</span> Item name <span class="menuosaur-item-symbols">...</span></p>
    <p class="menuosaur-item-description">Description</p>
    <p class="menuosaur-item-attributes">Attributes</p>
    <p class="menuosaur-variation-prices">Prices</p>
  </div>
  <div class="menuosaur-symbol-key">...</div>
</div>
```

The `data-menuosaur-item-count` attribute reflects the number of rendered menu items, so themes can tune grid columns for menus with 4, 6, or other specific item counts.

## Example Centered Menu CSS

```css
.menuosaur-menu {
  max-width: 980px;
  margin: 0 auto;
  text-align: center;
}

.menuosaur-category-heading {
  display: grid;
  grid-template-columns: minmax(48px, 1fr) auto minmax(48px, 1fr);
  align-items: center;
  gap: 2rem;
  margin: 4rem 0 2.25rem;
  line-height: 1.1;
}

.menuosaur-category-heading:first-child {
  margin-top: 0;
}

.menuosaur-category-heading::before,
.menuosaur-category-heading::after {
  content: "";
  border-top: 1px solid currentColor;
  opacity: 0.75;
}

.menuosaur-item {
  max-width: 820px;
  margin: 0 auto 2.6rem;
}

.menuosaur-item-name,
.menuosaur-item-description,
.menuosaur-item-attributes,
.menuosaur-variation-prices {
  margin: 0;
  line-height: 1.25;
}

.menuosaur-item-name {
  font-size: clamp(1.4rem, 2.5vw, 2rem);
}

.menuosaur-item-description,
.menuosaur-item-attributes,
.menuosaur-variation-prices {
  font-size: clamp(1.2rem, 2vw, 1.75rem);
}

.menuosaur-price-separator,
.menuosaur-attribute-separator {
  display: inline-block;
  margin: 0 0.25em;
}

@media (max-width: 640px) {
  .menuosaur-category-heading {
    display: block;
    margin: 3rem 0 1.75rem;
  }

  .menuosaur-category-heading::before,
  .menuosaur-category-heading::after {
    display: none;
  }

  .menuosaur-item {
    margin-bottom: 2rem;
  }
}
```

## Notes

- Menuosaur reads Square catalog data; it does not create or update Square items.
- Rendered shortcodes use cached Square object IDs, so item names and prices update after the next sync.
- Square image objects and WordPress image sizes are fetched only for menus configured to display images. A menu may briefly fall back to the original Square image URL until the cached attachment exists.
- If a selected item or variation is deleted or archived in Square, the public shortcode hides it and the admin builder shows a cleanup warning.
- Square access tokens are stored in WordPress options and only sent server-side.
