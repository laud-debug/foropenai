<div class="rfpf-wrapper">
  <ul class="nav nav-tabs rfpf-main-tabs">
    <li class="active"><a href="{$rfpf_web_url|escape:'htmlall':'UTF-8'}"><i class="icon-link"></i> Analyse web</a></li>
    <li><a href="{$rfpf_excel_url|escape:'htmlall':'UTF-8'}"><i class="icon-table"></i> Import Excel / Copier-coller</a></li>
    <li><a href="{$rfpf_dashboard_url|escape:'htmlall':'UTF-8'}"><i class="icon-dashboard"></i> Tableau de bord</a></li>
  </ul>
  <div class="panel">
    <div class="panel-heading"><i class="icon-link"></i> Analyser une nouvelle fiche produit</div>
    <form method="post" action="{$rfpf_action|escape:'htmlall':'UTF-8'}" class="form-horizontal">
      <div class="form-group">
        <label class="control-label col-lg-2 required">Lien de la page</label>
        <div class="col-lg-8">
          <input type="url" name="source_url" required placeholder="https://..." value="{if $rfpf_preview}{$rfpf_preview.source_url|escape:'htmlall':'UTF-8'}{elseif $rfpf_manual_source_url}{$rfpf_manual_source_url|escape:'htmlall':'UTF-8'}{/if}">
          <p class="help-block">Page publique du fabricant ou du fournisseur. Les URL internes et les réseaux privés sont bloqués.</p>
        </div>
        <div class="col-lg-2">
          <button type="submit" name="submitRfpfAnalyze" class="btn btn-primary">
            <i class="icon-search"></i> Analyser
          </button>
        </div>
      </div>
    </form>
  </div>

  <div class="panel rfpf-manual-panel{if $rfpf_manual_fallback_suggested} rfpf-manual-panel-open{/if}">
    <div class="panel-heading">
      <i class="icon-paste"></i> Site bloquant : analyser le code source manuellement
      <button type="button" id="rfpf-manual-toggle" class="btn btn-default btn-xs pull-right" aria-expanded="{if $rfpf_manual_fallback_suggested}true{else}false{/if}">
        {if $rfpf_manual_fallback_suggested}Masquer{else}Afficher{/if}
      </button>
    </div>
    <div id="rfpf-manual-body"{if !$rfpf_manual_fallback_suggested} style="display:none"{/if}>
    <p>
      Utilisez ce mode uniquement lorsqu’un fournisseur renvoie une erreur HTTP 403. Le module analyse le HTML fourni sans demander la page au site distant.
    </p>
    <ol class="rfpf-manual-steps">
      <li>Ouvrez la fiche produit dans votre navigateur.</li>
      <li>Appuyez sur <strong>Ctrl + U</strong> pour afficher le code source, puis <strong>Ctrl + A</strong> et <strong>Ctrl + C</strong>.</li>
      <li>Collez le code complet ci-dessous, ou enregistrez la page source et envoyez le fichier HTML.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" action="{$rfpf_action|escape:'htmlall':'UTF-8'}" class="form-horizontal">
      <div class="form-group">
        <label class="control-label col-lg-2 required">Lien d’origine</label>
        <div class="col-lg-10">
          <input type="url" name="manual_source_url" required placeholder="https://..." value="{$rfpf_manual_source_url|escape:'htmlall':'UTF-8'}">
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-2">Code source HTML</label>
        <div class="col-lg-10">
          <textarea name="source_html" rows="9" placeholder="Collez ici le code source complet de la page…"></textarea>
          <p class="help-block">Le HTML est utilisé uniquement pendant cette analyse et n’est pas enregistré tel quel dans la base.</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-2">Ou fichier HTML</label>
        <div class="col-lg-7">
          <input type="file" name="source_html_file" accept=".html,.htm,.txt,text/html,text/plain">
          <p class="help-block">Taille maximale : 5 Mo.</p>
        </div>
        <div class="col-lg-3 text-right">
          <button type="submit" name="submitRfpfAnalyzeHtml" class="btn btn-warning">
            <i class="icon-search"></i> Analyser le HTML
          </button>
        </div>
      </div>
    </form>
    </div>
  </div>

  <div class="panel rfpf-index-panel">
    <div class="panel-heading"><i class="icon-picture"></i> Index des images de couverture</div>
    <div class="row">
      <div class="col-lg-9">
        <p>La recherche visuelle compare l’image de la nouvelle fiche avec les couvertures déjà présentes dans PrestaShop.</p>
        <div class="progress rfpf-index-progress">
          <div id="rfpf-index-progress-bar" class="progress-bar" role="progressbar" style="width: {$rfpf_image_index_stats.percent|intval}%">
            <span id="rfpf-index-percent">{$rfpf_image_index_stats.percent|intval}%</span>
          </div>
        </div>
        <p id="rfpf-index-status" class="help-block">
          <strong id="rfpf-indexed-count">{$rfpf_image_index_stats.indexed|intval}</strong> /
          <strong id="rfpf-total-count">{$rfpf_image_index_stats.total|intval}</strong> image(s) de couverture indexée(s).
        </p>
      </div>
      <div class="col-lg-3 text-right">
        <button type="button" id="rfpf-index-images" class="btn btn-default" data-url="{$rfpf_image_index_url|escape:'htmlall':'UTF-8'}" {if !$rfpf_image_index_stats.remaining}disabled{/if}>
          <i class="icon-refresh"></i> <span>Indexer le catalogue</span>
        </button>
      </div>
    </div>
  </div>

  {if $rfpf_success}
    <div class="panel rfpf-success-card">
      {if isset($rfpf_success.action) && $rfpf_success.action == 'local_images_uploaded'}
        <div class="panel-heading"><i class="icon-picture"></i> Images locales ajoutées</div>
        <p><strong>{$rfpf_success.images_imported|default:0|intval}</strong> image(s) ont été ajoutée(s) au produit <strong>#{$rfpf_success.id_product|intval}</strong>.</p>
        {if !empty($rfpf_success.image_errors)}
          <div class="alert alert-warning">
            <strong>Certaines images n’ont pas pu être importées :</strong>
            <ul>{foreach from=$rfpf_success.image_errors item=imageError}<li>{$imageError|escape:'htmlall':'UTF-8'}</li>{/foreach}</ul>
          </div>
        {/if}
      {elseif isset($rfpf_success.action) && $rfpf_success.action == 'enriched'}
        <div class="panel-heading"><i class="icon-check"></i> Fiche existante enrichie</div>
        <p>Le produit <strong>#{$rfpf_success.id_product|intval}</strong> a été complété avec les éléments sélectionnés.</p>
        {if !empty($rfpf_success.changes)}
          <ul>{foreach from=$rfpf_success.changes item=change}<li>{$change|escape:'htmlall':'UTF-8'}</li>{/foreach}</ul>
        {/if}
        <p><strong>Images ajoutées :</strong> {$rfpf_success.images_imported|default:0|intval}</p>
        {if !empty($rfpf_success.image_errors)}
          <div class="alert alert-warning">
            <strong>Certaines images n’ont pas pu être importées :</strong>
            <ul>{foreach from=$rfpf_success.image_errors item=imageError}<li>{$imageError|escape:'htmlall':'UTF-8'}</li>{/foreach}</ul>
          </div>
        {/if}
      {else}
        <div class="panel-heading"><i class="icon-check"></i> Produit créé</div>
        <p>
          Le produit <strong>#{$rfpf_success.id_product|intval}</strong> a été créé
          {if isset($rfpf_success.publication_status) && $rfpf_success.publication_status == 'online'}
            <strong>en ligne immédiatement</strong>
          {else}
            <strong>hors ligne</strong>
          {/if}, disponible à la vente, visible partout et avec une quantité de 0.
        </p>
        <p>
          <strong>Images :</strong> {$rfpf_success.images_imported|default:0|intval} image(s) importée(s)
          {if isset($rfpf_success.images_requested)}sur {$rfpf_success.images_requested|intval} sélectionnée(s){/if}.
        </p>
        {if !empty($rfpf_success.image_errors)}
          <div class="alert alert-warning">
            <strong>Certaines images n’ont pas pu être importées :</strong>
            <ul>{foreach from=$rfpf_success.image_errors item=imageError}<li>{$imageError|escape:'htmlall':'UTF-8'}</li>{/foreach}</ul>
          </div>
        {/if}
      {/if}
      <a class="btn btn-default" href="{if !empty($rfpf_success.edit_url)}{$rfpf_success.edit_url|escape:'htmlall':'UTF-8'}{else}{$rfpf_products_link|escape:'htmlall':'UTF-8'}{/if}">
        <i class="icon-external-link"></i> Ouvrir la fiche produit
      </a>
      {if !empty($rfpf_success.id_product)}
        <hr>
        <form method="post" enctype="multipart/form-data" action="{$rfpf_action|escape:'htmlall':'UTF-8'}" class="form-inline rfpf-direct-upload-form">
          <input type="hidden" name="upload_product_id" value="{$rfpf_success.id_product|intval}">
          <label><strong>Ajouter maintenant des images depuis l’ordinateur :</strong></label>
          <input type="file" name="direct_local_images[]" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple required>
          <button type="submit" name="submitRfpfUploadLocalImages" value="1" class="btn btn-primary">
            <i class="icon-upload"></i> Ajouter les images
          </button>
          <p class="help-block">Cette action envoie directement les fichiers à PrestaShop et ne contacte pas Novalis.</p>
        </form>
      {/if}
    </div>
  {/if}

  {if $rfpf_preview}
    <form method="post" enctype="multipart/form-data" action="{$rfpf_action|escape:'htmlall':'UTF-8'}" class="form-horizontal" id="rfpf-create-form">
      <input type="hidden" name="id_job" value="{$rfpf_preview.id_job|intval}">
      <div class="panel">
        <div class="panel-heading"><i class="icon-edit"></i> Vérifier, enrichir ou créer la fiche</div>

        {if !empty($rfpf_preview.warnings)}
          <div class="alert alert-warning">
            <ul>{foreach from=$rfpf_preview.warnings item=warning}<li>{$warning|escape:'htmlall':'UTF-8'}</li>{/foreach}</ul>
          </div>
        {/if}


        <div class="alert alert-info rfpf-comparison-summary">
          <strong><i class="icon-search"></i> Comparaison avec le catalogue PrestaShop exécutée</strong>
          <p class="help-block">
            Critères utilisés :
            référence <strong>{if $rfpf_preview.reference}{$rfpf_preview.reference|escape:'htmlall':'UTF-8'}{else}non détectée{/if}</strong>,
            EAN <strong>{if $rfpf_preview.ean13}{$rfpf_preview.ean13|escape:'htmlall':'UTF-8'}{else}non détecté{/if}</strong>,
            nom et {$rfpf_preview.image_count|intval} image(s).
            La référence, l’EAN et le nom sont comparés à tout le catalogue ; la recherche visuelle couvre actuellement
            <strong>{$rfpf_preview.image_index_stats.indexed|intval} / {$rfpf_preview.image_index_stats.total|intval}</strong> couverture(s).
          </p>
          {if !$rfpf_duplicates}
            <p class="text-success"><i class="icon-check"></i> Aucune fiche existante correspondante n’a été trouvée avec les critères disponibles.</p>
          {/if}
        </div>

        {if $rfpf_duplicates}
          <div class="alert {if $rfpf_has_strong_duplicate}alert-danger{else}alert-warning{/if} rfpf-duplicate-alert">
            <strong>
              {if $rfpf_has_strong_duplicate}
                Produit existant ou très similaire détecté :
              {else}
                Produit potentiellement similaire à vérifier :
              {/if}
            </strong>
            <p class="help-block"><strong>Cette zone est une suggestion de contrôle.</strong> Elle ne modifie aucune fiche tant que vous ne choisissez pas volontairement un produit à enrichir.</p>
            <div class="rfpf-enrich-choice rfpf-no-enrich-choice">
              <label>
                <input type="radio" class="rfpf-enrich-target" name="enrich_product_id" value="0" {if empty($rfpf_preview.selected_enrich_product_id)}checked{/if}>
                <strong>Ne pas enrichir — créer ce produit comme une nouvelle fiche</strong>
              </label>
            </div>
            <div class="rfpf-duplicate-list">
              {foreach from=$rfpf_duplicates item=duplicate}
                <div class="rfpf-duplicate-item">
                  <div class="rfpf-duplicate-cover">
                    {if !empty($duplicate.cover_url)}
                      <img src="{$duplicate.cover_url|escape:'htmlall':'UTF-8'}" alt="Couverture de {$duplicate.name|escape:'htmlall':'UTF-8'}" loading="lazy">
                    {else}
                      <span class="rfpf-no-cover"><i class="icon-picture"></i><small>Sans image</small></span>
                    {/if}
                  </div>
                  <div class="rfpf-duplicate-content">
                    <div>
                      <strong>#{$duplicate.id_product|intval} — {$duplicate.name|escape:'htmlall':'UTF-8'}</strong>
                      {if $duplicate.id_product_attribute}
                        <span class="label label-default">Déclinaison #{$duplicate.id_product_attribute|intval}</span>
                      {/if}
                      {if $duplicate.active}
                        <span class="label label-success">Actif</span>
                      {else}
                        <span class="label label-default">Inactif</span>
                      {/if}
                    </div>
                    <div class="rfpf-existing-identifiers">
                      {if !empty($duplicate.product_reference)}<span>Réf. produit : <strong>{$duplicate.product_reference|escape:'htmlall':'UTF-8'}</strong></span>{/if}
                      {if !empty($duplicate.combination_reference)}<span>Réf. déclinaison : <strong>{$duplicate.combination_reference|escape:'htmlall':'UTF-8'}</strong></span>{/if}
                      {if !empty($duplicate.product_ean13)}<span>EAN : <strong>{$duplicate.product_ean13|escape:'htmlall':'UTF-8'}</strong></span>{/if}
                      {if !empty($duplicate.combination_ean13)}<span>EAN déclinaison : <strong>{$duplicate.combination_ean13|escape:'htmlall':'UTF-8'}</strong></span>{/if}
                    </div>
                    <div class="rfpf-match-labels">
                      {foreach from=$duplicate.match_labels item=matchLabel}
                        <span class="label {if $duplicate.strong_match}label-danger{else}label-warning{/if}">{$matchLabel|escape:'htmlall':'UTF-8'}</span>
                      {/foreach}
                    </div>
                    <a href="{$duplicate.edit_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                      <i class="icon-external-link"></i> Ouvrir la fiche existante
                    </a>
                    {if !empty($duplicate.enrichment.has_options)}
                      <div class="rfpf-enrich-choice">
                        <label>
                          <input type="radio" class="rfpf-enrich-target" name="enrich_product_id" value="{$duplicate.id_product|intval}" {if !empty($rfpf_preview.selected_enrich_product_id) && $rfpf_preview.selected_enrich_product_id == $duplicate.id_product}checked{/if}>
                          <strong>Choisir cette fiche pour l’enrichir</strong>
                        </label>
                      </div>
                      <div class="rfpf-enrichment-options{if !empty($rfpf_preview.selected_enrich_product_id) && $rfpf_preview.selected_enrich_product_id == $duplicate.id_product} is-active{/if}" data-product-id="{$duplicate.id_product|intval}">
                        <p class="help-block">Aucun champ n’est modifié sans case cochée. Les images identiques ou visuellement équivalentes seront ignorées automatiquement.</p>
                        {foreach from=$duplicate.enrichment.options item=enrichOption}
                          <label class="rfpf-enrichment-option">
                            <input type="checkbox" name="enrich_fields[{$duplicate.id_product|intval}][]" value="{$enrichOption.key|escape:'htmlall':'UTF-8'}" {if !empty($rfpf_preview.selected_enrich_product_id) && $rfpf_preview.selected_enrich_product_id == $duplicate.id_product && isset($rfpf_preview.selected_enrichment_fields)}{if $enrichOption.key|in_array:$rfpf_preview.selected_enrichment_fields}checked{/if}{elseif $enrichOption.default}checked{/if}>
                            <span>
                              <strong>{$enrichOption.label|escape:'htmlall':'UTF-8'}</strong>
                              <small><b>Actuel :</b> {$enrichOption.current|escape:'htmlall':'UTF-8'}</small>
                              <small><b>Nouveau :</b> {$enrichOption.incoming|escape:'htmlall':'UTF-8'}</small>
                            </span>
                          </label>
                        {/foreach}
                      </div>
                    {/if}
                  </div>
                </div>
              {/foreach}
            </div>
            {if $rfpf_has_strong_duplicate}
              <label class="rfpf-force-duplicate">
                <input type="checkbox" id="rfpf-confirm-duplicate" name="confirm_duplicate" value="1" {if !empty($rfpf_preview.confirm_duplicate)}checked{/if}>
                Je confirme exceptionnellement vouloir créer un nouveau produit malgré cette correspondance forte.
              </label>
            {/if}
          </div>
        {/if}

        <div class="row">
          <div class="col-lg-8">
            <div class="form-group">
              <label class="control-label col-lg-3 required">Nom</label>
              <div class="col-lg-9"><input type="text" name="name" required maxlength="128" value="{$rfpf_preview.name|escape:'htmlall':'UTF-8'}"></div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3">Référence</label>
              <div class="col-lg-4"><input type="text" name="reference" maxlength="64" value="{$rfpf_preview.reference|escape:'htmlall':'UTF-8'}"></div>
              <label class="control-label col-lg-2">EAN-13</label>
              <div class="col-lg-3"><input type="text" name="ean13" maxlength="13" value="{$rfpf_preview.ean13|escape:'htmlall':'UTF-8'}"></div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3 required">Catégorie</label>
              <div class="col-lg-9">
                <select name="id_category_default" required>
                  {foreach from=$rfpf_categories item=category}
                    <option value="{$category.id_category|intval}" {if isset($rfpf_preview.id_category_default) && $category.id_category == $rfpf_preview.id_category_default}selected{/if}>{$category.indent|escape:'htmlall':'UTF-8'}{$category.name|escape:'htmlall':'UTF-8'}</option>
                  {/foreach}
                </select>
              </div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3">Fabricant</label>
              <div class="col-lg-4">
                <select name="id_manufacturer"><option value="0">— Aucun —</option>{foreach from=$rfpf_manufacturers item=manufacturer}<option value="{$manufacturer.id_manufacturer|intval}" {if isset($rfpf_preview.id_manufacturer) && $manufacturer.id_manufacturer == $rfpf_preview.id_manufacturer}selected{/if}>{$manufacturer.name|escape:'htmlall':'UTF-8'}</option>{/foreach}</select>
              </div>
              <label class="control-label col-lg-2">Fournisseur</label>
              <div class="col-lg-3">
                <select name="id_supplier"><option value="0">— Aucun —</option>{foreach from=$rfpf_suppliers item=supplier}<option value="{$supplier.id_supplier|intval}" {if isset($rfpf_preview.id_supplier) && $supplier.id_supplier == $rfpf_preview.id_supplier}selected{/if}>{$supplier.name|escape:'htmlall':'UTF-8'}</option>{/foreach}</select>
              </div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3">Prix public TTC détecté</label>
              <div class="col-lg-3"><div class="input-group"><input type="text" id="rfpf-price-ttc" value="{if isset($rfpf_preview.price_ttc)}{$rfpf_preview.price_ttc|escape:'htmlall':'UTF-8'}{/if}"><span class="input-group-addon">{$rfpf_currency_sign|escape:'htmlall':'UTF-8'}</span></div></div>
              <label class="control-label col-lg-2 required">Prix de vente HT</label>
              <div class="col-lg-4"><div class="input-group"><input type="text" name="price_ht" id="rfpf-price-ht" required value="{if isset($rfpf_preview.price_ht)}{$rfpf_preview.price_ht|escape:'htmlall':'UTF-8'}{/if}"><span class="input-group-addon">{$rfpf_currency_sign|escape:'htmlall':'UTF-8'}</span></div></div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3">Prix d’achat HT</label>
              <div class="col-lg-3">
                <div class="input-group"><input type="text" name="wholesale_price_ht" value="{if isset($rfpf_preview.wholesale_price_ht)}{$rfpf_preview.wholesale_price_ht|escape:'htmlall':'UTF-8'}{/if}"><span class="input-group-addon">{$rfpf_currency_sign|escape:'htmlall':'UTF-8'}</span></div>
              </div>
              <div class="col-lg-6">
                <p class="help-block">Enregistré dans le prix d’achat PrestaShop et, si un fournisseur est sélectionné, dans sa fiche fournisseur. Vérifiez toujours le montant détecté sur un portail B2B.</p>
              </div>
            </div>
            <div class="form-group">
              <label class="control-label col-lg-3 required">Règle de taxe</label>
              <div class="col-lg-9">
                <select name="id_tax_rules_group" id="rfpf-tax-group">
                  {foreach from=$rfpf_tax_groups item=taxGroup}
                    <option value="{$taxGroup.id_tax_rules_group|intval}" data-rate="{if isset($taxGroup.rate)}{$taxGroup.rate|escape:'htmlall':'UTF-8'}{else}0{/if}" {if isset($rfpf_preview.id_tax_rules_group) && $taxGroup.id_tax_rules_group == $rfpf_preview.id_tax_rules_group}selected{/if}>{$taxGroup.name|escape:'htmlall':'UTF-8'}{if isset($taxGroup.rate)} ({$taxGroup.rate|escape:'htmlall':'UTF-8'} %){/if}</option>
                  {/foreach}
                </select>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="well">
              <strong>Source</strong>
              <p class="rfpf-source-url"><a href="{$rfpf_preview.final_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$rfpf_preview.final_url|escape:'htmlall':'UTF-8'}</a></p>
              <p>Marque détectée : <strong>{if $rfpf_preview.brand}{$rfpf_preview.brand|escape:'htmlall':'UTF-8'}{else}non détectée{/if}</strong></p>
              <p>Devise détectée : <strong>{$rfpf_preview.currency|escape:'htmlall':'UTF-8'}</strong></p>
            </div>
          </div>
        </div>

        <hr>
        <div class="form-group">
          <label class="control-label col-lg-2">Description courte</label>
          <div class="col-lg-10"><textarea name="description_short" rows="4">{$rfpf_preview.description_short|escape:'htmlall':'UTF-8'}</textarea></div>
        </div>
        <div class="form-group">
          <label class="control-label col-lg-2">Description longue</label>
          <div class="col-lg-10"><textarea name="description" rows="14">{$rfpf_preview.description|escape:'htmlall':'UTF-8'}</textarea></div>
        </div>
        <div class="form-group">
          <label class="control-label col-lg-2">Titre SEO</label>
          <div class="col-lg-4"><input type="text" name="meta_title" maxlength="70" value="{$rfpf_preview.meta_title|escape:'htmlall':'UTF-8'}"></div>
          <label class="control-label col-lg-2">URL simplifiée</label>
          <div class="col-lg-4"><input type="text" name="link_rewrite" value="{$rfpf_preview.link_rewrite|escape:'htmlall':'UTF-8'}"></div>
        </div>
        <div class="form-group">
          <label class="control-label col-lg-2">Méta-description</label>
          <div class="col-lg-10"><input type="text" name="meta_description" maxlength="255" value="{$rfpf_preview.meta_description|escape:'htmlall':'UTF-8'}"></div>
        </div>

        <div class="form-group rfpf-publication-status">
          <label class="control-label col-lg-2 required">Mise en ligne</label>
          <div class="col-lg-10">
            <div class="btn-group" data-toggle="buttons">
              <label class="btn btn-default {if !isset($rfpf_preview.publication_status) || $rfpf_preview.publication_status == 'offline'}active{/if}">
                <input type="radio" name="publication_status" value="offline" required {if !isset($rfpf_preview.publication_status) || $rfpf_preview.publication_status == 'offline'}checked{/if}>
                <i class="icon-ban"></i> Hors ligne
              </label>
              <label class="btn btn-default {if isset($rfpf_preview.publication_status) && $rfpf_preview.publication_status == 'online'}active{/if}">
                <input type="radio" name="publication_status" value="online" required {if isset($rfpf_preview.publication_status) && $rfpf_preview.publication_status == 'online'}checked{/if}>
                <i class="icon-check"></i> En ligne immédiatement
              </label>
            </div>
            <p id="rfpf-publication-summary" class="help-block" data-offline-text="La fiche sera créée hors ligne, mais déjà configurée comme disponible à la vente et visible partout dès son activation." data-online-text="La fiche sera créée, activée et mise en ligne immédiatement, avec Disponible à la vente coché et une visibilité Partout.">
              {if isset($rfpf_preview.publication_status) && $rfpf_preview.publication_status == 'online'}
                <strong>Statut choisi :</strong> la fiche sera créée et mise en ligne immédiatement.
              {else}
                <strong>Statut choisi :</strong> la fiche sera créée hors ligne.
              {/if}
            </p>
            <p class="help-block">Dans les deux cas, <strong>Disponible à la vente</strong> et <strong>Afficher le prix</strong> seront cochés, avec une visibilité <strong>Partout</strong>. Ce choix concerne uniquement la création d’un nouveau produit.</p>
          </div>
        </div>

        <div class="form-group">
          <label class="control-label col-lg-2">Images à importer</label>
          <div class="col-lg-10">
            {if !empty($rfpf_preview.remote_images_blocked)}
              <div class="alert alert-warning">
                <strong>Images distantes bloquées par Novalis.</strong>
                Les cases d’import distant ne sont pas présélectionnées. Enregistrez les images dans votre ordinateur puis utilisez la zone d’import local ci-dessous.
              </div>
            {/if}
            {if $rfpf_preview.images}
              <p class="help-block rfpf-image-summary">
                <strong>{$rfpf_preview.image_count|intval}</strong> image(s) distincte(s) détectée(s).
                {if !empty($rfpf_preview.image_duplicates_removed)}
                  {$rfpf_preview.image_duplicates_removed|intval} doublon(s) de taille, d’URL ou de contenu ont été écarté(s).
                {/if}
              </p>
              <div class="rfpf-image-tools">
                <button type="button" class="btn btn-default rfpf-download-all-images">
                  <i class="icon-download"></i> Télécharger toutes les images détectées
                </button>
                <span class="help-block rfpf-download-help">Le navigateur peut demander l’autorisation pour plusieurs téléchargements. Les fichiers sont enregistrés dans votre dossier de téléchargement habituel.</span>
              </div>
              <div class="rfpf-images" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;">
                {foreach from=$rfpf_preview.images item=imageUrl name=images}
                  <label class="rfpf-image-card" style="display:block;width:180px!important;max-width:180px!important;min-width:180px!important;box-sizing:border-box;border:1px solid #dbe1e8;border-radius:4px;padding:8px;background:#fff;text-align:center;overflow:hidden;">
                    <span class="rfpf-image-frame" style="display:flex;width:160px!important;height:160px!important;max-width:160px!important;max-height:160px!important;align-items:center;justify-content:center;overflow:hidden;margin:0 auto 8px;background:#f7f7f7;">
                      <img src="{$imageUrl|escape:'htmlall':'UTF-8'}" alt="Aperçu distant" loading="lazy" width="160" height="160" style="display:block;width:160px!important;height:160px!important;max-width:160px!important;max-height:160px!important;object-fit:contain!important;margin:0!important;">
                    </span>
                    <span><input type="checkbox" name="image_urls[]" value="{$imageUrl|escape:'htmlall':'UTF-8'}" {if isset($rfpf_preview.selected_images)}{if $imageUrl|in_array:$rfpf_preview.selected_images}checked{/if}{elseif empty($rfpf_preview.remote_images_blocked) && $smarty.foreach.images.index < 4}checked{/if}> Importer à distance</span>
                    <a href="{$imageUrl|escape:'htmlall':'UTF-8'}" data-image-url="{$imageUrl|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer" download class="btn btn-default btn-xs rfpf-open-source-image"><i class="icon-download"></i> Télécharger / ouvrir</a>
                  </label>
                {/foreach}
              </div>
            {else}
              <p class="help-block">Aucune image n’a été détectée.</p>
            {/if}

            <div class="rfpf-local-image-upload">
              <h4><i class="icon-upload"></i> Importer depuis votre ordinateur</h4>
              <p class="help-block">
                Solution recommandée lorsqu’un fournisseur, comme Novalis, bloque l’adresse IP du serveur.
                Téléchargez les images avec le bouton <strong>Ouvrir / enregistrer</strong>, puis sélectionnez-les ici.
              </p>
              <label class="rfpf-file-dropzone" tabindex="0">
                <input type="file" name="local_images[]" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple>
                <span class="rfpf-file-dropzone-icon"><i class="icon-cloud-upload"></i></span>
                <strong>Glissez les images ici, cliquez pour les choisir ou collez-les avec Ctrl + V</strong>
                <small>Jusqu’à 8 images, 8 Mo maximum par fichier.</small>
              </label>
              <div class="rfpf-file-selection help-block">Aucun fichier sélectionné.</div>
              <p class="help-block">Le premier fichier devient la couverture si la fiche n’en possède pas encore.</p>
              <label class="rfpf-local-only-choice">
                <input type="checkbox" name="local_images_only" value="1" {if !empty($rfpf_preview.remote_images_blocked)}checked{/if}>
                Utiliser uniquement les fichiers locaux et ne pas retenter les images distantes.
              </label>
            </div>
          </div>
        </div>

        <div class="panel-footer rfpf-actions-footer">
          {if $rfpf_duplicates}
            <button type="submit" name="submitRfpfEnrich" value="1" id="rfpf-enrich-button" class="btn btn-warning" {if empty($rfpf_preview.selected_enrich_product_id) || $rfpf_preview.selected_enrich_product_id <= 0}disabled{/if}>
              <i class="process-icon-refresh"></i> Enrichir la fiche sélectionnée
            </button>
          {/if}
          <button type="submit" name="submitRfpfCreate" value="1" id="rfpf-create-button" class="btn btn-primary pull-right" {if $rfpf_has_strong_duplicate && empty($rfpf_preview.confirm_duplicate)}disabled{/if}>
            <i class="process-icon-save"></i> <span id="rfpf-create-label">Créer le produit</span>
          </button>
        </div>
      </div>
    </form>
  {/if}

  <div class="panel rfpf-direct-upload-panel">
    <div class="panel-heading"><i class="icon-picture"></i> Ajouter des images à une fiche existante</div>
    <p class="help-block">Recherchez le produit par son nom, sa référence, son EAN ou son identifiant, puis glissez ou collez les images. Aucun appel n’est envoyé au site fournisseur.</p>
    <form method="post" enctype="multipart/form-data" action="{$rfpf_action|escape:'htmlall':'UTF-8'}" class="rfpf-direct-upload-form rfpf-product-upload-form">
      <div class="rfpf-product-picker" data-search-url="{$rfpf_product_search_url|escape:'htmlall':'UTF-8'}">
        <label for="rfpf-product-search"><strong>Produit à compléter</strong></label>
        <div class="input-group">
          <span class="input-group-addon"><i class="icon-search"></i></span>
          <input type="text" id="rfpf-product-search" class="form-control rfpf-product-search-input" autocomplete="off" placeholder="Nom, référence, EAN ou ID…">
        </div>
        <input type="hidden" name="upload_product_id" class="rfpf-product-id" value="">
        <div class="rfpf-product-search-results" role="listbox" aria-label="Résultats de recherche"></div>
        <div class="rfpf-selected-product" style="display:none">
          <div class="rfpf-selected-product-cover"><i class="icon-picture"></i></div>
          <div class="rfpf-selected-product-info">
            <strong class="rfpf-selected-product-name"></strong>
            <span class="rfpf-selected-product-meta"></span>
          </div>
          <button type="button" class="btn btn-default btn-xs rfpf-clear-product"><i class="icon-times"></i> Changer</button>
        </div>
      </div>

      <div class="rfpf-upload-step">
        <label><strong>Images à ajouter</strong></label>
        <label class="rfpf-file-dropzone" tabindex="0">
          <input type="file" name="direct_local_images[]" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple required>
          <span class="rfpf-file-dropzone-icon"><i class="icon-cloud-upload"></i></span>
          <strong>Glissez les images ici, cliquez pour les choisir ou collez-les avec Ctrl + V</strong>
          <small>Le premier fichier devient la couverture si le produit n’en possède pas encore.</small>
        </label>
        <div class="rfpf-file-selection help-block">Aucun fichier sélectionné.</div>
      </div>

      <div class="rfpf-upload-submit">
        <button type="submit" name="submitRfpfUploadLocalImages" value="1" class="btn btn-primary btn-lg"><i class="icon-upload"></i> Ajouter les images au produit</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-heading"><i class="icon-history"></i> Dernières analyses</div>
    <div class="table-responsive-row clearfix">
      <table class="table">
        <thead><tr><th>Date</th><th>Source</th><th>Statut</th><th>Produit</th><th>Erreur</th></tr></thead>
        <tbody>
          {foreach from=$rfpf_latest_jobs item=job}
            <tr>
              <td>{$job.date_add|escape:'htmlall':'UTF-8'}</td>
              <td><a href="{$job.source_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$job.source_url|truncate:80:'…'|escape:'htmlall':'UTF-8'}</a></td>
              <td><span class="label {if $job.status == 'created' || $job.status == 'enriched'}label-success{elseif $job.status == 'error'}label-danger{else}label-info{/if}">{$job.status|escape:'htmlall':'UTF-8'}</span></td>
              <td>{if $job.id_product}#{$job.id_product|intval} {$job.product_name|escape:'htmlall':'UTF-8'}{else}—{/if}</td>
              <td>{$job.error_message|truncate:120:'…'|escape:'htmlall':'UTF-8'}</td>
            </tr>
          {foreachelse}
            <tr><td colspan="5" class="text-center">Aucune analyse pour le moment.</td></tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
</div>
