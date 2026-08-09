<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\ApiClient;
use App\Console\GeoFlowCli\ApiException;
use App\Console\GeoFlowCli\CliException;
use App\Console\GeoFlowCli\SecretRedactor;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    #[Test]
    public function authenticated_request_preserves_success_json_and_expected_headers(): void
    {
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        $factory->fake(fn () => $factory->response(['success' => true, 'data' => ['id' => 9]], 200));
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 12);

        $result = $client->send('task.update', ['task' => 9], body: ['name' => 'Updated'], idempotencyKey: 'idem-1');

        $request = $factory->recorded()[0][0];
        $this->assertSame('PATCH', $request->method());
        $this->assertSame('https://api.example.com/api/v1/tasks/9', $request->url());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer secret-token'));
        $this->assertTrue($request->hasHeader('Accept-Encoding', 'identity'));
        $this->assertTrue($request->hasHeader('X-Idempotency-Key', 'idem-1'));
        $this->assertSame(['name' => 'Updated'], $request->data());
        $this->assertSame(['success' => true, 'data' => ['id' => 9]], $result->payload);
    }

    #[Test]
    public function delete_never_sends_an_idempotency_header(): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response(['success' => true], 200));
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        $client->send('task.delete', ['task' => 4], idempotencyKey: 'must-not-leak');

        $request = $factory->recorded()[0][0];
        $this->assertSame('DELETE', $request->method());
        $this->assertFalse($request->hasHeader('X-Idempotency-Key'));
    }

    #[Test]
    public function image_upload_is_sent_as_multipart(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'geoflow-image-');
        file_put_contents($path, 'fake image bytes');
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response(['success' => true], 201));
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        try {
            $client->send('material.item-upload', ['type' => 'image-libraries', 'id' => 2], uploadPath: $path);
        } finally {
            unlink($path);
        }

        /** @var Request $request */
        $request = $factory->recorded()[0][0];
        $this->assertTrue($request->isMultipart());
        $this->assertTrue($request->hasFile('image', filename: basename($path)));
    }

    #[Test]
    public function api_errors_keep_the_status_and_json_payload(): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response([
            'success' => false,
            'error' => ['code' => 'too_many_requests', 'details' => ['retry_after' => 17]],
        ], 429));
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        try {
            $client->send('catalog');
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame(429, $exception->httpStatus);
            $this->assertSame(17, $exception->payload['error']['details']['retry_after']);
        }
    }

    #[Test]
    public function bearer_tokens_with_control_characters_are_rejected_before_http(): void
    {
        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        $client = new ApiClient($factory, 'https://api.example.com', "secret-token\r\nX-Evil: yes", 30);

        $this->expectException(CliException::class);

        try {
            $client->send('catalog');
        } finally {
            $this->assertCount(0, $factory->recorded());
        }
    }

    #[Test]
    public function api_errors_recursively_remove_token_and_sensitive_request_values(): void
    {
        $token = 'request-token-secret';
        $password = 'body-password-secret';
        $nestedSecret = 'nested-api-secret';
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response([
            'success' => false,
            'error' => [
                'message' => "Rejected {$token} {$password} {$nestedSecret}",
                'details' => ['echo' => "{$token}/{$password}/{$nestedSecret}"],
            ],
        ], 422));
        $client = new ApiClient($factory, 'https://api.example.com', $token, 30);

        try {
            $client->send('task.create', body: [
                'password' => $password,
                'nested' => ['api_key' => $nestedSecret],
            ]);
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $diagnostic = json_encode([$exception->getMessage(), $exception->payload, $exception->raw], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($token, $diagnostic);
            $this->assertStringNotContainsString($password, $diagnostic);
            $this->assertStringNotContainsString($nestedSecret, $diagnostic);
        }
    }

    #[Test]
    public function two_xx_response_without_explicit_success_true_is_a_protocol_error(): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response(['data' => ['id' => 1]], 200));
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        try {
            $client->send('catalog');
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame('invalid_api_envelope', $exception->payload['error']['code']);
        }
    }

    #[Test]
    public function oversized_responses_are_rejected(): void
    {
        $factory = new HttpFactory;
        $factory->fake(fn () => $factory->response('{"success":true,"data":"'.str_repeat('x', 5 * 1024 * 1024).'"}', 200));
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('超过');

        $client->send('catalog');
    }

    #[Test]
    public function chunked_response_is_aborted_by_the_transport_progress_limit(): void
    {
        $factory = new class extends HttpFactory
        {
            public bool $handlerReachedResponse = false;

            public int $progressCalls = 0;

            public function createPendingRequest(): PendingRequest
            {
                return parent::createPendingRequest()->setHandler(function ($request, array $options) {
                    $this->progressCalls++;
                    $options['progress'](0, ApiClient::MAX_RESPONSE_BYTES + 1, 0, 0);
                    $this->handlerReachedResponse = true;

                    return Create::promiseFor(new PsrResponse(200, [], '{"success":true}'));
                });
            }
        };
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        try {
            $client->send('catalog');
            $this->fail('Expected the transport progress callback to abort the response.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('5 MiB', $exception->getMessage());
        }

        $this->assertSame(1, $factory->progressCalls);
        $this->assertFalse($factory->handlerReachedResponse);
    }

    #[Test]
    public function declared_oversized_response_is_aborted_when_headers_arrive(): void
    {
        $factory = new class extends HttpFactory
        {
            public bool $handlerReachedBody = false;

            public function createPendingRequest(): PendingRequest
            {
                return parent::createPendingRequest()->setHandler(function ($request, array $options) {
                    $options['on_headers'](new PsrResponse(200, [
                        'Content-Length' => (string) (ApiClient::MAX_RESPONSE_BYTES + 1),
                    ]));
                    $this->handlerReachedBody = true;

                    return Create::promiseFor(new PsrResponse(200, [], '{"success":true}'));
                });
            }
        };
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('5 MiB');

        try {
            $client->send('catalog');
        } finally {
            $this->assertFalse($factory->handlerReachedBody);
        }
    }

    #[Test]
    public function compressed_responses_are_rejected_before_the_body_is_downloaded(): void
    {
        $factory = new class extends HttpFactory
        {
            public bool $handlerReachedBody = false;

            public function createPendingRequest(): PendingRequest
            {
                return parent::createPendingRequest()->setHandler(function ($request, array $options) {
                    $this->assertIdentityEncoding($request);
                    $this->assertTransportDecodingDisabled($options);
                    $options['on_headers'](new PsrResponse(200, [
                        'Content-Encoding' => 'gzip',
                        'Content-Length' => '1024',
                    ]));
                    $this->handlerReachedBody = true;

                    return Create::promiseFor(new PsrResponse(200, [], '{"success":true}'));
                });
            }

            private function assertIdentityEncoding($request): void
            {
                if ($request->getHeaderLine('Accept-Encoding') !== 'identity') {
                    throw new \LogicException('The request did not require identity encoding.');
                }
            }

            /** @param array<string,mixed> $options */
            private function assertTransportDecodingDisabled(array $options): void
            {
                if (($options['decode_content'] ?? null) !== false) {
                    throw new \LogicException('Transport decoding must stay disabled.');
                }
            }
        };
        $client = new ApiClient($factory, 'https://api.example.com', 'secret-token', 30);

        try {
            $client->send('catalog');
            $this->fail('Expected the encoded response to be rejected.');
        } catch (CliException $exception) {
            $this->assertStringContainsString('identity', $exception->getMessage());
        }

        $this->assertFalse($factory->handlerReachedBody);
    }

    #[Test]
    public function secret_mask_keeps_only_a_small_fingerprint_for_long_values(): void
    {
        $this->assertSame('*****', SecretRedactor::mask('short'));
        $this->assertSame('abc***************st', SecretRedactor::mask('abcdefghijklmnopqrst'));
    }

    #[Test]
    public function overlapping_secrets_are_fully_redacted_from_text(): void
    {
        $message = SecretRedactor::text(
            'Rejected password abcdefghij and token abc',
            'abc',
            'abcdefghij',
        );

        $this->assertStringNotContainsString('abcdefghij', $message);
        $this->assertStringNotContainsString('defghij', $message);
        $this->assertSame('Rejected password [redacted] and token [redacted]', $message);
    }
}
