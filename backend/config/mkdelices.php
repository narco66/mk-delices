<?php

/*
 * Paramètres métier MK Délices.
 * Les valeurs ci-dessous sont des VALEURS PAR DÉFAUT TECHNIQUES, non validées commercialement.
 * Elles sont recopiées dans la table `settings` par le SettingsSeeder puis éditables en back-office.
 * Toute clé marquée requires_validation=true doit être confirmée par MK Délices avant production
 * (voir docs/DEV-DECISIONS-A-VALIDER.md).
 */

return [
    'business_timezone' => env('APP_TIMEZONE_BUSINESS', 'Africa/Dakar'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    // index.html du build frontend (pré-rendu SEO servi par SpaController).
    'spa_index_path' => env('SPA_INDEX_PATH', base_path('../frontend/dist/index.html')),

    // Supervision (audit E9) : contrôles de /health.
    'health' => [
        // Le planificateur (cron) doit tourner en production : battement attendu toutes les minutes.
        'require_scheduler' => (bool) env('HEALTH_REQUIRE_SCHEDULER', env('APP_ENV') === 'production' || env('APP_ENV') === 'staging'),
        // Horodatage de la dernière sauvegarde réussie (écrit par deploy/scripts/backup.sh) ; vide = non vérifié.
        'backup_status_file' => env('HEALTH_BACKUP_STATUS_FILE'),
    ],

    // Cartes et géolocalisation (DEC-GEO-01 : fond OpenStreetMap via un fournisseur COMMERCIAL ; jamais les
    // serveurs publics d'OpenStreetMap en production). Sans fond de carte, le site propose uniquement « Utiliser
    // ma position » et la saisie manuelle ; sans géocodage, pas de recherche d'adresse ni de quartier suggéré.
    'maps' => [
        // Modèle d'URL des tuiles raster {z}/{x}/{y} (clé « navigateur » restreinte au domaine du site).
        'tiles_url' => env('MAPS_TILES_URL'),
        'tiles_attribution' => env('MAPS_TILES_ATTRIBUTION', '© OpenStreetMap contributors'),
        // Géocodage côté serveur (la clé n'est jamais envoyée au navigateur) : maptiler | none.
        'geocoder' => env('MAPS_GEOCODER', 'none'),
        'geocoder_key' => env('MAPS_GEOCODER_KEY'),
        'geocoder_timeout' => (int) env('MAPS_GEOCODER_TIMEOUT', 4),
        // Mise en cache des réponses de géocodage (secondes) : 0 tant que la licence du fournisseur n'est pas vérifiée.
        'geocoder_cache_seconds' => (int) env('MAPS_GEOCODER_CACHE_SECONDS', 0),
        // Région acceptée pour une position de livraison (Dakar et environs) et centre par défaut de la carte.
        'bounds' => ['min_lat' => 14.0, 'max_lat' => 15.2, 'min_lng' => -17.8, 'max_lng' => -16.8],
        'default_center' => ['lat' => 14.7167, 'lng' => -17.4677, 'zoom' => 12],
        // Conservation des positions GPS dans les commandes terminées (livrées, annulées, refusées), en jours après
        // le créneau. 90 j (décision MK Délices du 18/11/2026) : historique de localisation court (référence CNIL :
        // 2 mois) + marge pour réclamations et remboursements. 0 = purge désactivée.
        // Seules lat/lng sont effacées : quartier, rue et repère restent (pièce de la commande et de la facture).
        'position_retention_days' => (int) env('MAPS_POSITION_RETENTION_DAYS', 90),
    ],

    // Notifications du navigateur aux livreurs (web push). Clés générées par `php artisan mk:vapid-keys` ;
    // sans clés, la fonction est simplement désactivée (aucun bouton « Activer les notifications »).
    'push' => [
        'vapid_public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
        // Contact transmis aux services de notification (Google, Mozilla, Apple) en cas d'abus.
        'subject' => env('WEB_PUSH_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'contact@example.com')),
        // Services de notification acceptés pour l'adresse d'abonnement (évite d'envoyer des requêtes vers
        // n'importe quel serveur à partir d'une adresse fournie par le navigateur).
        'allowed_hosts' => ['fcm.googleapis.com', '*.push.services.mozilla.com', '*.notify.windows.com', '*.push.apple.com', 'web.push.apple.com'],
    ],

    'payments' => [
        // Simulateur réservé aux environnements local/testing : jamais actif en production.
        'simulator_enabled' => (bool) env('PAYMENTS_SIMULATOR_ENABLED', false) && in_array(env('APP_ENV'), ['local', 'testing', 'staging'], true),
        'simulator_secret' => env('PAYMENTS_SIMULATOR_SECRET'),
        'wave' => [
            'api_key' => env('WAVE_API_KEY'),
            'webhook_secret' => env('WAVE_WEBHOOK_SECRET'),
            'api_base' => env('WAVE_API_BASE', 'https://api.wave.com'),
        ],
        'orange_money' => [
            'client_id' => env('ORANGE_MONEY_CLIENT_ID'),
            'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET'),
            'webhook_secret' => env('ORANGE_MONEY_WEBHOOK_SECRET'),
        ],
        'session_ttl_minutes' => 30,
    ],

    'settings' => [
        // key => [group, default, is_public, requires_validation, description]
        'business.name' => ['business', 'MK Délices', true, false, 'Nom commercial affiché'],
        'business.legal_name' => ['business', null, true, true, 'Raison sociale exacte (à fournir)'],
        'business.ninea' => ['business', null, true, true, 'NINEA (à fournir) — obligatoire sur les factures'],
        'business.rccm' => ['business', null, true, true, 'Numéro RCCM (à fournir) — porté sur les factures'],
        'business.whatsapp_number' => ['business', null, true, true, 'Numéro WhatsApp Business au format international sans + (ex. 221XXXXXXXXX)'],
        'business.phone' => ['business', null, true, true, 'Téléphone service client'],
        'business.email' => ['business', null, true, true, 'Email service client'],
        'business.address' => ['business', 'Dakar, Sénégal', true, true, 'Adresse publique'],
        'business.instagram_url' => ['business', null, true, true, 'Lien Instagram'],
        'business.facebook_url' => ['business', null, true, true, 'Lien Facebook'],
        'business.tiktok_url' => ['business', null, true, true, 'Lien TikTok'],
        'business.hours' => ['business', [
            'mon' => [['09:00', '19:00']], 'tue' => [['09:00', '19:00']], 'wed' => [['09:00', '19:00']],
            'thu' => [['09:00', '19:00']], 'fri' => [['09:00', '19:00']], 'sat' => [['09:00', '19:00']], 'sun' => [],
        ], true, true, 'Horaires de livraison par jour (heure de Dakar) — valeurs de démonstration'],
        'ordering.closed_dates' => ['ordering', [], true, true, 'Jours fermés exceptionnels (AAAA-MM-JJ)'],
        'ordering.slot_duration_minutes' => ['ordering', 120, true, true, 'Durée d\'un créneau de livraison'],
        'ordering.slot_capacity' => ['ordering', 5, false, true, 'Nombre maximal de commandes par créneau'],
        'ordering.min_lead_minutes' => ['ordering', 240, true, true, 'Délai minimal entre commande et livraison'],
        'ordering.max_days_ahead' => ['ordering', 14, true, false, 'Horizon de réservation en jours'],
        'ordering.pickup_enabled' => ['ordering', false, true, true, 'Retrait sur place autorisé'],
        'ordering.cart_ttl_hours' => ['ordering', 72, false, false, 'Durée de vie d\'un panier'],
        'ordering.tracking_token_ttl_days' => ['ordering', 30, false, true, 'Validité du lien de suivi invité'],
        'ordering.max_qty_per_line' => ['ordering', 20, true, false, 'Quantité maximale par ligne'],
        'payments.methods' => ['payments', [
            'wave' => false, 'orange_money' => false, 'card' => false, 'cash_on_delivery' => false,
        ], true, true, 'Moyens de paiement activés (aucun contrat marchand validé à ce jour)'],
        'payments.require_payment_before_acceptance' => ['payments', true, false, true, 'Exiger un paiement confirmé avant acceptation (hors paiement à la livraison)'],
        'policies.cancellation' => ['policies', null, true, true, 'Politique d\'annulation et de remboursement (à rédiger et valider)'],
        // Périmètre desservi (contour GeoJSON) : géré dans Opérations > Zones, pas dans la page Paramètres.
        'delivery.service_area' => ['delivery', null, false, true, 'Périmètre desservi (contour GeoJSON) : une position hors de ce périmètre ne peut pas commander en ligne'],
        'delivery.proof_types' => ['delivery', ['recipient_confirmation', 'photo_reference', 'code'], false, true, 'Types de preuve de remise acceptés'],
        'delivery.incident_reasons' => ['delivery', ['client_absent', 'adresse_introuvable', 'client_injoignable', 'refus_client', 'produit_endommage', 'autre'], false, false, 'Motifs d\'échec de livraison'],
        'delivery.client_fault_min_wait_minutes' => ['delivery', 10, false, true, 'Attente minimale sur place (minutes) que le livreur doit déclarer, avec une tentative de contact, pour être payé en cas d’échec dû au client'],
        'delivery.code_enabled' => ['delivery', true, true, true, 'Code de remise à 4 chiffres demandé au client à la livraison (la remise sans code reste possible et est signalée au commercial)'],
        'delivery.accept_timeout_minutes' => ['delivery', 10, false, false, 'Délai (minutes) au-delà duquel une mission proposée et non acceptée est signalée au responsable (0 = jamais)'],
        'delivery.failed_fee_percent' => ['delivery', 50, false, true, 'Part de la rémunération due au livreur pour une course échouée par la faute du client (%)'],
        'delivery.client_fault_reasons' => ['delivery', ['client_absent', 'client_injoignable', 'refus_client'], false, true, 'Motifs d’échec considérés comme dus au client (course rémunérée au pourcentage ci-dessus)'],
        // Facturation : les prix affichés sont TTC ; la TVA (si applicable) est extraite du prix TTC.
        'invoicing.vat_enabled' => ['invoicing', false, false, true, 'Appliquer la TVA sur les factures (assujettissement à confirmer par l’expert-comptable)'],
        'invoicing.vat_rate_percent' => ['invoicing', 18, false, true, 'Taux de TVA en % (taux normal au Sénégal : 18 %)'],
        'invoicing.vat_exemption_note' => ['invoicing', 'TVA non applicable', false, true, 'Mention portée sur les factures sans TVA (fondement à préciser par l’expert-comptable)'],
        'invoicing.issue_on' => ['invoicing', 'delivered', false, true, 'Émission automatique de la facture : delivered (à la livraison) ou paid (au paiement confirmé)'],
        'invoicing.footer' => ['invoicing', null, false, true, 'Mentions complémentaires en pied de facture (conditions de paiement, coordonnées bancaires…)'],
        'preparation.undo_window_minutes' => ['preparation', 15, false, false, 'Délai (minutes) pendant lequel un démarrage de préparation fait par erreur peut être annulé (0 = jamais)'],
        'quality.cold_chain_max_minutes' => ['quality', null, false, true, 'Durée maximale hors froid (seuil sanitaire à valider par MK Délices)'],
    ],
];
