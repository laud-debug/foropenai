# Architecture cible

## Pipeline
Source → extraction → normalisation → résolution fournisseur → détection de doublons → Diff Engine → validation → gestion des images → contrôle qualité → publication → audit.

## Services recommandés
- SourceReader : collecte des données sans logique métier de publication.
- Normalizer : applique les règles générales et profils fournisseurs.
- DuplicateDetector : retourne des candidats et les raisons du score.
- DiffEngine : produit les différences, sans écrire en base produit.
- ImageManager : télécharge, compare, classe et prépare les images.
- ValidationService : conserve les décisions opérateur.
- PublicationService : seule couche autorisée à créer ou modifier un produit.
- AuditLogger : journalise toutes les étapes importantes.

## Contraintes
Les traitements longs doivent être découpables et reprenables. L'historique des jobs doit disposer d'une politique de rétention. Les recherches de doublons doivent être indexées et éviter les balayages complets du catalogue à chaque produit.
