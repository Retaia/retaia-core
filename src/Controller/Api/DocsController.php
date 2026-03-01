<?php

namespace App\Controller\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DocsController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    #[Route('/api/{version}/docs', name: 'api_docs', requirements: ['version' => 'v[0-9]+(?:\.[0-9]+)?'], methods: ['GET'])]
    public function docs(string $version): Response
    {
        if ($this->resolveOpenApiPath($version) === null) {
            return new Response('OpenAPI version not found.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $openApiUrl = '/api/'.$version.'/openapi';
        $escapedOpenApiUrl = htmlspecialchars($openApiUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Retaia API Docs ({$version})</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css" />
    <style>
      html, body { margin: 0; padding: 0; }
      body { background: #fafafa; }
    </style>
  </head>
  <body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
      window.onload = function () {
        SwaggerUIBundle({
          url: '{$escapedOpenApiUrl}',
          dom_id: '#swagger-ui',
          deepLinking: true,
          presets: [SwaggerUIBundle.presets.apis],
          layout: 'BaseLayout'
        });
      };
    </script>
  </body>
</html>
HTML;

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    #[Route('/api/{version}/openapi', name: 'api_openapi', requirements: ['version' => 'v[0-9]+(?:\.[0-9]+)?'], methods: ['GET'])]
    public function openApi(string $version): Response
    {
        $openApiPath = $this->resolveOpenApiPath($version);
        if ($openApiPath === null) {
            return new Response('OpenAPI version not found.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $content = file_get_contents($openApiPath);
        if (!is_string($content)) {
            return new Response('Unable to read OpenAPI file.', Response::HTTP_INTERNAL_SERVER_ERROR, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response($content, Response::HTTP_OK, ['Content-Type' => 'application/yaml; charset=UTF-8']);
    }

    private function resolveOpenApiPath(string $version): ?string
    {
        $path = $this->projectDir.'/specs/api/openapi/'.$version.'.yaml';
        if (!is_file($path)) {
            return null;
        }

        return $path;
    }
}
