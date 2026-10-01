<?php
declare(strict_types=1);

/* Brand helpers: the official NEDCo logo (used unmodified) plus the "asset tag + bolt" system mark
   that identifies this application. Presentation only; no data or access logic lives here. */

/** Product name without the organisation prefix, e.g. "IT Asset Inventory". */
function brand_product(): string
{
    $n = trim((string)preg_replace('/^NEDCo\s+/i', '', (string)cfg('app_name')));
    return $n !== '' ? $n : (string)cfg('app_name');
}

/** Official logo, shown at its natural proportions (only blank margin around the artwork was trimmed). */
function brand_logo(string $class = ''): string
{
    return '<img class="nedco-logo' . ($class !== '' ? ' ' . e($class) : '') . '" src="assets/nedco-logo.png" width="426" height="90" alt="NEDCo">';
}

/** System mark: an asset tag (folded corner) carrying a lightning bolt with a data node at its tip.
    Tag = inventory/asset, bolt = electricity, node + trace = IT/digital. Colours come from the theme tokens (.m-a tag, .m-b bolt). */
function brand_mark(): string
{
    return '<svg class="sysmark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path class="m-a" d="M5 2h9.6L21 8.4V20a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>'
        . '<path class="m-b" d="M13.6 5.6 7.4 13.2h3.9l-1.3 5.2 6.4-8h-3.9l1.1-4.8z"/>'
        . '<circle class="m-c" cx="9.9" cy="19.3" r="1.3"/></svg>';
}

/** Full product name for headlines, broken after the organisation name: "NEDCo" / "IT Asset Inventory". */
function brand_title_html(): string
{
    $full = (string)cfg('app_name');
    return preg_match('/^NEDCo\s+/i', $full) ? 'NEDCo<br>' . e(brand_product()) : e($full);
}

/** Favicon and browser-chrome colour, for every page head. */
function brand_head(): string
{
    return '<link rel="icon" type="image/svg+xml" href="assets/brand/favicon.svg">'
        . '<link rel="alternate icon" type="image/png" href="assets/brand/favicon-32.png">'
        . '<link rel="apple-touch-icon" href="assets/brand/apple-touch-icon.png">'
        . '<meta name="theme-color" content="#22272C">';
}
