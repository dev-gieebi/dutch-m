/** Formate un montant entier en FCFA : 12500 → « 12 500 FCFA ». */
export function formatFCFA(amount: number | null | undefined): string {
  const n = Math.round(Number(amount ?? 0));
  const sign = n < 0 ? '-' : '';
  return `${sign}${String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} FCFA`;
}

export const GABON_PHONE_REGEX = /^\+241\d{7,9}$/;

/**
 * Normalise un numéro gabonais au format international +241XXXXXXXX.
 * Accepte : « 077 12 34 56 », « 241 77123456 », « 00241 ... », « +241 ... ».
 * Renvoie null si le numéro est invalide.
 */
export function normalizeGabonPhone(input: string | null | undefined): string | null {
  if (!input) return null;
  let digits = input.replace(/[\s().-]/g, '');
  if (digits.startsWith('00241')) digits = `+241${digits.slice(5)}`;
  else if (digits.startsWith('241') && digits.length >= 10) digits = `+${digits}`;
  else if (!digits.startsWith('+')) digits = `+241${digits}`;
  return GABON_PHONE_REGEX.test(digits) ? digits : null;
}

/** Affiche un numéro +241 de manière lisible : +241 77 12 34 56. */
export function formatPhone(phone: string | null | undefined): string {
  if (!phone) return '';
  const m = phone.match(/^\+241(\d+)$/);
  if (!m) return phone;
  return `+241 ${m[1].replace(/(\d{2})(?=\d)/g, '$1 ')}`.trim();
}

export function addDays(date: Date, days: number): Date {
  const d = new Date(date.getTime());
  d.setDate(d.getDate() + days);
  return d;
}

/** Date au format français dans le fuseau Africa/Libreville. */
export function formatDate(value: string | Date | null | undefined, withTime = false): string {
  if (!value) return '';
  const d = typeof value === 'string' ? new Date(value) : value;
  return new Intl.DateTimeFormat('fr-FR', {
    timeZone: 'Africa/Libreville',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  }).format(d);
}

export function slugify(input: string): string {
  return input
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 180);
}

export function buildWhatsAppLink(number: string, message: string): string {
  return `https://wa.me/${number.replace(/[^\d]/g, '')}?text=${encodeURIComponent(message)}`;
}
