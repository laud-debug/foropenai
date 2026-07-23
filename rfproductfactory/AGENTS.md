# AGENTS.md — RF Product Factory

## Mission
RF Product Factory est le PIM central de Rebel Forge pour PrestaShop 1.7.8.11. Il doit analyser des sources fournisseurs, détecter les doublons, comparer les données, proposer un enrichissement contrôlé, gérer les images et publier sans écrasement silencieux.

## Priorité absolue
Produire un outil fiable qui fait gagner du temps au quotidien. La qualité métier prime sur la sophistication technique.

## Règles non négociables
1. Ne jamais écraser une donnée produit existante sans décision explicite de l'opérateur.
2. Toujours distinguer création, enrichissement et mise à jour.
3. Toujours proposer le choix en ligne / hors ligne avant création d'un produit.
4. Un produit créé doit être « disponible à la vente » sauf règle métier explicitement contraire.
5. Les prix ne sont jamais modifiés automatiquement sans validation.
6. Les images sont comparées et validées séparément des textes.
7. Toute action importante est historisée : source, utilisateur, date, ancienne valeur, nouvelle valeur et résultat.
8. Préserver la compatibilité PrestaShop 1.7.8.11 et PHP supporté par l'installation Rebel Forge.
9. Ne pas introduire de dépendance externe sans justification et accord.
10. Ne jamais modifier plusieurs grands domaines fonctionnels dans un même ticket.

## Méthode obligatoire avant modification
1. Lire ce fichier et les documents liés au ticket.
2. Examiner le code existant avant de proposer une architecture nouvelle.
3. Décrire brièvement le plan et les fichiers concernés.
4. Identifier les risques de régression.
5. Limiter la modification au périmètre demandé.

## Méthode obligatoire après modification
1. Exécuter les contrôles disponibles : syntaxe PHP, tests, analyse statique ou scripts du dépôt.
2. Signaler clairement tout contrôle impossible à exécuter.
3. Résumer les fichiers modifiés et le comportement obtenu.
4. Mettre à jour la documentation concernée.
5. Ajouter ou mettre à jour les critères d'acceptation.

## Interdictions
- Pas de refonte générale opportuniste.
- Pas de suppression de comportement existant sans preuve qu'il est obsolète.
- Pas de valeur métier inventée.
- Pas de faux test « réussi » lorsque l'environnement ne permet pas de le lancer.
- Pas de contenu générique ou de listes « à compléter » dans la documentation finale.

## Vocabulaire métier
- Produit candidat : produit extrait d'une source avant publication.
- Produit existant : fiche déjà présente dans PrestaShop.
- Création : création d'une nouvelle fiche.
- Enrichissement : ajout de données absentes sans dégrader les données existantes.
- Remplacement : substitution explicite d'une valeur existante.
- Diff Engine : comparaison champ par champ entre candidat et produit existant.
- Publication hors ligne : produit créé mais désactivé sur la boutique.
- Publication en ligne : produit créé et activé.

## Sources de référence, par ordre de priorité
1. Règles métier validées dans `docs/01-regles-metier.md`.
2. Spécification fonctionnelle dans `docs/`.
3. Code actuellement en production.
4. Audit et architecture historiques dans `docs/reference/`.

En cas de contradiction, ne pas arbitrer silencieusement : documenter le conflit dans le compte rendu de mission.
