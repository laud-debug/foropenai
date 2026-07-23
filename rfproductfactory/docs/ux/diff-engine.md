# UX — Diff Engine

## Objectif
Permettre de décider rapidement, sans risque d'écrasement silencieux, quelles données du produit candidat doivent être ajoutées ou remplacer les données PrestaShop.

## En-tête
- Source analysée et fournisseur détecté.
- Produit candidat : nom, référence et EAN.
- Produit existant proposé : nom, référence, EAN, identifiant PrestaShop.
- Niveau de confiance et raisons principales.
- Actions : choisir un autre produit, déclarer nouveau produit, reporter.

## Tableau de comparaison
Colonnes minimales :
1. Champ.
2. Valeur actuelle.
3. Valeur proposée.
4. Type d'écart : absent, identique, différent, conflit.
5. Décision : conserver, ajouter, remplacer, fusionner.
6. Justification ou source.

Champs concernés : nom, référence, EAN, description courte, description longue, fabricant, fournisseur, catégories, caractéristiques, poids, dimensions, prix d'achat, prix de vente conseillé, SEO et disponibilité.

## Codes d'état
- Identique : aucune action nécessaire.
- Manquant dans PrestaShop : ajout proposé.
- Différent : décision manuelle.
- Conflit critique : blocage avant publication.

## Actions de masse autorisées
- Ajouter tous les champs manquants.
- Ignorer toutes les propositions non sélectionnées.
- Revenir aux décisions initiales.

L'action « tout remplacer » est déconseillée et ne doit pas être disponible sans confirmation renforcée.

## Critères d'acceptation
- Un champ existant n'est jamais remplacé sans sélection explicite.
- La différence entre ajout et remplacement est immédiatement visible.
- Un opérateur peut déclarer que le candidat est un nouveau produit.
- Les décisions survivent à un retour vers l'écran précédent pendant le même job.
