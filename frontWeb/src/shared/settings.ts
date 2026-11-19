export interface SupportSlot {
  /** 0 = dimanche … 6 = samedi */
  day: number;
  open: string;
  close: string;
}

export interface ShopInfo {
  name: string;
  tagline: string;
  logoUrl: string;
  email: string;
  phone: string;
  address: string;
  city: string;
  socials: { facebook: string; instagram: string; tiktok: string };
}

export interface PaymentSettings {
  cashOnDelivery: boolean;
  airtelMoney: boolean;
  moovMoney: boolean;
  /** Paiement à la livraison autorisé pour les articles en précommande */
  codForPreorder: boolean;
  /** Pourcentage d'acompte global des précommandes */
  depositPercent: number;
  /** Le solde de précommande peut être payé à la livraison */
  balanceOnDelivery: boolean;
  /** Acompte remboursable en cas d'annulation après commande fournisseur */
  depositRefundable: boolean;
  /** Délai d'expiration d'un paiement mobile money (minutes) */
  expiryMinutes: number;
}

/** Couleurs de l'interface (hexadécimal #RRGGBB) ; les nuances sont dérivées côté client. */
export interface ThemeSettings {
  primary: string;
  secondary: string;
  background: string;
  text: string;
}

export interface SettingsMap {
  shop: ShopInfo;
  theme: ThemeSettings;
  whatsappNumber: string;
  supportHours: { label: string; slots: SupportSlot[] };
  currency: string;
  payments: PaymentSettings;
  preorder: { defaultLeadTimeDays: number; maxQtyPerOrder: number };
  notifications: { email: boolean; sms: boolean; whatsapp: boolean };
  pickup: { enabled: boolean; address: string; hours: string };
  freeShippingThreshold: number | null;
  chat: { welcomeMessage: string; offHoursMessage: string };
}

export type SettingKey = keyof SettingsMap;

export const DEFAULT_SETTINGS: SettingsMap = {
  shop: {
    name: 'DutCh’M',
    tagline: 'La Fleuriste du Bien-être',
    logoUrl: '',
    email: 'contact@amoria.ga',
    phone: '+24177270034',
    address: 'Quartier Louis, Libreville',
    city: 'Libreville',
    socials: { facebook: '', instagram: '', tiktok: '' },
  },
  theme: { primary: '#D4A12A', secondary: '#E6BE5A', background: '#FCFAF5', text: '#2B2115' },
  whatsappNumber: '+24177270034',
  supportHours: {
    label: 'Du lundi au samedi, de 9 h à 18 h',
    slots: [1, 2, 3, 4, 5, 6].map((day) => ({ day, open: '09:00', close: '18:00' })),
  },
  currency: 'XAF',
  payments: {
    cashOnDelivery: true,
    airtelMoney: true,
    moovMoney: true,
    codForPreorder: false,
    depositPercent: 50,
    balanceOnDelivery: true,
    depositRefundable: false,
    expiryMinutes: 30,
  },
  preorder: { defaultLeadTimeDays: 14, maxQtyPerOrder: 20 },
  notifications: { email: true, sms: false, whatsapp: false },
  pickup: { enabled: true, address: 'Boutique DutCh’M, Quartier Louis, Libreville', hours: 'Lun–Sam 9 h – 18 h' },
  freeShippingThreshold: null,
  chat: {
    welcomeMessage: 'Bonjour et bienvenue chez DutCh’M 🌸 Comment pouvons-nous vous aider ?',
    offHoursMessage:
      'Notre équipe est actuellement hors ligne. Nous vous répondrons dès la réouverture, généralement sous quelques heures.',
  },
};

/** Clés exposées publiquement à la boutique. */
export const PUBLIC_SETTING_KEYS: SettingKey[] = [
  'shop',
  'theme',
  'whatsappNumber',
  'supportHours',
  'currency',
  'payments',
  'preorder',
  'pickup',
  'freeShippingThreshold',
  'chat',
];

export type PublicSettings = Pick<SettingsMap, (typeof PUBLIC_SETTING_KEYS)[number]> & { supportOnline: boolean };

/** Indique si l'assistance est ouverte à l'instant donné (heure de Libreville). */
export function isSupportOpen(slots: SupportSlot[], now: Date = new Date()): boolean {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Africa/Libreville',
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(now);
  const wd = parts.find((p) => p.type === 'weekday')?.value ?? 'Mon';
  const day = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(wd);
  const hm = `${parts.find((p) => p.type === 'hour')?.value}:${parts.find((p) => p.type === 'minute')?.value}`;
  return slots.some((s) => s.day === day && hm >= s.open && hm < s.close);
}
