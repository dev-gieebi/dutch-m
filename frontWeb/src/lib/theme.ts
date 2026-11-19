import { DEFAULT_SETTINGS, ThemeSettings } from '@/shared';

const STORAGE_KEY = 'amoria-theme';

type Rgb = [number, number, number];

function hexToRgb(hex: string): Rgb {
  const n = parseInt(hex.replace('#', ''), 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function rgbToHex([r, g, b]: Rgb): string {
  return `#${[r, g, b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')}`;
}

/** Mélange linéaire : t = 0 → a, t = 1 → b. */
function mix(a: Rgb, b: Rgb, t: number): Rgb {
  return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t];
}

function rgbToHsl([r, g, b]: Rgb): Rgb {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
  if (max === min) return [0, 0, l * 100];
  const d = max - min;
  const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
  const h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
  return [h * 60, s * 100, l * 100];
}

function hslToRgb([h, s, l]: Rgb): Rgb {
  s /= 100; l /= 100;
  const k = (n: number) => (n + h / 30) % 12;
  const a = s * Math.min(l, 1 - l);
  const f = (n: number) => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
  return [f(0) * 255, f(8) * 255, f(4) * 255];
}

/** Décale la luminosité (et la saturation) d'une couleur, en points de pourcentage. */
function shade(c: Rgb, dl: number, ds = 0): Rgb {
  const [h, s, l] = rgbToHsl(c);
  return hslToRgb([h, Math.max(0, Math.min(100, s + ds)), Math.max(0, Math.min(100, l + dl))]);
}

const triplet = (c: Rgb) => c.map((v) => Math.round(v)).join(' ');

/** Palette complète (variables CSS de index.css) dérivée des quatre couleurs choisies. */
export function themeVariables(theme: ThemeSettings): Record<string, string> {
  const primary = hexToRgb(theme.primary);
  const secondary = hexToRgb(theme.secondary);
  const bg = hexToRgb(theme.background);
  const text = hexToRgb(theme.text);
  const light = shade(primary, 22, 15);
  const dark = shade(primary, -16, 10);
  return {
    '--cream': triplet(bg),
    '--sand': triplet(mix(bg, primary, 0.15)),
    '--gold-light': triplet(light),
    '--gold': triplet(primary),
    '--gold-dark': triplet(dark),
    '--blush': triplet(mix(bg, secondary, 0.3)),
    '--rose': triplet(secondary),
    '--rose-dark': triplet(shade(secondary, -20)),
    '--ink': triplet(text),
    '--ink-soft': triplet(mix(text, bg, 0.35)),
    '--gold-gradient': `linear-gradient(135deg, ${rgbToHex(light)} 0%, ${theme.primary} 50%, ${rgbToHex(dark)} 100%)`,
  };
}

const isDefault = (t: ThemeSettings) =>
  (Object.keys(DEFAULT_SETTINGS.theme) as (keyof ThemeSettings)[]).every((k) => t[k].toLowerCase() === DEFAULT_SETTINGS.theme[k].toLowerCase());

let applied: string[] = [];

/** Applique le thème à toute l'interface (boutique et back-office) et le mémorise pour le prochain chargement. */
export function applyTheme(theme: ThemeSettings | undefined | null, persist = true): void {
  const root = document.documentElement;
  applied.forEach((v) => root.style.removeProperty(v));
  applied = [];
  if (theme && !isDefault(theme)) {
    for (const [k, v] of Object.entries(themeVariables(theme))) root.style.setProperty(k, v);
    applied = Object.keys(themeVariables(theme));
  }
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme?.background ?? DEFAULT_SETTINGS.theme.background);
  if (!persist) return;
  try {
    if (theme && !isDefault(theme)) localStorage.setItem(STORAGE_KEY, JSON.stringify(theme));
    else localStorage.removeItem(STORAGE_KEY);
  } catch { /* stockage indisponible */ }
}

/** Dernier thème connu, pour éviter un flash des couleurs par défaut au chargement. */
export function storedTheme(): ThemeSettings | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as ThemeSettings) : null;
  } catch {
    return null;
  }
}

/** Couleur courante d'une variable du thème, utilisable là où le CSS n'arrive pas (graphiques SVG). */
export function themeColor(name: string): string {
  const v = getComputedStyle(document.documentElement).getPropertyValue(`--${name}`).trim();
  return v ? `rgb(${v.split(/\s+/).join(',')})` : '#000';
}
