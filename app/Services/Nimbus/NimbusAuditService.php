<?php

namespace App\Services\Nimbus;

use App\Models\Nimbus\PortalUser;
use App\Services\Security\PiiPseudonymizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Centralized Nimbus audit helper.
 * Uses Spatie activity log ('nimbus' log_name) with pseudonymized PII.
 */
class NimbusAuditService
{
    public static function logPortalUserCreated(PortalUser $portalUser, ?Authenticatable $causer = null): void
    {
        activity('nimbus')
            ->performedOn($portalUser)
            ->causedBy($causer)
            ->withProperties([
                'email_hash' => PiiPseudonymizer::email($portalUser->email),
                'document_hash' => PiiPseudonymizer::document($portalUser->getAttribute('document_number')),
                'status' => $portalUser->status,
            ])
            ->log('nimbus.portal_user.created');
    }

    public static function logPortalUserUpdated(PortalUser $portalUser, ?Authenticatable $causer = null, array $changedKeys = []): void
    {
        activity('nimbus')
            ->performedOn($portalUser)
            ->causedBy($causer)
            ->withProperties([
                'changed_keys' => $changedKeys,
                'email_hash' => PiiPseudonymizer::email($portalUser->email),
                'status' => $portalUser->status,
            ])
            ->log('nimbus.portal_user.updated');
    }

    public static function logAccessTokenGenerated(PortalUser $portalUser, Model $token, ?Authenticatable $causer = null): void
    {
        activity('nimbus')
            ->performedOn($token)
            ->causedBy($causer)
            ->withProperties([
                'portal_user_id' => $portalUser->id,
                'email_hash' => PiiPseudonymizer::email($portalUser->email),
                'expires_at' => $token->getAttribute('expires_at')?->toIso8601String(),
            ])
            ->log('nimbus.access_token.generated');
    }

    public static function logAccessTokenRevoked(Model $token, ?Authenticatable $causer = null): void
    {
        activity('nimbus')
            ->performedOn($token)
            ->causedBy($causer)
            ->withProperties([
                'portal_user_id' => $token->getAttribute('nimbus_portal_user_id'),
            ])
            ->log('nimbus.access_token.revoked');
    }

    public static function logAccessTokenUsed(Model $token, Request $request): void
    {
        activity('nimbus')
            ->performedOn($token)
            ->causedBy($token->portalUser)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ])
            ->log('nimbus.access_token.used');
    }

    public static function logFileEvent(
        string $event,
        Model $file,
        ?Authenticatable $actor,
        ?Request $request = null,
        array $extra = [],
    ): void {
        $properties = array_merge([
            'file_id' => $file->getKey(),
            'submission_id' => $file->getAttribute('nimbus_submission_id'),
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ], $extra);

        activity('nimbus')
            ->performedOn($file)
            ->causedBy($actor)
            ->withProperties($properties)
            ->log($event);
    }

    public static function logDocumentEvent(
        string $event,
        Model $document,
        ?Authenticatable $actor,
        ?Request $request = null,
    ): void {
        activity('nimbus')
            ->performedOn($document)
            ->causedBy($actor)
            ->withProperties([
                'document_id' => $document->getKey(),
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            ])
            ->log($event);
    }
}
