<?php

namespace App\Core\Sso\Saml;

use OneLogin\Saml2\Response;

/** AUTH-11: php-saml's response, with the validated assertion's AuthnContextClassRef values. */
class SamlResponse extends Response
{
    /** @return list<string> */
    public function authnContextClassRefs(): array
    {
        $values = [];

        foreach ($this->_queryAssertion('/saml:AuthnStatement/saml:AuthnContext/saml:AuthnContextClassRef') as $node) {
            $values[] = trim($node->textContent);
        }

        return $values;
    }
}
