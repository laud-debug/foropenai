# RF-UX-MEGAMENU - Cartographie et gouvernance

Date : 2026-07-23

## Regle directrice

Ce document encadre les evolutions futures du programme RF-UX-MEGAMENU. Il ne modifie aucun menu, media, CSS, template, cache ou export JSON.

Toute correction structurelle, tout prototype et toute regle CSS doit etre active explicitement par une liste blanche de menus. Une regle structurelle doit commencer par le selecteur du menu autorise, par exemple :

```css
#adtm_menu .advtm_menu_1 { }
```

Les selecteurs globaux suivants sont interdits pour une nouvelle refonte :

```css
#adtm_menu .adtm_sub
#adtm_menu .columnWrapTable
#adtm_menu .adtm_column
```

Le fichier [custom.css](../../themes/warehouse/assets/css/custom.css) respecte actuellement cette contrainte : son unique correctif structurel RF-UX cible `.advtm_menu_1`; il ne contient pas de regle nouvelle pour `.advtm_menu_9` ni de selecteur structurel generique de megamenu.

## Menu protege : Jeux de societe

Verification faite depuis `Audit/export (1).json` :

| Libelle | id_menu | Classe | Wraps | Colonnes | Elements | Statut |
| --- | ---: | --- | ---: | ---: | ---: | --- |
| Jeux de societe | `9` | `.advtm_menu_9` | 4 | 4 | 28 | **PROTEGE** |

Le menu Jeux de societe est la reference visuelle existante. Il doit rester strictement inchange :

- aucune configuration PM Advanced Top Menu ;
- aucun wrap, colonne, element, lien, media ou contenu RTE ;
- aucune regle CSS RF-UX ;
- aucun placeholder ;
- aucun import JSON.

Une evolution est envisageable seulement avec une maquette dediee, une comparaison avant/apres, une qualite au moins equivalente et une validation explicite.

## Cartographie complete issue de l'export

`Medias` recense les fichiers d'icone declares par l'export. `Absents` signifie que le fichier attendu est introuvable localement selon la convention du module `{menu|column|element}_icons/{id}-fr.{ext}`. Les URLs ne sont pas verifiees en HTTP dans cet audit ; la colonne `Liens suspects` recense uniquement les incoherences demonstrables dans les donnees exportees.

| Libelle | id_menu | Classe | Wraps | Colonnes | Elements | Types de contenu | Medias presents | Medias absents | Liens suspects | Etat responsive | Statut |
| --- | ---: | --- | ---: | ---: | ---: | --- | --- | --- | --- | --- | --- |
| Jeux de Figurines | 1 | `.advtm_menu_1` | 4 | 4 | 22 | menu categorie; colonnes lien; elements lien/categorie/image | aucun | 9 elements : `12005`, `12007`, `12010-12012`, `12015-12016`, `12018-12019` en PNG | Horus Heresy `12004` : libelle vs slug `autres-gw` incoherents | breakpoint module `1024px`; correctif RF-UX-001 scope | A AUDITER, pilote propose |
| Ancien menu desactive | 3 | `.advtm_menu_3` | 7 | 18 | 51 | menu/colonnes/elements categorie | aucun | 3 colonnes (`372`, `373`, `375`) et 3 elements (`1572-1574`) PNG | aucun releve | non teste | A AUDITER |
| Cartes TCG | 4 | `.advtm_menu_4` | 4 | 4 | 20 | menu categorie; colonnes lien; elements lien/categorie | aucun | 15 elements PNG : `12023-12034`, `12040-12042` | aucun releve | non teste | A AUDITER |
| Peinture & Hobby | 5 | `.advtm_menu_5` | 4 | 4 | 14 | menu categorie; colonnes lien; elements lien/categorie | aucun configure | aucun | aucun releve | non teste | A AUDITER |
| Jeux de societe | 9 | `.advtm_menu_9` | 4 | 4 | 28 | menu categorie; colonnes lien; elements lien/categorie | aucun configure | aucun | aucun releve | reference existante; non modifiee | **PROTEGE** |
| Maquettes & Gunpla | 10 | `.advtm_menu_10` | 4 | 4 | 12 | menu categorie; colonnes lien; elements lien/categorie | aucun | 12 elements PNG : `12057-12068` | aucun releve | non teste | A AUDITER |
| VR Gaming | 12 | `.advtm_menu_12` | 4 | 4 | 8 | menu lien; colonnes lien; elements lien | aucun configure | aucun | aucun releve | non teste | A AUDITER |
| Livres | 13 | `.advtm_menu_13` | 4 | 4 | 7 | menu categorie; colonnes lien; elements categorie | aucun configure | aucun | aucun releve | non teste | A AUDITER |
| Collection & Goodies | 14 | `.advtm_menu_14` | 4 | 4 | 23 | menu categorie; colonnes lien; elements categorie | aucun | 2 elements PNG : `12102-12103` | aucun releve | non teste | A AUDITER |

