<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Site theme (Settings > General > Appearance)
|--------------------------------------------------------------------------
| Stored as plain system_settings rows. Every value is validated against an
| allow-list here, both when saving and when rendering, so nothing typed into
| the settings form is ever echoed into CSS verbatim.
*/

const IMS_THEME_DEFAULTS = [
    'theme_primary_color' => '#ea580c',
    'theme_sidebar_color' => '#1e2530', // --dark-color, the flat sidebar background in style.css
    'theme_font_family' => 'default',
    'theme_font_custom' => '',
    'theme_font_scale' => '100',
];

/**
 * Font choices: key => [label, CSS font stack]. Only locally available fonts
 * (no new external requests). 'custom' takes its stack from theme_font_custom.
 */
function ims_theme_font_families(): array
{
    return [
        'default' => ['DM Sans (default)', "'DM Sans', system-ui, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"],
        'system' => ['System UI', 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'],
        'arial' => ['Arial / Helvetica', 'Arial, Helvetica, sans-serif'],
        'verdana' => ['Verdana', 'Verdana, Geneva, sans-serif'],
        'tahoma' => ['Tahoma', 'Tahoma, Verdana, sans-serif'],
        'trebuchet' => ['Trebuchet MS', '"Trebuchet MS", Helvetica, sans-serif'],
        'georgia' => ['Georgia (serif)', 'Georgia, "Times New Roman", serif'],
        'custom' => ['Other (specify)', null],
    ];
}

const IMS_THEME_GENERIC_FONTS = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'];

/**
 * Canonicalise an admin-typed font list ("Open Sans, Arial") to "Open Sans, Arial",
 * or null when invalid. Names are restricted to letters, digits, spaces and
 * hyphens so the value can never break out of the CSS declaration.
 */
function ims_theme_custom_font(string $value): ?string
{
    $names = [];
    foreach (explode(',', $value) as $name) {
        $name = preg_replace('/\s+/', ' ', trim(trim($name), "\"' "));
        if ($name === '') continue;
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9 \-]{0,49}\z/', $name)) return null;
        $names[] = $name;
    }
    return $names === [] || count($names) > 4 ? null : implode(', ', $names);
}

/** CSS font stack for a canonical custom font list, with a sans-serif fallback. */
function ims_theme_custom_font_stack(string $fonts): string
{
    $stack = [];
    $hasGeneric = false;
    foreach (explode(', ', $fonts) as $name) {
        $generic = in_array(strtolower($name), IMS_THEME_GENERIC_FONTS, true);
        $hasGeneric = $hasGeneric || $generic;
        $stack[] = $generic ? strtolower($name) : '"' . $name . '"';
    }
    if (!$hasGeneric) $stack[] = 'sans-serif';
    return implode(', ', $stack);
}

/** Interface text size choices: percent => label. */
function ims_theme_font_scales(): array
{
    return ['90' => 'Small (90%)', '100' => 'Default (100%)', '110' => 'Large (110%)', '120' => 'Extra large (120%)'];
}

/** Normalise a #rgb/#rrggbb value to lowercase #rrggbb, or null when invalid. */
function ims_theme_hex(string $value): ?string
{
    $value = strtolower(trim($value));
    if (preg_match('/\A#([0-9a-f])([0-9a-f])([0-9a-f])\z/', $value, $m)) {
        return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    }
    return preg_match('/\A#[0-9a-f]{6}\z/', $value) ? $value : null;
}

