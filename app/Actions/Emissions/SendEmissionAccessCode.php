<?php

namespace App\Actions\Emissions;

use App\Mail\EmissionAccessCodeMail;
use App\Models\Emission;
use App\Models\EmissionAccess;
use Illuminate\Support\Facades\Mail;

class SendEmissionAccessCode
{
    public function __construct(
        protected CreateEmissionAccess $createEmissionAccess,
    ) {}

    /**
     * @param  array{name:string,email:string,phone:string}  $payload
     */
    public function handle(Emission $emission, array $payload): EmissionAccess
    {
        ['access' => $access, 'code' => $code] = $this->createEmissionAccess->handle($emission, $payload);

        $accessUrl = route('site.emissions.access.show', $access);

        Mail::mailer((string) config('nimbus.mail.mailer', config('mail.default', 'log')))
            ->to($access->requester_email)
            ->send(
                new EmissionAccessCodeMail($emission, $access, $code, $accessUrl),
            );

        return $access;
    }
}
