<section class="min-h-screen bg-[linear-gradient(180deg,rgba(248,250,252,0.94),rgba(255,255,255,0.98)),radial-gradient(circle_at_top,rgba(0,32,91,0.09),transparent_42%)] py-12 sm:py-16">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-[2rem] border border-[var(--border)] bg-[linear-gradient(135deg,rgba(0,18,51,0.98),rgba(5,27,74,0.95))] text-white shadow-[0_32px_80px_rgba(0,18,51,0.18)]">
            <div class="grid gap-8 px-6 py-8 sm:px-8 lg:grid-cols-[1.3fr_0.7fr] lg:px-10 lg:py-10">
                <div class="space-y-4">
                    <span class="inline-flex w-fit items-center rounded-full border border-[rgba(212,175,55,0.35)] bg-[rgba(212,175,55,0.12)] px-4 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-[var(--gold)]">
                        Oportunidade
                    </span>
                    <div class="space-y-3">
                        <h1 class="text-4xl font-black tracking-[-0.05em] sm:text-5xl">
                            Envie sua <span class="text-[var(--gold)]">Proposta</span>
                        </h1>
                        <p class="max-w-3xl text-sm leading-7 text-white/72 sm:text-base">
                            Seja para securitização, estruturação ou novos negócios, preencha o formulário abaixo para iniciarmos uma análise preliminar.
                        </p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <div class="rounded-[1.5rem] border border-white/10 bg-white/8 p-5 backdrop-blur">
                        <div class="text-[0.72rem] font-bold uppercase tracking-[0.24em] text-[var(--gold)]">Fluxo</div>
                        <div class="mt-2 text-lg font-semibold tracking-[-0.03em]">Cadastro inicial</div>
                        <div class="mt-2 text-sm text-white/68">Os dados da empresa e do contato são registrados nesta primeira etapa.</div>
                    </div>
                    <div class="rounded-[1.5rem] border border-white/10 bg-white/8 p-5 backdrop-blur">
                        <div class="text-[0.72rem] font-bold uppercase tracking-[0.24em] text-[var(--gold)]">Continuação</div>
                        <div class="mt-2 text-lg font-semibold tracking-[-0.03em]">Link seguro por e-mail</div>
                        <div class="mt-2 text-sm text-white/68">Depois do envio, você recebe um acesso protegido para complementar as informações.</div>
                    </div>
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-[1.5rem] border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800 shadow-sm">
                {{ session('success') }}
            </div>
        @endif

        @error('submission')
            <div class="rounded-[1.5rem] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700 shadow-sm">
                {{ $message }}
            </div>
        @enderror

        @if ($errors->any() && ! $errors->has('submission'))
            <div class="rounded-[1.5rem] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700 shadow-sm">
                <p class="font-semibold">Revise os campos destacados antes de continuar.</p>
            </div>
        @endif

        <form wire:submit="save" class="space-y-8">
            <div class="rounded-[2rem] border border-[var(--border)] bg-white shadow-[0_24px_60px_rgba(15,23,42,0.08)]">
                <div class="border-b border-[var(--border)] px-6 py-6 sm:px-8">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-[0.22em] text-[var(--gold)]">Etapa 1</div>
                            <h2 class="mt-2 text-2xl font-extrabold tracking-[-0.04em] text-[var(--brand)]">Dados da Empresa</h2>
                        </div>
                        <p class="max-w-xl text-sm leading-6 text-[var(--muted)]">
                            Informe o CNPJ para preencher a base inicial e ajuste os dados manualmente se necessário.
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 px-6 py-6 sm:px-8 lg:grid-cols-2">
                    <flux:field>
                        <flux:label>CNPJ</flux:label>
                        <flux:input wire:model.blur="cnpj" mask="99.999.999/9999-99" inputmode="numeric" placeholder="00.000.000/0000-00" />
                        <flux:description wire:loading wire:target="cnpj">Consultando dados públicos do CNPJ...</flux:description>
                        <flux:error name="cnpj" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Nome da Empresa</flux:label>
                        <flux:input wire:model.blur="companyName" placeholder="Razão Social" />
                        <flux:error name="companyName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Inscrição Estadual</flux:label>
                        <flux:input wire:model.blur="stateRegistration" placeholder="IE (opcional)" />
                        <flux:error name="stateRegistration" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Site</flux:label>
                        <flux:input wire:model.blur="website" type="url" placeholder="https://" />
                        <flux:error name="website" />
                    </flux:field>

                    <div class="lg:col-span-2">
                        <div class="space-y-3">
                            <div>
                                <label class="text-sm font-semibold text-zinc-700">Setores de Atuação</label>
                                <p class="mt-1 text-sm text-zinc-500">Selecione ao menos um setor ligado à proposta.</p>
                            </div>

                            <div class="flex flex-wrap gap-3">
                                @foreach ($sectors as $sector)
                                    <label wire:key="sector-{{ $sector->id }}" class="group relative cursor-pointer">
                                        <input type="checkbox" value="{{ $sector->id }}" wire:model.live="sectorIds" class="peer sr-only">
                                        <span class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-600 transition peer-checked:border-[var(--brand)] peer-checked:bg-[var(--brand)] peer-checked:text-white group-hover:border-[var(--gold)] group-hover:text-[var(--brand)]">
                                            {{ $sector->name }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>

                            @error('sectorIds')
                                <p class="text-sm font-medium text-red-600">{{ $message }}</p>
                            @enderror

                            @error('sectorIds.*')
                                <p class="text-sm font-medium text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--border)] bg-white shadow-[0_24px_60px_rgba(15,23,42,0.08)]">
                <div class="border-b border-[var(--border)] px-6 py-6 sm:px-8">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-[0.22em] text-[var(--gold)]">Etapa 2</div>
                            <h2 class="mt-2 text-2xl font-extrabold tracking-[-0.04em] text-[var(--brand)]">Localização</h2>
                        </div>
                        <p class="max-w-xl text-sm leading-6 text-[var(--muted)]">
                            Ao informar o CEP, o endereço é completado automaticamente e pode ser ajustado antes do envio.
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 px-6 py-6 sm:px-8 md:grid-cols-2 xl:grid-cols-4">
                    <flux:field class="xl:col-span-1">
                        <flux:label>CEP</flux:label>
                        <flux:input wire:model.blur="postalCode" mask="99999-999" inputmode="numeric" placeholder="00000-000" />
                        <flux:description wire:loading wire:target="postalCode">Buscando endereço pelo CEP...</flux:description>
                        <flux:error name="postalCode" />
                    </flux:field>

                    <flux:field class="xl:col-span-2">
                        <flux:label>Logradouro</flux:label>
                        <flux:input wire:model.blur="street" placeholder="Rua, Avenida..." />
                        <flux:error name="street" />
                    </flux:field>

                    <flux:field class="xl:col-span-1">
                        <flux:label>Número</flux:label>
                        <flux:input wire:model.blur="addressNumber" placeholder="Nº" />
                        <flux:error name="addressNumber" />
                    </flux:field>

                    <flux:field class="md:col-span-2 xl:col-span-1">
                        <flux:label>Complemento</flux:label>
                        <flux:input wire:model.blur="addressComplement" placeholder="Apto, Sala..." />
                        <flux:error name="addressComplement" />
                    </flux:field>

                    <flux:field class="md:col-span-2 xl:col-span-1">
                        <flux:label>Bairro</flux:label>
                        <flux:input wire:model.blur="neighborhood" placeholder="Bairro" />
                        <flux:error name="neighborhood" />
                    </flux:field>

                    <flux:field class="md:col-span-1 xl:col-span-1">
                        <flux:label>Cidade</flux:label>
                        <flux:input wire:model.blur="city" placeholder="Cidade" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field class="md:col-span-1 xl:col-span-1">
                        <flux:label>UF</flux:label>
                        <flux:input wire:model.blur="state" maxlength="2" placeholder="UF" />
                        <flux:error name="state" />
                    </flux:field>
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--border)] bg-white shadow-[0_24px_60px_rgba(15,23,42,0.08)]">
                <div class="border-b border-[var(--border)] px-6 py-6 sm:px-8">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-[0.22em] text-[var(--gold)]">Etapa 3</div>
                            <h2 class="mt-2 text-2xl font-extrabold tracking-[-0.04em] text-[var(--brand)]">Dados de Contato</h2>
                        </div>
                        <p class="max-w-xl text-sm leading-6 text-[var(--muted)]">
                            Este contato receberá o link seguro para continuar o preenchimento da proposta.
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 px-6 py-6 sm:px-8 md:grid-cols-2 xl:grid-cols-4">
                    <flux:field class="xl:col-span-2">
                        <flux:label>Nome do Contato</flux:label>
                        <flux:input wire:model.blur="contactName" placeholder="Nome completo" />
                        <flux:error name="contactName" />
                    </flux:field>

                    <flux:field class="xl:col-span-2">
                        <flux:label>E-mail</flux:label>
                        <flux:input wire:model.blur="email" type="email" placeholder="email@empresa.com.br" />
                        <flux:error name="email" />
                    </flux:field>

                    <flux:field class="xl:col-span-2">
                        <flux:label>Telefone Pessoal / Celular</flux:label>
                        <flux:input
                            wire:model.blur="personalPhone"
                            mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-9999'"
                            inputmode="tel"
                            placeholder="(00) 00000-0000"
                        />
                        <flux:error name="personalPhone" />
                    </flux:field>

                    <flux:field class="xl:col-span-1">
                        <flux:label>Telefone da Empresa</flux:label>
                        <flux:input
                            wire:model.blur="companyPhone"
                            mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-9999'"
                            inputmode="tel"
                            placeholder="(00) 0000-0000"
                        />
                        <flux:error name="companyPhone" />
                    </flux:field>

                    <flux:field class="xl:col-span-1">
                        <flux:label>Cargo</flux:label>
                        <flux:input wire:model.blur="jobTitle" placeholder="Ex: Diretor Financeiro" />
                        <flux:error name="jobTitle" />
                    </flux:field>

                    <div class="rounded-[1.5rem] border border-zinc-200 bg-zinc-50 px-5 py-4 md:col-span-2 xl:col-span-4">
                        <label class="flex items-start gap-3">
                            <flux:checkbox wire:model.live="hasWhatsapp" />
                            <span class="space-y-1">
                                <span class="block text-sm font-semibold text-zinc-800">Este contato recebe mensagens por WhatsApp</span>
                                <span class="block text-sm text-zinc-500">Use esta opção para facilitar o retorno do time comercial.</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--border)] bg-white shadow-[0_24px_60px_rgba(15,23,42,0.08)]">
                <div class="border-b border-[var(--border)] px-6 py-6 sm:px-8">
                    <div class="text-xs font-bold uppercase tracking-[0.22em] text-[var(--gold)]">Etapa 4</div>
                    <h2 class="mt-2 text-2xl font-extrabold tracking-[-0.04em] text-[var(--brand)]">Assunto e Observações</h2>
                </div>

                <div class="px-6 py-6 sm:px-8">
                    <flux:field>
                        <flux:label>Descreva brevemente sua proposta ou dúvida</flux:label>
                        <flux:textarea wire:model.blur="observations" rows="5" placeholder="Mais detalhes sobre o empreendimento ou operação..." />
                        <flux:error name="observations" />
                    </flux:field>
                </div>
            </div>

            <div class="flex flex-col gap-4 rounded-[2rem] border border-[var(--border)] bg-[linear-gradient(135deg,rgba(0,32,91,0.98),rgba(8,24,64,0.96))] px-6 py-6 text-white shadow-[0_24px_60px_rgba(0,18,51,0.18)] sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <div class="max-w-2xl">
                    <div class="text-xs font-bold uppercase tracking-[0.22em] text-[var(--gold)]">Próximo passo</div>
                    <p class="mt-2 text-sm leading-7 text-white/72 sm:text-base">
                        Após o envio, você receberá um link seguro para complementar os dados do empreendimento e anexar os documentos necessários.
                    </p>
                </div>

                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="justify-center !rounded-full !px-8 !py-3 !bg-[var(--gold)] !text-[var(--brand)] hover:!bg-[#e6c76d]"
                >
                    <span wire:loading.remove wire:target="save">Enviar Proposta</span>
                    <span wire:loading wire:target="save">Enviando...</span>
                </flux:button>
            </div>
        </form>
    </div>
</section>
