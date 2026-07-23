# Audit technique et fonctionnel – RF Product Factory v0.3.2

## 1. Résumé exécutif

Le module RF Product Factory implémente un workflow complet de bout en bout : analyse d’une page web, détection de doublons, création ou enrichissement de fiches produits, import d’images et import Excel par copier-coller. L’implémentation observée est plus riche qu’un simple prototype : elle combine une couche de services, un contrôleur d’administration, un repository de jobs, un moteur de détection de doublons et un flux Excel avec prévisualisation.

Le niveau de maturité technique est globalement correct pour un usage interne ou en petite phase d’opérationnalisation. Les principaux risques identifiés ne concernent pas l’existence du module, mais sa capacité à évoluer sans perte de performances, de traçabilité et de fiabilité sur un catalogue plus important ou sur des équipes plus nombreuses.

## 2. Portée et limites

### Portée
- Audit basé sur l’inspection réelle du code du dépôt, sans modification du code métier.
- Vérification des fichiers suivants :
  - [rfproductfactory/rfproductfactory.php](../rfproductfactory.php)
  - [rfproductfactory/controllers/admin/AdminRfProductFactoryController.php](../controllers/admin/AdminRfProductFactoryController.php)
  - [rfproductfactory/classes/Repository/RfProductFactoryJobRepository.php](../classes/Repository/RfProductFactoryJobRepository.php)
  - [rfproductfactory/classes/Service/RfProductFactoryDuplicateDetector.php](../classes/Service/RfProductFactoryDuplicateDetector.php)
  - [rfproductfactory/classes/Service/RfProductFactorySpreadsheetImporter.php](../classes/Service/RfProductFactorySpreadsheetImporter.php)
  - [rfproductfactory/classes/Service/RfProductFactoryImageImporter.php](../classes/Service/RfProductFactoryImageImporter.php)
  - [rfproductfactory/views/templates/admin/activity_dashboard.tpl](../views/templates/admin/activity_dashboard.tpl)
  - [rfproductfactory/docs/CHANGELOG.md](CHANGELOG.md)

### Limites
- Aucun environnement PrestaShop opérationnel n’a été lancé au cours de l’audit.
- Aucun exécutable PHP n’a été disponible localement pour valider le comportement via des tests d’exécution.
- Les conclusions ci-dessous sont donc fondées sur l’analyse statique du code et sur la recherche de patterns récurrents, sans exécuter le module en production.

## 3. Inventaire fonctionnel

### 3.1 Flux web
- Analyse d’une URL distante via le contrôleur d’administration.
- Mode manuel pour fournir du HTML brut ou un fichier HTML/TXT.
- Construction d’un aperçu produit avec métadonnées, images, prix et doublons potentiels.
- Création de produit à partir d’une analyse, avec possibilité d’enrichissement d’une fiche existante.

### 3.2 Détection de doublons
- Recherche par référence exacte, EAN, nom, et fusion avec des correspondances d’images.
- Blocage des créations si une correspondance forte est détectée, sauf confirmation explicite.
- Présentation d’un lien direct vers la fiche PrestaShop de la correspondance.

### 3.3 Import d’images
- Téléchargement d’images distantes avec limite de taille et stratégie de fallback.
- Import d’images locales depuis le back-office.
- Gestion du premier fichier comme couverture si aucune couverture n’existe.
- Support de formats modernes via conversion PNG si le runtime GD le permet.

### 3.4 Import Excel / copier-coller
- Lecture de colonnes via détection automatique de délimiteur.
- Validation des lignes, construction de références dans la règle GW- + Product Code.
- Détection des doublons au sein du lot et par rapport au catalogue existant.
- Prévisualisation avant création, avec sélection de lignes et options de catégorie/taxes.

### 3.5 Tableau de bord
- Statistiques de l’activité du jour et des 30 derniers jours.
- Liste des 20 derniers jobs avec statut, produit associé et message d’erreur.
- Navigation dédiée vers le tableau de bord depuis les onglets du module.

## 4. Forces observées

