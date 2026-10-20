<?php

namespace App\Core\Sso\Http\Requests;

use App\Core\Sso\Domains\SsoDomains;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Saml\SamlMetadata;
use App\Core\Sso\SsoConnections;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * AUTH-11, ADR 023: add (POST) or change (PATCH) a connection.
 *
 * - Entra ID: client id and directory id (GUIDs: `common`, `organizations`
 *   and `consumers` are refused), client secret.
 * - Google Workspace: client id, client secret, optional hosted domain.
 * - SAML: IdP entity id, sign-in URL (https) and one or two signing
 *   certificates, typed or read from metadata (pasted XML or a URL).
 *
 * The client secret is required when a connection is added and is
 * replaced only when sent.
 */
class SaveSsoConnectionRequest extends SsoManageRequest
{
    public const DOMAIN_PATTERN = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    protected function prepareForValidation(): void
    {
        foreach (['name', 'client_id', 'directory_id', 'idp_entity_id', 'idp_sso_url', 'metadata_url'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if (is_string($this->input('hosted_domain'))) {
            $hd = Str::lower(trim($this->input('hosted_domain')));
            $this->merge(['hosted_domain' => $hd === '' ? null : $hd]);
        }
    }

    public function provider(): string
    {
        return $this->creating() ? (string) $this->input('provider') : $this->connection()->provider;
    }

    public function rules(): array
    {
        $provider = $this->provider();
        $new = $this->creating();
        $saml = $provider === SsoConnection::SAML;
        $fromMetadata = $saml && ($this->filled('metadata_xml') || $this->filled('metadata_url'));
        $required = fn (bool $when) => $new && $when ? 'required' : 'sometimes';

        return [
            'provider' => $new ? ['required', 'string', Rule::in(SsoConnection::PROVIDERS)] : ['prohibited'],
            'name' => [$required(true), 'string', 'max:100'],
            'client_id' => $saml ? ['prohibited'] : [$required(true), 'string', 'max:255', ...($provider === SsoConnection::ENTRA ? ['uuid'] : [])],
            'client_secret' => $saml ? ['prohibited'] : [$required(true), 'string', 'max:1000'],
            'directory_id' => $provider === SsoConnection::ENTRA ? [$required(true), 'uuid'] : ['prohibited'],
            'hosted_domain' => $provider === SsoConnection::GOOGLE ? ['sometimes', 'nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN] : ['prohibited'],
            'metadata_xml' => $saml ? ['sometimes', 'nullable', 'string', 'max:'.(int) config('sso.metadata_max_bytes')] : ['prohibited'],
            'metadata_url' => $saml ? ['sometimes', 'nullable', 'string', 'max:2048', 'url:https'] : ['prohibited'],
            'idp_entity_id' => $saml ? [$required(! $fromMetadata), 'string', 'max:1024'] : ['prohibited'],
            'idp_sso_url' => $saml ? [$required(! $fromMetadata), 'string', 'max:2048', 'url:https'] : ['prohibited'],
            'idp_certificates' => $saml ? [$required(! $fromMetadata), 'array', 'min:1', 'max:'.SamlMetadata::MAX_CERTIFICATES] : ['prohibited'],
            'idp_certificates.*' => ['string', 'max:20000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // ADR 023: a Google Workspace domain the tenant has not proven is never trusted.
            $hd = $this->input('hosted_domain');

            if (is_string($hd) && $hd !== '' && ! $validator->errors()->has('hosted_domain') && ! app(SsoDomains::class)->isVerified($hd)) {
                $validator->errors()->add('hosted_domain', __('sso.errors.hosted_domain_unverified'));
            }

            foreach ((array) $this->input('idp_certificates', []) as $i => $certificate) {
                if (is_string($certificate) && SamlMetadata::normaliseCertificate($certificate) === null) {
                    $validator->errors()->add("idp_certificates.{$i}", __('sso.errors.certificate_invalid'));
                }
            }
        }];
    }

    private function creating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * The public settings sent, metadata read first (typed values win).
     *
     * @return array<string, mixed>
     */
    public function connectionSettings(SamlMetadata $metadata): array
    {
        $data = $this->validated();
        $settings = [];

        if ($this->provider() === SsoConnection::SAML) {
            if (filled($data['metadata_xml'] ?? null)) {
                $settings = $metadata->fromXml($data['metadata_xml']);
            } elseif (filled($data['metadata_url'] ?? null)) {
                $settings = $metadata->fromUrl($data['metadata_url']);
            }

            if (isset($data['idp_certificates'])) {
                $data['idp_certificates'] = array_values(array_map(fn ($cert) => SamlMetadata::normaliseCertificate($cert), $data['idp_certificates']));
            }
        }

        foreach (SsoConnections::SETTINGS[$this->provider()] as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
            }
        }

        return $settings;
    }

    public function attributes(): array
    {
        return collect(['provider', 'name', 'client_id', 'client_secret', 'directory_id', 'hosted_domain', 'metadata_xml', 'metadata_url', 'idp_entity_id', 'idp_sso_url', 'idp_certificates'])
            ->mapWithKeys(fn ($field) => [$field => __("sso.attributes.{$field}")])->all();
    }

    public function messages(): array
    {
        return ['hosted_domain.regex' => __('sso.errors.domain_format')];
    }
}
