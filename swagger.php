<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/profile_completion.php';

auth_required(['admin']);
refresh_current_user($pdo);
cas_require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$openapiUrl = asset_url('docs/openapi.json');
$nonce = csp_nonce();

$downloadLabel = t($t, 'download_openapi_spec', 'Download OpenAPI JSON');
$loadingText = t($t, 'api_doc_loading', 'Loading API documentation…');
$viewerFailedText = t($t, 'api_doc_viewer_failed', 'Unable to load the interactive viewer. You can still download the OpenAPI JSON below.');

$downloadUrl = htmlspecialchars($openapiUrl, ENT_QUOTES, 'UTF-8');
$downloadTextHtml = htmlspecialchars($downloadLabel, ENT_QUOTES, 'UTF-8');
$downloadTextJs = json_encode($downloadLabel, JSON_THROW_ON_ERROR);
$loadingLabelHtml = htmlspecialchars($loadingText, ENT_QUOTES, 'UTF-8');
$viewerFailedHtml = htmlspecialchars($viewerFailedText, ENT_QUOTES, 'UTF-8');
$viewerFailedJs = json_encode($viewerFailedText, JSON_THROW_ON_ERROR);
$pageTitle = htmlspecialchars(t($t, 'api_documentation', 'API Documentation'), ENT_QUOTES, 'UTF-8');
$localeEsc = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?=$localeEsc?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=$pageTitle?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css" crossorigin="anonymous">
  <style>
    body {
      margin: 0;
      background: #fafafa;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #212121;
    }
    .swagger-shell {
      padding: 24px;
      box-sizing: border-box;
      max-width: 1200px;
      margin: 0 auto;
    }
    .swagger-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 16px;
    }
    .swagger-header h1 {
      margin: 0;
      font-size: 28px;
    }
    .swagger-download-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #0057b8;
      color: #fff;
      padding: 10px 16px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 600;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .swagger-download-link:hover,
    .swagger-download-link:focus {
      background: #004494;
      color: #fff;
    }
    #swagger-ui {
      min-height: 480px;
    }
    .swagger-fallback,
    .swagger-error {
      background: #fff;
      border-radius: 12px;
      padding: 32px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .swagger-fallback p,
    .swagger-error p {
      margin: 0 0 16px;
      line-height: 1.5;
    }
    @media (max-width: 600px) {
      .swagger-header h1 {
        font-size: 22px;
      }
    }
  </style>
</head>
<body>
  <main class="swagger-shell">
    <header class="swagger-header">
      <h1 id="api-docs-title"><?=$pageTitle?></h1>
      <a class="swagger-download-link" href="<?=$downloadUrl?>" target="_blank" rel="noopener">
        <?=$downloadTextHtml?>
      </a>
    </header>
    <div id="swagger-ui">
      <div class="swagger-fallback" role="status" aria-live="polite">
        <p><?=$loadingLabelHtml?></p>
        <p>
          <a class="swagger-download-link" href="<?=$downloadUrl?>" target="_blank" rel="noopener">
            <?=$downloadTextHtml?>
          </a>
        </p>
      </div>
    </div>
  </main>
  <noscript>
    <div class="swagger-error">
      <p><?=$viewerFailedHtml?></p>
      <p><a class="swagger-download-link" href="<?=$downloadUrl?>" target="_blank" rel="noopener"><?=$downloadTextHtml?></a></p>
    </div>
  </noscript>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin="anonymous"></script>
  <script nonce="<?=htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8')?>">
    window.addEventListener('load', function () {
      var container = document.getElementById('swagger-ui');
      if (!container) {
        return;
      }

      if (typeof window.SwaggerUIBundle !== 'function') {
        container.innerHTML = '';
        var fallback = document.createElement('div');
        fallback.className = 'swagger-error';

        var message = document.createElement('p');
        message.textContent = <?=$viewerFailedJs?>;
        fallback.appendChild(message);

        var linkWrapper = document.createElement('p');
        var link = document.createElement('a');
        link.href = '<?=$downloadUrl?>';
        link.className = 'swagger-download-link';
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = <?=$downloadTextJs?>;
        linkWrapper.appendChild(link);
        fallback.appendChild(linkWrapper);

        container.appendChild(fallback);
        return;
      }

      SwaggerUIBundle({
        url: '<?=htmlspecialchars($openapiUrl, ENT_QUOTES, 'UTF-8')?>',
        dom_id: '#swagger-ui',
        withCredentials: true,
        persistAuthorization: true,
        requestInterceptor: function (request) {
          request.credentials = 'include';
          return request;
        }
      });
    });
  </script>
</body>
</html>