### 4.1 Sécurité de base et protections SSRF
Le module ne part pas d’une base vide : il possède une couche de garde des URLs et un client HTTP qui limite les comportements dangereux. Les classes [rfproductfactory/classes/Service/RfProductFactoryUrlGuard.php](../classes/Service/RfProductFactoryUrlGuard.php) et [rfproductfactory/classes/Service/RfProductFactoryHttpClient.php](../classes/Service/RfProductFactoryHttpClient.php) apportent une première barrière utile contre les URL non désirées.

### 4.2 Moteur de détection de doublons assez riche
La détection combine plusieurs signaux : identifier de produit, EAN, nom et image. Ce niveau de sophistication est un vrai point fort pour réduire les créations en double.

### 4.3 Flux Excel structuré
La classe [rfproductfactory/classes/Service/RfProductFactorySpreadsheetImporter.php](../classes/Service/RfProductFactorySpreadsheetImporter.php) offre un flux de validation, de préparation, de détection de doublons et de prévisualisation relativement complet. C’est un point fort pour la productivité métier.

### 4.4 Traçabilité de l’activité
Le repository de jobs et le tableau de bord fournissent une bonne base de traçabilité pour les opérations réalisées dans le module, même si cette traçabilité mérite davantage d’optimisation et de gouvernance.

## 5. Problèmes critiques et importants

### PF-000-01 – Absence de couverture automatisée et de pipeline de validation
- Gravité : Important
- Confiance : Très élevée
- Fichiers et méthodes concernés : l’ensemble du module ; aucune suite de tests n’a été retrouvée dans le dépôt.
- Constat factuel : l’audit n’a retrouvé ni tests PHPUnit, ni tests Behat, ni fichiers de tests dédiés, ni configuration de CI autour du module.
- Conséquence métier : toute évolution de la logique de parsing, de détection de doublons ou de création de produits peut introduire des régressions non détectées avant mise en production.
- Correction recommandée : ajouter une suite de tests ciblée sur les cas critiques (extraction web, construction de références, validation Excel, logique de doublons, import d’images) et intégrer une exécution automatique dans un pipeline CI.
- Ticket proposé : PF-000-01 – Mettre en place des tests automatisés et un pipeline CI minimum pour le module.
- Effort estimé : Moyen

### PF-000-02 – Requêtes de détection de doublons potentiellement coûteuses sur de gros catalogues
- Gravité : Important
- Confiance : Très élevée
- Fichiers et méthodes concernés : [rfproductfactory/classes/Service/RfProductFactoryDuplicateDetector.php](../classes/Service/RfProductFactoryDuplicateDetector.php), notamment les méthodes appendProductIdentifierMatches, appendCombinationIdentifierMatches et appendNameMatches.
- Constat factuel : les requêtes utilisent des conditions de type LIKE sur des colonnes de texte, notamment sur les noms de produits, et combinent plusieurs OR sur des tables de catalogue. Aucune stratégie de full text ou de colonne normalisée n’est visible dans le code.
- Conséquence métier : sur un catalogue volumineux ou avec beaucoup d’analyses simultanées, le temps de réponse du back-office risque d’augmenter fortement, avec des risques de timeout ou de saturation des ressources.
- Correction recommandée : privilégier des recherches indexées sur références/EAN, introduire une colonne de normalisation pour les noms, et revoir la logique pour éviter les scans complets sur la table produit à chaque analyse.
- Ticket proposé : PF-000-02 – Optimiser la détection de doublons pour les grands catalogues.
- Effort estimé : Moyen à élevé

