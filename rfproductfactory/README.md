# RF Product Factory 0.4.0

## Import Excel / Copier-coller

Le nouvel onglet permet de coller directement une grille copiée depuis Excel, de contrôler les correspondances de colonnes, de corriger chaque ligne et de créer les nouveaux produits en lot. Les doublons sont ignorés par sécurité.

Mapping GW proposé par défaut :

- Description → nom du produit
- Product Code + préfixe GW- → référence PrestaShop (règle fixe Rebel Forge)
- Product Code → référence fournisseur
- SS Code → information source séparée
- Barcode → EAN-13
- FRR → prix de vente TTC
- EUD → prix d’achat HT
- Release Date → date de disponibilité
- Weight (kg) → poids

La catégorie PrestaShop **Réservation GW** est sélectionnée par défaut lorsqu’elle existe. Le sélecteur de catégorie contient un champ de recherche et le dernier choix est mémorisé pour les prochains lots. La catégorisation automatique Race/System est désactivée par défaut afin de conserver la catégorie choisie.

# RF Product Factory 0.2.2

Module de création et d’enrichissement assisté de fiches produits pour PrestaShop 1.7.8.x.

## Fonctions principales

- Analyse d’une page produit publique via URL.
- Extraction JSON-LD Product, Open Graph et métadonnées HTML.
- Prévisualisation et modification des données avant création ou mise à jour.
- Détection des doublons par :
  - EAN exact, produit ou déclinaison ;
  - référence exacte ;
  - référence enveloppée par un préfixe ou suffixe fournisseur, par exemple `SWQ129` et `AMGSWQ129ML`, sans confondre `SWQ15` avec `SWQ154` ;
  - nom normalisé avec gestion des accents, ponctuations et pluriels ;
  - image de couverture identique ou visuellement très proche.
- Affichage de l’image de couverture, des références et des EAN du produit existant.
- Import sécurisé d’un maximum de 8 images, avec prise en charge WebP/AVIF lorsque PHP/GD le permet.
- Création d’un produit en ligne ou hors ligne selon le choix, disponible à la vente, visible partout et avec quantité 0.
- Historique des analyses, créations et enrichissements.
- Protection SSRF : blocage des réseaux privés, ports non standards et redirections non contrôlées.

## Enrichissement d’une fiche existante

Lorsqu’un doublon est détecté, le module permet maintenant de sélectionner la fiche existante puis de choisir précisément les éléments à compléter :

- référence ou EAN absents ;
- nom corrigé ou plus précis ;
- prix HT détecté ;
- description courte ou longue absente ;
- fusion d’informations nouvelles dans une description existante ;
- titre SEO et méta-description ;
- fabricant ou fournisseur absent ;
- images supplémentaires.

Aucune donnée n’est modifiée sans case cochée. Les options qui complètent un champ vide sont présélectionnées. Les remplacements d’informations déjà présentes, comme le prix ou le nom, restent décochés par défaut.

Avant l’ajout d’images, le module compare les fichiers distants avec toutes les images déjà liées au produit. Les images strictement identiques ou visuellement équivalentes sont ignorées automatiquement. Les nouvelles images sont ajoutées à la suite des images existantes sans remplacer la couverture.

## Installation ou mise à jour

1. Importer `rfproductfactory-0.4.0.zip` depuis **Modules > Gestionnaire de modules > Installer un module**.
2. Installer le ZIP directement par-dessus la version précédente, sans désinstaller le module.
3. Ouvrir **Catalogue > Product Factory**.
4. Dans le bloc **Index des images de couverture**, laisser l’index atteindre 100 % pour maximiser la détection visuelle des doublons.
5. Coller une URL publique et cliquer sur **Analyser**.

## Pré-requis

- PrestaShop 1.7.8.x.
- PHP avec extensions cURL et DOM.
- PHP/GD pour l’import et la comparaison visuelle des images.
- Accès sortant HTTPS depuis le serveur.

## Sécurité

Une fiche existante ne peut être enrichie que si elle figure encore dans les doublons détectés au moment de l’enregistrement. Les modifications sont appliquées uniquement à la boutique courante et à la langue utilisée dans le back-office pour les champs multilingues.

Installer d’abord sur une préproduction ou réaliser une sauvegarde complète avant test en production.

