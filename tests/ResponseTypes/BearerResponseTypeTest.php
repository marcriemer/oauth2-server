<?php

namespace LeagueTests\ResponseTypes;

use DateInterval;
use DateTimeImmutable;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT as JWT;
use League\Event\EmitterInterface;
use League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator;
use League\OAuth2\Server\ClaimExtractor;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClaimSetEntryInterface;
use League\OAuth2\Server\Entities\ClaimSetInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClaimSetRepositoryInterface;
use League\OAuth2\Server\Repositories\IdTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use League\OAuth2\Server\ResponseTypes\IdTokenResponse;
use LeagueTests\Stubs\AccessTokenEntity;
use LeagueTests\Stubs\ClientEntity;
use LeagueTests\Stubs\RefreshTokenEntity;
use LeagueTests\Stubs\ScopeEntity;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class BearerResponseTypeTest extends TestCase
{
    public function testGenerateHttpResponse()
    {
        $responseType = new BearerTokenResponse();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $scope = new ScopeEntity();
        $scope->setIdentifier('basic');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));
        $accessToken->setClient($client);
        $accessToken->addScope($scope);
        $accessToken->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('no-cache', $response->getHeader('pragma')[0]);
        $this->assertEquals('no-store', $response->getHeader('cache-control')[0]);
        $this->assertEquals('application/json; charset=UTF-8', $response->getHeader('content-type')[0]);

        $response->getBody()->rewind();
        $json = \json_decode($response->getBody()->getContents());
        $this->assertEquals('Bearer', $json->token_type);
        $this->assertObjectHasAttribute('expires_in', $json);
        $this->assertObjectHasAttribute('access_token', $json);
        $this->assertObjectHasAttribute('refresh_token', $json);
    }

    public function testGenerateHttpResponseWithIdToken()
    {
        $request = new ServerRequest(
            [],
            [],
            null,
            'POST',
            'php://input',
            [],
            [],
            [],
            [
                'grant_type'   => 'authorization_code',
                'client_id'    => 'foo',
                'redirect_uri' => 'https://example.com/callback',
                'code'         => 'code',
            ]
        );

        $claimSetRepository = new class () implements ClaimSetRepositoryInterface {
            public function getClaimSetEntry(AccessTokenEntityInterface $accessToken): ClaimSetEntryInterface
            {
                $claimSet = new class () implements ClaimSetEntryInterface {
                    public string $scope = 'openid';

                    /**
                     * @var array<string, string>
                     */
                    public array $claims = ['acr' => 'pop'];

                    public function getScope(): string
                    {
                        return $this->scope;
                    }

                    /**
                     * @return array<string, string> $claims
                     */
                    public function getClaims(): array
                    {
                        return $this->claims;
                    }
                };

                return $claimSet;
            }
        };

        $IdTokenRepository = (new class() implements IdTokenRepositoryInterface {
            private $issuer;

            public function getBuilder(AccessTokenEntityInterface $accessToken): JWT\Builder
            {
                if (\class_exists("\Lcobucci\JWT\Encoding\JoseEncoder")) {
                    $builder = (new JWT\Token\Builder(
                        new \Lcobucci\JWT\Encoding\JoseEncoder(),
                        \Lcobucci\JWT\Encoding\ChainedFormatter::withUnixTimestampDates()
                    ));
                } else {
                    $builder = (new JWT\Builder(new \Lcobucci\JWT\Parsing\Encoder(), new \Lcobucci\JWT\Claim\Factory()));
                }

                $builder->permittedFor($accessToken->getClient()->getIdentifier())
                    ->issuedBy($this->issuer)
                    ->issuedAt(new \DateTimeImmutable())
                    ->expiresAt($accessToken->getExpiryDateTime())
                    ->relatedTo($accessToken->getUserIdentifier())
                    ->withClaim('nonce', 's6G31Kolwu9p');

                return $builder;
            }

            public function setIssuer($issuer)
            {
                $this->issuer = $issuer;

                return $this;
            }
        })->setIssuer(\sprintf('%s://%s', $request->getUri()->getScheme(), $request->getUri()->getHost()));

        $responseType = new IdTokenResponse(
            $IdTokenRepository,
            $claimSetRepository,
            $this->getMockBuilder(EmitterInterface::class)->getMock(),
            $claimExtrator = new ClaimExtractor()
        );

        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $openidScope = new ScopeEntity();
        $openidScope->setIdentifier('openid');

        $emailScope = new ScopeEntity();
        $emailScope->setIdentifier('email');

        $profileScope = new ScopeEntity();
        $profileScope->setIdentifier('profile');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier(\uniqid());
        $accessToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));
        $accessToken->setClient($client);

        $accessToken->addScope($openidScope);
        $accessToken->addScope($emailScope);
        $accessToken->addScope($profileScope);

        $accessToken->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $accessToken->setUserIdentifier(\uniqid());

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier(\uniqid());
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('no-cache', $response->getHeader('pragma')[0]);
        $this->assertEquals('no-store', $response->getHeader('cache-control')[0]);
        $this->assertEquals('application/json; charset=UTF-8', $response->getHeader('content-type')[0]);

        $response->getBody()->rewind();
        $json = \json_decode($response->getBody()->getContents());
        $this->assertEquals('Bearer', $json->token_type);
        $this->assertObjectHasAttribute('expires_in', $json);
        $this->assertObjectHasAttribute('access_token', $json);
        $this->assertObjectHasAttribute('refresh_token', $json);

        $this->assertObjectHasAttribute('id_token', $json);

        if (\class_exists("\Lcobucci\JWT\Token\Parser")) {
            $token = (new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder()))->parse($json->id_token);
        } else {
            $token = (new \Lcobucci\JWT\Parser())->parse($json->id_token);
        }

        $validator = new JWT\Validation\Validator();

        $this->assertTrue($validator->validate(
            $token,
            new JWT\Validation\Constraint\SignedWith(new JWT\Signer\Rsa\Sha256(), JWT\Signer\Key\InMemory::file(__DIR__ . '/../Stubs/public.key', ''))
        ));

        $this->assertTrue($validator->validate(
            $token,
            new JWT\Validation\Constraint\IssuedBy(\sprintf('%s://%s', $request->getUri()->getScheme(), $request->getUri()->getHost()))
        ));

        $this->assertTrue($validator->validate(
            $token,
            new JWT\Validation\Constraint\PermittedFor($client->getIdentifier())
        ));

        $this->assertTrue($validator->validate(
            $token,
            new JWT\Validation\Constraint\RelatedTo($accessToken->getUserIdentifier())
        ));

        if (\class_exists("\Lcobucci\JWT\Validation\Constraint\LooseValidAt")) {
            $this->assertTrue($validator->validate(
                $token,
                new \Lcobucci\JWT\Validation\Constraint\LooseValidAt(new SystemClock($accessToken->getExpiryDateTime()->getTimezone()))
            ));
        } else {
            $this->assertTrue($validator->validate(
                $token,
                new \Lcobucci\JWT\Validation\Constraint\ValidAt(new SystemClock($accessToken->getExpiryDateTime()->getTimezone()))
            ));
        }

        if (\class_exists("\Lcobucci\JWT\Validation\Constraint\HasClaimWithValue")) {
            foreach ($claimExtrator->extract($accessToken->getScopes(), $claimSetRepository->getClaimSetEntry($accessToken)->getClaims()) as $claim => $value) {
                $this->assertTrue($validator->validate($token, new \Lcobucci\JWT\Validation\Constraint\HasClaimWithValue($claim, $value)));
            }
            $this->assertTrue($validator->validate($token, new \Lcobucci\JWT\Validation\Constraint\HasClaimWithValue('acr', 'pop')));
            $this->assertTrue($validator->validate($token, new \Lcobucci\JWT\Validation\Constraint\HasClaimWithValue('nonce', 's6G31Kolwu9p')));
        } else {
            foreach ($claimExtrator->extract($accessToken->getScopes(), $claimSetRepository->getClaimSetEntry($accessToken)->getClaims()) as $claim => $value) {
                $this->assertTrue(\array_key_exists($claim, $token->getClaims()));
                $this->assertEquals($value, $token->getClaims()[$claim]);
            }
        }
    }

    public function testGenerateHttpResponseWithExtraParams()
    {
        $responseType = new BearerTokenResponseWithParams();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $scope = new ScopeEntity();
        $scope->setIdentifier('basic');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));
        $accessToken->setClient($client);
        $accessToken->addScope($scope);
        $accessToken->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('no-cache', $response->getHeader('pragma')[0]);
        $this->assertEquals('no-store', $response->getHeader('cache-control')[0]);
        $this->assertEquals('application/json; charset=UTF-8', $response->getHeader('content-type')[0]);

        $response->getBody()->rewind();
        $json = \json_decode($response->getBody()->getContents());
        $this->assertEquals('Bearer', $json->token_type);
        $this->assertObjectHasAttribute('expires_in', $json);
        $this->assertObjectHasAttribute('access_token', $json);
        $this->assertObjectHasAttribute('refresh_token', $json);

        $this->assertObjectHasAttribute('foo', $json);
        $this->assertEquals('bar', $json->foo);
    }

    public function testDetermineAccessTokenInHeaderValidToken()
    {
        $responseType = new BearerTokenResponse();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setUserIdentifier(123);
        $accessToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));
        $accessToken->setClient($client);
        $accessToken->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());
        $json = \json_decode((string) $response->getBody());

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();
        $accessTokenRepositoryMock->method('isAccessTokenRevoked')->willReturn(false);

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = (new ServerRequest())->withHeader('authorization', \sprintf('Bearer %s', $json->access_token));

        $request = $authorizationValidator->validateAuthorization($request);

        $this->assertEquals('abcdef', $request->getAttribute('oauth_access_token_id'));
        $this->assertEquals('clientName', $request->getAttribute('oauth_client_id'));
        $this->assertEquals('123', $request->getAttribute('oauth_user_id'));
        $this->assertEquals([], $request->getAttribute('oauth_scopes'));
    }

    public function testDetermineAccessTokenInHeaderInvalidJWT()
    {
        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $responseType = new BearerTokenResponse();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setUserIdentifier(123);
        $accessToken->setExpiryDateTime((new DateTimeImmutable())->sub(new DateInterval('PT1H')));
        $accessToken->setClient($client);
        $accessToken->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());
        $json = \json_decode((string) $response->getBody());

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = (new ServerRequest())->withHeader('authorization', \sprintf('Bearer %s', $json->access_token));

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'Access token could not be verified',
                $e->getHint()
            );
        }
    }

    public function testDetermineAccessTokenInHeaderRevokedToken()
    {
        $responseType = new BearerTokenResponse();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setUserIdentifier(123);
        $accessToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));
        $accessToken->setClient($client);
        $accessToken->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new DateTimeImmutable())->add(new DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());
        $json = \json_decode((string) $response->getBody());

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();
        $accessTokenRepositoryMock->method('isAccessTokenRevoked')->willReturn(true);

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = (new ServerRequest())->withHeader('authorization', \sprintf('Bearer %s', $json->access_token));

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'Access token has been revoked',
                $e->getHint()
            );
        }
    }

    public function testDetermineAccessTokenInHeaderInvalidToken()
    {
        $responseType = new BearerTokenResponse();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = (new ServerRequest())->withHeader('authorization', 'Bearer blah');

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'The JWT string must have two dots',
                $e->getHint()
            );
        }
    }

    public function testDetermineMissingBearerInHeader()
    {
        $responseType = new BearerTokenResponse();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setEncryptionKey(\base64_encode(\random_bytes(36)));

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = (new ServerRequest())->withHeader('authorization', 'Bearer blah.blah.blah');

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'Error while decoding from JSON',
                $e->getHint()
            );
        }
    }
}
