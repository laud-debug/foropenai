# RF Product Factory - Architecture Complète

## Vue d'ensemble

**RF Product Factory** est un module PrestaShop (1.7.8+) conçu pour créer ou enrichir des fiches produits de manière contrôlée. Il permet :

1. **Analyse web** : analyser une page produit public et en extraire les données structurées
2. **Import Excel** : créer jusqu'à 200 produits en batch depuis un copier-coller ou fichier de données
3. **Détection de doublons** : identifier les produits existants par référence, EAN, nom ou image
4. **Enrichissement** : compléter une fiche existante avec de nouvelles données
5. **Gestion d'images** : importer des images distantes ou locales, avec comparaison visuelle

**Version actuelle** : 0.4.0

**Auteur** : Rebel Forge

**Compatibilité** : PrestaShop 1.7.8.0 à 1.7.8.99

---

## Architecture générale

```
rfproductfactory/
├── rfproductfactory.php                 # Classe du module (point d'entrée)
├── config.xml & config_fr.xml           # Manifeste du module
│
├── classes/
│   ├── Repository/
│   │   └── RfProductFactoryJobRepository.php        # Gestion persistante des analyses
│   │
│   └── Service/
│       ├── RfProductFactoryUrlGuard.php             # Validation des URL (SSRF)
│       ├── RfProductFactoryHttpClient.php           # Client HTTP sécurisé avec gestion cookies
│       ├── RfProductFactoryExtractor.php            # Analyse HTML / extraction JSON-LD
│       ├── RfProductFactoryContentBuilder.php       # Formatage du contenu (HTML, SEO)
│       ├── RfProductFactoryDuplicateDetector.php    # Détection par texte
│       ├── RfProductFactoryImageMatcher.php         # Détection par image (visuellement)
│       ├── RfProductFactoryEnrichmentPlanner.php    # Planification d'enrichissement
│       ├── RfProductFactoryProductCreator.php       # Création de produit
│       ├── RfProductFactoryProductEnricher.php      # Enrichissement de produit
│       ├── RfProductFactoryImageImporter.php        # Import d'images
│       └── RfProductFactorySpreadsheetImporter.php  # Analyse de fichiers tabulaires
│
├── controllers/
│   └── admin/
│       └── AdminRfProductFactoryController.php      # Contrôleur principal
│
├── views/
│   ├── templates/admin/
│   │   ├── dashboard.tpl                           # Onglet "Analyse web"
│   │   └── excel_import.tpl                        # Onglet "Import Excel"
│   │
│   ├── css/
│   │   └── admin.css                               # Styles
│   │
│   └── js/
│       └── admin.js                                # Interactions côté client
│
├── docs/
│   ├── CHANGELOG.md                                # Historique des versions
│   └── ARCHITECTURE.md                             # Ce fichier
│
├── sql/
│   └── index.php
│
└── upgrade/
    └── install-*.php                               # Scripts de migration
```

---

## Composants clés

### 1. Module (rfproductfactory.php)

**Classe** : `RfProductFactory extends Module`

**Responsabilités** :
- Déclaration du module PrestaShop
- Installation et désinstallation
- Création des tables de base de données
- Création du tab d'administration

**Tables créées** :
```sql
-- Historique des analyses et créations
rfproductfactory_job
  - id_rfproductfactory_job (PK)
  - id_shop
  - id_employee
  - source_url (2048 caractères)
  - source_hash (SHA256 de l'URL)
  - status (analyzed, created, enriched, error)
  - payload_json (données structurées)
  - id_product (produit créé ou enrichi)
  - error_message (si erreur)
  - date_add, date_upd

-- Cache d'empreintes images pour comparaison
rfproductfactory_image_hash
  - id_rfproductfactory_image_hash (PK)
  - id_shop, id_product, id_image
  - sha1, dhash (empreintes)
  - width, height, variance
  - file_size, file_mtime
  - date_upd
```

