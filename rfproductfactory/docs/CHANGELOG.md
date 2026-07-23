## 0.4.0

- Ajoute un nouveau tableau de bord d’activité dans le back-office avec statistiques du jour et des 20 derniers jobs.
- Expose les statistiques d’activité via le repository des jobs sans modifier les workflows d’analyse web ni d’import Excel.
- Ajoute un onglet dédié « Tableau de bord » dans la navigation du module.
- Conserve l’affichage des derniers jobs avec statut, produit associé, message d’erreur et lien direct vers la fiche PrestaShop quand elle existe.

## 0.3.2

- Présélectionne la catégorie PrestaShop « Réservation GW » pour les imports Excel lorsqu’elle existe.
- Mémorise la dernière catégorie choisie comme catégorie par défaut des prochains lots.
- Ajoute une recherche instantanée dans la liste des catégories pour retrouver rapidement une catégorie par nom.
- Désactive par défaut la suggestion automatique Race/System afin de conserver la catégorie choisie pour tout le lot.
- Ajoute dans la prévisualisation un sélecteur recherchable et un bouton pour appliquer une catégorie à toutes les lignes.

## 0.3.1

- Applique strictement la règle Rebel Forge : référence PrestaShop = `GW-` + `Product Code`.
- Rend la source et le préfixe de référence non modifiables dans l’interface d’import Excel.
- Conserve le SS Code comme information source séparée, sans l’utiliser comme référence produit.
- Rend la colonne Product Code obligatoire et signale clairement les lignes où elle manque.
- Vérifie les doublons en priorité sur la référence GW-Product Code, puis sur l’EAN.
- Empêche une modification manuelle de la référence lors de la création du lot en la recalculant côté serveur.

## 0.3.0

- Ajoute l’onglet « Import Excel / Copier-coller » pour créer jusqu’à 200 produits en lot.
- Reconnaît automatiquement les colonnes GW : date, système, gamme, SS Code, Product Code, description, EAN, poids et tarifs FRR/EUD/CHR/CHD.
- Prévisualise chaque ligne dans un tableau entièrement éditable avant création.
- Détecte les produits existants par EAN, référence PrestaShop et référence fournisseur.
- Utilise par défaut FRR comme prix de vente TTC et EUD comme prix d’achat HT.
- Applique automatiquement la taxe livres aux EAN 978/979, aux codes douaniers 4901 et aux produits Black Library.
- Suggère les catégories existantes à partir des colonnes Race et System, avec une catégorie de secours modifiable.
- Enregistre la date de sortie, le poids, le fabricant, le fournisseur et la référence fournisseur.
- Crée les produits hors ligne par défaut et revérifie les doublons juste avant la création.

## 0.2.7

- Sélecteur de produit avec recherche instantanée par nom, référence, EAN ou identifiant.
- Suppression de la saisie obligatoire d’un identifiant brut pour l’ajout d’images.
- Zone de dépôt plus conviviale : sélection, glisser-déposer et collage depuis le presse-papiers.
- Bouton de téléchargement groupé des images distantes détectées, sous réserve des autorisations du navigateur.
- Présentation du produit sélectionné avant l’envoi des images.

## 0.2.6
- Ajoute un import direct d’images locales vers une fiche existante sans relancer l’analyse.
- Réduit les erreurs Novalis répétées à un seul avertissement lorsque le domaine bloque les images distantes.
- Empêche les tentatives distantes connues comme bloquées et guide vers le téléversement local.

## 0.2.5

- Confirme le cas où le fournisseur bloque les téléchargements d’images depuis l’adresse IP du serveur PrestaShop.
- Ajoute un import manuel multi-images depuis l’ordinateur lors de la création ou de l’enrichissement.
- Accepte jusqu’à 8 images de 8 Mo chacune en JPEG, PNG, GIF, WebP ou AVIF selon le support PHP/GD.
- Le premier fichier local devient la couverture si le produit n’en possède pas encore.
- Ajoute une option, activée par défaut, pour ne pas retenter les URL distantes lorsque des fichiers locaux sont fournis.
- Ajoute un bouton « Ouvrir / enregistrer » sous chaque image distante.
- Affiche le nombre d’images réellement ajoutées après un enrichissement.

