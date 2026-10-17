<?php

namespace Tests\Support\Sso;

use DOMDocument;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * AUTH-11: SAML responses signed in the test with a self-signed
 * certificate generated per process (no fixture files, no network).
 */
class FakeSamlIdp
{
    public const ENTITY_ID = 'https://sts.windows.net/6f1c0b2e-3d4a-4b5c-8d9e-0a1b2c3d4e5f/';

    public const SSO_URL = 'https://login.microsoftonline.com/6f1c0b2e-3d4a-4b5c-8d9e-0a1b2c3d4e5f/saml2';

    /** @var array{key: string, cert: string}|null */
    private static ?array $pair = null;

    /** @var array{key: string, cert: string}|null */
    private static ?array $otherPair = null;

    /** @return array{key: string, cert: string} */
    public static function pair(): array
    {
        return self::$pair ??= self::generate('Fake IdP');
    }

    /** @return array{key: string, cert: string} a key and certificate the connection does not trust */
    public static function otherPair(): array
    {
        return self::$otherPair ??= self::generate('Attacker');
    }

    /**
     * A base64 SAMLResponse.
     *
     * @param  array{acs: string, audience: string, in_response_to: ?string, email: string, assertion_id?: string, not_on_or_after?: int, not_before?: int, context?: string, issuer?: string, sign?: string, pair?: array{key: string, cert: string}, tamper?: callable(string): string, attributes?: array<string, string>}  $o
     */
    public static function response(array $o): string
    {
        $now = time();
        $at = fn (int $t) => gmdate('Y-m-d\TH:i:s\Z', $t);
        $noa = $o['not_on_or_after'] ?? $now + 300;
        $nb = $o['not_before'] ?? $now - 60;
        $issuer = htmlspecialchars($o['issuer'] ?? self::ENTITY_ID, ENT_XML1);
        $acs = htmlspecialchars($o['acs'], ENT_XML1);
        $irt = $o['in_response_to'] === null ? '' : ' InResponseTo="'.htmlspecialchars($o['in_response_to'], ENT_XML1).'"';
        $assertionId = $o['assertion_id'] ?? '_a'.bin2hex(random_bytes(16));
        $context = htmlspecialchars($o['context'] ?? 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport', ENT_XML1);
        $email = htmlspecialchars($o['email'], ENT_XML1);
        $attributes = '';

        foreach ($o['attributes'] ?? ['http://schemas.microsoft.com/identity/claims/displayname' => 'Amina Otieno'] as $name => $value) {
            $attributes .= '<saml:Attribute Name="'.htmlspecialchars($name, ENT_XML1).'"><saml:AttributeValue>'.htmlspecialchars($value, ENT_XML1).'</saml:AttributeValue></saml:Attribute>';
        }

        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            .' ID="_r'.bin2hex(random_bytes(16)).'" Version="2.0" IssueInstant="'.$at($now).'" Destination="'.$acs.'"'.$irt.'>'
            .'<saml:Issuer>'.$issuer.'</saml:Issuer>'
            .'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .'<saml:Assertion ID="'.$assertionId.'" Version="2.0" IssueInstant="'.$at($now).'">'
            .'<saml:Issuer>'.$issuer.'</saml:Issuer>'
            .'<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">'.$email.'</saml:NameID>'
            .'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">'
            .'<saml:SubjectConfirmationData NotOnOrAfter="'.$at($noa).'" Recipient="'.$acs.'"'.$irt.'/>'
            .'</saml:SubjectConfirmation></saml:Subject>'
            .'<saml:Conditions NotBefore="'.$at($nb).'" NotOnOrAfter="'.$at($noa).'">'
            .'<saml:AudienceRestriction><saml:Audience>'.htmlspecialchars($o['audience'], ENT_XML1).'</saml:Audience></saml:AudienceRestriction>'
            .'</saml:Conditions>'
            .'<saml:AuthnStatement AuthnInstant="'.$at($now).'" SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>'.$context.'</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            .'<saml:AttributeStatement>'.$attributes.'</saml:AttributeStatement>'
            .'</saml:Assertion></samlp:Response>';

        $pair = $o['pair'] ?? self::pair();
        $signed = match ($o['sign'] ?? 'response') {
            'response' => Utils::addSign($xml, $pair['key'], $pair['cert']),
            'assertion' => self::signAssertion($xml, $pair),
            default => $xml,
        };

        if (isset($o['tamper'])) {
            $signed = ($o['tamper'])($signed);
        }

        return base64_encode($signed);
    }

    /** The ID of the AuthnRequest in an HTTP-Redirect sign-in URL. */
    public static function requestIdFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $xml = gzinflate(base64_decode($query['SAMLRequest']));
        preg_match('/ID="([^"]+)"/', $xml, $match);

        return $match[1];
    }

    /** @param array{key: string, cert: string} $pair */
    private static function signAssertion(string $xml, array $pair): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $assertion = $dom->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Assertion')->item(0);

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($pair['key']);
        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReferenceList([$assertion], XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID']);
        $dsig->sign($key);
        $dsig->add509Cert($pair['cert'], true);
        $issuer = $assertion->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);
        $dsig->insertSignature($assertion, $issuer->nextSibling);

        return $dom->saveXML($dom->documentElement);
    }

    /** @return array{key: string, cert: string} */
    private static function generate(string $name): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return ['key' => $keyPem, 'cert' => $certPem];
    }
}
