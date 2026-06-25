<?php

$content = file_get_contents('app/Filament/Resources/Emissions/EmissionResource/RelationManagers/ObligationsRelationManager.php');

$newViewAction = <<<PHP
                \Filament\Tables\Actions\ViewAction::make()
                    ->label('Acessar Dossiê')
                    ->color('info')
                    ->authorize(fn (): bool => auth()->user()?->can(\App\Enums\AccessPermission::ObligationsView->value) ?? false)
                    ->extraModalFooterActions(fn (\App\Models\Obligation \$record) => [
                        \$this->makeSubmitForReviewAction()->record(\$record)->visible(fn () => \$this->canRunWorkflowAction(\$record, \App\Services\Obligations\ObligationWorkflowService::TRANSITION_SUBMIT_FOR_REVIEW)),
                        \$this->makeCompleteAction()->record(\$record)->visible(fn () => \$this->canRunWorkflowAction(\$record, \App\Services\Obligations\ObligationWorkflowService::TRANSITION_COMPLETE)),
                        \$this->makeMarkNotApplicableAction()->record(\$record)->visible(fn () => \$this->canRunWorkflowAction(\$record, \App\Services\Obligations\ObligationWorkflowService::TRANSITION_MARK_NOT_APPLICABLE)),
                        \$this->makeReopenAction()->record(\$record)->visible(fn () => \$this->canRunWorkflowAction(\$record, \App\Services\Obligations\ObligationWorkflowService::TRANSITION_REOPEN)),
                    ]),
PHP;

$content = preg_replace('/\\\\Filament\\\\Tables\\\\Actions\\\\ViewAction::make\(\)\s*->label\(\'Acessar Dossiê\'\)\s*->color\(\'info\'\)\s*->authorize\(fn \(\): bool => auth\(\)->user\(\)\?->can\(\\\\App\\\\Enums\\\\AccessPermission::ObligationsView->value\) \?\? false\),/s', $newViewAction, $content);

file_put_contents('app/Filament/Resources/Emissions/EmissionResource/RelationManagers/ObligationsRelationManager.php', $content);
echo "ViewAction updated.\n";