# Changelog

## 0.2.4

- Corrige l'enrichissement des images : le contrôle anti-doublon utilise désormais le profil HTTP image avec le référent de la fiche source.
- Une impossibilité de comparer une image distante ne bloque plus son import ; l'importeur tente directement le téléchargement et remonte l'erreur réelle si nécessaire.
- Aucun changement de base de données.

## 0.2.3

- Corrige l’import des images provenant de pages analysées manuellement : le serveur PrestaShop transmet désormais le référent de la fiche produit lors du téléchargement.
- Utilise un profil HTTP spécifique aux images au lieu d’un profil de navigation HTML.
- Pour Novalis, privilégie automatiquement la variante `/large/` lorsqu’une miniature `/small/` est détectée.
- Normalise les URL cochées avant comparaison avec les URL autorisées, afin d’éviter une sélection vide à cause des entités HTML ou de la casse du domaine.
- Affiche après création le nombre d’images sélectionnées, importées et le détail exact des erreurs éventuelles.

## 0.2.2

- Récupère les références affichées près du nom du produit, notamment les libellés « N° de l’Article », « Référence », « SKU » et « Code produit ».
- Récupère les EAN/GTIN présents dans les tableaux ou blocs de spécifications.
- Convertit correctement les UPC-A à 12 chiffres en EAN-13 en ajoutant le préfixe 0 et vérifie la clé de contrôle.
- Affiche systématiquement le résultat de la comparaison avec le catalogue PrestaShop, même lorsqu’aucun doublon n’est trouvé.
- Ajoute le prix d’achat HT, distinct du prix de vente, et l’enregistre dans `wholesale_price`.
- Enregistre aussi la référence et le prix d’achat dans la fiche `ProductSupplier` lorsqu’un fournisseur est sélectionné.
- Présélectionne Novalis ou Asmodee si un fournisseur correspondant existe dans PrestaShop.
- Permet d’enrichir une fiche existante avec le prix d’achat HT.

## 0.2.1

- Ajout d’un mode manuel sécurisé pour les sites publics qui refusent les requêtes serveur avec HTTP 403.
- Analyse possible par collage du code source HTML ou envoi d’un fichier `.html`, `.htm` ou `.txt` de 5 Mo maximum.
- Le lien d’origine reste obligatoire afin de résoudre correctement les images et de conserver la traçabilité.
- Le code HTML brut n’est pas enregistré dans la base de données.

## 0.2.0

- Corrige les erreurs HTTP 403 de sites publics qui bloquent le User-Agent technique du module.
- Utilise des en-têtes de navigateur standards, compatibles avec les pages HTML et les images.
- En cas de 403, initialise une session sur l’accueil du même domaine puis réessaie une seule fois avec les cookies et le référent attendus.
- Isole les cookies par domaine et ne les transmet jamais lors d’une redirection vers un autre hôte.
- Conserve toutes les protections SSRF existantes et n’essaie jamais de contourner un CAPTCHA ou un espace privé.
- Améliore le message d’erreur si le serveur distant continue à refuser l’adresse IP de l’hébergement.

## 0.1.9

- Corrige l’incohérence visuelle entre le choix En ligne/Hors ligne et le texte du bouton de création.
- Le bouton affiche désormais toujours « Créer le produit » : le statut est déterminé uniquement par le choix explicite situé au-dessus.
- Ajoute un résumé dynamique et lisible du statut qui sera réellement appliqué.
- Supprime le verrou JavaScript global qui pouvait empêcher la réinitialisation de l’interface après une nouvelle analyse.
- Corrige le message de confirmation après création pour indiquer correctement si le produit a été créé en ligne ou hors ligne.

## 0.1.8

