<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'category' => fake()->randomElement([
                'anuncios',
                'assembleias',
                'convocacoes_assembleias',
                'demonstracoes_financeiras',
                'documentos_operacao',
                'fatos_relevantes',
                'relatorios_anuais',
            ]),
            'file_path' => 'documents/'.fake()->uuid().'.pdf',
            'file_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(1024, 10485760),
            'is_published' => false,
            'is_public' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
            'is_public' => true,
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
            'is_public' => false,
        ]);
    }

    public function replaced(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
            'replaced_at' => now(),
        ]);
    }
}
