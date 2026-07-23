# Workflows fonctionnels

## WF-01 — Création d'un nouveau produit
1. L'opérateur fournit une URL, un fichier ou des données fournisseur.
2. Product Factory extrait et normalise les données.
3. Le moteur cherche les correspondances existantes.
4. Aucune correspondance suffisamment fiable n'est trouvée.
5. L'écran affiche « Nouveau produit » et interdit toute confusion avec un enrichissement.
6. L'opérateur vérifie nom, référence, EAN, catégories, prix et images.
7. Il choisit en ligne ou hors ligne.
8. Product Factory crée la fiche avec « disponible à la vente » activé.
9. Le résultat et l'identifiant PrestaShop sont historisés.

## WF-02 — Enrichissement d'un produit existant
1. Une correspondance fiable est détectée.
2. Le Diff Engine affiche chaque champ actuel et proposé.
3. Les champs absents peuvent être précochés pour ajout.
4. Les champs déjà renseignés ne sont jamais précochés pour remplacement, sauf règle explicitement validée.
5. Les images sont comparées dans une zone dédiée.
6. L'opérateur valide champ par champ ou utilise une action de masse clairement réversible avant publication.
7. Product Factory applique uniquement les décisions validées.

## WF-03 — Correspondance ambiguë
1. Plusieurs produits possibles sont détectés ou les signaux sont contradictoires.
2. Product Factory affiche la liste des candidats et les raisons du score.
3. Aucune création ni mise à jour n'est possible avant décision.
4. L'opérateur choisit un produit, déclare « nouveau produit » ou reporte.

## WF-04 — Import d'images
1. Télécharger ou recevoir toutes les images accessibles.
2. Vérifier le format, la résolution et l'intégrité.
3. Détecter les doublons exacts puis les quasi-doublons.
4. Présenter les images nouvelles face aux images existantes.
5. Choisir image principale, ajout, remplacement ou rejet.
6. Normaliser les dimensions selon les règles PrestaShop.
