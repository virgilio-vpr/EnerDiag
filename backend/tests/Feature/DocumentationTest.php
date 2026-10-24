<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    public function test_scribe_documentation_generates_without_error(): void
    {
        $exitCode = Artisan::call('scribe:generate');

        $this->assertSame(0, $exitCode);
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
