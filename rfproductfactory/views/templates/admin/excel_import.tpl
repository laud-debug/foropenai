<div class="rfpf-wrapper rfpf-excel-wrapper">
  <ul class="nav nav-tabs rfpf-main-tabs">
    <li><a href="{$rfpf_web_url|escape:'htmlall':'UTF-8'}"><i class="icon-link"></i> Analyse web</a></li>
    <li class="active"><a href="{$rfpf_excel_url|escape:'htmlall':'UTF-8'}"><i class="icon-table"></i> Import Excel / Copier-coller</a></li>
    <li><a href="{$rfpf_dashboard_url|escape:'htmlall':'UTF-8'}"><i class="icon-dashboard"></i> Tableau de bord</a></li>
  </ul>

  <div class="panel">
    <div class="panel-heading"><i class="icon-paste"></i> Créer plusieurs produits depuis Excel</div>
    <div class="alert alert-info rfpf-excel-intro">
      Copiez les cellules dans Excel, puis collez-les dans la zone ci-dessous. La première ligne doit contenir les en-têtes.
      Le module utilise <strong>FRR comme prix de vente TTC</strong>, <strong>EUD comme prix d’achat HT</strong> et applique la règle catalogue Rebel Forge :
      <strong>référence PrestaShop = GW- + Product Code</strong>. Le SS Code reste affiché comme information source séparée.
    </div>

    <form method="post" enctype="multipart/form-data" action="{$rfpf_action|escape:'htmlall':'UTF-8'}" class="form-horizontal" id="rfpf-excel-preview-form">
      <input type="hidden" name="rfpf_section" value="excel">
      <div class="form-group">
        <label class="control-label col-lg-2 required">Copier-coller Excel</label>
        <div class="col-lg-10">
          <textarea name="spreadsheet_data" rows="10" class="form-control rfpf-excel-paste" placeholder="Release Date (Last 3 Months)&#9;Module&#9;System&#9;Race&#9;SS Code&#9;Product Code...">{$rfpf_excel_raw|escape:'htmlall':'UTF-8'}</textarea>
          <p class="help-block">Jusqu’à 200 produits. Les colonnes séparées par des tabulations sont reconnues automatiquement.</p>
          <div class="rfpf-file-alternative">
            <span>ou importer un export</span>
            <input type="file" name="spreadsheet_file" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values,text/plain">
          </div>
        </div>
      </div>

      <div class="panel rfpf-excel-settings">
        <div class="panel-heading"><i class="icon-cogs"></i> Règles d’import</div>
        <div class="row">
          <div class="col-lg-3 col-md-6">
            <label>Référence PrestaShop</label>
            <input type="text" class="form-control" value="GW- + Product Code" readonly>
            <input type="hidden" name="reference_source" value="product_code">
          </div>
          <div class="col-lg-3 col-md-6">
            <label>Préfixe de référence</label>
            <input type="text" name="reference_prefix" value="GW-" class="form-control" readonly>
          </div>
          <div class="col-lg-3 col-md-6">
            <label>Référence fournisseur</label>
            <input type="text" class="form-control" value="Product Code" readonly>
            <input type="hidden" name="supplier_reference_source" value="product_code">
          </div>
          <div class="col-lg-3 col-md-6">
            <label>Normaliser les noms</label>
            <select name="normalize_names" class="form-control">
              <option value="0"{if !$rfpf_excel_options.normalize_names} selected{/if}>Non, conserver le libellé</option>
              <option value="1"{if $rfpf_excel_options.normalize_names} selected{/if}>Oui, convertir en casse titre</option>
            </select>
          </div>
        </div>

        <div class="row rfpf-settings-row">
          <div class="col-lg-3 col-md-6">
            <label>Prix de vente TTC</label>
            <select name="sale_price_source" class="form-control">
              <option value="frr"{if $rfpf_excel_options.sale_price_source == 'frr'} selected{/if}>FRR</option>
              <option value="chr"{if $rfpf_excel_options.sale_price_source == 'chr'} selected{/if}>CHR</option>
            </select>
          </div>
          <div class="col-lg-3 col-md-6">
            <label>Prix d’achat HT</label>
            <select name="wholesale_price_source" class="form-control">
              <option value="eud"{if $rfpf_excel_options.wholesale_price_source == 'eud'} selected{/if}>EUD</option>
              <option value="chd"{if $rfpf_excel_options.wholesale_price_source == 'chd'} selected{/if}>CHD</option>
            </select>
          </div>
          <div class="col-lg-3 col-md-6">
            <label>Taxe standard</label>
            <select name="id_tax_rules_group_standard" class="form-control">
              {foreach from=$rfpf_tax_groups item=tax_group}
                <option value="{$tax_group.id_tax_rules_group|intval}"{if $rfpf_excel_options.id_tax_rules_group_standard == $tax_group.id_tax_rules_group} selected{/if}>{$tax_group.name|escape:'htmlall':'UTF-8'} ({$tax_group.rate|string_format:'%.2f'} %)</option>
              {/foreach}
            </select>
          </div>
          <div class="col-lg-3 col-md-6">
            <label>Taxe livres / Black Library</label>
            <select name="id_tax_rules_group_book" class="form-control">
              {foreach from=$rfpf_tax_groups item=tax_group}
                <option value="{$tax_group.id_tax_rules_group|intval}"{if $rfpf_excel_options.id_tax_rules_group_book == $tax_group.id_tax_rules_group} selected{/if}>{$tax_group.name|escape:'htmlall':'UTF-8'} ({$tax_group.rate|string_format:'%.2f'} %)</option>
              {/foreach}
            </select>
          </div>
        </div>

        <div class="row rfpf-settings-row">
          <div class="col-lg-5 col-md-6">
            <label>Catégorie par défaut du lot</label>
            <select name="excel_id_category_default" id="rfpf-excel-default-category" class="form-control rfpf-searchable-category" data-search-placeholder="Rechercher une catégorie PrestaShop…">
              {foreach from=$rfpf_categories item=category}
                <option value="{$category.id_category|intval}"{if $rfpf_excel_options.id_category_default == $category.id_category} selected{/if}>{$category.indent|escape:'htmlall':'UTF-8'}{$category.name|escape:'htmlall':'UTF-8'}</option>
              {/foreach}
            </select>
            <p class="help-block">La catégorie <strong>Réservation GW</strong> est présélectionnée lorsqu’elle existe. Votre dernier choix sera mémorisé.</p>
          </div>
          <div class="col-lg-2 col-md-6">
            <label>Remplacer via Race/System</label>
            <select name="auto_category" class="form-control">
              <option value="0"{if !$rfpf_excel_options.auto_category} selected{/if}>Non — garder la catégorie choisie</option>
              <option value="1"{if $rfpf_excel_options.auto_category} selected{/if}>Oui — suggestion automatique</option>
            </select>
          </div>
          <div class="col-lg-2 col-md-6">
            <label>Fabricant</label>
            <select name="excel_id_manufacturer" class="form-control">
              <option value="0">— Aucun —</option>
              {foreach from=$rfpf_manufacturers item=manufacturer}
                <option value="{$manufacturer.id_manufacturer|intval}"{if $rfpf_excel_options.id_manufacturer == $manufacturer.id_manufacturer} selected{/if}>{$manufacturer.name|escape:'htmlall':'UTF-8'}</option>
              {/foreach}
            </select>
          </div>
          <div class="col-lg-3 col-md-6">
            <label>Fournisseur</label>
            <select name="excel_id_supplier" class="form-control">
              <option value="0">— Aucun —</option>
              {foreach from=$rfpf_suppliers item=supplier}
                <option value="{$supplier.id_supplier|intval}"{if $rfpf_excel_options.id_supplier == $supplier.id_supplier} selected{/if}>{$supplier.name|escape:'htmlall':'UTF-8'}</option>
              {/foreach}
            </select>
          </div>
        </div>

        <div class="row rfpf-settings-row">
          <div class="col-lg-3 col-md-6">
            <label>Publication des produits créés</label>
            <select name="excel_publication_status" class="form-control">
              <option value="offline"{if $rfpf_excel_options.publication_status == 'offline'} selected{/if}>Hors ligne — recommandé</option>
              <option value="online"{if $rfpf_excel_options.publication_status == 'online'} selected{/if}>En ligne</option>
            </select>
          </div>
          <div class="col-lg-9 rfpf-excel-note">
            <i class="icon-info-circle"></i>
            Les livres sont détectés via l’EAN 978/979, le code douanier 4901 ou la gamme Black Library. Les produits existants sont détectés par EAN, référence PrestaShop et référence fournisseur.
          </div>
        </div>
      </div>

      <div class="panel-footer">
        <button type="submit" name="submitRfpfExcelPreview" class="btn btn-primary pull-right"><i class="icon-search"></i> Analyser et prévisualiser</button>
      </div>
    </form>
  </div>

  {if $rfpf_excel_result}
    <div class="panel">
      <div class="panel-heading"><i class="icon-check"></i> Résultat du dernier lot</div>
      <div class="rfpf-result-cards">
        <div class="rfpf-result-card success"><strong>{$rfpf_excel_result.created_count|intval}</strong><span>créé(s)</span></div>
        <div class="rfpf-result-card warning"><strong>{$rfpf_excel_result.skipped_count|intval}</strong><span>doublon(s) ignoré(s)</span></div>
        <div class="rfpf-result-card danger"><strong>{$rfpf_excel_result.failed_count|intval}</strong><span>échec(s)</span></div>
      </div>
      {if $rfpf_excel_result.created}
        <div class="rfpf-created-list">
          {foreach from=$rfpf_excel_result.created item=created}
            <a class="btn btn-default btn-sm" href="{$created.edit_url|escape:'htmlall':'UTF-8'}"><i class="icon-edit"></i> #{$created.id_product|intval} — {$created.name|escape:'htmlall':'UTF-8'}</a>
          {/foreach}
        </div>
      {/if}
      {if $rfpf_excel_result.failed}
        <div class="alert alert-warning">
          {foreach from=$rfpf_excel_result.failed item=failed}
            <div><strong>{$failed.name|escape:'htmlall':'UTF-8'}</strong> : {$failed.message|escape:'htmlall':'UTF-8'}</div>
          {/foreach}
        </div>
      {/if}
    </div>
  {/if}

  {if $rfpf_excel_preview}
    <div class="panel rfpf-batch-panel">
      <div class="panel-heading"><i class="icon-list"></i> Prévisualisation du lot</div>
      <div class="rfpf-batch-summary">
        <div><strong>{$rfpf_excel_preview.stats.total|intval}</strong><span>lignes</span></div>
        <div class="success"><strong>{$rfpf_excel_preview.stats.new|intval}</strong><span>nouveaux</span></div>
        <div class="warning"><strong>{$rfpf_excel_preview.stats.existing|intval}</strong><span>existants</span></div>
        <div class="danger"><strong>{$rfpf_excel_preview.stats.invalid|intval}</strong><span>invalides</span></div>
      </div>

      <form method="post" action="{$rfpf_action|escape:'htmlall':'UTF-8'}" id="rfpf-batch-create-form">
        <input type="hidden" name="rfpf_section" value="excel">
        <textarea name="batch_rows_json" id="rfpf-batch-rows-json" class="rfpf-hidden-json">{$rfpf_excel_rows_json|escape:'htmlall':'UTF-8'}</textarea>
        <input type="hidden" name="reference_source" value="{$rfpf_excel_options.reference_source|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="reference_prefix" value="{$rfpf_excel_options.reference_prefix|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="supplier_reference_source" value="{$rfpf_excel_options.supplier_reference_source|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="sale_price_source" value="{$rfpf_excel_options.sale_price_source|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="wholesale_price_source" value="{$rfpf_excel_options.wholesale_price_source|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="excel_id_category_default" value="{$rfpf_excel_options.id_category_default|intval}">
        <input type="hidden" name="auto_category" value="{$rfpf_excel_options.auto_category|intval}">
        <input type="hidden" name="id_tax_rules_group_standard" value="{$rfpf_excel_options.id_tax_rules_group_standard|intval}">
        <input type="hidden" name="id_tax_rules_group_book" value="{$rfpf_excel_options.id_tax_rules_group_book|intval}">
        <input type="hidden" name="excel_id_manufacturer" value="{$rfpf_excel_options.id_manufacturer|intval}">
        <input type="hidden" name="excel_id_supplier" value="{$rfpf_excel_options.id_supplier|intval}">
        <input type="hidden" name="excel_publication_status" value="{$rfpf_excel_options.publication_status|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="normalize_names" value="{$rfpf_excel_options.normalize_names|intval}">

        <div class="rfpf-batch-toolbar">
          <div class="rfpf-batch-selection-tools">
            <button type="button" class="btn btn-default" id="rfpf-batch-select-all"><i class="icon-check-square-o"></i> Tout sélectionner</button>
            <button type="button" class="btn btn-default" id="rfpf-batch-select-none"><i class="icon-square-o"></i> Tout désélectionner</button>
          </div>
          <div class="rfpf-batch-category-tools">
            <label for="rfpf-batch-category-choice">Catégorie pour le lot</label>
            <select id="rfpf-batch-category-choice" class="form-control rfpf-searchable-category" data-search-placeholder="Rechercher une catégorie…">
              {foreach from=$rfpf_categories item=category}
                <option value="{$category.id_category|intval}"{if $rfpf_excel_options.id_category_default == $category.id_category} selected{/if}>{$category.indent|escape:'htmlall':'UTF-8'}{$category.name|escape:'htmlall':'UTF-8'}</option>
              {/foreach}
            </select>
            <button type="button" class="btn btn-primary" id="rfpf-batch-apply-category"><i class="icon-level-down"></i> Appliquer à toutes les lignes</button>
          </div>
          <span class="rfpf-batch-toolbar-note">Les cellules restent modifiables avant la création.</span>
        </div>

        <div class="table-responsive rfpf-batch-table-wrap">
          <table class="table table-bordered table-striped rfpf-batch-table">
            <thead>
              <tr>
                <th class="rfpf-col-select">Créer</th>
                <th>État</th>
                <th>Nom</th>
                <th>Référence GW-Product Code</th>
                <th>EAN-13</th>
                <th>Catégorie</th>
                <th>Taxe</th>
                <th>Prix TTC</th>
                <th>Prix achat HT</th>
                <th>Date sortie</th>
                <th>Poids kg</th>
                <th>Réf. fournisseur</th>
                <th>Informations source</th>
              </tr>
            </thead>
            <tbody>
              {foreach from=$rfpf_excel_preview.rows item=row name=batchrows}
                <tr class="rfpf-batch-row{if $row.errors} has-error{elseif $row.existing_product} is-existing{elseif $row.created_product} is-created{/if}" data-row-index="{$smarty.foreach.batchrows.index|intval}">
                  <td class="rfpf-col-select">
                    <input type="checkbox" class="rfpf-batch-field rfpf-batch-select" data-field="selected" value="1"{if $row.selected && !$row.errors && !$row.existing_product && !$row.created_product} checked{/if}{if $row.errors || $row.existing_product || $row.created_product} disabled{/if}>
                  </td>
                  <td class="rfpf-batch-status">
                    {if $row.created_product}
                      <a class="label label-success" href="{$row.created_product.edit_url|escape:'htmlall':'UTF-8'}">Créé #{$row.created_product.id_product|intval}</a>
                    {elseif $row.existing_product}
                      <a class="label label-warning" href="{$row.existing_product.edit_url|escape:'htmlall':'UTF-8'}">Existe #{$row.existing_product.id_product|intval}</a>
                      <small>{$row.existing_reason|escape:'htmlall':'UTF-8'}</small>
                    {elseif $row.errors}
                      <span class="label label-danger">À corriger</span>
                      {foreach from=$row.errors item=row_error}<small>{$row_error|escape:'htmlall':'UTF-8'}</small>{/foreach}
                    {else}
                      <span class="label label-success">Nouveau</span>
                    {/if}
                  </td>
                  <td><input type="text" class="form-control rfpf-batch-field rfpf-field-name" data-field="name" value="{$row.name|escape:'htmlall':'UTF-8'}"></td>
                  <td><input type="text" class="form-control rfpf-batch-field" data-field="reference" value="{$row.reference|escape:'htmlall':'UTF-8'}" readonly></td>
                  <td><input type="text" class="form-control rfpf-batch-field rfpf-field-ean" data-field="ean13" value="{$row.ean13|escape:'htmlall':'UTF-8'}" maxlength="13"></td>
                  <td>
                    <select class="form-control rfpf-batch-field rfpf-field-category" data-field="id_category_default">
                      {foreach from=$rfpf_categories item=category}
                        <option value="{$category.id_category|intval}"{if $row.id_category_default == $category.id_category} selected{/if}>{$category.indent|escape:'htmlall':'UTF-8'}{$category.name|escape:'htmlall':'UTF-8'}</option>
                      {/foreach}
                    </select>
                  </td>
                  <td>
                    <select class="form-control rfpf-batch-field rfpf-field-tax" data-field="id_tax_rules_group">
                      {foreach from=$rfpf_tax_groups item=tax_group}
                        <option value="{$tax_group.id_tax_rules_group|intval}"{if $row.id_tax_rules_group == $tax_group.id_tax_rules_group} selected{/if}>{$tax_group.name|escape:'htmlall':'UTF-8'} ({$tax_group.rate|string_format:'%.2f'} %)</option>
                      {/foreach}
                    </select>
                  </td>
                  <td><input type="number" min="0" step="0.01" class="form-control rfpf-batch-field rfpf-field-price" data-field="price_ttc" value="{$row.price_ttc|escape:'htmlall':'UTF-8'}"></td>
                  <td><input type="number" min="0" step="0.000001" class="form-control rfpf-batch-field rfpf-field-price" data-field="wholesale_price_ht" value="{$row.wholesale_price_ht|escape:'htmlall':'UTF-8'}"></td>
                  <td><input type="date" class="form-control rfpf-batch-field rfpf-field-date" data-field="available_date" value="{if $row.available_date != '0000-00-00'}{$row.available_date|escape:'htmlall':'UTF-8'}{/if}"></td>
                  <td><input type="number" min="0" step="0.0001" class="form-control rfpf-batch-field rfpf-field-weight" data-field="weight" value="{$row.weight|escape:'htmlall':'UTF-8'}"></td>
                  <td><input type="text" class="form-control rfpf-batch-field" data-field="supplier_reference" value="{$row.supplier_reference|escape:'htmlall':'UTF-8'}" readonly></td>
                  <td class="rfpf-source-details">
                    <strong>{$row.system|escape:'htmlall':'UTF-8'}</strong>
                    <span>{$row.race|escape:'htmlall':'UTF-8'}</span>
                    <small>SS {$row.ss_code|escape:'htmlall':'UTF-8'} · Code {$row.product_code|escape:'htmlall':'UTF-8'}</small>
                    <small>Commande suggérée : {$row.order_qty|intval} · Colis : {$row.qty_in_pack|intval}</small>
                    {if $row.is_book}<span class="label label-info">Livre</span>{/if}
                  </td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </div>

        <div class="panel-footer rfpf-batch-footer">
          <span><i class="icon-shield"></i> Une nouvelle vérification des doublons sera faite juste avant la création.</span>
          <button type="submit" name="submitRfpfExcelCreate" class="btn btn-success pull-right" id="rfpf-batch-create-button"><i class="icon-plus-circle"></i> Créer les produits sélectionnés</button>
        </div>
      </form>
    </div>
  {/if}
</div>
