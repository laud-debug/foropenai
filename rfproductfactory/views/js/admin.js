(function () {
  'use strict';

  function initRfProductFactory() {
    var taxSelect = document.getElementById('rfpf-tax-group');
    var priceTtc = document.getElementById('rfpf-price-ttc');
    var priceHt = document.getElementById('rfpf-price-ht');

    function normalizeNumber(value) {
      return parseFloat(String(value || '').replace(/\s/g, '').replace(',', '.'));
    }

    function recalculateHt() {
      var ttc = normalizeNumber(priceTtc.value);
      var option = taxSelect.options[taxSelect.selectedIndex];
      var rate = normalizeNumber(option ? option.getAttribute('data-rate') : 0) || 0;
      if (!isNaN(ttc)) {
        priceHt.value = (ttc / (1 + rate / 100)).toFixed(6);
      }
    }

    if (taxSelect && priceTtc && priceHt) {
      taxSelect.addEventListener('change', recalculateHt);
      priceTtc.addEventListener('change', recalculateHt);
    }

    var duplicateConfirmation = document.getElementById('rfpf-confirm-duplicate');
    var createButton = document.getElementById('rfpf-create-button');
    var publicationStatuses = document.querySelectorAll('input[name="publication_status"]');
    var publicationSummary = document.getElementById('rfpf-publication-summary');

    function updatePublicationSummary() {
      var selected = document.querySelector('input[name="publication_status"]:checked');
      if (!publicationSummary || !selected) {
        return;
      }

      var isOnline = selected.value === 'online';
      var text = publicationSummary.getAttribute(isOnline ? 'data-online-text' : 'data-offline-text');
      publicationSummary.innerHTML = '<strong>Statut choisi :</strong> ' + text;
    }

    for (var publicationIndex = 0; publicationIndex < publicationStatuses.length; publicationIndex += 1) {
      if (publicationStatuses[publicationIndex].getAttribute('data-rfpf-bound') !== '1') {
        publicationStatuses[publicationIndex].setAttribute('data-rfpf-bound', '1');
        publicationStatuses[publicationIndex].addEventListener('change', updatePublicationSummary);
        publicationStatuses[publicationIndex].addEventListener('click', updatePublicationSummary);
      }
    }
    updatePublicationSummary();

    function updateCreateButton() {
      if (duplicateConfirmation && createButton) {
        createButton.disabled = !duplicateConfirmation.checked;
      }
    }

    if (duplicateConfirmation && createButton) {
      duplicateConfirmation.addEventListener('change', updateCreateButton);
      updateCreateButton();
    }

    var enrichTargets = document.querySelectorAll('.rfpf-enrich-target');
    var enrichOptions = document.querySelectorAll('.rfpf-enrichment-options');
    var enrichButton = document.getElementById('rfpf-enrich-button');

    function updateEnrichmentSelection() {
      var selectedId = '';
      for (var i = 0; i < enrichTargets.length; i += 1) {
        if (enrichTargets[i].checked && enrichTargets[i].value !== '0') {
          selectedId = enrichTargets[i].value;
          break;
        }
      }

      for (var j = 0; j < enrichOptions.length; j += 1) {
        var active = enrichOptions[j].getAttribute('data-product-id') === selectedId;
        if (active) {
          enrichOptions[j].classList.add('is-active');
        } else {
          enrichOptions[j].classList.remove('is-active');
        }
      }

      if (enrichButton) {
        enrichButton.disabled = selectedId === '';
      }
    }

    for (var targetIndex = 0; targetIndex < enrichTargets.length; targetIndex += 1) {
      enrichTargets[targetIndex].addEventListener('change', updateEnrichmentSelection);
    }

    if (enrichButton) {
      enrichButton.addEventListener('click', function (event) {
        var selected = document.querySelector('.rfpf-enrich-target:checked');
        if (!selected || selected.value === '0') {
          event.preventDefault();
          window.alert('Choisissez explicitement une fiche existante à enrichir.');
        }
      });
    }

    updateEnrichmentSelection();

    var manualToggle = document.getElementById('rfpf-manual-toggle');
    var manualBody = document.getElementById('rfpf-manual-body');
    if (manualToggle && manualBody) {
      manualToggle.addEventListener('click', function () {
        var isHidden = manualBody.style.display === 'none';
        manualBody.style.display = isHidden ? 'block' : 'none';
        manualToggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        manualToggle.textContent = isHidden ? 'Masquer' : 'Afficher';
      });
    }

    var indexButton = document.getElementById('rfpf-index-images');
    var indexBar = document.getElementById('rfpf-index-progress-bar');
    var indexPercent = document.getElementById('rfpf-index-percent');
    var indexedCount = document.getElementById('rfpf-indexed-count');
    var totalCount = document.getElementById('rfpf-total-count');
    var indexStatus = document.getElementById('rfpf-index-status');

    function updateIndexProgress(stats) {
      var percent = parseInt(stats.percent, 10) || 0;
      if (indexBar) {
        indexBar.style.width = percent + '%';
      }
      if (indexPercent) {
        indexPercent.textContent = percent + '%';
      }
      if (indexedCount) {
        indexedCount.textContent = parseInt(stats.indexed, 10) || 0;
      }
      if (totalCount) {
        totalCount.textContent = parseInt(stats.total, 10) || 0;
      }
    }

    function indexNextBatch() {
      if (!indexButton || typeof window.jQuery === 'undefined') {
        return;
      }

      window.jQuery.ajax({
        url: indexButton.getAttribute('data-url'),
        method: 'POST',
        dataType: 'json',
        cache: false
      }).done(function (response) {
        if (!response || !response.success || !response.stats) {
          indexButton.disabled = false;
          indexButton.querySelector('span').textContent = 'Reprendre l’indexation';
          if (indexStatus) {
            indexStatus.className = 'help-block text-danger';
            indexStatus.appendChild(document.createTextNode(' — ' + (response && response.message ? response.message : 'Réponse d’indexation invalide.')));
          }
          return;
        }

        updateIndexProgress(response.stats);
        if (parseInt(response.stats.remaining, 10) > 0 && parseInt(response.stats.processed, 10) > 0) {
          window.setTimeout(indexNextBatch, 120);
          return;
        }

        indexButton.disabled = true;
        indexButton.querySelector('span').textContent = 'Catalogue indexé';
        if (indexStatus) {
          indexStatus.className = 'help-block text-success';
        }
      }).fail(function (xhr) {
        indexButton.disabled = false;
        indexButton.querySelector('span').textContent = 'Reprendre l’indexation';
        if (indexStatus) {
          indexStatus.className = 'help-block text-danger';
          indexStatus.appendChild(document.createTextNode(' — Échec de l’indexation. Réessayez.'));
        }
      });
    }

    if (indexButton) {
      indexButton.addEventListener('click', function () {
        indexButton.disabled = true;
        indexButton.querySelector('span').textContent = 'Indexation en cours…';
        indexNextBatch();
      });
    }

    function escapeText(value) {
      return String(value === null || typeof value === 'undefined' ? '' : value);
    }

    function initProductPicker(picker) {
      var searchUrl = picker.getAttribute('data-search-url');
      var input = picker.querySelector('.rfpf-product-search-input');
      var hiddenId = picker.querySelector('.rfpf-product-id');
      var results = picker.querySelector('.rfpf-product-search-results');
      var selected = picker.querySelector('.rfpf-selected-product');
      var selectedName = picker.querySelector('.rfpf-selected-product-name');
      var selectedMeta = picker.querySelector('.rfpf-selected-product-meta');
      var selectedCover = picker.querySelector('.rfpf-selected-product-cover');
      var clearButton = picker.querySelector('.rfpf-clear-product');
      var timer = null;
      var requestSequence = 0;

      function hideResults() {
        if (results) {
          results.innerHTML = '';
          results.classList.remove('is-open');
        }
      }

      function clearSelection() {
        hiddenId.value = '';
        input.value = '';
        input.readOnly = false;
        input.focus();
        selected.style.display = 'none';
        selectedCover.innerHTML = '<i class="icon-picture"></i>';
        hideResults();
      }

      function selectProduct(product) {
        hiddenId.value = String(product.id_product || '');
        input.value = '#' + product.id_product + ' — ' + escapeText(product.name);
        input.readOnly = true;
        selectedName.textContent = escapeText(product.name);
        var meta = ['#' + product.id_product];
        if (product.reference) {
          meta.push('Réf. ' + product.reference);
        }
        if (product.ean13) {
          meta.push('EAN ' + product.ean13);
        }
        meta.push(product.active ? 'Actif' : 'Inactif');
        selectedMeta.textContent = meta.join(' · ');
        if (product.cover_url) {
          selectedCover.innerHTML = '';
          var image = document.createElement('img');
          image.src = product.cover_url;
          image.alt = '';
          selectedCover.appendChild(image);
        } else {
          selectedCover.innerHTML = '<i class="icon-picture"></i>';
        }
        selected.style.display = 'flex';
        hideResults();
      }

      function showMessage(message, className) {
        results.innerHTML = '';
        var row = document.createElement('div');
        row.className = 'rfpf-product-result-message ' + (className || '');
        row.textContent = message;
        results.appendChild(row);
        results.classList.add('is-open');
      }

      function renderProducts(products) {
        results.innerHTML = '';
        if (!products.length) {
          showMessage('Aucun produit trouvé.', 'text-muted');
          return;
        }

        for (var i = 0; i < products.length; i += 1) {
          (function (product) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'rfpf-product-result';
            button.setAttribute('role', 'option');

            var cover = document.createElement('span');
            cover.className = 'rfpf-product-result-cover';
            if (product.cover_url) {
              var image = document.createElement('img');
              image.src = product.cover_url;
              image.alt = '';
              cover.appendChild(image);
            } else {
              cover.innerHTML = '<i class="icon-picture"></i>';
            }

            var content = document.createElement('span');
            content.className = 'rfpf-product-result-content';
            var name = document.createElement('strong');
            name.textContent = escapeText(product.name);
            var meta = document.createElement('small');
            var bits = ['#' + product.id_product];
            if (product.reference) {
              bits.push('Réf. ' + product.reference);
            }
            if (product.ean13) {
              bits.push('EAN ' + product.ean13);
            }
            meta.textContent = bits.join(' · ');
            content.appendChild(name);
            content.appendChild(meta);

            var status = document.createElement('span');
            status.className = 'label ' + (product.active ? 'label-success' : 'label-default');
            status.textContent = product.active ? 'Actif' : 'Inactif';

            button.appendChild(cover);
            button.appendChild(content);
            button.appendChild(status);
            button.addEventListener('click', function () {
              selectProduct(product);
            });
            results.appendChild(button);
          }(products[i]));
        }
        results.classList.add('is-open');
      }

      function searchProducts() {
        var query = input.value.replace(/^\s+|\s+$/g, '');
        if (query.length < 2 && !/^\d+$/.test(query)) {
          hideResults();
          return;
        }
        if (typeof window.jQuery === 'undefined') {
          showMessage('La recherche nécessite jQuery.', 'text-danger');
          return;
        }

        requestSequence += 1;
        var sequence = requestSequence;
        showMessage('Recherche en cours…', 'text-muted');
        window.jQuery.ajax({
          url: searchUrl,
          method: 'GET',
          dataType: 'json',
          cache: false,
          data: { q: query }
        }).done(function (response) {
          if (sequence !== requestSequence) {
            return;
          }
          if (!response || !response.success) {
            showMessage(response && response.message ? response.message : 'Recherche impossible.', 'text-danger');
            return;
          }
          renderProducts(response.products || []);
        }).fail(function () {
          if (sequence === requestSequence) {
            showMessage('La recherche produit a échoué. Réessayez.', 'text-danger');
          }
        });
      }

      input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(searchProducts, 280);
      });
      input.addEventListener('focus', function () {
        if (!input.readOnly && input.value.length >= 2) {
          searchProducts();
        }
      });
      if (clearButton) {
        clearButton.addEventListener('click', clearSelection);
      }
      document.addEventListener('click', function (event) {
        if (!picker.contains(event.target)) {
          hideResults();
        }
      });

      var form = picker.closest('form');
      if (form) {
        form.addEventListener('submit', function (event) {
          if (!hiddenId.value) {
            event.preventDefault();
            window.alert('Sélectionnez d’abord le produit à compléter dans la liste de résultats.');
            input.readOnly = false;
            input.focus();
          }
        });
      }
    }

    var productPickers = document.querySelectorAll('.rfpf-product-picker');
    for (var pickerIndex = 0; pickerIndex < productPickers.length; pickerIndex += 1) {
      initProductPicker(productPickers[pickerIndex]);
    }

    function setFiles(input, newFiles, append) {
      if (!input || typeof window.DataTransfer === 'undefined') {
        return false;
      }
      var transfer = new window.DataTransfer();
      var seen = {};
      var current = append && input.files ? input.files : [];
      var i;
      for (i = 0; i < current.length; i += 1) {
        var currentKey = current[i].name + ':' + current[i].size + ':' + current[i].lastModified;
        if (!seen[currentKey]) {
          transfer.items.add(current[i]);
          seen[currentKey] = true;
        }
      }
      for (i = 0; i < newFiles.length; i += 1) {
        if (!/^image\//i.test(newFiles[i].type || '')) {
          continue;
        }
        var key = newFiles[i].name + ':' + newFiles[i].size + ':' + newFiles[i].lastModified;
        if (!seen[key]) {
          transfer.items.add(newFiles[i]);
          seen[key] = true;
        }
      }
      input.files = transfer.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }

    function initFileDropzone(dropzone) {
      var input = dropzone.querySelector('input[type="file"]');
      var container = dropzone.parentNode;
      var summary = container ? container.querySelector('.rfpf-file-selection') : null;
      if (!input) {
        return;
      }

      function updateSummary() {
        if (!summary) {
          return;
        }
        if (!input.files || !input.files.length) {
          summary.textContent = 'Aucun fichier sélectionné.';
          summary.className = 'rfpf-file-selection help-block';
          return;
        }
        var names = [];
        for (var i = 0; i < input.files.length; i += 1) {
          names.push(input.files[i].name);
        }
        summary.textContent = input.files.length + ' image(s) sélectionnée(s) : ' + names.join(', ');
        summary.className = 'rfpf-file-selection help-block text-success';
      }

      input.addEventListener('change', updateSummary);
      dropzone.addEventListener('dragover', function (event) {
        event.preventDefault();
        dropzone.classList.add('is-dragging');
      });
      dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('is-dragging');
      });
      dropzone.addEventListener('drop', function (event) {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
        if (event.dataTransfer && event.dataTransfer.files) {
          setFiles(input, event.dataTransfer.files, true);
        }
      });
      dropzone.addEventListener('paste', function (event) {
        var files = [];
        var items = event.clipboardData && event.clipboardData.items ? event.clipboardData.items : [];
        for (var i = 0; i < items.length; i += 1) {
          if (items[i].kind === 'file' && /^image\//i.test(items[i].type || '')) {
            var file = items[i].getAsFile();
            if (file) {
              files.push(file);
            }
          }
        }
        if (files.length) {
          event.preventDefault();
          setFiles(input, files, true);
        }
      });
      dropzone.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          input.click();
        }
      });
      updateSummary();
    }

    var dropzones = document.querySelectorAll('.rfpf-file-dropzone');
    for (var dropzoneIndex = 0; dropzoneIndex < dropzones.length; dropzoneIndex += 1) {
      initFileDropzone(dropzones[dropzoneIndex]);
    }

    function normalizeCategorySearch(value) {
      var normalized = String(value || '').toLowerCase();
      if (typeof normalized.normalize === 'function') {
        normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      }
      return normalized.replace(/[—–_-]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function initSearchableCategory(select) {
      if (!select || select.getAttribute('data-rfpf-search-bound') === '1') {
        return;
      }
      select.setAttribute('data-rfpf-search-bound', '1');

      var wrapper = document.createElement('div');
      wrapper.className = 'rfpf-category-search';
      select.parentNode.insertBefore(wrapper, select);
      wrapper.appendChild(select);

      var searchRow = document.createElement('div');
      searchRow.className = 'rfpf-category-search-row';
      var icon = document.createElement('i');
      icon.className = 'icon-search';
      var input = document.createElement('input');
      input.type = 'search';
      input.className = 'form-control rfpf-category-search-input';
      input.placeholder = select.getAttribute('data-search-placeholder') || 'Rechercher une catégorie…';
      input.setAttribute('autocomplete', 'off');
      var count = document.createElement('span');
      count.className = 'rfpf-category-search-count';
      searchRow.appendChild(icon);
      searchRow.appendChild(input);
      searchRow.appendChild(count);
      wrapper.insertBefore(searchRow, select);

      function filterOptions() {
        var query = normalizeCategorySearch(input.value);
        var visible = 0;
        for (var i = 0; i < select.options.length; i += 1) {
          var option = select.options[i];
          var matches = !query || normalizeCategorySearch(option.textContent || option.innerText).indexOf(query) !== -1;
          option.hidden = !matches && !option.selected;
          option.style.display = matches || option.selected ? '' : 'none';
          if (matches) {
            visible += 1;
          }
        }
        count.textContent = query ? visible + ' résultat(s)' : '';
      }

      input.addEventListener('input', filterOptions);
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          input.value = '';
          filterOptions();
          input.blur();
        }
      });
      select.addEventListener('change', function () {
        input.value = '';
        filterOptions();
      });
      filterOptions();
    }

    var searchableCategories = document.querySelectorAll('.rfpf-searchable-category');
    for (var searchableCategoryIndex = 0; searchableCategoryIndex < searchableCategories.length; searchableCategoryIndex += 1) {
      initSearchableCategory(searchableCategories[searchableCategoryIndex]);
    }

    var batchForm = document.getElementById('rfpf-batch-create-form');
    var batchJson = document.getElementById('rfpf-batch-rows-json');
    if (batchForm && batchJson) {
      var batchRows = [];
      try {
        batchRows = JSON.parse(batchJson.value || '[]');
      } catch (batchParseError) {
        batchRows = [];
      }

      function syncBatchRows() {
        var tableRows = batchForm.querySelectorAll('.rfpf-batch-row[data-row-index]');
        for (var rowIndex = 0; rowIndex < tableRows.length; rowIndex += 1) {
          var tableRow = tableRows[rowIndex];
          var index = parseInt(tableRow.getAttribute('data-row-index'), 10);
          if (isNaN(index) || !batchRows[index]) {
            continue;
          }
          var fields = tableRow.querySelectorAll('.rfpf-batch-field[data-field]');
          for (var fieldIndex = 0; fieldIndex < fields.length; fieldIndex += 1) {
            var field = fields[fieldIndex];
            var key = field.getAttribute('data-field');
            if (field.type === 'checkbox') {
              batchRows[index][key] = field.checked && !field.disabled ? 1 : 0;
            } else {
              batchRows[index][key] = field.value;
            }
          }
        }
        batchJson.value = JSON.stringify(batchRows);
        updateBatchButton();
      }

      function updateBatchButton() {
        var button = document.getElementById('rfpf-batch-create-button');
        if (!button) {
          return;
        }
        var selectedCount = 0;
        var checkboxes = batchForm.querySelectorAll('.rfpf-batch-select:not(:disabled)');
        for (var i = 0; i < checkboxes.length; i += 1) {
          if (checkboxes[i].checked) {
            selectedCount += 1;
          }
        }
        button.disabled = selectedCount === 0;
        button.innerHTML = '<i class="icon-plus-circle"></i> Créer ' + selectedCount + ' produit(s) sélectionné(s)';
      }

      var editableFields = batchForm.querySelectorAll('.rfpf-batch-field');
      for (var batchFieldIndex = 0; batchFieldIndex < editableFields.length; batchFieldIndex += 1) {
        editableFields[batchFieldIndex].addEventListener('change', syncBatchRows);
        editableFields[batchFieldIndex].addEventListener('input', syncBatchRows);
      }

      var selectAll = document.getElementById('rfpf-batch-select-all');
      var selectNone = document.getElementById('rfpf-batch-select-none');
      if (selectAll) {
        selectAll.addEventListener('click', function () {
          var checkboxes = batchForm.querySelectorAll('.rfpf-batch-select:not(:disabled)');
          for (var i = 0; i < checkboxes.length; i += 1) {
            checkboxes[i].checked = true;
          }
          syncBatchRows();
        });
      }
      if (selectNone) {
        selectNone.addEventListener('click', function () {
          var checkboxes = batchForm.querySelectorAll('.rfpf-batch-select:not(:disabled)');
          for (var i = 0; i < checkboxes.length; i += 1) {
            checkboxes[i].checked = false;
          }
          syncBatchRows();
        });
      }
      var batchCategoryChoice = document.getElementById('rfpf-batch-category-choice');
      var batchApplyCategory = document.getElementById('rfpf-batch-apply-category');
      if (batchCategoryChoice && batchApplyCategory) {
        batchApplyCategory.addEventListener('click', function () {
          var categoryId = String(batchCategoryChoice.value || '');
          if (!categoryId) {
            window.alert('Choisissez une catégorie à appliquer.');
            return;
          }

          var categoryFields = batchForm.querySelectorAll('.rfpf-field-category');
          var applied = 0;
          for (var i = 0; i < categoryFields.length; i += 1) {
            categoryFields[i].value = categoryId;
            if (String(categoryFields[i].value) === categoryId) {
              applied += 1;
            }
          }

          var hiddenDefaultCategory = batchForm.querySelector('input[name="excel_id_category_default"]');
          if (hiddenDefaultCategory) {
            hiddenDefaultCategory.value = categoryId;
          }
          syncBatchRows();

          var originalHtml = batchApplyCategory.innerHTML;
          batchApplyCategory.innerHTML = '<i class="icon-check"></i> Appliquée à ' + applied + ' ligne(s)';
          batchApplyCategory.classList.remove('btn-primary');
          batchApplyCategory.classList.add('btn-success');
          window.setTimeout(function () {
            batchApplyCategory.innerHTML = originalHtml;
            batchApplyCategory.classList.remove('btn-success');
            batchApplyCategory.classList.add('btn-primary');
          }, 1800);
        });
      }

      batchForm.addEventListener('submit', function (event) {
        syncBatchRows();
        var selected = 0;
        for (var i = 0; i < batchRows.length; i += 1) {
          if (parseInt(batchRows[i].selected, 10) === 1) {
            selected += 1;
          }
        }
        if (!selected) {
          event.preventDefault();
          window.alert('Sélectionnez au moins un nouveau produit à créer.');
          return;
        }
        if (!window.confirm('Créer ' + selected + ' nouvelle(s) fiche(s) produit dans PrestaShop ?')) {
          event.preventDefault();
        }
      });
      updateBatchButton();
    }

    var downloadAllButtons = document.querySelectorAll('.rfpf-download-all-images');
    for (var downloadIndex = 0; downloadIndex < downloadAllButtons.length; downloadIndex += 1) {
      downloadAllButtons[downloadIndex].addEventListener('click', function () {
        var links = document.querySelectorAll('.rfpf-open-source-image[data-image-url]');
        if (!links.length) {
          window.alert('Aucune image distante à télécharger.');
          return;
        }
        for (var i = 0; i < links.length; i += 1) {
          var anchor = document.createElement('a');
          anchor.href = links[i].getAttribute('data-image-url');
          anchor.download = '';
          anchor.target = '_blank';
          anchor.rel = 'noopener noreferrer';
          document.body.appendChild(anchor);
          anchor.click();
          document.body.removeChild(anchor);
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRfProductFactory);
  } else {
    initRfProductFactory();
  }
}());
