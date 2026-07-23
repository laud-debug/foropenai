# Règles métier validées

## Création et publication
- L'opérateur choisit avant création : **en ligne** ou **hors ligne**.
- Le produit doit être marqué **disponible à la vente** par défaut.
- Le bouton ou libellé final doit refléter exactement la décision prise ; aucune contradiction du type « hors ligne » après choix « en ligne ».

## Détection du produit
- Référence exacte et EAN sont les signaux les plus forts.
- Une référence proche n'est pas une référence identique : `SWQ154` ne doit pas être assimilé à `SWQ15`.
- Les préfixes fournisseurs peuvent différer sans forcément désigner un autre produit, par exemple `AMGSWQ129ML` et `SWQ129` ; cette normalisation doit être spécifique au fournisseur.
- Le nom, le texte et l'image servent de signaux secondaires, jamais de preuve unique lorsque la référence contredit le rapprochement.

## Enrichissement
- Un enrichissement complète en priorité les champs absents.
- Tout remplacement d'une valeur existante est présenté séparément et nécessite une validation.
- L'opérateur doit pouvoir désactiver l'enrichissement lorsqu'il s'agit clairement d'un nouveau produit.
- Le système ne doit pas imposer « enrichir » lorsqu'aucun produit existant fiable n'est identifié.

## Images
- Toutes les images produit pertinentes doivent être proposées, pas uniquement la première.
- Les images identiques ou quasi identiques ne doivent pas être importées en double.
- Les tailles disproportionnées doivent être normalisées sans détérioration visible.
- L'image principale est choisie explicitement ou recommandée selon des critères documentés.
- Les images importées ne doivent pas être traitées comme des données Shopify ; l'environnement cible est PrestaShop.

## Prix et stock
- Le prix d'achat, lorsqu'il est disponible dans la source, doit être récupéré et présenté.
- Aucun prix existant ne doit être écrasé automatiquement.
- L'état « disponible à la vente » ne signifie pas nécessairement « actif en ligne ».

## Traçabilité
Pour chaque publication ou enrichissement, conserver au minimum : source, date, utilisateur, produit cible, champs acceptés/refusés, images retenues et résultat PrestaShop.