## Correctif 0.1.6

Le bouton d’enrichissement est désormais activé côté serveur dès qu’une fiche compatible est sélectionnée automatiquement. Les ressources du back-office sont versionnées pour éviter qu’un ancien JavaScript conservé en cache maintienne le bouton désactivé.


## Statut et disponibilité (0.1.6)

Avant création, choisir **Hors ligne** ou **En ligne immédiatement**. Dans les deux cas, le produit est configuré avec **Disponible à la vente = oui**, **Afficher le prix = oui** et **Visibilité = Partout**. Le mode hors ligne laisse simplement le produit désactivé. La récupération d’images analyse aussi les galeries, `srcset`, attributs de zoom et scripts produit Shopify afin d’importer les images secondaires.

Lorsqu’une ancienne fiche détectée n’est pas disponible à la vente ou reste en visibilité « Nulle part », l’enrichissement propose automatiquement l’option **Préparer la fiche pour la vente** sans modifier son état en ligne/hors ligne.


## Correctif d’aperçu et de doublons (0.1.7)

Les aperçus distants sont enfermés dans des cadres fixes de 160 × 160 px afin qu’une image de plusieurs milliers de pixels ne déforme plus la page. Le module regroupe également les URL Shopify correspondant au même fichier et vérifie le contenu des images par empreinte binaire et visuelle. Une même couverture exposée sous plusieurs URL n’est donc proposée qu’une fois ; les véritables vues secondaires restent séparées.


## Correctif de références et choix d’action (0.1.8)

La détection par référence incluse distingue maintenant un véritable emballage fournisseur d’une autre référence numérique. Ainsi, `SWQ129` peut correspondre à `AMGSWQ129ML`, mais `SWQ15` ne correspond jamais à `SWQ154`.

L’enrichissement n’est plus sélectionné automatiquement. Le choix par défaut est **Ne pas enrichir — créer ce produit comme une nouvelle fiche**. Pour enrichir, il faut sélectionner volontairement une fiche existante, puis cliquer sur le bouton d’enrichissement.

Le module fonctionne dans le back-office **PrestaShop**. Lorsqu’il analyse une page externe, il peut simplement reconnaître la structure technique du site source afin d’en extraire les images ; cela ne change pas la plateforme de la boutique.


## Correctif du choix de publication (0.1.9)

Le bouton final porte désormais le libellé neutre **Créer le produit**. Le choix **Hors ligne** ou **En ligne immédiatement** effectué juste au-dessus est la seule valeur utilisée lors de la création. Un résumé sous les boutons indique en toutes lettres le statut qui sera appliqué. Cette présentation évite toute contradiction si un ancien script du navigateur est encore en cache.


## Correctif HTTP 403 (0.2.0)

Le client HTTP utilise désormais un profil de navigateur standard pour les pages publiques qui refusent les User-Agent techniques. En cas de 403, il initialise une session sur la page d’accueil du même domaine, conserve uniquement les cookies de ce domaine, puis effectue une seule nouvelle tentative avec le référent d’origine. Les protections SSRF restent actives : ports standards uniquement, validation DNS, blocage des réseaux privés et contrôle manuel de chaque redirection. Aucun CAPTCHA, espace privé ou contrôle d’accès n’est contourné.

## Sites qui renvoient HTTP 403

Si un fournisseur bloque l’adresse IP du serveur PrestaShop, utilisez le panneau **Site bloquant : analyser le code source manuellement** :

1. ouvrir la fiche produit dans un navigateur ;
2. faire `Ctrl + U`, puis `Ctrl + A` et `Ctrl + C` ;
3. coller le code source complet dans le module, ou envoyer le fichier HTML sauvegardé ;
4. vérifier les informations détectées avant la création.

Le HTML brut n’est pas stocké. Le module conserve seulement les informations produit extraites dans l’historique d’analyse.

### Import d’images bloquées par le fournisseur

Quand le serveur fournisseur refuse l’adresse IP de PrestaShop avec un HTTP 403, l’écran de validation permet d’envoyer jusqu’à 8 images depuis l’ordinateur. Les fichiers locaux passent directement dans le pipeline sécurisé de création d’images PrestaShop, sans requête vers le site distant.