- Corrige les faux positifs de références : `SWQ15` n’est plus considéré comme identique à `SWQ154`.
- Conserve la détection des références réellement enveloppées par un code fournisseur, comme `SWQ129` et `AMGSWQ129ML`.
- N’accepte une référence incluse que lorsque les caractères ajoutés autour sont alphabétiques, jamais lorsqu’il s’agit d’une prolongation numérique.
- Ne présélectionne plus automatiquement l’enrichissement d’une fiche existante.
- Ajoute le choix explicite « Ne pas enrichir — créer ce produit comme une nouvelle fiche ».
- Désactive le bouton d’enrichissement tant qu’une vraie fiche existante n’a pas été volontairement choisie.
- Clarifie que PrestaShop reste la boutique cible ; les particularités techniques éventuelles concernent uniquement la page web source analysée.

## 0.1.7

- Contraint l’aperçu des images à des cartes de 160 × 160 px, y compris si un ancien CSS reste en cache.
- Ajoute une protection inline contre les images distantes qui imposent leurs dimensions réelles.
- Déduplique les images servies sous plusieurs domaines ou variantes Shopify.
- Compare le contenu téléchargé par SHA-1 et dHash pour écarter une même image redimensionnée ou recompressée.
- Conserve de préférence la variante ayant la meilleure définition.
- Affiche le nombre d’images distinctes et le nombre de doublons supprimés.

## 0.1.6

- Demande explicitement si la nouvelle fiche doit être créée en ligne ou hors ligne.
- Coche toujours « Disponible à la vente » et règle la visibilité sur « Partout ».
- Propose aussi de corriger ces réglages lors de l’enrichissement d’une ancienne fiche.
- Conserve le mode hors ligne comme choix prudent par défaut.
- Récupère les images secondaires des galeries produit, notamment sur Shopify.
- Lit les attributs `srcset`, `data-src`, zoom et les scripts JSON produit.
- Déduplique les variantes de taille d’une même image avant import.
- Affiche un libellé de création adapté au statut choisi.


## 0.1.5

- Corrige le bouton « Enrichir la fiche sélectionnée » pouvant rester désactivé.
- Présélectionne côté serveur la première fiche enrichissable.
- Affiche les options d’enrichissement sans dépendre uniquement du JavaScript.
- Ajoute un cache-busting sur les fichiers CSS et JavaScript du back-office.
- Ajoute une vérification de secours au clic sur le bouton d’enrichissement.

## 0.1.4

- Ajoute un mode d’enrichissement des fiches existantes détectées comme doublons.
- Compare les valeurs actuelles et les données récupérées champ par champ.
- Présélectionne uniquement les compléments sûrs lorsque le champ existant est vide.
- Permet une mise à jour volontaire du nom, de la référence, de l’EAN, du prix, du SEO, du fabricant et du fournisseur.
- Fusionne les blocs de texte nouveaux sans remplacer automatiquement la description existante.
- Compare les images distantes à toutes les images du produit et ignore les doublons visuels.
- Ajoute les nouvelles images à la suite sans écraser la couverture existante.
- Enregistre les opérations d’enrichissement dans l’historique.

## 0.1.3

- Détecte les références incluses dans des références plus longues, comme `SWQ129` et `AMGSWQ129ML`.
- Normalise les noms pour comparer accents, ponctuation et pluriels.
- Ajoute une comparaison perceptuelle des images de couverture avec indexation progressive du catalogue.
- Affiche la couverture, la référence et l’EAN de chaque produit potentiellement existant.
- Bloque aussi la création pour les correspondances fortes de nom ou d’image.

## 0.1.2

- Renforce la détection des doublons par référence et EAN exacts.
- Recherche également dans les références et EAN des déclinaisons.
- Affiche la raison de la correspondance et un lien direct vers la fiche existante.
- Bloque le bouton de création tant que le forçage explicite n’est pas confirmé.

## 0.1.1

- Corrige les images blanches lors de l’import de sources WebP/AVIF.
- Vérifie la création effective du fichier principal et améliore les messages d’erreur.

## 0.1.0

- Première version installable.
- Analyse URL, aperçu, doublons, images et création hors ligne.
