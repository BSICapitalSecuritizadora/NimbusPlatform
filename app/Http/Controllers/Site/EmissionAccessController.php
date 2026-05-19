<?php

namespace App\Http\Controllers\Site;

use App\Actions\Emissions\SendEmissionAccessCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmissionAccessRequest;
use App\Http\Requests\VerifyEmissionAccessRequest;
use App\Models\Emission;
use App\Models\EmissionAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmissionAccessController extends Controller
{
    public function __construct(
        protected SendEmissionAccessCode $sendEmissionAccessCode,
    ) {}

    public function show(Request $request, EmissionAccess $access): View|RedirectResponse
    {
        $access->loadMissing('emission');

        if (! $access->isActive()) {
            return redirect()
                ->route('site.emissions.show', $access->emission->if_code)
                ->withErrors([
                    'code' => 'Este código expirou ou foi revogado. Solicite um novo acesso para consultar a operação.',
                ]);
        }

        $access->markLinkOpened();

        if ($this->hasAuthorizedSession($request, $access) || $this->currentInvestorCanView($request, $access->emission)) {
            return redirect()->route('site.emissions.show', $access->emission->if_code);
        }

        return view('site.emission-access', [
            'emission' => $access->emission,
            'access' => $access,
        ]);
    }

    public function store(StoreEmissionAccessRequest $request, string $if_code): RedirectResponse
    {
        $emission = $this->findPublicEmission($if_code);

        try {
            $access = $this->sendEmissionAccessCode->handle($emission, $request->validated());
        } catch (\Throwable $exception) {
            Log::error('Falha ao enviar código de acesso da emissão.', [
                'emission_id' => $emission->id,
                'if_code' => $emission->if_code,
                'email' => $request->validated('email'),
                'message' => $exception->getMessage(),
            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'access_request' => 'Não foi possível enviar o código de acesso no momento. Tente novamente em alguns instantes.',
                ]);
        }

        return redirect()
            ->route('site.emissions.access.show', $access)
            ->with('success', 'Enviamos um código de acesso para o e-mail informado. Digite-o abaixo para consultar a operação.');
    }

    public function verify(VerifyEmissionAccessRequest $request, EmissionAccess $access): RedirectResponse
    {
        $access->loadMissing('emission');

        if (! $access->isActive()) {
            return redirect()
                ->route('site.emissions.show', $access->emission->if_code)
                ->withErrors([
                    'code' => 'Este código expirou ou foi revogado. Solicite um novo acesso para consultar a operação.',
                ]);
        }

        if (! $access->matchesCode($request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => 'Código de acesso inválido.',
            ]);
        }

        $request->session()->put($access->authorizationSessionKey(), $access->id);

        $access->markVerified();

        return redirect()
            ->route('site.emissions.show', $access->emission->if_code)
            ->with('success', 'Acesso validado com sucesso. Você já pode consultar as informações completas da operação.');
    }

    protected function hasAuthorizedSession(Request $request, EmissionAccess $access): bool
    {
        return (int) $request->session()->get($access->authorizationSessionKey()) === $access->id
            && $access->isVerified()
            && $access->isActive();
    }

    protected function currentInvestorCanView(Request $request, Emission $emission): bool
    {
        $investor = $request->user('investor');

        if (! $investor) {
            return false;
        }

        return $emission->investors()->whereKey($investor->id)->exists();
    }

    protected function findPublicEmission(string $if_code): Emission
    {
        return Emission::query()
            ->where('if_code', $if_code)
            ->where('is_public', true)
            ->firstOrFail();
    }
}
