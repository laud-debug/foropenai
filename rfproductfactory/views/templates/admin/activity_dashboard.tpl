<div class="rfpf-wrapper rfpf-dashboard-wrapper">
  <ul class="nav nav-tabs rfpf-main-tabs">
    <li><a href="{$rfpf_web_url|escape:'htmlall':'UTF-8'}"><i class="icon-link"></i> Analyse web</a></li>
    <li><a href="{$rfpf_excel_url|escape:'htmlall':'UTF-8'}"><i class="icon-table"></i> Import Excel / Copier-coller</a></li>
    <li class="active"><a href="{$rfpf_dashboard_url|escape:'htmlall':'UTF-8'}"><i class="icon-dashboard"></i> Tableau de bord</a></li>
  </ul>

  <div class="panel">
    <div class="panel-heading"><i class="icon-dashboard"></i> Activité du module</div>
    <div class="row rfpf-dashboard-stats">
      <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="rfpf-stat-card">
          <span class="rfpf-stat-label">Analyses aujourd’hui</span>
          <strong class="rfpf-stat-value">{$rfpf_activity_stats.analyses_today|default:0|intval}</strong>
        </div>
      </div>
      <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="rfpf-stat-card">
          <span class="rfpf-stat-label">Produits créés</span>
          <strong class="rfpf-stat-value">{$rfpf_activity_stats.products_created_today|default:0|intval}</strong>
        </div>
      </div>
      <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="rfpf-stat-card">
          <span class="rfpf-stat-label">Produits enrichis</span>
          <strong class="rfpf-stat-value">{$rfpf_activity_stats.products_enriched_today|default:0|intval}</strong>
        </div>
      </div>
      <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="rfpf-stat-card rfpf-stat-card-error">
          <span class="rfpf-stat-label">Erreurs aujourd’hui</span>
          <strong class="rfpf-stat-value">{$rfpf_activity_stats.errors_today|default:0|intval}</strong>
        </div>
      </div>
      <div class="col-lg-4 col-md-8 col-sm-12">
        <div class="rfpf-stat-card rfpf-stat-card-wide">
          <span class="rfpf-stat-label">Analyses sur 30 jours</span>
          <strong class="rfpf-stat-value">{$rfpf_activity_stats.analyses_last_30_days|default:0|intval}</strong>
        </div>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-heading"><i class="icon-history"></i> 20 derniers jobs</div>
    <div class="table-responsive-row clearfix">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>URL source</th>
            <th>Statut</th>
            <th>Produit associé</th>
            <th>Message d’erreur</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$rfpf_recent_jobs item=job}
            <tr>
              <td>{$job.date_add|escape:'htmlall':'UTF-8'}</td>
              <td>
                {if !empty($job.source_url)}
                  <a href="{$job.source_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$job.source_url|truncate:90:'…'|escape:'htmlall':'UTF-8'}</a>
                {else}
                  —
                {/if}
              </td>
              <td>
                <span class="label {if $job.status == 'created' || $job.status == 'enriched'}label-success{elseif $job.status == 'error'}label-danger{else}label-info{/if}">
                  {$job.status|escape:'htmlall':'UTF-8'}
                </span>
              </td>
              <td>
                {if !empty($job.id_product)}
                  <a href="{$job.product_edit_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                    #{$job.id_product|intval} {$job.product_name|escape:'htmlall':'UTF-8'}
                  </a>
                {else}
                  —
                {/if}
              </td>
              <td>
                {if !empty($job.error_message)}
                  {$job.error_message|escape:'htmlall':'UTF-8'}
                {else}
                  —
                {/if}
              </td>
            </tr>
          {foreachelse}
            <tr>
              <td colspan="5" class="text-center">Aucun job enregistré pour le moment.</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
</div>
