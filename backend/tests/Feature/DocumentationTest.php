<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_scribe_documentation_generates_without_error(): void
    {
        $exitCode = Artisan::call('scribe:generate');

        $this->assertSame(0, $exitCode);
        $this->assertGeneratedDocumentationCoversEveryApiRoute();
    }

    private function assertGeneratedDocumentationCoversEveryApiRoute(): void
    {
        $openApiPath = storage_path('app/private/scribe/openapi.yaml');
        $postmanPath = storage_path('app/private/scribe/collection.json');
        $this->assertFileExists($openApiPath);
        $this->assertFileExists($postmanPath);

        $openApi = Yaml::parseFile($openApiPath);
        $postman = str_replace('\\/', '/', (string) file_get_contents($postmanPath));
        $expectedOperations = [
            '/api/v1/status' => ['get'],
            '/api/v1/distributors' => ['get'],
            '/api/v1/auth/register' => ['post'],
            '/api/v1/auth/verify-email' => ['post'],
            '/api/v1/auth/resend-code' => ['post'],
            '/api/v1/auth/login' => ['post'],
            '/api/v1/auth/logout' => ['post'],
            '/api/v1/auth/forgot-password' => ['post'],
            '/api/v1/auth/reset-password' => ['post'],
            '/api/v1/leads' => ['post'],
            '/api/v1/admin/customers' => ['get'],
            '/api/v1/admin/customers/{id}' => ['get'],
            '/api/v1/admin/simulations' => ['get'],
            '/api/v1/admin/simulations/{id}' => ['get'],
            '/api/v1/admin/simulations/{id}/recalculate' => ['post'],
            '/api/v1/admin/reports/{id}' => ['put'],
            '/api/v1/admin/reports/{id}/resend' => ['post'],
            '/api/v1/admin/tariffs' => ['get', 'post'],
            '/api/v1/admin/tariffs/{id}' => ['put'],
            '/api/v1/admin/tariffs/import' => ['post'],
            '/api/v1/admin/parameters' => ['get', 'post'],
            '/api/v1/admin/leads' => ['get'],
            '/api/v1/simulations' => ['get', 'post'],
            '/api/v1/simulations/{simulationId}/status' => ['get'],
            '/api/v1/simulations/{simulationId}/report' => ['get'],
            '/api/v1/simulations/{simulationId}/pdf' => ['get'],
            '/api/v1/me' => ['get', 'put', 'delete'],
            '/api/v1/me/contact-request' => ['post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            $this->assertArrayHasKey($path, $openApi['paths'], "Missing OpenAPI path {$path}");
            $postmanPath = str_replace(['{id}', '{simulationId}'], [':id', ':simulationId'], $path);
            $this->assertStringContainsString($postmanPath, $postman, "Missing Postman path {$path}");
            foreach ($methods as $method) {
                $this->assertArrayHasKey($method, $openApi['paths'][$path], "Missing {$method} {$path}");
                $responses = $openApi['paths'][$path][$method]['responses'];
                $this->assertNotEmpty(array_filter(array_keys($responses), fn (int|string $status): bool => (int) $status >= 200 && (int) $status < 300), "Missing success example for {$method} {$path}");
                $this->assertNotEmpty(array_filter(array_keys($responses), fn (int|string $status): bool => (int) $status >= 400), "Missing error example for {$method} {$path}");
            }
        }
    }

    public function test_docs_page_is_accessible_and_documents_success_and_error_responses(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);

        // Verify the example endpoint is documented with success and error formats
        $response->assertSee('Exemplos');
        $response->assertSee('Validação de Exemplo');
        $response->assertSee('200');
        $response->assertSee('Dados validados com sucesso.');
        $response->assertSee('422');
        $response->assertSee('VALIDATION_ERROR');
        $response->assertSee('Authorization: Bearer');
        $response->assertDontSee('/api/v1/test-validation');
    }

    public function test_documented_example_endpoint_returns_the_documented_success_and_validation_error(): void
    {
        $this->postJson('/api/v1/example/validate', [
            'email' => 'contato@empresa.com.br',
        ])->assertOk()->assertExactJson([
            'status' => 'ok',
            'message' => 'Dados validados com sucesso.',
            'data' => ['email' => 'contato@empresa.com.br'],
        ]);

        $this->postJson('/api/v1/example/validate', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'O campo e-mail é obrigatório.',
                'code' => 'VALIDATION_ERROR',
                'errors' => ['email' => ['O campo e-mail é obrigatório.']],
            ]);
    }
}