### PF-000-03 – Historique des jobs et payload JSON sans stratégie de rétention ni d’indexation adaptée
- Gravité : Important
- Confiance : Très élevée
- Fichiers et méthodes concernés : [rfproductfactory/classes/Repository/RfProductFactoryJobRepository.php](../classes/Repository/RfProductFactoryJobRepository.php) et [rfproductfactory/rfproductfactory.php](../rfproductfactory.php).
- Constat factuel : la table rfproductfactory_job stocke un payload complet au format JSON dans un champ MEDIUMTEXT et le module ne montre aucun mécanisme de purge, d’archivage ou de compression. La table ne possède pas non plus d’index dédié sur les colonnes de dates utilisées pour les statistiques.
- Conséquence métier : la croissance du volume de données sur les jobs va ralentir les statistiques et l’affichage du tableau de bord, puis finir par alourdir la base de données sans réelle valeur pour l’utilisateur.
- Correction recommandée : ajouter des index composite sur les colonnes d’usage fréquent, prévoir une politique de rétention des jobs anciens, et envisager de ne stocker qu’un sous-ensemble structuré des informations nécessaires.
- Ticket proposé : PF-000-03 – Ajouter une politique de rétention et des index pour l’historique de jobs.
- Effort estimé : Moyen

### PF-000-04 – Faible visibilité opérationnelle sur les échecs et les anomalies d’exécution
- Gravité : Moyen
- Confiance : Haute
- Fichiers et méthodes concernés : [rfproductfactory/controllers/admin/AdminRfProductFactoryController.php](../controllers/admin/AdminRfProductFactoryController.php), [rfproductfactory/classes/Repository/RfProductFactoryJobRepository.php](../classes/Repository/RfProductFactoryJobRepository.php).
- Constat factuel : les erreurs sont enregistrées dans le job et remontées à l’interface, mais le module ne semble pas fournir de journal centralisé, de métriques ou de corrélation facile entre les erreurs, les jobs et les produits créés. La traçabilité existe, mais elle reste partielle.
- Conséquence métier : en cas de problème récurrent, l’équipe support devra reconstruire l’historique manuellement à partir de la base, ce qui augmente le temps de résolution.
- Correction recommandée : enrichir la table des jobs avec des événements détaillés, ou installer un mécanisme de logging dédié côté module et côté base, avec filtrage par statut et par source.
- Ticket proposé : PF-000-04 – Ajouter un niveau de journalisation et de diagnostic plus structuré.
- Effort estimé : Moyen

## 6. Améliorations secondaires

### 6.1 Rendre les règles métier plus explicites et plus testables
Certaines règles sont intégrées directement dans la logique du contrôleur et des services, notamment la construction des références, la détection des catégories et les règles de taxe. Elles fonctionnent, mais elles méritent d’être isolées dans des classes métier dédiées ou des options de configuration plus explicites.

### 6.2 Renforcer le contrôle des données saisies côté serveur
Le module lit des valeurs POST, des champs de fichiers et des URLs distantes. Il serait utile d’introduire une validation plus stricte des champs métier avant exécution, avec un niveau de cohérence supervisé entre l’analyse, la création et l’enrichissement.

### 6.3 Clarifier l’expérience utilisateur autour des erreurs
Le tableau de bord et les messages existants sont utiles, mais une expérience plus robuste pourrait inclure des codes d’erreur, des conseils d’action et une meilleure séparation entre les avertissements, les erreurs bloquantes et les warnings non bloquants.

## 7. Sécurité

### Points positifs
- Le module présente une barrière initiale contre les URL non autorisées via le garde-fou des URLs et le client HTTP.
- Les uploads d’images sont vérifiés côté serveur avec des limites de taille et un contrôle du type de fichier.

### Risques à surveiller
- Le module traite des contenus distants et stocke des données sensibles en base sous forme de JSON. Une mauvaise évolution de la logique ou une injection de contenu mal formé pourrait devenir problématique si l’architecture n’est pas sécurisée par des tests et des contrôles unitaires.
- Les flux sensibles sont déclenchés par des formulaires back-office. Il serait prudent d’ajouter une validation explicite des tokens et un contrôle plus visible des permissions sur chaque action critique.

## 8. Performance

### Observations
- La logique de détection de doublons effectue des opérations de recherche textuelle sur des tables de catalogue potentielles sans mécanisme de cache ou d’index de recherche.
- Les statistiques du tableau de bord reposent sur des requêtes de comptage sur la table de jobs sans index dédié sur les colonnes de date.
- Le stockage du payload complet en JSON peut devenir coûteux en espace et en temps de lecture au fil de la croissance de l’historique.