Etat `A AUDITER` ne signifie pas defaut confirme. Il signifie que le menu ne peut pas recevoir de CSS, placeholder ou reorganisation avant son audit de phase A. Aucun menu n'est `PRET POUR PROTOTYPE` ou `VALIDE` a ce stade. Le seul pilote autorisable apres validation de ses preconditions est Jeux de Figurines (`.advtm_menu_1`).

## Liste blanche actuelle

| Menu | Selecteur autorisable | Niveau d'autorisation |
| --- | --- | --- |
| Jeux de Figurines | `#adtm_menu .advtm_menu_1` | audit realise; prototype uniquement apres validation explicite et sauvegarde |
| Jeux de societe | `#adtm_menu .advtm_menu_9` | interdit : protege |
| Tous les autres menus | selecteur specifique a definir apres audit | interdit avant audit et validation |

## Systeme graphique reutilisable

Les classes suivantes sont autorisees seulement sur les nouveaux blocs HTML RTE des menus mis sur liste blanche :

```text
.rf-megamenu
.rf-megamenu-heading
.rf-megamenu-link
.rf-megamenu-logo
.rf-megamenu-icon
.rf-megamenu-editorial
.rf-megamenu-card
.rf-megamenu-card-image
.rf-megamenu-card-content
.rf-megamenu-cta
```

Ces classes doivent rester inertes tant qu'aucun contenu RTE ne les utilise. Les regles structurelles de leur conteneur restent scopees au menu autorise, par exemple `#adtm_menu .advtm_menu_1 .rf-megamenu-card`. Elles ne doivent jamais etre ajoutees pour le menu protege `.advtm_menu_9`.

## Placeholders : regles de prototype

Pour un menu explicitement autorise en phase B, les medias temporaires doivent etre crees uniquement dans :

```text
themes/warehouse/assets/img/rf-megamenu/placeholders/{nom-du-menu}/
```

Formats indicatifs : logo `96x36`, pictogramme `40x40`, carte `150x120`, banniere `300x150` pixels. Chaque fichier temporaire doit etre visuellement identifiable (fond presque noir, bordure doree discrete, fonction et dimensions visibles), posseder un texte alternatif explicite et etre effectivement reference dans la configuration du menu ou dans son RTE. Aucun placeholder ne doit produire un faux lien.

Un placeholder est reserve a une nouvelle zone de prototype. Il est interdit de l'utiliser pour remplacer, dissimuler ou recrer les fichiers manquants existants du module, notamment pour `.advtm_menu_1` :

```text
12005-fr.png
12007-fr.png
12010-fr.png
12011-fr.png
12012-fr.png
12015-fr.png
12016-fr.png
12018-fr.png
12019-fr.png
```

Ces 404 doivent rester visibles jusqu'a une decision metier et a la livraison des medias originaux valides. Le menu Jeux de societe ne recoit aucun placeholder.

Les emplacements de prototype doivent garder proportions, dimensions d'affichage et structure HTML/CSS lors du remplacement par les medias definitifs. La correspondance nom temporaire -> media definitif doit etre documentee.

## Methode obligatoire menu par menu

### Phase A - Audit

Analyser la structure, inventorier contenu, URLs et medias, proposer l'organisation, puis fournir une maquette ou un schema. Aucun changement de configuration, CSS ou media.

### Phase B - Prototype

Apres validation explicite : creer les placeholders dans le sous-dossier dedie, les referencer effectivement sur le front-office, appliquer uniquement le CSS scope au menu autorise et conserver les liens existants. Aucun import JSON.

### Phase C - Validation

Tester `1440`, `1280`, `1025`, `1024`, `992`, `768` et `375px`, ainsi que hover, clic, clavier, liens `<a>` et `span[data-href]`. Verifier l'absence d'impact sur tous les autres menus et comparer au menu Jeux de societe sans le modifier.

### Phase D - Finalisation

Remplacer les placeholders par les medias definitifs, ajouter uniquement les URLs validees, exporter une sauvegarde du menu final et produire le rapport de validation.

## Sauvegardes et retour arriere

Avant chaque menu autorise :

1. Exporter sa configuration PM Advanced Top Menu.
2. Sauvegarder `themes/warehouse/assets/css/custom.css`.
3. Sauvegarder les contenus RTE concernes.
4. Capturer le rendu desktop et mobile avant modification.
5. Archiver les medias du menu avec hash et horodatage.

Chaque menu doit etre restaurable independamment : restaurer sa configuration exportee, son contenu RTE, ses medias et le bloc CSS scope concerne, puis vider Smarty/CCC. Ne jamais utiliser les caches compiles comme source de restauration.

## Interdictions permanentes

- ne pas modifier le coeur PrestaShop ;
- ne pas modifier directement PHP, Smarty ou JavaScript du module ;
- ne pas modifier `pm_advancedtopmenu_advanced-1.css` ;
- ne pas modifier `rfproductfactory` ;
- ne pas modifier les caches compiles ;
- ne pas importer un JSON non valide ;
- ne pas inventer d'URL ni ecrire de prix en dur ;
- ne pas refondre plusieurs menus simultanement ;
- ne pas toucher au menu Jeux de societe sans validation explicite.