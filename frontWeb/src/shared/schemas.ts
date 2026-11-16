import { z } from 'zod';
import { DeliveryGrouping, DeliveryMode, PaymentMethod } from './enums';
import { normalizeGabonPhone } from './format';

export const phoneSchema = z
  .string({ required_error: 'Le téléphone est obligatoire' })
  .trim()
  .min(1, 'Le téléphone est obligatoire')
  .refine((v) => normalizeGabonPhone(v) !== null, 'Numéro gabonais invalide (ex. +241 77 12 34 56)')
  .transform((v) => normalizeGabonPhone(v) as string);

export const optionalEmailSchema = z
  .string()
  .trim()
  .email('Adresse e-mail invalide')
  .or(z.literal(''))
  .optional()
  .transform((v) => (v ? v : undefined));

export const passwordSchema = z
  .string()
  .min(8, 'Au moins 8 caractères')
  .max(128, '128 caractères maximum');

export const adminPasswordSchema = passwordSchema
  .min(10, 'Au moins 10 caractères')
  .regex(/[A-Z]/, 'Au moins une majuscule')
  .regex(/[a-z]/, 'Au moins une minuscule')
  .regex(/\d/, 'Au moins un chiffre')
  .regex(/[^A-Za-z0-9]/, 'Au moins un caractère spécial');

export const contactStepSchema = z
  .object({
    fullName: z.string().trim().min(2, 'Indiquez votre nom complet').max(120),
    phone: phoneSchema,
    email: optionalEmailSchema,
    createAccount: z.boolean().default(false),
    password: z.string().optional(),
  })
  .refine((v) => !v.createAccount || (v.password && v.password.length >= 8), {
    message: 'Mot de passe de 8 caractères minimum requis pour créer un compte',
    path: ['password'],
  });

export const deliveryStepSchema = z
  .object({
    mode: z.nativeEnum(DeliveryMode),
    zoneId: z.coerce.number().int().positive().optional(),
    city: z.string().trim().max(80).optional(),
    district: z.string().trim().max(120).optional(),
    landmark: z.string().trim().max(255).optional(),
    instructions: z.string().trim().max(500).optional(),
    grouping: z.nativeEnum(DeliveryGrouping).default(DeliveryGrouping.SPLIT),
  })
  .superRefine((v, ctx) => {
    if (v.mode !== DeliveryMode.HOME) return;
    if (!v.zoneId) ctx.addIssue({ code: 'custom', path: ['zoneId'], message: 'Choisissez votre zone de livraison' });
    if (!v.landmark || v.landmark.length < 3)
      ctx.addIssue({ code: 'custom', path: ['landmark'], message: 'Le point de repère est obligatoire' });
  });

export const checkoutSchema = z.object({
  contact: contactStepSchema,
  delivery: deliveryStepSchema,
  items: z
    .array(z.object({ variantId: z.number().int().positive(), quantity: z.number().int().min(1).max(999) }))
    .min(1, 'Votre panier est vide'),
  couponCode: z.string().trim().max(40).optional(),
  paymentMethod: z.nativeEnum(PaymentMethod),
  preorderTermsAccepted: z.boolean().default(false),
  cgvAccepted: z.literal(true, { errorMap: () => ({ message: 'Vous devez accepter les CGV' }) }),
});

export type CheckoutInput = z.infer<typeof checkoutSchema>;

export const loginSchema = z.object({
  identifier: z.string().trim().min(3, 'E-mail ou téléphone requis'),
  password: z.string().min(1, 'Mot de passe requis'),
});

export const registerSchema = z.object({
  fullName: z.string().trim().min(2, 'Nom requis').max(120),
  phone: phoneSchema,
  email: optionalEmailSchema,
  password: passwordSchema,
  newsletterConsent: z.boolean().default(false),
});

export const trackingSchema = z.object({
  number: z
    .string()
    .trim()
    .toUpperCase()
    .regex(/^AMO-\d{4}-\d{6}$/, 'Format attendu : AMO-2026-000123'),
  phone: phoneSchema,
});

export const contactFormSchema = z.object({
  name: z.string().trim().min(2, 'Nom requis').max(120),
  phone: phoneSchema,
  email: optionalEmailSchema,
  subject: z.string().trim().max(150).optional(),
  message: z.string().trim().min(5, 'Votre message est trop court').max(3000),
});

export const newsletterSchema = z
  .object({
    email: optionalEmailSchema,
    phone: z.string().trim().optional(),
    consent: z.literal(true, { errorMap: () => ({ message: 'Votre consentement est requis' }) }),
  })
  .refine((v) => v.email || (v.phone && normalizeGabonPhone(v.phone)), {
    message: 'Indiquez un e-mail ou un téléphone valide',
    path: ['email'],
  });

export const startConversationSchema = z.object({
  name: z.string().trim().min(2, 'Votre nom').max(120),
  phone: phoneSchema,
  message: z.string().trim().min(1, 'Votre message').max(3000),
  productId: z.number().int().positive().optional(),
  orderNumber: z.string().trim().optional(),
});
