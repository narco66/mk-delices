<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Gestion des erreurs (20/11/2026) : toute erreur rendue par l'API suit le format { error: { code, message,
 * details }, request_id }, en français compréhensible, sans détail technique interne.
 */
class ErrorHandlingTest extends TestCase
{
    public function test_validation_messages_are_in_plain_french_with_readable_field_names(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['email' => 'pas-une-adresse', 'password' => 'court'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame('L’adresse e-mail doit être une adresse e-mail valide (ex. nom@exemple.com).', $response->json('error.details.email.0'));
        $this->assertSame('Le prénom est obligatoire.', $response->json('error.details.first_name.0'));
        $this->assertStringNotContainsString('validation.', $response->getContent(), 'aucune clé de traduction brute');
    }

    public function test_framework_errors_never_expose_internal_details(): void
    {
        $response = $this->deleteJson('/api/v1/config')->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed')->assertJsonPath('error.message', 'Cette opération n’est pas disponible.');
        $this->assertStringNotContainsString('route', $response->json('error.message'));
        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_message_written_in_code_with_abort_is_kept(): void
    {
        $this->actingAs($this->staff(User::ROLE_ADMIN))->getJson('/api/v1/admin/stats?from=2024-01-01&to=2026-01-01')
            ->assertStatus(422)->assertJsonPath('error.message', 'Période limitée à un an.');
    }

    public function test_maintenance_and_unexpected_errors_return_a_reference_without_leaking_the_cause(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/_test/maintenance', fn () => abort(503, '', ['Retry-After' => 60]));
        Route::get('/api/v1/_test/crash', fn () => throw new RuntimeException('SQLSTATE secret interne'));

        $this->getJson('/api/v1/_test/maintenance')->assertStatus(503)->assertHeader('Retry-After', '60')
            ->assertJsonPath('error.code', 'maintenance')->assertJsonPath('error.details.retry_after', 60);

        $crash = $this->getJson('/api/v1/_test/crash')->assertStatus(500)->assertJsonPath('error.code', 'server_error');
        $this->assertStringNotContainsString('SQLSTATE', $crash->getContent());
        $this->assertStringContainsString($crash->json('request_id'), $crash->json('error.message'), 'référence donnée à l’utilisateur');
    }
}
