<?php

namespace Tests\Feature;

use App\Models\Api\Convenio;
use App\Models\Api\Representante;
use App\Models\Api\TipoInstrumento;
use App\Models\Api\UnidadResponsable;
use App\Models\Api\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConvenioDraftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_approval_is_followed_by_admin_signature_and_is_listed_as_signed(): void
    {
        $reviewer = User::create([
            'name' => 'Revisor',
            'email' => 'revisor@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        $admin = User::create([
            'name' => 'Administración',
            'email' => 'admin@example.test',
            'password' => 'password',
            'rol' => 'administracion',
        ]);
        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $unidadSecundaria = UnidadResponsable::create(['nombre' => 'Rectorado', 'tipo' => 'Administrativa']);
        $tipo = TipoInstrumento::create(['nombre' => 'Convenio marco', 'categoria' => 'Colaboración']);
        $convenio = Convenio::create([
            'fecha_creacion' => now()->toDateString(),
            'titulo' => 'Convenio para aprobación y firma',
            'estado' => 'revision',
            'codigo' => 'CONV-FIRMA-001',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_responsable_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidadSecundaria->id,
        ]);

        Sanctum::actingAs($reviewer);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'estado' => 'aprobado',
            'fundamento' => 'Aprobado en revisión',
        ])->assertOk()->assertJsonPath('estado', 'aprobado');
        $this->assertDatabaseHas('notificaciones', [
            'user_id' => $admin->id,
            'convenio_id' => $convenio->id,
            'tipo' => 'cambio_datos',
            'titulo' => 'Cambio en un convenio',
        ]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'estado' => 'firmado',
            'fundamento' => 'Firmado por Administración',
        ])->assertOk()
            ->assertJsonPath('estado', 'firmado')
            ->assertJsonPath('firma_decano', true);

        $this->assertDatabaseHas('convenios', [
            'id' => $convenio->id,
            'estado' => 'firmado',
            'firma_decano' => true,
        ]);

        $usuarios = [
            $reviewer,
            $admin,
            User::create([
                'name' => 'Gestor',
                'email' => 'gestor@example.test',
                'password' => 'password',
                'rol' => 'gestion',
            ]),
            User::create([
                'name' => 'Consulta',
                'email' => 'consulta@example.test',
                'password' => 'password',
                'rol' => 'consulta',
            ]),
            User::create([
                'name' => 'Decano',
                'email' => 'decano@example.test',
                'password' => 'password',
                'rol' => 'decano',
            ]),
        ];

        foreach ($usuarios as $usuario) {
            Sanctum::actingAs($usuario);
            $this->getJson('/api/convenios?estado=firmado')
                ->assertOk()
                ->assertJsonPath('data.0.id', $convenio->id);
        }
    }

    public function test_only_reviewers_can_approve_and_only_admins_can_sign(): void
    {
        $gestor = User::create([
            'name' => 'Gestor',
            'email' => 'gestor@example.test',
            'password' => 'password',
            'rol' => 'gestion',
        ]);
        $reviewer = User::create([
            'name' => 'Revisor',
            'email' => 'revisor@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $tipo = TipoInstrumento::create(['nombre' => 'Convenio marco', 'categoria' => 'Colaboración']);
        $convenio = Convenio::create([
            'fecha_creacion' => now()->toDateString(),
            'titulo' => 'Convenio con acciones limitadas por rol',
            'estado' => 'revision',
            'codigo' => 'CONV-ROLES-001',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_responsable_id' => $unidad->id,
        ]);

        Sanctum::actingAs($gestor);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'estado' => 'aprobado',
            'fundamento' => 'Aprobado en revisión',
        ])->assertForbidden();

        $convenio->update(['estado' => 'aprobado']);
        Sanctum::actingAs($reviewer);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'estado' => 'firmado',
            'fundamento' => 'Firmado por Administración',
        ])->assertForbidden();
        $this->putJson("/api/convenios/{$convenio->id}", [
            'firma_decano' => true,
        ])->assertForbidden();
    }

    public function test_reviewer_cannot_approve_without_a_distinct_secondary_unit(): void
    {
        $reviewer = User::create([
            'name' => 'Revisor',
            'email' => 'revisor@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $tipo = TipoInstrumento::create(['nombre' => 'Convenio marco', 'categoria' => 'Colaboración']);
        $convenio = Convenio::create([
            'fecha_creacion' => now()->toDateString(),
            'titulo' => 'Convenio sin segunda unidad',
            'estado' => 'revision',
            'codigo' => 'CONV-SIN-UNIDAD-001',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_responsable_id' => $unidad->id,
        ]);

        Sanctum::actingAs($reviewer);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'estado' => 'aprobado',
            'fundamento' => 'Aprobado en revisión',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['estado']);
    }

    public function test_it_allows_a_draft_to_use_the_same_primary_and_secondary_unit(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Gestor',
            'email' => 'gestor@example.test',
            'password' => 'password',
            'rol' => 'gestion',
        ]));

        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $tipo = TipoInstrumento::create(['nombre' => 'Convenio marco', 'categoria' => 'Colaboración']);

        $this->postJson('/api/convenios', [
            'titulo' => 'Convenio sin segunda unidad',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_id' => $unidad->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['unidad_responsable_secundaria_id']);

        $this->postJson('/api/convenios', [
            'titulo' => 'Convenio con una sola unidad',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidad->id,
        ])->assertCreated()
            ->assertJsonPath('estado', 'borrador')
            ->assertJsonPath('unidad.id', $unidad->id)
            ->assertJsonPath('unidad_responsable_secundaria.id', $unidad->id);

        $this->assertDatabaseHas('convenios', [
            'titulo' => 'Convenio con una sola unidad',
            'unidad_responsable_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidad->id,
            'estado' => 'borrador',
        ]);
    }

    public function test_it_creates_a_draft_with_form_data_and_generated_metadata(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Gestor',
            'email' => 'gestor@example.test',
            'password' => 'password',
            'rol' => 'gestion',
        ]));

        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $unidadSecundaria = UnidadResponsable::create(['nombre' => 'Rectorado', 'tipo' => 'Administrativa']);
        $tipo = TipoInstrumento::create(['nombre' => 'Pasantía', 'categoria' => 'Pasantía']);

        $response = $this->postJson('/api/convenios', [
            'titulo' => 'Convenio de prueba',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidadSecundaria->id,
            'representantes' => [[
                'nombre' => 'Ana',
                'apellido' => 'Pérez',
                'cargo' => 'Directora',
                'email' => 'ana@example.test',
                'dni' => '12345678',
                'cuil' => '20-12345678-9',
            ]],
            'detalle_pasantia' => [
                'fecha_inicio_pasantia' => '2027-01-10',
                'fecha_fin_pasantia' => '2027-06-10',
                'carga_horaria_semanal' => '20',
                'asignacion_estimulo' => '150000',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('estado', 'borrador')
            ->assertJsonPath('unidad.id', $unidad->id)
            ->assertJsonPath('unidad_responsable_secundaria.id', $unidadSecundaria->id)
            ->assertJsonPath('representantes.0.apellido', 'Pérez');
        $this->assertSame(
            '2027-01-10',
            substr($response->json('detalle_pasantia.fecha_inicio'), 0, 10)
        );

        $convenioId = $response->json('id');
        $this->assertMatchesRegularExpression('/^CONV-\d{4}-[A-Z0-9]{8}$/', $response->json('codigo'));
        $this->assertDatabaseHas('convenios', [
            'id' => $convenioId,
            'estado' => 'borrador',
            'unidad_responsable_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidadSecundaria->id,
        ]);
        $this->assertDatabaseHas('partes', ['convenio_id' => $convenioId]);
        $this->assertDatabaseHas('representantes', [
            'nombre' => 'Ana',
            'apellido' => 'Pérez',
            'dni' => '12345678',
        ]);
        $this->assertDatabaseHas('detalle_pasantias', [
            'convenio_id' => $convenioId,
            'carga_horaria_semanal' => '20',
            'asignacion_estimulo' => '150000',
        ]);

        $this->getJson('/api/convenios?estado=borrador')
            ->assertOk()
            ->assertJsonPath('data.0.id', $convenioId);

        Sanctum::actingAs(User::create([
            'name' => 'Administrador',
            'email' => 'admin@example.test',
            'password' => 'password',
            'rol' => 'administracion',
        ]));
        $this->getJson('/api/convenios?estado=borrador')
            ->assertOk()
            ->assertJsonPath('data.0.id', $convenioId);
    }

    public function test_it_persists_the_selected_reviewer_when_sending_a_draft_to_review(): void
    {
        $gestor = User::create([
            'name' => 'Gestor',
            'email' => 'gestor@example.test',
            'password' => 'password',
            'rol' => 'gestion',
        ]);
        $revisor = User::create([
            'name' => 'Revisor',
            'email' => 'revisor@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        Sanctum::actingAs($gestor);

        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $unidadSecundaria = UnidadResponsable::create(['nombre' => 'Rectorado', 'tipo' => 'Administrativa']);
        $tipo = TipoInstrumento::create(['nombre' => 'Convenio marco', 'categoria' => 'Colaboración']);
        $convenio = $this->postJson('/api/convenios', [
            'titulo' => 'Convenio para revisión',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidadSecundaria->id,
        ])->assertCreated()->json();

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $revisor->id);

        $this->putJson("/api/convenios/{$convenio['id']}", [
            'estado' => 'revision',
            'revisor_id' => $revisor->id,
            'fundamento' => 'Asignación para revisión',
        ])->assertOk()
            ->assertJsonPath('estado', 'revision')
            ->assertJsonPath('revisor.id', $revisor->id);

        $this->assertDatabaseHas('convenios', [
            'id' => $convenio['id'],
            'estado' => 'revision',
            'revisor_id' => $revisor->id,
        ]);
    }

    public function test_convenio_listing_includes_status_history_for_returned_drafts(): void
    {
        $gestor = User::create([
            'name' => 'Gestor',
            'email' => 'gestor@example.test',
            'password' => 'password',
            'rol' => 'gestion',
        ]);
        $revisor = User::create([
            'name' => 'Revisor',
            'email' => 'revisor@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        Sanctum::actingAs($gestor);

        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $tipo = TipoInstrumento::create(['nombre' => 'Convenio marco', 'categoria' => 'Colaboración']);
        $convenio = Convenio::create([
            'fecha_creacion' => now()->toDateString(),
            'titulo' => 'Convenio devuelto para corregir',
            'estado' => 'borrador',
            'codigo' => 'CONV-DEVUELTO-001',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_responsable_id' => $unidad->id,
        ]);
        $convenio->historialEstados()->create([
            'user_id' => $revisor->id,
            'fundamento' => 'Completar documentación',
            'fecha_cambio' => now(),
            'estado_anterior' => 'revision',
            'estado_nuevo' => 'borrador',
        ]);

        $this->getJson('/api/convenios?estado=borrador')
            ->assertOk()
            ->assertJsonPath('data.0.id', $convenio->id)
            ->assertJsonPath('data.0.historial_estados.0.estado_anterior', 'revision')
            ->assertJsonPath('data.0.historial_estados.0.estado_nuevo', 'borrador');
    }

    public function test_gestor_can_correct_a_returned_draft_and_resend_it_to_the_same_reviewer(): void
    {
        $gestor = User::create([
            'name' => 'Gestor',
            'email' => 'gestor@example.test',
            'password' => 'password',
            'rol' => 'gestion',
        ]);
        $revisor = User::create([
            'name' => 'Revisor',
            'email' => 'revisor@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $unidadSecundaria = UnidadResponsable::create(['nombre' => 'Rectorado', 'tipo' => 'Administrativa']);
        $tipo = TipoInstrumento::create(['nombre' => 'Pasantía', 'categoria' => 'Pasantía']);
        $convenio = Convenio::create([
            'fecha_creacion' => now()->toDateString(),
            'titulo' => 'Convenio devuelto',
            'estado' => 'borrador',
            'codigo' => 'CONV-CORREGIR-001',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_responsable_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidadSecundaria->id,
        ]);
        $convenio->historialEstados()->create([
            'user_id' => $revisor->id,
            'fundamento' => 'Completar los datos',
            'fecha_cambio' => now(),
            'estado_anterior' => 'revision',
            'estado_nuevo' => 'borrador',
        ]);
        $representanteAnterior = Representante::create([
            'nombre' => 'Ana',
            'email' => 'ana@example.test',
            'cuil' => '20-12345678-9',
            'telefono' => '123',
            'unidad_responsable_id' => $unidad->id,
        ]);
        $convenio->representantes()->attach($representanteAnterior);
        $convenio->detallePasantia()->create([
            'fecha_inicio' => '2027-01-01',
            'fecha_fin' => '2027-02-01',
            'carga_horaria_semanal' => '10',
            'asignacion_estimulo' => '1000',
        ]);

        Sanctum::actingAs($gestor);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'titulo' => 'Convenio corregido',
            'unidad_responsable_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidadSecundaria->id,
            'representantes' => [[
                'nombre' => 'Lucía',
                'apellido' => 'Pérez',
                'cargo' => 'Directora',
                'email' => 'lucia@example.test',
                'dni' => '12345678',
                'cuil' => '27-12345678-9',
            ]],
            'detalle_pasantia' => [
                'fecha_inicio_pasantia' => '2027-03-01',
                'fecha_fin_pasantia' => '2027-06-01',
                'carga_horaria_semanal' => '20',
                'asignacion_estimulo' => '2000',
            ],
            'estado' => 'revision',
            'revisor_id' => $revisor->id,
            'fundamento' => 'Se corrigieron los datos',
        ])->assertOk()
            ->assertJsonPath('estado', 'revision')
            ->assertJsonPath('titulo', 'Convenio corregido')
            ->assertJsonPath('revisor.id', $revisor->id);

        $this->assertDatabaseHas('detalle_pasantias', [
            'convenio_id' => $convenio->id,
            'carga_horaria_semanal' => '20',
        ]);
        $detalleActualizado = $convenio->fresh()->detallePasantia;
        $this->assertSame('2027-03-01', $detalleActualizado->fecha_inicio->format('Y-m-d'));
        $this->assertSame('2027-06-01', $detalleActualizado->fecha_fin->format('Y-m-d'));
        $this->assertDatabaseHas('representantes', [
            'nombre' => 'Lucía',
            'apellido' => 'Pérez',
        ]);
        $this->assertDatabaseMissing('partes', [
            'convenio_id' => $convenio->id,
            'representante_id' => $representanteAnterior->id,
        ]);
        $this->assertDatabaseHas('historial_estados', [
            'convenio_id' => $convenio->id,
            'estado_anterior' => 'borrador',
            'estado_nuevo' => 'revision',
            'fundamento' => 'Se corrigieron los datos',
        ]);
        $this->assertDatabaseHas('notificaciones', [
            'user_id' => $revisor->id,
            'convenio_id' => $convenio->id,
            'mensaje' => 'Se te asignó el convenio CONV-CORREGIR-001: Convenio corregido. Fundamento: Se corrigieron los datos',
        ]);
    }

    public function test_a_returned_draft_cannot_be_sent_to_a_different_reviewer(): void
    {
        $gestor = User::create([
            'name' => 'Gestor',
            'email' => 'gestor@example.test',
            'password' => 'password',
            'rol' => 'gestion',
        ]);
        $revisorQueDevolvio = User::create([
            'name' => 'Revisor que devolvió',
            'email' => 'revisor1@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        $otroRevisor = User::create([
            'name' => 'Otro revisor',
            'email' => 'revisor2@example.test',
            'password' => 'password',
            'rol' => 'revision',
        ]);
        $unidad = UnidadResponsable::create(['nombre' => 'Ingeniería', 'tipo' => 'Facultad']);
        $unidadSecundaria = UnidadResponsable::create(['nombre' => 'Rectorado', 'tipo' => 'Administrativa']);
        $tipo = TipoInstrumento::create(['nombre' => 'Convenio marco', 'categoria' => 'Colaboración']);
        $convenio = Convenio::create([
            'fecha_creacion' => now()->toDateString(),
            'titulo' => 'Convenio devuelto',
            'estado' => 'borrador',
            'codigo' => 'CONV-OTRO-REVISOR-001',
            'tipo_instrumento_id' => $tipo->id,
            'unidad_responsable_id' => $unidad->id,
            'unidad_responsable_secundaria_id' => $unidadSecundaria->id,
        ]);
        $convenio->historialEstados()->create([
            'user_id' => $revisorQueDevolvio->id,
            'fundamento' => 'Completar los datos',
            'fecha_cambio' => now(),
            'estado_anterior' => 'revision',
            'estado_nuevo' => 'borrador',
        ]);

        Sanctum::actingAs($gestor);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'estado' => 'revision',
            'revisor_id' => $otroRevisor->id,
            'fundamento' => 'Se corrigieron los datos',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['revisor_id']);

        Sanctum::actingAs($revisorQueDevolvio);
        $this->putJson("/api/convenios/{$convenio->id}", [
            'estado' => 'revision',
            'revisor_id' => $revisorQueDevolvio->id,
            'fundamento' => 'Se corrigieron los datos',
        ])->assertForbidden();

        $this->assertDatabaseHas('convenios', [
            'id' => $convenio->id,
            'estado' => 'borrador',
            'revisor_id' => null,
        ]);
    }
}
