<?php

/*
| Gabarits de notifications par défaut (syntaxe Handlebars : {{variable}}, {{#if variable}}…{{/if}}).
| Éditables depuis l'administration après le seed.
*/

return [
    'ORDER_CREATED' => [
        'name' => 'Commande créée',
        'email' => [
            'subject' => 'Votre commande {{orderNumber}} est enregistrée',
            'body' => '<p>Bonjour {{customerName}},</p><p>Merci pour votre commande <strong>{{orderNumber}}</strong> d\'un montant de <strong>{{total}}</strong>.</p>
<p>{{paymentInstructions}}</p><p>Suivez votre commande à tout moment : <a href="{{trackingUrl}}">{{trackingUrl}}</a></p>',
        ],
        'sms' => 'DutCh\'M : commande {{orderNumber}} enregistree ({{total}}). Suivi : {{trackingUrl}}',
        'whatsapp' => 'Bonjour {{customerName}} 🌸 Votre commande *{{orderNumber}}* ({{total}}) est bien enregistrée. Suivi : {{trackingUrl}}',
    ],
    'PAYMENT_CONFIRMED' => [
        'name' => 'Paiement confirmé',
        'email' => [
            'subject' => 'Paiement reçu pour la commande {{orderNumber}}',
            'body' => '<p>Bonjour {{customerName}},</p><p>Nous avons bien reçu votre paiement de <strong>{{amount}}</strong> pour la commande {{orderNumber}}.</p>
<p>Reste à payer : {{balanceDue}}.</p><p><a href="{{trackingUrl}}">Suivre ma commande</a></p>',
        ],
        'sms' => 'DutCh\'M : paiement de {{amount}} recu pour {{orderNumber}}. Merci !',
        'whatsapp' => 'Paiement de *{{amount}}* bien reçu pour votre commande {{orderNumber}}. Merci 🌸',
    ],
    'PAYMENT_FAILED' => [
        'name' => 'Paiement échoué',
        'email' => [
            'subject' => 'Votre paiement pour {{orderNumber}} n’a pas abouti',
            'body' => '<p>Bonjour {{customerName}},</p><p>Le paiement de votre commande {{orderNumber}} n\'a pas pu être validé ({{reason}}).</p>
<p>Vous pouvez réessayer depuis votre page de suivi : <a href="{{trackingUrl}}">{{trackingUrl}}</a></p>',
        ],
        'sms' => 'DutCh\'M : le paiement de {{orderNumber}} a echoue. Reessayez : {{trackingUrl}}',
        'whatsapp' => 'Le paiement de votre commande {{orderNumber}} n’a pas abouti. Vous pouvez réessayer ici : {{trackingUrl}}',
    ],
    'SHIPMENT_STEP' => [
        'name' => 'Nouvelle étape de suivi',
        'email' => [
            'subject' => '{{orderNumber}} : {{stepLabel}}',
            'body' => '<p>Bonjour {{customerName}},</p><p>Votre commande {{orderNumber}} vient de passer à l\'étape <strong>{{stepLabel}}</strong>.</p>
{{#if comment}}<p>{{comment}}</p>{{/if}}{{#if estimatedDate}}<p>Livraison estimée : {{estimatedDate}}</p>{{/if}}<p><a href="{{trackingUrl}}">Voir le suivi</a></p>',
        ],
        'sms' => 'DutCh\'M {{orderNumber}} : {{stepLabel}}. Suivi : {{trackingUrl}}',
        'whatsapp' => 'Votre commande {{orderNumber}} : *{{stepLabel}}*. {{comment}} Suivi : {{trackingUrl}}',
    ],
    'SHIPMENT_DELAYED' => [
        'name' => 'Retard signalé',
        'email' => [
            'subject' => 'Information sur votre commande {{orderNumber}}',
            'body' => '<p>Bonjour {{customerName}},</p><p>Votre commande {{orderNumber}} connaît un retard : {{comment}}</p>
<p>Nouvelle date estimée : <strong>{{estimatedDate}}</strong>. Nous vous prions de nous excuser.</p><p><a href="{{trackingUrl}}">Voir le suivi</a></p>',
        ],
        'sms' => 'DutCh\'M {{orderNumber}} : retard ({{comment}}). Nouvelle date estimee : {{estimatedDate}}.',
        'whatsapp' => 'Votre commande {{orderNumber}} a un peu de retard : {{comment}}. Nouvelle date estimée : {{estimatedDate}}. Toutes nos excuses 🌸',
    ],
    'OUT_FOR_DELIVERY' => [
        'name' => 'Livreur en route / prêt pour retrait',
        'email' => [
            'subject' => '{{orderNumber}} : {{stepLabel}}',
            'body' => '<p>Bonjour {{customerName}},</p><p>Votre commande {{orderNumber}} : <strong>{{stepLabel}}</strong>.</p>
<p>Votre code de remise : <strong style="font-size:20px;letter-spacing:4px">{{deliveryCode}}</strong><br>Communiquez-le uniquement au moment de la remise.</p>',
        ],
        'sms' => 'DutCh\'M {{orderNumber}} : {{stepLabel}}. Code de remise : {{deliveryCode}}',
        'whatsapp' => 'Votre commande {{orderNumber}} : *{{stepLabel}}* 🚚 Code de remise : *{{deliveryCode}}* (à donner au livreur uniquement).',
    ],
    'ORDER_DELIVERED' => [
        'name' => 'Commande livrée',
        'email' => [
            'subject' => 'Votre commande {{orderNumber}} a été livrée',
            'body' => '<p>Bonjour {{customerName}},</p><p>Votre commande {{orderNumber}} a bien été remise. Merci pour votre confiance et à très bientôt chez DutCh’M !</p>',
        ],
        'sms' => 'DutCh\'M : commande {{orderNumber}} livree. Merci et a bientot !',
        'whatsapp' => 'Votre commande {{orderNumber}} a été livrée 🌸 Merci pour votre confiance !',
    ],
    'BACK_IN_STOCK' => [
        'name' => 'Retour en stock',
        'email' => [
            'subject' => '{{productName}} est de nouveau disponible',
            'body' => '<p>Bonne nouvelle ! <strong>{{productName}}</strong> est de nouveau disponible.</p><p><a href="{{productUrl}}">Je le commande</a></p>',
        ],
        'sms' => 'DutCh\'M : {{productName}} est de nouveau disponible : {{productUrl}}',
        'whatsapp' => 'Bonne nouvelle 🌸 *{{productName}}* est de nouveau disponible : {{productUrl}}',
    ],
    'CONVERSATION_REPLY' => [
        'name' => 'Réponse du service client',
        'email' => [
            'subject' => 'DutCh’M vous a répondu',
            'body' => '<p>Bonjour {{customerName}},</p><p>Notre équipe vous a répondu :</p><blockquote>{{message}}</blockquote><p><a href="{{conversationUrl}}">Continuer la discussion</a></p>',
        ],
        'sms' => 'DutCh\'M vous a repondu : {{conversationUrl}}',
        'whatsapp' => 'DutCh’M vous a répondu : « {{message}} »',
    ],
    'PASSWORD_RESET' => [
        'name' => 'Réinitialisation du mot de passe',
        'email' => [
            'subject' => 'Réinitialisez votre mot de passe DutCh’M',
            'body' => '<p>Bonjour {{customerName}},</p><p>Pour choisir un nouveau mot de passe, cliquez sur le lien ci-dessous (valable 1 heure) :</p>
<p><a href="{{resetUrl}}">{{resetUrl}}</a></p><p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message.</p>',
        ],
        'sms' => 'DutCh\'M : reinitialisez votre mot de passe (1 h) : {{resetUrl}}',
        'whatsapp' => 'Réinitialisez votre mot de passe DutCh’M (valable 1 h) : {{resetUrl}}',
    ],
    'PHONE_VERIFICATION' => [
        'name' => 'Code de vérification du téléphone',
        'email' => [
            'subject' => 'Votre code DutCh’M',
            'body' => '<p>Votre code de vérification : <strong>{{code}}</strong> (valable 10 minutes).</p>',
        ],
        'sms' => 'DutCh\'M : votre code de verification est {{code}} (valable 10 min).',
        'whatsapp' => 'Votre code de vérification DutCh’M : *{{code}}* (valable 10 min).',
    ],
];