### Recommandation
Prioriser l’optimisation sur les deux points suivants :
1. Les requêtes de doublons.
2. Les requêtes d’historique et de statistiques.

## 9. Migrations et évolution de schéma

### État actuel
- Le module crée les tables de jobs et d’images à l’installation via [rfproductfactory/rfproductfactory.php](../rfproductfactory.php).
- Aucune migration spécifique dédiée à la version 0.4.0 n’a été observée dans le dépôt, ce qui est acceptable tant que le schéma reste stable.

### Point de vigilance
Si le schéma évolue (ajout d’index, nouvelle table de logs, séparation du payload), il sera important d’introduire une vraie stratégie d’upgrade côté module, plutôt que de dépendre uniquement de l’installation.

## 10. Expérience utilisateur

### Points positifs
- Le module fournit un tableau de bord clair, des messages de statut et des flux de prévisualisation utiles.
- Les imports Excel et les doublons sont visibles avant création, ce qui réduit les erreurs de manipulation.

### Points d’amélioration
- L’interface pourrait mieux distinguer les erreurs bloquantes des avertissements non bloquants.
- Les messages d’erreurs pourraient être plus guidés et fournir des actions concrètes à l’utilisateur.
- Le tableau de bord pourrait évoluer vers une vue plus analytique avec des filtres par statut et par source.

## 11. Tests et gouvernance

### État observé
- Aucune preuve de tests automatisés ou de CI n’a été trouvée dans le dépôt.
- Les changements de version sont documentés dans [rfproductfactory/docs/CHANGELOG.md](CHANGELOG.md), mais l’accompagnement technique reste minimal.

### Recommandation
Ajouter un minimum de gouvernance technique :
- tests unitaires sur les services critiques,
- tests d’intégration autour des flux Excel et de création,
- exécution automatique dans un pipeline CI,
- conventions de versionnage et de revue de code.

## 12. Matrice de risque

| Risque | Impact | Probabilité | Niveau |
|---|---|---:|---|
| Régressions non détectées | Élevé | Élevée | Élevé |
| Dégradation des performances sur les doublons | Élevé | Moyenne | Élevé |
| Croissance de l’historique et ralentissement de la base | Moyen | Élevée | Élevé |
| Faible observabilité des échecs en production | Moyen | Moyenne | Moyen |

## 13. Recommandations prioritaires

1. Ajouter une suite de tests automatisés sur les services critiques et intégrer une CI simple.
2. Optimiser les requêtes de détection de doublons pour réduire le coût sur le catalogue.
3. Introduire une politique de rétention et des index adaptés sur la table des jobs.
4. Structurer davantage les logs et les diagnostics pour rendre l’exploitation plus robuste.

## 14. Feuille de route recommandée

### Court terme (1 à 2 semaines)
- Mettre en place des tests automatisés sur la logique de référence, de doublons et de validation Excel.
- Ajouter des index de base et une première politique de rétention des jobs anciens.

### Moyen terme (1 à 2 mois)
- Revoir la logique de détection de doublons pour limiter les scans de catalogue.
- Introduire un niveau de journalisation plus détaillé et des indicateurs de suivi.

### Moyen/long terme (2 à 4 mois)
- Évoluer vers une architecture plus modulaire et plus testable, avec séparation claire entre orchestration, règles métier et stockage.
- Préparer une stratégie d’upgrade de schéma et d’archivage des logs métier.

## 15. Conclusion

Le module RF Product Factory présente une base fonctionnelle solide et un niveau de sophistication technique déjà convaincant pour un module PrestaShop de gestion de catalogues. La principale faiblesse n’est pas l’absence de fonction, mais le manque de gouvernance technique autour de la robustesse, de la performance et de la traçabilité à grande échelle. Les améliorations prioritaires sont surtout de nature opérationnelle et architecturale : tests, performance des requêtes, gestion de l’historique et observabilité.