/** WCAG contrast ratio of a #rrggbb colour against white. */
function ims_theme_contrast_with_white(string $hex): float
{
    $channel = static function (int $c): float {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    $luminance = 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    return 1.05 / ($luminance + 0.05);
}

/** Mix a #rrggbb colour toward white (positive weight) or black (negative weight). */
function ims_theme_mix(string $hex, float $weight): string
{
    $target = $weight >= 0 ? 255 : 0;
    $weight = abs($weight);
    return '#' . implode('', array_map(
        static fn (int $c): string => sprintf('%02x', (int)round($c + ($target - $c) * $weight)),
        sscanf($hex, '#%02x%02x%02x')
    ));
}

/**
 * Validate submitted theme values. Throws InvalidArgumentException with a
 * user-facing message; returns the canonical values to store.
 */
function ims_theme_validate(array $input): array
{
    $theme = [];
    foreach (['theme_primary_color' => 'Primary colour', 'theme_sidebar_color' => 'Sidebar colour'] as $key => $label) {
        $hex = ims_theme_hex((string)($input[$key] ?? IMS_THEME_DEFAULTS[$key]));
        if ($hex === null) {
            throw new InvalidArgumentException($label . ' must be a hex colour such as #ea580c.');
        }
        // White text sits on both colours (buttons, sidebar links). 3:1 is the
        // floor the default orange (#ea580c, ~3.6:1) already meets.
        if (ims_theme_contrast_with_white($hex) < 3.0) {
            throw new InvalidArgumentException($label . ' is too light for white text; choose a darker colour.');
        }
        $theme[$key] = $hex;
    }

    $font = (string)($input['theme_font_family'] ?? 'default');
    if (!array_key_exists($font, ims_theme_font_families())) {
        throw new InvalidArgumentException('Choose a font family from the list.');
    }
    $theme['theme_font_family'] = $font;

    $customInput = trim((string)($input['theme_font_custom'] ?? ''));
    $custom = $customInput === '' ? '' : ims_theme_custom_font($customInput);
    if ($custom === null || ($font === 'custom' && $custom === '')) {
        throw new InvalidArgumentException('Custom font must be up to 4 font names separated by commas, using only letters, numbers, spaces and hyphens (e.g. "Open Sans, Arial").');
    }
    $theme['theme_font_custom'] = $custom;

    $scale = (string)($input['theme_font_scale'] ?? '100');
    if (!array_key_exists($scale, ims_theme_font_scales())) {
        throw new InvalidArgumentException('Choose a text size from the list.');
    }
    $theme['theme_font_scale'] = $scale;

    return $theme;
}

/** Stored values (possibly invalid or missing) => safe values, falling back to defaults per key. */
function ims_theme_resolve(array $stored): array
{
    $theme = IMS_THEME_DEFAULTS;
    foreach (['theme_primary_color', 'theme_sidebar_color'] as $key) {
        $hex = ims_theme_hex((string)($stored[$key] ?? ''));
        if ($hex !== null) $theme[$key] = $hex;
    }
    $custom = ims_theme_custom_font((string)($stored['theme_font_custom'] ?? ''));
    if ($custom !== null) $theme['theme_font_custom'] = $custom;
    $font = (string)($stored['theme_font_family'] ?? '');
    if (array_key_exists($font, ims_theme_font_families()) && ($font !== 'custom' || $custom !== null)) {
        $theme['theme_font_family'] = $font;
    }
    if (array_key_exists((string)($stored['theme_font_scale'] ?? ''), ims_theme_font_scales())) {
        $theme['theme_font_scale'] = (string)$stored['theme_font_scale'];
    }
    return $theme;
}

/** The <style> block that applies the theme, or '' when everything is at its default. */
function ims_theme_style_tag(array $theme): string
{
    $theme = ims_theme_resolve($theme);
    // html:root / #appSidebar outrank the plain :root and .sidebar rules in
    // style.css, reports.css and per-page stylesheets loaded after this block.
    $css = [];

    if ($theme['theme_primary_color'] !== IMS_THEME_DEFAULTS['theme_primary_color']) {
        $p = $theme['theme_primary_color'];
        $css[] = 'html:root{--brand-50:' . ims_theme_mix($p, 0.93) . ';--brand-100:' . ims_theme_mix($p, 0.85)
            . ';--brand-200:' . ims_theme_mix($p, 0.68) . ';--brand-400:' . ims_theme_mix($p, 0.35)
            . ';--brand-500:' . ims_theme_mix($p, 0.15) . ';--brand-600:' . $p
            . ';--brand-700:' . ims_theme_mix($p, -0.18) . ';}';
    }
    if ($theme['theme_sidebar_color'] !== IMS_THEME_DEFAULTS['theme_sidebar_color']) {
        $s = $theme['theme_sidebar_color'];
        $css[] = '#appSidebar.sidebar{background:' . $s . ';}';
    }
    if ($theme['theme_font_family'] !== 'default') {
        $stack = $theme['theme_font_family'] === 'custom'
            ? ims_theme_custom_font_stack($theme['theme_font_custom'])
            : ims_theme_font_families()[$theme['theme_font_family']][1];
        $css[] = 'html:root{--font-body:' . $stack . ';}body,button,input,select,textarea{font-family:' . $stack . ';}';
    }
    if ($theme['theme_font_scale'] !== '100') {
        // The stylesheets size text in px, so scale the page rather than the root font size.
        // A visitor's own accessibility-widget zoom multiplies with this instead of replacing it.
        $scale = (int)$theme['theme_font_scale'] / 100;
        $css[] = 'html:root{--ims-font-scale:' . $scale . ';}body{zoom:var(--ims-font-scale);}'
            . ':root.a11y-zoom body{zoom:calc(var(--ims-font-scale) * var(--a11y-zoom, 1));}'
            // Keep the accessibility widget itself at its normal size, as accessibility.css does.
            . ':root .a11y-ui,:root .a11y-overlay{zoom:calc(1 / var(--ims-font-scale));}'
            . ':root.a11y-zoom .a11y-ui,:root.a11y-zoom .a11y-overlay{zoom:calc(1 / (var(--ims-font-scale) * var(--a11y-zoom, 1)));}';
    }

    return $css === [] ? '' : "<style id=\"ims-site-theme\">" . implode('', $css) . "</style>\n";
}
