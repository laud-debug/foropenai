# Mission Codex 01 — Consolidation documentaire et cartographie du module

Lis intégralement `AGENTS.md`, le dossier `docs/` et le code du module.

## But
Transformer la documentation actuelle en une spécification fidèle au comportement réel du module, sans modifier le code PHP.

## Travaux demandés
1. Cartographier les écrans, contrôleurs, services, modèles, tables et flux existants.
2. Comparer le comportement actuel aux règles de `docs/01-regles-metier.md`.
3. Compléter les documents existants avec des informations concrètes issues du code.
4. Créer `docs/architecture/cartographie-actuelle.md` avec :
   - points d'entrée ;
   - flux par fonctionnalité ;
   - services et responsabilités ;
   - tables/configurations utilisées ;
   - dépendances PrestaShop ;
   - zones à risque.
5. Créer `docs/backlog/ecarts-v1.md` avec les écarts constatés, classés : bloquant, important, amélioration.
6. Pour chaque écart, indiquer les fichiers concernés et proposer un ticket atomique.
7. Compléter `docs/tests/acceptance.md` par des tests d'acceptation directement liés au comportement observé.

## Contraintes
- Ne modifier aucun fichier PHP, JS, CSS, SQL ou template.
- Ne pas inventer de comportement absent du code.
- Ne pas remplir la documentation de phrases génériques ou de sections « à compléter ».
- Ne pas supprimer les règles métier fournies ; signaler les contradictions.

## Livrable final attendu
- Liste des fichiers Markdown créés ou modifiés.
- Résumé des cinq risques les plus importants.
- Proposition du premier ticket d'implémentation, suffisamment petit pour être développé et testé séparément.
- Commandes Git suggérées, mais aucun commit automatique sauf demande explicite.
