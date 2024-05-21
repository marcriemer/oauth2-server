<?php

declare(strict_types=1);

namespace LeagueTests\RedirectUriValidators;

use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use PHPUnit\Framework\TestCase;

class RedirectUriValidatorTest extends TestCase
{
    public function testInvalidNonLoopbackUri(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com:8443/endpoint',
            'https://example.com/different/endpoint',
        ]);

        $invalidRedirectUri = 'https://example.com/endpoint';

        self::assertFalse(
            $validator->validateRedirectUri($invalidRedirectUri),
            'Non loopback URI must match in every part'
        );
    }

    public function testValidNonLoopbackUri(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com:8443/endpoint',
            'https://example.com/different/endpoint',
        ]);

        $validRedirectUri = 'https://example.com:8443/endpoint';

        self::assertTrue(
            $validator->validateRedirectUri($validRedirectUri),
            'Redirect URI must be valid when matching in every part'
        );
    }

    public function testInvalidLoopbackUri(): void
    {
        $validator = new RedirectUriValidator('http://127.0.0.1:8443/endpoint');

        $invalidRedirectUri = 'http://127.0.0.1:8443/different/endpoint';

        self::assertFalse(
            $validator->validateRedirectUri($invalidRedirectUri),
            'Valid loopback redirect URI can change only the port number'
        );
    }

    public function testValidLoopbackUri(): void
    {
        $validator = new RedirectUriValidator('http://127.0.0.1:8443/endpoint');

        $validRedirectUri = 'http://127.0.0.1:8080/endpoint';

        self::assertTrue(
            $validator->validateRedirectUri($validRedirectUri),
            'Loopback redirect URI can change the port number'
        );
    }

    public function testValidIpv6LoopbackUri(): void
    {
        $validator = new RedirectUriValidator('http://[::1]:8443/endpoint');

        $validRedirectUri = 'http://[::1]:8080/endpoint';

        self::assertTrue(
            $validator->validateRedirectUri($validRedirectUri),
            'Loopback redirect URI can change the port number'
        );
    }

    public function testCanValidateUrn(): void
    {
        $validator = new RedirectUriValidator('urn:ietf:wg:oauth:2.0:oob');

        self::assertTrue(
            $validator->validateRedirectUri('urn:ietf:wg:oauth:2.0:oob'),
            'An invalid pre-registered urn was provided'
        );
    }

    public function canValidateCustomSchemeHost(): void
    {
        $validator = new RedirectUriValidator('msal://redirect');

        self::assertTrue(
            $validator->validateRedirectUri('msal://redirect'),
            'An invalid, pre-registered, custom scheme uri was provided'
        );
    }

    public function canValidateCustomSchemePath(): void
    {
        $validator = new RedirectUriValidator('com.example.app:/oauth2redirect/example-provider');

        self::assertTrue(
            $validator->validateRedirectUri('com.example.app:/oauth2redirect/example-provider'),
            'An invalid, pre-registered, custom scheme uri was provided'
        );
    }
}
