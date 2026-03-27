<?php

namespace Database\Factories;

use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProposalStatusHistory>
 */
class ProposalStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => function (): int {
                $company = ProposalCompany::query()->create([
                    'name' => fake()->company(),
                    'cnpj' => fake()->unique()->numerify('##.###.###/####-##'),
                ]);

                $contact = ProposalContact::query()->create([
                    'company_id' => $company->id,
                    'name' => fake()->name(),
                    'email' => fake()->unique()->safeEmail(),
                ]);

                return Proposal::query()->create([
                    'company_id' => $company->id,
                    'contact_id' => $contact->id,
                    'status' => Proposal::STATUS_IN_REVIEW,
                ])->id;
            },
            'previous_status' => fake()->randomElement([
                null,
                'aguardando_complementacao',
                'em_analise',
                'aguardando_informacoes',
                'aprovado',
            ]),
            'new_status' => fake()->randomElement([
                'em_analise',
                'aguardando_informacoes',
                'aprovado',
                'rejeitado',
            ]),
            'changed_by_user_id' => User::factory(),
            'note' => fake()->optional()->sentence(),
            'changed_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }
}
