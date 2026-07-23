# UX — Gestionnaire d'images

## Objectif
Importer toutes les images utiles, éliminer les doublons et éviter les images anormalement grandes, déformées ou de mauvaise qualité.

## Présentation
Deux zones : images actuellement dans PrestaShop et images issues de la source. Chaque vignette affiche résolution, format, taille, origine et statut de similarité.

## Décisions disponibles
- Conserver l'image existante.
- Ajouter l'image source.
- Définir comme image principale.
- Remplacer une image existante.
- Rejeter.

## Règles
- Détecter les doublons exacts par hash.
- Détecter les quasi-doublons par empreinte perceptuelle, avec validation humaine.
- Conserver l'image de meilleure résolution lorsque le contenu est identique.
- Ne pas agrandir artificiellement une petite image.
- Respecter le ratio ; aucune déformation.
- Traiter plusieurs images d'une même fiche, pas uniquement la première.

## Critères d'acceptation
- Une source contenant quatre images pertinentes permet d'en importer quatre.
- La même image ne peut pas être ajoutée deux fois au même produit.
- L'opérateur visualise clairement l'image principale avant publication.
- Les erreurs de téléchargement n'empêchent pas de traiter les autres images.
