# Tests d'acceptation prioritaires

## TA-001 — Nouveau produit distinct par référence
Étant donné un produit existant `SWQ15`, lorsque la source contient `SWQ154`, Product Factory doit proposer une création distincte et ne doit pas imposer l'enrichissement de `SWQ15`.

## TA-002 — Référence fournisseur normalisée
Lorsque la source contient `AMGSWQ129ML` et que PrestaShop contient `SWQ129`, le système peut proposer ce produit comme candidat uniquement si la règle fournisseur correspond et si les autres signaux sont cohérents. La raison du rapprochement doit être visible.

## TA-003 — Import de plusieurs images
Lorsque la source contient quatre images valides et différentes, les quatre sont proposées. Aucune ne disparaît silencieusement.

## TA-004 — Doublon image
Lorsque deux URL renvoient la même image, une seule image est proposée pour ajout.

## TA-005 — Publication hors ligne
Lorsque l'opérateur choisit hors ligne, le produit est créé disponible à la vente mais désactivé sur la boutique.

## TA-006 — Publication en ligne
Lorsque l'opérateur choisit en ligne et que le contrôle qualité est valide, le produit est créé actif et disponible à la vente.

## TA-007 — Protection d'une description existante
Une description existante ne peut pas être remplacée sans action explicite de l'opérateur.

## TA-008 — Échec partiel d'images
Si une image échoue au téléchargement, les autres restent traitables et l'erreur est journalisée.