**Prérequis** :
- PHP cURL (pour requêtes HTTP)
- PHP DOM/XML (pour analyse HTML)
- Optionnel : PHP GD (pour comparaison visuelle d'images)

---

### 2. Couche Repository

#### RfProductFactoryJobRepository

**Responsabilité** : Persistance des analyses et résultats

**Méthodes principales** :
- `create($sourceUrl, $payload, $status, $idShop, $idEmployee)` : crée une nouvelle analyse
- `get($idJob)` : récupère une analyse avec décodage du JSON
- `markCreated($idJob, $idProduct, $payload)` : marque comme créée
- `markEnriched($idJob, $idProduct, $payload)` : marque comme enrichie
- `markError($idJob, $message)` : enregistre une erreur
- `getLatest($idShop, $limit)` : récupère les analyses récentes avec noms des produits

**Détails** :
- Utilise `hash('sha256', $url)` pour déduplication
- JSON encodé sans échappement d'Unicode pour UTF-8 complet
- SQL safe via `pSQL()` et `Db::getInstance()`

---

### 3. Couche Service

#### 3.1. RfProductFactoryUrlGuard

**Responsabilité** : Protection SSRF et validation d'URL

**Méthodes** :
- `assertSafe($url)` : vérifie et lève une exception si dangereux
- `resolveSafeTarget($url)` : valide et résout une URL vers sa cible réelle

**Règles** :
- URL max 2048 caractères
- Schéma : `http` ou `https` seulement
- Interdiction des identifiants (`user:pass@`)
- Ports : 80 et 443 seulement
- Hôtes interdits : `localhost`, `.local`
- Résolution DNS avec validation des adresses privées/réservées
- Retour : `{ scheme, host, port, ip }`

---

#### 3.2. RfProductFactoryHttpClient

**Responsabilité** : Requêtes HTTP sécurisées avec gestion de session

**Méthodes** :
- `get($url, $maxBytes, $maxRedirects)` : télécharge un document HTML
- `getImage($url, $maxBytes, $maxRedirects, $referer)` : télécharge une image

**Caractéristiques** :
- User-Agent : navigateur moderne (Chrome)
- Gestion des redirections (max 5)
- Gestion des cookies par domaine
- Timeouts : 8s connexion, 25s total
- Fallback "browser warmup" : en cas de 403, demande la page d'accueil du domaine puis réessaie (simule un navigateur normal)
- Limite de taille configurable
- Retour : `{ body, status, headers, location, final_url, content_type }`

---

#### 3.3. RfProductFactoryExtractor

**Responsabilité** : Extraction de données structurées d'une page HTML

**Flux d'extraction** :
1. Télécharge la page (5 Mo max, 3 redirections)
2. Parse HTML avec DOMDocument
3. Extrait JSON-LD (schéma.org Product)
4. Extrait Open Graph / Meta tags
5. Extrait Microdata (itemscope/itemtype)
6. Extrait images de galerie
7. Normalise les données
8. Déduplique les images

**Méthodes** :
- `extract($url)` : analyse URL distante
- `extractFromHtml($url, $html, $finalUrl)` : analyse HTML fourni manuellement

**Données extraites** :
```php
[
  'source_url' => string,
  'final_url' => string (après redirections),
  'name' => string,                    // Titre du produit
  'description' => string,             // Description complète
  'description_short' => string,       // Extrait (350 car)
  'reference' => string,               // SKU / Référence fournisseur
  'ean13' => string,                   // Code-barres
  'brand' => string,                   // Marque/Fabricant
  'price_ttc' => float|null,           // Prix de vente TTC
  'price_ttc_kind' => string,          // 'retail', 'regular', etc.
  'wholesale_price_ht' => float|null,  // Prix d'achat HT
  'wholesale_price_ttc' => float|null, // Prix d'achat TTC
  'tax_rate' => float|null,            // Taux TVA détecté
  'currency' => string,                // Code ISO devise
  'images' => [url, url, ...],         // URLs des 8 images max
  'meta_title' => string,              // Pour SEO
  'meta_description' => string,        // Pour SEO
  'confidence' => [field => level],    // 'high', 'medium', 'low'
  'warnings' => [message, ...]         // Avertissements d'extraction
]
```

**Limites** :
- Max 16 images candidates (déduplication)
- 8 images finales max
- Empêche les images presque blanches/transparentes (variance < 5)

---

#### 3.4. RfProductFactoryContentBuilder

**Responsabilité** : Formatage du contenu et SEO

**Méthode** :
- `build($data)` : structure le contenu

**Résultat** :
```php
[
  'description' => '<h2>Présentation...</h2><p>...</p>...',  // HTML
  'description_short' => '<p>Extrait tronqué…</p>',
  'meta_title' => string,              // Limité à 70 car
  'meta_description' => string,        // Limité à 155 car
  'link_rewrite' => string             // URL-friendly
]
```

---

#### 3.5. RfProductFactoryDuplicateDetector

**Responsabilité** : Détection de doublons par données textuelles

**Méthode** :
- `find($reference, $ean13, $name, $idLang, $idShop)` : cherche les produits proches

**Stratégies de correspondance** :
1. **Produits** (référence exacte, EAN exact, inclusion de référence)
2. **Déclinaisons** (attributs avec même logique)
3. **Noms** (similitude textuelle)

**Critères** :
- Référence exacte → score 100, "strong match"
- Référence enveloppée (ex: `SWQ129` dans `AMGSWQ129ML`) → score 96, "strong match"
- EAN identique → score 100, "strong match"
- Noms similaires → scores variables

**Retour** (max 30 résultats) :
```php
[
  [
    'id_product' => int,
    'id_product_attribute' => int,
    'name' => string,
    'product_reference' => string,
    'ean13' => string,
    'active' => bool,
    'match_labels' => [description, ...],
    'strong_match' => bool,
    'match_score' => int,
  ],
  ...
]
```

---

#### 3.6. RfProductFactoryImageMatcher

**Responsabilité** : Détection de doublons par comparaison d'images

**Approche** :
- Construit un index des couvertures avec empreinte perceptuelle (dhash)
- Compare les images des candidats texte/référence
- Indexe automatiquement 120 images par visite (cron implicite)

**Méthodes** :
- `findMatches($imageUrls, $idLang, $idShop, $candidateProductIds)` : compare les images
- `indexProductIds($idShop, $productIds, $overwrite)` : pré-indexe des produits
- `indexBatch($idShop, $batchSize)` : indexation progressive du catalogue

**Empreinte** :
```php
[
  'sha1' => string,           // Hash SHA1
  'dhash' => string (16 hex), // Hash perceptuel (8x8)
  'width' => int,
  'height' => int,
  'variance' => float         // Variance de pixels
]
```

**Résultat** :
```php
[
  'matches' => [...],         // Produits visuellement similaires
  'warnings' => [...],        // Limitations (GD, table)
  'stats' => [                // État de l'index
    'total' => int,
    'indexed' => int,
    'remaining' => int,
    'percent' => int
  ]
]
```

---

#### 3.7. RfProductFactoryProductCreator

**Responsabilité** : Création d'une nouvelle fiche produit

**Flux** :
1. Valide tous les paramètres (nom, référence, EAN, prix, catégorie, taxes)
2. Crée l'objet `Product`
3. Ajoute à la catégorie
4. Fixe le stock à 0
5. Crée la relation fournisseur si applicable
6. Importe les images (locales, distantes)

**Configuration appliquée** :
- `visibility = 'both'`
- `available_for_order = 1`
- `show_price = 1`
- `condition = 'new'`
- `minimal_quantity = 1`
- `active = 0 ou 1` (selon status demandé)

**Retour** :
```php
[
  'id_product' => int,
  'publication_status' => 'online'|'offline',
  'images_requested' => int,
  'images_imported' => int,
  'image_errors' => [...],
  'imported_image_urls' => [...]
]
```

---

#### 3.8. RfProductFactoryProductEnricher

**Responsabilité** : Enrichissement d'une fiche existante

**Champs enrichissables** :
- `sale_settings` : prépare pour la vente
- `name` : remplace le nom
- `reference` : ajoute ou remplace référence
- `ean13` : ajoute ou remplace EAN
- `price` : met à jour prix HT
- `wholesale_price` : met à jour prix d'achat HT
- `description_short_fill` / `description_short_merge` : court-circuit
- `description_fill` / `description_merge` : description complète
- `meta_title` / `meta_description` : SEO
- `manufacturer` : ajoute/remplace fabricant
- `supplier` : ajoute/remplace fournisseur
- `images` : ajoute images distantes/locales

**Retour** :
```php
[
  'id_product' => int,
  'changes' => [description, ...],    // Résumé des changements
  'images_imported' => int,
  'images_skipped' => [...],          // Raisons du skip
  'warnings' => [...],
  'image_errors' => [...]
]
```

---

#### 3.9. RfProductFactoryImageImporter

**Responsabilité** : Import des images (distantes et locales)

**Méthodes** :
- `import($idProduct, $imageUrls, $idShop, $legend, $sourceUrl)` : URLs distantes
- `importUploaded($idProduct, $uploadedFiles, $idShop, $legend)` : fichiers PHP
- `mergeResults($first, $second)` : fusionne les résultats

**Logique** :
1. Max 8 images par produit
2. Valide les fichiers (taille, type MIME)
3. Crée fichier temporaire normalisé
4. Importe via API PrestaShop `Image`
5. Nettoie les fichiers temporaires

**Formats acceptés** : JPEG, PNG, GIF, WebP, AVIF

**Retour** :
```php
[
  'requested' => int,
  'imported' => int,
  'errors' => [message, ...],
  'imported_urls' => [url, ...]
]
```

---

#### 3.10. RfProductFactorySpreadsheetImporter

**Responsabilité** : Analyse de fichiers tabulaires (CSV, TSV, Excel copié-collé)

**Colonnes reconnues** :
- Obligatoires : `Description`, `Barcode` (EAN), `Product Code`
- Sources de prix : `FRR` (vente TTC), `CHR`, `EUD` (achat HT), `CHD`
- Optionnelles : `Release Date`, `Module`, `System`, `Race`, `SS Code`, `Weight (kg)`, `Qty in Pack`, `Commodity Code`, `Country of Origin`, etc.

**Alias pris en compte** :
```
Français et anglais reconnus automatiquement, ex:
- 'barcode' = 'ean' = 'ean13' = 'code barre'
- 'description' = 'designation' = 'désignation' = 'nom'
- 'weight (kg)' = 'poids kg'
```

**Méthodes** :
- `parse($raw, $options, $categories, $taxGroups, $idLang, $idShop)` : analyse le fichier
- `validateSubmittedRow($row, $categories, $taxGroups)` : valide une ligne avant création
- `refreshExistingProducts($rows, $idLang, $idShop)` : revérifie les doublons

**Logique** :
1. Détecte le séparateur (tabulation, virgule)
2. Mappe les colonnes via alias
3. Déduplique interne (même référence/EAN dans le lot)
4. Vérifie les doublons existants
5. Max 200 lignes par lot

**Règles Rebel Forge** :
- Référence PrestaShop : `GW-` + `Product Code` (immuable)
- Référence fournisseur : `Product Code`
- Taxe livres : appliquée si EAN `978*` ou `979*`, code douanier `4901*`, ou produit Black Library
- Prix de vente : `FRR` (default) ou `CHR`
- Prix d'achat : `EUD` (default) ou `CHD`

**Retour** :
```php
[
  'rows' => [
    [
      'name' => string,
      'reference' => string,
      'ean13' => string,
      'price_ht' => float,
      'wholesale_price_ht' => float,
      'id_category_default' => int,
      'id_tax_rules_group' => int,
      'existing_product' => null|[id_product, ...],
      'existing_reason' => string,
      'errors' => [...],
      'selected' => bool,
    ],
    ...
  ],
  'stats' => [
    'total' => int,
    'valid' => int,
    'new' => int,
    'existing' => int,
    'invalid' => int,
    'selected' => int
  ],
  'warnings' => [...],
  'headers' => [...],
  'delimiter' => 'tab'|','
]
```

---

#### 3.11. RfProductFactoryEnrichmentPlanner

**Responsabilité** : Calcul des options d'enrichissement disponibles

**Méthode** :
- `build($idProduct, $incoming, $idLang, $idShop)` : détermine les options

**Logique** :
- Charge le produit existant
- Compare avec les données entrantes
- Propose les changements pertinents
- Distingue "ajouter" (manquant) et "remplacer" (déjà présent)

**Retour** :
```php
[
  'has_options' => bool,
  'existing' => [
    'name' => string,
    'reference' => string,
    'price_ht' => float,
    'description_short' => string,
    'meta_title' => string,
    'image_count' => int,
    ...
  ],
  'options' => [
    [
      'field' => string,
      'label' => string,
      'existing_value' => string,
      'new_value' => string,
      'is_addition' => bool
    ],
    ...
  ]
]
```

---

### 4. Contrôleur d'administration

#### AdminRfProductFactoryController

**Classe** : `AdminRfProductFactoryController extends ModuleAdminController`

**Responsabilité** : Orchestration des actions utilisateur

**Sections** :
- `web` : analyse de fiches produit (par URL ou HTML manuel)
- `excel` : import en lot depuis fichiers tabulaires

**Actions POST** :
1. `submitRfpfAnalyze` → `processAnalyze()` : télécharge et analyse une URL
2. `submitRfpfAnalyzeHtml` → `processAnalyzeHtml()` : analyse HTML fourni manuellement
3. `submitRfpfCreate` → `processCreate()` : crée un produit après analyse
4. `submitRfpfEnrich` → `processEnrich()` : enrichit une fiche existante
5. `submitRfpfUploadLocalImages` → `processUploadLocalImages()` : importe images locales
6. `submitRfpfExcelPreview` → `processExcelPreview()` : prévisualise un lot Excel
7. `submitRfpfExcelCreate` → `processExcelCreate()` : crée le lot

**Flux workflow web** :
```
URL → Analyze → Preview (avec détection doublons)
           ↓
      Sélectionner images → Create Product
           ↓
      Ou enrichir produit existant → Enrich
           ↓
      Importer images locales (après création)
```

**Flux workflow Excel** :
```
Copier-coller / Fichier → Parse → Preview (éditable)
           ↓
      Vérifier doublons → Create Batch
           ↓
      Résultat (créé, dupliqué, erreur)
```

**Variables de template** :
```php
// Web
$rfpf_preview          // Données extraites
$rfpf_duplicates       // Doublons détectés
$rfpf_success          // Résultat création/enrichissement
$rfpf_manual_source_url
$rfpf_manual_fallback_suggested
$rfpf_image_index_stats

// Excel
$rfpf_excel_raw        // Texte brut entrée
$rfpf_excel_options    // Options d'import (taxes, catégories, prix)
$rfpf_excel_preview    // Tableau éditable des lignes
$rfpf_excel_result     // Résultat du lot
$rfpf_categories       // Liste des catégories
$rfpf_tax_groups       // Groupes de taxe
```

---

### 5. Templates

#### 5.1. dashboard.tpl (Onglet "Analyse web")

**Sections** :
1. **Zone d'analyse** : saisie d'une URL
2. **Mode manuel (replié)** : code source HTML ou fichier
3. **Index d'images** : barre de progression + bouton d'indexation
4. **Résultat d'analyse** (si réussi) :
   - Prévisualisation : nom, prix, EAN, images
   - Détection de doublons
   - Options de sélection d'images
   - Champs éditables avant création/enrichissement
5. **Succès** (après création/enrichissement) : barre verte avec détails

**Interactions JS** :
- Toggle du panel manuel
- Affichage/masquage des détails doublons
- Multi-sélection d'images
- Glisser-déposer d'images locales
- Téléchargement groupé d'images

---

#### 5.2. excel_import.tpl (Onglet "Import Excel")

**Sections** :
1. **Saisie** : zone texte ou upload fichier
2. **Règles d'import** : configuration (obligatoire)
   - Référence = GW- + Product Code (non modifiable, v0.3.1+)
   - Source de prix (FRR/CHR, EUD/CHD)
   - Taxes (standard, livres)
   - Catégorie (avec recherche, v0.3.2+)
3. **Prévisualisation** : tableau éditable
   - Colonnes : nom, référence, EAN, prix, catégorie, taxes, statut
   - Rang-clé : éditabilité
   - Couleur : vert (nouveau), orange (doublon), rouge (erreur)
4. **Résultat** : résumé créé/dupliqué/erreur avec liens

**Interactions JS** :
- Recherche instantanée dans les catégories
- Édition inline du tableau
- Sélection/désélection par checkbox
- Bouton "Appliquer à tout"
- Statistiques en temps réel

---

### 6. Flux de données complet

#### Scenario 1 : Analyse et création web

```
Utilisateur saisit URL
    ↓
UrlGuard.resolveSafeTarget()    [Validation SSRF]
    ↓
HttpClient.get()                 [Téléchargement avec fallback browser]
    ↓
Extractor.parseHtml()            [Extraction JSON-LD, Meta, Images]
    ↓
ContentBuilder.build()           [Formatage HTML + SEO]
    ↓
DuplicateDetector.find()         [Texte : ref/EAN/nom]
    ↓
ImageMatcher.findMatches()       [Visuel : comparaison image]
    ↓
JobRepository.create()           [Enregistrement]
    ↓
Template dashboard.tpl           [Affichage aperçu]
    ↓
Utilisateur sélectionne images + champs éditables
    ↓
ProductCreator.create()          [Création fiche]
    ↓
ImageImporter.import()           [Import images distantes]
    ↓
ImageImporter.importUploaded()   [Import images locales si ajoutées]
    ↓
JobRepository.markCreated()      [Enregistrement succès]
    ↓
Template affiche "Produit créé"
```

#### Scenario 2 : Enrichissement

```
Analyse complète (voir scénario 1)
    ↓
Utilisateur choisit "Enrichir" → sélectionne produit existant
    ↓
EnrichmentPlanner.build()        [Calcule options disponibles]
    ↓
Utilisateur coche champs à enrichir
    ↓
ProductEnricher.enrich()         [Application des changements]
    ↓
ImageMatcher.findMatches()       [Comparaison images]
    ↓
ImageImporter.import()           [Import images]
    ↓
JobRepository.markEnriched()     [Enregistrement]
    ↓
Template affiche "Produit enrichi"
```

#### Scenario 3 : Import Excel en lot

```
Utilisateur colle données / envoie fichier
    ↓
SpreadsheetImporter.parse()      [Détection séparateur + mapping colonnes]
    ↓
SpreadsheetImporter.refreshExistingProducts()
                                  [Vérification doublons]
    ↓
Template excel_import.tpl        [Affiche tableau éditable]
    ↓
Utilisateur édite + désélectionne doublons
    ↓
SpreadsheetImporter.validateSubmittedRow()
                                  [Validation de chaque ligne sélectionnée]
    ↓
Boucle sur chaque ligne :
    ProductCreator.create()      [Création fiche]
    ↓
Template affiche résumé (créé 15, dupliqué 3, erreur 1)
```

---

## Sécurité

### SSRF (Server-Side Request Forgery)

**Mitigations** :
- ✅ URL Guard bloque : schémas non HTTP/HTTPS
- ✅ URL Guard bloque : ports non 80/443
- ✅ URL Guard bloque : identifiants d'authentification
- ✅ URL Guard bloque : `localhost`, `.local`
- ✅ URL Guard bloque : adresses privées (127.0.0.0/8, 10.0.0.0/8, etc.)
- ✅ URL Guard résout DNS puis valide l'IP

### XSS (Cross-Site Scripting)

**Mitigations** :
- ✅ Contenu utilisateur toujours échappé en template Smarty
- ✅ HTML extrait de pages publiques est nettoyé avant affichage
- ✅ Descriptions produits passées par `htmlspecialchars()` en import
- ✅ Input type="url" pour URL

### CSRF (Cross-Site Request Forgery)

**Mitigation** :
- ✅ Intégration PrestaShop native (token CSRF via formulaire)

### SQL Injection

**Mitigations** :
- ✅ Toutes les chaînes filtrées via `pSQL()`
- ✅ Valeurs numériques castées `(int)`
- ✅ Requêtes préparées quand possibles

### Validation des données

- ✅ Référence : `Validate::isReference()`
- ✅ EAN-13 : `Validate::isEan13()`
- ✅ Nom : `Validate::isCatalogName()`
- ✅ URLs : `filter_var(..., FILTER_VALIDATE_URL)`

---

## Hooks (si intégration future)

Le module n'utilise pas de hooks PrestaShop pour le moment. Les points d'extension possibles :
- `hookActionProductCreate` / `hookActionProductUpdate` : audit
- `hookDisplayBackOfficeHeader` : notifications d'index
- `hookCronJobs` : indexation progressive en arrière-plan

---

## Événements et statistiques

### JobRepository

**Statuts enregistrés** :
- `analyzed` : extraction réussie
- `created` : produit créé
- `enriched` : produit enrichi
- `error` : erreur lors de création/enrichissement

**Payload JSON stocké** :
- Contient toutes les données extraites
- Résultat final (images importées, etc.)
- Historique complet pour audit

### Image Index

**Mise à jour automatique** :
- Auto-indexation de 120 images par visite
- Couvre progressivement tout le catalogue
- Bouton manuel disponible pour forcer

**Statistiques exposées** :
- `indexed` : nombre d'images indexées
- `total` : nombre total d'images de couverture
- `remaining` : à indexer
- `percent` : pourcentage

---

## Configuration

### Constantes

```php
// Module
const VERSION = '0.4.0';
const CONFIG_EXCEL_DEFAULT_CATEGORY = 'RFPF_EXCEL_DEFAULT_CATEGORY';
const CONFIG_EXCEL_AUTO_CATEGORY = 'RFPF_EXCEL_AUTO_CATEGORY';

// HttpClient
const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36...';

// Extractor
const MAX_DISTINCT_IMAGE_CANDIDATES = 16;
const MAX_IMAGE_FINGERPRINT_BYTES = 8388608;

// ImageMatcher
const AUTO_INDEX_BATCH = 120;
const MAX_REMOTE_IMAGES = 2;
const MAX_REMOTE_BYTES = 12582912;

// DuplicateDetector
const MAX_RESULTS = 30;

// SpreadsheetImporter
const MAX_ROWS = 200;
```

### Options de configuration (stockées en base)

```php
// Catégorie par défaut pour imports Excel (mémorisée, v0.3.2+)
RFPF_EXCEL_DEFAULT_CATEGORY

// Auto-replacement Race/System (activé/désactivé)
RFPF_EXCEL_AUTO_CATEGORY
```

---

## Performance et limites

| Aspect | Limite | Raison |
|--------|--------|--------|
| Taille URL | 2048 car | Sécurité, gestion système |
| Taille page | 5 Mo | Analyse HTML, limiter mémoire |
| Images/analyse | 8 max | Stockage PrestaShop, perf template |
| Candidats images | 16 max | Déduplication performante |
| Lignes Excel | 200 max | Éviter timeouts, limiter requêtes |
| Résultats doublons | 30 max | Affichage template |
| Redirections HTTP | 5 max | Boucles infinies |
| Connexion HTTP | 8s | Responsivité |
| Requête HTTP | 25s | Timeouts serveur |
| Auto-index/visite | 120 images | Backoff progressif |
| Comparaison image | 2 images source | Perf acceptable |

---

## Flux d'enrichissement (v0.2+)

L'enrichissement propose un choix granulaire :

**Remplissage (fill)** : complète un champ vide
- Exemple : ajouter `description_short` si absent

**Fusion (merge)** : complète ET détecte chevauchements
- Exemple : `description` nouveau + existant concaténé ou alerté

**Remplacement** : écrase la valeur existante
- Tous les champs sauf textes

**Paramètres d'enrichissement** :
```php
$selectedFields = [
  'name',                  // remplace nom
  'reference',             // ajoute/remplace ref
  'ean13',                 // ajoute/remplace EAN
  'price',                 // met à jour prix HT
  'wholesale_price',       // met à jour prix achat HT
  'description_short_fill',
  'description_short_merge',
  'description_fill',
  'description_merge',
  'meta_title',            // remplace pour SEO
  'meta_description',      // remplace pour SEO
  'manufacturer',          // ajoute/remplace
  'supplier',              // ajoute/remplace
  'sale_settings',         // prépare pour vente
  'images'                 // ajoute images
]
```

---

## Versions et évolution

**v0.4.0** (actuelle) :
- Catégorie présélectionnée "Réservation GW"
- Mémorisation catégorie par défaut
- Recherche instantanée catégories
- Bouton applique catégorie à tout le lot

**v0.3.1** :
- Règle GW- immuable
- SS Code séparé de la référence
- Product Code obligatoire

**v0.3.0** :
- Import Excel en lot (200 lignes)
- Taxes livres automatiques

**v0.2.x** :
- Mode manuel (code source)
- Enrichissement
- Images locales
- Vérification anti-doublon Novalis

**v0.1.x** :
- Analyse web de base
- Extraction JSON-LD

---

## Dépendances internes

```
AdminRfProductFactoryController (orchestration)
  ├── RfProductFactoryJobRepository (persistance)
  ├── RfProductFactoryExtractor
  │   └── RfProductFactoryHttpClient
  │       └── RfProductFactoryUrlGuard
  ├── RfProductFactoryContentBuilder
  ├── RfProductFactoryDuplicateDetector
  ├── RfProductFactoryImageMatcher
  │   └── RfProductFactoryHttpClient
  ├── RfProductFactoryEnrichmentPlanner
  ├── RfProductFactoryProductCreator
  │   └── RfProductFactoryImageImporter
  │       └── RfProductFactoryHttpClient
  ├── RfProductFactoryProductEnricher
  │   ├── RfProductFactoryImageImporter
  │   └── RfProductFactoryImageMatcher
  ├── RfProductFactorySpreadsheetImporter
  └── Templates (dashboard.tpl, excel_import.tpl)
```

---

## Intégrations PrestaShop

**Dépendances** :
- `Db::getInstance()` : requêtes base
- `Context::getContext()` : contexte shop/langue/employé
- `Product`, `Category`, `Manufacturer`, `Supplier`, `Image` : objets métier
- `Language::getLanguages()` : langues
- `TaxRulesGroup`, `Validate` : utilitaires
- `Tools`, `Configuration` : helpers

**Création de Tab** :
- Onglet admin "AdminRfProductFactory" sous "Catalogue"
- Multilingue (localisable)

---

## Debugging et logs

**Enregistrements** :
- Tous les jobs (analyses, créations, erreurs) en base
- Payload JSON complet disponible pour audit
- Timestamp précis (date_add, date_upd)

**Pas de fichiers log natifs** : préférer les tables `rfproductfactory_job` pour tracer.

---

## Perspectives d'amélioration

1. **Webhook** : notifier un service externe après création
2. **Import/Export mappings** : sauvegarde de configurations d'import
3. **CLI** : indexation images en fond via cron
4. **API REST** : accès programmé (en REST PrestaShop 1.7.4+)
5. **Notifications** : alertes administrateur sur doublons détectés
6. **Planification** : retarder la création à une date ultérieure
7. **Variantes** : support des déclinaisons (attributs)

---

**Document généré pour v0.4.0**
**Auteur analyse** : Architecture extracted from source code  
**Date** : 2025-07-23
